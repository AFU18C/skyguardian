package com.openai.mini4kgps;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.hardware.usb.UsbAccessory;
import android.hardware.usb.UsbManager;
import android.os.Build;
import android.os.Bundle;
import android.os.ParcelFileDescriptor;
import android.text.method.ScrollingMovementMethod;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.io.ByteArrayOutputStream;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Strict Mini 4K ATTI-on-Sport controller.
 *
 * Exact parameter confirmed on this aircraft family by the read-only scanner:
 * table 0 / index 681 / fswitch_selection_1|g_config.control.control_mode[1]
 * stock Sport value=8, candidate ATTI value=3.
 *
 * Writes remain locked until E1 identity + shape + E2 current value are verified by the FC.
 * Only index 681 is writable here. gps_enable, failsafe and all other FC parameters are untouched.
 */
public class AttiControlActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.ATTI_ACCESSORY_PERMISSION";
    private static final int TABLE = 0;
    private static final int INDEX = 681;
    private static final int STOCK_SPORT = 8;
    private static final int ATTI = 3;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x7A00);

    private UsbManager usbManager;
    private TextView log;
    private Button check;
    private Button attiOn;
    private Button restore;
    private volatile boolean pendingPermissionCheck;
    private volatile boolean confirmed;
    private volatile Boolean confirmedEncrypted;
    private volatile Integer currentValue;
    private volatile String confirmedName;

    private static final class Route {
        final int senderType, senderIndex, receiverType, receiverIndex;
        Route(int st, int si, int rt, int ri) {
            senderType = st;
            senderIndex = si;
            receiverType = rt;
            receiverIndex = ri;
        }
    }

    private static final Route APP_FLYC = new Route(
            DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0);

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        usbManager = (UsbManager) getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
        showInstructions();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        try { unregisterReceiver(usbReceiver); } catch (Exception ignored) {}
        io.shutdownNow();
    }

    private void buildUi() {
        int pad = dp(16);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("Mini 4K ATTI CONTROL v1.3");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        check = new Button(this);
        check.setText("ATTI CHECK");
        root.addView(check, top(12));

        attiOn = new Button(this);
        attiOn.setText("ATTI ON SPORT (LOCKED)");
        attiOn.setEnabled(false);
        root.addView(attiOn, top(8));

        restore = new Button(this);
        restore.setText("RESTORE SPORT (LOCKED)");
        restore.setEnabled(false);
        root.addView(restore, top(8));

        log = new TextView(this);
        log.setTextSize(13);
        log.setTextIsSelectable(true);
        log.setMovementMethod(new ScrollingMovementMethod());
        log.setPadding(0, dp(12), 0, dp(12));
        ScrollView sc = new ScrollView(this);
        sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        check.setOnClickListener(v -> runBusy(this::attiCheck));
        attiOn.setOnClickListener(v -> new AlertDialog.Builder(this)
                .setTitle("ATTI на положении SPORT")
                .setMessage("Будет изменён ТОЛЬКО control_mode[1]: 8 → 3. gps_enable и остальные параметры не трогаются. После записи сначала проверяйте на земле, затем только очень низко в штиль. Продолжить?")
                .setNegativeButton("Отмена", null)
                .setPositiveButton("ATTI ON", (d, w) -> runBusy(() -> writeMode(ATTI)))
                .show());
        restore.setOnClickListener(v -> new AlertDialog.Builder(this)
                .setTitle("Вернуть штатный SPORT")
                .setMessage("Будет возвращён control_mode[1]: 3 → 8 и выполнена проверка чтением.")
                .setNegativeButton("Отмена", null)
                .setPositiveButton("RESTORE", (d, w) -> runBusy(() -> writeMode(STOCK_SPORT)))
                .show());
    }

    private LinearLayout.LayoutParams top(int px) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = dp(px);
        return p;
    }

    private int dp(int x) {
        return Math.round(x * getResources().getDisplayMetrics().density);
    }

    private void showInstructions() {
        log.setText("");
        append("ATTI CONTROL v1.3");
        append("ATTI CHECK только читает FC. Запись заблокирована до строгой проверки table=0 index=681 и имени control_mode[1].");
        append("ATTI ON SPORT меняет только значение 8 → 3. RESTORE SPORT возвращает 3 → 8.");
        append("gps_enable / without_gps / failsafe здесь НЕ меняются.");
        append("");
        append("DJI Fly полностью закрыть. Телефон подключить к ВЕРХНЕМУ порту RC-N1. Дрон и пульт должны быть включены и связаны.");
    }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_ATTACHED);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(usbReceiver, f, Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver, f);
    }

    private final BroadcastReceiver usbReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context c, Intent i) {
            String a = i.getAction();
            if (ACTION_USB_PERMISSION.equals(a)) {
                boolean ok = i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(ok ? "USB Accessory permission: OK." : "USB Accessory permission: ОТКАЗАНО.");
                if (ok && pendingPermissionCheck) {
                    pendingPermissionCheck = false;
                    runBusy(AttiControlActivity.this::attiCheck);
                }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(a)) {
                append("AOA accessory отключён.");
                clearConfirmation();
            }
        }
    };

    private void runBusy(Runnable task) {
        runOnUiThread(() -> {
            check.setEnabled(false);
            attiOn.setEnabled(false);
            restore.setEnabled(false);
        });
        io.submit(() -> {
            try { task.run(); }
            catch (Throwable t) { append("ОШИБКА: " + t.getClass().getSimpleName() + ": " + t.getMessage()); }
            finally {
                runOnUiThread(() -> {
                    check.setEnabled(true);
                    boolean ready = confirmed && currentValue != null && (currentValue == STOCK_SPORT || currentValue == ATTI);
                    attiOn.setEnabled(ready);
                    restore.setEnabled(ready);
                    attiOn.setText(ready ? "ATTI ON SPORT" : "ATTI ON SPORT (LOCKED)");
                    restore.setText(ready ? "RESTORE SPORT" : "RESTORE SPORT (LOCKED)");
                });
            }
        });
    }

    private void clearConfirmation() {
        confirmed = false;
        confirmedEncrypted = null;
        currentValue = null;
        confirmedName = null;
        runOnUiThread(() -> {
            attiOn.setEnabled(false);
            restore.setEnabled(false);
            attiOn.setText("ATTI ON SPORT (LOCKED)");
            restore.setText("RESTORE SPORT (LOCKED)");
        });
    }

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] as = usbManager.getAccessoryList();
        if (as == null || as.length == 0) return null;
        for (UsbAccessory a : as) {
            if ("DJI".equalsIgnoreCase(a.getManufacturer())) return a;
        }
        return as[0];
    }

    private void requestAccessoryPermission(UsbAccessory a) {
        pendingPermissionCheck = true;
        PendingIntent pi = PendingIntent.getBroadcast(this, 0,
                new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        usbManager.requestPermission(a, pi);
        append("Разрешите Android доступ к USB. ATTI CHECK продолжится автоматически.");
    }

    private void attiCheck() {
        runOnUiThread(() -> log.setText(""));
        clearConfirmation();
        append("=== ATTI CHECK v1.3 / READ ONLY ===");
        append("DUML crypto self-test: " + (DumlV2.selfTest() ? "PASS" : "FAIL"));
        if (!DumlV2.selfTest()) return;

        UsbAccessory a = chooseAccessory();
        if (a == null) {
            append("DJI AOA accessory не найден. Закройте DJI Fly и подключите телефон к верхнему порту RC-N1.");
            return;
        }
        append("AOA: manufacturer='" + nz(a.getManufacturer()) + "' model='" + nz(a.getModel()) + "'");
        if (!usbManager.hasPermission(a)) {
            requestAccessoryPermission(a);
            return;
        }

        AoaSession s = null;
        try {
            s = AoaSession.open(usbManager, a, seq);
            if (s == null) {
                append("AOA pipe не открылся. Проверьте, что DJI Fly полностью закрыт.");
                return;
            }
            s.startProtocol();
            sleep(350);
            append("AOA pipe: OPEN; route=" + s.routeString());

            Boolean enc = detectTransport(s);
            if (enc == null) {
                append("Не удалось открыть read-only FLYC E0 transport. Ничего не записано.");
                return;
            }
            append("FLYC transport=" + (enc ? "SIMPLE encrypted" : "plaintext"));

            DumlV2.ParamInfo2017 info = readIdentity(s, enc);
            if (!safeIdentity(info)) {
                append("ОТКАЗ: index 681 не подтвердил точный control_mode[1] с диапазоном 0..14.");
                return;
            }
            Integer v = readIndex(s, enc);
            if (v == null) {
                append("ОТКАЗ: E2 current value не прочитан.");
                return;
            }

            confirmed = true;
            confirmedEncrypted = enc;
            currentValue = v;
            confirmedName = info.name;

            append("PARAM OK: table=" + info.tableNo + " index=" + info.paramIndex);
            append("name='" + info.name + "' type=" + info.typeId + " size=" + info.size + " range=" + info.min + ".." + info.max);
            append("current=" + v + modeLabel(v));
            if (v == STOCK_SPORT) append("READY: штатный SPORT=8 подтверждён. ATTI ON SPORT разблокирован.");
            else if (v == ATTI) append("READY: ATTI candidate=3 уже установлен. RESTORE SPORT доступен.");
            else {
                append("НЕОЖИДАННОЕ значение=" + v + ". Запись остаётся заблокированной для безопасности.");
                confirmed = false;
            }
            append("WRITE COMMANDS SENT: 0");
        } catch (Exception e) {
            append("CHECK: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            if (s != null) s.close();
            append("AOA pipe: CLOSED");
        }
    }

    private Boolean detectTransport(AoaSession s) throws Exception {
        for (boolean enc : new boolean[]{false, true}) {
            byte[] q = DumlV2.le16(TABLE);
            DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0, q, enc, 900);
            if (r == null) continue;
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(r.payload);
            if (a != null && a.status == 0 && a.tableNo == TABLE && a.entriesNum > INDEX) {
                append("E0 table0 entries=" + a.entriesNum + String.format(Locale.US, " crc=0x%08X", a.entriesCrc));
                return enc;
            }
        }
        return null;
    }

    private DumlV2.ParamInfo2017 readIdentity(AoaSession s, boolean enc) throws Exception {
        byte[] q = DumlV2.concat(DumlV2.le16(TABLE), DumlV2.le16(INDEX));
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE1, q, enc, 900);
        return r == null ? null : DumlV2.ParamInfo2017.parse(r.payload);
    }

    private boolean safeIdentity(DumlV2.ParamInfo2017 i) {
        if (i == null || i.status != 0 || i.tableNo != TABLE || i.paramIndex != INDEX) return false;
        if (i.typeId != 0 || i.size != 1 || i.min != 0 || i.max != 14) return false;
        if (i.name == null) return false;
        boolean alias = false;
        boolean canonical = false;
        for (String p : i.name.split("\\|")) {
            String x = p.trim();
            if ("fswitch_selection_1".equals(x)) alias = true;
            if ("g_config.control.control_mode[1]".equals(x)) canonical = true;
        }
        return alias || canonical;
    }

    private Integer readIndex(AoaSession s, boolean enc) throws Exception {
        byte[] q = DumlV2.concat(DumlV2.le16(TABLE), DumlV2.le16(1), DumlV2.le16(INDEX));
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE2, q, enc, 900);
        if (r == null || r.payload.length < 7) return null;
        int st = DumlV2.u16(r.payload, 0);
        int got = DumlV2.u16(r.payload, 4);
        if (st != 0 || got != INDEX) return null;
        return r.payload[6] & 0xFF;
    }

    private void writeMode(int target) {
        if (!confirmed || confirmedEncrypted == null || currentValue == null || confirmedName == null) {
            append("Запись заблокирована: сначала ATTI CHECK.");
            return;
        }
        if (target != ATTI && target != STOCK_SPORT) {
            append("Запись заблокирована: недопустимое target=" + target);
            return;
        }

        UsbAccessory a = chooseAccessory();
        if (a == null || !usbManager.hasPermission(a)) {
            append("AOA connection/permission потеряно. Повторите ATTI CHECK.");
            clearConfirmation();
            return;
        }

        append("");
        append(target == ATTI ? "=== ATTI ON SPORT: 8 → 3 ===" : "=== RESTORE SPORT: 3 → 8 ===");
        AoaSession s = null;
        try {
            s = AoaSession.open(usbManager, a, seq);
            if (s == null) {
                append("AOA pipe не открылся.");
                return;
            }
            s.startProtocol();
            sleep(350);
            boolean enc = confirmedEncrypted;

            // Re-validate exact identity in the same session immediately before any write.
            DumlV2.ParamInfo2017 info = readIdentity(s, enc);
            if (!safeIdentity(info)) {
                append("ОТМЕНА: pre-write E1 identity не совпала с control_mode[1].");
                clearConfirmation();
                return;
            }
            Integer before = readIndex(s, enc);
            if (before == null || (before != STOCK_SPORT && before != ATTI)) {
                append("ОТМЕНА: pre-read=" + before + ", ожидалось только 8 или 3.");
                clearConfirmation();
                return;
            }
            append("Pre-read: " + info.name + "=" + before + modeLabel(before));

            if (before == target) {
                append("Уже установлено " + target + modeLabel(target) + ". Ничего не записывал.");
                currentValue = target;
                return;
            }
            if (target == ATTI && before != STOCK_SPORT) {
                append("ОТМЕНА: ATTI разрешено только из штатного SPORT=8.");
                return;
            }
            if (target == STOCK_SPORT && before != ATTI) {
                append("ОТМЕНА: RESTORE разрешён только из ATTI=3.");
                return;
            }

            // Same transient Assistant access command already used by the proven GPS ON/OFF writer.
            transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xDF, DumlV2.le32(1), enc, 650);

            byte[] q = DumlV2.concat(DumlV2.le16(TABLE), DumlV2.le16(1),
                    DumlV2.le16(INDEX), new byte[]{(byte) target});
            DumlV2.Frame wr = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE3, q, enc, 1200);
            append("Write ACK: " + (wr == null ? "нет/не распознан" : shortHex(wr.payload, 32)));
            sleep(250);

            Integer after = readIndex(s, enc);
            if (after != null && after == target) {
                currentValue = after;
                append("УСПЕХ: FC read-back=" + after + modeLabel(after) + ".");
                if (target == ATTI) {
                    append("Теперь положение SPORT назначено на candidate ATTI=3. Сначала выключить/включить питание и повторить ATTI CHECK.");
                    append("Первый полётный тест: только открытая площадка, штиль, 30–50 см. Не подниматься на 50 м до проверки управления.");
                } else {
                    append("Штатный SPORT=8 восстановлен.");
                }
            } else {
                append("НЕ ПОДТВЕРЖДЕНО: post-read=" + after + ". Состояние неизвестно — не запускать моторы до ATTI CHECK.");
                clearConfirmation();
            }
        } catch (Exception e) {
            append("WRITE: " + e.getClass().getSimpleName() + ": " + e.getMessage());
            clearConfirmation();
        } finally {
            if (s != null) s.close();
            append("AOA pipe: CLOSED");
        }
    }

    private String modeLabel(int v) {
        if (v == STOCK_SPORT) return " (stock SPORT)";
        if (v == ATTI) return " (ATTI candidate)";
        return "";
    }

    private DumlV2.Frame transact(AoaSession s, Route route, int set, int id,
                                  byte[] payload, boolean encrypted, int timeoutMs) throws Exception {
        int qseq = seq.getAndIncrement() & 0xFFFF;
        byte[] q = DumlV2.packet(route.senderType, route.senderIndex, route.receiverType,
                route.receiverIndex, qseq, set, id, payload, encrypted);
        s.clearQueue();
        s.sendDuml(q);

        long end = System.currentTimeMillis() + timeoutMs;
        DumlV2.Frame fallback = null;
        while (System.currentTimeMillis() < end) {
            long left = Math.max(1, end - System.currentTimeMillis());
            DumlV2.Frame f = s.poll(Math.min(90, left));
            if (f == null) continue;
            if (!f.response || f.cmdSet != set || f.cmdId != id) continue;
            boolean reverse = f.senderType == route.receiverType && f.receiverType == route.senderType;
            if (f.seq == qseq && reverse) return f;
            if (f.seq == qseq) return f;
            if (reverse && fallback == null) fallback = f;
        }
        return fallback;
    }

    private String shortHex(byte[] b, int max) {
        if (b == null) return "";
        int n = Math.min(b.length, max);
        byte[] c = new byte[n];
        System.arraycopy(b, 0, c, 0, n);
        return DumlV2.hex(c) + (b.length > max ? " ..." : "");
    }

    private static String nz(String s) { return s == null ? "" : s; }

    private static void sleep(long ms) {
        try { Thread.sleep(ms); } catch (InterruptedException e) { Thread.currentThread().interrupt(); }
    }

    private void append(String s) {
        runOnUiThread(() -> {
            log.append(s + "\n");
            View v = (View) log.getParent();
            if (v instanceof ScrollView) ((ScrollView) v).post(() -> ((ScrollView) v).fullScroll(View.FOCUS_DOWN));
        });
    }

    /** AOA + RCLink composite transport used by the top RC-N1 phone port. */
    private static final class AoaSession {
        private final ParcelFileDescriptor pfd;
        private final FileInputStream in;
        private final FileOutputStream out;
        private final AtomicInteger seq;
        private final AtomicBoolean running = new AtomicBoolean(true);
        private final LinkedBlockingQueue<DumlV2.Frame> rx = new LinkedBlockingQueue<>(5000);
        private final Thread reader;
        private Thread keepalive;
        private final Object writeLock = new Object();
        private volatile int route = 0x5749;
        private volatile long dumlUnits, dumlFrames, otherUnits;
        private int headerPos;
        private final byte[] header = new byte[8];
        private long bodyLeft;
        private int bodyType = -1;
        private ByteArrayOutputStream body;

        static AoaSession open(UsbManager manager, UsbAccessory a, AtomicInteger sequence) {
            try {
                ParcelFileDescriptor p = manager.openAccessory(a);
                if (p == null) return null;
                return new AoaSession(p, sequence);
            } catch (Exception e) {
                return null;
            }
        }

        private AoaSession(ParcelFileDescriptor pfd, AtomicInteger sequence) {
            this.pfd = pfd;
            this.seq = sequence;
            this.in = new FileInputStream(pfd.getFileDescriptor());
            this.out = new FileOutputStream(pfd.getFileDescriptor());
            this.reader = new Thread(this::readLoop, "mini4k-atti-aoa-rx");
            this.reader.setDaemon(true);
            this.reader.start();
        }

        void startProtocol() throws IOException {
            byte[] bootPayload = new byte[]{0, 0, 1};
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_AIRCRAFT_PROXY, 0,
                    seq.getAndIncrement() & 0xFFFF, DumlV2.CMDSET_GENERAL, 0x00, bootPayload, false));
            sleep(4);
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_ANY, 0,
                    seq.getAndIncrement() & 0xFFFF, DumlV2.CMDSET_GENERAL, 0x00, bootPayload, false));
            sleep(8);
            startKeepalive();
        }

        private void startKeepalive() {
            if (keepalive != null) return;
            keepalive = new Thread(() -> {
                sleep(2500);
                byte[] p = new byte[]{1, 1, 0, (byte) 0xFF, (byte) 0xFF, 0x20, 0, 0};
                while (running.get()) {
                    try {
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_REMOTE_RADIO, 0,
                                seq.getAndIncrement() & 0xFFFF, 0x06, 0x77, p, false));
                        sleep(4);
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, 14, 0,
                                seq.getAndIncrement() & 0xFFFF, 0x06, 0x77, p, false));
                    } catch (Exception ignored) {
                        break;
                    }
                    sleep(2500);
                }
            }, "mini4k-atti-aoa-keepalive");
            keepalive.setDaemon(true);
            keepalive.start();
        }

        void clearQueue() { rx.clear(); }
        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        String routeString() { return String.format(Locale.US, "0x%04X", route); }

        void sendDuml(byte[] duml) throws IOException {
            int r = route;
            int n = duml.length;
            byte[] w = new byte[8 + n];
            w[0] = 0x55;
            w[1] = (byte) 0xCC;
            w[2] = (byte) (r & 0xFF);
            w[3] = (byte) ((r >>> 8) & 0xFF);
            w[4] = (byte) (n & 0xFF);
            w[5] = (byte) ((n >>> 8) & 0xFF);
            w[6] = (byte) ((n >>> 16) & 0xFF);
            w[7] = (byte) ((n >>> 24) & 0xFF);
            System.arraycopy(duml, 0, w, 8, n);
            synchronized (writeLock) {
                out.write(w);
                out.flush();
            }
            sleep(3);
        }

        private void readLoop() {
            byte[] b = new byte[16384];
            try {
                while (running.get()) {
                    int n = in.read(b);
                    if (n < 0) break;
                    if (n > 0) feed(b, n);
                }
            } catch (Exception ignored) {
            } finally {
                running.set(false);
            }
        }

        private void feed(byte[] a, int n) {
            for (int i = 0; i < n; i++) {
                int x = a[i] & 0xFF;
                if (bodyLeft > 0) {
                    if (body != null) body.write(x);
                    bodyLeft--;
                    if (bodyLeft == 0) finishUnit();
                    continue;
                }
                if (headerPos == 0) {
                    if (x == 0x55) { header[0] = 0x55; headerPos = 1; }
                    continue;
                }
                if (headerPos == 1) {
                    if (x == 0xCC) { header[1] = (byte) 0xCC; headerPos = 2; }
                    else if (x == 0x55) { header[0] = 0x55; headerPos = 1; }
                    else headerPos = 0;
                    continue;
                }
                header[headerPos++] = (byte) x;
                if (headerPos == 8) {
                    int type = (header[2] & 0xFF) | ((header[3] & 0xFF) << 8);
                    long len = ((long) header[4] & 0xFF) |
                            (((long) header[5] & 0xFF) << 8) |
                            (((long) header[6] & 0xFF) << 16) |
                            (((long) header[7] & 0xFF) << 24);
                    headerPos = 0;
                    if (len < 0 || len > 0x200000L) {
                        bodyLeft = 0;
                        body = null;
                        bodyType = -1;
                        continue;
                    }
                    bodyType = type;
                    bodyLeft = len;
                    if (type == 0x5749 || type == 0x7530) {
                        route = type;
                        body = new ByteArrayOutputStream((int) Math.min(len, 16384));
                    } else body = null;
                    if (bodyLeft == 0) finishUnit();
                }
            }
        }

        private void finishUnit() {
            if (bodyType == 0x5749 || bodyType == 0x7530) {
                dumlUnits++;
                if (body != null) {
                    for (DumlV2.Frame f : DumlV2.frames(body.toByteArray())) {
                        dumlFrames++;
                        if (!rx.offer(f)) {
                            rx.poll();
                            rx.offer(f);
                        }
                    }
                }
            } else otherUnits++;
            bodyType = -1;
            body = null;
            bodyLeft = 0;
        }

        void close() {
            running.set(false);
            try { if (keepalive != null) keepalive.interrupt(); } catch (Exception ignored) {}
            try { reader.interrupt(); } catch (Exception ignored) {}
            try { in.close(); } catch (Exception ignored) {}
            try { out.close(); } catch (Exception ignored) {}
            try { pfd.close(); } catch (Exception ignored) {}
        }
    }
}

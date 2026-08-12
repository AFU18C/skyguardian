package com.openai.mini4kgps;

import android.app.Activity;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.hardware.usb.UsbAccessory;
import android.hardware.usb.UsbDevice;
import android.hardware.usb.UsbInterface;
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
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Read-only GNSS/navigation parameter scanner for DJI Mini 4K through RC-N1 AOA.
 *
 * This activity never sends FLYC parameter write commands (E3/F9), reset commands,
 * or gps_enable changes. It reads table metadata (E0), parameter metadata (E1),
 * and current values (E2). No 0xDF config-unlock is used in this scanner.
 */
public class GnssScanActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_SCAN_PERMISSION";
    private static final int RC_N1_VID = 0x2CA3;
    private static final int RC_N1_PID = 0x1020;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x7900);

    private UsbManager usbManager;
    private TextView log;
    private Button scan;
    private volatile boolean pendingPermission;

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
        title.setText("Mini 4K GNSS SCAN v1.1");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        scan = new Button(this);
        scan.setText("GNSS SCAN — READ ONLY");
        LinearLayout.LayoutParams bp = new LinearLayout.LayoutParams(-1, -2);
        bp.topMargin = dp(12);
        root.addView(scan, bp);

        log = new TextView(this);
        log.setTextSize(13);
        log.setTextIsSelectable(true);
        log.setMovementMethod(new ScrollingMovementMethod());
        log.setPadding(0, dp(12), 0, dp(12));

        ScrollView sc = new ScrollView(this);
        sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        scan.setOnClickListener(v -> runBusy(this::performScan));
    }

    private int dp(int x) {
        return Math.round(x * getResources().getDisplayMetrics().density);
    }

    private void showInstructions() {
        log.setText("");
        append("GNSS SCAN v1.1 — ТОЛЬКО ЧТЕНИЕ.");
        append("Сканер читает имена/свойства/текущие значения параметров, связанных с GPS/GNSS/navigation.");
        append("Он НЕ отправляет E3/F9 write, НЕ меняет gps_enable и НЕ использует config-unlock 0xDF.");
        append("");
        append("ПОДКЛЮЧЕНИЕ:");
        append("1) Включите Mini 4K и RC-N1, дождитесь связи дрон ↔ пульт.");
        append("2) Полностью закройте DJI Fly.");
        append("3) Телефон подключите к ВЕРХНЕМУ телефонному порту RC-N1.");
        append("4) Нажмите GNSS SCAN — READ ONLY.");
        append("");
        append("Результат покажет table/index, имя, type, size, range и raw current value для найденных GNSS/navigation параметров.");
    }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_ATTACHED);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        f.addAction(UsbManager.ACTION_USB_DEVICE_ATTACHED);
        f.addAction(UsbManager.ACTION_USB_DEVICE_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(usbReceiver, f, Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver, f);
    }

    private final BroadcastReceiver usbReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context c, Intent i) {
            String a = i.getAction();
            if (ACTION_USB_PERMISSION.equals(a)) {
                boolean ok = i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(ok ? "USB Accessory permission: OK." : "USB Accessory permission: ОТКАЗАНО.");
                if (ok && pendingPermission) {
                    pendingPermission = false;
                    runBusy(GnssScanActivity.this::performScan);
                }
            } else if (UsbManager.ACTION_USB_ACCESSORY_ATTACHED.equals(a)) {
                append("AOA accessory подключён.");
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(a)) {
                append("AOA accessory отключён.");
            }
        }
    };

    private void runBusy(Runnable task) {
        runOnUiThread(() -> scan.setEnabled(false));
        io.submit(() -> {
            try { task.run(); }
            catch (Throwable t) { append("ОШИБКА: " + t.getClass().getSimpleName() + ": " + t.getMessage()); }
            finally { runOnUiThread(() -> scan.setEnabled(true)); }
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
        pendingPermission = true;
        PendingIntent pi = PendingIntent.getBroadcast(this, 0,
                new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        usbManager.requestPermission(a, pi);
        append("Android запросил USB Accessory permission. Разрешите — скан продолжится автоматически.");
    }

    private void noAccessoryDiagnosis() {
        append("AOA accessory DJI не найден.");
        boolean servicePort = false;
        for (UsbDevice d : usbManager.getDeviceList().values()) {
            append(String.format(Locale.US, "USB host: VID=%04X PID=%04X IF=%d",
                    d.getVendorId(), d.getProductId(), d.getInterfaceCount()));
            for (int j = 0; j < d.getInterfaceCount(); j++) {
                UsbInterface u = d.getInterface(j);
                append(String.format(Locale.US, "  IF%d class=0x%02X sub=0x%02X proto=0x%02X eps=%d",
                        j, u.getInterfaceClass(), u.getInterfaceSubclass(),
                        u.getInterfaceProtocol(), u.getEndpointCount()));
            }
            if (d.getVendorId() == RC_N1_VID && d.getProductId() == RC_N1_PID) servicePort = true;
        }
        append("");
        if (servicePort) {
            append("Виден нижний сервисный USB RC-N1 2CA3:1020. Переставьте телефон в ВЕРХНИЙ телефонный порт RC-N1.");
        } else {
            append("Подключите телефон к ВЕРХНЕМУ телефонному порту RC-N1, не напрямую к дрону и не к нижнему USB пульта.");
        }
    }

    private void performScan() {
        runOnUiThread(() -> log.setText(""));
        append("=== GNSS SCAN v1.1 / READ ONLY ===");
        append("DUML crypto self-test: " + (DumlV2.selfTest() ? "PASS" : "FAIL"));
        if (!DumlV2.selfTest()) {
            append("Self-test FAIL. Никаких команд не отправляю.");
            return;
        }

        UsbAccessory a = chooseAccessory();
        if (a == null) {
            noAccessoryDiagnosis();
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
                append("AOA pipe не открылся. Полностью закройте DJI Fly и повторите.");
                return;
            }
            s.startProtocol();
            sleep(350);
            append("AOA pipe: OPEN; bootstrap=sent; keepalive=running; route=" + s.routeString());

            DumlV2.Frame version = transact(s, APP_FLYC, DumlV2.CMDSET_GENERAL, 0x01,
                    new byte[0], false, 1200);
            if (version != null) {
                String hw = hwString(version.payload);
                append("FC COMMON/Version: OK" + (hw.isEmpty() ? "" : "; HW='" + hw + "'"));
            } else {
                append("FC COMMON/Version: ACK не пойман; пробую таблицу параметров.");
            }

            Boolean enc = establishReadOnlyMode(s);
            if (enc == null) {
                append("READ-ONLY E0 не ответил ни plaintext, ни SIMPLE encrypted способом.");
                append("Скан остановлен. Никаких write/unlock команд не отправлялось.");
                return;
            }
            append("READ transport=" + (enc ? "SIMPLE encrypted" : "plaintext"));

            List<DumlV2.TableAttr2017> tables = readTables(s, enc);
            if (tables.isEmpty()) {
                append("Таблица 2017 не открылась. Ничего не изменено.");
                return;
            }

            int matches = 0;
            int scanned = 0;
            int timeouts = 0;
            append("");
            append("--- MATCHES ---");
            for (DumlV2.TableAttr2017 table : tables) {
                int total = (int) Math.min(table.entriesNum, 10000);
                append("SCAN table=" + table.tableNo + " indexes=0.." + (total - 1));
                int noReplyStreak = 0;
                for (int index = 0; index < total; index++) {
                    byte[] q = DumlV2.concat(DumlV2.le16(table.tableNo), DumlV2.le16(index));
                    DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE1, q, enc, 380);
                    scanned++;
                    if (r == null) {
                        timeouts++;
                        noReplyStreak++;
                        if (noReplyStreak >= 20) {
                            append("table=" + table.tableNo + ": остановка после 20 подряд E1 timeout.");
                            break;
                        }
                        continue;
                    }
                    noReplyStreak = 0;
                    DumlV2.ParamInfo2017 info = DumlV2.ParamInfo2017.parse(r.payload);
                    if (info == null || info.status != 0 || !isInteresting(info.name)) {
                        if (index > 0 && index % 100 == 0) append("  progress " + index + "/" + total);
                        continue;
                    }

                    matches++;
                    byte[] value = readIndexRaw(s, enc, info.tableNo, info.paramIndex, info.size);
                    append(formatMatch(info, value));
                    if (index > 0 && index % 100 == 0) append("  progress " + index + "/" + total);
                }
            }

            append("");
            append("=== SCAN COMPLETE ===");
            append("Scanned metadata entries=" + scanned + ", matches=" + matches + ", E1 timeouts=" + timeouts);
            append("RX DUML=" + s.dumlFrames() + ", route=" + s.routeString());
            append("WRITE COMMANDS SENT: 0");
            append("gps_enable НЕ ИЗМЕНЯЛСЯ.");
        } catch (Exception e) {
            append("SCAN: " + e.getClass().getSimpleName() + ": " + e.getMessage());
            append("WRITE COMMANDS SENT: 0");
        } finally {
            if (s != null) s.close();
            append("AOA pipe: CLOSED");
        }
    }

    /** Strictly read-only transport detection: only E0 table reads, no config unlock. */
    private Boolean establishReadOnlyMode(AoaSession s) throws Exception {
        boolean[] modes = new boolean[]{false, true};
        for (boolean enc : modes) {
            DumlV2.Frame e0 = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0,
                    DumlV2.le16(0), enc, 1000);
            if (e0 == null) continue;
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(e0.payload);
            if (a != null && a.status == 0 && a.entriesNum > 0 && a.entriesNum < 20000) {
                append("E0 table0: entries=" + a.entriesNum + " crc=0x" + Long.toHexString(a.entriesCrc));
                return enc;
            }
        }
        return null;
    }

    private List<DumlV2.TableAttr2017> readTables(AoaSession s, boolean enc) throws Exception {
        List<DumlV2.TableAttr2017> out = new ArrayList<>();
        for (int t = 0; t < 32; t++) {
            DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0,
                    DumlV2.le16(t), enc, 800);
            if (r == null) break;
            if (r.payload.length <= 2) break;
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(r.payload);
            if (a == null || a.status != 0 || a.entriesNum <= 0 || a.entriesNum > 20000) break;
            out.add(a);
            append("TABLE " + a.tableNo + ": entries=" + a.entriesNum + " crc=0x" + Long.toHexString(a.entriesCrc));
        }
        return out;
    }

    private byte[] readIndexRaw(AoaSession s, boolean enc, int table, int index, int expectedSize) throws Exception {
        byte[] q = DumlV2.concat(DumlV2.le16(table), DumlV2.le16(1), DumlV2.le16(index));
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE2, q, enc, 850);
        if (r == null || r.payload.length < 6) return null;
        int st = DumlV2.u16(r.payload, 0);
        int got = DumlV2.u16(r.payload, 4);
        if (st != 0 || got != index) return null;
        int available = r.payload.length - 6;
        int n = expectedSize > 0 ? Math.min(expectedSize, available) : available;
        if (n <= 0) return new byte[0];
        byte[] value = new byte[n];
        System.arraycopy(r.payload, 6, value, 0, n);
        return value;
    }

    private boolean isInteresting(String name) {
        if (name == null) return false;
        String n = name.toLowerCase(Locale.US);
        String[] direct = new String[]{
                "gps", "gnss", "glonass", "galileo", "beidou", "bds", "satellite",
                "sat_num", "satnum", "sat_count", "snr", "ephemer", "almanac"
        };
        for (String k : direct) if (n.contains(k)) return true;

        String[] nav = new String[]{
                "nav_", ".nav", "navigation", "position", "_pos", ".pos", "coordinate",
                "coord", "home_point", "homepoint", "velocity", "speed"
        };
        for (String k : nav) if (n.contains(k)) return true;
        return false;
    }

    private String formatMatch(DumlV2.ParamInfo2017 info, byte[] value) {
        StringBuilder s = new StringBuilder();
        s.append("MATCH table=").append(info.tableNo)
                .append(" index=").append(info.paramIndex)
                .append(" name='").append(info.name).append("'")
                .append(" type=").append(info.typeId)
                .append(" size=").append(info.size)
                .append(" def=").append(info.def)
                .append(" range=").append(info.min).append("..").append(info.max);
        if (value == null) {
            s.append(" current=<E2 no read>");
        } else {
            s.append(" currentRaw=").append(DumlV2.hex(value));
            if (value.length > 0 && value.length <= 4) {
                long u = 0;
                for (int i = 0; i < value.length; i++) u |= ((long) value[i] & 0xFFL) << (8 * i);
                s.append(" uLE=").append(u);
                if (value.length == 4) {
                    int bits = (int) u;
                    s.append(" f32LE=").append(Float.intBitsToFloat(bits));
                }
            }
        }
        return s.toString();
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

    private String hwString(byte[] p) {
        if (p == null || p.length < 18) return "";
        StringBuilder s = new StringBuilder();
        for (int i = 2; i < Math.min(18, p.length); i++) {
            int b = p[i] & 0xFF;
            if (b >= 32 && b <= 126) s.append((char) b);
        }
        return s.toString().trim();
    }

    private static String nz(String s) { return s == null ? "" : s; }

    private static void sleep(long ms) {
        try { Thread.sleep(ms); } catch (InterruptedException e) { Thread.currentThread().interrupt(); }
    }

    private void append(String s) {
        runOnUiThread(() -> {
            log.append(s + "\n");
            View v = (View) log.getParent();
            if (v instanceof ScrollView) {
                ((ScrollView) v).post(() -> ((ScrollView) v).fullScroll(View.FOCUS_DOWN));
            }
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
            this.reader = new Thread(this::readLoop, "mini4k-gnss-scan-rx");
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
            }, "mini4k-gnss-scan-keepalive");
            keepalive.setDaemon(true);
            keepalive.start();
        }

        void clearQueue() { rx.clear(); }
        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        long dumlFrames() { return dumlFrames; }
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
                    } else {
                        body = null;
                    }
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
            } else {
                otherUnits++;
            }
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

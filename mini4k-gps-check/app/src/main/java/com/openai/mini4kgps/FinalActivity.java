package com.openai.mini4kgps;

import android.app.Activity;
import android.app.AlertDialog;
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
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Mini 4K defensive GNSS parameter tool.
 *
 * Transport: Android Open Accessory on the TOP RC-N1 phone port.
 * RCLink: 55 CC | route u16 LE | payload length u32 LE | DUML.
 * FLYC parameter traffic can use DJI SIMPLE encryption; both encrypted/plain paths are probed
 * read-only and the working one is remembered. GPS writes stay locked until the FC itself returns
 * exact gps_enable identity + 1-byte 0..1 shape + a 0/1 read-back.
 */
public class FinalActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.FINAL_ACCESSORY_PERMISSION";
    private static final int RC_N1_VID = 0x2CA3;
    private static final int RC_N1_PID = 0x1020;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x7300);

    private UsbManager usbManager;
    private TextView log;
    private Button check;
    private Button gpsOff;
    private Button gpsOn;
    private volatile boolean pendingPermissionCheck;

    private enum ParamProtocol { NONE, INDEX_2017, HASH_2015 }
    private volatile ParamProtocol confirmedProtocol = ParamProtocol.NONE;
    private volatile Boolean confirmedEncrypted = null;
    private volatile Integer confirmedTable = null;
    private volatile Integer confirmedIndex = null;
    private volatile Long confirmedHash = null;
    private volatile String confirmedName = null;
    private volatile Integer confirmedValue = null;

    private static final class Route {
        final int senderType, senderIndex, receiverType, receiverIndex;
        Route(int st, int si, int rt, int ri) {
            senderType = st; senderIndex = si; receiverType = rt; receiverIndex = ri;
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
        title.setText("Mini 4K GPS TOOL v1.0");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        check = new Button(this);
        check.setText("FULL CHECK");
        root.addView(check, top(12));

        gpsOff = new Button(this);
        gpsOff.setText("GPS OFF (LOCKED)");
        gpsOff.setEnabled(false);
        root.addView(gpsOff, top(8));

        gpsOn = new Button(this);
        gpsOn.setText("GPS ON (LOCKED)");
        gpsOn.setEnabled(false);
        root.addView(gpsOn, top(8));

        log = new TextView(this);
        log.setTextSize(13);
        log.setTextIsSelectable(true);
        log.setMovementMethod(new ScrollingMovementMethod());
        log.setPadding(0, dp(12), 0, dp(12));
        ScrollView sc = new ScrollView(this);
        sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        check.setOnClickListener(v -> runBusy(this::fullCheck));
        gpsOff.setOnClickListener(v -> new AlertDialog.Builder(this)
                .setTitle("GPS OFF — наземная проверка")
                .setMessage("Будет записан 0 только в параметр gps_enable, уже подтверждённый контроллером. GPS-удержание и GNSS-RTH станут недоступны. Первый тест — моторы НЕ запускать. Продолжить?")
                .setNegativeButton("Отмена", null)
                .setPositiveButton("GPS OFF", (d, w) -> runBusy(() -> writeGps(0)))
                .show());
        gpsOn.setOnClickListener(v -> runBusy(() -> writeGps(1)));
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
        append("Mini 4K GPS TOOL v1.0");
        append("FULL CHECK не пишет gps_enable. GPS OFF/ON заблокированы до строгой проверки параметра.");
        append("");
        append("ПОДКЛЮЧЕНИЕ:");
        append("1) Включите Mini 4K и RC-N1 и дождитесь их связи.");
        append("2) Полностью закройте DJI Fly.");
        append("3) Подключите телефон к ВЕРХНЕМУ телефонному порту RC-N1 — тому, которым обычно пользуется DJI Fly.");
        append("4) Нажмите FULL CHECK и разрешите доступ к USB, если Android спросит.");
        append("");
        append("Внутри используются RC-N1 AOA bootstrap + RCLink keepalive + 55 CC/0x5749 envelope. FLYC проверяется и с SIMPLE encryption, и без неё.");
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
                if (ok && pendingPermissionCheck) {
                    pendingPermissionCheck = false;
                    runBusy(FinalActivity.this::fullCheck);
                }
            } else if (UsbManager.ACTION_USB_ACCESSORY_ATTACHED.equals(a)) {
                append("AOA accessory подключён.");
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(a)) {
                append("AOA accessory отключён.");
                clearConfirmation();
            }
        }
    };

    private void runBusy(Runnable task) {
        runOnUiThread(() -> {
            check.setEnabled(false);
            gpsOff.setEnabled(false);
            gpsOn.setEnabled(false);
        });
        io.submit(() -> {
            try { task.run(); }
            catch (Throwable t) { append("ОШИБКА: " + t.getClass().getSimpleName() + ": " + t.getMessage()); }
            finally {
                runOnUiThread(() -> {
                    check.setEnabled(true);
                    boolean ready = confirmedProtocol != ParamProtocol.NONE;
                    gpsOff.setEnabled(ready);
                    gpsOn.setEnabled(ready);
                    gpsOff.setText(ready ? "GPS OFF" : "GPS OFF (LOCKED)");
                    gpsOn.setText(ready ? "GPS ON" : "GPS ON (LOCKED)");
                });
            }
        });
    }

    private void clearConfirmation() {
        confirmedProtocol = ParamProtocol.NONE;
        confirmedEncrypted = null;
        confirmedTable = null;
        confirmedIndex = null;
        confirmedHash = null;
        confirmedName = null;
        confirmedValue = null;
        runOnUiThread(() -> {
            gpsOff.setEnabled(false);
            gpsOn.setEnabled(false);
            gpsOff.setText("GPS OFF (LOCKED)");
            gpsOn.setText("GPS ON (LOCKED)");
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
        append("Android запросил USB Accessory permission. Разрешите — FULL CHECK продолжится автоматически.");
    }

    private void noAccessoryDiagnosis() {
        append("AOA accessory DJI не найден.");
        boolean servicePort = false;
        for (UsbDevice d : usbManager.getDeviceList().values()) {
            append(String.format(Locale.US, "USB host: VID=%04X PID=%04X IF=%d", d.getVendorId(), d.getProductId(), d.getInterfaceCount()));
            for (int j = 0; j < d.getInterfaceCount(); j++) {
                UsbInterface u = d.getInterface(j);
                append(String.format(Locale.US, "  IF%d class=0x%02X sub=0x%02X proto=0x%02X eps=%d", j, u.getInterfaceClass(), u.getInterfaceSubclass(), u.getInterfaceProtocol(), u.getEndpointCount()));
            }
            if (d.getVendorId() == RC_N1_VID && d.getProductId() == RC_N1_PID) servicePort = true;
        }
        append("");
        if (servicePort) {
            append("Виден 2CA3:1020 — это НИЖНИЙ сервисный USB RC-N1. Для этой версии он не нужен.");
            append("Переставьте телефон в ВЕРХНИЙ телефонный порт RC-N1, закройте DJI Fly и повторите FULL CHECK.");
        } else {
            append("Подключите телефон именно к ВЕРХНЕМУ телефонному порту RC-N1 (обычный кабель DJI Fly). Не к дрону и не к нижнему USB пульта.");
        }
    }

    private void fullCheck() {
        runOnUiThread(() -> log.setText(""));
        clearConfirmation();
        append("=== FULL CHECK v1.0 ===");
        append("Protocol crypto self-test: " + (DumlV2.selfTest() ? "PASS" : "FAIL"));
        if (!DumlV2.selfTest()) {
            append("Внутренний SIMPLE crypto test провален. Команды не отправляю.");
            return;
        }

        UsbAccessory a = chooseAccessory();
        if (a == null) {
            noAccessoryDiagnosis();
            return;
        }
        append("AOA: manufacturer='" + nz(a.getManufacturer()) + "' model='" + nz(a.getModel()) + "' version='" + nz(a.getVersion()) + "'");
        if (!usbManager.hasPermission(a)) {
            requestAccessoryPermission(a);
            return;
        }

        AoaSession s = null;
        try {
            s = AoaSession.open(usbManager, a, seq);
            if (s == null) {
                append("AOA pipe не открылся. Убедитесь, что DJI Fly полностью закрыт, и повторите.");
                return;
            }
            s.startProtocol();
            sleep(350);
            append("AOA pipe: OPEN; bootstrap=sent; keepalive=running; route=" + s.routeString());
            append("RX so far: DUML=" + s.dumlFrames() + ", RCLink DUML units=" + s.dumlUnits() + ", other units=" + s.otherUnits());

            append("");
            append("[1/4] Проверка канала APP -> Flight Controller");
            DumlV2.Frame version = transact(s, APP_FLYC, DumlV2.CMDSET_GENERAL, 0x01, new byte[0], false, 1200);
            if (version != null) {
                append("COMMON/Version: ответ есть, seq=" + version.seq + " payload=" + shortHex(version.payload, 40));
                String hw = hwString(version.payload);
                if (!hw.isEmpty()) append("FC HW: '" + hw + "'");
            } else {
                append("COMMON/Version ACK не пойман; продолжаю параметрический канал отдельно.");
            }

            append("");
            append("[2/4] Определение FLYC param transport (encrypted/plain)");
            Boolean mode = establishFlycMode(s);
            if (mode == null) {
                append("FLYC param transport не ответил ни encrypted, ни plaintext способом.");
                append("Ничего в gps_enable не записано.");
                append("RX: DUML=" + s.dumlFrames() + ", units=" + s.dumlUnits() + ", other=" + s.otherUnits());
                return;
            }
            confirmedEncrypted = mode;
            append("FLYC mode: " + (mode ? "SIMPLE ENCRYPTED" : "PLAINTEXT") + " — подтверждён ответом контроллера.");

            append("");
            append("[3/4] 2017 table/index — поиск gps_enable по фактическому имени");
            if (find2017(s, mode)) {
                success(s);
                return;
            }

            append("");
            append("[4/4] 2015 hash fallback");
            if (find2015(s, mode)) {
                success(s);
                return;
            }

            append("");
            append("РЕЗУЛЬТАТ: транспорт до FC работает, но безопасно подтверждённый gps_enable не найден.");
            append("GPS OFF/ON остаются заблокированы. НИЧЕГО В gps_enable НЕ ЗАПИСАНО.");
            append("RX: DUML=" + s.dumlFrames() + ", units=" + s.dumlUnits() + ", other=" + s.otherUnits());
        } catch (Exception e) {
            append("FULL CHECK: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            if (s != null) s.close();
            append("AOA pipe: CLOSED");
        }
    }

    /**
     * The newer RC app path may require SIMPLE encryption for FLYC config frames. We do not guess:
     * for each candidate mode we send transient assistant-access unlock and then a read-only E0
     * table query. If 2017 is unavailable, exact F7 gps hashes are used as fallback proof.
     */
    private Boolean establishFlycMode(AoaSession s) throws Exception {
        boolean[] modes = new boolean[]{true, false};
        for (boolean enc : modes) {
            append("  probe " + (enc ? "encrypted" : "plaintext") + "...");
            transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xDF, DumlV2.le32(1), enc, 650); // transient config access
            DumlV2.Frame e0 = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0, DumlV2.le16(0), enc, 900);
            if (e0 != null) {
                append("  E0 table0 response=" + shortHex(e0.payload, 32));
                return enc;
            }
        }

        String[] names = candidateNames();
        for (boolean enc : modes) {
            for (String n : names) {
                long h = DumlV2.parameterHash(n);
                DumlV2.Frame f = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xF7, DumlV2.le32(h), enc, 800);
                if (f != null) {
                    append(String.format(Locale.US, "  F7 response in %s mode hash=0x%08X", enc ? "encrypted" : "plaintext", h));
                    return enc;
                }
            }
        }
        return null;
    }

    private boolean find2017(AoaSession s, boolean enc) throws Exception {
        List<DumlV2.TableAttr2017> tables = new ArrayList<>();
        for (int t = 0; t < 32; t++) {
            DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0, DumlV2.le16(t), enc, 700);
            if (r == null) {
                if (t == 0) append("2017: E0 table0 timeout.");
                break;
            }
            if (r.payload.length <= 2) {
                append("2017: end/status at table " + t + " payload=" + shortHex(r.payload, 16));
                break;
            }
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(r.payload);
            if (a == null || a.status != 0 || a.entriesNum <= 0 || a.entriesNum > 20000) {
                append("2017: invalid table response t=" + t + " payload=" + shortHex(r.payload, 40));
                break;
            }
            tables.add(a);
            append("TABLE " + a.tableNo + ": entries=" + a.entriesNum + " crc=0x" + Long.toHexString(a.entriesCrc));
        }
        if (tables.isEmpty()) return false;

        Set<Long> seen = new HashSet<>();
        // Read-only fast pass over the region where Mini-family GPS parameters commonly appear.
        for (DumlV2.TableAttr2017 a : tables) {
            if (a.tableNo != 0) continue;
            int from = 300;
            int to = (int) Math.min(a.entriesNum - 1, 900);
            if (from <= to) {
                append("Fast read-only scan table0 " + from + ".." + to);
                int noReply = 0;
                for (int i = from; i <= to; i++) {
                    seen.add(key(a.tableNo, i));
                    Probe p = probeInfo2017(s, enc, a.tableNo, i, 360);
                    if (p == Probe.FOUND) return true;
                    if (p == Probe.NO_REPLY && ++noReply >= 15) {
                        append("Fast scan paused after 15 consecutive E1 timeouts.");
                        break;
                    }
                    if (p != Probe.NO_REPLY) noReply = 0;
                }
            }
        }

        for (DumlV2.TableAttr2017 a : tables) {
            int total = (int) Math.min(a.entriesNum, 10000);
            append("Full read-only scan table " + a.tableNo + " 0.." + (total - 1));
            int noReply = 0;
            for (int i = 0; i < total; i++) {
                if (seen.contains(key(a.tableNo, i))) continue;
                Probe p = probeInfo2017(s, enc, a.tableNo, i, 360);
                if (p == Probe.FOUND) return true;
                if (p == Probe.NO_REPLY) {
                    noReply++;
                    if (noReply >= 20) {
                        append("Table " + a.tableNo + " scan stopped after 20 consecutive E1 timeouts.");
                        break;
                    }
                } else noReply = 0;
                if (i > 0 && i % 100 == 0) append("  progress: table " + a.tableNo + " index " + i + "/" + total);
            }
        }
        return false;
    }

    private enum Probe { FOUND, REPLIED, NO_REPLY }

    private Probe probeInfo2017(AoaSession s, boolean enc, int table, int index, int timeout) throws Exception {
        byte[] q = DumlV2.concat(DumlV2.le16(table), DumlV2.le16(index));
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE1, q, enc, timeout);
        if (r == null) return Probe.NO_REPLY;
        DumlV2.ParamInfo2017 info = DumlV2.ParamInfo2017.parse(r.payload);
        if (info == null) return Probe.REPLIED;
        if (!isGpsName(info.name)) return Probe.REPLIED;

        append("GPS candidate: table=" + info.tableNo + " index=" + info.paramIndex +
                " name='" + info.name + "' type=" + info.typeId + " size=" + info.size +
                " range=" + info.min + ".." + info.max + " status=" + info.status);
        if (!safeGpsShape(info.name, info.status, info.typeId, info.size, info.min, info.max)) {
            append("Candidate rejected: identity/shape is not safely exact.");
            return Probe.REPLIED;
        }
        Integer v = readIndex(s, enc, info.tableNo, info.paramIndex);
        if (v == null || (v != 0 && v != 1)) {
            append("Candidate rejected: E2 read-back is not 0/1.");
            return Probe.REPLIED;
        }
        confirmedProtocol = ParamProtocol.INDEX_2017;
        confirmedTable = info.tableNo;
        confirmedIndex = info.paramIndex;
        confirmedName = info.name;
        confirmedValue = v;
        return Probe.FOUND;
    }

    private boolean find2015(AoaSession s, boolean enc) throws Exception {
        for (String n : candidateNames()) {
            long h = DumlV2.parameterHash(n);
            DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xF7, DumlV2.le32(h), enc, 850);
            if (r == null) continue;
            DumlV2.ParamInfo2015 info = DumlV2.ParamInfo2015.parse(r.payload);
            if (info == null) {
                append("F7 hash response but unknown info format: " + shortHex(r.payload, 40));
                continue;
            }
            append(String.format(Locale.US, "F7 hash=0x%08X -> name='%s' type=%d size=%d range=%d..%d status=%d",
                    h, info.name, info.typeId, info.size, info.min, info.max, info.status));
            if (!safeGpsShape(info.name, info.status, info.typeId, info.size, info.min, info.max)) continue;
            Integer v = readHash(s, enc, h);
            if (v == null || (v != 0 && v != 1)) continue;
            confirmedProtocol = ParamProtocol.HASH_2015;
            confirmedHash = h;
            confirmedName = info.name;
            confirmedValue = v;
            return true;
        }
        return false;
    }

    private String[] candidateNames() {
        return new String[]{
                "gps_enable",
                "g_config.gps_cfg.gps_enable",
                "gps_enable|g_config.gps_cfg.gps_enable",
                "g_config.gps_cfg.gps_enable|gps_enable"
        };
    }

    private void success(AoaSession s) {
        append("");
        append("=== УСПЕХ ===");
        append("FC подтвердил настоящий параметр: '" + confirmedName + "'");
        append("Current value=" + confirmedValue + " (1=GPS ON, 0=GPS OFF)");
        append("Transport=" + (Boolean.TRUE.equals(confirmedEncrypted) ? "SIMPLE encrypted" : "plaintext"));
        if (confirmedProtocol == ParamProtocol.INDEX_2017) {
            append("Protocol=2017 table=" + confirmedTable + " index=" + confirmedIndex);
        } else {
            append(String.format(Locale.US, "Protocol=2015 hash=0x%08X", confirmedHash));
        }
        append("GPS OFF / GPS ON разблокированы. FULL CHECK gps_enable НЕ МЕНЯЛ.");
        append("RX DUML=" + s.dumlFrames() + ", route=" + s.routeString());
    }

    private void writeGps(int target) {
        if (confirmedProtocol == ParamProtocol.NONE || confirmedEncrypted == null || confirmedName == null) {
            append("Запись заблокирована: сначала нужен успешный FULL CHECK.");
            return;
        }
        UsbAccessory a = chooseAccessory();
        if (a == null || !usbManager.hasPermission(a)) {
            append("AOA connection/permission потеряно. Снова подключитесь и выполните FULL CHECK.");
            return;
        }

        append("");
        append("=== GPS " + (target == 0 ? "OFF" : "ON") + " ===");
        AoaSession s = null;
        try {
            s = AoaSession.open(usbManager, a, seq);
            if (s == null) {
                append("AOA pipe не открылся.");
                return;
            }
            s.startProtocol();
            sleep(300);
            boolean enc = confirmedEncrypted;
            transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xDF, DumlV2.le32(1), enc, 650);

            Integer before = confirmedProtocol == ParamProtocol.INDEX_2017
                    ? readIndex(s, enc, confirmedTable, confirmedIndex)
                    : readHash(s, enc, confirmedHash);
            if (before == null || (before != 0 && before != 1)) {
                append("ОТМЕНА: обязательный pre-read не подтвердил текущее 0/1.");
                return;
            }
            append("Pre-read: " + confirmedName + "=" + before);
            if (before == target) {
                append("Значение уже " + target + ". Ничего не записывал.");
                confirmedValue = target;
                return;
            }

            DumlV2.Frame wr;
            if (confirmedProtocol == ParamProtocol.INDEX_2017) {
                byte[] q = DumlV2.concat(DumlV2.le16(confirmedTable), DumlV2.le16(1),
                        DumlV2.le16(confirmedIndex), new byte[]{(byte) target});
                wr = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE3, q, enc, 1200);
            } else {
                byte[] q = DumlV2.concat(DumlV2.le32(confirmedHash), new byte[]{(byte) target});
                wr = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xF9, q, enc, 1200);
            }
            append("Write ACK: " + (wr == null ? "нет/не распознан" : shortHex(wr.payload, 32)));
            sleep(200);

            Integer after = confirmedProtocol == ParamProtocol.INDEX_2017
                    ? readIndex(s, enc, confirmedTable, confirmedIndex)
                    : readHash(s, enc, confirmedHash);
            if (after != null && after == target) {
                confirmedValue = after;
                append("УСПЕХ: FC read-back=" + after + ". GPS " + (target == 0 ? "OFF" : "ON") + " подтверждён.");
                if (target == 0) append("Первый тест только на земле. Моторы не запускать; для возврата нажмите GPS ON.");
            } else {
                append("НЕ ПОДТВЕРЖДЕНО: post-read=" + after + ". Состояние неизвестно — моторы не запускать.");
            }
        } catch (Exception e) {
            append("WRITE: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            if (s != null) s.close();
            append("AOA pipe: CLOSED");
        }
    }

    private Integer readIndex(AoaSession s, boolean enc, int table, int index) throws Exception {
        byte[] q = DumlV2.concat(DumlV2.le16(table), DumlV2.le16(1), DumlV2.le16(index));
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE2, q, enc, 850);
        if (r == null || r.payload.length < 7) return null;
        int st = DumlV2.u16(r.payload, 0);
        int got = DumlV2.u16(r.payload, 4);
        if (st != 0 || got != index) return null;
        return r.payload[6] & 0xFF;
    }

    private Integer readHash(AoaSession s, boolean enc, long hash) throws Exception {
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xF8, DumlV2.le32(hash), enc, 850);
        if (r == null || r.payload.length < 6) return null;
        int st = r.payload[0] & 0xFF;
        long got = DumlV2.u32(r.payload, 1);
        if (st != 0 || got != (hash & 0xFFFFFFFFL)) return null;
        return r.payload[5] & 0xFF;
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

    private boolean safeGpsShape(String name, int status, int type, int size, long min, long max) {
        return status == 0 && isGpsName(name) && size == 1 && min == 0 && max == 1 && (type == 0 || type == 11);
    }

    private boolean isGpsName(String name) {
        if (name == null) return false;
        for (String p : name.trim().split("\\|")) {
            String x = p.trim();
            if ("gps_enable".equals(x) || "g_config.gps_cfg.gps_enable".equals(x)) return true;
        }
        return false;
    }

    private static long key(int table, int index) {
        return (((long) table) << 32) | (index & 0xFFFFFFFFL);
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
            this.reader = new Thread(this::readLoop, "mini4k-aoa-rx");
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
            }, "mini4k-aoa-keepalive");
            keepalive.setDaemon(true);
            keepalive.start();
        }

        void clearQueue() { rx.clear(); }
        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        long dumlUnits() { return dumlUnits; }
        long dumlFrames() { return dumlFrames; }
        long otherUnits() { return otherUnits; }
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
                        bodyLeft = 0; body = null; bodyType = -1;
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

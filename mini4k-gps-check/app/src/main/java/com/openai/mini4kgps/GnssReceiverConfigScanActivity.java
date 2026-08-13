package com.openai.mini4kgps;

import android.app.Activity;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.hardware.usb.UsbAccessory;
import android.hardware.usb.UsbManager;
import android.os.Build;
import android.os.Bundle;
import android.os.ParcelFileDescriptor;
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
 * One-shot, read-only map of the FC parameter store with GNSS/RF classification.
 *
 * Reads only:
 *  - FLYC E0 table attributes
 *  - FLYC E1 item attributes/names
 *  - FLYC E2 current values for GNSS/RF candidates
 *  - a small set of known read-only FLYC GET commands for context
 *
 * Never sends E3/F9 writes, 0xDF unlock, E9 positive/exec selectors, or receiver SET commands.
 */
public class GnssReceiverConfigScanActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_RX_SCAN_PERMISSION";
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x8D00);
    private final AtomicBoolean busy = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView log;
    private Button scan;
    private Button copy;
    private volatile boolean pendingPermission;
    private volatile String lastReport = "";

    private static final class Route {
        final int st, si, rt, ri;
        Route(int st, int si, int rt, int ri) { this.st = st; this.si = si; this.rt = rt; this.ri = ri; }
    }

    private static final Route APP_FLYC = new Route(
            DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0);

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        usbManager = (UsbManager) getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
    }

    @Override protected void onDestroy() {
        try { unregisterReceiver(usbReceiver); } catch (Exception ignored) {}
        io.shutdownNow();
        super.onDestroy();
    }

    private void buildUi() {
        int pad = dp(16);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("Mini 4K ONE-SHOT GNSS PARAM MAP v2.4");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        scan = new Button(this);
        scan.setText("RUN ONE BIG READ-ONLY TEST");
        LinearLayout.LayoutParams bp = new LinearLayout.LayoutParams(-1, -2);
        bp.topMargin = dp(12);
        root.addView(scan, bp);

        copy = new Button(this);
        copy.setText("COPY FULL REPORT");
        copy.setEnabled(false);
        root.addView(copy, top(8));

        log = new TextView(this);
        log.setTextSize(12);
        log.setTextIsSelectable(true);
        log.setPadding(0, dp(12), 0, dp(12));
        ScrollView sc = new ScrollView(this);
        sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        append("ОДИН ТЕСТ. ТОЛЬКО ЧТЕНИЕ. Моторы OFF.");
        append("Сканирует ВСЕ доступные FLYC config tables и ВСЕ имена параметров, а не только заранее придуманные индексы.");
        append("Для GNSS/RF-кандидатов читает текущее значение и показывает: table/index, name, назначение по имени, range/default, hash, read path и возможный paired write path.");
        append("E3/F9/0xDF/E9 EXEC/RTK SET: НЕ ОТПРАВЛЯЮТСЯ.");
        append("После теста нажми COPY FULL REPORT и пришли текст — больше скриншоты по кускам не нужны.");
        append("");

        scan.setOnClickListener(v -> begin());
        copy.setOnClickListener(v -> copyReport());
    }

    private LinearLayout.LayoutParams top(int d) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = dp(d);
        return p;
    }

    private int dp(int x) { return Math.round(x * getResources().getDisplayMetrics().density); }

    private void copyReport() {
        String r = lastReport;
        if (r == null || r.isEmpty()) return;
        ClipboardManager cm = (ClipboardManager)getSystemService(Context.CLIPBOARD_SERVICE);
        if (cm != null) {
            cm.setPrimaryClip(ClipData.newPlainText("Mini4K GNSS full map", r));
            append("FULL REPORT COPIED TO CLIPBOARD (" + r.length() + " chars)");
        }
    }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(usbReceiver, f, Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver, f);
    }

    private final BroadcastReceiver usbReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context c, Intent i) {
            if (ACTION_USB_PERMISSION.equals(i.getAction())) {
                boolean ok = i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(ok ? "USB permission: OK" : "USB permission: DENIED");
                if (ok && pendingPermission) { pendingPermission = false; begin(); }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(i.getAction())) {
                append("RC-N1 AOA отключён.");
            }
        }
    };

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] as = usbManager.getAccessoryList();
        if (as == null || as.length == 0) return null;
        for (UsbAccessory a : as) if ("DJI".equalsIgnoreCase(a.getManufacturer())) return a;
        return as[0];
    }

    private void requestPermission(UsbAccessory a) {
        pendingPermission = true;
        PendingIntent pi = PendingIntent.getBroadcast(this, 0,
                new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        usbManager.requestPermission(a, pi);
    }

    private void begin() {
        if (!busy.compareAndSet(false, true)) return;
        UsbAccessory a = chooseAccessory();
        if (a == null) {
            append("AOA DJI не найден. Закрой DJI Fly и подключи телефон к верхнему порту RC-N1.");
            busy.set(false);
            return;
        }
        if (!usbManager.hasPermission(a)) {
            requestPermission(a);
            busy.set(false);
            return;
        }
        scan.setEnabled(false);
        copy.setEnabled(false);
        lastReport = "";
        io.submit(() -> {
            try { performScan(a); }
            catch (Throwable t) { append("SCAN ERROR: " + t.getClass().getSimpleName() + ": " + t.getMessage()); }
            finally {
                busy.set(false);
                runOnUiThread(() -> scan.setEnabled(true));
            }
        });
    }

    private void performScan(UsbAccessory a) throws Exception {
        runOnUiThread(() -> log.setText(""));
        StringBuilder report = new StringBuilder(256 * 1024);
        line(report, "MINI 4K ONE-SHOT GNSS PARAM MAP v2.4");
        line(report, "READ-ONLY: E0/E1/E2 + known GET commands only");
        line(report, "WRITE/EXEC SENT: E3=0 F9=0 0xDF=0 E9-positive=0 RTK-SET=0");
        line(report, "");

        append("=== ONE-SHOT FULL GNSS PARAM MAP v2.4 ===");
        append("WRITE/EXEC COMMANDS SENT: 0");
        boolean self = DumlV2.selfTest();
        append("DUML self-test: " + (self ? "PASS" : "FAIL"));
        line(report, "DUML self-test=" + (self ? "PASS" : "FAIL"));
        if (!self) {
            finishReport(report);
            return;
        }

        AoaSession s = AoaSession.open(usbManager, a, seq);
        if (s == null) {
            append("AOA pipe не открылся.");
            line(report, "AOA=FAILED");
            finishReport(report);
            return;
        }

        try {
            s.startProtocol();
            sleep(350);
            append("AOA OPEN route=" + s.routeString());
            line(report, "AOA route=" + s.routeString());

            Boolean enc = establishReadOnlyMode(s, report);
            if (enc == null) {
                append("E0 READ не ответил. Ничего не изменено.");
                line(report, "FATAL: FLYC E0 config table read did not respond");
                finishReport(report);
                return;
            }
            append("READ transport=" + (enc ? "SIMPLE encrypted" : "PLAINTEXT"));
            line(report, "FLYC config transport=" + (enc ? "SIMPLE encrypted" : "PLAINTEXT"));

            List<DumlV2.TableAttr2017> tables = readTables(s, enc, report);
            if (tables.isEmpty()) {
                append("Таблицы параметров не прочитаны.");
                line(report, "FATAL: no readable FLYC config tables");
                finishReport(report);
                return;
            }

            int scanned = 0;
            int valid = 0;
            int matches = 0;
            int valueReads = 0;
            int timeouts = 0;
            int high = 0;
            List<String> mapLines = new ArrayList<>();

            line(report, "");
            line(report, "=== COMPLETE PARAMETER INDEX (ALL READABLE NAMES) ===");
            append("Сканирую полный индекс параметров. Это один проход; может занять несколько минут...");

            for (DumlV2.TableAttr2017 table : tables) {
                int total = (int)Math.min(table.entriesNum, 20000);
                append("TABLE " + table.tableNo + ": " + total + " entries");
                int noReply = 0;

                for (int index = 0; index < total; index++) {
                    byte[] q = DumlV2.concat(DumlV2.le16(table.tableNo), DumlV2.le16(index));
                    DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE1, q, enc, 380);
                    scanned++;
                    if (r == null) {
                        timeouts++;
                        noReply++;
                        if (noReply >= 24) {
                            line(report, "INDEX-STOP|table=" + table.tableNo + "|reason=24 consecutive E1 timeouts|at=" + index);
                            break;
                        }
                        continue;
                    }
                    noReply = 0;
                    DumlV2.ParamInfo2017 info = DumlV2.ParamInfo2017.parse(r.payload);
                    if (info == null || info.status != 0 || info.name == null || info.name.isEmpty()) continue;
                    valid++;

                    long hash = DumlV2.parameterHash(info.name);
                    line(report, "INDEX|table=" + info.tableNo + "|index=" + info.paramIndex +
                            "|name=" + safe(info.name) + "|hash=0x" + hex8(hash) +
                            "|type=" + info.typeId + "|size=" + info.size +
                            "|default=" + info.def + "|min=" + info.min + "|max=" + info.max);

                    String category = classify(info.name);
                    if (category != null) {
                        matches++;
                        if (highValue(info.name, category)) high++;
                        byte[] value = readIndexRaw(s, enc, info.tableNo, info.paramIndex, info.size);
                        if (value != null) valueReads++;
                        String purpose = purpose(info.name, category);
                        String map = "MAP|category=" + category +
                                "|storage=FLYC_CONFIG table=" + info.tableNo + " index=" + info.paramIndex +
                                "|name=" + safe(info.name) +
                                "|purpose=" + purpose +
                                "|confidence=NAME_BASED" +
                                "|current=" + decodeValue(value) +
                                "|type=" + info.typeId + "|size=" + info.size +
                                "|default=" + info.def + "|range=" + info.min + ".." + info.max +
                                "|hash=0x" + hex8(hash) +
                                "|read=FLYC E2(table,index)" +
                                "|paired_write_candidate=FLYC E3(table,index) NOT_SENT";
                        mapLines.add(map);
                        append((highValue(info.name, category) ? "*** " : "") + map);
                    }

                    if (index > 0 && index % 100 == 0) {
                        append("progress table=" + table.tableNo + " " + index + "/" + total +
                                " | readable=" + valid + " candidates=" + matches);
                    }
                }
            }

            line(report, "");
            line(report, "=== GNSS / RF PARAMETER MAP ===");
            if (mapLines.isEmpty()) line(report, "NO NAME-BASED GNSS/RF MATCHES IN E1 TABLE NAMES");
            else for (String m : mapLines) line(report, m);

            line(report, "");
            line(report, "=== KNOWN READ-ONLY FLYC INTERFACES ===");
            readKnownInterface(s, enc, 0x45, "GPS_SNR_GET", report);
            readKnownInterface(s, enc, 0x57, "GPS_GLNS_INFO", report);
            readKnownInterface(s, enc, 0xA1, "AGPS_STATUS_GET", report);
            readKnownInterface(s, enc, 0x37, "DEVICE_INFO_GET", report);
            readKnownInterface(s, enc, 0xFD, "PRODUCT_TYPE_GET", report);

            line(report, "");
            line(report, "=== SUMMARY ===");
            line(report, "tables=" + tables.size() + " scanned_E1=" + scanned + " readable_names=" + valid +
                    " GNSS_RF_candidates=" + matches + " high_value=" + high +
                    " E2_value_reads=" + valueReads + " E1_timeouts=" + timeouts);
            line(report, "RX_DUML=" + s.dumlFrames() + " route=" + s.routeString());
            line(report, "WRITE/EXEC SENT: E3=0 F9=0 0xDF=0 E9-positive=0 RTK-SET=0");

            append("");
            append("=== ГОТОВО ===");
            append("Tables=" + tables.size() + " | readable params=" + valid + " | GNSS/RF candidates=" + matches + " | high-value=" + high);
            append("Полный индекс ВСЕХ имён тоже сохранён в отчёте.");
            append("Нажми COPY FULL REPORT и пришли сюда текст. По нему уже разбираем без следующих поисковых APK.");
            append("WRITE/EXEC COMMANDS SENT: 0");
            finishReport(report);
        } finally {
            s.close();
            append("AOA CLOSED; WRITE/EXEC COMMANDS SENT: 0");
        }
    }

    private void finishReport(StringBuilder report) {
        lastReport = report.toString();
        runOnUiThread(() -> copy.setEnabled(!lastReport.isEmpty()));
    }

    private Boolean establishReadOnlyMode(AoaSession s, StringBuilder report) throws Exception {
        for (boolean enc : new boolean[]{false, true}) {
            DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0,
                    DumlV2.le16(0), enc, 1000);
            if (r == null) continue;
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(r.payload);
            line(report, "E0-probe|transport=" + (enc ? "SIMPLE" : "PLAIN") + "|raw=" + DumlV2.hex(r.payload));
            if (a != null && a.status == 0 && a.entriesNum > 0 && a.entriesNum < 20000) {
                append("E0 table0 entries=" + a.entriesNum + " crc=0x" + Long.toHexString(a.entriesCrc));
                return enc;
            }
        }
        return null;
    }

    private List<DumlV2.TableAttr2017> readTables(AoaSession s, boolean enc, StringBuilder report) throws Exception {
        List<DumlV2.TableAttr2017> out = new ArrayList<>();
        int misses = 0;
        for (int t = 0; t < 32; t++) {
            DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0,
                    DumlV2.le16(t), enc, 650);
            if (r == null) {
                misses++;
                if (!out.isEmpty() && misses >= 5) break;
                continue;
            }
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(r.payload);
            if (a == null || a.status != 0 || a.entriesNum <= 0 || a.entriesNum > 20000) {
                misses++;
                line(report, "TABLE-PROBE|requested=" + t + "|raw=" + DumlV2.hex(r.payload));
                if (!out.isEmpty() && misses >= 5) break;
                continue;
            }
            misses = 0;
            out.add(a);
            append("TABLE " + a.tableNo + " entries=" + a.entriesNum + " crc=0x" + Long.toHexString(a.entriesCrc));
            line(report, "TABLE|no=" + a.tableNo + "|entries=" + a.entriesNum + "|crc=0x" + Long.toHexString(a.entriesCrc));
        }
        return out;
    }

    private void readKnownInterface(AoaSession s, boolean preferredEnc, int cmd, String label,
                                    StringBuilder report) throws Exception {
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, cmd, new byte[0], preferredEnc, 800);
        boolean used = preferredEnc;
        if (r == null) {
            used = !preferredEnc;
            r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, cmd, new byte[0], used, 800);
        }
        if (r == null) {
            line(report, "GET|" + label + "|cmd=0x" + hex2(cmd) + "|response=NO_RESPONSE");
        } else {
            line(report, "GET|" + label + "|cmd=0x" + hex2(cmd) +
                    "|transport=" + (used ? "SIMPLE" : "PLAIN") +
                    "|len=" + r.payload.length + "|raw=" + DumlV2.hex(r.payload));
        }
    }

    private byte[] readIndexRaw(AoaSession s, boolean enc, int table, int index, int size) throws Exception {
        byte[] q = DumlV2.concat(DumlV2.le16(table), DumlV2.le16(1), DumlV2.le16(index));
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE2, q, enc, 850);
        if (r == null || r.payload.length < 6) return null;
        int st = DumlV2.u16(r.payload, 0), got = DumlV2.u16(r.payload, 4);
        if (st != 0 || got != index) return null;
        int available = r.payload.length - 6;
        int n = size > 0 ? Math.min(size, available) : available;
        if (n <= 0) return new byte[0];
        byte[] v = new byte[n];
        System.arraycopy(r.payload, 6, v, 0, n);
        return v;
    }

    private String classify(String name) {
        if (name == null) return null;
        String n = name.toLowerCase(Locale.US);
        boolean gnss = containsAny(n, "gps", "gnss", "glonass", "galileo", "beidou", "bds", "satellite", "sat_num", "satnum");

        if (containsAny(n, "lna", "agc", "antenna", "ant_gain", "antgain", "rf_gain", "receiver_gain",
                "noise_floor", "sensitivity", "sensi") ||
                (gnss && containsAny(n, "gain", "receiver", "signal", "cn0", "cno"))) return "RF/FRONTEND";

        if (containsAny(n, "agps", "a_gps", "ephemer", "almanac", "assist_data", "assistdata")) return "A-GPS/ASSIST";

        if ((gnss && containsAny(n, "acquisition", "acquire", "acq_", "tracking", "track_", "ttff",
                "hot_start", "hotstart", "warm_start", "warmstart", "cold_start", "coldstart")) ||
                containsAny(n, "gps_acq", "gps_track", "gnss_acq", "gnss_track")) return "ACQUISITION/TRACKING";

        if (containsAny(n, "galileo", "glonass", "beidou", "bds", "constellation") ||
                (gnss && containsAny(n, "mask", "select", "enable", "mode", "cfg", "config"))) return "CONSTELLATION/ENABLE";

        if (gnss && containsAny(n, "fdi", "spoof", "jam", "disagree", "abrupt", "stuck", "conform",
                "invalid", "mismatch", "disconnect", "integrity", "fault")) return "INTEGRITY/FDI";

        if (gnss && containsAny(n, "clock", "clk", "freq", "frequency", "osc", "tcxo", "pps", "drift")) return "CLOCK/TIMING";

        if (gnss && containsAny(n, "weight", "fusion", "position", "pos_", "velocity", "vel_", "accuracy",
                "hacc", "vacc", "dop", "hdop", "vdop")) return "NAV/FUSION";

        if (gnss && containsAny(n, "snr", "signal", "sat", "quality")) return "SIGNAL/SATELLITES";

        if (gnss) return "GNSS/OTHER";

        // Broad RF candidates are included even when the name does not say GPS,
        // because receiver front-end controls may be shared or generically named.
        if (containsAny(n, "rf_", ".rf", "receiver", "frontend", "front_end")) return "RF-CANDIDATE/GENERIC";
        return null;
    }

    private boolean highValue(String name, String category) {
        if (name == null) return false;
        String n = name.toLowerCase(Locale.US);
        return category.startsWith("RF/") || category.startsWith("CONSTELLATION") ||
                category.startsWith("ACQUISITION") || category.startsWith("A-GPS") ||
                containsAny(n, "gps_enable", "gnss_enable", "lna", "agc", "sensitivity", "rf_gain",
                        "constellation", "galileo", "beidou", "glonass", "ephemer", "almanac", "ttff", "tcxo");
    }

    private String purpose(String name, String category) {
        String n = name == null ? "" : name.toLowerCase(Locale.US);
        if (containsAny(n, "gps_enable", "gnss_enable")) return "GNSS enable/use gate";
        if (containsAny(n, "fdi_open") && containsAny(n, "gps", "gnss")) return "GNSS fault-detection/integrity enable";
        if (containsAny(n, "pos_weight", "position_weight")) return "GPS position weight in navigation fusion";
        if (containsAny(n, "vel_weight", "velocity_weight")) return "GPS velocity weight in navigation fusion";
        if (containsAny(n, "lna")) return "receiver low-noise-amplifier control/gain";
        if (containsAny(n, "agc")) return "receiver automatic-gain-control setting";
        if (containsAny(n, "sensitivity", "sensi", "noise_floor")) return "receiver sensitivity/noise threshold";
        if (containsAny(n, "rf_gain", "receiver_gain", "ant_gain", "antgain")) return "receiver RF/antenna gain";
        if (containsAny(n, "galileo", "glonass", "beidou", "bds", "constellation")) return "satellite constellation selection/configuration";
        if (containsAny(n, "acquisition", "acquire", "acq_", "ttff")) return "satellite acquisition/TTFF behavior";
        if (containsAny(n, "tracking", "track_")) return "satellite tracking behavior";
        if (containsAny(n, "agps", "a_gps", "ephemer", "almanac")) return "assistance/ephemeris/almanac handling";
        if (containsAny(n, "spoof", "jam", "fdi", "integrity", "fault")) return "GNSS integrity/fault/jam/spoof detection";
        if (containsAny(n, "clock", "clk", "tcxo", "pps", "freq")) return "GNSS timing/oscillator/frequency behavior";
        if (containsAny(n, "snr", "cn0", "cno", "signal")) return "GNSS signal-quality threshold/telemetry";
        if (category.startsWith("NAV/FUSION")) return "GNSS position/velocity accuracy or fusion behavior";
        if (category.startsWith("RF-CANDIDATE")) return "generic RF/receiver setting; GNSS relation requires code/firmware correlation";
        return "GNSS-related FC configuration; exact semantics require name/code correlation";
    }

    private static boolean containsAny(String n, String... keys) {
        for (String k : keys) if (n.contains(k)) return true;
        return false;
    }

    private String decodeValue(byte[] v) {
        if (v == null) return "NO_E2_VALUE";
        if (v.length == 0) return "EMPTY";
        StringBuilder s = new StringBuilder();
        s.append("raw[").append(DumlV2.hex(v)).append(']');
        if (v.length <= 4) {
            long u = 0;
            for (int i = 0; i < v.length; i++) u |= ((long)v[i] & 0xFFL) << (8 * i);
            long signed = u;
            int bits = v.length * 8;
            if (bits < 64 && (u & (1L << (bits - 1))) != 0) signed = u - (1L << bits);
            s.append(" uLE=").append(u).append(" sLE=").append(signed);
            if (v.length == 4) s.append(" f32LE=").append(Float.intBitsToFloat((int)u));
        }
        return s.toString();
    }

    private DumlV2.Frame transact(AoaSession s, Route route, int set, int id, byte[] payload,
                                  boolean encrypted, int timeoutMs) throws Exception {
        int qseq = seq.getAndIncrement() & 0xFFFF;
        s.clearQueue();
        s.sendDuml(DumlV2.packet(route.st, route.si, route.rt, route.ri,
                qseq, set, id, payload, encrypted));
        long end = System.currentTimeMillis() + timeoutMs;
        DumlV2.Frame fallback = null;
        while (System.currentTimeMillis() < end) {
            DumlV2.Frame f = s.poll(Math.min(90, Math.max(1, end - System.currentTimeMillis())));
            if (f == null || !f.response || f.cmdSet != set || f.cmdId != id) continue;
            boolean reverse = f.senderType == route.rt && f.receiverType == route.st;
            if (f.seq == qseq && reverse) return f;
            if (f.seq == qseq) return f;
            if (reverse && fallback == null) fallback = f;
        }
        return fallback;
    }

    private static String safe(String x) {
        if (x == null) return "";
        return x.replace('|', '/').replace('\n', ' ').replace('\r', ' ');
    }

    private static String hex8(long v) {
        return String.format(Locale.US, "%08X", v & 0xFFFFFFFFL);
    }

    private static String hex2(int v) {
        return String.format(Locale.US, "%02X", v & 0xFF);
    }

    private static void line(StringBuilder r, String x) { r.append(x).append('\n'); }

    private void append(String s) {
        runOnUiThread(() -> {
            log.append(s + "\n");
            View p = (View)log.getParent();
            if (p instanceof ScrollView) ((ScrollView)p).post(() -> ((ScrollView)p).fullScroll(View.FOCUS_DOWN));
        });
    }

    private static void sleep(long ms) {
        try { Thread.sleep(ms); }
        catch (InterruptedException e) { Thread.currentThread().interrupt(); }
    }

    private static final class AoaSession {
        private final ParcelFileDescriptor pfd;
        private final FileInputStream in;
        private final FileOutputStream out;
        private final AtomicInteger seq;
        private final AtomicBoolean running = new AtomicBoolean(true);
        private final LinkedBlockingQueue<DumlV2.Frame> rx = new LinkedBlockingQueue<>(5000);
        private final Object writeLock = new Object();
        private final Thread reader;
        private Thread keepalive;
        private volatile int route = 0x5749;
        private volatile long dumlFrames;
        private int headerPos;
        private final byte[] header = new byte[8];
        private long bodyLeft;
        private int bodyType = -1;
        private ByteArrayOutputStream body;

        static AoaSession open(UsbManager manager, UsbAccessory a, AtomicInteger sequence) {
            try {
                ParcelFileDescriptor p = manager.openAccessory(a);
                return p == null ? null : new AoaSession(p, sequence);
            } catch (Exception e) { return null; }
        }

        private AoaSession(ParcelFileDescriptor p, AtomicInteger sequence) {
            pfd = p;
            seq = sequence;
            in = new FileInputStream(p.getFileDescriptor());
            out = new FileOutputStream(p.getFileDescriptor());
            reader = new Thread(this::readLoop, "mini4k-one-shot-gnss-rx");
            reader.setDaemon(true);
            reader.start();
        }

        void startProtocol() throws IOException {
            byte[] boot = new byte[]{0,0,1};
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_AIRCRAFT_PROXY,0,
                    seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(4);
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_ANY,0,
                    seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(8);
            startKeepalive();
        }

        private void startKeepalive() {
            keepalive = new Thread(() -> {
                sleep(2500);
                byte[] p = new byte[]{1,1,0,(byte)0xFF,(byte)0xFF,0x20,0,0};
                while (running.get()) {
                    try {
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_REMOTE_RADIO,0,
                                seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false));
                        sleep(4);
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,14,0,
                                seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false));
                    } catch (Exception e) { break; }
                    sleep(2500);
                }
            }, "mini4k-one-shot-keepalive");
            keepalive.setDaemon(true);
            keepalive.start();
        }

        void clearQueue() { rx.clear(); }
        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        long dumlFrames() { return dumlFrames; }
        String routeString() { return String.format(Locale.US, "0x%04X", route); }

        void sendDuml(byte[] duml) throws IOException {
            int n = duml.length;
            byte[] w = new byte[8+n];
            w[0]=0x55; w[1]=(byte)0xCC;
            w[2]=(byte)(route&0xFF); w[3]=(byte)((route>>>8)&0xFF);
            w[4]=(byte)(n&0xFF); w[5]=(byte)((n>>>8)&0xFF);
            w[6]=(byte)((n>>>16)&0xFF); w[7]=(byte)((n>>>24)&0xFF);
            System.arraycopy(duml,0,w,8,n);
            synchronized (writeLock) { out.write(w); out.flush(); }
            sleep(3);
        }

        private void readLoop() {
            byte[] b = new byte[16384];
            try {
                while (running.get()) {
                    int n = in.read(b);
                    if (n < 0) break;
                    if (n > 0) feed(b,n);
                }
            } catch (Exception ignored) {} finally { running.set(false); }
        }

        private void feed(byte[] a, int n) {
            for (int i=0;i<n;i++) {
                int x=a[i]&0xFF;
                if (bodyLeft>0) {
                    if (body!=null) body.write(x);
                    bodyLeft--;
                    if (bodyLeft==0) finishUnit();
                    continue;
                }
                if (headerPos==0) {
                    if (x==0x55) { header[0]=0x55; headerPos=1; }
                    continue;
                }
                if (headerPos==1) {
                    if (x==0xCC) { header[1]=(byte)0xCC; headerPos=2; }
                    else if (x==0x55) { header[0]=0x55; headerPos=1; }
                    else headerPos=0;
                    continue;
                }
                header[headerPos++]=(byte)x;
                if (headerPos==8) {
                    int type=(header[2]&0xFF)|((header[3]&0xFF)<<8);
                    long len=((long)header[4]&0xFF)|(((long)header[5]&0xFF)<<8)|
                            (((long)header[6]&0xFF)<<16)|(((long)header[7]&0xFF)<<24);
                    headerPos=0;
                    if (len<0 || len>0x200000L) {
                        bodyLeft=0; body=null; bodyType=-1; continue;
                    }
                    bodyType=type;
                    bodyLeft=len;
                    if (type==0x5749 || type==0x7530) {
                        route=type;
                        body=new ByteArrayOutputStream((int)Math.min(len,16384));
                    } else body=null;
                    if (bodyLeft==0) finishUnit();
                }
            }
        }

        private void finishUnit() {
            if ((bodyType==0x5749 || bodyType==0x7530) && body!=null) {
                for (DumlV2.Frame f : DumlV2.frames(body.toByteArray())) {
                    dumlFrames++;
                    if (!rx.offer(f)) { rx.poll(); rx.offer(f); }
                }
            }
            bodyType=-1;
            body=null;
            bodyLeft=0;
        }

        void close() {
            running.set(false);
            try { if (keepalive!=null) keepalive.interrupt(); } catch (Exception ignored) {}
            try { reader.interrupt(); } catch (Exception ignored) {}
            try { in.close(); } catch (Exception ignored) {}
            try { out.close(); } catch (Exception ignored) {}
            try { pfd.close(); } catch (Exception ignored) {}
        }
    }
}

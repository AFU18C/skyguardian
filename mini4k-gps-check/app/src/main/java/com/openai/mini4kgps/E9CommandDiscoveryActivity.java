package com.openai.mini4kgps;

import android.app.Activity;
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
import android.view.Gravity;
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
 * Read-only discovery of the FLYC Config Command Table (DUML cmdset 0x03 / cmd 0xE9).
 *
 * Known protocol semantics from the DJI DUML dissector:
 *   selector == -1 : get command count
 *   selector < 0   : get command name
 *   selector > 0   : execute command
 *
 * This activity NEVER sends zero/positive selectors, so it cannot execute a command.
 * It is intended to discover hidden FC service/config commands related to GPS/GNSS.
 */
public class E9CommandDiscoveryActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.E9_DISCOVERY_USB";
    private static final int DEV_FLYC = DumlV2.DEV_FLYCONTROLLER;
    private static final int CMD_E9 = 0xE9;
    private static final int FALLBACK_NAME_SCAN = 256;
    private static final int MAX_NAME_SCAN = 512;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0xB300);
    private final AtomicBoolean running = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView status;
    private TextView log;
    private Button start;
    private Button stop;
    private volatile boolean pendingStart;
    private volatile AoaSession activeSession;

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        usbManager = (UsbManager)getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
    }

    @Override protected void onDestroy() {
        running.set(false);
        AoaSession s = activeSession;
        if (s != null) s.close();
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
        title.setText("Mini 4K FLYC E9 COMMAND DISCOVERY v2.2");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        start = new Button(this);
        start.setText("SCAN INTERNAL COMMAND NAMES — READ ONLY");
        root.addView(start, top(12));

        stop = new Button(this);
        stop.setText("STOP");
        stop.setEnabled(false);
        root.addView(stop, top(8));

        status = new TextView(this);
        status.setTextSize(15);
        status.setTextIsSelectable(true);
        status.setPadding(0, dp(12), 0, dp(10));
        status.setText("Моторы OFF. DJI Fly закрыть. Телефон -> верхний порт RC-N1.\n\n" +
                "Сканирует FLYC 0xE9 только отрицательными селекторами: count/name.\n" +
                "EXEC selectors (>0): 0 | PARAM WRITES: 0 | CONFIG WRITES: 0");
        root.addView(status, new LinearLayout.LayoutParams(-1, -2));

        log = new TextView(this);
        log.setTextSize(11);
        log.setTextIsSelectable(true);
        ScrollView sc = new ScrollView(this);
        sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        start.setOnClickListener(v -> begin());
        stop.setOnClickListener(v -> running.set(false));
    }

    private LinearLayout.LayoutParams top(int d) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = dp(d);
        return p;
    }

    private int dp(int x) { return Math.round(x * getResources().getDisplayMetrics().density); }

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
                if (ok && pendingStart) { pendingStart = false; begin(); }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(i.getAction())) {
                running.set(false);
                append("RC-N1 AOA disconnected");
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
        pendingStart = true;
        PendingIntent pi = PendingIntent.getBroadcast(this, 0,
                new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        usbManager.requestPermission(a, pi);
    }

    private void begin() {
        if (running.get()) return;
        UsbAccessory a = chooseAccessory();
        if (a == null) { append("AOA DJI не найден. Закрой DJI Fly и переподключи телефон к верхнему порту RC-N1."); return; }
        if (!usbManager.hasPermission(a)) { requestPermission(a); return; }
        running.set(true);
        runOnUiThread(() -> { start.setEnabled(false); stop.setEnabled(true); status.setText("START...\nEXEC selectors: 0 | WRITES: 0"); });
        io.submit(() -> runDiscovery(a));
    }

    private void runDiscovery(UsbAccessory a) {
        AoaSession s = null;
        try {
            if (!DumlV2.selfTest()) {
                setStatus("DUML SIMPLE crypto self-test: FAIL. Ничего не отправлено.");
                return;
            }

            s = AoaSession.open(usbManager, a, seq);
            activeSession = s;
            if (s == null) { setStatus("AOA pipe не открылся. DJI Fly должен быть полностью закрыт."); return; }
            s.startProtocol();
            sleep(400);

            append("=== FLYC E9 CONFIG COMMAND TABLE DISCOVERY v2.2 ===");
            append("AOA route=" + s.routeString());
            append("ONLY selectors <= -1; EXEC selectors (>0) SENT: 0");
            append("PARAM WRITES: 0; CONFIG WRITES: 0; UNLOCK 0xDF: 0");

            setStatus("1/3 Detect E9 read transport...");
            ProbeMode mode = detectMode(s);
            if (mode == null) {
                setStatus("FLYC 0xE9 не ответил ни plaintext, ни SIMPLE encrypted на read/name запросы.\nEXEC=0; WRITES=0");
                return;
            }
            append("E9 transport=" + (mode.encrypted ? "SIMPLE encrypted" : "PLAINTEXT"));
            if (mode.countFrame != null) append("E9 selector -1 response: " + describe(mode.countFrame.payload));

            int count = plausibleCount(mode.countFrame == null ? null : mode.countFrame.payload);
            int scan = count > 0 ? Math.min(MAX_NAME_SCAN, count + 12) : FALLBACK_NAME_SCAN;
            append("Plausible command count=" + (count > 0 ? count : "UNKNOWN") + "; negative-name selectors to test=" + scan);

            setStatus("2/3 Enumerating E9 command names...\nOnly negative selectors; ~до 60 сек.");
            List<String> all = new ArrayList<>();
            List<String> hits = new ArrayList<>();
            int responses = 0;
            int noReplyStreak = 0;

            // -1 is reserved for count. All selectors below are strictly negative => name lookup only.
            for (int n = 2; n <= scan + 1 && running.get(); n++) {
                int selector = -n;
                DumlV2.Frame r = transact(s, selector, mode.encrypted, 260);
                if (r == null) {
                    noReplyStreak++;
                    if (count <= 0 && responses >= 8 && noReplyStreak >= 32) break;
                    continue;
                }
                noReplyStreak = 0;
                responses++;
                String text = printable(r.payload);
                String row = "selector=" + selector + " | " + describe(r.payload);
                all.add(row);
                if (interesting(text)) {
                    hits.add(row);
                    append("*** GNSS HIT: " + row);
                } else if (responses <= 12 || responses % 16 == 0) {
                    append(row);
                }
                if (responses % 20 == 0) setStatus("2/3 E9 names: responses=" + responses + " GNSS hits=" + hits.size() + " selector=" + selector);
            }

            setStatus("3/3 RESULT\nPreparing command-name report...");
            StringBuilder out = new StringBuilder();
            out.append("FLYC E9 COMMAND DISCOVERY RESULT\n\n");
            out.append("Transport: ").append(mode.encrypted ? "SIMPLE encrypted" : "PLAINTEXT").append('\n');
            out.append("Count hint: ").append(count > 0 ? count : "UNKNOWN").append('\n');
            out.append("Name responses: ").append(responses).append('\n');
            out.append("GNSS/RF matches: ").append(hits.size()).append("\n\n");
            if (hits.isEmpty()) {
                out.append("No keyword match in returned command names. Full responsive list is in log.\n");
            } else {
                out.append("=== GNSS/RF COMMAND CANDIDATES ===\n");
                for (String h : hits) out.append(h).append('\n');
            }
            out.append("\nEXEC selectors (>0) SENT: 0\nPARAM/CONFIG WRITES: 0\n");
            out.append("Следующий шаг: разбирать только найденные имена/селекторы; ничего не исполнять до идентификации команды.");
            setStatus(out.toString());
            append(out.toString().replace('\n', ' '));

            if (!all.isEmpty()) {
                append("=== ALL RESPONSIVE E9 NAME LOOKUPS ===");
                for (String row : all) append(row);
            }
        } catch (Throwable t) {
            setStatus("E9 DISCOVERY ERROR: " + t.getClass().getSimpleName() + ": " + t.getMessage());
            append("ERROR " + t);
        } finally {
            if (s != null) s.close();
            activeSession = null;
            running.set(false);
            runOnUiThread(() -> { start.setEnabled(true); stop.setEnabled(false); });
            append("AOA CLOSED; EXEC=0; WRITES=0");
        }
    }

    private ProbeMode detectMode(AoaSession s) throws Exception {
        for (boolean enc : new boolean[]{false, true}) {
            DumlV2.Frame count = transact(s, -1, enc, 900);
            if (count != null) return new ProbeMode(enc, count);
            DumlV2.Frame name = transact(s, -2, enc, 900);
            if (name != null) {
                append("E9 selector -2 fallback response: " + describe(name.payload));
                return new ProbeMode(enc, null);
            }
        }
        return null;
    }

    private DumlV2.Frame transact(AoaSession s, int selector, boolean encrypted, int timeoutMs) throws Exception {
        if (selector >= 0) throw new IllegalArgumentException("E9 execution selector blocked: " + selector);
        int qseq = seq.getAndIncrement() & 0xFFFF;
        byte[] payload = new byte[]{(byte)(selector & 0xFF), (byte)((selector >>> 8) & 0xFF)};
        s.sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DEV_FLYC, 0,
                qseq, DumlV2.CMDSET_FLYC, CMD_E9, payload, encrypted));
        long end = System.currentTimeMillis() + timeoutMs;
        while (System.currentTimeMillis() < end) {
            DumlV2.Frame f = s.poll(Math.min(80, Math.max(1, end - System.currentTimeMillis())));
            if (f == null) continue;
            if (f.response && f.seq == qseq && f.cmdSet == DumlV2.CMDSET_FLYC && f.cmdId == CMD_E9) return f;
        }
        return null;
    }

    private static int plausibleCount(byte[] p) {
        if (p == null || p.length == 0) return -1;
        for (int o = 0; o + 1 < p.length && o < 6; o++) {
            int v = (p[o] & 0xFF) | ((p[o + 1] & 0xFF) << 8);
            if (v >= 1 && v <= MAX_NAME_SCAN) return v;
        }
        for (int o = 0; o < p.length && o < 6; o++) {
            int v = p[o] & 0xFF;
            if (v >= 1 && v <= MAX_NAME_SCAN) return v;
        }
        return -1;
    }

    private static boolean interesting(String text) {
        if (text == null) return false;
        String s = text.toLowerCase(Locale.US);
        String[] keys = {
                "gps", "gnss", "ubx", "u-blox", "ublox", "sat", "galileo", "glonass", "beidou", "bds",
                "antenna", "lna", "agc", "receiver", "signal", "snr", "rf", "jam", "spoof", "navx", "itfm",
                "ephemer", "almanac", "acquisition", "tracking", "bandwidth", "mon-hw", "cfg-gnss"
        };
        for (String k : keys) if (s.contains(k)) return true;
        return false;
    }

    private static String describe(byte[] p) {
        return "len=" + (p == null ? 0 : p.length) + " ascii='" + printable(p) + "' raw=" + shortHex(p, 120);
    }

    private static String printable(byte[] p) {
        if (p == null) return "";
        StringBuilder s = new StringBuilder();
        for (byte q : p) {
            int v = q & 0xFF;
            if (v >= 32 && v <= 126) s.append((char)v);
            else if (v == 0 && s.length() > 0 && s.charAt(s.length() - 1) != ' ') s.append(' ');
        }
        return s.toString().trim();
    }

    private static String shortHex(byte[] p, int max) {
        if (p == null) return "";
        StringBuilder s = new StringBuilder();
        int n = Math.min(max, p.length);
        for (int i = 0; i < n; i++) {
            if (i > 0) s.append(' ');
            s.append(String.format(Locale.US, "%02X", p[i] & 0xFF));
        }
        if (p.length > n) s.append(" ...");
        return s.toString();
    }

    private void setStatus(String s) { runOnUiThread(() -> status.setText(s)); }
    private void append(String s) { runOnUiThread(() -> log.append(s + "\n")); }
    private static void sleep(long ms) { try { Thread.sleep(ms); } catch (InterruptedException e) { Thread.currentThread().interrupt(); } }

    private static final class ProbeMode {
        final boolean encrypted;
        final DumlV2.Frame countFrame;
        ProbeMode(boolean encrypted, DumlV2.Frame countFrame) { this.encrypted = encrypted; this.countFrame = countFrame; }
    }

    private static final class AoaSession {
        private final ParcelFileDescriptor pfd;
        private final FileInputStream in;
        private final FileOutputStream out;
        private final AtomicInteger seq;
        private final AtomicBoolean alive = new AtomicBoolean(true);
        private final LinkedBlockingQueue<DumlV2.Frame> rx = new LinkedBlockingQueue<>(5000);
        private final Object writeLock = new Object();
        private final Thread reader;
        private Thread keepalive;
        private volatile int route = 0x5749;
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
            reader = new Thread(this::readLoop, "mini4k-e9-rx");
            reader.setDaemon(true);
            reader.start();
        }

        void startProtocol() throws IOException {
            byte[] boot = new byte[]{0, 0, 1};
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_AIRCRAFT_PROXY, 0,
                    seq.getAndIncrement() & 0xFFFF, DumlV2.CMDSET_GENERAL, 0x00, boot, false));
            sleep(4);
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_ANY, 0,
                    seq.getAndIncrement() & 0xFFFF, DumlV2.CMDSET_GENERAL, 0x00, boot, false));
            sleep(8);
            startKeepalive();
        }

        void startKeepalive() {
            keepalive = new Thread(() -> {
                sleep(2500);
                byte[] p = new byte[]{1, 1, 0, (byte)0xFF, (byte)0xFF, 0x20, 0, 0};
                while (alive.get()) {
                    try {
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_REMOTE_RADIO, 0,
                                seq.getAndIncrement() & 0xFFFF, 0x06, 0x77, p, false));
                        sleep(4);
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, 14, 0,
                                seq.getAndIncrement() & 0xFFFF, 0x06, 0x77, p, false));
                    } catch (Exception e) { break; }
                    sleep(2500);
                }
            }, "mini4k-e9-ka");
            keepalive.setDaemon(true);
            keepalive.start();
        }

        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        String routeString() { return String.format(Locale.US, "0x%04X", route); }

        void sendDuml(byte[] duml) throws IOException {
            int n = duml.length;
            byte[] w = new byte[8 + n];
            w[0] = 0x55; w[1] = (byte)0xCC;
            w[2] = (byte)(route & 0xFF); w[3] = (byte)((route >>> 8) & 0xFF);
            w[4] = (byte)(n & 0xFF); w[5] = (byte)((n >>> 8) & 0xFF);
            w[6] = (byte)((n >>> 16) & 0xFF); w[7] = (byte)((n >>> 24) & 0xFF);
            System.arraycopy(duml, 0, w, 8, n);
            synchronized (writeLock) { out.write(w); out.flush(); }
            sleep(3);
        }

        void readLoop() {
            byte[] b = new byte[16384];
            try {
                while (alive.get()) {
                    int n = in.read(b);
                    if (n < 0) break;
                    if (n > 0) feed(b, n);
                }
            } catch (Exception ignored) {} finally { alive.set(false); }
        }

        void feed(byte[] a, int n) {
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
                    if (x == 0xCC) { header[1] = (byte)0xCC; headerPos = 2; }
                    else if (x == 0x55) { header[0] = 0x55; headerPos = 1; }
                    else headerPos = 0;
                    continue;
                }
                header[headerPos++] = (byte)x;
                if (headerPos == 8) {
                    int type = (header[2] & 0xFF) | ((header[3] & 0xFF) << 8);
                    long len = ((long)header[4] & 0xFF) | (((long)header[5] & 0xFF) << 8) |
                            (((long)header[6] & 0xFF) << 16) | (((long)header[7] & 0xFF) << 24);
                    headerPos = 0;
                    if (len < 0 || len > 0x200000L) { bodyLeft = 0; body = null; bodyType = -1; continue; }
                    bodyType = type;
                    bodyLeft = len;
                    if (type == 0x5749 || type == 0x7530) {
                        route = type;
                        body = new ByteArrayOutputStream((int)Math.min(len, 16384));
                    } else body = null;
                    if (bodyLeft == 0) finishUnit();
                }
            }
        }

        void finishUnit() {
            if ((bodyType == 0x5749 || bodyType == 0x7530) && body != null) {
                for (DumlV2.Frame f : DumlV2.frames(body.toByteArray())) {
                    if (!rx.offer(f)) { rx.poll(); rx.offer(f); }
                }
            }
            bodyType = -1; body = null; bodyLeft = 0;
        }

        void close() {
            if (!alive.getAndSet(false)) return;
            try { if (keepalive != null) keepalive.interrupt(); } catch (Exception ignored) {}
            try { reader.interrupt(); } catch (Exception ignored) {}
            try { in.close(); } catch (Exception ignored) {}
            try { out.close(); } catch (Exception ignored) {}
            try { pfd.close(); } catch (Exception ignored) {}
        }
    }
}

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
 * Deep read-only scan for GNSS receiver/config related parameters.
 * It reads E0/E1/E2 only. No E3/F9 writes, no 0xDF unlock, no receiver reconfiguration.
 */
public class GnssReceiverConfigScanActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_RX_SCAN_PERMISSION";
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x8D00);
    private final AtomicBoolean busy = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView log;
    private Button scan;
    private volatile boolean pendingPermission;

    private static final class Route {
        final int st, si, rt, ri;
        Route(int st, int si, int rt, int ri) { this.st = st; this.si = si; this.rt = rt; this.ri = ri; }
    }
    private static final Route APP_FLYC = new Route(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0);

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
        title.setText("Mini 4K GNSS RECEIVER CONFIG SCAN v1.9");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        scan = new Button(this);
        scan.setText("DEEP GNSS RECEIVER SCAN — READ ONLY");
        LinearLayout.LayoutParams bp = new LinearLayout.LayoutParams(-1, -2);
        bp.topMargin = dp(12);
        root.addView(scan, bp);

        log = new TextView(this);
        log.setTextSize(13);
        log.setTextIsSelectable(true);
        log.setPadding(0, dp(12), 0, dp(12));
        ScrollView sc = new ScrollView(this);
        sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        append("ТОЛЬКО ЧТЕНИЕ. Моторы OFF.");
        append("Ищу receiver/RF/antenna/LNA/AGC/sensitivity, GPS/GNSS/GLONASS/Galileo/BeiDou, acquisition/A-GPS, ephemeris/almanac, clock/frequency и GNSS integrity/FDI параметры.");
        append("Команды записи E3/F9 и config-unlock 0xDF НЕ используются.");
        append("DJI Fly полностью закрыть; телефон — в верхний порт RC-N1.");
        append("");
        scan.setOnClickListener(v -> begin());
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
        append("=== DEEP GNSS RECEIVER CONFIG SCAN v1.9 ===");
        append("WRITE COMMANDS SENT: 0");
        append("DUML self-test: " + (DumlV2.selfTest() ? "PASS" : "FAIL"));
        if (!DumlV2.selfTest()) return;

        AoaSession s = AoaSession.open(usbManager, a, seq);
        if (s == null) { append("AOA pipe не открылся."); return; }
        try {
            s.startProtocol();
            sleep(350);
            append("AOA OPEN route=" + s.routeString());

            Boolean enc = establishReadOnlyMode(s);
            if (enc == null) {
                append("E0 READ не ответил. Скан остановлен; ничего не изменено.");
                return;
            }
            append("READ transport=" + (enc ? "SIMPLE encrypted" : "plaintext"));

            List<DumlV2.TableAttr2017> tables = readTables(s, enc);
            if (tables.isEmpty()) { append("Таблицы параметров не прочитаны."); return; }

            int scanned = 0, matches = 0, high = 0, timeouts = 0;
            int rf = 0, constellation = 0, acquisition = 0, clock = 0, integrity = 0, other = 0;
            append("");
            append("--- CANDIDATES ---");

            for (DumlV2.TableAttr2017 table : tables) {
                int total = (int)Math.min(table.entriesNum, 10000);
                append("SCAN table=" + table.tableNo + " indexes=0.." + (total - 1));
                int noReply = 0;
                for (int index = 0; index < total; index++) {
                    byte[] q = DumlV2.concat(DumlV2.le16(table.tableNo), DumlV2.le16(index));
                    DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE1, q, enc, 380);
                    scanned++;
                    if (r == null) {
                        timeouts++; noReply++;
                        if (noReply >= 20) { append("table=" + table.tableNo + ": stop after 20 E1 timeouts"); break; }
                        continue;
                    }
                    noReply = 0;
                    DumlV2.ParamInfo2017 info = DumlV2.ParamInfo2017.parse(r.payload);
                    if (info == null || info.status != 0) continue;
                    String category = classify(info.name);
                    if (category == null) {
                        if (index > 0 && index % 100 == 0) append("  progress " + index + "/" + total);
                        continue;
                    }
                    matches++;
                    if ("RF/ANTENNA".equals(category)) rf++;
                    else if ("CONSTELLATION".equals(category)) constellation++;
                    else if ("ACQUISITION/A-GPS".equals(category)) acquisition++;
                    else if ("CLOCK/FREQUENCY".equals(category)) clock++;
                    else if ("INTEGRITY/FDI".equals(category)) integrity++;
                    else other++;

                    boolean hv = highValue(info.name);
                    if (hv) high++;
                    byte[] value = readIndexRaw(s, enc, info.tableNo, info.paramIndex, info.size);
                    append((hv ? "*** HIGH-VALUE " : "") + category + " | " + format(info, value));
                    if (index > 0 && index % 100 == 0) append("  progress " + index + "/" + total);
                }
            }

            append("");
            append("=== SCAN COMPLETE ===");
            append("Scanned=" + scanned + " matches=" + matches + " high-value=" + high + " E1 timeouts=" + timeouts);
            append("RF/antenna=" + rf + " constellation=" + constellation + " acquisition/A-GPS=" + acquisition +
                    " clock/freq=" + clock + " integrity/FDI=" + integrity + " other=" + other);
            append("RX DUML=" + s.dumlFrames() + " route=" + s.routeString());
            append("WRITE COMMANDS SENT: 0");
            append("Ничего не менять по найденным значениям до разбора результата.");
        } finally {
            s.close();
            append("AOA CLOSED; WRITE COMMANDS SENT: 0");
        }
    }

    private String classify(String name) {
        if (name == null) return null;
        String n = name.toLowerCase(Locale.US);
        boolean gnssCtx = containsAny(n, "gps", "gnss", "glonass", "galileo", "beidou", "bds", "sat", "nav");

        if (containsAny(n, "antenna", "ant_gain", "antgain", "lna", "agc", "sensitivity", "sensi", "noise_floor", "rf_gain", "rf_front", "receiver_gain") ||
                (gnssCtx && containsAny(n, "gain", "signal", "snr", "cn0", "cno", "receiver", "_rx", ".rx"))) return "RF/ANTENNA";

        if (containsAny(n, "agps", "a_gps", "ephemer", "almanac", "hot_start", "hotstart", "warm_start", "warmstart",
                "cold_start", "coldstart", "acquisition", "acquire", "acq_", "ttff", "assist_data", "assistdata")) return "ACQUISITION/A-GPS";

        if (gnssCtx && containsAny(n, "clock", "clk", "freq", "frequency", "osc", "tcxo", "pps", "drift")) return "CLOCK/FREQUENCY";

        if (gnssCtx && containsAny(n, "spoof", "jam", "fdi", "disagree", "abrupt", "stuck", "conform", "signature",
                "invalid", "mismatch", "disconnect", "range", "height_drift")) return "INTEGRITY/FDI";

        if (containsAny(n, "glonass", "galileo", "beidou", "bds", "constellation", "satellite", "sat_num", "satnum", "sat_count") ||
                (gnssCtx && containsAny(n, "enable", "mask", "select", "mode", "cfg", "config"))) return "CONSTELLATION";

        if (gnssCtx) return "GNSS/OTHER";
        return null;
    }

    private boolean highValue(String name) {
        if (name == null) return false;
        String n = name.toLowerCase(Locale.US);
        return containsAny(n, "antenna", "lna", "agc", "sensitivity", "rf_gain", "receiver_gain", "constellation",
                "galileo", "beidou", "glonass", "agps", "ephemer", "almanac", "hotstart", "warmstart", "coldstart",
                "acquisition", "ttff", "gps_clk", "gnss_clk", "tcxo");
    }

    private static boolean containsAny(String n, String... keys) {
        for (String k : keys) if (n.contains(k)) return true;
        return false;
    }

    private String format(DumlV2.ParamInfo2017 i, byte[] v) {
        StringBuilder s = new StringBuilder();
        s.append("table=").append(i.tableNo).append(" index=").append(i.paramIndex)
                .append(" name='").append(i.name).append("' type=").append(i.typeId)
                .append(" size=").append(i.size).append(" def=").append(i.def)
                .append(" range=").append(i.min).append("..").append(i.max);
        if (v == null) return s.append(" current=<no E2 read>").toString();
        s.append(" currentRaw=").append(DumlV2.hex(v));
        if (v.length > 0 && v.length <= 4) {
            long u = 0;
            for (int x = 0; x < v.length; x++) u |= ((long)v[x] & 0xFFL) << (8*x);
            s.append(" uLE=").append(u);
            if (v.length == 4) s.append(" f32LE=").append(Float.intBitsToFloat((int)u));
        }
        return s.toString();
    }

    private Boolean establishReadOnlyMode(AoaSession s) throws Exception {
        for (boolean enc : new boolean[]{false, true}) {
            DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0, DumlV2.le16(0), enc, 1000);
            if (r == null) continue;
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(r.payload);
            if (a != null && a.status == 0 && a.entriesNum > 0 && a.entriesNum < 20000) {
                append("E0 table0 entries=" + a.entriesNum + " crc=0x" + Long.toHexString(a.entriesCrc));
                return enc;
            }
        }
        return null;
    }

    private List<DumlV2.TableAttr2017> readTables(AoaSession s, boolean enc) throws Exception {
        List<DumlV2.TableAttr2017> out = new ArrayList<>();
        for (int t = 0; t < 32; t++) {
            DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE0, DumlV2.le16(t), enc, 800);
            if (r == null || r.payload.length <= 2) break;
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(r.payload);
            if (a == null || a.status != 0 || a.entriesNum <= 0 || a.entriesNum > 20000) break;
            out.add(a);
            append("TABLE " + a.tableNo + " entries=" + a.entriesNum + " crc=0x" + Long.toHexString(a.entriesCrc));
        }
        return out;
    }

    private byte[] readIndexRaw(AoaSession s, boolean enc, int table, int index, int size) throws Exception {
        byte[] q = DumlV2.concat(DumlV2.le16(table), DumlV2.le16(1), DumlV2.le16(index));
        DumlV2.Frame r = transact(s, APP_FLYC, DumlV2.CMDSET_FLYC, 0xE2, q, enc, 850);
        if (r == null || r.payload.length < 6) return null;
        int st = DumlV2.u16(r.payload, 0), got = DumlV2.u16(r.payload, 4);
        if (st != 0 || got != index) return null;
        int available = r.payload.length - 6, n = size > 0 ? Math.min(size, available) : available;
        if (n <= 0) return new byte[0];
        byte[] v = new byte[n];
        System.arraycopy(r.payload, 6, v, 0, n);
        return v;
    }

    private DumlV2.Frame transact(AoaSession s, Route route, int set, int id, byte[] payload, boolean encrypted, int timeoutMs) throws Exception {
        int qseq = seq.getAndIncrement() & 0xFFFF;
        s.clearQueue();
        s.sendDuml(DumlV2.packet(route.st, route.si, route.rt, route.ri, qseq, set, id, payload, encrypted));
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

    private void append(String s) {
        runOnUiThread(() -> {
            log.append(s + "\n");
            View p = (View)log.getParent();
            if (p instanceof ScrollView) ((ScrollView)p).post(() -> ((ScrollView)p).fullScroll(View.FOCUS_DOWN));
        });
    }

    private static void sleep(long ms) { try { Thread.sleep(ms); } catch (InterruptedException e) { Thread.currentThread().interrupt(); } }

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
            pfd = p; seq = sequence;
            in = new FileInputStream(p.getFileDescriptor());
            out = new FileOutputStream(p.getFileDescriptor());
            reader = new Thread(this::readLoop, "mini4k-gnss-rxcfg-rx");
            reader.setDaemon(true); reader.start();
        }

        void startProtocol() throws IOException {
            byte[] boot = new byte[]{0,0,1};
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_AIRCRAFT_PROXY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(4);
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_ANY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(8);
            startKeepalive();
        }

        private void startKeepalive() {
            keepalive = new Thread(() -> {
                sleep(2500);
                byte[] p = new byte[]{1,1,0,(byte)0xFF,(byte)0xFF,0x20,0,0};
                while (running.get()) {
                    try {
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_REMOTE_RADIO,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false));
                        sleep(4);
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,14,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false));
                    } catch (Exception e) { break; }
                    sleep(2500);
                }
            }, "mini4k-gnss-rxcfg-keepalive");
            keepalive.setDaemon(true); keepalive.start();
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
            w[4]=(byte)(n&0xFF); w[5]=(byte)((n>>>8)&0xFF); w[6]=(byte)((n>>>16)&0xFF); w[7]=(byte)((n>>>24)&0xFF);
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
                if (headerPos==0) { if (x==0x55) { header[0]=0x55; headerPos=1; } continue; }
                if (headerPos==1) {
                    if (x==0xCC) { header[1]=(byte)0xCC; headerPos=2; }
                    else if (x==0x55) { header[0]=0x55; headerPos=1; }
                    else headerPos=0;
                    continue;
                }
                header[headerPos++]=(byte)x;
                if (headerPos==8) {
                    int type=(header[2]&0xFF)|((header[3]&0xFF)<<8);
                    long len=((long)header[4]&0xFF)|(((long)header[5]&0xFF)<<8)|(((long)header[6]&0xFF)<<16)|(((long)header[7]&0xFF)<<24);
                    headerPos=0;
                    if (len<0 || len>0x200000L) { bodyLeft=0; body=null; bodyType=-1; continue; }
                    bodyType=type; bodyLeft=len;
                    if (type==0x5749 || type==0x7530) { route=type; body=new ByteArrayOutputStream((int)Math.min(len,16384)); }
                    else body=null;
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
            bodyType=-1; body=null; bodyLeft=0;
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

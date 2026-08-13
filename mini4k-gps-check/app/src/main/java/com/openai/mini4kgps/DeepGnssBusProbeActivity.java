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
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Deep but non-persistent GNSS discovery for Mini 4K / RC-N1.
 *
 * Persistent writes: NONE.
 * Safe active operations used here:
 *  - COMMON/Version read against DJI device endpoints;
 *  - read probes to FLYC DeviceInfo(0x37), GPS/GLNS(0x57), A-GPS status(0xA1), ProductType(0xFD);
 *  - temporary SetPushGpsSnr 0x46 = 1, then 0 at exit. This only controls telemetry streaming.
 */
public class DeepGnssBusProbeActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.DEEP_GNSS_USB";
    private static final int DEV_GPS = 26;
    private static final int DEV_FLYC = 3;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0xA200);
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
        title.setText("Mini 4K DEEP GNSS DISCOVERY v2.1");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        start = new Button(this);
        start.setText("START DEEP READ-ONLY DISCOVERY");
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
                "Глубокий поиск всех DUML endpoint + GPS indices 0..7 + FLYC GNSS/A-GPS reads + UBX/NMEA fingerprint.\n" +
                "PERSISTENT WRITES: 0");
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
        if (a == null) { append("AOA DJI не найден."); return; }
        if (!usbManager.hasPermission(a)) { requestPermission(a); return; }
        running.set(true);
        runOnUiThread(() -> { start.setEnabled(false); stop.setEnabled(true); status.setText("START...\nPERSISTENT WRITES: 0"); });
        io.submit(() -> runDeep(a));
    }

    private void runDeep(UsbAccessory a) {
        AoaSession s = null;
        Stats st = new Stats();
        boolean snrEnabled = false;
        try {
            s = AoaSession.open(usbManager, a, seq);
            activeSession = s;
            if (s == null) { setStatus("AOA pipe не открылся. Закрой DJI Fly."); return; }
            s.startProtocol();
            sleep(400);
            append("=== DEEP GNSS DISCOVERY v2.1 ===");
            append("AOA route=" + s.routeString());
            append("PERSISTENT PARAM WRITES: 0; CONFIG WRITES: 0");

            setStatus("1/5 PASSIVE CENSUS — 8 сек...");
            capture(s, st, 8000);

            if (!running.get()) return;
            setStatus("2/5 TEMP SNR STREAM + GNSS READS...");
            DumlV2.Frame ena = transact(s, DEV_FLYC, 0, DumlV2.CMDSET_FLYC, 0x46, new byte[]{1}, 800);
            st.tempControls++;
            snrEnabled = true;
            st.snrEnableAck = ena != null;
            append("FLYC 0x46 TEMP SNR enable ACK=" + (ena != null ? "YES" : "NO"));
            capture(s, st, 3000);

            st.flyc57Read = transact(s, DEV_FLYC, 0, DumlV2.CMDSET_FLYC, 0x57, new byte[0], 900);
            st.agpsA1Read = transact(s, DEV_FLYC, 0, DumlV2.CMDSET_FLYC, 0xA1, new byte[0], 900);
            st.flycDeviceInfo = transact(s, DEV_FLYC, 0, DumlV2.CMDSET_FLYC, 0x37, new byte[0], 900);
            st.productType = transact(s, DEV_FLYC, 0, DumlV2.CMDSET_FLYC, 0xFD, new byte[0], 900);
            st.inspectRead("FLYC 0x57 GPS/GLNS", st.flyc57Read);
            st.inspectRead("FLYC 0xA1 A-GPS", st.agpsA1Read);
            st.inspectRead("FLYC 0x37 DeviceInfo", st.flycDeviceInfo);
            st.inspectRead("FLYC 0xFD ProductType", st.productType);

            if (!running.get()) return;
            setStatus("3/5 COMMON/Version endpoint sweep 1..31 x index 0..7...\n~40-60 сек");
            for (int dev = 1; dev <= 31 && running.get(); dev++) {
                if (dev == DumlV2.DEV_MOBILE_APP) continue;
                for (int idx = 0; idx < 8 && running.get(); idx++) {
                    DumlV2.Frame r = transact(s, dev, idx, DumlV2.CMDSET_GENERAL, 0x01, new byte[0], 180);
                    if (r != null) {
                        String key = devName(dev) + "(" + dev + ")[" + idx + "]";
                        String val = describePayload(r.payload);
                        st.endpoints.put(key, val);
                        st.inspectPayload(r.payload);
                        append("RESP " + key + " -> " + val);
                    }
                }
                if (dev % 4 == 0) setStatus("3/5 endpoint sweep: device " + dev + "/31\nresponsive=" + st.endpoints.size());
            }

            if (!running.get()) return;
            setStatus("4/5 GPS type26 RETRY indices 0..7 — long timeout...");
            for (int idx = 0; idx < 8 && running.get(); idx++) {
                DumlV2.Frame r = transact(s, DEV_GPS, idx, DumlV2.CMDSET_GENERAL, 0x01, new byte[0], 850);
                if (r != null) {
                    String key = "GPS(26)[" + idx + "] LONG";
                    String val = describePayload(r.payload);
                    st.endpoints.put(key, val);
                    st.gpsDirect++;
                    st.inspectPayload(r.payload);
                    append("GPS LONG RESP " + idx + " -> " + val);
                }
            }

            if (!running.get()) return;
            setStatus("5/5 FINAL PASSIVE CAPTURE — 5 сек...");
            capture(s, st, 5000);

            String result = st.render();
            setStatus(result);
            append(result.replace('\n', ' '));
        } catch (Throwable t) {
            setStatus("DEEP PROBE ERROR: " + t.getClass().getSimpleName() + ": " + t.getMessage());
            append("ERROR " + t);
        } finally {
            if (s != null && snrEnabled) {
                try {
                    transact(s, DEV_FLYC, 0, DumlV2.CMDSET_FLYC, 0x46, new byte[]{0}, 700);
                    st.tempControls++;
                    append("FLYC 0x46 TEMP SNR disable sent");
                } catch (Exception ignored) {}
            }
            if (s != null) s.close();
            activeSession = null;
            running.set(false);
            runOnUiThread(() -> { start.setEnabled(true); stop.setEnabled(false); });
            append("AOA CLOSED; PERSISTENT WRITES: 0");
        }
    }

    private void capture(AoaSession s, Stats st, long ms) throws Exception {
        long end = System.currentTimeMillis() + ms;
        while (running.get() && System.currentTimeMillis() < end) {
            DumlV2.Frame f = s.poll(200);
            if (f == null) continue;
            st.add(f);
        }
    }

    private DumlV2.Frame transact(AoaSession s, int dev, int devIdx, int set, int id, byte[] payload, int timeoutMs) throws Exception {
        int qseq = seq.getAndIncrement() & 0xFFFF;
        s.sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, dev, devIdx, qseq, set, id, payload, false));
        long end = System.currentTimeMillis() + timeoutMs;
        while (System.currentTimeMillis() < end) {
            DumlV2.Frame f = s.poll(Math.min(80, Math.max(1, end - System.currentTimeMillis())));
            if (f == null) continue;
            if (f.response && f.seq == qseq && f.cmdSet == set && f.cmdId == id) return f;
        }
        return null;
    }

    private static final class Stats {
        int total;
        int gpsDirect;
        int flyc43;
        int flyc44;
        int flyc45;
        int flyc57;
        int flycA1;
        int tempControls;
        boolean snrEnableAck;
        boolean ubxSeen;
        boolean nmeaSeen;
        String vendorHints = "";
        final Map<String,Integer> traffic = new LinkedHashMap<>();
        final Map<String,String> endpoints = new LinkedHashMap<>();
        DumlV2.Frame flyc57Read, agpsA1Read, flycDeviceInfo, productType;
        String last57 = "none", last45 = "none", lastA1 = "none";

        void add(DumlV2.Frame f) {
            total++;
            String key = devName(f.senderType) + "(" + f.senderType + ")[" + f.senderIndex + "] set=" + hex2(f.cmdSet) + " id=" + hex2(f.cmdId) + (f.response ? " RSP" : " PUSH");
            traffic.put(key, traffic.getOrDefault(key, 0) + 1);
            if (f.senderType == DEV_GPS) gpsDirect++;
            if (f.senderType == DEV_FLYC && f.cmdSet == DumlV2.CMDSET_FLYC) {
                if (f.cmdId == 0x43) flyc43++;
                else if (f.cmdId == 0x44) flyc44++;
                else if (f.cmdId == 0x45) { flyc45++; last45 = shortHex(f.payload, 72); }
                else if (f.cmdId == 0x57) { flyc57++; last57 = shortHex(f.payload, 96); }
                else if (f.cmdId == 0xA1) { flycA1++; lastA1 = shortHex(f.payload, 64); }
            }
            inspectPayload(f.payload);
        }

        void inspectRead(String label, DumlV2.Frame f) {
            if (f == null) return;
            inspectPayload(f.payload);
        }

        void inspectPayload(byte[] p) {
            if (p == null || p.length == 0) return;
            for (int i = 0; i + 1 < p.length; i++) {
                if ((p[i] & 0xFF) == 0xB5 && (p[i+1] & 0xFF) == 0x62) ubxSeen = true;
                if (p[i] == '$' && (p[i+1] == 'G' || p[i+1] == 'P' || p[i+1] == 'N')) nmeaSeen = true;
            }
            String a = printable(p).toLowerCase(Locale.US);
            String[] hints = {"u-blox","ublox","ubx","m8030","m8","m9","m10","mediatek","mtk","unicore","casic","atgm","quectel","skytraq","broadcom","sony","galileo","glonass","beidou"};
            for (String h : hints) if (a.contains(h)) addHint(h);
        }

        void addHint(String h) {
            if (vendorHints.isEmpty()) vendorHints = h;
            else if (!vendorHints.contains(h)) vendorHints += ", " + h;
        }

        String render() {
            StringBuilder x = new StringBuilder();
            x.append("DEEP GNSS DISCOVERY RESULT\n\n");
            x.append("Captured DUML frames: ").append(total).append("\n");
            x.append("Responsive COMMON/Version endpoints: ").append(endpoints.size()).append("\n");
            for (Map.Entry<String,String> e : endpoints.entrySet()) x.append("  ").append(e.getKey()).append(" -> ").append(e.getValue()).append("\n");
            x.append("\nDirect GPS type26 frames/responses: ").append(gpsDirect).append("\n");
            x.append("UBX B5 62 fingerprint: ").append(ubxSeen ? "FOUND" : "NOT SEEN").append("\n");
            x.append("NMEA fingerprint: ").append(nmeaSeen ? "FOUND" : "NOT SEEN").append("\n");
            x.append("Vendor/protocol hints: ").append(vendorHints.isEmpty() ? "NONE" : vendorHints).append("\n");
            x.append("\nFLYC pushes 0x43/0x44/0x45/0x57/0xA1: ")
                    .append(flyc43).append('/').append(flyc44).append('/').append(flyc45).append('/').append(flyc57).append('/').append(flycA1).append("\n");
            x.append("SNR temporary enable ACK: ").append(snrEnableAck ? "YES" : "NO").append("\n");
            x.append("0x45 last raw: ").append(last45).append("\n");
            x.append("0x57 last raw: ").append(last57).append("\n");
            x.append("0xA1 last raw: ").append(lastA1).append("\n");
            x.append("\nDirect READ responses:\n");
            x.append("  FLYC 0x57: ").append(describeFrame(flyc57Read)).append("\n");
            x.append("  FLYC 0xA1: ").append(describeFrame(agpsA1Read)).append("\n");
            x.append("  FLYC 0x37 DeviceInfo: ").append(describeFrame(flycDeviceInfo)).append("\n");
            x.append("  FLYC 0xFD ProductType: ").append(describeFrame(productType)).append("\n");
            x.append("\nTEMP TELEMETRY CONTROL COMMANDS: ").append(tempControls).append("\n");
            x.append("PERSISTENT PARAM/CONFIG WRITES: 0\n");
            x.append("Не меняет gain/bandwidth/constellation. Это только глубокое определение реального интерфейса GNSS.");
            return x.toString();
        }
    }

    private static String describeFrame(DumlV2.Frame f) {
        if (f == null) return "NO RESPONSE";
        return "len=" + f.payload.length + " ascii='" + printable(f.payload) + "' raw=" + shortHex(f.payload, 96);
    }

    private static String describePayload(byte[] p) {
        if (p == null) return "len=0";
        String a = printable(p);
        return "len=" + p.length + (a.isEmpty() ? "" : " ascii='" + a + "'") + " raw=" + shortHex(p, 48);
    }

    private static String devName(int d) {
        switch (d) {
            case 0: return "ANY";
            case 1: return "CAMERA";
            case 2: return "APP";
            case 3: return "FLYC";
            case 4: return "GIMBAL";
            case 5: return "CENTER";
            case 6: return "RC";
            case 7: return "WIFI";
            case 8: return "DM368";
            case 9: return "OFDM";
            case 10: return "PC";
            case 11: return "BATTERY";
            case 12: return "DIGITAL";
            case 13: return "DM368_G";
            case 14: return "OSD";
            case 15: return "TRANSFORM";
            case 16: return "TRANSFORM_G";
            case 17: return "SINGLE";
            case 18: return "DOUBLE";
            case 19: return "FPGA";
            case 20: return "FPGA_G";
            case 26: return "GPS";
            case 27: return "WIFI_G";
            case 28: return "GLASS";
            case 31: return "BROADCAST/PROXY";
            default: return "DEV";
        }
    }

    private static String hex2(int v) { return String.format(Locale.US, "0x%02X", v & 0xFF); }

    private static String printable(byte[] p) {
        if (p == null) return "";
        StringBuilder s = new StringBuilder();
        for (byte q : p) {
            int v = q & 0xFF;
            if (v >= 32 && v <= 126) s.append((char)v);
            else if (v == 0 && s.length() > 0 && s.charAt(s.length()-1) != ' ') s.append(' ');
        }
        return s.toString().trim();
    }

    private static String shortHex(byte[] p, int max) {
        if (p == null) return "none";
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

    private static final class AoaSession {
        private final ParcelFileDescriptor pfd;
        private final FileInputStream in;
        private final FileOutputStream out;
        private final AtomicInteger seq;
        private final AtomicBoolean alive = new AtomicBoolean(true);
        private final LinkedBlockingQueue<DumlV2.Frame> rx = new LinkedBlockingQueue<>(12000);
        private final Object writeLock = new Object();
        private final Thread reader;
        private Thread keepalive;
        private volatile int route = 0x5749;
        private int headerPos;
        private final byte[] header = new byte[8];
        private long bodyLeft;
        private int bodyType = -1;
        private ByteArrayOutputStream body;

        static AoaSession open(UsbManager manager, UsbAccessory a, AtomicInteger seq) {
            try {
                ParcelFileDescriptor p = manager.openAccessory(a);
                if (p == null) return null;
                return new AoaSession(p, seq);
            } catch (Exception e) { return null; }
        }

        private AoaSession(ParcelFileDescriptor p, AtomicInteger seq) {
            this.pfd = p;
            this.seq = seq;
            in = new FileInputStream(p.getFileDescriptor());
            out = new FileOutputStream(p.getFileDescriptor());
            reader = new Thread(this::readLoop, "deep-gnss-rx");
            reader.setDaemon(true);
            reader.start();
        }

        void startProtocol() throws IOException {
            byte[] boot = new byte[]{0,0,1};
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_AIRCRAFT_PROXY,0,
                    seq.getAndIncrement() & 0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(5);
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_ANY,0,
                    seq.getAndIncrement() & 0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(10);
            startKeepalive();
        }

        private void startKeepalive() {
            keepalive = new Thread(() -> {
                sleep(2000);
                byte[] p = new byte[]{1,1,0,(byte)0xFF,(byte)0xFF,0x20,0,0};
                while (alive.get()) {
                    try {
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_REMOTE_RADIO,0,
                                seq.getAndIncrement() & 0xFFFF,0x06,0x77,p,false));
                        sleep(4);
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,14,0,
                                seq.getAndIncrement() & 0xFFFF,0x06,0x77,p,false));
                    } catch (Exception e) { break; }
                    sleep(2400);
                }
            }, "deep-gnss-keepalive");
            keepalive.setDaemon(true);
            keepalive.start();
        }

        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        String routeString() { return String.format(Locale.US, "0x%04X", route); }

        void sendDuml(byte[] duml) throws IOException {
            int n = duml.length;
            byte[] w = new byte[8+n];
            w[0]=0x55; w[1]=(byte)0xCC;
            w[2]=(byte)(route & 0xFF); w[3]=(byte)((route >>> 8) & 0xFF);
            w[4]=(byte)(n & 0xFF); w[5]=(byte)((n >>> 8) & 0xFF); w[6]=(byte)((n >>> 16) & 0xFF); w[7]=(byte)((n >>> 24) & 0xFF);
            System.arraycopy(duml,0,w,8,n);
            synchronized (writeLock) { out.write(w); out.flush(); }
            sleep(2);
        }

        private void readLoop() {
            byte[] b = new byte[16384];
            try {
                while (alive.get()) {
                    int n = in.read(b);
                    if (n < 0) break;
                    if (n > 0) feed(b,n);
                }
            } catch (Exception ignored) {} finally { alive.set(false); }
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
                    if (type==0x5749 || type==0x7530) {
                        route=type;
                        body=new ByteArrayOutputStream((int)Math.min(len,32768));
                    } else body=null;
                    if (bodyLeft==0) finishUnit();
                }
            }
        }

        private void finishUnit() {
            if ((bodyType==0x5749 || bodyType==0x7530) && body!=null) {
                for (DumlV2.Frame f : DumlV2.frames(body.toByteArray())) {
                    if (!rx.offer(f)) { rx.poll(); rx.offer(f); }
                }
            }
            bodyType=-1; body=null; bodyLeft=0;
        }

        void close() {
            alive.set(false);
            try { if (keepalive!=null) keepalive.interrupt(); } catch (Exception ignored) {}
            try { reader.interrupt(); } catch (Exception ignored) {}
            try { in.close(); } catch (Exception ignored) {}
            try { out.close(); } catch (Exception ignored) {}
            try { pfd.close(); } catch (Exception ignored) {}
        }
    }
}

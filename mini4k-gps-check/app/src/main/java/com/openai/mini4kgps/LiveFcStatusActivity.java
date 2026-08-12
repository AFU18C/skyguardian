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
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Read-only live FC monitor for validating ATTI-on-Sport.
 * Sends only COMMON bootstrap/keepalive and FLYC 0x43 OSD General Data GET requests.
 * No FLYC parameter write command (E3/F9) is ever sent from this activity.
 */
public class LiveFcStatusActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.LIVE_FC_USB_PERMISSION";
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x7C00);
    private final AtomicBoolean monitoring = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView status;
    private TextView log;
    private Button start;
    private Button stop;
    private volatile boolean pendingStart;
    private volatile AoaSession activeSession;

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
    }

    @Override
    protected void onDestroy() {
        monitoring.set(false);
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
        title.setText("Mini 4K LIVE FC STATUS v1.4");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        start = new Button(this);
        start.setText("START LIVE — READ ONLY");
        root.addView(start, top(12));

        stop = new Button(this);
        stop.setText("STOP");
        stop.setEnabled(false);
        root.addView(stop, top(8));

        status = new TextView(this);
        status.setTextSize(18);
        status.setText("Не запущено.\n\nПроверка: моторы НЕ запускать. START → положение N → запомнить статус → положение S.");
        status.setTextIsSelectable(true);
        status.setPadding(0, dp(14), 0, dp(12));
        root.addView(status, new LinearLayout.LayoutParams(-1, -2));

        log = new TextView(this);
        log.setTextSize(13);
        log.setTextIsSelectable(true);
        log.setText("READ ONLY: параметрические команды записи E3/F9 не используются.\nDJI Fly полностью закрыть; телефон — в верхний порт RC-N1.\n\n");
        ScrollView sc = new ScrollView(this);
        sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        start.setOnClickListener(v -> begin());
        stop.setOnClickListener(v -> endMonitor("Остановлено пользователем."));
    }

    private LinearLayout.LayoutParams top(int px) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = dp(px);
        return p;
    }

    private int dp(int x) {
        return Math.round(x * getResources().getDisplayMetrics().density);
    }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(usbReceiver, f, Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver, f);
    }

    private final BroadcastReceiver usbReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            if (ACTION_USB_PERMISSION.equals(intent.getAction())) {
                boolean ok = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(ok ? "USB permission: OK" : "USB permission: DENIED");
                if (ok && pendingStart) {
                    pendingStart = false;
                    begin();
                }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(intent.getAction())) {
                endMonitor("RC-N1 AOA отключён.");
            }
        }
    };

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] as = usbManager.getAccessoryList();
        if (as == null || as.length == 0) return null;
        for (UsbAccessory a : as) {
            if ("DJI".equalsIgnoreCase(a.getManufacturer())) return a;
        }
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
        if (monitoring.get()) return;
        UsbAccessory a = chooseAccessory();
        if (a == null) {
            append("AOA DJI не найден. Закрой DJI Fly и подключи телефон к верхнему порту RC-N1.");
            return;
        }
        if (!usbManager.hasPermission(a)) {
            append("Запрашиваю USB permission...");
            requestPermission(a);
            return;
        }

        monitoring.set(true);
        runOnUiThread(() -> {
            start.setEnabled(false);
            stop.setEnabled(true);
            status.setText("Подключение к FC...");
        });
        io.submit(() -> runLive(a));
    }

    private void endMonitor(String why) {
        monitoring.set(false);
        AoaSession s = activeSession;
        if (s != null) s.close();
        activeSession = null;
        append(why);
        runOnUiThread(() -> {
            start.setEnabled(true);
            stop.setEnabled(false);
        });
    }

    private void runLive(UsbAccessory a) {
        AoaSession s = null;
        try {
            s = AoaSession.open(usbManager, a, seq);
            activeSession = s;
            if (s == null) {
                endMonitor("AOA pipe не открылся. DJI Fly должен быть полностью закрыт.");
                return;
            }
            s.startProtocol();
            sleep(350);
            append("AOA OPEN route=" + s.routeString() + "; LIVE 0x43 started; WRITE COMMANDS SENT: 0");
            append("Переключи N → S. Смотри FC State и GPS Used.");

            String lastKey = "";
            int misses = 0;
            while (monitoring.get()) {
                DumlV2.Frame f = queryOsd(s, 800);
                if (f == null) {
                    misses++;
                    if (misses == 1 || misses % 5 == 0) append("0x43: нет ответа (misses=" + misses + ")");
                    sleep(250);
                    continue;
                }
                misses = 0;
                Osd o = Osd.parse(f.payload);
                if (o == null) {
                    append("0x43 payload неизвестного формата len=" + f.payload.length + " raw=" + shortHex(f.payload, 32));
                    sleep(300);
                    continue;
                }
                showStatus(o, f.payload.length);
                String key = o.fcState + "/" + o.gpsUsed + "/" + o.gpsState + "/" + o.sats + "/" + o.onGround;
                if (!key.equals(lastKey)) {
                    append(String.format(Locale.US,
                            "CHANGE: state=%s(%d), GPS Used=%s, sats=%d, GPS State=%s(%d), ground=%s",
                            fcStateName(o.fcState), o.fcState, o.gpsUsed ? "YES" : "NO", o.sats,
                            gpsStateName(o.gpsState), o.gpsState, o.onGround ? "YES" : "NO"));
                    lastKey = key;
                }
                sleep(250);
            }
        } catch (Throwable t) {
            append("LIVE ERROR: " + t.getClass().getSimpleName() + ": " + t.getMessage());
        } finally {
            if (s != null) s.close();
            activeSession = null;
            monitoring.set(false);
            runOnUiThread(() -> {
                start.setEnabled(true);
                stop.setEnabled(false);
            });
            append("AOA pipe: CLOSED; WRITE COMMANDS SENT: 0");
        }
    }

    private void showStatus(Osd o, int payloadLen) {
        String stateName = fcStateName(o.fcState);
        boolean attiLike = stateName.startsWith("Atti") || stateName.equals("Manual");
        String verdict;
        if (attiLike && !o.gpsUsed) verdict = "ATTI PATH: GPS НЕ используется для горизонтальной скорости";
        else if (o.gpsUsed) verdict = "GPS PATH: GPS используется горизонтальным контуром";
        else verdict = "GPS Used=NO; режим=" + stateName;

        String text = String.format(Locale.US,
                "FC State: %s  [%d]\nGPS Used: %s\nSatellites: %d\nGPS State: %s  [%d]\nGPS Level: %d\nGround: %s   Motors: %s\nHeight: %.1f m\nVx/Vy/Vz: %.1f / %.1f / %.1f m/s\n\n%s\n\n0x43 payload len=%d\nWRITE COMMANDS SENT: 0",
                stateName, o.fcState, o.gpsUsed ? "YES" : "NO", o.sats,
                gpsStateName(o.gpsState), o.gpsState, o.gpsLevel,
                o.onGround ? "YES" : "NO", o.motorOn ? "ON" : "OFF",
                o.height, o.vx, o.vy, o.vz, verdict, payloadLen);
        runOnUiThread(() -> status.setText(text));
    }

    private DumlV2.Frame queryOsd(AoaSession s, int timeoutMs) throws Exception {
        int qseq = seq.getAndIncrement() & 0xFFFF;
        byte[] q = DumlV2.packet(APP_FLYC.senderType, APP_FLYC.senderIndex,
                APP_FLYC.receiverType, APP_FLYC.receiverIndex,
                qseq, DumlV2.CMDSET_FLYC, 0x43, new byte[0], false);
        s.clearQueue();
        s.sendDuml(q);

        long end = System.currentTimeMillis() + timeoutMs;
        DumlV2.Frame fallback = null;
        while (System.currentTimeMillis() < end) {
            DumlV2.Frame f = s.poll(Math.min(90, Math.max(1, end - System.currentTimeMillis())));
            if (f == null) continue;
            if (f.cmdSet != DumlV2.CMDSET_FLYC || f.cmdId != 0x43) continue;
            boolean reverse = f.senderType == APP_FLYC.receiverType && f.receiverType == APP_FLYC.senderType;
            if (f.response && f.seq == qseq && reverse) return f;
            if (f.seq == qseq && reverse) return f;
            if (reverse && fallback == null) fallback = f;
        }
        return fallback;
    }

    private static final class Osd {
        int fcState, gpsState, sats, gpsLevel;
        boolean gpsUsed, onGround, motorOn;
        double height, vx, vy, vz;

        static Osd parse(byte[] p) {
            if (p == null) return null;
            int b = base(p);
            if (b < 0 || p.length < b + 42) return null;
            Osd o = new Osd();
            o.height = s16(p, b + 16) / 10.0;
            o.vx = s16(p, b + 18) / 10.0;
            o.vy = s16(p, b + 20) / 10.0;
            o.vz = s16(p, b + 22) / 10.0;
            int ctrlInfo = p[b + 30] & 0xFF;
            o.fcState = ctrlInfo & 0x7F;
            long cs = u32(p, b + 32);
            o.onGround = (cs & 0x02L) != 0;
            o.motorOn = (cs & 0x08L) != 0;
            o.gpsUsed = (cs & 0x8000L) != 0;
            o.gpsLevel = (int) ((cs & 0x3C0000L) >>> 18);
            o.sats = p[b + 36] & 0xFF;
            o.gpsState = p[b + 39] & 0x0F;
            return o;
        }

        private static int base(byte[] p) {
            if (p.length == 50 || p.length == 55) return 0;
            if ((p.length == 51 || p.length == 56) && (p[0] & 0xFF) == 0) return 1;
            // Newer variants may append fields. Prefer direct OSD layout when enough bytes exist.
            if (p.length >= 50) return 0;
            if (p.length >= 43 && (p[0] & 0xFF) == 0) return 1;
            return -1;
        }

        private static int s16(byte[] a, int off) {
            int v = (a[off] & 0xFF) | ((a[off + 1] & 0xFF) << 8);
            return (short) v;
        }

        private static long u32(byte[] a, int off) {
            return ((long) a[off] & 0xFF) |
                    (((long) a[off + 1] & 0xFF) << 8) |
                    (((long) a[off + 2] & 0xFF) << 16) |
                    (((long) a[off + 3] & 0xFF) << 24);
        }
    }

    private static String fcStateName(int x) {
        switch (x) {
            case 0x00: return "Manual";
            case 0x01: return "Atti";
            case 0x02: return "Atti_CL";
            case 0x03: return "Atti_Hover";
            case 0x04: return "Hover";
            case 0x05: return "GPS_Blake";
            case 0x06: return "GPS_Atti";
            case 0x07: return "GPS_CL";
            case 0x08: return "GPS_HomeLock";
            case 0x09: return "GPS_HotPoint";
            case 0x0A: return "AssistedTakeoff";
            case 0x0B: return "AutoTakeoff";
            case 0x0C: return "AutoLanding";
            case 0x0D: return "AttiLanding";
            case 0x0E: return "NaviGo";
            case 0x0F: return "GoHome";
            case 0x10: return "ClickGo";
            case 0x11: return "Joystick";
            case 0x17: return "Atti_Limited";
            case 0x18: return "GPS_Atti_Limited";
            case 0x1E: return "FPV";
            case 0x1F: return "SPORT";
            case 0x20: return "NOVICE";
            case 0x21: return "FORCE_LANDING";
            case 0x24: return "NAVI_ADV_GOHOME";
            case 0x25: return "NAVI_ADV_LANDING";
            case 0x26: return "TRIPOD_GPS";
            case 0x2B: return "GENTLE_GPS";
            default: return String.format(Locale.US, "UNKNOWN_0x%02X", x);
        }
    }

    private static String gpsStateName(int x) {
        switch (x) {
            case 0: return "ALREADY/OK";
            case 1: return "FORBID";
            case 2: return "GPSNUM_NOT_ENOUGH";
            case 3: return "HDOP_LARGE";
            case 4: return "POSITION_NONMATCH";
            case 5: return "SPEED_ERROR_LARGE";
            case 6: return "YAW_ERROR_LARGE";
            case 7: return "COMPASS_ERROR_LARGE";
            default: return "UNKNOWN";
        }
    }

    private static String shortHex(byte[] b, int max) {
        int n = Math.min(b.length, max);
        byte[] c = new byte[n];
        System.arraycopy(b, 0, c, 0, n);
        return DumlV2.hex(c) + (b.length > max ? " ..." : "");
    }

    private void append(String s) {
        runOnUiThread(() -> log.append(s + "\n"));
    }

    private static void sleep(long ms) {
        try { Thread.sleep(ms); } catch (InterruptedException e) { Thread.currentThread().interrupt(); }
    }

    /** Android Open Accessory + DJI RCLink envelope transport. */
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
            reader = new Thread(this::readLoop, "mini4k-live-rx");
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

        private void startKeepalive() {
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
                    } catch (Exception e) { break; }
                    sleep(2500);
                }
            }, "mini4k-live-keepalive");
            keepalive.setDaemon(true);
            keepalive.start();
        }

        void clearQueue() { rx.clear(); }
        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        String routeString() { return String.format(Locale.US, "0x%04X", route); }

        void sendDuml(byte[] duml) throws IOException {
            int n = duml.length;
            byte[] w = new byte[8 + n];
            w[0] = 0x55; w[1] = (byte) 0xCC;
            w[2] = (byte) (route & 0xFF); w[3] = (byte) ((route >>> 8) & 0xFF);
            w[4] = (byte) (n & 0xFF); w[5] = (byte) ((n >>> 8) & 0xFF);
            w[6] = (byte) ((n >>> 16) & 0xFF); w[7] = (byte) ((n >>> 24) & 0xFF);
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
            } finally { running.set(false); }
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
                    if (len < 0 || len > 0x200000L) { bodyLeft = 0; body = null; bodyType = -1; continue; }
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
            if ((bodyType == 0x5749 || bodyType == 0x7530) && body != null) {
                for (DumlV2.Frame f : DumlV2.frames(body.toByteArray())) {
                    if (!rx.offer(f)) { rx.poll(); rx.offer(f); }
                }
            }
            bodyType = -1;
            body = null;
            bodyLeft = 0;
        }

        void close() {
            if (!running.getAndSet(false)) return;
            try { if (keepalive != null) keepalive.interrupt(); } catch (Exception ignored) {}
            try { reader.interrupt(); } catch (Exception ignored) {}
            try { in.close(); } catch (Exception ignored) {}
            try { out.close(); } catch (Exception ignored) {}
            try { pfd.close(); } catch (Exception ignored) {}
        }
    }
}

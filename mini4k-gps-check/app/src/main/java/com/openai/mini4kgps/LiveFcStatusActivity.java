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
 *
 * Important: DJI command 0x43 is GetPushCommon, i.e. unsolicited push telemetry.
 * This activity therefore does NOT query 0x43. It passively consumes incoming FLYC pushes
 * after the normal RC-N1 AOA bootstrap/keepalive. No FLYC parameter write command E3/F9 is sent.
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
        title.setText("Mini 4K LIVE FC STATUS v1.5");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        start = new Button(this);
        start.setText("START PASSIVE LIVE — READ ONLY");
        root.addView(start, top(12));

        stop = new Button(this);
        stop.setText("STOP");
        stop.setEnabled(false);
        root.addView(stop, top(8));

        status = new TextView(this);
        status.setTextSize(18);
        status.setText("Не запущено.\n\nМоторы НЕ запускать. START → положение N → скрин → положение S → скрин.");
        status.setTextIsSelectable(true);
        status.setPadding(0, dp(14), 0, dp(12));
        root.addView(status, new LinearLayout.LayoutParams(-1, -2));

        log = new TextView(this);
        log.setTextSize(13);
        log.setTextIsSelectable(true);
        log.setText("READ ONLY. 0x43 теперь читается как PASSIVE PUSH, а не запрос.\n" +
                "E3/F9 и другие команды записи параметров здесь не используются.\n" +
                "DJI Fly полностью закрыть; телефон — в верхний порт RC-N1.\n\n");
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
            status.setText("Подключение... жду входящие FLYC push-пакеты.");
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
            sleep(450);
            append("AOA OPEN route=" + s.routeString() + "; PASSIVE FLYC PUSH monitor started.");
            append("0x43 НЕ запрашивается. Ждём GetPushCommon от FC. WRITE COMMANDS SENT: 0");
            append("Положение N → подожди 2–3 сек → скрин. Потом S → подожди 2–3 сек → скрин.");

            int[] flycCmdCounts = new int[256];
            int flycTotal = 0;
            int osdTotal = 0;
            long startMs = System.currentTimeMillis();
            long lastDiag = startMs;
            String lastKey = "";

            while (monitoring.get()) {
                DumlV2.Frame f = s.poll(500);
                long now = System.currentTimeMillis();

                if (f != null && f.cmdSet == DumlV2.CMDSET_FLYC) {
                    flycTotal++;
                    int id = f.cmdId & 0xFF;
                    flycCmdCounts[id]++;

                    if (flycCmdCounts[id] == 1 && id != 0x43) {
                        if (id == 0x4A || id == 0x4B || id == 0x4C || id == 0x58 || id == 0x5A || id == 0x57) {
                            append(String.format(Locale.US,
                                    "Seen FLYC push 0x%02X len=%d sender=%d->%d raw=%s",
                                    id, f.payload == null ? 0 : f.payload.length,
                                    f.senderType, f.receiverType,
                                    f.payload == null ? "" : shortHex(f.payload, 24)));
                        }
                    }

                    if (id == 0x43) {
                        osdTotal++;
                        Osd o = Osd.parse(f.payload);
                        if (o == null) {
                            if (osdTotal <= 3) append("0x43 push есть, но формат пока не распознан: len=" +
                                    (f.payload == null ? 0 : f.payload.length) + " raw=" +
                                    (f.payload == null ? "" : shortHex(f.payload, 48)));
                        } else {
                            showStatus(o, f.payload.length, osdTotal, flycTotal);
                            String key = o.fcState + "/" + o.gpsUsed + "/" + o.sats + "/" +
                                    o.nonGpsCause + "/" + o.modeRaw + "/" + o.groundOrSky;
                            if (!key.equals(lastKey)) {
                                append(String.format(Locale.US,
                                        "CHANGE: FC=%s(%d), GPS Used=%s, sats=%d, nonGPS=%s(%d), modeRaw=%d, groundSky=%d",
                                        fcStateName(o.fcState), o.fcState,
                                        o.gpsUsed ? "YES" : "NO", o.sats,
                                        nonGpsCauseName(o.nonGpsCause), o.nonGpsCause,
                                        o.modeRaw, o.groundOrSky));
                                lastKey = key;
                            }
                        }
                    }
                }

                if (now - lastDiag >= 2500) {
                    lastDiag = now;
                    if (osdTotal == 0) {
                        String census = census(flycCmdCounts);
                        String msg = "Жду 0x43 push... FLYC packets=" + flycTotal +
                                (census.isEmpty() ? "; пока других FLYC push не видно" : "; вижу " + census);
                        runOnUiThread(() -> status.setText(msg +
                                "\n\nЭто пассивный монитор. Ничего в FC не записывается.\nWRITE COMMANDS SENT: 0"));
                        append(msg);
                    }
                }

                if (now - startMs > 15000 && osdTotal == 0 && flycTotal == 0) {
                    // Keep listening; this message only explains what is missing.
                    startMs = now + 3600000L;
                    append("15 сек: через AOA нет ни одного FLYC push. Если так и останется, следующим шагом нужен push-subscription/другая телеметрическая команда, а не повторные 0x43 GET.");
                }
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

    private void showStatus(Osd o, int payloadLen, int osdCount, int flycCount) {
        String stateName = fcStateName(o.fcState);
        boolean attiLike = o.fcState == 0 || o.fcState == 1 || o.fcState == 2 ||
                o.fcState == 3 || o.fcState == 13 || o.fcState == 23;

        String verdict;
        if (attiLike && !o.gpsUsed) verdict = "ATTI PATH: FC сообщает ATTI-подобный режим и GPS Used=NO";
        else if (o.gpsUsed) verdict = "GPS PATH: FC сообщает GPS Used=YES";
        else verdict = "GPS Used=NO; FC State=" + stateName;

        String text = String.format(Locale.US,
                "FC State: %s  [%d]\n" +
                        "GPS Used/Valid bit: %s\n" +
                        "Satellites: %d\n" +
                        "GPS Level: %d\n" +
                        "Non-GPS Cause: %s  [%d]\n" +
                        "RC Mode raw: %d\n" +
                        "Ground/Sky raw: %d   Motors: %s\n" +
                        "Height: %.1f m\n" +
                        "Vx/Vy/Vz: %.1f / %.1f / %.1f m/s\n\n" +
                        "%s\n\n" +
                        "0x43 pushes=%d  FLYC packets=%d  payload=%d\n" +
                        "WRITE COMMANDS SENT: 0",
                stateName, o.fcState,
                o.gpsUsed ? "YES" : "NO",
                o.sats, o.gpsLevel,
                nonGpsCauseName(o.nonGpsCause), o.nonGpsCause,
                o.modeRaw, o.groundOrSky, o.motorOn ? "ON" : "OFF",
                o.height, o.vx, o.vy, o.vz,
                verdict, osdCount, flycCount, payloadLen);
        runOnUiThread(() -> status.setText(text));
    }

    private static final class Osd {
        int fcState, sats, gpsLevel, nonGpsCause, modeRaw, groundOrSky;
        boolean gpsUsed, motorOn;
        double height, vx, vy, vz;

        static Osd parse(byte[] p) {
            if (p == null || p.length < 42) return null;

            // DJI DataOsdGetPushCommon is normally a raw push payload (no ccode byte).
            // Keep one conservative fallback for captures that prepend a zero status byte.
            int b = chooseBase(p);
            if (b < 0 || p.length < b + 42) return null;

            Osd o = new Osd();
            o.height = s16(p, b + 16) / 10.0;
            o.vx = s16(p, b + 18) / 10.0;
            o.vy = s16(p, b + 20) / 10.0;
            o.vz = s16(p, b + 22) / 10.0;

            o.fcState = (p[b + 30] & 0xFF) & 0x7F;
            long cs = u32(p, b + 32);
            o.groundOrSky = (int) ((cs >>> 1) & 0x03L);
            o.motorOn = (cs & 0x08L) != 0;
            o.modeRaw = (int) ((cs >>> 13) & 0x03L);
            o.gpsUsed = (cs & 0x8000L) != 0;
            o.gpsLevel = (int) ((cs >>> 18) & 0x0FL);
            o.sats = p[b + 36] & 0xFF;
            o.nonGpsCause = p[b + 39] & 0x0F;
            return o;
        }

        private static int chooseBase(byte[] p) {
            if (p.length == 50 || p.length == 55 || p.length >= 52) return 0;
            if ((p.length == 51 || p.length == 56 || p.length == 43) && (p[0] & 0xFF) == 0) return 1;
            return p.length >= 42 ? 0 : -1;
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
            case 0: return "Manual";
            case 1: return "Atti";
            case 2: return "Atti_CL";
            case 3: return "Atti_Hover";
            case 4: return "Hover";
            case 5: return "GPS_Blake";
            case 6: return "GPS_Atti";
            case 7: return "GPS_CL";
            case 8: return "GPS_HomeLock";
            case 9: return "GPS_HotPoint";
            case 10: return "AssistedTakeoff";
            case 11: return "AutoTakeoff";
            case 12: return "AutoLanding";
            case 13: return "AttiLanding";
            case 14: return "NaviGo";
            case 15: return "GoHome";
            case 16: return "ClickGo";
            case 17: return "Joystick";
            case 19: return "Cinematic";
            case 23: return "Atti_Limited";
            case 24: return "NaviSubMode_Draw";
            case 25: return "NaviMissionFollow";
            case 26: return "NaviSubMode_Tracking";
            case 27: return "NaviSubMode_Pointing";
            case 28: return "PANO";
            case 29: return "Farming";
            case 30: return "FPV";
            case 31: return "SPORT";
            case 32: return "NOVICE";
            case 33: return "FORCE_LANDING";
            case 35: return "TERRAIN_TRACKING";
            case 36: return "PALM_CONTROL";
            case 37: return "QUICK_SHOT";
            case 38: return "TRIPOD_GPS";
            case 39: return "TRACK_HEADLOCK";
            case 41: return "ENGINE_START";
            case 43: return "DETOUR";
            case 46: return "TIME_LAPSE";
            case 49: return "OMNI_MOVING";
            case 50: return "POI_WITH_VISION";
            default: return String.format(Locale.US, "UNKNOWN_%d", x);
        }
    }

    private static String nonGpsCauseName(int x) {
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

    private static String census(int[] c) {
        StringBuilder sb = new StringBuilder();
        int shown = 0;
        for (int id = 0; id < c.length; id++) {
            if (c[id] == 0) continue;
            if (shown++ > 0) sb.append(", ");
            sb.append(String.format(Locale.US, "0x%02X=%d", id, c[id]));
            if (shown >= 8) break;
        }
        return sb.toString();
    }

    private static String shortHex(byte[] b, int max) {
        if (b == null) return "";
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

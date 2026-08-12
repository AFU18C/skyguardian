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
 * Passive ground motor/propeller health check for Mini 4K.
 *
 * Important: this activity NEVER starts/stops motors and NEVER writes FC parameters.
 * It only listens to the standard FLYC 0x43 GetPushCommon stream after the normal
 * RC-N1 AOA bootstrap/keepalive. The user manually starts motors at idle on the ground.
 */
public class PropellerTestActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.PROP_TEST_USB_PERMISSION";
    private static final long TEST_MS = 20_000L;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x8200);
    private final AtomicBoolean running = new AtomicBoolean(false);

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
        title.setText("Mini 4K MOTOR / PROPELLER TEST v1.6");
        title.setTextSize(21);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        start = new Button(this);
        start.setText("CONNECT TEST — READ ONLY");
        root.addView(start, top(12));

        stop = new Button(this);
        stop.setText("STOP");
        stop.setEnabled(false);
        root.addView(stop, top(8));

        status = new TextView(this);
        status.setTextSize(17);
        status.setTextIsSelectable(true);
        status.setPadding(0, dp(12), 0, dp(10));
        status.setText(
                "1) Дрон на ровной открытой площадке.\n" +
                "2) Пропеллеры установлены правильно.\n" +
                "3) Нажми CONNECT TEST.\n" +
                "4) Когда появится 'ЖДУ МОТОРЫ' — вручную запусти моторы CSC и НЕ давай газ.\n\n" +
                "Тест сам начнётся при Motors=ON и длится 20 сек. Взлетать не нужно.");
        root.addView(status, new LinearLayout.LayoutParams(-1, -2));

        log = new TextView(this);
        log.setTextSize(13);
        log.setTextIsSelectable(true);
        log.setText(
                "READ ONLY. Приложение НЕ запускает моторы и не отправляет E3/F9.\n" +
                "Проверяются штатные FC-флаги: ESC error, motor block, not enough force, propeller fault, vibration + raw motor revolution.\n" +
                "Это наземная диагностика явных неисправностей, а не измерение полной тяги каждого винта.\n\n");
        ScrollView sc = new ScrollView(this);
        sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        start.setOnClickListener(v -> begin());
        stop.setOnClickListener(v -> end("Остановлено пользователем."));
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
                end("RC-N1 AOA отключён.");
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
        if (running.get()) return;
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

        running.set(true);
        runOnUiThread(() -> {
            start.setEnabled(false);
            stop.setEnabled(true);
            status.setText("Подключение к FC...");
        });
        io.submit(() -> runTest(a));
    }

    private void end(String why) {
        running.set(false);
        AoaSession s = activeSession;
        if (s != null) s.close();
        activeSession = null;
        append(why);
        runOnUiThread(() -> {
            start.setEnabled(true);
            stop.setEnabled(false);
        });
    }

    private void runTest(UsbAccessory a) {
        AoaSession s = null;
        try {
            s = AoaSession.open(usbManager, a, seq);
            activeSession = s;
            if (s == null) {
                finishUi("AOA pipe не открылся. DJI Fly должен быть полностью закрыт.");
                return;
            }
            s.startProtocol();
            sleep(350);
            append("AOA OPEN route=" + s.routeString() + "; passive propeller test armed; WRITE COMMANDS SENT: 0");
            append("Жду Motors=ON. Запусти моторы вручную CSC; газ не поднимай.");
            setStatus("ЖДУ МОТОРЫ...\n\nЗапусти моторы вручную CSC. Левый стик вверх НЕ поднимать.");

            Stats stats = new Stats();
            long motorStartAt = 0L;
            long lastUi = 0L;
            long lastPacketAt = System.currentTimeMillis();

            while (running.get()) {
                DumlV2.Frame f = s.poll(350);
                long now = System.currentTimeMillis();
                if (f == null) {
                    if (now - lastPacketAt > 4000) {
                        setStatus("Нет телеметрии 0x43. Проверь связь RC ↔ дрон.");
                    }
                    continue;
                }
                if (f.cmdSet != DumlV2.CMDSET_FLYC || f.cmdId != 0x43) continue;
                Osd o = Osd.parse(f.payload);
                if (o == null) continue;
                lastPacketAt = now;

                if (!o.motorOn) {
                    if (motorStartAt != 0L) {
                        long elapsed = now - motorStartAt;
                        if (elapsed < 8000L) {
                            finishUi("ТЕСТ НЕ ЗАВЕРШЁН\n\nМоторы остановились через " + (elapsed / 1000.0) + " сек. Нужно минимум 20 сек на холостых.");
                            return;
                        }
                    }
                    setStatus("ЖДУ МОТОРЫ...\n\nMotors=OFF. Запусти вручную CSC, газ не поднимать.");
                    continue;
                }

                if (motorStartAt == 0L) {
                    motorStartAt = now;
                    stats.reset();
                    append("Motors=ON → старт 20-секундного пассивного теста.");
                }

                long elapsed = now - motorStartAt;
                stats.add(o);

                // A real takeoff is outside the scope of this test. GroundSky=1 is normal after motor start on this Mini 4K.
                if (Math.abs(o.heightM) > 0.60 || o.groundSky >= 2) {
                    finishUi("ТЕСТ ПРЕРВАН: FC сообщает отрыв/полёт.\n\nЭтот тест рассчитан только на моторы на холостых на земле. Посади/останови моторы.");
                    return;
                }

                if (now - lastUi > 250) {
                    long left = Math.max(0L, TEST_MS - elapsed);
                    setStatus(stats.liveText(left));
                    lastUi = now;
                }

                if (elapsed >= TEST_MS) {
                    finishUi(stats.resultText());
                    return;
                }
            }
        } catch (Throwable t) {
            finishUi("TEST ERROR: " + t.getClass().getSimpleName() + ": " + t.getMessage());
        } finally {
            if (s != null) s.close();
            activeSession = null;
            running.set(false);
            runOnUiThread(() -> {
                start.setEnabled(true);
                stop.setEnabled(false);
            });
            append("AOA pipe: CLOSED; WRITE COMMANDS SENT: 0");
        }
    }

    private static final class Stats {
        int samples;
        int vibrationSamples;
        int escErrorSamples;
        int motorBlockSamples;
        int forceSamples;
        int propellerFaultSamples;
        int revSamples;
        int revMin;
        int revMax;
        long revSum;
        int maxAbsPitch;
        int maxAbsRoll;
        int lastFcState;
        int lastFlycVersion;

        void reset() {
            samples = vibrationSamples = escErrorSamples = motorBlockSamples = forceSamples = propellerFaultSamples = 0;
            revSamples = 0;
            revMin = Integer.MAX_VALUE;
            revMax = Integer.MIN_VALUE;
            revSum = 0;
            maxAbsPitch = maxAbsRoll = 0;
            lastFcState = -1;
            lastFlycVersion = -1;
        }

        void add(Osd o) {
            samples++;
            if (o.vibrating) vibrationSamples++;
            if (o.escError) escErrorSamples++;
            if (o.motorBlock) motorBlockSamples++;
            if (o.notEnoughForce) forceSamples++;
            if (o.propellerFault) propellerFaultSamples++;
            if (o.motorRevolutionRaw > 0) {
                revSamples++;
                revMin = Math.min(revMin, o.motorRevolutionRaw);
                revMax = Math.max(revMax, o.motorRevolutionRaw);
                revSum += o.motorRevolutionRaw;
            }
            maxAbsPitch = Math.max(maxAbsPitch, Math.abs(o.pitchRaw));
            maxAbsRoll = Math.max(maxAbsRoll, Math.abs(o.rollRaw));
            lastFcState = o.fcState;
            lastFlycVersion = o.flycVersion;
        }

        String liveText(long leftMs) {
            int sec = (int) Math.ceil(leftMs / 1000.0);
            String rev = revSamples == 0 ? "n/a" : (revMin + ".." + revMax + " avg=" + String.format(Locale.US, "%.1f", revSum / (double) revSamples));
            return "ТЕСТ ИДЁТ: " + sec + " сек\n" +
                    "Motors: ON   samples=" + samples + "\n" +
                    "ESC error: " + escErrorSamples + "\n" +
                    "Motor block: " + motorBlockSamples + "\n" +
                    "Not enough force: " + forceSamples + "\n" +
                    "Propeller fault: " + propellerFaultSamples + "\n" +
                    "Vibration flag: " + vibrationSamples + "\n" +
                    "Motor revolution raw: " + rev + "\n\n" +
                    "Газ НЕ поднимать. Взлетать не нужно.";
        }

        String resultText() {
            boolean enough = samples >= 80;
            boolean critical = escErrorSamples > 0 || motorBlockSamples > 0 || forceSamples > 0 || propellerFaultSamples > 0;
            double vibPct = samples == 0 ? 0.0 : (100.0 * vibrationSamples / samples);
            boolean vibrationWarn = vibPct >= 10.0;

            String verdict;
            if (!enough) verdict = "INCOMPLETE — мало данных";
            else if (critical) verdict = "WARNING — FC обнаружил мотор/пропеллерную проблему";
            else if (vibrationWarn) verdict = "WARNING — высокий vibration flag";
            else verdict = "PASS — явных мотор/пропеллерных ошибок FC не обнаружено";

            String rev = revSamples == 0
                    ? "не публикуется/0 на этом состоянии"
                    : (revMin + ".." + revMax + ", avg=" + String.format(Locale.US, "%.1f", revSum / (double) revSamples));

            return verdict + "\n\n" +
                    "20 сек, Motors=ON, samples=" + samples + "\n" +
                    "ESC error=" + escErrorSamples + "\n" +
                    "Motor block=" + motorBlockSamples + "\n" +
                    "Not enough force=" + forceSamples + "\n" +
                    "Propeller fault=" + propellerFaultSamples + "\n" +
                    "Vibration=" + vibrationSamples + " (" + String.format(Locale.US, "%.1f", vibPct) + "%)\n" +
                    "Motor revolution raw=" + rev + "\n" +
                    "FC state=" + lastFcState + ", FLYC version=" + lastFlycVersion + "\n\n" +
                    "Важно: PASS означает, что на холостых FC не увидел явной ошибки. Он не измеряет отдельную тягу каждого винта и не заменяет короткую проверку зависания.";
        }
    }

    private static final class Osd {
        int fcState;
        int groundSky;
        int flycVersion;
        int motorRevolutionRaw;
        int pitchRaw;
        int rollRaw;
        boolean motorOn;
        boolean vibrating;
        boolean escError;
        boolean motorBlock;
        boolean notEnoughForce;
        boolean propellerFault;
        double heightM;

        static Osd parse(byte[] p) {
            if (p == null) return null;
            int b = base(p);
            if (b < 0 || p.length < b + 49) return null;

            Osd o = new Osd();
            o.heightM = s16(p, b + 16) / 10.0;
            o.pitchRaw = s16(p, b + 24);
            o.rollRaw = s16(p, b + 26);
            o.fcState = (p[b + 30] & 0xFF) & 0x7F;
            long cs = u32(p, b + 32);
            o.groundSky = (int) ((cs >>> 1) & 0x03L);
            o.motorOn = (cs & 0x08L) != 0;
            o.vibrating = (cs & (1L << 25)) != 0;
            o.motorBlock = (cs & (1L << 27)) != 0;
            o.notEnoughForce = (cs & (1L << 28)) != 0;
            o.propellerFault = (cs & (1L << 29)) != 0;
            o.motorRevolutionRaw = p[b + 44] & 0xFF;
            o.flycVersion = p[b + 47] & 0xFF;
            // In DJI GetPushCommon this bit is ESC error on FC versions >= 7.
            o.escError = o.flycVersion >= 7 && (cs & (1L << 26)) != 0;
            return o;
        }

        private static int base(byte[] p) {
            if (p.length == 50 || p.length == 55) return 0;
            if ((p.length == 51 || p.length == 56) && (p[0] & 0xFF) == 0) return 1;
            if (p.length >= 50) return 0;
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

    private void setStatus(String text) {
        runOnUiThread(() -> status.setText(text));
    }

    private void finishUi(String text) {
        setStatus(text);
        append("RESULT: " + text.replace('\n', ' '));
        running.set(false);
    }

    private void append(String text) {
        runOnUiThread(() -> log.append(text + "\n"));
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
            } catch (Exception e) {
                return null;
            }
        }

        private AoaSession(ParcelFileDescriptor p, AtomicInteger sequence) {
            pfd = p;
            seq = sequence;
            in = new FileInputStream(p.getFileDescriptor());
            out = new FileOutputStream(p.getFileDescriptor());
            reader = new Thread(this::readLoop, "mini4k-prop-rx");
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
                while (alive.get()) {
                    try {
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_REMOTE_RADIO, 0,
                                seq.getAndIncrement() & 0xFFFF, 0x06, 0x77, p, false));
                        sleep(4);
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, 14, 0,
                                seq.getAndIncrement() & 0xFFFF, 0x06, 0x77, p, false));
                    } catch (Exception e) {
                        break;
                    }
                    sleep(2500);
                }
            }, "mini4k-prop-keepalive");
            keepalive.setDaemon(true);
            keepalive.start();
        }

        DumlV2.Frame poll(long ms) throws InterruptedException {
            return rx.poll(ms, TimeUnit.MILLISECONDS);
        }

        String routeString() {
            return String.format(Locale.US, "0x%04X", route);
        }

        void sendDuml(byte[] duml) throws IOException {
            int n = duml.length;
            byte[] w = new byte[8 + n];
            w[0] = 0x55;
            w[1] = (byte) 0xCC;
            w[2] = (byte) (route & 0xFF);
            w[3] = (byte) ((route >>> 8) & 0xFF);
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
                while (alive.get()) {
                    int n = in.read(b);
                    if (n < 0) break;
                    if (n > 0) feed(b, n);
                }
            } catch (Exception ignored) {
            } finally {
                alive.set(false);
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
                    if (x == 0x55) {
                        header[0] = 0x55;
                        headerPos = 1;
                    }
                    continue;
                }
                if (headerPos == 1) {
                    if (x == 0xCC) {
                        header[1] = (byte) 0xCC;
                        headerPos = 2;
                    } else if (x == 0x55) {
                        header[0] = 0x55;
                        headerPos = 1;
                    } else {
                        headerPos = 0;
                    }
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
            if ((bodyType == 0x5749 || bodyType == 0x7530) && body != null) {
                for (DumlV2.Frame f : DumlV2.frames(body.toByteArray())) {
                    if (!rx.offer(f)) {
                        rx.poll();
                        rx.offer(f);
                    }
                }
            }
            bodyType = -1;
            body = null;
            bodyLeft = 0;
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

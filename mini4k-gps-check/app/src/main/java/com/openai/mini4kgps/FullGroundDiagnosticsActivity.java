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
import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Full ground-only diagnostics for DJI Mini 4K.
 *
 * READ ONLY: no E3/F9 parameter writes, no motor start/stop commands, no flight commands.
 * Phase 1: 30 s with motors OFF.
 * Phase 2: user manually starts motors at idle; 20 s with Motors=ON while still on the ground.
 * The test aborts if FC reports an airborne ground/sky state.
 */
public class FullGroundDiagnosticsActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.FULL_GROUND_USB_PERMISSION";
    private static final long OFF_TEST_MS = 30_000L;
    private static final long ON_TEST_MS = 20_000L;
    private static final long WAIT_MOTORS_MS = 60_000L;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x9000);
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
        usbManager = (UsbManager) getSystemService(Context.USB_SERVICE);
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
        title.setText("Mini 4K FULL GROUND DIAGNOSTICS v1.7");
        title.setTextSize(21);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        start = new Button(this);
        start.setText("START FULL GROUND TEST — READ ONLY");
        root.addView(start, top(12));

        stop = new Button(this);
        stop.setText("STOP");
        stop.setEnabled(false);
        root.addView(stop, top(8));

        status = new TextView(this);
        status.setTextSize(16);
        status.setTextIsSelectable(true);
        status.setPadding(0, dp(12), 0, dp(10));
        status.setText(
                "ВСЕ ЭТАПЫ ТОЛЬКО НА ЗЕМЛЕ.\n\n" +
                "Этап 1: 30 сек, моторы OFF.\n" +
                "Этап 2: приложение попросит вручную запустить моторы CSC; 20 сек на холостых, газ НЕ поднимать.\n\n" +
                "Проверяются FC/GNSS/IMU/компас/барометр/моторы/ESC/пропеллеры/батарея/RC/Home и доступные anti-jam/anti-spoof флаги.\n" +
                "Если показатель не отдаётся твоим Mini 4K — будет N/A, а не выдуманный PASS.");
        root.addView(status, new LinearLayout.LayoutParams(-1, -2));

        log = new TextView(this);
        log.setTextSize(12);
        log.setTextIsSelectable(true);
        log.setText("READ ONLY. WRITE COMMANDS SENT: 0\nПриложение само моторы НЕ запускает и НЕ останавливает.\n\n");
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

    private int dp(int x) { return Math.round(x * getResources().getDisplayMetrics().density); }

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
                if (ok && pendingStart) { pendingStart = false; begin(); }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(intent.getAction())) {
                end("RC-N1 AOA отключён.");
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
        io.submit(() -> runFullTest(a));
    }

    private void end(String why) {
        running.set(false);
        AoaSession s = activeSession;
        if (s != null) s.close();
        activeSession = null;
        append(why);
        runOnUiThread(() -> { start.setEnabled(true); stop.setEnabled(false); });
    }

    private void runFullTest(UsbAccessory a) {
        AoaSession s = null;
        try {
            s = AoaSession.open(usbManager, a, seq);
            activeSession = s;
            if (s == null) { finishUi("AOA pipe не открылся. DJI Fly должен быть полностью закрыт."); return; }
            s.startProtocol();
            sleep(350);
            append("AOA OPEN route=" + s.routeString() + "; FULL GROUND TEST armed; WRITE COMMANDS SENT: 0");

            Stats st = new Stats();
            setStatus("ЭТАП 1/2 — МОТОРЫ OFF\n30 секунд. Дрон не двигать.");
            append("PHASE 1: 30 s Motors=OFF.");
            long phaseStart = System.currentTimeMillis();
            long lastUi = 0;
            while (running.get() && System.currentTimeMillis() - phaseStart < OFF_TEST_MS) {
                DumlV2.Frame f = s.poll(300);
                if (f != null) processFrame(st, f, false);
                if (st.airborneDetected) { finishUi("FAIL SAFE STOP\nFC сообщил состояние В ВОЗДУХЕ. Тест только наземный и остановлен."); return; }
                long now = System.currentTimeMillis();
                if (now - lastUi > 700) {
                    long left = Math.max(0, (OFF_TEST_MS - (now - phaseStart) + 999) / 1000);
                    setStatus("ЭТАП 1/2 — МОТОРЫ OFF\nОсталось: " + left + " сек\n0x43=" + st.commonOff + " 0x44=" + st.homeOff + " battery=" + st.batteryOff);
                    lastUi = now;
                }
            }
            if (!running.get()) return;
            if (st.commonOff < 20) { finishUi("INCOMPLETE\nНедостаточно телеметрии 0x43 на этапе Motors=OFF."); return; }

            setStatus("ЭТАП 2/2 — ЖДУ МОТОРЫ\n\nЗапусти моторы вручную CSC.\nГАЗ ВВЕРХ НЕ ДАВАТЬ. Дрон должен оставаться на земле.");
            append("PHASE 2: waiting for Motors=ON; user starts manually; no throttle.");
            long waitStart = System.currentTimeMillis();
            boolean motorStarted = false;
            while (running.get() && System.currentTimeMillis() - waitStart < WAIT_MOTORS_MS) {
                DumlV2.Frame f = s.poll(300);
                if (f != null) {
                    processFrame(st, f, true);
                    if (st.lastMotorOn) { motorStarted = true; break; }
                }
                if (st.airborneDetected) { finishUi("FAIL SAFE STOP\nFC сообщил состояние В ВОЗДУХЕ. Тест остановлен."); return; }
            }
            if (!running.get()) return;
            if (!motorStarted) { finishUi("INCOMPLETE\nЗа 60 секунд Motors=ON не обнаружен."); return; }

            append("Motors=ON detected → 20 s idle ground test.");
            long onStart = System.currentTimeMillis();
            lastUi = 0;
            while (running.get() && System.currentTimeMillis() - onStart < ON_TEST_MS) {
                DumlV2.Frame f = s.poll(250);
                if (f != null) processFrame(st, f, true);
                if (st.airborneDetected) { finishUi("FAIL SAFE STOP\nОбнаружен отрыв от земли. Тест остановлен."); return; }
                if (st.seenMotorOn && !st.lastMotorOn && System.currentTimeMillis() - onStart > 1500) {
                    finishUi("INCOMPLETE\nМоторы остановились до окончания 20-секундного этапа."); return;
                }
                long now = System.currentTimeMillis();
                if (now - lastUi > 700) {
                    long left = Math.max(0, (ON_TEST_MS - (now - onStart) + 999) / 1000);
                    setStatus("ЭТАП 2/2 — МОТОРЫ ON, ЗЕМЛЯ\nОсталось: " + left + " сек\nГаз не поднимать.\n0x43=" + st.commonOn + " 0x44=" + st.homeOn + " battery=" + st.batteryOn);
                    lastUi = now;
                }
            }
            if (!running.get()) return;
            String report = buildReport(st);
            finishUi(report);
            append("RESULT:\n" + report.replace("\n", " | "));
        } catch (Throwable t) {
            finishUi("ERROR\n" + t.getClass().getSimpleName() + ": " + t.getMessage());
        } finally {
            if (s != null) s.close();
            activeSession = null;
            running.set(false);
            runOnUiThread(() -> { start.setEnabled(true); stop.setEnabled(false); });
            append("AOA pipe: CLOSED; WRITE COMMANDS SENT: 0");
        }
    }

    private void processFrame(Stats st, DumlV2.Frame f, boolean phaseOnOrWait) {
        st.totalFrames++;
        if (f.senderType >= 0 && f.senderType < st.senderCounts.length) st.senderCounts[f.senderType]++;

        if (f.cmdSet == DumlV2.CMDSET_FLYC && f.cmdId == 0x43) {
            Common c = Common.parse(f.payload);
            if (c == null) return;
            st.lastMotorOn = c.motorOn;
            if (c.motorOn) { st.seenMotorOn = true; st.commonOn++; } else st.commonOff++;
            if (c.groundSky >= 2) st.airborneDetected = true;
            st.flycVersion = c.flycVersion;
            st.droneType = c.droneType;
            st.lastFcState = c.fcState;
            st.lastRcMode = c.rcMode;
            st.maxSats = Math.max(st.maxSats, c.sats);
            st.maxGpsLevel = Math.max(st.maxGpsLevel, c.gpsLevel);
            st.maxAbsHeight = Math.max(st.maxAbsHeight, Math.abs(c.height));
            st.maxGroundSpeed = Math.max(st.maxGroundSpeed, Math.hypot(c.vx, c.vy));
            if (!c.rcConnected) st.rcDisconnect++;
            if (c.compassError) st.compassErrors++;
            if (!c.imuPreheated) st.imuNotReady++;
            if (c.vibrating) st.vibration++;
            if (c.escError) st.escErrors++;
            if (c.motorBlock) st.motorBlocks++;
            if (c.notEnoughForce) st.notEnoughForce++;
            if (c.propellerFault) st.propellerFaults++;
            if (c.barometerDead) st.baroErrors++;
            if (c.gpsUsed) st.gpsUsedSamples++;
            if (c.gpsValid) st.gpsValidSamples++;
            if (c.visionUsed) st.visionUsedSamples++;
            st.batteryCommonMin = Math.min(st.batteryCommonMin, c.batteryPercent);
            st.batteryCommonMax = Math.max(st.batteryCommonMax, c.batteryPercent);
            st.motorRevMin = Math.min(st.motorRevMin, c.motorRevolution);
            st.motorRevMax = Math.max(st.motorRevMax, c.motorRevolution);
            st.motorRevSum += c.motorRevolution;
            st.motorRevN++;
            return;
        }

        if (f.cmdSet == DumlV2.CMDSET_FLYC && f.cmdId == 0x44) {
            Home h = Home.parse(f.payload);
            if (h == null) return;
            if (st.lastMotorOn) st.homeOn++; else st.homeOff++;
            st.homeSeen = true;
            st.homeRecorded |= h.homeRecorded;
            st.goHomeHeight = h.goHomeHeight;
            st.impactGround |= h.impactGround;
            st.wingBroken |= h.wingBroken;
            st.bigGale |= h.bigGale;
            st.compassInstallError |= h.compassInstallError;
            st.mainGpsSheltered |= h.mainGpsSheltered;
            st.extraLoad |= h.extraLoad;
            st.imuDeviceAbnormal |= h.imuDeviceAbnormal;
            st.compassDeviceAbnormal |= h.compassDeviceAbnormal;
            st.baroDeviceAbnormal |= h.baroDeviceAbnormal;
            st.gpsDeviceAbnormal |= h.gpsDeviceAbnormal;
            st.navDeviceAbnormal |= h.navDeviceAbnormal;
            st.imuAbnormal |= h.imuAbnormal;
            st.compassAbnormal |= h.compassAbnormal;
            st.baroAbnormal |= h.baroAbnormal;
            st.gpsAbnormal |= h.gpsAbnormal;
            st.navAbnormal |= h.navAbnormal;
            st.jammingState = Math.max(st.jammingState, h.jammingState);
            st.spoofingState = Math.max(st.spoofingState, h.spoofingState);
            st.envTempState = h.envTempState;
            st.motorPropAbnormal |= h.motorPropAbnormal != 0;
            for (int i = 0; i < 4; i++) {
                if (h.motorEscm[i] >= 0) st.motorEscmLast[i] = h.motorEscm[i];
                if (isMotorEscmFault(h.motorEscm[i])) st.motorEscmFaultCount[i]++;
            }
            return;
        }

        if (f.cmdSet == DumlV2.CMDSET_FLYC && f.cmdId == 0x51) {
            Battery b = Battery.parse(f.payload);
            if (b == null) return;
            if (st.lastMotorOn) st.batteryOn++; else st.batteryOff++;
            st.batterySeen = true;
            st.battMinPercent = Math.min(st.battMinPercent, b.percent);
            st.battMaxPercent = Math.max(st.battMaxPercent, b.percent);
            st.battMinVoltage = Math.min(st.battMinVoltage, b.voltageMv);
            st.battMaxVoltage = Math.max(st.battMaxVoltage, b.voltageMv);
            st.batteryStatusOr |= b.status;
        }
    }

    private String buildReport(Stats s) {
        int fail = 0, warn = 0;
        boolean motorFail = s.escErrors > 0 || s.motorBlocks > 0 || s.notEnoughForce > 0 || s.propellerFaults > 0 || s.motorPropAbnormal;
        for (int i = 0; i < 4; i++) motorFail |= s.motorEscmFaultCount[i] > 0;
        boolean sensorFail = s.imuDeviceAbnormal || s.compassDeviceAbnormal || s.baroDeviceAbnormal || s.navDeviceAbnormal ||
                s.imuAbnormal || s.compassAbnormal || s.baroAbnormal || s.navAbnormal || s.wingBroken;
        boolean batteryFail = (s.batteryStatusOr & (64 | 128 | 8192)) != 0;
        if (motorFail) fail++;
        if (sensorFail) fail++;
        if (batteryFail) fail++;

        boolean gpsWarn = s.gpsAbnormal || s.gpsDeviceAbnormal || s.jammingState >= 2 || s.spoofingState >= 2 || s.mainGpsSheltered;
        boolean commonWarn = s.compassErrors > 0 || s.vibration > 0 || s.rcDisconnect > 0 || s.baroErrors > 0 || s.maxGroundSpeed > 2.0;
        boolean batteryWarn = (s.batteryStatusOr & (16 | 32 | 256 | 512 | 1024 | 2048 | 4096 | 16384 | 32768 | 65536)) != 0;
        if (gpsWarn) warn++;
        if (commonWarn) warn++;
        if (batteryWarn) warn++;

        String overall = fail > 0 ? "FAIL" : (warn > 0 ? "WARNING" : "PASS");
        StringBuilder r = new StringBuilder();
        r.append(overall).append(" — FULL GROUND DIAGNOSTICS\n\n");
        r.append("FC: ").append(sensorFail ? "FAIL" : "OK")
                .append(" | FLYC v").append(s.flycVersion).append(" | state=").append(s.lastFcState)
                .append(" | RC mode=").append(s.lastRcMode).append("\n");
        r.append("RC link: ").append(s.rcDisconnect == 0 ? "OK" : "WARNING disconnect samples=" + s.rcDisconnect).append("\n");
        r.append("IMU: ").append(flag(s.imuDeviceAbnormal || s.imuAbnormal, "FAIL", "OK"))
                .append(" | not-ready samples=").append(s.imuNotReady).append("\n");
        r.append("Compass: ").append(flag(s.compassDeviceAbnormal || s.compassAbnormal || s.compassInstallError, "FAIL", s.compassErrors > 0 ? "WARNING" : "OK"))
                .append(" | common errors=").append(s.compassErrors).append("\n");
        r.append("Barometer: ").append(flag(s.baroDeviceAbnormal || s.baroAbnormal, "FAIL", s.baroErrors > 0 ? "WARNING" : "OK"))
                .append(" | max |height|=").append(fmt(s.maxAbsHeight)).append(" m\n");
        r.append("GNSS: ").append(gpsWarn ? "WARNING" : "OK")
                .append(" | sats max=").append(s.maxSats).append(" | level max=").append(s.maxGpsLevel)
                .append(" | jam=").append(jamName(s.jammingState)).append(" | spoof=").append(spoofName(s.spoofingState)).append("\n");
        r.append("Ground motion check: max horizontal=").append(fmt(s.maxGroundSpeed)).append(" m/s")
                .append(s.maxGroundSpeed > 2.0 ? " WARNING" : " OK").append("\n");
        r.append("Motors/ESC/props: ").append(motorFail ? "FAIL" : "OK")
                .append(" | ESC=").append(s.escErrors).append(" block=").append(s.motorBlocks)
                .append(" force=").append(s.notEnoughForce).append(" prop=").append(s.propellerFaults)
                .append(" vibration=").append(s.vibration).append("\n");
        r.append("Motor ESC states M1..M4: ");
        for (int i = 0; i < 4; i++) {
            if (i > 0) r.append(" / ");
            r.append(motorEscmName(s.motorEscmLast[i]));
        }
        r.append("\n");
        if (s.motorRevN > 0) r.append("Motor revolution raw: ").append(s.motorRevMin).append("..").append(s.motorRevMax)
                .append(" avg=").append(String.format(Locale.US, "%.1f", s.motorRevSum / (double) s.motorRevN)).append(" (aggregate FC field)\n");
        r.append("Battery: ");
        if (s.batterySeen) {
            r.append(batteryFail ? "FAIL" : (batteryWarn ? "WARNING" : "OK"))
                    .append(" | ").append(s.battMinPercent).append("..").append(s.battMaxPercent).append("%")
                    .append(" | ").append(s.battMinVoltage).append("..").append(s.battMaxVoltage).append(" mV")
                    .append(" | status=0x").append(Long.toHexString(s.batteryStatusOr).toUpperCase(Locale.US));
        } else {
            r.append("N/A dedicated 0x51; common battery=")
                    .append(s.batteryCommonMin == 999 ? "N/A" : (s.batteryCommonMin + ".." + s.batteryCommonMax + "%"));
        }
        r.append("\n");
        r.append("Home/RTH: ");
        if (s.homeSeen) r.append("homeRecorded=").append(s.homeRecorded ? "YES" : "NO").append(" | RTH height=").append(s.goHomeHeight).append(" m");
        else r.append("N/A — 0x44 not received");
        r.append("\n");
        r.append("Vision used samples: ").append(s.visionUsedSamples).append(" (ground stream; not a full VPS optical test)\n");
        r.append("Camera traffic: ").append(s.senderCounts[1] > 0 ? "DETECTED" : "N/A").append(" | Gimbal traffic: ").append(s.senderCounts[4] > 0 ? "DETECTED" : "N/A").append("\n");
        r.append("Packets: total=").append(s.totalFrames).append(" 0x43 OFF/ON=").append(s.commonOff).append("/").append(s.commonOn)
                .append(" 0x44 OFF/ON=").append(s.homeOff).append("/").append(s.homeOn)
                .append(" battery OFF/ON=").append(s.batteryOff).append("/").append(s.batteryOn).append("\n\n");
        r.append("Важно: весь тест выполнен на земле. PASS означает отсутствие обнаруженных FC/сенсорных/ESC/пропеллерных/батарейных ошибок в доступной телеметрии; это не стендовое измерение тяги и не заменяет визуальный осмотр.");
        return r.toString();
    }

    private static String flag(boolean bad, String badText, String okText) { return bad ? badText : okText; }
    private static String fmt(double v) { return String.format(Locale.US, "%.2f", v); }

    private static boolean isMotorEscmFault(int x) { return x >= 1 && x <= 7 || x == 11; }
    private static String motorEscmName(int x) {
        switch (x) {
            case 0: return "NON_SMART"; case 1: return "DISCONNECT"; case 2: return "SIGNAL_ERROR";
            case 3: return "RESISTANCE_ERROR"; case 4: return "BLOCK"; case 5: return "NON_BALANCE";
            case 6: return "ESCM_ERROR"; case 7: return "PROPELLER_OFF"; case 8: return "MOTOR_IDLE";
            case 9: return "MOTOR_UP"; case 10: return "MOTOR_OFF"; case 11: return "NON_CONNECT";
            default: return x < 0 ? "N/A" : "OTHER(" + x + ")";
        }
    }
    private static String jamName(int x) {
        switch (x) { case 1: return "OK"; case 2: return "WARNING"; case 3: return "CRITICAL"; default: return "UNKNOWN"; }
    }
    private static String spoofName(int x) {
        switch (x) { case 1: return "OK"; case 2: return "WARNING"; case 3: return "CRITICAL"; default: return "UNKNOWN"; }
    }

    private static final class Stats {
        long totalFrames, commonOff, commonOn, homeOff, homeOn, batteryOff, batteryOn;
        final long[] senderCounts = new long[32];
        boolean lastMotorOn, seenMotorOn, airborneDetected;
        int flycVersion = -1, droneType = -1, lastFcState = -1, lastRcMode = -1;
        int maxSats, maxGpsLevel, rcDisconnect, compassErrors, imuNotReady, vibration, escErrors, motorBlocks, notEnoughForce, propellerFaults, baroErrors;
        int gpsUsedSamples, gpsValidSamples, visionUsedSamples;
        double maxAbsHeight, maxGroundSpeed;
        int batteryCommonMin = 999, batteryCommonMax = -1;
        int motorRevMin = Integer.MAX_VALUE, motorRevMax = Integer.MIN_VALUE, motorRevN;
        long motorRevSum;
        boolean homeSeen, homeRecorded, impactGround, wingBroken, bigGale, compassInstallError, mainGpsSheltered, extraLoad;
        int goHomeHeight = -1;
        boolean imuDeviceAbnormal, compassDeviceAbnormal, baroDeviceAbnormal, gpsDeviceAbnormal, navDeviceAbnormal;
        boolean imuAbnormal, compassAbnormal, baroAbnormal, gpsAbnormal, navAbnormal, motorPropAbnormal;
        int jammingState, spoofingState, envTempState = -1;
        final int[] motorEscmLast = new int[]{-1,-1,-1,-1};
        final int[] motorEscmFaultCount = new int[4];
        boolean batterySeen;
        int battMinPercent = 999, battMaxPercent = -1, battMinVoltage = Integer.MAX_VALUE, battMaxVoltage = -1;
        long batteryStatusOr;
    }

    private static final class Common {
        int fcState, sats, gpsLevel, groundSky, rcMode, batteryPercent, motorRevolution, flycVersion, droneType;
        boolean rcConnected, motorOn, visionUsed, gpsUsed, gpsValid, compassError, imuPreheated, vibrating, escError, motorBlock, notEnoughForce, propellerFault, barometerDead;
        double height, vx, vy, vz;
        static Common parse(byte[] p) {
            if (p == null) return null;
            int b = base(p);
            if (b < 0 || p.length < b + 49) return null;
            Common o = new Common();
            o.height = s16(p,b+16)/10.0; o.vx=s16(p,b+18)/10.0; o.vy=s16(p,b+20)/10.0; o.vz=s16(p,b+22)/10.0;
            int c = p[b+30]&0xFF; o.rcConnected=(c&0x80)==0; o.fcState=c&0x7F;
            long cs=u32(p,b+32); o.groundSky=(int)((cs>>>1)&3); o.motorOn=(cs&0x08L)!=0; o.visionUsed=(cs&0x100L)!=0;
            o.rcMode=(int)((cs>>>13)&3); o.gpsUsed=(cs&0x8000L)!=0; o.compassError=(cs&0x10000L)!=0; o.gpsLevel=(int)((cs>>>18)&15);
            o.vibrating=(cs&(1L<<25))!=0; o.escError=(cs&(1L<<26))!=0; o.motorBlock=(cs&(1L<<27))!=0; o.notEnoughForce=(cs&(1L<<28))!=0; o.propellerFault=(cs&(1L<<29))!=0;
            o.gpsValid=((p[b+33]&0x80)!=0); o.sats=p[b+36]&0xFF; o.batteryPercent=p[b+40]&0xFF; o.motorRevolution=p[b+44]&0xFF;
            o.flycVersion=p[b+47]&0xFF; o.droneType=p[b+48]&0xFF; o.imuPreheated=(cs&0x1000L)!=0;
            o.barometerDead=o.flycVersion<7 && (cs&(1L<<26))!=0;
            return o;
        }
        private static int base(byte[] p) {
            if (p.length==50||p.length==55) return 0;
            if ((p.length==51||p.length==56)&&(p[0]&0xFF)==0) return 1;
            if (p.length>=50) return 0;
            return -1;
        }
    }

    private static final class Home {
        boolean homeRecorded, impactGround, wingBroken, bigGale, compassInstallError, mainGpsSheltered, extraLoad;
        int goHomeHeight=-1, jammingState, spoofingState, envTempState=-1, motorPropAbnormal;
        boolean imuDeviceAbnormal, compassDeviceAbnormal, baroDeviceAbnormal, gpsDeviceAbnormal, navDeviceAbnormal;
        boolean imuAbnormal, compassAbnormal, baroAbnormal, gpsAbnormal, navAbnormal;
        final int[] motorEscm = new int[]{-1,-1,-1,-1};
        static Home parse(byte[] p) {
            if (p==null||p.length<23) return null;
            Home h=new Home();
            int m=u16(p,20); h.homeRecorded=(m&1)!=0; if(p.length>=24) h.goHomeHeight=u16(p,22);
            if(p.length>=36){ long f=u32(p,32); h.impactGround=(f&16)!=0; h.wingBroken=(f&4096)!=0; h.bigGale=(f&16384)!=0; h.compassInstallError=(f&8388608)!=0; h.mainGpsSheltered=(f&(1L<<30))!=0; h.extraLoad=(f&(1L<<31))!=0; }
            if(p.length>=45){ long ms=u32(p,41); for(int i=0;i<4;i++) h.motorEscm[i]=(int)((ms>>>(i*4))&15); }
            if(p.length>=50){ long r=u32(p,46); h.imuDeviceAbnormal=((r>>>2)&1)!=0; h.compassDeviceAbnormal=((r>>>3)&1)!=0; h.baroDeviceAbnormal=((r>>>4)&1)!=0; h.gpsDeviceAbnormal=((r>>>5)&1)!=0; h.navDeviceAbnormal=((r>>>6)&1)!=0; h.imuAbnormal=((r>>>12)&1)!=0; h.compassAbnormal=((r>>>13)&1)!=0; h.baroAbnormal=((r>>>14)&1)!=0; h.gpsAbnormal=((r>>>15)&1)!=0; h.navAbnormal=((r>>>16)&1)!=0; }
            if(p.length>=76){ int x=p[75]&0xFF; h.envTempState=x&3; h.jammingState=(x>>>3)&3; h.spoofingState=(x>>>5)&3; }
            if(p.length>=78) h.motorPropAbnormal=p[77]&0xFF;
            return h;
        }
    }

    private static final class Battery {
        int usefulTime, goHomeTime, landTime, goHomeBattery, landBattery, voltageMv, percent;
        long status;
        static Battery parse(byte[] p) {
            if(p==null||p.length<30) return null;
            Battery b=new Battery(); b.usefulTime=u16(p,0); b.goHomeTime=u16(p,2); b.landTime=u16(p,4); b.goHomeBattery=u16(p,6); b.landBattery=u16(p,8);
            b.status=u32(p,18); b.voltageMv=u16(p,24); b.percent=p[26]&0xFF; return b;
        }
    }

    private static int s16(byte[] a,int o){ return (short)((a[o]&0xFF)|((a[o+1]&0xFF)<<8)); }
    private static int u16(byte[] a,int o){ return (a[o]&0xFF)|((a[o+1]&0xFF)<<8); }
    private static long u32(byte[] a,int o){ return ((long)a[o]&255)|(((long)a[o+1]&255)<<8)|(((long)a[o+2]&255)<<16)|(((long)a[o+3]&255)<<24); }

    private void setStatus(String s) { runOnUiThread(() -> status.setText(s)); }
    private void append(String s) { runOnUiThread(() -> log.append(s + "\n")); }
    private void finishUi(String s) { setStatus(s); }
    private static void sleep(long ms) { try { Thread.sleep(ms); } catch (InterruptedException e) { Thread.currentThread().interrupt(); } }

    /** Android Open Accessory + DJI RCLink envelope transport. */
    private static final class AoaSession {
        private final ParcelFileDescriptor pfd;
        private final FileInputStream in;
        private final FileOutputStream out;
        private final AtomicInteger seq;
        private final AtomicBoolean alive = new AtomicBoolean(true);
        private final LinkedBlockingQueue<DumlV2.Frame> rx = new LinkedBlockingQueue<>(10000);
        private final Object writeLock = new Object();
        private final Thread reader;
        private Thread keepalive;
        private volatile int route = 0x5749;
        private int headerPos;
        private final byte[] header = new byte[8];
        private long bodyLeft;
        private int bodyType=-1;
        private ByteArrayOutputStream body;

        static AoaSession open(UsbManager m, UsbAccessory a, AtomicInteger seq) {
            try { ParcelFileDescriptor p=m.openAccessory(a); return p==null?null:new AoaSession(p,seq); } catch(Exception e){ return null; }
        }
        private AoaSession(ParcelFileDescriptor p, AtomicInteger q) {
            pfd=p; seq=q; in=new FileInputStream(p.getFileDescriptor()); out=new FileOutputStream(p.getFileDescriptor());
            reader=new Thread(this::readLoop,"mini4k-full-ground-rx"); reader.setDaemon(true); reader.start();
        }
        void startProtocol() throws IOException {
            byte[] boot=new byte[]{0,0,1};
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_AIRCRAFT_PROXY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(4);
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_ANY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(8); startKeepalive();
        }
        private void startKeepalive(){
            keepalive=new Thread(()->{ sleep(2500); byte[] p=new byte[]{1,1,0,(byte)0xFF,(byte)0xFF,0x20,0,0};
                while(alive.get()){
                    try{
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_REMOTE_RADIO,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false));
                        sleep(4);
                        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,14,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false));
                    }catch(Exception e){break;} sleep(2500);
                }
            },"mini4k-full-ground-keepalive"); keepalive.setDaemon(true); keepalive.start();
        }
        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        String routeString(){ return String.format(Locale.US,"0x%04X",route); }
        void sendDuml(byte[] duml) throws IOException {
            int n=duml.length; byte[] w=new byte[8+n]; w[0]=0x55;w[1]=(byte)0xCC;w[2]=(byte)(route&255);w[3]=(byte)((route>>>8)&255);
            w[4]=(byte)(n&255);w[5]=(byte)((n>>>8)&255);w[6]=(byte)((n>>>16)&255);w[7]=(byte)((n>>>24)&255);System.arraycopy(duml,0,w,8,n);
            synchronized(writeLock){out.write(w);out.flush();} sleep(3);
        }
        private void readLoop(){ byte[] b=new byte[16384]; try{ while(alive.get()){int n=in.read(b);if(n<0)break;if(n>0)feed(b,n);} }catch(Exception ignored){}finally{alive.set(false);} }
        private void feed(byte[] a,int n){
            for(int i=0;i<n;i++){int x=a[i]&255;
                if(bodyLeft>0){if(body!=null)body.write(x);bodyLeft--;if(bodyLeft==0)finishUnit();continue;}
                if(headerPos==0){if(x==0x55){header[0]=0x55;headerPos=1;}continue;}
                if(headerPos==1){if(x==0xCC){header[1]=(byte)0xCC;headerPos=2;}else if(x==0x55){header[0]=0x55;headerPos=1;}else headerPos=0;continue;}
                header[headerPos++]=(byte)x;
                if(headerPos==8){int type=(header[2]&255)|((header[3]&255)<<8);long len=((long)header[4]&255)|(((long)header[5]&255)<<8)|(((long)header[6]&255)<<16)|(((long)header[7]&255)<<24);headerPos=0;
                    if(len<0||len>0x200000L){bodyLeft=0;body=null;bodyType=-1;continue;}bodyType=type;bodyLeft=len;
                    if(type==0x5749||type==0x7530){route=type;body=new ByteArrayOutputStream((int)Math.min(len,16384));}else body=null;if(bodyLeft==0)finishUnit();}
            }
        }
        private void finishUnit(){
            if((bodyType==0x5749||bodyType==0x7530)&&body!=null){for(DumlV2.Frame f:DumlV2.frames(body.toByteArray())){if(!rx.offer(f)){rx.poll();rx.offer(f);}}}
            bodyType=-1;body=null;bodyLeft=0;
        }
        void close(){ if(!alive.getAndSet(false))return; try{if(keepalive!=null)keepalive.interrupt();}catch(Exception ignored){}try{reader.interrupt();}catch(Exception ignored){}try{in.close();}catch(Exception ignored){}try{out.close();}catch(Exception ignored){}try{pfd.close();}catch(Exception ignored){} }
    }
}

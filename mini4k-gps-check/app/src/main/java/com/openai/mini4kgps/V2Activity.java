package com.openai.mini4kgps;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.hardware.usb.UsbDevice;
import android.hardware.usb.UsbDeviceConnection;
import android.hardware.usb.UsbManager;
import android.os.Build;
import android.os.Bundle;
import android.text.method.ScrollingMovementMethod;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import com.hoho.android.usbserial.driver.CdcAcmSerialDriver;
import com.hoho.android.usbserial.driver.ProbeTable;
import com.hoho.android.usbserial.driver.UsbSerialDriver;
import com.hoho.android.usbserial.driver.UsbSerialPort;
import com.hoho.android.usbserial.driver.UsbSerialProber;

import java.io.ByteArrayOutputStream;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;

public class V2Activity extends Activity {
    private static final int DJI_VENDOR = 11427;
    private static final int RC_N1_PID = 4128;
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.USB_PERMISSION";

    private static final byte[] RC_N1_INIT_PROBE = new byte[]{
            0x55, 0x0D, 0x04, 0x21, 0x2A, 0x1F, 0x00, 0x00, 0x00, 0x00, 0x01, (byte) 0x86, 0x20
    };

    private static final int RELATED_MODEL_GPS_TABLE = 0;
    private static final int RELATED_MODEL_GPS_INDEX = 771;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger sequence = new AtomicInteger(0x5200);

    private UsbManager usbManager;
    private TextView status;
    private Button checkButton;
    private Button offButton;
    private Button onButton;

    private enum ParamMode { NONE, HASH_2015, INDEX_2017 }

    private volatile ParamMode confirmedMode = ParamMode.NONE;
    private volatile Route confirmedRoute = null;
    private volatile Long confirmedGpsHash = null;
    private volatile Integer confirmedTable = null;
    private volatile Integer confirmedIndex = null;
    private volatile String confirmedParamName = null;

    private static final class Route {
        final int senderType;
        final int senderIndex;
        final int receiverType;
        final int receiverIndex;
        final String label;

        Route(int senderType, int senderIndex, int receiverType, int receiverIndex, String label) {
            this.senderType = senderType;
            this.senderIndex = senderIndex;
            this.receiverType = receiverType;
            this.receiverIndex = receiverIndex;
            this.label = label;
        }

        @Override public String toString() { return label; }
    }

    private static final Route[] ROUTES = new Route[]{
            new Route(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0, "APP0 -> FLYC"),
            new Route(DumlV2.DEV_PC, 0, DumlV2.DEV_FLYCONTROLLER, 0, "PC0 -> FLYC"),
            new Route(DumlV2.DEV_PC, 1, DumlV2.DEV_FLYCONTROLLER, 0, "PC1 -> FLYC"),
            new Route(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_AIRCRAFT_PROXY, 0, "APP0 -> AIRCRAFT(31)"),
            new Route(DumlV2.DEV_PC, 0, DumlV2.DEV_AIRCRAFT_PROXY, 0, "PC0 -> AIRCRAFT(31)"),
            new Route(DumlV2.DEV_PC, 1, DumlV2.DEV_AIRCRAFT_PROXY, 0, "PC1 -> AIRCRAFT(31)")
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        usbManager = (UsbManager) getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
        showIntro();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        try { unregisterReceiver(usbReceiver); } catch (Exception ignored) {}
        io.shutdownNow();
    }

    private void showIntro() {
        status.setText("");
        append("Mini 4K GPS Parameter Test v0.2");
        append("ТЕСТ ТОЛЬКО НА ЗЕМЛЕ. МОТОРЫ НЕ ЗАПУСКАТЬ.");
        append("");
        append("v0.2: проверяет несколько USB/DUML маршрутов, принимает ответ даже если RC-N1 меняет sequence,");
        append("а если старый hash-протокол закрыт — пробует 2017 table/index.");
        append("");
        append("CHECK не записывает параметры полёта. GPS OFF/ON останутся заблокированы, пока gps_enable");
        append("не будет подтверждён по имени + типу + размеру + диапазону 0..1 + текущему значению.");
    }

    private void buildUi() {
        int pad = dp(16);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("Mini 4K GPS CHECK v0.2");
        title.setTextSize(23);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        checkButton = new Button(this);
        checkButton.setText("CHECK");
        root.addView(checkButton, paramsTop(12));

        offButton = new Button(this);
        offButton.setText("GPS OFF (TEST)");
        offButton.setEnabled(false);
        root.addView(offButton, paramsTop(8));

        onButton = new Button(this);
        onButton.setText("GPS ON");
        onButton.setEnabled(false);
        root.addView(onButton, paramsTop(8));

        status = new TextView(this);
        status.setTextSize(13);
        status.setTextIsSelectable(true);
        status.setMovementMethod(new ScrollingMovementMethod());
        status.setPadding(0, dp(12), 0, dp(12));

        ScrollView scroll = new ScrollView(this);
        scroll.addView(status, new ScrollView.LayoutParams(-1, -2));
        root.addView(scroll, new LinearLayout.LayoutParams(-1, 0, 1f));

        setContentView(root);

        checkButton.setOnClickListener(v -> runBusy(this::performCheck));
        offButton.setOnClickListener(v -> new AlertDialog.Builder(this)
                .setTitle("GPS OFF — только наземный тест")
                .setMessage("Не запускайте моторы. Лучше снять пропеллеры. После GPS OFF RTH и GPS-удержание недоступны. Продолжить?")
                .setNegativeButton("Отмена", null)
                .setPositiveButton("GPS OFF", (d, w) -> runBusy(() -> performWrite(0)))
                .show());
        onButton.setOnClickListener(v -> runBusy(() -> performWrite(1)));
    }

    private LinearLayout.LayoutParams paramsTop(int marginDp) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = dp(marginDp);
        return p;
    }

    private int dp(int x) {
        return Math.round(x * getResources().getDisplayMetrics().density);
    }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_DEVICE_ATTACHED);
        f.addAction(UsbManager.ACTION_USB_DEVICE_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(usbReceiver, f, Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver, f);
    }

    private final BroadcastReceiver usbReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            if (ACTION_USB_PERMISSION.equals(intent.getAction())) {
                boolean ok = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(ok ? "USB permission: OK. Нажмите CHECK ещё раз." : "USB permission: отказано.");
            } else if (UsbManager.ACTION_USB_DEVICE_ATTACHED.equals(intent.getAction())) {
                append("USB подключен.");
            } else if (UsbManager.ACTION_USB_DEVICE_DETACHED.equals(intent.getAction())) {
                append("USB отключен.");
                clearConfirmation();
            }
        }
    };

    private void runBusy(Runnable task) {
        setBusy(true);
        io.submit(() -> {
            try {
                task.run();
            } catch (Throwable e) {
                append("ОШИБКА: " + e.getClass().getSimpleName() + ": " + e.getMessage());
            } finally {
                setBusy(false);
            }
        });
    }

    private void setBusy(boolean busy) {
        runOnUiThread(() -> {
            checkButton.setEnabled(!busy);
            boolean canWrite = confirmedMode != ParamMode.NONE && confirmedRoute != null && !busy;
            offButton.setEnabled(canWrite);
            onButton.setEnabled(canWrite);
        });
    }

    private void clearConfirmation() {
        confirmedMode = ParamMode.NONE;
        confirmedRoute = null;
        confirmedGpsHash = null;
        confirmedTable = null;
        confirmedIndex = null;
        confirmedParamName = null;
        setBusy(false);
    }

    private void performCheck() {
        runOnUiThread(() -> status.setText(""));
        clearConfirmation();
        append("=== CHECK v0.2 ===");

        UsbSerialPort port = null;
        try {
            port = openRcPort();
            if (port == null) return;

            append("");
            append("[1/3] RC-N1 tunnel probe");
            probeKnownRcInit(port);

            append("");
            append("[2/3] 2015/hash + route scan");
            for (Route route : ROUTES) {
                append("-- " + route.label);
                if (try2015Gps(port, route)) {
                    appendSuccessSummary();
                    return;
                }
            }

            append("");
            append("[3/3] 2017/table fallback");
            for (Route route : ROUTES) {
                append("-- " + route.label);
                if (try2017Gps(port, route)) {
                    appendSuccessSummary();
                    return;
                }
            }

            append("");
            append("РЕЗУЛЬТАТ: USB RC-N1 работает, но gps_enable пока не подтверждён.");
            append("НИЧЕГО В ПАРАМЕТРАХ ДРОНА НЕ ЗАПИСАНО.");
            append("Пришлите весь лог CHECK v0.2 — по ответам/таймаутам сделаю следующий точный шаг.");
        } catch (Exception e) {
            append("CHECK failed: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            closeQuietly(port);
        }
    }

    private void probeKnownRcInit(UsbSerialPort port) {
        try {
            port.write(RC_N1_INIT_PROBE, 1000);
            ByteArrayOutputStream raw = readWindow(port, 650);
            List<DumlV2.Frame> frames = DumlV2.frames(raw);
            if (frames.isEmpty()) {
                append("RC init/version probe: валидных DUML-ответов не поймано (допустимо). bytes=" + raw.size());
            } else {
                append("RC init/version probe: ответов=" + frames.size());
                int shown = 0;
                for (DumlV2.Frame f : frames) {
                    append("  " + f.shortDescription());
                    if (++shown >= 4) break;
                }
            }
        } catch (Exception e) {
            append("RC init probe: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        }
    }

    private boolean try2015Gps(UsbSerialPort port, Route route) throws Exception {
        String[] candidates = {"gps_enable", "g_config.gps_cfg.gps_enable"};
        for (String candidate : candidates) {
            long hash = DumlV2.parameterHash(candidate);
            DumlV2.Frame infoFrame = transact(port, route, DumlV2.CMDSET_FLYC, 0xF7, DumlV2.le32(hash), 700);
            if (infoFrame == null) continue;

            append(String.format(Locale.US, "  F7 response for %s hash=0x%08X seq=%d", candidate, hash, infoFrame.seq));
            DumlV2.ParamInfo2015 info = DumlV2.ParamInfo2015.parse(infoFrame.payload);
            if (info == null) {
                append("  2015 info format unknown: " + DumlV2.hex(infoFrame.payload));
                continue;
            }
            append("  name='" + info.name + "' type=" + info.typeId + " size=" + info.size +
                    " range=" + info.min + ".." + info.max + " status=" + info.status);

            if (!safeGpsIdentity(info.name, info.status, info.typeId, info.size, info.min, info.max)) {
                append("  Не доверяю этому параметру — запись остаётся заблокирована.");
                continue;
            }

            Integer value = readGpsHash(port, route, hash);
            if (value == null || (value != 0 && value != 1)) {
                append("  Имя найдено, но read-back 0/1 не подтверждён.");
                continue;
            }

            confirmedMode = ParamMode.HASH_2015;
            confirmedRoute = route;
            confirmedGpsHash = hash;
            confirmedParamName = info.name;
            append("  2015/hash подтверждён, value=" + value);
            return true;
        }
        return false;
    }

    private boolean try2017Gps(UsbSerialPort port, Route route) throws Exception {
        DumlV2.Frame tableFrame = transact(port, route, DumlV2.CMDSET_FLYC, 0xE0,
                DumlV2.le16(RELATED_MODEL_GPS_TABLE), 700);

        if (tableFrame == null) {
            DumlV2.Frame unlock = transact(port, route, DumlV2.CMDSET_FLYC, 0xDF,
                    new byte[]{1, 0, 0, 0}, 650);
            if (unlock != null) {
                int st = unlock.payload.length > 0 ? (unlock.payload[0] & 0xFF) : -1;
                append("  Assistant read-access response, status=" + st);
            }
            tableFrame = transact(port, route, DumlV2.CMDSET_FLYC, 0xE0,
                    DumlV2.le16(RELATED_MODEL_GPS_TABLE), 800);
        }

        if (tableFrame == null) return false;

        DumlV2.TableAttr2017 attr = DumlV2.TableAttr2017.parse(tableFrame.payload);
        if (attr == null) {
            append("  E0 ответ есть, формат: " + DumlV2.hex(tableFrame.payload));
            return false;
        }
        append("  TABLE " + attr.tableNo + ": status=" + attr.status + " entries=" + attr.entriesNum);
        if (attr.status != 0 || attr.tableNo != RELATED_MODEL_GPS_TABLE || attr.entriesNum <= 0) return false;

        int maxIndex = (int) Math.min(attr.entriesNum - 1, 5000);
        if (RELATED_MODEL_GPS_INDEX <= maxIndex) {
            Probe2017Result direct = probe2017Index(port, route, RELATED_MODEL_GPS_TABLE, RELATED_MODEL_GPS_INDEX);
            if (direct == Probe2017Result.CONFIRMED) return true;
            if (direct == Probe2017Result.NO_RESPONSE) {
                DumlV2.Frame unlock = transact(port, route, DumlV2.CMDSET_FLYC, 0xDF,
                        new byte[]{1, 0, 0, 0}, 650);
                if (unlock != null) append("  Assistant read-access retry: ответ получен.");
                direct = probe2017Index(port, route, RELATED_MODEL_GPS_TABLE, RELATED_MODEL_GPS_INDEX);
                if (direct == Probe2017Result.CONFIRMED) return true;
                if (direct == Probe2017Result.NO_RESPONSE) {
                    append("  E1 index 771 не отвечает — массовый scan на этом route не запускаю.");
                    return false;
                }
            }
        }

        int from = Math.max(0, RELATED_MODEL_GPS_INDEX - 120);
        int to = Math.min(maxIndex, RELATED_MODEL_GPS_INDEX + 120);
        append("  gps index differs; scanning " + from + ".." + to + " (только чтение)");
        int noResponseStreak = 0;
        Set<Integer> already = new HashSet<>();
        already.add(RELATED_MODEL_GPS_INDEX);

        for (int idx = from; idx <= to; idx++) {
            if (already.contains(idx)) continue;
            Probe2017Result r = probe2017IndexQuiet(port, route, RELATED_MODEL_GPS_TABLE, idx);
            if (r == Probe2017Result.CONFIRMED) {
                append("  FOUND at table=0 index=" + idx);
                return true;
            }
            if (r == Probe2017Result.NO_RESPONSE) {
                noResponseStreak++;
                if (noResponseStreak >= 4) {
                    append("  scan остановлен: 4 последовательных E1 timeout.");
                    break;
                }
            } else {
                noResponseStreak = 0;
            }
            if ((idx - from) % 50 == 0) append("  scan progress: index " + idx);
        }
        return false;
    }

    private enum Probe2017Result { CONFIRMED, REPLIED_NOT_GPS, NO_RESPONSE }

    private Probe2017Result probe2017Index(UsbSerialPort port, Route route, int table, int index) throws Exception {
        DumlV2.Frame f = transact(port, route, DumlV2.CMDSET_FLYC, 0xE1,
                DumlV2.concat(DumlV2.le16(table), DumlV2.le16(index)), 750);
        if (f == null) {
            append("  E1 table=" + table + " index=" + index + ": timeout");
            return Probe2017Result.NO_RESPONSE;
        }

        DumlV2.ParamInfo2017 info = DumlV2.ParamInfo2017.parse(f.payload);
        if (info == null) {
            append("  E1 " + table + ":" + index + " unknown payload=" + DumlV2.hex(f.payload));
            return Probe2017Result.REPLIED_NOT_GPS;
        }
        append("  E1 " + table + ":" + index + " -> name='" + info.name + "' type=" + info.typeId +
                " size=" + info.size + " range=" + info.min + ".." + info.max + " status=" + info.status);
        return confirm2017IfGps(port, route, info)
                ? Probe2017Result.CONFIRMED : Probe2017Result.REPLIED_NOT_GPS;
    }

    private Probe2017Result probe2017IndexQuiet(UsbSerialPort port, Route route, int table, int index) throws Exception {
        DumlV2.Frame f = transact(port, route, DumlV2.CMDSET_FLYC, 0xE1,
                DumlV2.concat(DumlV2.le16(table), DumlV2.le16(index)), 520);
        if (f == null) return Probe2017Result.NO_RESPONSE;
        DumlV2.ParamInfo2017 info = DumlV2.ParamInfo2017.parse(f.payload);
        if (info == null) return Probe2017Result.REPLIED_NOT_GPS;

        if (isGpsName(info.name)) {
            append("  candidate " + table + ":" + index + " name='" + info.name + "' type=" +
                    info.typeId + " size=" + info.size + " range=" + info.min + ".." + info.max);
            if (confirm2017IfGps(port, route, info)) return Probe2017Result.CONFIRMED;
        }
        return Probe2017Result.REPLIED_NOT_GPS;
    }

    private boolean confirm2017IfGps(UsbSerialPort port, Route route, DumlV2.ParamInfo2017 info) throws Exception {
        if (!safeGpsIdentity(info.name, info.status, info.typeId, info.size, info.min, info.max)) return false;
        Integer value = readGpsIndex(port, route, info.tableNo, info.paramIndex);
        if (value == null || (value != 0 && value != 1)) {
            append("  gps name/shape OK, но E2 read-back 0/1 не подтверждён.");
            return false;
        }

        confirmedMode = ParamMode.INDEX_2017;
        confirmedRoute = route;
        confirmedTable = info.tableNo;
        confirmedIndex = info.paramIndex;
        confirmedParamName = info.name;
        append("  2017/index подтверждён, value=" + value);
        return true;
    }

    private boolean safeGpsIdentity(String name, int statusCode, int typeId, int size, long min, long max) {
        return statusCode == 0 && isGpsName(name) && size == 1 && min == 0 && max == 1 &&
                (typeId == 0 || typeId == 11);
    }

    private boolean isGpsName(String name) {
        if (name == null) return false;
        String n = name.trim();
        return "gps_enable".equals(n) || "g_config.gps_cfg.gps_enable".equals(n) ||
                "gps_enable|g_config.gps_cfg.gps_enable".equals(n) ||
                "g_config.gps_cfg.gps_enable|gps_enable".equals(n);
    }

    private Integer readGpsHash(UsbSerialPort port, Route route, long hash) throws Exception {
        DumlV2.Frame r = transact(port, route, DumlV2.CMDSET_FLYC, 0xF8, DumlV2.le32(hash), 750);
        if (r == null || r.payload.length < 6) return null;
        int st = r.payload[0] & 0xFF;
        long returnedHash = DumlV2.u32(r.payload, 1);
        if (st != 0 || returnedHash != (hash & 0xFFFFFFFFL)) return null;
        return r.payload[5] & 0xFF;
    }

    private Integer readGpsIndex(UsbSerialPort port, Route route, int table, int index) throws Exception {
        byte[] payload = DumlV2.concat(DumlV2.le16(table), DumlV2.le16(1), DumlV2.le16(index));
        DumlV2.Frame r = transact(port, route, DumlV2.CMDSET_FLYC, 0xE2, payload, 750);
        if (r == null || r.payload.length < 7) return null;
        int st = DumlV2.u16(r.payload, 0);
        int returnedIndex = DumlV2.u16(r.payload, 4);
        if (st != 0 || returnedIndex != index) return null;
        return r.payload[6] & 0xFF;
    }

    private void appendSuccessSummary() {
        append("");
        append("УСПЕХ: gps_enable БЕЗОПАСНО ПОДТВЕРЖДЁН.");
        append("route=" + confirmedRoute);
        append("name='" + confirmedParamName + "'");
        if (confirmedMode == ParamMode.HASH_2015) {
            append(String.format(Locale.US, "protocol=2015/hash 0x%08X", confirmedGpsHash));
        } else {
            append("protocol=2017/index table=" + confirmedTable + " index=" + confirmedIndex);
        }
        append("GPS OFF / GPS ON разблокированы.");
        append("ПОКА НЕ НАЖИМАЙТЕ GPS OFF. Пришлите этот лог — сначала проверим, что всё совпало.");
    }

    private void performWrite(int target) {
        ParamMode mode = confirmedMode;
        Route route = confirmedRoute;
        if (mode == ParamMode.NONE || route == null) {
            append("Сначала нужен успешный CHECK.");
            return;
        }

        append("");
        append("=== GPS " + (target == 0 ? "OFF" : "ON") + " ===");
        UsbSerialPort port = null;
        try {
            port = openRcPort();
            if (port == null) return;

            Integer before;
            if (mode == ParamMode.HASH_2015 && confirmedGpsHash != null) {
                before = readGpsHash(port, route, confirmedGpsHash);
            } else if (mode == ParamMode.INDEX_2017 && confirmedTable != null && confirmedIndex != null) {
                before = readGpsIndex(port, route, confirmedTable, confirmedIndex);
            } else {
                append("Внутреннее состояние CHECK потеряно. Запись отменена.");
                return;
            }

            if (before == null || (before != 0 && before != 1)) {
                append("Запись отменена: текущее значение не подтверждено.");
                return;
            }
            append("До записи: " + confirmedParamName + "=" + before);
            if (before == target) {
                append("Уже установлено значение " + target + ".");
                return;
            }

            DumlV2.Frame wr;
            if (mode == ParamMode.HASH_2015) {
                byte[] payload = DumlV2.concat(DumlV2.le32(confirmedGpsHash), new byte[]{(byte) target});
                wr = transact(port, route, DumlV2.CMDSET_FLYC, 0xF9, payload, 900);
                if (wr == null || wr.payload.length < 1 || (wr.payload[0] & 0xFF) != 0) {
                    append("F9 write не подтверждён. Состояние считаю неизвестным.");
                    return;
                }
            } else {
                byte[] payload = DumlV2.concat(DumlV2.le16(confirmedTable), DumlV2.le16(1),
                        DumlV2.le16(confirmedIndex), new byte[]{(byte) target});
                wr = transact(port, route, DumlV2.CMDSET_FLYC, 0xE3, payload, 900);
                if (wr == null || wr.payload.length < 6 || DumlV2.u16(wr.payload, 0) != 0) {
                    append("E3 write не подтверждён. Состояние считаю неизвестным.");
                    return;
                }
            }

            Integer after = mode == ParamMode.HASH_2015
                    ? readGpsHash(port, route, confirmedGpsHash)
                    : readGpsIndex(port, route, confirmedTable, confirmedIndex);

            if (after != null && after == target) {
                append("УСПЕХ: read-back=" + after + ". GPS " + (target == 0 ? "OFF" : "ON") + " подтверждён.");
                if (target == 0) append("МОТОРЫ НЕ ЗАПУСКАТЬ в первом тесте. После проверки нажмите GPS ON.");
            } else {
                append("НЕ ПОДТВЕРЖДЕНО: read-back=" + after + ". Не запускайте моторы.");
            }
        } catch (Exception e) {
            append("Write failed: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            closeQuietly(port);
        }
    }

    private UsbSerialPort openRcPort() throws Exception {
        UsbDevice target = null;
        for (UsbDevice d : usbManager.getDeviceList().values()) {
            append(String.format(Locale.US, "USB: vendor=%d (0x%04X) product=%d (0x%04X)",
                    d.getVendorId(), d.getVendorId(), d.getProductId(), d.getProductId()));
            if (d.getVendorId() == DJI_VENDOR && d.getProductId() == RC_N1_PID) target = d;
        }

        if (target == null) {
            append("RC-N1 11427:4128 не найден. Дрон и пульт должны быть уже соединены;");
            append("телефон — DATA-кабелем к НИЖНЕМУ USB-C порту RC-N1.");
            return null;
        }

        if (!usbManager.hasPermission(target)) {
            PendingIntent pi = PendingIntent.getBroadcast(this, 0,
                    new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                    PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
            usbManager.requestPermission(target, pi);
            append("Android запросил USB-разрешение. Разрешите и снова нажмите CHECK.");
            return null;
        }

        ProbeTable table = new ProbeTable();
        table.addProduct(DJI_VENDOR, RC_N1_PID, CdcAcmSerialDriver.class);
        UsbSerialDriver driver = new UsbSerialProber(table).probeDevice(target);
        if (driver == null || driver.getPorts().isEmpty()) throw new IllegalStateException("CDC serial driver не найден");

        UsbDeviceConnection connection = usbManager.openDevice(target);
        if (connection == null) throw new IllegalStateException("Не удалось открыть USB device");

        UsbSerialPort port = driver.getPorts().get(0);
        port.open(connection);
        port.setParameters(19200, 8, UsbSerialPort.STOPBITS_1, UsbSerialPort.PARITY_NONE);
        try { port.setDTR(true); } catch (Exception ignored) {}
        try { port.setRTS(true); } catch (Exception ignored) {}
        try { port.purgeHwBuffers(true, true); } catch (Exception ignored) {}
        append("RC-N1 serial: OPEN 19200 8N1");
        return port;
    }

    private DumlV2.Frame transact(UsbSerialPort port, Route route, int cmdSet, int cmdId,
                                  byte[] payload, int timeoutMs) throws Exception {
        int seq = sequence.getAndIncrement() & 0xFFFF;
        byte[] p = DumlV2.packet(route.senderType, route.senderIndex, route.receiverType,
                route.receiverIndex, seq, cmdSet, cmdId, payload);
        port.write(p, 1000);

        long start = System.currentTimeMillis();
        long deadline = start + timeoutMs;
        ByteArrayOutputStream stream = new ByteArrayOutputStream();
        byte[] buf = new byte[2048];

        while (System.currentTimeMillis() < deadline) {
            int n = 0;
            try {
                n = port.read(buf, 120);
            } catch (Exception readError) {
                if (System.currentTimeMillis() >= deadline) break;
            }
            if (n > 0) {
                stream.write(buf, 0, n);
                DumlV2.Frame exact = DumlV2.findFrame(stream, seq, cmdSet, cmdId, false);
                if (exact != null) return exact;
                if (System.currentTimeMillis() - start >= 220) {
                    DumlV2.Frame routed = DumlV2.findFrame(stream, seq, cmdSet, cmdId, true);
                    if (routed != null) return routed;
                }
                if (stream.size() > 32768) {
                    byte[] keep = stream.toByteArray();
                    stream.reset();
                    int from = Math.max(0, keep.length - 8192);
                    stream.write(keep, from, keep.length - from);
                }
            }
        }
        return DumlV2.findFrame(stream, seq, cmdSet, cmdId, true);
    }

    private ByteArrayOutputStream readWindow(UsbSerialPort port, int timeoutMs) {
        ByteArrayOutputStream stream = new ByteArrayOutputStream();
        byte[] buf = new byte[2048];
        long deadline = System.currentTimeMillis() + timeoutMs;
        while (System.currentTimeMillis() < deadline) {
            try {
                int n = port.read(buf, 100);
                if (n > 0) stream.write(buf, 0, n);
            } catch (Exception ignored) {}
        }
        return stream;
    }

    private void closeQuietly(UsbSerialPort port) {
        if (port != null) {
            try { port.close(); } catch (Exception ignored) {}
            append("USB serial: CLOSED");
        }
    }

    private void append(String text) {
        runOnUiThread(() -> {
            status.append(text + "\n");
            View parent = (View) status.getParent();
            if (parent instanceof ScrollView) {
                ((ScrollView) parent).post(() -> ((ScrollView) parent).fullScroll(View.FOCUS_DOWN));
            }
        });
    }
}

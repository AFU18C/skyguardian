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
import java.util.Arrays;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;

public class MainActivity extends Activity {
    private static final int DJI_VENDOR = 11427;
    private static final int RC_N1_PID = 4128;
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.USB_PERMISSION";

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger sequence = new AtomicInteger(0x4200);

    private UsbManager usbManager;
    private TextView status;
    private Button checkButton;
    private Button offButton;
    private Button onButton;

    private volatile Long confirmedGpsHash = null;
    private volatile String confirmedParamName = null;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        usbManager = (UsbManager) getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
        append("Mini 4K GPS Parameter Test v0.1\n" +
                "ПЕРВЫЙ ТЕСТ — только на земле, моторы не запускать.\n\n" +
                "1) Включите Mini 4K и RC-N1 и дождитесь их соединения.\n" +
                "2) Телефон подключите DATA-кабелем к НИЖНЕМУ USB-C порту RC-N1.\n" +
                "3) Нажмите CHECK. CHECK ничего в дроне не меняет.\n\n" +
                "GPS OFF/ON станут активными только если CHECK подтвердит настоящий параметр gps_enable, его размер и диапазон 0..1.");
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
        title.setText("Mini 4K GPS CHECK");
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
        status.setTextSize(14);
        status.setTextIsSelectable(true);
        status.setMovementMethod(new ScrollingMovementMethod());
        status.setPadding(0, dp(12), 0, dp(12));

        ScrollView scroll = new ScrollView(this);
        scroll.addView(status, new ScrollView.LayoutParams(-1, -2));
        LinearLayout.LayoutParams sp = new LinearLayout.LayoutParams(-1, 0, 1f);
        root.addView(scroll, sp);

        setContentView(root);

        checkButton.setOnClickListener(v -> runBusy(this::performCheck));
        offButton.setOnClickListener(v -> new AlertDialog.Builder(this)
                .setTitle("GPS OFF")
                .setMessage("Только для проверки на земле. Не запускайте моторы. GPS OFF отключает использование GPS-позиционирования; RTH и удержание позиции по GPS недоступны. Продолжить?")
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
                append(ok ? "USB permission: OK. Теперь снова нажмите CHECK." : "USB permission: отказано.");
            } else if (UsbManager.ACTION_USB_DEVICE_ATTACHED.equals(intent.getAction())) {
                append("USB подключен. Нажмите CHECK.");
            } else if (UsbManager.ACTION_USB_DEVICE_DETACHED.equals(intent.getAction())) {
                append("USB отключен.");
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
            boolean canWrite = confirmedGpsHash != null && !busy;
            offButton.setEnabled(canWrite);
            onButton.setEnabled(canWrite);
        });
    }

    private void performCheck() {
        append("\n=== CHECK ===");
        confirmedGpsHash = null;
        confirmedParamName = null;
        setBusy(true);

        UsbSerialPort port = null;
        try {
            port = openRcPort();
            if (port == null) return;

            Duml.Frame unlock = transact(port, 0xDF, new byte[]{1, 0, 0, 0}, 1400);
            if (unlock == null) append("AssistantUnlock: ответа нет (продолжаю CHECK).");
            else if (unlock.payload.length > 0) append("AssistantUnlock status=" + (unlock.payload[0] & 0xFF));

            String[] candidates = {"gps_enable", "g_config.gps_cfg.gps_enable"};
            boolean anyResponse = false;
            for (String candidate : candidates) {
                long hash = Duml.parameterHash(candidate);
                append(String.format(Locale.US, "Проверяю %s, hash=0x%08X", candidate, hash));

                Duml.Frame infoFrame = transact(port, 0xF7, Duml.le32(hash), 1600);
                if (infoFrame == null) {
                    append("  GetParamInfo: нет ответа.");
                    continue;
                }
                anyResponse = true;
                Duml.ParamInfo info = Duml.ParamInfo.parse2015(infoFrame.payload);
                if (info == null) {
                    append("  GetParamInfo: ответ есть, но формат не 2015. payload=" + Duml.hex(infoFrame.payload));
                    continue;
                }
                append("  status=" + info.status + " name='" + info.name + "' type=" + info.typeId +
                        " size=" + info.size + " range=" + info.min + ".." + info.max +
                        " attr=0x" + Integer.toHexString(info.attribute));

                boolean nameOk = "gps_enable".equals(info.name) || "g_config.gps_cfg.gps_enable".equals(info.name);
                boolean shapeOk = info.status == 0 && info.size == 1 && info.min == 0 && info.max == 1 &&
                        (info.typeId == 0 || info.typeId == 11);
                if (!nameOk || !shapeOk) {
                    append("  Не разрешаю запись: идентичность/тип параметра не подтверждены.");
                    continue;
                }

                Integer value = readGpsValue(port, hash);
                if (value == null) {
                    append("  Параметр найден, но текущее значение прочитать не удалось.");
                    continue;
                }
                if (value != 0 && value != 1) {
                    append("  Неожиданное значение=" + value + ". Запись заблокирована.");
                    continue;
                }

                confirmedGpsHash = hash;
                confirmedParamName = info.name;
                append("\nУСПЕХ: gps_enable подтверждён. Текущее значение=" + value +
                        ".\nGPS OFF / GPS ON разблокированы.");
                return;
            }

            if (!anyResponse) {
                append("\nРЕЗУЛЬТАТ: RC-N1 отвечает как USB, но FLYC GetParamInfo по hash не ответил. Ничего не изменено. Пришлите этот экран — сделаю fallback под 2017/table protocol.");
            } else {
                append("\nРЕЗУЛЬТАТ: ответы от контроллера получены, но gps_enable безопасно не подтверждён. Ничего не изменено. Пришлите весь лог с экрана.");
            }
        } catch (Exception e) {
            append("CHECK failed: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            closeQuietly(port);
        }
    }

    private void performWrite(int target) {
        Long hash = confirmedGpsHash;
        if (hash == null) {
            append("Сначала нужен успешный CHECK.");
            return;
        }
        append("\n=== GPS " + (target == 0 ? "OFF" : "ON") + " ===");
        UsbSerialPort port = null;
        try {
            port = openRcPort();
            if (port == null) return;
            transact(port, 0xDF, new byte[]{1, 0, 0, 0}, 1200);

            Integer before = readGpsValue(port, hash);
            if (before == null || (before != 0 && before != 1)) {
                append("Запись отменена: перед записью текущее значение не подтверждено.");
                return;
            }
            append("До записи: " + confirmedParamName + "=" + before);
            if (before == target) {
                append("Уже установлено нужное значение " + target + ".");
                return;
            }

            byte[] payload = Arrays.copyOf(Duml.le32(hash), 5);
            payload[4] = (byte) target;
            Duml.Frame wr = transact(port, 0xF9, payload, 1600);
            if (wr == null) {
                append("WriteParam: ответа нет. Считаю запись НЕподтверждённой.");
                return;
            }
            if (wr.payload.length < 1 || (wr.payload[0] & 0xFF) != 0) {
                append("WriteParam отклонён. payload=" + Duml.hex(wr.payload));
                return;
            }

            Integer after = readGpsValue(port, hash);
            if (after != null && after == target) {
                append("УСПЕХ: read-back=" + after + ". GPS " + (target == 0 ? "OFF" : "ON") + " подтверждён контроллером.");
                if (target == 0) append("Для первого теста моторы НЕ запускайте. После проверки верните GPS ON.");
            } else {
                append("НЕ ПОДТВЕРЖДЕНО: read-back=" + after + ". Состояние неизвестно — попробуйте GPS ON.");
            }
        } catch (Exception e) {
            append("Write failed: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            closeQuietly(port);
        }
    }

    private Integer readGpsValue(UsbSerialPort port, long hash) throws Exception {
        Duml.Frame r = transact(port, 0xF8, Duml.le32(hash), 1500);
        if (r == null) return null;
        if (r.payload.length < 6) {
            append("  ReadParam слишком короткий: " + Duml.hex(r.payload));
            return null;
        }
        int statusCode = r.payload[0] & 0xFF;
        long returnedHash = Duml.u32(r.payload, 1);
        if (statusCode != 0 || returnedHash != (hash & 0xFFFFFFFFL)) {
            append(String.format(Locale.US, "  ReadParam status=%d hash=0x%08X", statusCode, returnedHash));
            return null;
        }
        int value = r.payload[5] & 0xFF;
        append("  ReadParam value=" + value);
        return value;
    }

    private UsbSerialPort openRcPort() throws Exception {
        UsbDevice target = null;
        for (UsbDevice d : usbManager.getDeviceList().values()) {
            append(String.format(Locale.US, "USB: vendor=%d (0x%04X) product=%d (0x%04X)",
                    d.getVendorId(), d.getVendorId(), d.getProductId(), d.getProductId()));
            if (d.getVendorId() == DJI_VENDOR && d.getProductId() == RC_N1_PID) target = d;
        }

        if (target == null) {
            append("RC-N1 не найден как 11427:4128. Убедитесь: дрон+пульт уже соединены, телефон подключён к НИЖНЕМУ USB-C порту пульта DATA-кабелем.");
            return null;
        }

        if (!usbManager.hasPermission(target)) {
            PendingIntent pi = PendingIntent.getBroadcast(this, 0,
                    new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                    PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
            usbManager.requestPermission(target, pi);
            append("Android запросил USB-разрешение. Разрешите и затем снова нажмите CHECK.");
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
        append("RC-N1 serial: OPEN 19200 8N1");
        return port;
    }

    private Duml.Frame transact(UsbSerialPort port, int cmdId, byte[] payload, int timeoutMs) throws Exception {
        int seq = sequence.getAndIncrement() & 0xFFFF;
        byte[] p = Duml.packet(seq, cmdId, payload);
        port.write(p, 1000);

        long deadline = System.currentTimeMillis() + timeoutMs;
        ByteArrayOutputStream stream = new ByteArrayOutputStream();
        byte[] buf = new byte[1024];
        while (System.currentTimeMillis() < deadline) {
            int n;
            try {
                n = port.read(buf, 180);
            } catch (Exception timeoutOrIo) {
                if (System.currentTimeMillis() >= deadline) throw timeoutOrIo;
                continue;
            }
            if (n > 0) {
                stream.write(buf, 0, n);
                Duml.Frame frame = Duml.findFrame(stream, seq, cmdId);
                if (frame != null) return frame;
                if (stream.size() > 8192) stream.reset();
            }
        }
        return null;
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
            if (parent instanceof ScrollView) ((ScrollView) parent).post(() -> ((ScrollView) parent).fullScroll(View.FOCUS_DOWN));
        });
    }
}

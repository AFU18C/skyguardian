package com.openai.mini4kgps;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.hardware.usb.UsbAccessory;
import android.hardware.usb.UsbDevice;
import android.hardware.usb.UsbInterface;
import android.hardware.usb.UsbManager;
import android.os.Build;
import android.os.Bundle;
import android.os.ParcelFileDescriptor;
import android.text.method.ScrollingMovementMethod;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;

public class V1Activity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.ACCESSORY_PERMISSION";
    private static final int RC_N1_VID = 0x2CA3;
    private static final int RC_N1_PID = 0x1020;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger sequence = new AtomicInteger(0x7100);

    private UsbManager usbManager;
    private TextView status;
    private Button checkButton;
    private Button offButton;
    private Button onButton;
    private volatile boolean pendingAutoCheck;

    private enum ConfirmMode { NONE, INDEX_2017, HASH_2015 }
    private volatile ConfirmMode confirmedMode = ConfirmMode.NONE;
    private volatile Integer confirmedTable;
    private volatile Integer confirmedIndex;
    private volatile Long confirmedHash;
    private volatile String confirmedName;
    private volatile Integer confirmedValue;

    private static final class Route {
        final int senderType, senderIndex, receiverType, receiverIndex;
        final String label;
        Route(int st, int si, int rt, int ri, String label) {
            senderType = st; senderIndex = si; receiverType = rt; receiverIndex = ri; this.label = label;
        }
    }

    private static final Route APP_TO_FLYC = new Route(
            DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0, "APP0 -> FLYC");

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

    private void buildUi() {
        int pad = dp(16);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("Mini 4K GPS TOOL v1.0");
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        checkButton = new Button(this);
        checkButton.setText("FULL CHECK");
        root.addView(checkButton, paramsTop(12));

        offButton = new Button(this);
        offButton.setText("GPS OFF");
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

        checkButton.setOnClickListener(v -> runBusy(this::performFullCheck));
        offButton.setOnClickListener(v -> new AlertDialog.Builder(this)
                .setTitle("GPS OFF")
                .setMessage("Наземный тест: моторы не запускайте. После GPS OFF не будет GPS-удержания и RTH по GNSS. Записать 0 только в подтверждённый gps_enable?")
                .setNegativeButton("Отмена", null)
                .setPositiveButton("GPS OFF", (d, w) -> runBusy(() -> performWrite(0)))
                .show());
        onButton.setOnClickListener(v -> runBusy(() -> performWrite(1)));
    }

    private void showIntro() {
        status.setText("");
        append("Mini 4K GPS TOOL v1.0");
        append("FULL CHECK — только чтение. Он сам ищет точный gps_enable и ничего не записывает.");
        append("");
        append("ПОДКЛЮЧЕНИЕ ДЛЯ ЭТОЙ ВЕРСИИ:");
        append("1) Включите Mini 4K и RC-N1, дождитесь связи дрон ↔ пульт.");
        append("2) Полностью закройте DJI Fly.");
        append("3) Телефон подключите к ВЕРХНЕМУ разъёму RC-N1 — тому, которым телефон обычно подключается к DJI Fly.");
        append("4) Нажмите FULL CHECK. Если Android спросит USB-доступ — разрешите; тест продолжится автоматически.");
        append("");
        append("GPS OFF/ON физически заблокированы, пока FC не вернёт имя gps_enable, размер 1, диапазон 0..1 и текущее значение 0/1.");
    }

    private LinearLayout.LayoutParams paramsTop(int m) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = dp(m);
        return p;
    }

    private int dp(int x) {
        return Math.round(x * getResources().getDisplayMetrics().density);
    }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_ATTACHED);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        f.addAction(UsbManager.ACTION_USB_DEVICE_ATTACHED);
        f.addAction(UsbManager.ACTION_USB_DEVICE_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(usbReceiver, f, Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver, f);
    }

    private final BroadcastReceiver usbReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            String action = intent.getAction();
            if (ACTION_USB_PERMISSION.equals(action)) {
                boolean granted = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(granted ? "USB Accessory permission: OK." : "USB Accessory permission: ОТКАЗАНО.");
                if (granted && pendingAutoCheck) {
                    pendingAutoCheck = false;
                    runBusy(V1Activity.this::performFullCheck);
                }
            } else if (UsbManager.ACTION_USB_ACCESSORY_ATTACHED.equals(action)) {
                append("AOA accessory подключён.");
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(action)) {
                append("AOA accessory отключён.");
                clearConfirmation();
            }
        }
    };

    private void runBusy(Runnable r) {
        runOnUiThread(() -> checkButton.setEnabled(false));
        io.submit(() -> {
            try { r.run(); }
            catch (Throwable t) { append("ОШИБКА: " + t.getClass().getSimpleName() + ": " + t.getMessage()); }
            finally {
                runOnUiThread(() -> {
                    checkButton.setEnabled(true);
                    boolean ok = confirmedMode != ConfirmMode.NONE;
                    offButton.setEnabled(ok);
                    onButton.setEnabled(ok);
                });
            }
        });
    }

    private void clearConfirmation() {
        confirmedMode = ConfirmMode.NONE;
        confirmedTable = null;
        confirmedIndex = null;
        confirmedHash = null;
        confirmedName = null;
        confirmedValue = null;
        runOnUiThread(() -> {
            offButton.setEnabled(false);
            onButton.setEnabled(false);
        });
    }

    private void performFullCheck() {
        runOnUiThread(() -> status.setText(""));
        clearConfirmation();
        append("=== FULL CHECK v1.0 ===");
        append("DUML CRC/SIMPLE self-test: " + (DumlV2.selfTest() ? "PASS" : "FAIL"));
        if (!DumlV2.selfTest()) {
            append("Внутренний crypto test не прошёл. Никаких команд не отправляю.");
            return;
        }

        UsbAccessory accessory = chooseAccessory();
        if (accessory == null) {
            appendNoAccessoryDiagnosis();
            return;
        }

        append("AOA: manufacturer='" + safe(accessory.getManufacturer()) + "' model='" + safe(accessory.getModel()) + "' version='" + safe(accessory.getVersion()) + "'");
        if (!usbManager.hasPermission(accessory)) {
            pendingAutoCheck = true;
            PendingIntent pi = PendingIntent.getBroadcast(this, 0,
                    new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                    PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
            usbManager.requestPermission(accessory, pi);
            append("Запросил разрешение Android на USB Accessory. После разрешения FULL CHECK продолжится сам.");
            return;
        }

        AoaLink link = null;
        try {
            link = AoaLink.open(usbManager, accessory);
            if (link == null) {
                append("Не удалось открыть AOA pipe. Закройте DJI Fly и повторите FULL CHECK.");
                return;
            }
            sleep(350);
            append("AOA pipe: OPEN; rx DUML frames=" + link.getDumlFrames() + " other units=" + link.getOtherUnits());

            append("");
            append("[1/4] Проверка канала до Flight Controller");
            DumlV2.Frame ver = transact(link, APP_TO_FLYC, DumlV2.CMDSET_GENERAL, 0x01, new byte[0], false, 1300);
            if (ver != null) {
                append("FC ответил на COMMON/Version: seq=" + ver.seq + " payload=" + compactHex(ver.payload, 48));
                String hw = parseHwVersion(ver.payload);
                if (!hw.isEmpty()) append("FC hw/version string: '" + hw + "'");
            } else {
                append("COMMON/Version ACK не пойман. Продолжаю: param path может работать отдельно.");
            }

            append("");
            append("[2/4] Assistant/config unlock");
            DumlV2.Frame unlock = transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xDF, DumlV2.le32(1), true, 1300);
            if (unlock != null) {
                int st = unlock.payload.length > 0 ? (unlock.payload[0] & 0xFF) : -1;
                append("Unlock response status=" + st + " (encrypted FLYC path отвечает)");
            } else {
                append("Unlock ACK не пойман. Для чтения некоторых FC unlock не обязателен — продолжаю.");
            }

            append("");
            append("[3/4] 2017 table/index — полный поиск имени gps_enable");
            if (findGps2017(link)) {
                finishSuccess(link);
                return;
            }

            append("");
            append("[4/4] 2015/hash fallback");
            if (findGps2015(link)) {
                finishSuccess(link);
                return;
            }

            append("");
            append("РЕЗУЛЬТАТ: AOA транспорт открыт, но gps_enable не подтверждён ни table/index, ни hash способом.");
            append("НИЧЕГО В ДРОН НЕ ЗАПИСАНО.");
            append("rx DUML frames=" + link.getDumlFrames() + ", DUML units=" + link.getDumlUnits() + ", other units=" + link.getOtherUnits());
        } catch (Exception e) {
            append("FULL CHECK failed: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            if (link != null) link.close();
            append("AOA pipe: CLOSED");
        }
    }

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] list = usbManager.getAccessoryList();
        if (list == null || list.length == 0) return null;
        for (UsbAccessory a : list) {
            if ("DJI".equalsIgnoreCase(a.getManufacturer())) return a;
        }
        return list[0];
    }

    private void appendNoAccessoryDiagnosis() {
        append("AOA accessory не найден.");
        boolean rcBottom = false;
        for (UsbDevice d : usbManager.getDeviceList().values()) {
            append(String.format(Locale.US, "USB host device: VID=%04X PID=%04X interfaces=%d", d.getVendorId(), d.getProductId(), d.getInterfaceCount()));
            for (int i = 0; i < d.getInterfaceCount(); i++) {
                UsbInterface in = d.getInterface(i);
                append(String.format(Locale.US, "  IF%d class=0x%02X sub=0x%02X proto=0x%02X eps=%d", i, in.getInterfaceClass(), in.getInterfaceSubclass(), in.getInterfaceProtocol(), in.getEndpointCount()));
            }
            if (d.getVendorId() == RC_N1_VID && d.getProductId() == RC_N1_PID) rcBottom = true;
        }
        append("");
        if (rcBottom) {
            append("Сейчас Android видит сервисный USB RC-N1 2CA3:1020. Это не основной AOA-канал DJI Fly.");
            append("Переставьте телефон в ВЕРХНИЙ разъём RC-N1 (обычный телефонный кабель DJI Fly), полностью закройте DJI Fly и нажмите FULL CHECK.");
        } else {
            append("Подключите телефон к ВЕРХНЕМУ телефонному разъёму RC-N1, а не напрямую к дрону и не к нижнему сервисному USB пульта.");
        }
    }

    private boolean findGps2017(AoaLink link) throws Exception {
        List<DumlV2.TableAttr2017> tables = new ArrayList<>();
        int misses = 0;
        for (int tableNo = 0; tableNo < 32; tableNo++) {
            DumlV2.Frame f = transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xE0, DumlV2.le16(tableNo), true, 800);
            if (f == null) {
                misses++;
                if (tableNo == 0) {
                    append("E0 table 0: timeout");
                    break;
                }
                if (misses >= 2) break;
                continue;
            }
            misses = 0;
            if (f.payload.length <= 2) {
                int st = f.payload.length >= 2 ? DumlV2.u16(f.payload, 0) : -1;
                append("E0 table " + tableNo + ": end/status=" + st);
                break;
            }
            DumlV2.TableAttr2017 a = DumlV2.TableAttr2017.parse(f.payload);
            if (a == null || a.status != 0 || a.entriesNum <= 0 || a.entriesNum > 20000) {
                append("E0 table " + tableNo + ": unexpected payload=" + compactHex(f.payload, 48));
                break;
            }
            tables.add(a);
            append("TABLE " + a.tableNo + ": entries=" + a.entriesNum + " crc=0x" + Long.toHexString(a.entriesCrc));
        }

        if (tables.isEmpty()) {
            append("2017: таблицы параметров не открылись.");
            return false;
        }

        Set<Long> probed = new HashSet<>();
        // Fast priority window from the WM160/Mini family. We never trust the index itself;
        // only the returned exact name+shape can unlock writes.
        for (DumlV2.TableAttr2017 a : tables) {
            if (a.tableNo != 0) continue;
            int from = 340;
            int to = (int) Math.min(a.entriesNum - 1, 470);
            if (from <= to) {
                append("Priority scan table 0: " + from + ".." + to);
                for (int idx = from; idx <= to; idx++) {
                    probed.add(key(a.tableNo, idx));
                    if (probe2017Info(link, a.tableNo, idx)) return true;
                }
            }
        }

        for (DumlV2.TableAttr2017 a : tables) {
            int total = (int) Math.min(a.entriesNum, 10000);
            append("Full scan table " + a.tableNo + ": 0.." + (total - 1));
            int noReplyStreak = 0;
            for (int idx = 0; idx < total; idx++) {
                if (probed.contains(key(a.tableNo, idx))) continue;
                ProbeResult pr = probe2017InfoDetailed(link, a.tableNo, idx);
                if (pr == ProbeResult.FOUND) return true;
                if (pr == ProbeResult.NO_REPLY) {
                    noReplyStreak++;
                    if (noReplyStreak >= 20) {
                        append("Scan остановлен: 20 подряд E1 timeout на table=" + a.tableNo + ".");
                        break;
                    }
                } else {
                    noReplyStreak = 0;
                }
                if (idx > 0 && idx % 100 == 0) append("  progress table " + a.tableNo + ": " + idx + "/" + total);
            }
        }
        return false;
    }

    private enum ProbeResult { FOUND, REPLIED, NO_REPLY }

    private boolean probe2017Info(AoaLink link, int table, int index) throws Exception {
        return probe2017InfoDetailed(link, table, index) == ProbeResult.FOUND;
    }

    private ProbeResult probe2017InfoDetailed(AoaLink link, int table, int index) throws Exception {
        byte[] rq = DumlV2.concat(DumlV2.le16(table), DumlV2.le16(index));
        DumlV2.Frame f = transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xE1, rq, true, 380);
        if (f == null) return ProbeResult.NO_REPLY;
        DumlV2.ParamInfo2017 info = DumlV2.ParamInfo2017.parse(f.payload);
        if (info == null) return ProbeResult.REPLIED;
        if (!isGpsName(info.name)) return ProbeResult.REPLIED;

        append("GPS candidate: table=" + info.tableNo + " index=" + info.paramIndex + " name='" + info.name + "' type=" + info.typeId + " size=" + info.size + " range=" + info.min + ".." + info.max + " status=" + info.status);
        if (!safeGpsShape(info.name, info.status, info.typeId, info.size, info.min, info.max)) {
            append("Кандидат отвергнут: имя похоже, но тип/размер/диапазон не совпадают безопасно.");
            return ProbeResult.REPLIED;
        }
        Integer v = readIndexValue(link, info.tableNo, info.paramIndex);
        if (v == null || (v != 0 && v != 1)) {
            append("Кандидат отвергнут: E2 read-back не дал 0/1.");
            return ProbeResult.REPLIED;
        }
        confirmedMode = ConfirmMode.INDEX_2017;
        confirmedTable = info.tableNo;
        confirmedIndex = info.paramIndex;
        confirmedName = info.name;
        confirmedValue = v;
        return ProbeResult.FOUND;
    }

    private boolean findGps2015(AoaLink link) throws Exception {
        String[] names = new String[]{
                "gps_enable",
                "g_config.gps_cfg.gps_enable",
                "gps_enable|g_config.gps_cfg.gps_enable"
        };
        for (String name : names) {
            long hash = DumlV2.parameterHash(name);
            DumlV2.Frame f = transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xF7, DumlV2.le32(hash), true, 850);
            if (f == null) continue;
            DumlV2.ParamInfo2015 info = DumlV2.ParamInfo2015.parse(f.payload);
            if (info == null) {
                append("F7 " + name + ": ответ, но формат неизвестен " + compactHex(f.payload, 48));
                continue;
            }
            append("F7 candidate name='" + info.name + "' type=" + info.typeId + " size=" + info.size + " range=" + info.min + ".." + info.max + " status=" + info.status);
            if (!safeGpsShape(info.name, info.status, info.typeId, info.size, info.min, info.max)) continue;
            Integer v = readHashValue(link, hash);
            if (v == null || (v != 0 && v != 1)) continue;
            confirmedMode = ConfirmMode.HASH_2015;
            confirmedHash = hash;
            confirmedName = info.name;
            confirmedValue = v;
            return true;
        }
        return false;
    }

    private void finishSuccess(AoaLink link) {
        append("");
        append("=== УСПЕХ ===");
        append("Подтверждён настоящий параметр: '" + confirmedName + "'");
        append("Текущее значение: " + confirmedValue + " (1=GPS ON, 0=GPS OFF)");
        if (confirmedMode == ConfirmMode.INDEX_2017) {
            append("Protocol: 2017 table=" + confirmedTable + " index=" + confirmedIndex);
        } else {
            append(String.format(Locale.US, "Protocol: 2015 hash=0x%08X", confirmedHash));
        }
        append("GPS OFF / GPS ON разблокированы.");
        append("FULL CHECK ничего не записал. rx DUML frames=" + link.getDumlFrames() + ".");
    }

    private void performWrite(int target) {
        ConfirmMode mode = confirmedMode;
        if (mode == ConfirmMode.NONE) {
            append("Сначала нужен успешный FULL CHECK.");
            return;
        }
        UsbAccessory accessory = chooseAccessory();
        if (accessory == null || !usbManager.hasPermission(accessory)) {
            append("AOA connection/permission потеряно. Снова подключите верхний порт и нажмите FULL CHECK.");
            return;
        }

        append("");
        append("=== GPS " + (target == 0 ? "OFF" : "ON") + " ===");
        AoaLink link = null;
        try {
            link = AoaLink.open(usbManager, accessory);
            if (link == null) {
                append("Не удалось открыть AOA pipe.");
                return;
            }
            sleep(250);
            transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xDF, DumlV2.le32(1), true, 900);

            Integer before;
            if (mode == ConfirmMode.INDEX_2017 && confirmedTable != null && confirmedIndex != null) {
                before = readIndexValue(link, confirmedTable, confirmedIndex);
            } else if (mode == ConfirmMode.HASH_2015 && confirmedHash != null) {
                before = readHashValue(link, confirmedHash);
            } else {
                append("Подтверждённое состояние потеряно. Запись отменена.");
                return;
            }
            if (before == null || (before != 0 && before != 1)) {
                append("Запись отменена: перед записью read-back не подтвердил 0/1.");
                return;
            }
            append("До записи: " + confirmedName + "=" + before);
            if (before == target) {
                append("Уже установлено значение " + target + ". Ничего не менял.");
                confirmedValue = target;
                return;
            }

            boolean ackOk = false;
            if (mode == ConfirmMode.INDEX_2017) {
                byte[] rq = DumlV2.concat(DumlV2.le16(confirmedTable), DumlV2.le16(1), DumlV2.le16(confirmedIndex), new byte[]{(byte) target});
                DumlV2.Frame wr = transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xE3, rq, true, 1200);
                if (wr != null && wr.payload.length >= 2 && DumlV2.u16(wr.payload, 0) == 0) ackOk = true;
            } else {
                byte[] rq = DumlV2.concat(DumlV2.le32(confirmedHash), new byte[]{(byte) target});
                DumlV2.Frame wr = transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xF9, rq, true, 1200);
                if (wr != null && wr.payload.length >= 1 && (wr.payload[0] & 0xFF) == 0) ackOk = true;
            }
            append("Write ACK: " + (ackOk ? "OK" : "не подтверждён"));
            sleep(180);

            Integer after = mode == ConfirmMode.INDEX_2017
                    ? readIndexValue(link, confirmedTable, confirmedIndex)
                    : readHashValue(link, confirmedHash);
            if (after != null && after == target) {
                confirmedValue = after;
                append("УСПЕХ: обязательный read-back=" + after + ". GPS " + (target == 0 ? "OFF" : "ON") + " подтверждён контроллером.");
                if (target == 0) append("Первый тест — только на земле, моторы не запускать. Для возврата нажмите GPS ON.");
            } else {
                append("НЕ ПОДТВЕРЖДЕНО: read-back=" + after + ". Состояние считаю неизвестным; моторы не запускать.");
            }
        } catch (Exception e) {
            append("Write failed: " + e.getClass().getSimpleName() + ": " + e.getMessage());
        } finally {
            if (link != null) link.close();
            append("AOA pipe: CLOSED");
        }
    }

    private Integer readIndexValue(AoaLink link, int table, int index) throws Exception {
        byte[] rq = DumlV2.concat(DumlV2.le16(table), DumlV2.le16(1), DumlV2.le16(index));
        DumlV2.Frame r = transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xE2, rq, true, 850);
        if (r == null || r.payload.length < 7) return null;
        int st = DumlV2.u16(r.payload, 0);
        int returnedIndex = DumlV2.u16(r.payload, 4);
        if (st != 0 || returnedIndex != index) return null;
        return r.payload[6] & 0xFF;
    }

    private Integer readHashValue(AoaLink link, long hash) throws Exception {
        DumlV2.Frame r = transact(link, APP_TO_FLYC, DumlV2.CMDSET_FLYC, 0xF8, DumlV2.le32(hash), true, 850);
        if (r == null || r.payload.length < 6) return null;
        int st = r.payload[0] & 0xFF;
        long returnedHash = DumlV2.u32(r.payload, 1);
        if (st != 0 || returnedHash != (hash & 0xFFFFFFFFL)) return null;
        return r.payload[5] & 0xFF;
    }

    private DumlV2.Frame transact(AoaLink link, Route route, int cmdSet, int cmdId,
                                  byte[] payload, boolean encryptFlyc, int timeoutMs) throws Exception {
        int seq = sequence.getAndIncrement() & 0xFFFF;
        byte[] p = DumlV2.packet(route.senderType, route.senderIndex, route.receiverType,
                route.receiverIndex, seq, cmdSet, cmdId, payload, encryptFlyc);
        link.clearQueue();
        link.sendDuml(p);

        long deadline = System.currentTimeMillis() + timeoutMs;
        DumlV2.Frame fallback = null;
        while (System.currentTimeMillis() < deadline) {
            long left = Math.max(1, deadline - System.currentTimeMillis());
            DumlV2.Frame f = link.poll(Math.min(80, left));
            if (f == null) continue;
            if (!f.response || f.cmdSet != cmdSet || f.cmdId != cmdId) continue;
            boolean direction = f.senderType == route.receiverType && f.receiverType == route.senderType;
            if (f.seq == seq && direction) return f;
            if (f.seq == seq) return f;
            if (direction && fallback == null) fallback = f;
        }
        return fallback;
    }

    private boolean safeGpsShape(String name, int statusCode, int typeId, int size, long min, long max) {
        return statusCode == 0 && isGpsName(name) && size == 1 && min == 0 && max == 1 && (typeId == 0 || typeId == 11);
    }

    private boolean isGpsName(String name) {
        if (name == null) return false;
        for (String part : name.trim().split("\\|")) {
            String p = part.trim();
            if ("gps_enable".equals(p) || "g_config.gps_cfg.gps_enable".equals(p)) return true;
        }
        return false;
    }

    private static long key(int table, int index) {
        return (((long) table) << 32) | (index & 0xFFFFFFFFL);
    }

    private static String safe(String s) { return s == null ? "" : s; }

    private static void sleep(long ms) {
        try { Thread.sleep(ms); } catch (InterruptedException e) { Thread.currentThread().interrupt(); }
    }

    private String parseHwVersion(byte[] p) {
        if (p == null || p.length < 18) return "";
        int start = 2;
        int end = Math.min(p.length, start + 16);
        while (end > start && p[end - 1] == 0) end--;
        StringBuilder s = new StringBuilder();
        for (int i = start; i < end; i++) {
            int b = p[i] & 0xFF;
            if (b >= 32 && b <= 126) s.append((char) b);
        }
        return s.toString().trim();
    }

    private String compactHex(byte[] b, int max) {
        if (b == null) return "";
        int n = Math.min(b.length, max);
        byte[] c = new byte[n];
        System.arraycopy(b, 0, c, 0, n);
        return DumlV2.hex(c) + (b.length > max ? " ..." : "");
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

    // Android Open Accessory link. RC is USB host; phone is the accessory side.
    // Wire format above the AOA pipe: 55 CC | type(u16 LE) | len(u32 LE) | payload.
    // DUML travels on type 0x5749 (and incoming aux 0x7530 is accepted too).
    private static final class AoaLink {
        private final ParcelFileDescriptor pfd;
        private final FileInputStream in;
        private final FileOutputStream out;
        private final LinkedBlockingQueue<DumlV2.Frame> queue = new LinkedBlockingQueue<>(3000);
        private volatile boolean running = true;
        private final Thread reader;

        private int headerPos = 0;
        private final byte[] header = new byte[8];
        private long remaining = 0;
        private int currentType = -1;
        private java.io.ByteArrayOutputStream currentPayload;
        private volatile long dumlUnits = 0;
        private volatile long dumlFrames = 0;
        private volatile long otherUnits = 0;

        static AoaLink open(UsbManager manager, UsbAccessory accessory) {
            try {
                ParcelFileDescriptor pfd = manager.openAccessory(accessory);
                if (pfd == null) return null;
                return new AoaLink(pfd);
            } catch (Exception e) {
                return null;
            }
        }

        private AoaLink(ParcelFileDescriptor pfd) {
            this.pfd = pfd;
            this.in = new FileInputStream(pfd.getFileDescriptor());
            this.out = new FileOutputStream(pfd.getFileDescriptor());
            this.reader = new Thread(this::readLoop, "mini4k-aoa-reader");
            this.reader.setDaemon(true);
            this.reader.start();
        }

        long getDumlUnits() { return dumlUnits; }
        long getDumlFrames() { return dumlFrames; }
        long getOtherUnits() { return otherUnits; }

        void clearQueue() { queue.clear(); }

        DumlV2.Frame poll(long ms) throws InterruptedException {
            return queue.poll(ms, TimeUnit.MILLISECONDS);
        }

        synchronized void sendDuml(byte[] duml) throws IOException {
            int len = duml.length;
            byte[] frame = new byte[8 + len];
            frame[0] = 0x55;
            frame[1] = (byte) 0xCC;
            frame[2] = 0x49;
            frame[3] = 0x57; // 0x5749 LE
            frame[4] = (byte) (len & 0xFF);
            frame[5] = (byte) ((len >>> 8) & 0xFF);
            frame[6] = (byte) ((len >>> 16) & 0xFF);
            frame[7] = (byte) ((len >>> 24) & 0xFF);
            System.arraycopy(duml, 0, frame, 8, len);
            out.write(frame);
            out.flush();
        }

        private void readLoop() {
            byte[] buf = new byte[16384];
            try {
                while (running) {
                    int n = in.read(buf);
                    if (n < 0) break;
                    if (n == 0) continue;
                    feedComposite(buf, n);
                }
            } catch (Exception ignored) {
            } finally {
                running = false;
            }
        }

        private void feedComposite(byte[] data, int n) {
            for (int i = 0; i < n; i++) {
                int b = data[i] & 0xFF;
                if (remaining > 0) {
                    if (currentPayload != null) currentPayload.write(b);
                    remaining--;
                    if (remaining == 0) finishUnit();
                    continue;
                }

                if (headerPos == 0) {
                    if (b == 0x55) {
                        header[0] = 0x55;
                        headerPos = 1;
                    }
                    continue;
                }
                if (headerPos == 1) {
                    if (b == 0xCC) {
                        header[1] = (byte) 0xCC;
                        headerPos = 2;
                    } else if (b == 0x55) {
                        header[0] = 0x55;
                        headerPos = 1;
                    } else {
                        headerPos = 0;
                    }
                    continue;
                }

                header[headerPos++] = (byte) b;
                if (headerPos == 8) {
                    currentType = (header[2] & 0xFF) | ((header[3] & 0xFF) << 8);
                    long len = ((long) header[4] & 0xFF) |
                            (((long) header[5] & 0xFF) << 8) |
                            (((long) header[6] & 0xFF) << 16) |
                            (((long) header[7] & 0xFF) << 24);
                    headerPos = 0;
                    if (len < 0 || len > 0x200000L) {
                        currentType = -1;
                        remaining = 0;
                        currentPayload = null;
                        continue;
                    }
                    remaining = len;
                    if (currentType == 0x5749 || currentType == 0x7530) {
                        currentPayload = new java.io.ByteArrayOutputStream((int) Math.min(len, 8192));
                    } else {
                        currentPayload = null;
                    }
                    if (remaining == 0) finishUnit();
                }
            }
        }

        private void finishUnit() {
            if (currentType == 0x5749 || currentType == 0x7530) {
                dumlUnits++;
                if (currentPayload != null) {
                    List<DumlV2.Frame> frames = DumlV2.frames(currentPayload.toByteArray());
                    for (DumlV2.Frame f : frames) {
                        dumlFrames++;
                        if (!queue.offer(f)) {
                            queue.poll();
                            queue.offer(f);
                        }
                    }
                }
            } else {
                otherUnits++;
            }
            currentType = -1;
            currentPayload = null;
            remaining = 0;
        }

        void close() {
            running = false;
            try { in.close(); } catch (Exception ignored) {}
            try { out.close(); } catch (Exception ignored) {}
            try { pfd.close(); } catch (Exception ignored) {}
            try { reader.interrupt(); } catch (Exception ignored) {}
        }
    }
}

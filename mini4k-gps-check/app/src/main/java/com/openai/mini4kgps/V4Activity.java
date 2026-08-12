package com.openai.mini4kgps;

import android.app.Activity;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.hardware.usb.UsbConstants;
import android.hardware.usb.UsbDevice;
import android.hardware.usb.UsbDeviceConnection;
import android.hardware.usb.UsbEndpoint;
import android.hardware.usb.UsbInterface;
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

import java.io.ByteArrayOutputStream;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;

public class V4Activity extends Activity {
    private static final int DJI_VENDOR = 0x2CA3;
    private static final int RC_N1_PID = 0x1020;
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.USB_PERMISSION";
    private static final int RELATED_MODEL_GPS_TABLE = 0;
    private static final int RELATED_MODEL_GPS_INDEX = 771;

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger sequence = new AtomicInteger(0x6400);

    private UsbManager usbManager;
    private TextView status;
    private Button checkButton;
    private Button offButton;
    private Button onButton;

    private static final class Route {
        final int senderType, senderIndex, receiverType, receiverIndex;
        final String label;
        Route(int st, int si, int rt, int ri, String label) {
            this.senderType = st; this.senderIndex = si; this.receiverType = rt; this.receiverIndex = ri; this.label = label;
        }
    }

    private static final Route[] ROUTES = new Route[]{
            new Route(DumlV2.DEV_PC, 0, DumlV2.DEV_FLYCONTROLLER, 0, "PC0 -> FLYC"),
            new Route(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0, "APP0 -> FLYC"),
            new Route(DumlV2.DEV_PC, 0, DumlV2.DEV_AIRCRAFT_PROXY, 0, "PC0 -> AIRCRAFT(31)")
    };

    private static final class BulkLink {
        final UsbDeviceConnection conn;
        final UsbInterface intf;
        final UsbEndpoint in;
        final UsbEndpoint out;
        BulkLink(UsbDeviceConnection conn, UsbInterface intf, UsbEndpoint in, UsbEndpoint out) {
            this.conn = conn; this.intf = intf; this.in = in; this.out = out;
        }
        void close() {
            try { conn.releaseInterface(intf); } catch (Exception ignored) {}
            try { conn.close(); } catch (Exception ignored) {}
        }
    }

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        usbManager = (UsbManager) getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
        showIntro();
    }

    @Override protected void onDestroy() {
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
        title.setText("Mini 4K GPS CHECK v0.4");
        title.setTextSize(23);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        checkButton = new Button(this);
        checkButton.setText("DIRECT CHECK");
        root.addView(checkButton, paramsTop(12));

        offButton = new Button(this);
        offButton.setText("GPS OFF (LOCKED)");
        offButton.setEnabled(false);
        root.addView(offButton, paramsTop(8));

        onButton = new Button(this);
        onButton.setText("GPS ON (LOCKED)");
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

        checkButton.setOnClickListener(v -> {
            checkButton.setEnabled(false);
            io.submit(() -> {
                try { performDirectCheck(); }
                catch (Throwable t) { append("ОШИБКА: " + t.getClass().getSimpleName() + ": " + t.getMessage()); }
                finally { runOnUiThread(() -> checkButton.setEnabled(true)); }
            });
        });
    }

    private void showIntro() {
        status.setText("");
        append("Mini 4K GPS Parameter Test v0.4");
        append("ТОЛЬКО ЧТЕНИЕ. НИ ОДИН ПАРАМЕТР НЕ ЗАПИСЫВАЕТСЯ.");
        append("");
        append("ВАЖНО: v0.4 подключается НЕ через RC-N1.");
        append("1) Включите дрон. Пульт для этого теста можно оставить включённым.");
        append("2) Отключите телефон от нижнего USB-C пульта.");
        append("3) DATA-кабелем USB-C подключите телефон НАПРЯМУЮ к USB-C дрона.");
        append("4) Разрешите Android доступ к USB и нажмите DIRECT CHECK.");
        append("");
        append("Ищется DJI USB BULK/ACM интерфейс subclass 0x43 — тот тип транспорта, который используется сервисными DUML-инструментами для прямого подключения к UAV.");
    }

    private LinearLayout.LayoutParams paramsTop(int m) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2);
        p.topMargin = dp(m); return p;
    }
    private int dp(int x) { return Math.round(x * getResources().getDisplayMetrics().density); }

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
            String a = intent.getAction();
            if (ACTION_USB_PERMISSION.equals(a)) {
                boolean ok = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(ok ? "USB permission: OK. Теперь нажмите DIRECT CHECK." : "USB permission: ОТКАЗАНО.");
            } else if (UsbManager.ACTION_USB_DEVICE_ATTACHED.equals(a)) append("USB устройство подключено.");
            else if (UsbManager.ACTION_USB_DEVICE_DETACHED.equals(a)) append("USB устройство отключено.");
        }
    };

    private void performDirectCheck() throws Exception {
        runOnUiThread(() -> status.setText(""));
        append("=== DIRECT CHECK v0.4 ===");

        UsbDevice target = null;
        for (UsbDevice d : usbManager.getDeviceList().values()) {
            append(String.format(Locale.US, "USB device: VID=%04X PID=%04X interfaces=%d", d.getVendorId(), d.getProductId(), d.getInterfaceCount()));
            if (d.getVendorId() == DJI_VENDOR && d.getProductId() != RC_N1_PID) target = d;
        }

        if (target == null) {
            for (UsbDevice d : usbManager.getDeviceList().values()) {
                if (d.getVendorId() == DJI_VENDOR && d.getProductId() == RC_N1_PID) {
                    append("");
                    append("Сейчас Android видит RC-N1 (2CA3:1020), а не сам дрон.");
                    append("Отключите телефон от пульта и подключите его DATA-кабелем НАПРЯМУЮ к USB-C разъёму Mini 4K.");
                    append("После переподключения нажмите DIRECT CHECK.");
                    return;
                }
            }
            append("DJI USB устройство (VID 2CA3) не найдено. Проверьте DATA-кабель/OTG и что дрон включён.");
            return;
        }

        append(String.format(Locale.US, "TARGET: DJI VID=%04X PID=%04X", target.getVendorId(), target.getProductId()));
        if (!usbManager.hasPermission(target)) {
            PendingIntent pi = PendingIntent.getBroadcast(this, 0,
                    new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                    PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
            usbManager.requestPermission(target, pi);
            append("Android запросил USB-разрешение. Разрешите и снова нажмите DIRECT CHECK.");
            return;
        }

        describeInterfaces(target);
        BulkLink link = openDjiBulk(target);
        if (link == null) {
            append("");
            append("BULK/ACM интерфейс с subclass 0x43 не найден или не удалось его открыть.");
            append("Пришлите этот экран целиком — по списку interface/endpoint сделаю следующий транспорт.");
            return;
        }

        try {
            drain(link);
            append("");
            append("[1/2] 2015/hash direct");
            for (Route route : ROUTES) {
                append("-- " + route.label);
                if (try2015(link, route)) return;
            }

            append("");
            append("[2/2] 2017/table direct");
            for (Route route : ROUTES) {
                append("-- " + route.label);
                if (try2017(link, route)) return;
            }

            append("");
            append("РЕЗУЛЬТАТ: прямой USB транспорт открыт, но gps_enable этим набором команд не подтверждён.");
            append("НИЧЕГО В ДРОН НЕ ЗАПИСАНО. Пришлите весь экран DIRECT CHECK v0.4.");
        } finally {
            link.close();
            append("Direct USB: CLOSED");
        }
    }

    private void describeInterfaces(UsbDevice d) {
        for (int i = 0; i < d.getInterfaceCount(); i++) {
            UsbInterface intf = d.getInterface(i);
            append(String.format(Locale.US, "IF%d id=%d class=0x%02X sub=0x%02X proto=0x%02X eps=%d",
                    i, intf.getId(), intf.getInterfaceClass(), intf.getInterfaceSubclass(), intf.getInterfaceProtocol(), intf.getEndpointCount()));
            for (int e = 0; e < intf.getEndpointCount(); e++) {
                UsbEndpoint ep = intf.getEndpoint(e);
                append(String.format(Locale.US, "  EP%d addr=0x%02X type=%d dir=%s max=%d",
                        e, ep.getAddress(), ep.getType(), ep.getDirection() == UsbConstants.USB_DIR_IN ? "IN" : "OUT", ep.getMaxPacketSize()));
            }
        }
    }

    private BulkLink openDjiBulk(UsbDevice d) {
        UsbDeviceConnection conn = usbManager.openDevice(d);
        if (conn == null) { append("Не удалось открыть DJI USB device."); return null; }

        UsbInterface fallback = null;
        UsbEndpoint fallbackIn = null, fallbackOut = null;
        for (int i = 0; i < d.getInterfaceCount(); i++) {
            UsbInterface intf = d.getInterface(i);
            UsbEndpoint in = null, out = null;
            for (int e = 0; e < intf.getEndpointCount(); e++) {
                UsbEndpoint ep = intf.getEndpoint(e);
                if (ep.getType() != UsbConstants.USB_ENDPOINT_XFER_BULK) continue;
                if (ep.getDirection() == UsbConstants.USB_DIR_IN && in == null) in = ep;
                if (ep.getDirection() == UsbConstants.USB_DIR_OUT && out == null) out = ep;
            }
            if (in != null && out != null) {
                if (intf.getInterfaceSubclass() == 0x43) {
                    if (!conn.claimInterface(intf, true)) continue;
                    append("Direct USB BULK/ACM: OPEN IF=" + i + " subclass=0x43");
                    return new BulkLink(conn, intf, in, out);
                }
                if (fallback == null) { fallback = intf; fallbackIn = in; fallbackOut = out; }
            }
        }

        // Do not send DUML blindly to MTP/ADB. Fallback is only logged for diagnosis.
        if (fallback != null) append("Есть другой BULK IF id=" + fallback.getId() + ", но без subclass 0x43 — не использую его вслепую.");
        conn.close();
        return null;
    }

    private boolean try2015(BulkLink link, Route route) {
        String[] names = {"gps_enable", "g_config.gps_cfg.gps_enable"};
        for (String name : names) {
            long hash = DumlV2.parameterHash(name);
            DumlV2.Frame f = transact(link, route, DumlV2.CMDSET_FLYC, 0xF7, DumlV2.le32(hash), 900);
            if (f == null) continue;
            append(String.format(Locale.US, "  F7 response hash=0x%08X payload=%s", hash, DumlV2.hex(f.payload)));
            DumlV2.ParamInfo2015 info = DumlV2.ParamInfo2015.parse(f.payload);
            if (info == null) continue;
            append("  name='" + info.name + "' type=" + info.typeId + " size=" + info.size + " range=" + info.min + ".." + info.max + " status=" + info.status);
            if (isGps(info.name) && info.status == 0 && info.size == 1 && info.min == 0 && info.max == 1) {
                DumlV2.Frame r = transact(link, route, DumlV2.CMDSET_FLYC, 0xF8, DumlV2.le32(hash), 900);
                if (r != null) {
                    append("  F8 read payload=" + DumlV2.hex(r.payload));
                    Integer v = parseHashValue(r.payload, hash);
                    if (v != null && (v == 0 || v == 1)) {
                        append("");
                        append("УСПЕХ: gps_enable подтверждён через DIRECT USB + 2015/hash. value=" + v);
                        append("GPS OFF/ON В v0.4 специально оставлены заблокированными. Сначала пришлите этот экран.");
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private boolean try2017(BulkLink link, Route route) {
        DumlV2.Frame table = transact(link, route, DumlV2.CMDSET_FLYC, 0xE0, DumlV2.le16(RELATED_MODEL_GPS_TABLE), 900);
        if (table == null) {
            DumlV2.Frame unlock = transact(link, route, DumlV2.CMDSET_FLYC, 0xDF, new byte[]{1,0,0,0}, 700);
            if (unlock != null) append("  DF read-access response=" + DumlV2.hex(unlock.payload));
            table = transact(link, route, DumlV2.CMDSET_FLYC, 0xE0, DumlV2.le16(RELATED_MODEL_GPS_TABLE), 900);
        }
        if (table == null) return false;
        append("  E0 payload=" + DumlV2.hex(table.payload));
        DumlV2.TableAttr2017 attr = DumlV2.TableAttr2017.parse(table.payload);
        if (attr == null) return false;
        append("  table=" + attr.tableNo + " status=" + attr.status + " entries=" + attr.entriesNum);
        if (attr.status != 0 || attr.tableNo != 0 || attr.entriesNum <= RELATED_MODEL_GPS_INDEX) return false;

        DumlV2.Frame f = transact(link, route, DumlV2.CMDSET_FLYC, 0xE1,
                DumlV2.concat(DumlV2.le16(0), DumlV2.le16(RELATED_MODEL_GPS_INDEX)), 900);
        if (f == null) return false;
        append("  E1 0:771 payload=" + DumlV2.hex(f.payload));
        DumlV2.ParamInfo2017 info = DumlV2.ParamInfo2017.parse(f.payload);
        if (info == null) return false;
        append("  name='" + info.name + "' type=" + info.typeId + " size=" + info.size + " range=" + info.min + ".." + info.max + " status=" + info.status);
        if (!isGps(info.name) || info.status != 0 || info.size != 1 || info.min != 0 || info.max != 1) return false;

        byte[] rq = DumlV2.concat(DumlV2.le16(0), DumlV2.le16(1), DumlV2.le16(RELATED_MODEL_GPS_INDEX));
        DumlV2.Frame r = transact(link, route, DumlV2.CMDSET_FLYC, 0xE2, rq, 900);
        if (r == null) return false;
        append("  E2 read payload=" + DumlV2.hex(r.payload));
        Integer v = parseIndexValue(r.payload, RELATED_MODEL_GPS_INDEX);
        if (v != null && (v == 0 || v == 1)) {
            append("");
            append("УСПЕХ: gps_enable подтверждён через DIRECT USB + 2017/index table=0 index=771. value=" + v);
            append("GPS OFF/ON В v0.4 специально оставлены заблокированными. Сначала пришлите этот экран.");
            return true;
        }
        return false;
    }

    private Integer parseHashValue(byte[] p, long hash) {
        if (p == null || p.length < 6) return null;
        if ((p[0] & 0xFF) != 0) return null;
        if (DumlV2.u32(p, 1) != (hash & 0xFFFFFFFFL)) return null;
        return p[5] & 0xFF;
    }

    private Integer parseIndexValue(byte[] p, int index) {
        if (p == null || p.length < 7) return null;
        if (DumlV2.u16(p, 0) != 0) return null;
        if (DumlV2.u16(p, 4) != index) return null;
        return p[6] & 0xFF;
    }

    private boolean isGps(String name) {
        if (name == null) return false;
        String n = name.trim();
        return "gps_enable".equals(n) || "g_config.gps_cfg.gps_enable".equals(n) ||
                "gps_enable|g_config.gps_cfg.gps_enable".equals(n) ||
                "g_config.gps_cfg.gps_enable|gps_enable".equals(n);
    }

    private DumlV2.Frame transact(BulkLink link, Route route, int set, int id, byte[] payload, int timeoutMs) {
        int seq = sequence.getAndIncrement() & 0xFFFF;
        byte[] req = DumlV2.packet(route.senderType, route.senderIndex, route.receiverType, route.receiverIndex,
                seq, set, id, payload);
        int wr = link.conn.bulkTransfer(link.out, req, req.length, 1000);
        if (wr != req.length) {
            append("  USB OUT failed wr=" + wr + "/" + req.length);
            return null;
        }

        long start = System.currentTimeMillis();
        ByteArrayOutputStream all = new ByteArrayOutputStream();
        byte[] buf = new byte[Math.max(512, link.in.getMaxPacketSize() * 8)];
        while (System.currentTimeMillis() - start < timeoutMs) {
            int n = link.conn.bulkTransfer(link.in, buf, buf.length, 120);
            if (n > 0) {
                all.write(buf, 0, n);
                DumlV2.Frame exact = DumlV2.findFrame(all, seq, set, id, false);
                if (exact != null) return exact;
                if (System.currentTimeMillis() - start > 220) {
                    DumlV2.Frame anySeq = DumlV2.findFrame(all, seq, set, id, true);
                    if (anySeq != null) return anySeq;
                }
            }
        }
        return DumlV2.findFrame(all, seq, set, id, true);
    }

    private void drain(BulkLink link) {
        byte[] b = new byte[Math.max(256, link.in.getMaxPacketSize() * 4)];
        int total = 0;
        for (int i = 0; i < 4; i++) {
            int n = link.conn.bulkTransfer(link.in, b, b.length, 40);
            if (n > 0) total += n; else break;
        }
        append("Direct USB initial drain bytes=" + total);
    }

    private void append(String s) {
        runOnUiThread(() -> {
            status.append(s + "\n");
            View p = (View) status.getParent();
            if (p instanceof ScrollView) ((ScrollView) p).post(() -> ((ScrollView) p).fullScroll(View.FOCUS_DOWN));
        });
    }
}

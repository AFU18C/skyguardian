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
import android.view.Gravity;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Read-only probe for the DJI GPS DeviceType RTK MultiParam interface.
 *
 * Reverse-engineered DJI app sources show this exact route for GetMultiParam:
 * APP -> GPS DeviceType(26), receiverId=5, CmdSet RTK(0x0F), CmdId 0xF5,
 * payload = one-byte parameter id. The paired SET command is 0xF4 but is never sent here.
 */
public class GpsRtkParamProbeActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GPS_RTK_PARAM_USB";
    private static final int DEV_GPS = 26;
    private static final int CMDSET_RTK = 0x0F;
    private static final int CMD_GET_MULTI_PARAM = 0xF5;
    private static final int CMD_SET_MULTI_PARAM = 0xF4; // documented only; NEVER SENT
    private static final int PREFERRED_GPS_INDEX = 5;

    private static final int[] KNOWN_IDS = {
            0, 2, 10, 22, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36
    };

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0xC400);
    private final AtomicBoolean running = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView status;
    private TextView log;
    private Button start;
    private Button stop;
    private volatile boolean pendingStart;
    private volatile ProbeAoaSession activeSession;

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
        ProbeAoaSession s = activeSession;
        if (s != null) s.close();
        try {
            unregisterReceiver(usbReceiver);
        } catch (Exception ignored) {
        }
        io.shutdownNow();
        super.onDestroy();
    }

    private void buildUi() {
        int pad = dp(16);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("Mini 4K GPS PARAM ACCESS PROBE v2.3");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        start = new Button(this);
        start.setText("PROBE GPS(26) RTK PARAM ACCESS — READ ONLY");
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
                "Проверяет реальный DJI путь GPS DeviceType=26 / receiverId=5 / RTK 0x0F / GetMultiParam 0xF5.\n" +
                "SET 0xF4: NEVER SENT | PARAM WRITES: 0 | EXEC: 0");
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

    private int dp(int x) {
        return Math.round(x * getResources().getDisplayMetrics().density);
    }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(usbReceiver, f, Context.RECEIVER_NOT_EXPORTED);
        } else {
            registerReceiver(usbReceiver, f);
        }
    }

    private final BroadcastReceiver usbReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context c, Intent i) {
            if (ACTION_USB_PERMISSION.equals(i.getAction())) {
                boolean ok = i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(ok ? "USB permission: OK" : "USB permission: DENIED");
                if (ok && pendingStart) {
                    pendingStart = false;
                    begin();
                }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(i.getAction())) {
                running.set(false);
                append("RC-N1 AOA disconnected");
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
            append("AOA DJI не найден. Закрой DJI Fly и переподключи телефон к верхнему порту RC-N1.");
            return;
        }
        if (!usbManager.hasPermission(a)) {
            requestPermission(a);
            return;
        }
        running.set(true);
        runOnUiThread(() -> {
            start.setEnabled(false);
            stop.setEnabled(true);
            status.setText("START...\nGET 0xF5 only | SET 0xF4 sent: 0");
        });
        io.submit(() -> runProbe(a));
    }

    private void runProbe(UsbAccessory a) {
        ProbeAoaSession s = null;
        try {
            if (!DumlV2.selfTest()) {
                setStatus("DUML self-test FAIL. Ничего не отправлено.");
                return;
            }

            s = ProbeAoaSession.open(usbManager, a, seq);
            activeSession = s;
            if (s == null) {
                setStatus("AOA pipe не открылся. DJI Fly должен быть полностью закрыт.");
                return;
            }
            s.startProtocol();
            sleep(400);

            append("=== GPS DEVICE RTK MULTIPARAM ACCESS PROBE v2.3 ===");
            append("AOA route=" + s.routeString());
            append("Route under test: APP -> GPS(26)[index] / cmdset RTK 0x0F / GET 0xF5");
            append("Known DJI preferred receiverId=5");
            append("PAIRED SET COMMAND 0x" + hex2(CMD_SET_MULTI_PARAM) + " EXISTS BUT SENT: 0");

            setStatus("1/3 Finding responsive GPS receiver index...\nGET-only known parameter IDs.");
            Candidate candidate = findCandidate(s);
            if (candidate == null) {
                setStatus("NO RTK MultiParam endpoint response on GPS(26) indices 0..7.\nGET sent only; SET 0xF4 sent: 0.\nСледующий путь: E9/service command table + firmware/board analysis.");
                return;
            }

            append("SELECTED endpoint GPS(26)[" + candidate.index + "] transport=" +
                    (candidate.encrypted ? "SIMPLE" : "PLAINTEXT") +
                    " successfulKnownReads=" + candidate.successes + " responses=" + candidate.responses);

            setStatus("2/3 Enumerating parameter IDs 0..255...\nGET 0xF5 only. ~до 70 сек.");
            List<ParamResult> successes = new ArrayList<>();
            int responses = 0;
            int invalid = 0;
            int timeouts = 0;

            for (int id = 0; id <= 255 && running.get(); id++) {
                DumlV2.Frame r = getMulti(s, candidate.index, id, candidate.encrypted, 260);
                if (r == null) {
                    timeouts++;
                    continue;
                }
                responses++;
                ParamResult pr = ParamResult.parse(id, r.payload);
                if (pr.success) {
                    successes.add(pr);
                    append("*** PARAM ACCESS id=" + id + " | " + pr.describe());
                } else {
                    invalid++;
                    if (knownId(id)) append("KNOWN id=" + id + " rejected | " + pr.describe());
                }
                if (id % 32 == 31) {
                    setStatus("2/3 GET MultiParam scan id=" + id + "/255\nresponses=" + responses + " accessible=" + successes.size());
                }
            }

            setStatus("3/3 RESULT\nBuilding access map...");
            StringBuilder out = new StringBuilder();
            out.append("GPS RTK PARAM ACCESS RESULT\n\n");
            out.append("Endpoint: GPS(26)[").append(candidate.index).append("]\n");
            out.append("Transport: ").append(candidate.encrypted ? "SIMPLE encrypted" : "PLAINTEXT").append('\n');
            out.append("Protocol: RTK cmdset 0x0F / GetMultiParam 0xF5\n");
            out.append("GET responses: ").append(responses).append(" | accessible IDs: ").append(successes.size())
                    .append(" | rejected: ").append(invalid).append(" | timeouts: ").append(timeouts).append("\n\n");

            if (successes.isEmpty()) {
                out.append("Endpoint responded, but no parameter ID returned success in 0..255.\n");
            } else {
                out.append("=== ACCESSIBLE PARAMETER IDS ===\n");
                for (ParamResult pr : successes) {
                    out.append("id=").append(pr.requestId).append(" | ").append(pr.describe()).append('\n');
                }
            }

            out.append("\nDJI paired write command: RTK SetMultiParam 0xF4 (NOT SENT).\n");
            out.append("SET 0xF4 SENT: 0 | PARAM WRITES: 0 | EXEC: 0\n");
            out.append("Если нужные GNSS receiver IDs здесь есть, следующий APK сможет делать точечный read/write только после идентификации значения.");
            setStatus(out.toString());
            append(out.toString().replace('\n', ' '));
        } catch (Throwable t) {
            setStatus("GPS RTK PROBE ERROR: " + t.getClass().getSimpleName() + ": " + t.getMessage());
            append("ERROR " + t);
        } finally {
            if (s != null) s.close();
            activeSession = null;
            running.set(false);
            runOnUiThread(() -> {
                start.setEnabled(true);
                stop.setEnabled(false);
            });
            append("AOA CLOSED; SET 0xF4 SENT: 0; WRITES: 0");
        }
    }

    private Candidate findCandidate(ProbeAoaSession s) throws Exception {
        int[] probeIds = {0, 22, 10};
        Candidate best = null;

        // DJI source explicitly uses receiverId 5, so test it first.
        int[] indexOrder = {5, 0, 1, 2, 3, 4, 6, 7};
        for (boolean encrypted : new boolean[]{false, true}) {
            for (int idx : indexOrder) {
                int responses = 0;
                int successes = 0;
                for (int id : probeIds) {
                    DumlV2.Frame r = getMulti(s, idx, id, encrypted, idx == PREFERRED_GPS_INDEX ? 900 : 500);
                    if (r == null) continue;
                    responses++;
                    ParamResult pr = ParamResult.parse(id, r.payload);
                    append("DISCOVERY GPS(26)[" + idx + "] " + (encrypted ? "SIMPLE" : "PLAIN") +
                            " id=" + id + " -> " + pr.describe());
                    if (pr.success) successes++;
                }
                if (responses > 0) {
                    Candidate c = new Candidate(idx, encrypted, responses, successes);
                    if (best == null || c.score() > best.score()) best = c;
                    if (idx == PREFERRED_GPS_INDEX && successes > 0) return c;
                }
            }
            if (best != null && best.successes > 0) return best;
        }
        return best;
    }

    private DumlV2.Frame getMulti(ProbeAoaSession s, int receiverIndex, int paramId,
                                  boolean encrypted, int timeoutMs) throws Exception {
        int qseq = seq.getAndIncrement() & 0xFFFF;
        byte[] payload = new byte[]{(byte) (paramId & 0xFF)};
        s.sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DEV_GPS, receiverIndex,
                qseq, CMDSET_RTK, CMD_GET_MULTI_PARAM, payload, encrypted));
        long end = System.currentTimeMillis() + timeoutMs;
        while (System.currentTimeMillis() < end) {
            DumlV2.Frame f = s.poll(Math.min(80, Math.max(1, end - System.currentTimeMillis())));
            if (f == null) continue;
            if (f.response && f.seq == qseq && f.cmdSet == CMDSET_RTK && f.cmdId == CMD_GET_MULTI_PARAM) {
                return f;
            }
        }
        return null;
    }

    private static final class Candidate {
        final int index;
        final boolean encrypted;
        final int responses;
        final int successes;

        Candidate(int index, boolean encrypted, int responses, int successes) {
            this.index = index;
            this.encrypted = encrypted;
            this.responses = responses;
            this.successes = successes;
        }

        int score() {
            return successes * 100 + responses * 10 + (index == PREFERRED_GPS_INDEX ? 1 : 0);
        }
    }

    private static final class ParamResult {
        final int requestId;
        final int echoedId;
        final int result;
        final int flags;
        final int length;
        final byte[] value;
        final byte[] raw;
        final boolean success;

        ParamResult(int requestId, int echoedId, int result, int flags, int length,
                    byte[] value, byte[] raw, boolean success) {
            this.requestId = requestId;
            this.echoedId = echoedId;
            this.result = result;
            this.flags = flags;
            this.length = length;
            this.value = value;
            this.raw = raw;
            this.success = success;
        }

        static ParamResult parse(int requestId, byte[] p) {
            if (p == null) return new ParamResult(requestId, -1, -1, -1, -1, new byte[0], new byte[0], false);
            int echoed = p.length > 0 ? p[0] & 0xFF : -1;
            int result = p.length > 1 ? p[1] & 0xFF : -1;
            int flags = p.length > 2 ? p[2] & 0xFF : -1;
            int declared = p.length > 3 ? p[3] & 0xFF : -1;
            int available = Math.max(0, p.length - 4);
            int n = declared >= 0 ? Math.min(declared, available) : 0;
            byte[] value = new byte[n];
            if (n > 0) System.arraycopy(p, 4, value, 0, n);
            boolean ok = p.length >= 4 && result == 0 && declared <= available && (echoed == requestId || echoed < 0);
            return new ParamResult(requestId, echoed, result, flags, declared, value, p, ok);
        }

        String describe() {
            StringBuilder x = new StringBuilder();
            x.append("echo=").append(echoedId)
                    .append(" result=").append(result)
                    .append(" flags=").append(flags)
                    .append(" len=").append(length)
                    .append(" value=").append(shortHex(value, 96));
            String ascii = printable(value);
            if (!ascii.isEmpty()) x.append(" ascii='").append(ascii).append("'");
            if (value.length > 0 && value.length <= 4) x.append(" uLE=").append(unsignedLe(value));
            x.append(" raw=").append(shortHex(raw, 120));
            return x.toString();
        }
    }

    private static boolean knownId(int id) {
        for (int k : KNOWN_IDS) if (k == id) return true;
        return false;
    }

    private static long unsignedLe(byte[] b) {
        long v = 0;
        for (int i = 0; i < b.length && i < 8; i++) v |= ((long) b[i] & 0xFFL) << (8 * i);
        return v;
    }

    private static String printable(byte[] p) {
        if (p == null || p.length == 0) return "";
        String s = new String(p, StandardCharsets.UTF_8).replace('\u0000', ' ').trim();
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            if (c >= 32 && c <= 126) out.append(c);
        }
        return out.toString().trim();
    }

    private static String shortHex(byte[] p, int max) {
        if (p == null) return "";
        StringBuilder s = new StringBuilder();
        int n = Math.min(max, p.length);
        for (int i = 0; i < n; i++) {
            if (i > 0) s.append(' ');
            s.append(String.format(Locale.US, "%02X", p[i] & 0xFF));
        }
        if (p.length > n) s.append(" ...");
        return s.toString();
    }

    private static String hex2(int v) {
        return String.format(Locale.US, "%02X", v & 0xFF);
    }

    private void setStatus(String s) {
        runOnUiThread(() -> status.setText(s));
    }

    private void append(String s) {
        runOnUiThread(() -> log.append(s + "\n"));
    }

    private static void sleep(long ms) {
        try {
            Thread.sleep(ms);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }
}

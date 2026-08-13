package com.openai.mini4kgps;

import android.app.Activity;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.ClipData;
import android.content.ClipboardManager;
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

import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * v3.0 passive GNSS boot-log/signature capture.
 *
 * The diagnostic itself sends no DUML read/write/config commands. ProbeAoaSession only
 * performs the existing AOA transport handshake/RC keepalive needed to receive traffic.
 * We then passively inspect incoming DUML payloads, especially General 0x0E log messages,
 * for receiver-family evidence such as m8/m10/ublox/MGA/DBD/uniqid.
 */
public class GnssBootLogProbeActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_BOOTLOG_PERMISSION";
    private static final long CAPTURE_MS = 55_000L;
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0xD100);
    private final AtomicBoolean busy = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView log, status;
    private Button run, copy;
    private volatile boolean pendingPermission;
    private volatile String lastReport = "";

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        usbManager=(UsbManager)getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
    }

    @Override protected void onDestroy() {
        try { unregisterReceiver(usbReceiver); } catch(Exception ignored) {}
        io.shutdownNow();
        super.onDestroy();
    }

    private void buildUi() {
        int pad=dp(16);
        LinearLayout root=new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad,pad,pad,pad);

        TextView title=new TextView(this);
        title.setText("Mini 4K GNSS BOOT SIGNATURE v3.0");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title,new LinearLayout.LayoutParams(-1,-2));

        status=new TextView(this);
        status.setText("Для максимального шанса: RC-N1 включён, телефон подключён, DJI Fly закрыт, САМ ДРОН выключен. Нажми RUN и в течение 5 секунд включи дрон. Захват 55 секунд.\n\nPASSIVE CAPTURE: параметров/конфигурации/receiver commands = 0.");
        status.setTextSize(15);
        status.setTextIsSelectable(true);
        root.addView(status,top(12));

        run=new Button(this);
        run.setText("RUN 55s PASSIVE BOOT SIGNATURE CAPTURE");
        root.addView(run,top(12));

        copy=new Button(this);
        copy.setText("COPY FULL REPORT");
        copy.setEnabled(false);
        root.addView(copy,top(8));

        log=new TextView(this);
        log.setTextSize(12);
        log.setTextIsSelectable(true);
        log.setPadding(0,dp(12),0,dp(12));
        ScrollView sc=new ScrollView(this);
        sc.addView(log,new ScrollView.LayoutParams(-1,-2));
        root.addView(sc,new LinearLayout.LayoutParams(-1,0,1f));
        setContentView(root);

        append("PASSIVE ONLY. Ищем фактические boot strings: m8 / m10 / ublox / MGA / DBD / uniqid / GPS / GNSS.");
        run.setOnClickListener(v -> begin());
        copy.setOnClickListener(v -> copyReport());
    }

    private LinearLayout.LayoutParams top(int d) {
        LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);
        p.topMargin=dp(d);
        return p;
    }
    private int dp(int x) { return Math.round(x*getResources().getDisplayMetrics().density); }

    private void registerUsbReceiver() {
        IntentFilter f=new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if(Build.VERSION.SDK_INT>=33) registerReceiver(usbReceiver,f,Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver,f);
    }

    private final BroadcastReceiver usbReceiver=new BroadcastReceiver() {
        @Override public void onReceive(Context c, Intent i) {
            if(ACTION_USB_PERMISSION.equals(i.getAction())) {
                boolean ok=i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED,false);
                append(ok?"USB permission: OK":"USB permission: DENIED");
                if(ok && pendingPermission) { pendingPermission=false; begin(); }
            } else if(UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(i.getAction())) {
                append("RC-N1 AOA отключён.");
            }
        }
    };

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] as=usbManager.getAccessoryList();
        if(as==null || as.length==0) return null;
        for(UsbAccessory a:as) if("DJI".equalsIgnoreCase(a.getManufacturer())) return a;
        return as[0];
    }

    private void requestPermission(UsbAccessory a) {
        pendingPermission=true;
        PendingIntent pi=PendingIntent.getBroadcast(this,0,
                new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                PendingIntent.FLAG_MUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
        usbManager.requestPermission(a,pi);
    }

    private void begin() {
        if(!busy.compareAndSet(false,true)) return;
        UsbAccessory a=chooseAccessory();
        if(a==null) {
            append("AOA DJI не найден. Подключи телефон к RC-N1 и закрой DJI Fly.");
            busy.set(false);
            return;
        }
        if(!usbManager.hasPermission(a)) {
            requestPermission(a);
            busy.set(false);
            return;
        }
        run.setEnabled(false); copy.setEnabled(false); lastReport="";
        runOnUiThread(() -> log.setText(""));
        io.submit(() -> {
            try { perform(a); }
            catch(Throwable t) { append("CAPTURE ERROR: "+t.getClass().getSimpleName()+": "+t.getMessage()); }
            finally { busy.set(false); runOnUiThread(() -> run.setEnabled(true)); }
        });
    }

    private void perform(UsbAccessory a) throws Exception {
        StringBuilder r=new StringBuilder(256*1024);
        line(r,"MINI 4K GNSS PASSIVE BOOT SIGNATURE CAPTURE v3.0");
        line(r,"DIAGNOSTIC DUML READ COMMANDS=0 PARAM_WRITES=0 CONFIG_WRITES=0 UNLOCK=0 EXEC=0 RECEIVER_COMMANDS=0");
        line(r,"NOTE: AOA transport handshake + RC keepalive are transport-only and are not GNSS configuration commands.");

        if(!DumlV2.selfTest()) {
            line(r,"DUML self-test=FAIL"); finish(r); return;
        }
        ProbeAoaSession s=ProbeAoaSession.open(usbManager,a,seq);
        if(s==null) { line(r,"AOA=FAILED"); finish(r); return; }

        try {
            s.startProtocol();
            line(r,"AOA route="+s.routeString());
            append("AOA OPEN "+s.routeString()+" — СЕЙЧАС включи дрон, если он выключен.");
            runOnUiThread(() -> status.setText("Захват идёт 55 секунд. Если дрон был выключен — включи его сейчас. Ничего больше не нажимай."));

            long startMs=System.currentTimeMillis();
            long end=startMs+CAPTURE_MS;
            int total=0, general0e=0, flycFrames=0, textFrames=0;
            Set<String> signatures=new LinkedHashSet<>();
            Set<String> interestingLines=new LinkedHashSet<>();
            Map<String,Integer> hist=new LinkedHashMap<>();

            while(System.currentTimeMillis()<end) {
                DumlV2.Frame f=s.poll(180);
                if(f==null) continue;
                total++;
                if(f.senderType==DumlV2.DEV_FLYCONTROLLER) flycFrames++;
                String hk=String.format(Locale.US,"src=%d:%d set=0x%02X id=0x%02X %s",
                        f.senderType,f.senderIndex,f.cmdSet,f.cmdId,f.response?"RSP":"PUSH");
                hist.put(hk,hist.getOrDefault(hk,0)+1);

                String text=extractPrintable(f.payload);
                if(!text.isEmpty()) textFrames++;
                boolean isLog=(f.cmdSet==DumlV2.CMDSET_GENERAL && f.cmdId==0x0E);
                if(isLog) general0e++;

                String lower=text.toLowerCase(Locale.US);
                String sig=signatureFrom(lower);
                if(!sig.isEmpty()) signatures.add(sig);
                boolean interesting=isLog || containsGnssEvidence(lower);
                if(interesting) {
                    String row=String.format(Locale.US,
                            "TEXT|t=%dms|src=%d:%d|set=0x%02X|id=0x%02X|rsp=%d|ascii='%s'|raw=%s",
                            System.currentTimeMillis()-startMs,f.senderType,f.senderIndex,f.cmdSet,f.cmdId,
                            f.response?1:0,safe(text),hex(f.payload,192));
                    if(interestingLines.add(row)) {
                        line(r,row);
                        if(containsStrongSignature(lower)) append("*** GNSS SIGNATURE: "+text);
                    }
                }
            }

            line(r,""); line(r,"=== SIGNATURE SUMMARY ===");
            line(r,"STRONG_SIGNATURES="+(signatures.isEmpty()?"NONE":join(signatures)));
            line(r,"TOTAL_DUML="+total+" FLYC_FRAMES="+flycFrames+" GENERAL_0x0E="+general0e+" PRINTABLE_PAYLOADS="+textFrames+" INTERESTING_TEXT_ROWS="+interestingLines.size());
            line(r,""); line(r,"=== COMMAND HISTOGRAM (top observed keys) ===");
            int emitted=0;
            for(Map.Entry<String,Integer> e:hist.entrySet()) {
                if(emitted++>=180) break;
                line(r,"HIST|"+e.getKey()+"|count="+e.getValue());
            }
            line(r,"");
            line(r,"INTERPRETATION RULE: only literal captured strings count as receiver-family proof. 'm8' => M8-family evidence; 'm10' => M10-family evidence. NONE means this bus did not expose the boot signature; it is not proof of either family.");
            line(r,"DIAGNOSTIC DUML READ COMMANDS=0 PARAM_WRITES=0 CONFIG_WRITES=0 UNLOCK=0 EXEC=0 RECEIVER_COMMANDS=0");

            append("=== ГОТОВО === signatures="+(signatures.isEmpty()?"NONE":join(signatures))+" 0x0E="+general0e+" text="+textFrames);
            append("Нажми COPY FULL REPORT и пришли текст.");
            finish(r);
        } finally {
            s.close();
            append("AOA CLOSED; GNSS writes/config=0");
        }
    }

    private static boolean containsGnssEvidence(String s) {
        if(s==null || s.isEmpty()) return false;
        String[] k={"gps","gnss","ublox","u-blox","m8_","m8 ","m8030","m10_","m10 ","m10050","m10150","mga","dbd","uniqid","assistnow","agps","navi","ai900"};
        for(String x:k) if(s.contains(x)) return true;
        return false;
    }
    private static boolean containsStrongSignature(String s) {
        return s.contains("m8_") || s.contains("m8030") || s.contains("m10_") || s.contains("m10050") || s.contains("m10150") || s.contains("ublox") || s.contains("u-blox");
    }
    private static String signatureFrom(String s) {
        if(s==null) return "";
        if(s.contains("m8030")) return "M8030";
        if(s.contains("m10050")) return "M10050/M10";
        if(s.contains("m10150")) return "M10150/M10";
        if(s.contains("m10_") || s.contains("m10 ")) return "M10";
        if(s.contains("m8_") || s.contains("m8 ")) return "M8";
        if(s.contains("ublox") || s.contains("u-blox")) return "u-blox";
        return "";
    }

    private static String extractPrintable(byte[] p) {
        if(p==null || p.length==0) return "";
        StringBuilder out=new StringBuilder();
        StringBuilder run=new StringBuilder();
        for(byte q:p) {
            int v=q&0xFF;
            if(v>=32 && v<=126) run.append((char)v);
            else {
                if(run.length()>=4) { if(out.length()>0) out.append(" | "); out.append(run); }
                run.setLength(0);
            }
        }
        if(run.length()>=4) { if(out.length()>0) out.append(" | "); out.append(run); }
        return out.toString();
    }
    private static String safe(String s) { return s==null?"":s.replace("\\","\\\\").replace("'","\\'").replace("\n"," ").replace("\r"," "); }
    private static String hex(byte[] p,int max) {
        if(p==null) return "";
        StringBuilder s=new StringBuilder();
        int n=Math.min(max,p.length);
        for(int i=0;i<n;i++) { if(i>0)s.append(' '); s.append(String.format(Locale.US,"%02X",p[i]&0xFF)); }
        if(p.length>n) s.append(" ...");
        return s.toString();
    }
    private static String join(Set<String> s) { StringBuilder x=new StringBuilder(); for(String q:s){ if(x.length()>0)x.append(','); x.append(q); } return x.toString(); }
    private static void line(StringBuilder b,String s) { b.append(s).append('\n'); }
    private void finish(StringBuilder r) { lastReport=r.toString(); runOnUiThread(() -> copy.setEnabled(true)); }
    private void copyReport() {
        if(lastReport==null || lastReport.isEmpty()) return;
        ClipboardManager cm=(ClipboardManager)getSystemService(Context.CLIPBOARD_SERVICE);
        if(cm!=null) { cm.setPrimaryClip(ClipData.newPlainText("Mini4K GNSS boot signature v3.0",lastReport)); append("FULL REPORT COPIED ("+lastReport.length()+" chars)"); }
    }
    private void append(String s) { runOnUiThread(() -> { log.append(s+"\n"); }); }
}

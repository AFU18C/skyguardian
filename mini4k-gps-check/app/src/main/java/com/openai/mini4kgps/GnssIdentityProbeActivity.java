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

import java.nio.charset.StandardCharsets;
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
 * v2.9 read-only device identity inventory.
 *
 * Sends only General command reads documented by dji-firmware-tools:
 *  - 0x01 Version Inquiry
 *  - 0xFF Query Device Info
 *
 * No FC parameter writes, no unlock, no receiver configuration.
 */
public class GnssIdentityProbeActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_IDENTITY_PERMISSION";
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0xC100);
    private final AtomicBoolean busy = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView log;
    private Button run, copy;
    private volatile boolean pendingPermission;
    private volatile String lastReport = "";

    private static final String[] DEV_NAMES = {
            "ANY","CAMERA","APP","FLYC","GIMBAL","CENTER","RC","WIFI_AIR","DM36X_AIR","HD_MCU_AIR",
            "PC","BATTERY","ESC","DM36X_GND","HD_MCU_GND","USB_AIR","USB_GND","MONOCULAR","BINOCULAR",
            "FPGA_AIR","FPGA_GND","SIMULATOR","BASE_STATION","AIR_COMPUTE","RC_BATTERY","IMU","GPS_RTK",
            "WIFI_GND","SIG_CVT","PMU","UNKNOWN","LAST"
    };

    private static final class Ep {
        final int type, index;
        Ep(int type, int index) { this.type=type; this.index=index; }
        String key() { return type+":"+index; }
        String label() { return devName(type)+"["+index+"]("+type+")"; }
    }

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
        title.setText("Mini 4K GNSS DEVICE ID PROBE v2.9");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title,new LinearLayout.LayoutParams(-1,-2));

        run=new Button(this);
        run.setText("RUN DEVICE ID / VERSION INVENTORY — READ ONLY");
        root.addView(run,top(12));

        copy=new Button(this);
        copy.setText("COPY FULL REPORT");
        copy.setEnabled(false);
        root.addView(copy,top(8));

        TextView note=new TextView(this);
        note.setText("Только General 0x01 Version Inquiry и 0xFF Query Device Info. Ищет реальный hardware/version string у доступных модулей, отдельно проверяет IMU/GPS-RTK/PMU. WRITE/CONFIG=0.");
        note.setTextSize(14);
        root.addView(note,top(10));

        log=new TextView(this);
        log.setTextSize(12);
        log.setTextIsSelectable(true);
        log.setPadding(0,dp(12),0,dp(12));
        ScrollView sc=new ScrollView(this);
        sc.addView(log,new ScrollView.LayoutParams(-1,-2));
        root.addView(sc,new LinearLayout.LayoutParams(-1,0,1f));
        setContentView(root);

        append("READ ONLY. General 0x01 + 0xFF only. Persistent writes: 0.");
        run.setOnClickListener(v -> begin());
        copy.setOnClickListener(v -> copyReport());
    }

    private LinearLayout.LayoutParams top(int d) {
        LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);
        p.topMargin=dp(d);
        return p;
    }
    private int dp(int x) { return Math.round(x*getResources().getDisplayMetrics().density); }

    private void copyReport() {
        if(lastReport==null || lastReport.isEmpty()) return;
        ClipboardManager cm=(ClipboardManager)getSystemService(Context.CLIPBOARD_SERVICE);
        if(cm!=null) {
            cm.setPrimaryClip(ClipData.newPlainText("Mini4K GNSS identity v2.9",lastReport));
            append("FULL REPORT COPIED ("+lastReport.length()+" chars)");
        }
    }

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
            append("AOA DJI не найден. Закрой DJI Fly и подключи телефон к RC-N1.");
            busy.set(false);
            return;
        }
        if(!usbManager.hasPermission(a)) {
            requestPermission(a);
            busy.set(false);
            return;
        }
        run.setEnabled(false);
        copy.setEnabled(false);
        lastReport="";
        io.submit(() -> {
            try { perform(a); }
            catch(Throwable t) { append("PROBE ERROR: "+t.getClass().getSimpleName()+": "+t.getMessage()); }
            finally { busy.set(false); runOnUiThread(() -> run.setEnabled(true)); }
        });
    }

    private void perform(UsbAccessory a) throws Exception {
        runOnUiThread(() -> log.setText(""));
        StringBuilder r=new StringBuilder(192*1024);
        line(r,"MINI 4K GNSS DEVICE ID / VERSION INVENTORY v2.9");
        line(r,"COMMANDS SENT: General 0x01 Version Inquiry, General 0xFF Query Device Info");
        line(r,"PERSISTENT_WRITES=0 CONFIG_WRITES=0 UNLOCK=0 EXEC=0");

        if(!DumlV2.selfTest()) {
            line(r,"DUML self-test=FAIL");
            finish(r);
            return;
        }

        ProbeAoaSession s=ProbeAoaSession.open(usbManager,a,seq);
        if(s==null) {
            line(r,"AOA=FAILED");
            append("AOA pipe не открылся.");
            finish(r);
            return;
        }

        try {
            s.startProtocol();
            sleep(350);
            line(r,"AOA route="+s.routeString());
            append("AOA OPEN route="+s.routeString());

            LinkedHashMap<String,Ep> endpoints=new LinkedHashMap<>();
            line(r,"");
            line(r,"=== PASSIVE ENDPOINT INVENTORY (4s) ===");
            long end=System.currentTimeMillis()+4000;
            int passive=0;
            while(System.currentTimeMillis()<end) {
                DumlV2.Frame f=s.poll(120);
                if(f==null) continue;
                passive++;
                if(f.senderType>0 && f.senderType<31) {
                    Ep ep=new Ep(f.senderType,f.senderIndex);
                    endpoints.put(ep.key(),ep);
                }
            }
            line(r,"PASSIVE_FRAMES="+passive+" UNIQUE_SENDERS="+endpoints.size());
            for(Ep ep:endpoints.values()) line(r,"PASSIVE|sender="+ep.label());

            // Known DJI endpoint types, plus all type-0 instances discovered passively.
            add(endpoints,new Ep(3,0));   // Flight controller
            add(endpoints,new Ep(5,0));   // Center board
            for(int i=0;i<4;i++) add(endpoints,new Ep(25,i)); // IMU
            for(int i=0;i<4;i++) add(endpoints,new Ep(26,i)); // GPS/RTK
            for(int i=0;i<4;i++) add(endpoints,new Ep(29,i)); // PMU

            line(r,"");
            line(r,"=== GENERAL 0x01 / 0xFF TARGETED INVENTORY ===");
            int versionHits=0, infoHits=0;
            for(Ep ep:endpoints.values()) {
                DumlV2.Frame vr=transact(s,ep.type,ep.index,0x01,new byte[0],360);
                String requestForm="empty";
                if(vr==null && (ep.type==25 || ep.type==26 || ep.type==29)) {
                    vr=transact(s,ep.type,ep.index,0x01,new byte[]{0},360);
                    requestForm="00";
                }
                if(vr!=null) {
                    versionHits++;
                    String parsed=parseVersion(vr.payload);
                    line(r,"VERSION_HIT|target="+ep.label()+"|reply="+devName(vr.senderType)+"["+vr.senderIndex+"]("+vr.senderType+")|request="+requestForm+"|"+parsed+"|raw="+hex(vr.payload,128));
                    append("*** VERSION "+ep.label()+" -> "+parsed);
                } else {
                    line(r,"VERSION_NO_RESPONSE|target="+ep.label());
                }

                // Query Device Info is documented as an identification/build-info request.
                // We ask it only for endpoints that replied to Version or the GNSS-related endpoint types.
                if(vr!=null || ep.type==25 || ep.type==26 || ep.type==29) {
                    DumlV2.Frame ir=transact(s,ep.type,ep.index,0xFF,new byte[0],360);
                    if(ir!=null) {
                        infoHits++;
                        String ascii=printable(ir.payload);
                        line(r,"DEVICE_INFO_HIT|target="+ep.label()+"|reply="+devName(ir.senderType)+"["+ir.senderIndex+"]("+ir.senderType+")|ascii="+safe(ascii)+"|raw="+hex(ir.payload,160));
                        append("*** INFO "+ep.label()+" ascii='"+ascii+"'");
                    } else {
                        line(r,"DEVICE_INFO_NO_RESPONSE|target="+ep.label());
                    }
                }
            }

            line(r,"");
            line(r,"=== TYPE-0 DISCOVERY SWEEP ===");
            // One read-only Version Inquiry per standard DUML device type. This finds a module
            // that may be routable but silent in passive traffic. Index 0 only, short timeout.
            int sweepHits=0;
            for(int type=1;type<=30;type++) {
                if(type==2) continue; // mobile app itself
                Ep ep=new Ep(type,0);
                if(endpoints.containsKey(ep.key())) continue;
                DumlV2.Frame vr=transact(s,type,0,0x01,new byte[0],220);
                if(vr==null) continue;
                sweepHits++;
                String parsed=parseVersion(vr.payload);
                line(r,"SWEEP_VERSION_HIT|target="+ep.label()+"|reply="+devName(vr.senderType)+"["+vr.senderIndex+"]("+vr.senderType+")|"+parsed+"|raw="+hex(vr.payload,128));
                append("*** SWEEP "+ep.label()+" -> "+parsed);
                DumlV2.Frame ir=transact(s,type,0,0xFF,new byte[0],300);
                if(ir!=null) {
                    infoHits++;
                    line(r,"SWEEP_DEVICE_INFO|target="+ep.label()+"|ascii="+safe(printable(ir.payload))+"|raw="+hex(ir.payload,160));
                }
            }

            line(r,"");
            line(r,"=== SUMMARY ===");
            line(r,"VERSION_HITS="+versionHits+" DEVICE_INFO_HITS="+infoHits+" SWEEP_EXTRA_HITS="+sweepHits);
            line(r,"GNSS_DIRECT_ENDPOINT=type26 GPS/RTK; result is proven only if VERSION_HIT/DEVICE_INFO_HIT appears for type26.");
            line(r,"If type26 stays silent, chip/vendor is not exposed as a routable DUML endpoint through RC-N1; board/firmware evidence is then required.");
            line(r,"PERSISTENT_WRITES=0 CONFIG_WRITES=0 UNLOCK=0 EXEC=0");
            append("=== ГОТОВО === version="+versionHits+" info="+infoHits+" sweep="+sweepHits);
            append("Нажми COPY FULL REPORT и пришли текст.");
            finish(r);
        } finally {
            s.close();
            append("AOA CLOSED; writes=0");
        }
    }

    private static void add(Map<String,Ep> m, Ep e) { m.putIfAbsent(e.key(),e); }

    private DumlV2.Frame transact(ProbeAoaSession s,int dev,int devIdx,int id,byte[] payload,int timeoutMs) throws Exception {
        int qseq=seq.getAndIncrement()&0xFFFF;
        s.sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,dev,devIdx,qseq,DumlV2.CMDSET_GENERAL,id,payload,false));
        long end=System.currentTimeMillis()+timeoutMs;
        while(System.currentTimeMillis()<end) {
            DumlV2.Frame f=s.poll(Math.min(100,Math.max(1,end-System.currentTimeMillis())));
            if(f==null) continue;
            if(f.response && f.seq==qseq && f.cmdSet==DumlV2.CMDSET_GENERAL && f.cmdId==id) return f;
        }
        return null;
    }

    private static String parseVersion(byte[] p) {
        if(p==null) return "payload=NULL";
        StringBuilder x=new StringBuilder();
        x.append("len=").append(p.length);
        if(p.length>=18) {
            String hw=asciiFixed(p,2,16);
            x.append("|hw='").append(safe(hw)).append("'");
        }
        if(p.length>=22) x.append("|loader=").append(version4(p,18));
        if(p.length>=26) x.append("|app=").append(version4(p,22));
        if(p.length>=30) x.append("|flags=0x").append(String.format(Locale.US,"%08X",u32(p,26)));
        String all=printable(p);
        if(!all.isEmpty()) x.append("|ascii='").append(safe(all)).append("'");
        return x.toString();
    }

    private static String version4(byte[] p,int o) {
        if(p.length<o+4) return "N/A";
        return String.format(Locale.US,"%d.%d.%d.%d",p[o+3]&0xFF,p[o+2]&0xFF,p[o+1]&0xFF,p[o]&0xFF);
    }
    private static long u32(byte[] p,int o) {
        return ((long)p[o]&0xFF)|(((long)p[o+1]&0xFF)<<8)|(((long)p[o+2]&0xFF)<<16)|(((long)p[o+3]&0xFF)<<24);
    }
    private static String asciiFixed(byte[] p,int o,int n) {
        if(p==null || o>=p.length) return "";
        int e=Math.min(p.length,o+n), z=o;
        while(z<e && p[z]!=0) z++;
        return new String(p,o,z-o, StandardCharsets.US_ASCII).trim();
    }
    private static String printable(byte[] p) {
        if(p==null) return "";
        StringBuilder s=new StringBuilder();
        for(byte b:p) {
            int v=b&0xFF;
            if(v>=32 && v<=126) s.append((char)v);
            else if(v==0 && s.length()>0 && s.charAt(s.length()-1)!=' ') s.append(' ');
        }
        return s.toString().trim();
    }
    private static String hex(byte[] p,int max) {
        if(p==null) return "";
        StringBuilder s=new StringBuilder();
        int n=Math.min(max,p.length);
        for(int i=0;i<n;i++) { if(i>0)s.append(' '); s.append(String.format(Locale.US,"%02X",p[i]&0xFF)); }
        if(p.length>n) s.append(" ...");
        return s.toString();
    }
    private static String safe(String s) { return s==null?"":s.replace('|','/').replace('\n',' '); }
    private static String devName(int t) { return t>=0 && t<DEV_NAMES.length?DEV_NAMES[t]:"DEV"+t; }

    private void finish(StringBuilder r) {
        lastReport=r.toString();
        runOnUiThread(() -> copy.setEnabled(true));
    }
    private void line(StringBuilder r,String s) { r.append(s).append('\n'); }
    private void append(String s) { runOnUiThread(() -> log.append(s+"\n")); }
    private static void sleep(long ms) { try { Thread.sleep(ms); } catch(InterruptedException e) { Thread.currentThread().interrupt(); } }
}

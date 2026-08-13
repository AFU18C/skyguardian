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
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.util.ArrayList;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * v2.6 targeted read-only GNSS neighborhood dump.
 *
 * Why this exists:
 * v2.4 proved the Mini 4K has a 1384-entry E1/E2 FLYC table and found GNSS anchors,
 * while v2.5 proved F7/F8 exposes a separate service/hash view but old DJI guessed names
 * do not reveal the receiver tuning block. This scan therefore stops guessing names and
 * dumps every readable parameter/value in the neighborhoods around the Mini 4K anchors.
 *
 * Commands sent: E0/E1/E2/F7/F8 GET only. No E3/F2/F9, no DF unlock, no E9 exec,
 * no RTK SET, no A0 data injection, no 0x46 push-control command.
 */
public class GnssNeighborhoodDumpActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_NEIGHBOR_PERMISSION";
    private static final int[][] RANGES = new int[][]{
            {330, 480},   // FDI/integrity cluster
            {530, 590},   // exgps/hardware/status neighborhood
            {760, 840},   // gps_enable neighborhood
            {1160, 1210}, // sdk_widget_gps neighborhood
            {1320, 1383}  // gps_fw_status / cfg_save / fcntl / clock tail block
    };
    private static final int[] ANCHORS = new int[]{
            362,363,364,365,366,367,368,369,370,371,372,373,374,413,425,458,460,461,462,
            569,570,571,655,799,1191,1374,1375,1376,1377,1378,1379,1380
    };

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x9800);
    private final AtomicBoolean busy = new AtomicBoolean(false);
    private UsbManager usbManager;
    private TextView log;
    private Button run, copy;
    private volatile boolean pendingPermission;
    private volatile String lastReport = "";

    private static final class Route {
        final int st, si, rt, ri;
        Route(int st, int si, int rt, int ri) { this.st=st; this.si=si; this.rt=rt; this.ri=ri; }
    }
    private static final Route APP_FLYC = new Route(
            DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_FLYCONTROLLER,0);

    private static final class Mode {
        final boolean enc;
        final int entries;
        Mode(boolean enc, int entries) { this.enc=enc; this.entries=entries; }
    }

    private static final class AliasResult {
        final String query;
        final DumlV2.ParamInfo2015 info;
        final byte[] value;
        AliasResult(String query, DumlV2.ParamInfo2015 info, byte[] value) {
            this.query=query; this.info=info; this.value=value;
        }
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
        title.setText("Mini 4K GNSS DEEP NEIGHBOR MAP v2.6");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title,new LinearLayout.LayoutParams(-1,-2));

        run=new Button(this);
        run.setText("DUMP GNSS NEIGHBORHOODS — READ ONLY");
        LinearLayout.LayoutParams rp=new LinearLayout.LayoutParams(-1,-2);
        rp.topMargin=dp(12);
        root.addView(run,rp);

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

        append("ТОЛЬКО ЧТЕНИЕ. Никаких настроек не меняет.");
        append("Не угадывает названия: читает ВСЕ E1/E2 параметры вокруг уже подтверждённых Mini 4K GPS/FDI/service индексов.");
        append("Для подозрительных и хвостовых параметров дополнительно проверяет F7/F8 alias-view.");
        append("WRITE/EXEC: E3=0 F2=0 F9=0 DF=0 E9=0 RTK-SET=0 A0=0 0x46=0.");
        append("");

        run.setOnClickListener(v -> begin());
        copy.setOnClickListener(v -> copyReport());
    }

    private LinearLayout.LayoutParams top(int d) {
        LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);
        p.topMargin=dp(d); return p;
    }
    private int dp(int x) { return Math.round(x*getResources().getDisplayMetrics().density); }

    private void copyReport() {
        if(lastReport==null || lastReport.isEmpty()) return;
        ClipboardManager cm=(ClipboardManager)getSystemService(Context.CLIPBOARD_SERVICE);
        if(cm!=null) {
            cm.setPrimaryClip(ClipData.newPlainText("Mini4K GNSS v2.6 neighborhood map",lastReport));
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
        @Override public void onReceive(Context c,Intent i) {
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
            busy.set(false); return;
        }
        if(!usbManager.hasPermission(a)) {
            requestPermission(a); busy.set(false); return;
        }
        run.setEnabled(false); copy.setEnabled(false); lastReport="";
        io.submit(() -> {
            try { perform(a); }
            catch(Throwable t) { append("SCAN ERROR: "+t.getClass().getSimpleName()+": "+t.getMessage()); }
            finally { busy.set(false); runOnUiThread(() -> run.setEnabled(true)); }
        });
    }

    private void perform(UsbAccessory a) throws Exception {
        runOnUiThread(() -> log.setText(""));
        StringBuilder r=new StringBuilder(384*1024);
        line(r,"MINI 4K GNSS DEEP NEIGHBOR MAP v2.6");
        line(r,"READ ONLY: FLYC E0/E1/E2 + F7/F8 alias checks + passive push capture");
        line(r,"WRITE/EXEC SENT: E3=0 F2=0 F9=0 DF=0 E9=0 RTK-SET=0 A0=0 0x46=0");
        line(r,"");
        append("=== GNSS DEEP NEIGHBOR MAP v2.6 ===");
        append("WRITE/EXEC COMMANDS SENT: 0");
        if(!DumlV2.selfTest()) { line(r,"DUML self-test=FAIL"); finish(r); return; }
        line(r,"DUML self-test=PASS");

        ProbeAoaSession s=ProbeAoaSession.open(usbManager,a,seq);
        if(s==null) { append("AOA pipe не открылся."); line(r,"AOA=FAILED"); finish(r); return; }
        try {
            s.startProtocol(); sleep(350);
            append("AOA OPEN route="+s.routeString());
            line(r,"AOA route="+s.routeString());

            Mode m=detectMode(s,r);
            if(m==null) {
                append("E0 не ответил. Ничего не изменено.");
                line(r,"FATAL: E0 no response"); finish(r); return;
            }
            append("FLYC="+(m.enc?"SIMPLE":"PLAIN")+" table0_entries="+m.entries);
            line(r,"FLYC transport="+(m.enc?"SIMPLE":"PLAIN")+" table0_entries="+m.entries);

            int valid=0, values=0, aliasHits=0, interesting=0;
            for(int[] range:RANGES) {
                int from=Math.max(0,range[0]);
                int to=Math.min(m.entries-1,range[1]);
                if(from>to) continue;
                append("SCAN table0 "+from+".."+to);
                line(r,""); line(r,"=== RANGE "+from+".."+to+" ===");
                for(int idx=from; idx<=to; idx++) {
                    DumlV2.ParamInfo2017 p=readInfo(s,m.enc,idx);
                    if(p==null || p.status!=0 || p.name==null || p.name.isEmpty()) {
                        line(r,"ROW|index="+idx+"|E1=NO_VALID_INFO");
                        continue;
                    }
                    valid++;
                    byte[] v=readIndexRaw(s,m.enc,idx,p.size);
                    if(v!=null) values++;
                    boolean hot=isInteresting(p.name) || nearAnchor(idx,4) || idx>=1360;
                    String aliases="";
                    if(hot) {
                        List<AliasResult> ar=probeAliases(s,m.enc,p.name);
                        aliasHits+=ar.size();
                        aliases=formatAliases(ar);
                    }
                    String row="ROW|index="+idx+"|name="+safe(p.name)+
                            "|type="+p.typeId+"|size="+p.size+
                            "|default="+formatLimit(p.def,p.typeId)+
                            "|min="+formatLimit(p.min,p.typeId)+"|max="+formatLimit(p.max,p.typeId)+
                            "|current="+decode(v,p.typeId)+
                            (aliases.isEmpty()?"":"|F7_ALIASES="+aliases);
                    line(r,row);
                    if(hot) {
                        interesting++;
                        append("*** "+row);
                    }
                    if((idx-from)>0 && (idx-from)%40==0) append("progress "+idx+"/"+to);
                }
            }

            line(r,"");
            line(r,"=== PASSIVE KNOWN GNSS PUSH CAPTURE (NO SUBSCRIPTION COMMAND SENT) ===");
            int pushes=capturePassive(s,r,1800);
            line(r,"PASSIVE_PUSH_MATCHES="+pushes+" (FLYC 0x45 GPS_SNR / 0xA1 AGPS_STATUS only)");

            line(r,"");
            line(r,"=== SUMMARY ===");
            line(r,"valid_E1="+valid+" E2_values="+values+" hot_rows="+interesting+" F7_alias_hits="+aliasHits);
            line(r,"WRITE/EXEC SENT: E3=0 F2=0 F9=0 DF=0 E9=0 RTK-SET=0 A0=0 0x46=0");
            append("=== ГОТОВО === valid="+valid+" hot="+interesting+" aliases="+aliasHits);
            append("Нажми COPY FULL REPORT и пришли текст.");
            append("WRITE/EXEC COMMANDS SENT: 0");
            finish(r);
        } finally {
            s.close();
            append("AOA CLOSED; WRITE/EXEC COMMANDS SENT: 0");
        }
    }

    private Mode detectMode(ProbeAoaSession s,StringBuilder r) throws Exception {
        for(boolean enc:new boolean[]{false,true}) {
            DumlV2.Frame f=transact(s,0xE0,DumlV2.le16(0),enc,1000);
            if(f==null) continue;
            DumlV2.TableAttr2017 a=DumlV2.TableAttr2017.parse(f.payload);
            line(r,"E0|mode="+(enc?"SIMPLE":"PLAIN")+"|raw="+DumlV2.hex(f.payload));
            if(a!=null && a.status==0 && a.tableNo==0 && a.entriesNum>0 && a.entriesNum<20000)
                return new Mode(enc,(int)a.entriesNum);
        }
        return null;
    }

    private DumlV2.ParamInfo2017 readInfo(ProbeAoaSession s,boolean enc,int index) throws Exception {
        byte[] q=DumlV2.concat(DumlV2.le16(0),DumlV2.le16(index));
        DumlV2.Frame f=transact(s,0xE1,q,enc,420);
        return f==null?null:DumlV2.ParamInfo2017.parse(f.payload);
    }

    private byte[] readIndexRaw(ProbeAoaSession s,boolean enc,int index,int size) throws Exception {
        byte[] q=DumlV2.concat(DumlV2.le16(0),DumlV2.le16(1),DumlV2.le16(index));
        DumlV2.Frame f=transact(s,0xE2,q,enc,500);
        if(f==null || f.payload==null || f.payload.length<6) return null;
        if(DumlV2.u16(f.payload,0)!=0 || DumlV2.u16(f.payload,4)!=index) return null;
        int available=f.payload.length-6;
        int n=size>0?Math.min(size,available):available;
        if(n<=0) return new byte[0];
        byte[] v=new byte[n];
        System.arraycopy(f.payload,6,v,0,n);
        return v;
    }

    private List<AliasResult> probeAliases(ProbeAoaSession s,boolean enc,String rawName) throws Exception {
        Set<String> qnames=new LinkedHashSet<>();
        if(rawName!=null && !rawName.isEmpty()) {
            qnames.add(rawName);
            for(String part:rawName.split("\\|")) {
                String x=part.trim();
                if(x.isEmpty()) continue;
                qnames.add(x);
                if(!x.endsWith("_0")) qnames.add(x+"_0");
            }
            if(!rawName.endsWith("_0")) qnames.add(rawName+"_0");
        }
        List<AliasResult> out=new ArrayList<>();
        Set<Long> hashes=new LinkedHashSet<>();
        int attempts=0;
        for(String q:qnames) {
            if(attempts++>=8) break;
            long h=DumlV2.parameterHash(q);
            if(!hashes.add(h)) continue;
            DumlV2.Frame f=transact(s,0xF7,DumlV2.le32(h),enc,230);
            if(f==null) continue;
            DumlV2.ParamInfo2015 p=DumlV2.ParamInfo2015.parse(f.payload);
            if(p==null || p.status!=0 || p.name==null || p.name.isEmpty()) continue;
            byte[] value=readHashRaw(s,enc,h,p.size);
            out.add(new AliasResult(q,p,value));
        }
        return out;
    }

    private byte[] readHashRaw(ProbeAoaSession s,boolean enc,long hash,int size) throws Exception {
        DumlV2.Frame f=transact(s,0xF8,DumlV2.le32(hash),enc,360);
        if(f==null || f.payload==null || f.payload.length<5) return null;
        if((f.payload[0]&0xFF)!=0 || DumlV2.u32(f.payload,1)!=(hash&0xFFFFFFFFL)) return null;
        int available=f.payload.length-5;
        int n=size>0?Math.min(size,available):available;
        if(n<=0) return new byte[0];
        byte[] v=new byte[n]; System.arraycopy(f.payload,5,v,0,n); return v;
    }

    private String formatAliases(List<AliasResult> list) {
        if(list==null || list.isEmpty()) return "";
        StringBuilder b=new StringBuilder();
        Set<String> seen=new LinkedHashSet<>();
        for(AliasResult a:list) {
            String key=a.info.name+"#"+a.info.typeId+"#"+a.info.size;
            if(!seen.add(key)) continue;
            if(b.length()>0) b.append(';');
            b.append("query:").append(safe(a.query))
                    .append(",returned:").append(safe(a.info.name))
                    .append(",attr:0x").append(Integer.toHexString(a.info.attribute))
                    .append(",type:").append(a.info.typeId)
                    .append(",size:").append(a.info.size)
                    .append(",cur:").append(decode(a.value,a.info.typeId));
        }
        return b.toString();
    }

    private int capturePassive(ProbeAoaSession s,StringBuilder r,long ms) throws Exception {
        int n=0;
        long end=System.currentTimeMillis()+ms;
        while(System.currentTimeMillis()<end) {
            DumlV2.Frame f=s.poll(Math.min(100,Math.max(1,end-System.currentTimeMillis())));
            if(f==null) continue;
            if(f.cmdSet==DumlV2.CMDSET_FLYC && (f.cmdId==0x45 || f.cmdId==0xA1)) {
                n++;
                line(r,"PUSH|cmd=0x"+hex2(f.cmdId)+"|sender="+f.senderType+":"+f.senderIndex+
                        "|receiver="+f.receiverType+":"+f.receiverIndex+"|len="+f.payload.length+
                        "|raw="+DumlV2.hex(f.payload));
            }
        }
        return n;
    }

    private DumlV2.Frame transact(ProbeAoaSession s,int id,byte[] payload,boolean enc,int timeoutMs) throws Exception {
        int qseq=seq.getAndIncrement()&0xFFFF;
        s.sendDuml(DumlV2.packet(APP_FLYC.st,APP_FLYC.si,APP_FLYC.rt,APP_FLYC.ri,
                qseq,DumlV2.CMDSET_FLYC,id,payload,enc));
        long end=System.currentTimeMillis()+timeoutMs;
        while(System.currentTimeMillis()<end) {
            DumlV2.Frame f=s.poll(Math.min(70,Math.max(1,end-System.currentTimeMillis())));
            if(f==null || !f.response || f.cmdSet!=DumlV2.CMDSET_FLYC || f.cmdId!=id) continue;
            if(f.seq==qseq) return f;
        }
        return null;
    }

    private boolean nearAnchor(int idx,int d) {
        for(int a:ANCHORS) if(Math.abs(idx-a)<=d) return true;
        return false;
    }

    private boolean isInteresting(String name) {
        if(name==null) return false;
        String n=name.toLowerCase(Locale.US);
        return has(n,"gps","gnss","glonass","galileo","beidou","bds","sat","svn","sv_","fdi","spoof","jamm",
                "hdop","vdop","dop","cno","cn0","snr","lna","agc","rf_","receiver","antenna","frontend","front_end",
                "clock","clk","tcxo","freq","osc","ephemer","almanac","assist","acq","track","fix","nav","position",
                "velocity","pos_","vel_","quality","signal","cfg_save","fcntl","hw_type");
    }

    private static boolean has(String n,String... keys) {
        for(String k:keys) if(n.contains(k)) return true;
        return false;
    }

    private String decode(byte[] v,int type) {
        if(v==null) return "NO_VALUE";
        if(v.length==0) return "EMPTY";
        String raw="raw["+DumlV2.hex(v)+"]";
        if(v.length==1) return raw+" u8="+(v[0]&0xFF);
        if(v.length==2) return raw+" u16="+DumlV2.u16(v,0)+" i16="+(short)DumlV2.u16(v,0);
        if(v.length==4) {
            long u=DumlV2.u32(v,0);
            return raw+" u32="+u+" i32="+(int)u+" f32="+Float.intBitsToFloat((int)u);
        }
        return raw;
    }

    private String formatLimit(long raw,int type) {
        if(type==8 || type==9) return Float.toString(Float.intBitsToFloat((int)raw));
        return Long.toString(raw);
    }

    private void finish(StringBuilder r) {
        lastReport=r.toString();
        runOnUiThread(() -> copy.setEnabled(!lastReport.isEmpty()));
    }
    private static void line(StringBuilder r,String s) { r.append(s).append('\n'); }
    private static String safe(String s) { return s==null?"":s.replace('|','/').replace('\n',' ').replace('\r',' '); }
    private static String hex2(int x) { return String.format(Locale.US,"%02X",x&0xFF); }
    private void append(String s) {
        runOnUiThread(() -> {
            log.append(s+"\n");
            View p=(View)log.getParent();
            if(p instanceof ScrollView) ((ScrollView)p).post(() -> ((ScrollView)p).fullScroll(View.FOCUS_DOWN));
        });
    }
    private static void sleep(long ms) {
        try { Thread.sleep(ms); } catch(InterruptedException e) { Thread.currentThread().interrupt(); }
    }
}

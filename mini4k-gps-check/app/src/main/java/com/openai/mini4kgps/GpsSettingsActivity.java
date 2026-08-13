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

import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * v3.1 factual GPS settings inventory.
 *
 * E1/E2 provide the confirmed table/index identity and current value.
 * F7 metadata is used only for the same already-confirmed name in order to read DJI's
 * attribute/access flags. No parameter writes are sent by this activity.
 */
public class GpsSettingsActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GPS_SETTINGS_PERMISSION";
    private static final int[] INDICES = new int[]{
            362,363,364,365,366,367,368,369,370,371,372,373,374,
            413,425,458,460,461,462,799,1191,
            1374,1375,1376,1377,1378,1379,1380,1381
    };

    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0xE100);
    private final AtomicBoolean busy = new AtomicBoolean(false);
    private UsbManager usbManager;
    private TextView log;
    private Button scan, copy, openEditor;
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
        title.setText("Mini 4K GPS SETTINGS v3.1");
        title.setTextSize(21);
        title.setGravity(Gravity.CENTER);
        root.addView(title,new LinearLayout.LayoutParams(-1,-2));

        TextView note=new TextView(this);
        note.setText("Только уже подтверждённые на этом Mini 4K GPS-параметры. Показывает CURRENT/MIN/MAX/DEFAULT и реальный ATTR из F7 metadata. Имена не угадываются.");
        note.setTextSize(14);
        root.addView(note,top(12));

        scan=new Button(this);
        scan.setText("READ CONFIRMED GPS SETTINGS");
        root.addView(scan,top(12));

        openEditor=new Button(this);
        openEditor.setText("OPEN CONFIRMED GPS ON/OFF EDITOR");
        root.addView(openEditor,top(8));

        copy=new Button(this);
        copy.setText("COPY FULL SETTINGS REPORT");
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

        append("DJI Attribute: 0=READ_ONLY, 1=READ_WRITE, 2=EEPROM_WRITE, 3=EEPROM_RW, 4=EEPROM_SPECIFIC, 8=IMPORT_EXPORT.");
        append("Этот экран не пишет параметры. Подтверждённый gps_enable editor открывается отдельной кнопкой.");

        scan.setOnClickListener(v -> begin());
        openEditor.setOnClickListener(v -> startActivity(new Intent(this,FinalActivity.class)));
        copy.setOnClickListener(v -> copyReport());
    }

    private LinearLayout.LayoutParams top(int d) {
        LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);
        p.topMargin=dp(d); return p;
    }
    private int dp(int x) { return Math.round(x*getResources().getDisplayMetrics().density); }

    private void registerUsbReceiver() {
        IntentFilter f=new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if(Build.VERSION.SDK_INT>=33) registerReceiver(usbReceiver,f,Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver,f);
    }

    private final BroadcastReceiver usbReceiver=new BroadcastReceiver(){
        @Override public void onReceive(Context c,Intent i) {
            if(ACTION_USB_PERMISSION.equals(i.getAction())) {
                boolean ok=i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED,false);
                append(ok?"USB permission: OK":"USB permission: DENIED");
                if(ok && pendingPermission) { pendingPermission=false; begin(); }
            } else if(UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(i.getAction())) {
                append("RC-N1 AOA disconnected");
            }
        }
    };

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] as=usbManager.getAccessoryList();
        if(as==null||as.length==0) return null;
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
        if(a==null) { append("AOA DJI not found. Close DJI Fly and connect phone to RC-N1."); busy.set(false); return; }
        if(!usbManager.hasPermission(a)) { requestPermission(a); busy.set(false); return; }
        scan.setEnabled(false); copy.setEnabled(false); lastReport="";
        io.submit(() -> {
            try { perform(a); }
            catch(Throwable t) { append("ERROR: "+t.getClass().getSimpleName()+": "+t.getMessage()); }
            finally { busy.set(false); runOnUiThread(() -> scan.setEnabled(true)); }
        });
    }

    private void perform(UsbAccessory a) throws Exception {
        runOnUiThread(() -> log.setText(""));
        StringBuilder r=new StringBuilder(64*1024);
        line(r,"MINI 4K GPS SETTINGS v3.1");
        line(r,"FACTUAL LIVE INVENTORY OF PREVIOUSLY CONFIRMED INDICES");
        line(r,"ACCESS SOURCE=F7 metadata for the exact E1-confirmed parameter name");
        line(r,"WRITES_SENT_BY_THIS_SCAN=0");
        line(r,"ATTR: 0=READ_ONLY 1=READ_WRITE 2=EEPROM_WRITE 3=EEPROM_RW 4=EEPROM_SPECIFIC 8=IMPORT_EXPORT");
        line(r,"");

        if(!DumlV2.selfTest()) { line(r,"DUML_SELF_TEST=FAIL"); finish(r); return; }
        ProbeAoaSession s=ProbeAoaSession.open(usbManager,a,seq);
        if(s==null) { line(r,"AOA=FAILED"); finish(r); return; }
        try {
            s.startProtocol(); sleep(350);
            line(r,"AOA="+s.routeString());
            Mode m=detectMode(s);
            if(m==null) { line(r,"FLYC_PARAM_TRANSPORT=NO_RESPONSE"); finish(r); return; }
            line(r,"FLYC_MODE="+(m.enc?"SIMPLE":"PLAIN")+" TABLE0_ENTRIES="+m.entries);
            line(r,"");
            line(r,"=== CONFIRMED GPS SETTINGS ===");

            int ok=0, attrHits=0;
            for(int idx:INDICES) {
                if(idx<0 || idx>=m.entries) continue;
                DumlV2.ParamInfo2017 p=readInfo(s,m.enc,idx);
                if(p==null || p.status!=0 || p.name==null || p.name.isEmpty()) {
                    line(r,"INDEX="+idx+"|NO_VALID_INFO");
                    continue;
                }
                byte[] v=readValue(s,m.enc,idx,p.size);
                DumlV2.ParamInfo2015 meta=readF7Meta(s,m.enc,p.name);
                int attr=meta==null?-1:meta.attribute;
                if(meta!=null) attrHits++;
                String access=attr<0?"F7_NO_METADATA":attrName(attr);
                String control=controlState(p.name,attr);
                String attrText=attr<0?"NA":"0x"+Integer.toHexString(attr).toUpperCase(Locale.US);
                String row="INDEX="+idx+"|NAME="+safe(p.name)+"|TYPE="+p.typeId+"|SIZE="+p.size+
                        "|ATTR="+attrText+"("+access+")"+
                        "|MIN="+formatLimit(p.min,p.typeId)+"|MAX="+formatLimit(p.max,p.typeId)+
                        "|DEFAULT="+formatLimit(p.def,p.typeId)+"|CURRENT="+decode(v,p.typeId)+
                        "|CONTROL="+control;
                line(r,row);
                append(row);
                ok++;
            }
            line(r,"");
            line(r,"=== RESULT ===");
            line(r,"VALID_SETTINGS="+ok+" F7_ATTR_HITS="+attrHits);
            line(r,"EDITABLE_UI_CONFIRMED=gps_enable via existing strict read-identify-write-readback editor");
            line(r,"UNKNOWN_SEMANTICS_AND_FDI_INTEGRITY_WRITES=NOT_EXPOSED_ON_THIS_PAGE");
            line(r,"WRITES_SENT_BY_THIS_SCAN=0");
            append("=== DONE === valid="+ok+" attr="+attrHits);
            finish(r);
        } finally {
            s.close();
            append("AOA CLOSED; scan writes=0");
        }
    }

    private static final class Mode { final boolean enc; final int entries; Mode(boolean e,int n){enc=e;entries=n;} }

    private Mode detectMode(ProbeAoaSession s) throws Exception {
        for(boolean enc:new boolean[]{false,true}) {
            DumlV2.Frame f=transact(s,0xE0,DumlV2.le16(0),enc,1000);
            if(f==null) continue;
            DumlV2.TableAttr2017 a=DumlV2.TableAttr2017.parse(f.payload);
            if(a!=null && a.status==0 && a.tableNo==0 && a.entriesNum>0 && a.entriesNum<20000)
                return new Mode(enc,(int)a.entriesNum);
        }
        return null;
    }

    private DumlV2.ParamInfo2017 readInfo(ProbeAoaSession s,boolean enc,int idx) throws Exception {
        DumlV2.Frame f=transact(s,0xE1,DumlV2.concat(DumlV2.le16(0),DumlV2.le16(idx)),enc,450);
        return f==null?null:DumlV2.ParamInfo2017.parse(f.payload);
    }

    private byte[] readValue(ProbeAoaSession s,boolean enc,int idx,int size) throws Exception {
        byte[] q=DumlV2.concat(DumlV2.le16(0),DumlV2.le16(1),DumlV2.le16(idx));
        DumlV2.Frame f=transact(s,0xE2,q,enc,550);
        if(f==null || f.payload==null || f.payload.length<6) return null;
        if(DumlV2.u16(f.payload,0)!=0 || DumlV2.u16(f.payload,4)!=idx) return null;
        int n=Math.min(Math.max(0,size),f.payload.length-6);
        byte[] out=new byte[n];
        System.arraycopy(f.payload,6,out,0,n);
        return out;
    }

    private DumlV2.ParamInfo2015 readF7Meta(ProbeAoaSession s,boolean enc,String rawName) throws Exception {
        String[] candidates=new String[]{rawName+"_0",rawName};
        for(String q:candidates) {
            long h=DumlV2.parameterHash(q);
            DumlV2.Frame f=transact(s,0xF7,DumlV2.le32(h),enc,420);
            if(f==null) continue;
            DumlV2.ParamInfo2015 p=DumlV2.ParamInfo2015.parse(f.payload);
            if(p==null || p.status!=0 || p.name==null || p.name.isEmpty()) continue;
            String returned=p.name;
            String base=rawName;
            int slash=base.indexOf('/');
            if(slash>=0) base=base.substring(0,slash);
            if(returned.equals(base) || returned.equals(rawName) || rawName.contains(returned)) return p;
        }
        return null;
    }

    private DumlV2.Frame transact(ProbeAoaSession s,int id,byte[] payload,boolean enc,int timeoutMs) throws Exception {
        int qseq=seq.getAndIncrement()&0xFFFF;
        s.sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_FLYCONTROLLER,0,
                qseq,DumlV2.CMDSET_FLYC,id,payload,enc));
        long end=System.currentTimeMillis()+timeoutMs;
        while(System.currentTimeMillis()<end) {
            DumlV2.Frame f=s.poll(Math.min(100,Math.max(1,end-System.currentTimeMillis())));
            if(f==null) continue;
            if(f.response && f.seq==qseq && f.cmdSet==DumlV2.CMDSET_FLYC && f.cmdId==id) return f;
        }
        return null;
    }

    private static String attrName(int a) {
        if(a==0) return "READ_ONLY";
        if(a==1) return "READ_WRITE";
        if(a==2) return "EEPROM_WRITE";
        if(a==3) return "EEPROM_RW";
        if(a==4) return "EEPROM_SPECIFIC";
        if(a==8) return "IMPORT_EXPORT";
        StringBuilder s=new StringBuilder();
        if((a&1)!=0) s.append("READ_WRITE+");
        if((a&2)!=0) s.append("EEPROM_WRITE+");
        if((a&4)!=0) s.append("EEPROM_SPECIFIC+");
        if((a&8)!=0) s.append("IMPORT_EXPORT+");
        if(s.length()>0) s.setLength(s.length()-1);
        return s.length()==0?"UNKNOWN":s.toString();
    }

    private static String controlState(String name,int attr) {
        String n=name.toLowerCase(Locale.US);
        if(n.contains("gps_enable")) return "EDIT_UI_AVAILABLE";
        if(n.contains("fdi") || n.contains("without_gps")) return "LOCKED_SAFETY_INTEGRITY";
        if(attr>=0 && (((attr&1)!=0) || ((attr&2)!=0))) return "PROTOCOL_WRITE_FLAG_PRESENT_NO_UI";
        return "READ_ONLY_OR_UNPROVEN";
    }

    private static String decode(byte[] v,int type) {
        if(v==null) return "NO_RESPONSE";
        if(v.length==0) return "raw[]";
        String raw="raw["+hex(v)+"]";
        ByteBuffer b=ByteBuffer.wrap(v).order(ByteOrder.LITTLE_ENDIAN);
        try {
            switch(type) {
                case 0: return raw+" u8="+(v[0]&0xFF);
                case 1: return raw+" u16="+(b.getShort(0)&0xFFFF);
                case 2: if(v.length>=4) return raw+" u32="+(b.getInt(0)&0xFFFFFFFFL); break;
                case 4: return raw+" i8="+v[0];
                case 5: if(v.length>=2) return raw+" i16="+b.getShort(0); break;
                case 6: if(v.length>=4) return raw+" i32="+b.getInt(0); break;
                case 8: if(v.length>=4) return raw+" f32="+b.getFloat(0); break;
                case 9: if(v.length>=8) return raw+" f64="+b.getDouble(0); break;
            }
        } catch(Exception ignored) {}
        return raw;
    }

    private static String formatLimit(long bits,int type) {
        try {
            if(type==8) return Float.toString(Float.intBitsToFloat((int)bits));
            if(type==9) return Double.toString(Double.longBitsToDouble(bits));
        } catch(Exception ignored) {}
        return Long.toUnsignedString(bits);
    }

    private static String safe(String s) { return s==null?"":s.replace('|','/').replace('\n',' '); }
    private static String hex(byte[] p) {
        if(p==null) return "";
        StringBuilder s=new StringBuilder();
        for(int i=0;i<p.length;i++){ if(i>0)s.append(' '); s.append(String.format(Locale.US,"%02X",p[i]&0xFF)); }
        return s.toString();
    }
    private static void sleep(long ms){ try{Thread.sleep(ms);}catch(InterruptedException e){Thread.currentThread().interrupt();} }

    private void line(StringBuilder r,String s){ r.append(s).append('\n'); }
    private void finish(StringBuilder r){ lastReport=r.toString(); runOnUiThread(() -> copy.setEnabled(true)); }
    private void copyReport(){
        if(lastReport==null||lastReport.isEmpty()) return;
        ClipboardManager cm=(ClipboardManager)getSystemService(Context.CLIPBOARD_SERVICE);
        if(cm!=null){ cm.setPrimaryClip(ClipData.newPlainText("Mini4K GPS settings",lastReport)); append("FULL SETTINGS REPORT COPIED ("+lastReport.length()+" chars)"); }
    }
    private void append(String s){ runOnUiThread(() -> log.append(s+"\n")); }
}

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
 * v2.7 targeted constellation/receiver discovery.
 *
 * Persistent parameter writes: NONE.
 * Reads E0/E1/E2/F7/F8 and temporarily enables FLYC GPS-SNR push (0x46=1),
 * captures raw 0x45 packets, then disables the push again (0x46=0) in finally.
 */
public class GnssConstellationProbeActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_CONSTELLATION_PERMISSION";
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0xA200);
    private final AtomicBoolean busy = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView log;
    private Button run, copy;
    private volatile boolean pendingPermission;
    private volatile String lastReport = "";

    private static final class Route {
        final int st, si, rt, ri;
        Route(int st,int si,int rt,int ri){this.st=st;this.si=si;this.rt=rt;this.ri=ri;}
    }
    private static final Route APP_FLYC = new Route(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_FLYCONTROLLER,0);

    private static final class Mode {
        final boolean enc; final int entries;
        Mode(boolean enc,int entries){this.enc=enc;this.entries=entries;}
    }

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        usbManager=(UsbManager)getSystemService(Context.USB_SERVICE);
        buildUi(); registerUsbReceiver();
    }

    @Override protected void onDestroy() {
        try { unregisterReceiver(usbReceiver); } catch(Exception ignored) {}
        io.shutdownNow(); super.onDestroy();
    }

    private void buildUi() {
        int pad=dp(16);
        LinearLayout root=new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad,pad,pad,pad);
        TextView title=new TextView(this);
        title.setText("Mini 4K GNSS CONSTELLATION PROBE v2.7");
        title.setTextSize(20); title.setGravity(Gravity.CENTER);
        root.addView(title,new LinearLayout.LayoutParams(-1,-2));

        run=new Button(this);
        run.setText("PROBE CONSTELLATIONS / DISABLE_BD — SAFE DIAGNOSTIC");
        LinearLayout.LayoutParams rp=new LinearLayout.LayoutParams(-1,-2); rp.topMargin=dp(12);
        root.addView(run,rp);

        copy=new Button(this); copy.setText("COPY FULL REPORT"); copy.setEnabled(false);
        root.addView(copy,top(8));

        log=new TextView(this); log.setTextSize(12); log.setTextIsSelectable(true); log.setPadding(0,dp(12),0,dp(12));
        ScrollView sc=new ScrollView(this); sc.addView(log,new ScrollView.LayoutParams(-1,-2));
        root.addView(sc,new LinearLayout.LayoutParams(-1,0,1f));
        setContentView(root);

        append("Диагностика на земле. Постоянные параметры НЕ изменяются.");
        append("Проверяет tail 1368..1383, скрытые constellation aliases через F7/F8 и реальные SNR push blocks.");
        append("0x46 используется только временно: SNR stream ON -> capture -> OFF.");
        append("E3/F2/F9/DF/E9/RTK-SET/A0: 0.");
        run.setOnClickListener(v -> begin()); copy.setOnClickListener(v -> copyReport());
    }

    private LinearLayout.LayoutParams top(int d){LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2);p.topMargin=dp(d);return p;}
    private int dp(int x){return Math.round(x*getResources().getDisplayMetrics().density);}

    private void copyReport(){
        if(lastReport==null||lastReport.isEmpty()) return;
        ClipboardManager cm=(ClipboardManager)getSystemService(Context.CLIPBOARD_SERVICE);
        if(cm!=null){cm.setPrimaryClip(ClipData.newPlainText("Mini4K GNSS v2.7",lastReport));append("FULL REPORT COPIED ("+lastReport.length()+" chars)");}
    }

    private void registerUsbReceiver(){
        IntentFilter f=new IntentFilter(); f.addAction(ACTION_USB_PERMISSION); f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if(Build.VERSION.SDK_INT>=33) registerReceiver(usbReceiver,f,Context.RECEIVER_NOT_EXPORTED); else registerReceiver(usbReceiver,f);
    }

    private final BroadcastReceiver usbReceiver=new BroadcastReceiver(){
        @Override public void onReceive(Context c,Intent i){
            if(ACTION_USB_PERMISSION.equals(i.getAction())){
                boolean ok=i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED,false); append(ok?"USB permission: OK":"USB permission: DENIED");
                if(ok&&pendingPermission){pendingPermission=false;begin();}
            } else if(UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(i.getAction())) append("RC-N1 AOA отключён.");
        }
    };

    private UsbAccessory chooseAccessory(){
        UsbAccessory[] as=usbManager.getAccessoryList(); if(as==null||as.length==0)return null;
        for(UsbAccessory a:as) if("DJI".equalsIgnoreCase(a.getManufacturer())) return a; return as[0];
    }
    private void requestPermission(UsbAccessory a){
        pendingPermission=true;
        PendingIntent pi=PendingIntent.getBroadcast(this,0,new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),PendingIntent.FLAG_MUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
        usbManager.requestPermission(a,pi);
    }

    private void begin(){
        if(!busy.compareAndSet(false,true)) return;
        UsbAccessory a=chooseAccessory();
        if(a==null){append("AOA DJI не найден. Закрой DJI Fly и подключи телефон к RC-N1.");busy.set(false);return;}
        if(!usbManager.hasPermission(a)){requestPermission(a);busy.set(false);return;}
        run.setEnabled(false);copy.setEnabled(false);lastReport="";
        io.submit(() -> {try{perform(a);}catch(Throwable t){append("PROBE ERROR: "+t.getClass().getSimpleName()+": "+t.getMessage());}finally{busy.set(false);runOnUiThread(() -> run.setEnabled(true));}});
    }

    private void perform(UsbAccessory a) throws Exception {
        runOnUiThread(() -> log.setText(""));
        StringBuilder r=new StringBuilder(256*1024);
        line(r,"MINI 4K GNSS CONSTELLATION PROBE v2.7");
        line(r,"PERSISTENT WRITES=0; TEMP SNR STREAM CONTROL 0x46 ON/OFF ONLY");
        line(r,"E3=0 F2=0 F9=0 DF=0 E9=0 RTK-SET=0 A0=0");
        append("=== GNSS CONSTELLATION PROBE v2.7 ===");
        if(!DumlV2.selfTest()){line(r,"DUML self-test=FAIL");finish(r);return;}

        ProbeAoaSession s=ProbeAoaSession.open(usbManager,a,seq);
        if(s==null){line(r,"AOA=FAILED");append("AOA pipe не открылся.");finish(r);return;}
        boolean streamEnabled=false;
        try{
            s.startProtocol();sleep(350);append("AOA OPEN route="+s.routeString());line(r,"AOA route="+s.routeString());
            Mode m=detectMode(s,r); if(m==null){append("E0 не ответил.");finish(r);return;}
            append("FLYC="+(m.enc?"SIMPLE":"PLAIN")+" entries="+m.entries);

            line(r,""); line(r,"=== TAIL RAW / PARSED 1368..1383 ===");
            for(int idx=1368;idx<=1383 && idx<m.entries;idx++) dumpTail(s,m.enc,idx,r);

            line(r,""); line(r,"=== CONSTELLATION HASH DICTIONARY F7/F8 ===");
            int hits=probeDictionary(s,m.enc,r); append("F7 constellation/service hits="+hits);

            line(r,""); line(r,"=== TEMP GPS-SNR STREAM CAPTURE ===");
            DumlV2.Frame on=transact(s,0x46,new byte[]{1},false,700);
            if(on==null && m.enc) on=transact(s,0x46,new byte[]{1},true,700);
            streamEnabled=on!=null;
            line(r,"SNR_STREAM_ON_ACK="+(on!=null?DumlV2.hex(on.payload):"NO_RESPONSE"));
            append("SNR stream temporary ON ack="+(on!=null?"YES":"NO"));
            int packets=captureSnr(s,r,5000);
            line(r,"SNR_PACKETS="+packets);

            if(streamEnabled){
                DumlV2.Frame off=transact(s,0x46,new byte[]{0},false,700);
                if(off==null && m.enc) off=transact(s,0x46,new byte[]{0},true,700);
                line(r,"SNR_STREAM_OFF_ACK="+(off!=null?DumlV2.hex(off.payload):"NO_RESPONSE"));
                streamEnabled=false;
            }

            line(r,""); line(r,"=== SUMMARY ===");
            line(r,"F7_HITS="+hits+" PERSISTENT_WRITES=0 TEMP_0x46_ONLY=ON/OFF");
            append("=== ГОТОВО ==="); append("Нажми COPY FULL REPORT и пришли текст.");
            finish(r);
        } finally {
            if(streamEnabled){
                try{transact(s,0x46,new byte[]{0},false,500);}catch(Exception ignored){}
            }
            s.close(); append("AOA CLOSED; persistent parameter writes=0");
        }
    }

    private Mode detectMode(ProbeAoaSession s,StringBuilder r)throws Exception{
        for(boolean enc:new boolean[]{false,true}){
            DumlV2.Frame f=transact(s,0xE0,DumlV2.le16(0),enc,1000); if(f==null)continue;
            DumlV2.TableAttr2017 a=DumlV2.TableAttr2017.parse(f.payload); line(r,"E0 "+(enc?"SIMPLE":"PLAIN")+" raw="+DumlV2.hex(f.payload));
            if(a!=null&&a.status==0&&a.tableNo==0&&a.entriesNum>0&&a.entriesNum<20000)return new Mode(enc,(int)a.entriesNum);
        } return null;
    }

    private void dumpTail(ProbeAoaSession s,boolean enc,int idx,StringBuilder r)throws Exception{
        byte[] q=DumlV2.concat(DumlV2.le16(0),DumlV2.le16(idx));
        DumlV2.Frame f=transact(s,0xE1,q,enc,500);
        if(f==null){line(r,"TAIL|index="+idx+"|E1=NO_RESPONSE");return;}
        DumlV2.ParamInfo2017 p=DumlV2.ParamInfo2017.parse(f.payload);
        if(p==null){line(r,"TAIL|index="+idx+"|E1_RAW="+DumlV2.hex(f.payload)+"|PARSE=NULL");return;}
        byte[] v=(p.status==0&&p.name!=null&&!p.name.isEmpty())?readIndexRaw(s,enc,idx,p.size):null;
        line(r,"TAIL|index="+idx+"|status="+p.status+"|name="+safe(p.name)+"|type="+p.typeId+"|size="+p.size+"|min="+p.min+"|max="+p.max+"|def="+p.def+"|current="+decode(v,p.typeId)+"|E1_RAW="+DumlV2.hex(f.payload));
    }

    private byte[] readIndexRaw(ProbeAoaSession s,boolean enc,int index,int size)throws Exception{
        byte[] q=DumlV2.concat(DumlV2.le16(0),DumlV2.le16(1),DumlV2.le16(index));
        DumlV2.Frame f=transact(s,0xE2,q,enc,500); if(f==null||f.payload==null||f.payload.length<6)return null;
        if(DumlV2.u16(f.payload,0)!=0||DumlV2.u16(f.payload,4)!=index)return null;
        int n=Math.min(size,Math.max(0,f.payload.length-6)); byte[] v=new byte[n]; if(n>0)System.arraycopy(f.payload,6,v,0,n); return v;
    }

    private int probeDictionary(ProbeAoaSession s,boolean enc,StringBuilder r)throws Exception{
        String[] base={
                "disable_bd","disable_bds","disable_beidou","bd_disable","bds_disable","beidou_disable",
                "enable_bd","enable_bds","enable_beidou","bd_enable","bds_enable","beidou_enable",
                "disable_gl","disable_glo","disable_glonass","glo_disable","glonass_disable",
                "enable_gl","enable_glo","enable_glonass","glo_enable","glonass_enable",
                "disable_gal","disable_galileo","gal_disable","galileo_disable",
                "enable_gal","enable_galileo","gal_enable","galileo_enable",
                "disable_gps","gps_disable","enable_gps","gps_enable",
                "constellation_mask","gnss_mask","satellite_mask","gps_system_mask","gnss_mode","satellite_system",
                "gps1_cfg_save","gps2_cfg_save","gps3_cfg_save","gps1_cfg","gps2_cfg","gps3_cfg",
                "gps_fcnt","gps_fw_status","gps_clk_dft_status","start_gps_clk_dft",
                "gps_fix_num","hdop_fix","hdop_good","snr_threshold","cno_threshold","cn0_threshold",
                "lna_enable","lna_gain","agc_enable","agc_gain","rf_gain","receiver_gain","bandwidth",
                "acq_threshold","track_threshold","tracking_threshold","acquisition_threshold"
        };
        Set<String> names=new LinkedHashSet<>();
        for(String b:base){
            names.add(b); names.add(b+"_0");
            names.add("g_config.gps_cfg."+b); names.add("g_config.gps_cfg."+b+"_0");
            names.add("gps_"+b); names.add("gps_"+b+"_0");
            names.add("gnss_"+b); names.add("gnss_"+b+"_0");
        }
        Set<Long> hashes=new LinkedHashSet<>(); int hits=0;
        for(String name:names){
            long h=DumlV2.parameterHash(name); if(!hashes.add(h))continue;
            DumlV2.Frame f=transact(s,0xF7,DumlV2.le32(h),enc,170); if(f==null)continue;
            DumlV2.ParamInfo2015 p=DumlV2.ParamInfo2015.parse(f.payload);
            if(p==null||p.status!=0||p.name==null||p.name.isEmpty())continue;
            byte[] v=readHashRaw(s,enc,h,p.size); hits++;
            String out="F7-HIT|query="+safe(name)+"|returned="+safe(p.name)+"|hash=0x"+hex8(h)+"|attr=0x"+Integer.toHexString(p.attribute)+"|type="+p.typeId+"|size="+p.size+"|min="+p.min+"|max="+p.max+"|default="+p.def+"|current="+decode(v,p.typeId);
            line(r,out); append("*** "+out);
        }
        return hits;
    }

    private byte[] readHashRaw(ProbeAoaSession s,boolean enc,long hash,int size)throws Exception{
        DumlV2.Frame f=transact(s,0xF8,DumlV2.le32(hash),enc,350); if(f==null||f.payload==null||f.payload.length<5)return null;
        if((f.payload[0]&0xFF)!=0||DumlV2.u32(f.payload,1)!=(hash&0xFFFFFFFFL))return null;
        int n=Math.min(size,Math.max(0,f.payload.length-5)); byte[] v=new byte[n]; if(n>0)System.arraycopy(f.payload,5,v,0,n); return v;
    }

    private int captureSnr(ProbeAoaSession s,StringBuilder r,long ms)throws Exception{
        int n=0; long end=System.currentTimeMillis()+ms;
        while(System.currentTimeMillis()<end){
            DumlV2.Frame f=s.poll(Math.min(120,Math.max(1,end-System.currentTimeMillis()))); if(f==null)continue;
            if(f.cmdSet!=DumlV2.CMDSET_FLYC||f.cmdId!=0x45)continue;
            n++; byte[] p=f.payload==null?new byte[0]:f.payload;
            line(r,"SNR|packet="+n+"|len="+p.length+"|GPS="+blockStats(p,0)+"|GLONASS="+blockStats(p,32)+"|BLOCK3="+blockStats(p,64)+"|BLOCK4="+blockStats(p,96)+"|raw="+DumlV2.hex(p));
            if(n<=3) append("SNR packet len="+p.length+" GPS="+blockStats(p,0)+" GLONASS="+blockStats(p,32)+" B3="+blockStats(p,64)+" B4="+blockStats(p,96));
        } return n;
    }

    private String blockStats(byte[] p,int off){
        if(p==null||off>=p.length)return "ABSENT";
        int end=Math.min(p.length,off+32),nonzero=0,used=0,max=0;
        for(int i=off;i<end;i++){int x=p[i]&0xFF;int s=x&0x7F;if(s>0)nonzero++;if((x&0x80)!=0)used++;if(s>max)max=s;}
        return "bytes="+(end-off)+",nonzero="+nonzero+",used="+used+",max="+max;
    }

    private DumlV2.Frame transact(ProbeAoaSession s,int id,byte[] payload,boolean enc,int timeoutMs)throws Exception{
        int qseq=seq.getAndIncrement()&0xFFFF;
        s.sendDuml(DumlV2.packet(APP_FLYC.st,APP_FLYC.si,APP_FLYC.rt,APP_FLYC.ri,qseq,DumlV2.CMDSET_FLYC,id,payload,enc));
        long end=System.currentTimeMillis()+timeoutMs;
        while(System.currentTimeMillis()<end){
            DumlV2.Frame f=s.poll(Math.min(70,Math.max(1,end-System.currentTimeMillis())));
            if(f==null||!f.response||f.cmdSet!=DumlV2.CMDSET_FLYC||f.cmdId!=id)continue;
            if(f.seq==qseq)return f;
        } return null;
    }

    private String decode(byte[] v,int type){
        if(v==null)return "NO_VALUE";if(v.length==0)return "EMPTY";String raw="raw["+DumlV2.hex(v)+"]";
        if(v.length==1)return raw+" u8="+(v[0]&0xFF);
        if(v.length==2)return raw+" u16="+DumlV2.u16(v,0);
        if(v.length==4){long u=DumlV2.u32(v,0);return raw+" u32="+u+" i32="+(int)u+" f32="+Float.intBitsToFloat((int)u);}return raw;
    }

    private void finish(StringBuilder r){lastReport=r.toString();runOnUiThread(() -> copy.setEnabled(!lastReport.isEmpty()));}
    private static void line(StringBuilder r,String s){r.append(s).append('\n');}
    private static String safe(String s){return s==null?"":s.replace('|','/').replace('\n',' ').replace('\r',' ');}
    private static String hex8(long v){return String.format(Locale.US,"%08X",v&0xFFFFFFFFL);}
    private void append(String s){runOnUiThread(() -> {log.append(s+"\n");View p=(View)log.getParent();if(p instanceof ScrollView)((ScrollView)p).post(() -> ((ScrollView)p).fullScroll(View.FOCUS_DOWN));});}
    private static void sleep(long ms){try{Thread.sleep(ms);}catch(InterruptedException e){Thread.currentThread().interrupt();}}
}

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
import android.os.ParcelFileDescriptor;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.io.ByteArrayOutputStream;
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
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Deep read-only GNSS parameter discovery for Mini 4K.
 *
 * Uses only:
 *  - E0: determine current FLYC parameter transport / table size
 *  - F0: legacy/hidden Get Param Info by raw index
 *  - F7: Get Param Info by exact parameter hash
 *  - F8: Read Param by hash for confirmed F7 hits
 *
 * NEVER sends E3/F2/F9 writes, 0xDF unlock, E9 exec, RTK SET or any receiver write.
 */
public class HiddenGnssParamActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.HIDDEN_GNSS_PERMISSION";
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x9100);
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
            DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0);

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        usbManager = (UsbManager)getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
    }

    @Override protected void onDestroy() {
        try { unregisterReceiver(usbReceiver); } catch (Exception ignored) {}
        io.shutdownNow();
        super.onDestroy();
    }

    private void buildUi() {
        int pad = dp(16);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(pad,pad,pad,pad);

        TextView title = new TextView(this);
        title.setText("Mini 4K HIDDEN GNSS PARAM DISCOVERY v2.5");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1,-2));

        run = new Button(this);
        run.setText("DEEP HIDDEN GPS PARAM SCAN — READ ONLY");
        LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(-1,-2);
        rp.topMargin = dp(12);
        root.addView(run,rp);

        copy = new Button(this);
        copy.setText("COPY FULL REPORT");
        copy.setEnabled(false);
        root.addView(copy, top(8));

        log = new TextView(this);
        log.setTextSize(12);
        log.setTextIsSelectable(true);
        log.setPadding(0,dp(12),0,dp(12));
        ScrollView sc = new ScrollView(this);
        sc.addView(log,new ScrollView.LayoutParams(-1,-2));
        root.addView(sc,new LinearLayout.LayoutParams(-1,0,1f));
        setContentView(root);

        append("ТОЛЬКО ЧТЕНИЕ. Моторы OFF.");
        append("Ищет то, что v2.4 не видел: F0 hidden/legacy index metadata + F7/F8 hash probes.");
        append("Цель: GPS fix/HDOP, FDI thresholds, constellation/channel config, RF/LNA/AGC, acquisition/tracking, clock, A-GPS и скрытые gps*_cfg.");
        append("WRITE/EXEC: E3=0 F2=0 F9=0 DF=0 E9=0 RTK-SET=0.");
        append("");

        run.setOnClickListener(v -> begin());
        copy.setOnClickListener(v -> copyReport());
    }

    private LinearLayout.LayoutParams top(int d) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1,-2);
        p.topMargin = dp(d);
        return p;
    }
    private int dp(int x) { return Math.round(x * getResources().getDisplayMetrics().density); }

    private void copyReport() {
        if (lastReport == null || lastReport.isEmpty()) return;
        ClipboardManager cm=(ClipboardManager)getSystemService(Context.CLIPBOARD_SERVICE);
        if (cm != null) {
            cm.setPrimaryClip(ClipData.newPlainText("Mini4K hidden GNSS params", lastReport));
            append("FULL REPORT COPIED ("+lastReport.length()+" chars)");
        }
    }

    private void registerUsbReceiver() {
        IntentFilter f=new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if (Build.VERSION.SDK_INT>=33) registerReceiver(usbReceiver,f,Context.RECEIVER_NOT_EXPORTED);
        else registerReceiver(usbReceiver,f);
    }

    private final BroadcastReceiver usbReceiver=new BroadcastReceiver() {
        @Override public void onReceive(Context c, Intent i) {
            if (ACTION_USB_PERMISSION.equals(i.getAction())) {
                boolean ok=i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED,false);
                append(ok?"USB permission: OK":"USB permission: DENIED");
                if (ok && pendingPermission) { pendingPermission=false; begin(); }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(i.getAction())) append("RC-N1 AOA отключён.");
        }
    };

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] as=usbManager.getAccessoryList();
        if (as==null || as.length==0) return null;
        for (UsbAccessory a:as) if ("DJI".equalsIgnoreCase(a.getManufacturer())) return a;
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
        if (!busy.compareAndSet(false,true)) return;
        UsbAccessory a=chooseAccessory();
        if (a==null) {
            append("AOA DJI не найден. Закрой DJI Fly и подключи телефон к верхнему порту RC-N1.");
            busy.set(false); return;
        }
        if (!usbManager.hasPermission(a)) {
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
        StringBuilder r=new StringBuilder(256*1024);
        line(r,"MINI 4K HIDDEN GNSS PARAM DISCOVERY v2.5");
        line(r,"READ ONLY: E0 + F0 + F7 + F8");
        line(r,"WRITE/EXEC SENT: E3=0 F2=0 F9=0 DF=0 E9=0 RTK-SET=0");
        line(r,"");
        append("=== HIDDEN GNSS SCAN v2.5 ===");
        append("WRITE/EXEC COMMANDS SENT: 0");
        if (!DumlV2.selfTest()) { line(r,"DUML self-test=FAIL"); finish(r); return; }
        line(r,"DUML self-test=PASS");

        AoaSession s=AoaSession.open(usbManager,a,seq);
        if (s==null) { append("AOA pipe не открылся."); line(r,"AOA=FAILED"); finish(r); return; }
        try {
            s.startProtocol(); sleep(350);
            append("AOA OPEN route="+s.routeString());
            line(r,"AOA route="+s.routeString());

            Mode m=detectMode(s,r);
            if (m==null) {
                append("E0 transport не найден. Ничего не записано.");
                line(r,"FATAL: E0 no response"); finish(r); return;
            }
            append("FLYC transport="+(m.enc?"SIMPLE":"PLAIN")+" table0_entries="+m.entries);
            line(r,"FLYC transport="+(m.enc?"SIMPLE":"PLAIN")+" table0_entries="+m.entries);

            append("[1/2] Проверяю известные и предполагаемые скрытые GNSS имена через F7/F8...");
            int hashHits=probeHashDictionary(s,m.enc,r);
            append("F7 confirmed hits="+hashHits);

            append("[2/2] Проверяю второй DJI metadata path F0 по raw index...");
            int f0Hits=scanF0(s,m.enc,m.entries,r);
            append("F0 GNSS/RF hits="+f0Hits);

            line(r,"");
            line(r,"=== SUMMARY ===");
            line(r,"F7_confirmed="+hashHits+" F0_GNSS_RF_hits="+f0Hits+" RX_DUML="+s.dumlFrames());
            line(r,"WRITE/EXEC SENT: E3=0 F2=0 F9=0 DF=0 E9=0 RTK-SET=0");
            append("=== ГОТОВО === F7="+hashHits+" F0="+f0Hits);
            append("Нажми COPY FULL REPORT и пришли текст.");
            append("WRITE/EXEC COMMANDS SENT: 0");
            finish(r);
        } finally {
            s.close();
            append("AOA CLOSED; WRITE/EXEC COMMANDS SENT: 0");
        }
    }

    private static final class Mode {
        final boolean enc; final int entries;
        Mode(boolean enc,int entries){this.enc=enc;this.entries=entries;}
    }

    private Mode detectMode(AoaSession s,StringBuilder r) throws Exception {
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

    private int probeHashDictionary(AoaSession s,boolean enc,StringBuilder r) throws Exception {
        Set<Long> seen=new HashSet<>();
        int hits=0;
        for(String name:candidates()) {
            long h=DumlV2.parameterHash(name);
            if(!seen.add(h)) continue;
            DumlV2.Frame f=transact(s,0xF7,DumlV2.le32(h),enc,240);
            if(f==null) continue;
            DumlV2.ParamInfo2015 p=DumlV2.ParamInfo2015.parse(f.payload);
            if(p==null || p.status!=0 || p.name==null || p.name.isEmpty()) {
                line(r,"F7-RAW|query="+safe(name)+"|hash=0x"+hex8(h)+"|raw="+DumlV2.hex(f.payload));
                continue;
            }
            hits++;
            byte[] value=readHashRaw(s,enc,h,p.size);
            String cat=classify(p.name);
            String out="F7-HIT|query="+safe(name)+"|returned="+safe(p.name)+"|hash=0x"+hex8(h)+
                    "|category="+(cat==null?"OTHER":cat)+"|type="+p.typeId+"|size="+p.size+
                    "|attr=0x"+Integer.toHexString(p.attribute)+"|min="+p.min+"|max="+p.max+"|default="+p.def+
                    "|current="+decode(value,p.typeId)+"|read=F8|write_pair=F9 NOT_SENT";
            line(r,out); append("*** "+out);
        }
        return hits;
    }

    private int scanF0(AoaSession s,boolean enc,int entries,StringBuilder r) throws Exception {
        int[] probes={0,1,128,352,353,388,799,1191,1374,1378};
        boolean alive=false;
        for(int idx:probes) {
            DumlV2.Frame f=transact(s,0xF0,DumlV2.le16(idx),enc,350);
            if(f!=null) {
                alive=true;
                DumlV2.ParamInfo2015 p=DumlV2.ParamInfo2015.parse(f.payload);
                line(r,"F0-PROBE|index="+idx+"|raw="+DumlV2.hex(f.payload)+"|name="+(p==null?"":safe(p.name)));
                if(p!=null && p.status==0 && interesting(p.name)) {
                    byte[] v=readHashRaw(s,enc,DumlV2.parameterHash(p.name),p.size);
                    line(r,formatF0(idx,p,v));
                }
            }
        }
        if(!alive) {
            // Some platforms use the opposite crypto mode for old F0/F7 even when E0 is on the new path.
            boolean alt=!enc;
            for(int idx:probes) {
                DumlV2.Frame f=transact(s,0xF0,DumlV2.le16(idx),alt,350);
                if(f!=null) { alive=true; enc=alt; line(r,"F0 transport override="+(enc?"SIMPLE":"PLAIN")); break; }
            }
        }
        if(!alive) { line(r,"F0=NO_RESPONSE on both transport modes"); return 0; }

        int max=Math.min(Math.max(entries+256,1600),2600);
        int hits=0, valid=0, noReply=0;
        for(int i=0;i<max;i++) {
            DumlV2.Frame f=transact(s,0xF0,DumlV2.le16(i),enc,150);
            if(f==null) {
                noReply++;
                if(i>entries && noReply>=48) break;
                continue;
            }
            noReply=0;
            DumlV2.ParamInfo2015 p=DumlV2.ParamInfo2015.parse(f.payload);
            if(p==null || p.status!=0 || p.name==null || p.name.isEmpty()) continue;
            valid++;
            if(interesting(p.name)) {
                hits++;
                long h=DumlV2.parameterHash(p.name);
                byte[] value=readHashRaw(s,enc,h,p.size);
                String out=formatF0(i,p,value);
                line(r,out); append("*** "+out);
            }
            if(i>0 && i%200==0) append("F0 progress "+i+"/"+max+" valid="+valid+" GPS/RF="+hits);
        }
        line(r,"F0-STATS|valid_names="+valid+"|interesting="+hits+"|scan_max="+max);
        return hits;
    }

    private String formatF0(int index,DumlV2.ParamInfo2015 p,byte[] value) {
        return "F0-HIT|index="+index+"|name="+safe(p.name)+"|category="+classify(p.name)+
                "|hash=0x"+hex8(DumlV2.parameterHash(p.name))+"|type="+p.typeId+"|size="+p.size+
                "|attr=0x"+Integer.toHexString(p.attribute)+"|min="+p.min+"|max="+p.max+"|default="+p.def+
                "|current="+decode(value,p.typeId)+"|metadata=F0|value=F8-if-hash-valid|write_pair=F9 NOT_SENT";
    }

    private byte[] readHashRaw(AoaSession s,boolean enc,long hash,int size) throws Exception {
        DumlV2.Frame f=transact(s,0xF8,DumlV2.le32(hash),enc,420);
        if(f==null || f.payload==null || f.payload.length<5) return null;
        if((f.payload[0]&0xFF)!=0 || DumlV2.u32(f.payload,1)!=(hash&0xFFFFFFFFL)) return null;
        int available=f.payload.length-5;
        int n=size>0?Math.min(size,available):available;
        if(n<=0) return new byte[0];
        byte[] v=new byte[n]; System.arraycopy(f.payload,5,v,0,n); return v;
    }

    private boolean interesting(String name) {
        return classify(name)!=null;
    }

    private String classify(String name) {
        if(name==null) return null;
        String n=name.toLowerCase(Locale.US);
        boolean gps=has(n,"gps","gnss","glonass","galileo","beidou","bds","satellite","ephemer","almanac","agps","a_gps");
        if(has(n,"spoof","jamm","integrity") || (gps && has(n,"fdi","disagree","conform","signature","frequency_err","drift","predict_error","stdvar","mismatch"))) return "ANTI-JAM/SPOOF/FDI";
        if(gps && has(n,"glonass","galileo","beidou","bds","constellation","mask","channel","system")) return "CONSTELLATION/CHANNEL";
        if(has(n,"lna","agc","rf_gain","receiver_gain","noise_floor") || (gps && has(n,"sensitivity","gain","frontend"))) return "RF/LNA/AGC";
        if(gps && has(n,"acq","acquisition","track","tracking","ttff","hot_start","warm_start","cold_start")) return "ACQUISITION/TRACKING";
        if(gps && has(n,"snr","cno","cn0","hdop","fix_num","level_low","quality")) return "FIX/SIGNAL/QUALITY";
        if(gps && has(n,"clock","clk","tcxo","frequency","freq","pps","dft")) return "CLOCK/FREQUENCY";
        if(gps && has(n,"agps","a_gps","ephemer","almanac","assist")) return "A-GPS/ASSIST";
        if(gps && has(n,"cfg","config","enable","fw_status","fcntl","save","hw_type")) return "GPS-CONFIG/SERVICE";
        if(gps) return "GPS/OTHER";
        if(has(n,"ubx","ublox","skytraq","unicore","cas_ic","casic","mtk","mediatek","gnss_","receiver_")) return "GNSS-CHIP/RECEIVER";
        return null;
    }

    private static boolean has(String n,String... ks) { for(String k:ks) if(n.contains(k)) return true; return false; }

    private String decode(byte[] v,int type) {
        if(v==null) return "NO_VALUE";
        if(v.length==0) return "EMPTY";
        String raw="raw["+DumlV2.hex(v)+"]";
        if(v.length==1) return raw+" u8="+(v[0]&0xFF);
        if(v.length==2) return raw+" u16="+DumlV2.u16(v,0);
        if(v.length==4) {
            long u=DumlV2.u32(v,0);
            return raw+" u32="+u+" i32="+(int)u+" f32="+Float.intBitsToFloat((int)u);
        }
        return raw;
    }

    private List<String> candidates() {
        List<String> x=new ArrayList<>();
        String[] exact={
                "g_config.gps_cfg.gps_enable_0","g_config.gps_cfg.gps_fix_num_0","g_config.gps_cfg.hdop_fix_0","g_config.gps_cfg.hdop_good_0",
                "g_config.fdi.gps_intergrate_predict_time_0","g_config.fdi.gps_max_pos_predict_error_0",
                "g_config.fdi.gps_max_horizontal_vel_mod_0","g_config.fdi.gps_min_horizontal_vel_stdvar_0",
                "g_config.fdi.gps_max_horizontal_vel_diff_0","g_config.fdi.gps_max_horizontal_vel_over_count_0",
                "g_config.fdi.gps_max_horizontal_pos_mod_0","g_config.fdi.gps_min_horizontal_pos_stdvar_0",
                "g_config.fdi.gps_max_horizontal_pos_diff_0","g_config.fdi.gps_max_horizontal_pos_over_count_0",
                "gps_fw_status","gps1_cfg_save","gps2_cfg_save","gps3_cfg_save","gps_fcntl","gps_clk_dft_status","start_gps_clk_dft",
                "g_status.exgps0_hw_type","g_status.exgps1_hw_type","g_status.exgps2_hw_type"
        };
        for(String s:exact) { x.add(s); if(s.endsWith("_0")) x.add(s.substring(0,s.length()-2)); else x.add(s+"_0"); }

        String[] fields={
                "glonass_enable","galileo_enable","beidou_enable","bds_enable","gnss_enable","gnss_mode","constellation_mask","gnss_mask","satellite_system","channel_mask",
                "lna_enable","lna_gain","agc_enable","agc_gain","rf_gain","receiver_gain","sensitivity","noise_floor","bandwidth",
                "acq_threshold","acquisition_threshold","track_threshold","tracking_threshold","ttff","hot_start","warm_start","cold_start",
                "snr_min","snr_threshold","cno_threshold","cn0_threshold","fix_num","hdop_fix","hdop_good",
                "clock_drift","tcxo","tcxo_enable","pps_enable","frequency_error","frequency_threshold",
                "agps_enable","a_gps_enable","ephemeris","almanac","assist_enable","spoof_threshold","jamming_threshold","jam_threshold","integrity_threshold"
        };
        for(String f:fields) {
            x.add("g_config.gps_cfg."+f+"_0");
            x.add("g_config.gps_cfg."+f);
            x.add("gps_"+f+"_0");
            x.add("gps_"+f);
            x.add("gnss_"+f+"_0");
            x.add("gnss_"+f);
        }
        return x;
    }

    private DumlV2.Frame transact(AoaSession s,int id,byte[] payload,boolean enc,int timeoutMs) throws Exception {
        int qseq=seq.getAndIncrement()&0xFFFF;
        s.clearQueue();
        s.sendDuml(DumlV2.packet(APP_FLYC.st,APP_FLYC.si,APP_FLYC.rt,APP_FLYC.ri,qseq,DumlV2.CMDSET_FLYC,id,payload,enc));
        long end=System.currentTimeMillis()+timeoutMs;
        DumlV2.Frame fallback=null;
        while(System.currentTimeMillis()<end) {
            DumlV2.Frame f=s.poll(Math.min(70,Math.max(1,end-System.currentTimeMillis())));
            if(f==null || !f.response || f.cmdSet!=DumlV2.CMDSET_FLYC || f.cmdId!=id) continue;
            boolean reverse=f.senderType==APP_FLYC.rt && f.receiverType==APP_FLYC.st;
            if(f.seq==qseq && reverse) return f;
            if(f.seq==qseq) return f;
            if(reverse && fallback==null) fallback=f;
        }
        return fallback;
    }

    private void finish(StringBuilder r) { lastReport=r.toString(); runOnUiThread(() -> copy.setEnabled(!lastReport.isEmpty())); }
    private static void line(StringBuilder r,String s){r.append(s).append('\n');}
    private static String safe(String s){return s==null?"":s.replace('|','/').replace('\n',' ').replace('\r',' ');}
    private static String hex8(long v){return String.format(Locale.US,"%08X",v&0xFFFFFFFFL);}
    private void append(String s){runOnUiThread(() -> {log.append(s+"\n"); View p=(View)log.getParent(); if(p instanceof ScrollView)((ScrollView)p).post(() -> ((ScrollView)p).fullScroll(View.FOCUS_DOWN));});}
    private static void sleep(long ms){try{Thread.sleep(ms);}catch(InterruptedException e){Thread.currentThread().interrupt();}}

    private static final class AoaSession {
        private final ParcelFileDescriptor pfd;
        private final FileInputStream in;
        private final FileOutputStream out;
        private final AtomicInteger seq;
        private final AtomicBoolean running=new AtomicBoolean(true);
        private final LinkedBlockingQueue<DumlV2.Frame> rx=new LinkedBlockingQueue<>(5000);
        private final Object writeLock=new Object();
        private final Thread reader;
        private Thread keepalive;
        private volatile int route=0x5749;
        private volatile long dumlFrames;
        private int headerPos;
        private final byte[] header=new byte[8];
        private long bodyLeft;
        private int bodyType=-1;
        private ByteArrayOutputStream body;

        static AoaSession open(UsbManager manager,UsbAccessory a,AtomicInteger sequence){
            try{ParcelFileDescriptor p=manager.openAccessory(a);return p==null?null:new AoaSession(p,sequence);}catch(Exception e){return null;}
        }
        private AoaSession(ParcelFileDescriptor p,AtomicInteger sequence){
            pfd=p;seq=sequence;in=new FileInputStream(p.getFileDescriptor());out=new FileOutputStream(p.getFileDescriptor());
            reader=new Thread(this::readLoop,"mini4k-hidden-gnss-rx");reader.setDaemon(true);reader.start();
        }
        void startProtocol() throws IOException {
            byte[] boot=new byte[]{0,0,1};
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_AIRCRAFT_PROXY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(4);
            sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_ANY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false));
            sleep(8);startKeepalive();
        }
        private void startKeepalive(){
            keepalive=new Thread(() -> {sleep(2500);byte[] p=new byte[]{1,1,0,(byte)0xFF,(byte)0xFF,0x20,0,0};while(running.get()){
                try{sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_REMOTE_RADIO,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false));sleep(4);
                    sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,14,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false));}catch(Exception e){break;}sleep(2500);
            }},"mini4k-hidden-keepalive");keepalive.setDaemon(true);keepalive.start();
        }
        void clearQueue(){rx.clear();}
        DumlV2.Frame poll(long ms)throws InterruptedException{return rx.poll(ms,TimeUnit.MILLISECONDS);}
        long dumlFrames(){return dumlFrames;}
        String routeString(){return String.format(Locale.US,"0x%04X",route);}
        void sendDuml(byte[] duml)throws IOException{
            int n=duml.length;byte[] w=new byte[8+n];w[0]=0x55;w[1]=(byte)0xCC;w[2]=(byte)(route&0xFF);w[3]=(byte)((route>>>8)&0xFF);
            w[4]=(byte)(n&0xFF);w[5]=(byte)((n>>>8)&0xFF);w[6]=(byte)((n>>>16)&0xFF);w[7]=(byte)((n>>>24)&0xFF);System.arraycopy(duml,0,w,8,n);
            synchronized(writeLock){out.write(w);out.flush();}sleep(3);
        }
        private void readLoop(){byte[] b=new byte[16384];try{while(running.get()){int n=in.read(b);if(n<0)break;if(n>0)feed(b,n);}}catch(Exception ignored){}finally{running.set(false);}}
        private void feed(byte[] a,int n){
            for(int i=0;i<n;i++){int x=a[i]&0xFF;if(bodyLeft>0){if(body!=null)body.write(x);bodyLeft--;if(bodyLeft==0)finishUnit();continue;}
                if(headerPos==0){if(x==0x55){header[0]=0x55;headerPos=1;}continue;}
                if(headerPos==1){if(x==0xCC){header[1]=(byte)0xCC;headerPos=2;}else if(x==0x55){header[0]=0x55;headerPos=1;}else headerPos=0;continue;}
                header[headerPos++]=(byte)x;if(headerPos==8){int type=(header[2]&0xFF)|((header[3]&0xFF)<<8);long len=((long)header[4]&0xFF)|(((long)header[5]&0xFF)<<8)|(((long)header[6]&0xFF)<<16)|(((long)header[7]&0xFF)<<24);headerPos=0;
                    if(len<0||len>0x200000L){bodyLeft=0;body=null;bodyType=-1;continue;}bodyType=type;bodyLeft=len;if(type==0x5749||type==0x7530){route=type;body=new ByteArrayOutputStream((int)Math.min(len,16384));}else body=null;if(bodyLeft==0)finishUnit();}}
        }
        private void finishUnit(){if((bodyType==0x5749||bodyType==0x7530)&&body!=null){for(DumlV2.Frame f:DumlV2.frames(body.toByteArray())){dumlFrames++;if(!rx.offer(f)){rx.poll();rx.offer(f);}}}bodyType=-1;body=null;bodyLeft=0;}
        void close(){running.set(false);try{if(keepalive!=null)keepalive.interrupt();}catch(Exception ignored){}try{reader.interrupt();}catch(Exception ignored){}try{in.close();}catch(Exception ignored){}try{out.close();}catch(Exception ignored){}try{pfd.close();}catch(Exception ignored){}}
    }
}

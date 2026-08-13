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

import java.util.LinkedHashSet;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * v2.8 read-mostly GNSS integrity/source diagnostic.
 *
 * Persistent FC parameter writes: NONE.
 * Temporary control: FLYC 0x46 GPS-SNR push ON, then OFF in finally.
 * Reads E0/E1/E2/F7/F8 and raw 0x45 push payloads.
 */
public class GnssIntegritySourceProbeActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_INTEGRITY_SOURCE_PERMISSION";
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0xB200);
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
    private static final Route APP_FLYC = new Route(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_FLYCONTROLLER,0);

    private static final class Mode {
        final boolean enc;
        final int entries;
        Mode(boolean enc, int entries) { this.enc=enc; this.entries=entries; }
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
        title.setText("Mini 4K GNSS INTEGRITY / SOURCE PROBE v2.8");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title,new LinearLayout.LayoutParams(-1,-2));

        run=new Button(this);
        run.setText("RUN DEEP GNSS SOURCE + INTEGRITY TEST — SAFE DIAGNOSTIC");
        LinearLayout.LayoutParams rp=new LinearLayout.LayoutParams(-1,-2);
        rp.topMargin=dp(12);
        root.addView(run,rp);

        copy=new Button(this);
        copy.setText("COPY FULL REPORT");
        copy.setEnabled(false);
        root.addView(copy,top(8));

        TextView note=new TextView(this);
        note.setText("Постоянные параметры не меняет. Проверяет GPS FDI/integrity, disable_bd/source-mode aliases, tail 1368..1383 и RAW 160-byte SNR packets. 0x46 только временно ON->capture->OFF.");
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

        append("READ-ONLY FC PARAMS. E3/F2/F9/DF/E9/RTK-SET/A0 = 0.");
        append("SNR count in v2.8 = number of non-zero slots; raw bytes are preserved.");

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
            cm.setPrimaryClip(ClipData.newPlainText("Mini4K GNSS v2.8",lastReport));
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
        StringBuilder r=new StringBuilder(320*1024);
        line(r,"MINI 4K GNSS INTEGRITY / SOURCE PROBE v2.8");
        line(r,"PERSISTENT PARAMETER WRITES=0");
        line(r,"TEMP CONTROL=FLYC 0x46 GPS-SNR PUSH ON/OFF ONLY");
        line(r,"E3=0 F2=0 F9=0 DF=0 E9=0 RTK-SET=0 A0=0");
        append("=== GNSS INTEGRITY / SOURCE PROBE v2.8 ===");

        if(!DumlV2.selfTest()) {
            line(r,"DUML self-test=FAIL");
            finish(r);
            return;
        }

        ProbeAoaSession s=ProbeAoaSession.open(usbManager,a,seq);
        if(s==null) {
            append("AOA pipe не открылся.");
            line(r,"AOA=FAILED");
            finish(r);
            return;
        }

        boolean streamEnabled=false;
        try {
            s.startProtocol();
            sleep(350);
            append("AOA OPEN route="+s.routeString());
            line(r,"AOA route="+s.routeString());

            Mode m=detectMode(s,r);
            if(m==null) {
                append("E0 не ответил. Ничего не изменено.");
                finish(r);
                return;
            }
            append("FLYC="+(m.enc?"SIMPLE":"PLAIN")+" entries="+m.entries);

            line(r,"");
            line(r,"=== GPS INTEGRITY / FDI CURRENT STATE ===");
            int[] important={362,363,364,365,366,367,368,369,370,371,372,373,374,413,425,458,460,461,462,799,1374,1375,1376,1377,1378,1379,1380,1381,1382,1383};
            for(int idx:important) {
                if(idx<m.entries) dumpIndex(s,m.enc,idx,r,true);
            }

            line(r,"");
            line(r,"=== MODERN GNSS/SOURCE-MODE HASH DICTIONARY ===");
            int hashHits=probeDictionary(s,m.enc,r);
            append("F7 source/integrity hits="+hashHits);

            line(r,"");
            line(r,"=== TEMP RAW GPS-SNR STREAM ===");
            DumlV2.Frame on=transact(s,0x46,new byte[]{1},false,700);
            if(on==null && m.enc) on=transact(s,0x46,new byte[]{1},true,700);
            streamEnabled=on!=null;
            line(r,"SNR_STREAM_ON_ACK="+(on==null?"NO_RESPONSE":DumlV2.hex(on.payload)));
            append("SNR stream temporary ON ack="+(on!=null?"YES":"NO"));

            int packets=captureSnr(s,r,5500);
            line(r,"SNR_PACKETS="+packets);

            if(streamEnabled) {
                DumlV2.Frame off=transact(s,0x46,new byte[]{0},false,700);
                if(off==null && m.enc) off=transact(s,0x46,new byte[]{0},true,700);
                line(r,"SNR_STREAM_OFF_ACK="+(off==null?"NO_RESPONSE":DumlV2.hex(off.payload)));
                streamEnabled=false;
            }

            line(r,"");
            line(r,"=== SUMMARY ===");
            line(r,"F7_HITS="+hashHits+" SNR_PACKETS="+packets+" PERSISTENT_WRITES=0");
            line(r,"BLOCK LABELS: B0=legacy GPS, B1=legacy GLONASS; B2/B3/B4 are intentionally UNCONFIRMED until correlated with a second interface/firmware symbol.");
            append("=== ГОТОВО === F7="+hashHits+" SNR="+packets);
            append("Нажми COPY FULL REPORT и пришли текст.");
            append("PERSISTENT PARAMETER WRITES: 0");
            finish(r);
        } finally {
            if(streamEnabled) {
                try { transact(s,0x46,new byte[]{0},false,500); } catch(Exception ignored) {}
            }
            s.close();
            append("AOA CLOSED; persistent parameter writes=0");
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

    private void dumpIndex(ProbeAoaSession s,boolean enc,int idx,StringBuilder r,boolean show) throws Exception {
        byte[] q=DumlV2.concat(DumlV2.le16(0),DumlV2.le16(idx));
        DumlV2.Frame f=transact(s,0xE1,q,enc,500);
        if(f==null) {
            String x="INDEX|"+idx+"|E1=NO_RESPONSE";
            line(r,x); if(show) append("*** "+x); return;
        }
        DumlV2.ParamInfo2017 p=DumlV2.ParamInfo2017.parse(f.payload);
        if(p==null) {
            String x="INDEX|"+idx+"|PARSE=NULL|raw="+DumlV2.hex(f.payload);
            line(r,x); if(show) append("*** "+x); return;
        }
        byte[] v=(p.status==0 && p.name!=null && !p.name.isEmpty())?readIndexRaw(s,enc,idx,p.size):null;
        String x="INDEX|"+idx+"|status="+p.status+"|name="+safe(p.name)+"|type="+p.typeId+"|size="+p.size+
                "|min="+formatLimit(p.min,p.typeId)+"|max="+formatLimit(p.max,p.typeId)+"|default="+formatLimit(p.def,p.typeId)+
                "|current="+decode(v,p.typeId)+"|E1_RAW="+DumlV2.hex(f.payload);
        line(r,x); if(show) append("*** "+x);
    }

    private byte[] readIndexRaw(ProbeAoaSession s,boolean enc,int index,int size) throws Exception {
        byte[] q=DumlV2.concat(DumlV2.le16(0),DumlV2.le16(1),DumlV2.le16(index));
        DumlV2.Frame f=transact(s,0xE2,q,enc,500);
        if(f==null || f.payload==null || f.payload.length<6) return null;
        if(DumlV2.u16(f.payload,0)!=0 || DumlV2.u16(f.payload,4)!=index) return null;
        int n=Math.min(size,Math.max(0,f.payload.length-6));
        byte[] v=new byte[n];
        if(n>0) System.arraycopy(f.payload,6,v,0,n);
        return v;
    }

    private int probeDictionary(ProbeAoaSession s,boolean enc,StringBuilder r) throws Exception {
        String[] base={
                "disable_bd","single_bd_mode_disabled","SingleBDModeDisabled","disable_single_beidou","single_beidou_disabled",
                "gnss_source_mode","GnssSourceMode","gps_source_mode","source_mode","gps_galileo_beidou_mode","beidou_mode",
                "gps_snr_info","GPSSNRInfo","gps_snr_l5_info","GPSSNRL5Info","gps_snr_l5","gnss_snr_l5",
                "gps_fdi_open_signature_invalid","gps_fdi_open_frequency_err","gps_fdi_open_disagree","gps_fdi_open_conformity",
                "gps_fdi_open_svn_exception","gps_fdi_open_level_low","gps_fdi_open_height_drift","gps_multi_fdi_open",
                "gps_fix_num","hdop_fix","hdop_good","vdop_fix","snr_threshold","cno_threshold","cn0_threshold",
                "gps_max_pos_predict_error","gps_max_horizontal_vel_diff","gps_max_horizontal_pos_diff",
                "gps_max_vertical_vel_diff","gps_max_vertical_pos_diff","gps_pos_stdvar","gps_vel_stdvar",
                "lna_enable","lna_gain","agc_enable","agc_gain","rf_gain","receiver_gain","bandwidth",
                "acq_threshold","acquisition_threshold","track_threshold","tracking_threshold",
                "constellation_mask","gnss_mask","satellite_mask","gnss_mode","gps_system_mask",
                "enable_galileo","disable_galileo","enable_glonass","disable_glonass","enable_beidou","disable_beidou"
        };
        Set<String> names=new LinkedHashSet<>();
        for(String b:base) {
            names.add(b);
            if(!b.endsWith("_0")) names.add(b+"_0");
            names.add("g_config.gps_cfg."+b);
            names.add("g_config.gps_cfg."+b+"_0");
            names.add("g_config.gnss_cfg."+b);
            names.add("g_config.gnss_cfg."+b+"_0");
            names.add("gps_"+b);
            names.add("gps_"+b+"_0");
            names.add("gnss_"+b);
            names.add("gnss_"+b+"_0");
        }
        Set<Long> hashes=new LinkedHashSet<>();
        int hits=0;
        for(String name:names) {
            long h=DumlV2.parameterHash(name);
            if(!hashes.add(h)) continue;
            DumlV2.Frame f=transact(s,0xF7,DumlV2.le32(h),enc,165);
            if(f==null) continue;
            DumlV2.ParamInfo2015 p=DumlV2.ParamInfo2015.parse(f.payload);
            if(p==null || p.status!=0 || p.name==null || p.name.isEmpty()) continue;
            byte[] v=readHashRaw(s,enc,h,p.size);
            hits++;
            String x="F7-HIT|query="+safe(name)+"|returned="+safe(p.name)+"|hash=0x"+hex8(h)+
                    "|attr=0x"+Integer.toHexString(p.attribute)+"|type="+p.typeId+"|size="+p.size+
                    "|min="+formatLimit(p.min,p.typeId)+"|max="+formatLimit(p.max,p.typeId)+"|default="+formatLimit(p.def,p.typeId)+
                    "|current="+decode(v,p.typeId);
            line(r,x);
            append("*** "+x);
        }
        return hits;
    }

    private byte[] readHashRaw(ProbeAoaSession s,boolean enc,long hash,int size) throws Exception {
        DumlV2.Frame f=transact(s,0xF8,DumlV2.le32(hash),enc,350);
        if(f==null || f.payload==null || f.payload.length<5) return null;
        if((f.payload[0]&0xFF)!=0 || DumlV2.u32(f.payload,1)!=(hash&0xFFFFFFFFL)) return null;
        int n=Math.min(size,Math.max(0,f.payload.length-5));
        byte[] v=new byte[n];
        if(n>0) System.arraycopy(f.payload,5,v,0,n);
        return v;
    }

    private int captureSnr(ProbeAoaSession s,StringBuilder r,long ms) throws Exception {
        int packets=0;
        long end=System.currentTimeMillis()+ms;
        while(System.currentTimeMillis()<end) {
            DumlV2.Frame f=s.poll(Math.min(120,Math.max(1,end-System.currentTimeMillis())));
            if(f==null || f.cmdSet!=DumlV2.CMDSET_FLYC || f.cmdId!=0x45) continue;
            packets++;
            byte[] p=f.payload==null?new byte[0]:f.payload;
            String raw="SNR_RAW|packet="+packets+"|len="+p.length+"|raw="+DumlV2.hex(p);
            line(r,raw);

            StringBuilder summary=new StringBuilder();
            summary.append("SNR packet len=").append(p.length);
            int blocks=(p.length+31)/32;
            for(int b=0;b<blocks;b++) {
                int off=b*32;
                int n=Math.min(32,p.length-off);
                summary.append(" B").append(b).append("=").append(blockStats(p,off,n));
                line(r,"SNR_BLOCK|packet="+packets+"|block="+b+"|offset="+off+"|"+blockStatsDetailed(p,off,n));
            }
            append(summary.toString());
            if(packets>=4) break;
        }
        return packets;
    }

    private String blockStats(byte[] p,int off,int n) {
        int nonzero=0,max=0,sum=0;
        for(int i=0;i<n;i++) {
            int v=p[off+i]&0x7F;
            if(v!=0) { nonzero++; sum+=v; if(v>max) max=v; }
        }
        int avg=nonzero==0?0:Math.round((float)sum/nonzero);
        return "bytes="+n+",nonzero="+nonzero+",max="+max+",avg="+avg;
    }

    private String blockStatsDetailed(byte[] p,int off,int n) {
        StringBuilder slots=new StringBuilder();
        int nonzero=0,max=0,sum=0;
        for(int i=0;i<n;i++) {
            int raw=p[off+i]&0xFF;
            int v=raw&0x7F;
            if(v!=0) {
                if(slots.length()>0) slots.append(',');
                slots.append(i).append(':').append(v);
                if((raw&0x80)!=0) slots.append('*');
                nonzero++; sum+=v; if(v>max) max=v;
            }
        }
        int avg=nonzero==0?0:Math.round((float)sum/nonzero);
        return "nonzero="+nonzero+"|max="+max+"|avg="+avg+"|slots="+slots;
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
    private static String hex8(long x) { return String.format(Locale.US,"%08X",x&0xFFFFFFFFL); }
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

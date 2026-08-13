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
import android.os.ParcelFileDescriptor;
import android.view.Gravity;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.io.ByteArrayOutputStream;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Read-only GNSS bus/chip probe for Mini 4K.
 *
 * Safe operations only:
 *  - passive DUML capture;
 *  - COMMON/Version (set 0x00, id 0x01) read requests to DeviceType GPS (26), indices 0..2;
 *  - passive parsing of FLYC 0x57 GPS/GLNS info and 0xA1 A-GPS status.
 *
 * No E3/F9 parameter writes, no 0xDF unlock, no GPS receiver configuration commands.
 */
public class GnssChipProbeActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_CHIP_PROBE_USB";
    private static final int DEV_GPS = 26;
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x9100);
    private final AtomicBoolean running = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView status, log;
    private Button start, stop;
    private volatile boolean pendingStart;
    private volatile AoaSession activeSession;

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        usbManager = (UsbManager)getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
    }

    @Override protected void onDestroy() {
        running.set(false);
        AoaSession s = activeSession;
        if (s != null) s.close();
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
        title.setText("Mini 4K GNSS CHIP / BUS PROBE v2.0");
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        root.addView(title,new LinearLayout.LayoutParams(-1,-2));

        start = new Button(this);
        start.setText("START READ-ONLY CHIP PROBE");
        root.addView(start, top(12));
        stop = new Button(this);
        stop.setText("STOP"); stop.setEnabled(false);
        root.addView(stop, top(8));

        status = new TextView(this);
        status.setTextSize(16); status.setTextIsSelectable(true); status.setPadding(0,dp(12),0,dp(10));
        status.setText("Моторы OFF. DJI Fly полностью закрыть.\n\n" +
                "Проба ищет отдельный DUML GPS endpoint, читает только COMMON/Version у GPS[0..2] и пассивно слушает GPS/GLNS + A-GPS status.\n\n" +
                "WRITE/CONFIG COMMANDS: 0");
        root.addView(status,new LinearLayout.LayoutParams(-1,-2));

        log = new TextView(this);
        log.setTextSize(12); log.setTextIsSelectable(true);
        ScrollView sc = new ScrollView(this); sc.addView(log,new ScrollView.LayoutParams(-1,-2));
        root.addView(sc,new LinearLayout.LayoutParams(-1,0,1f));
        setContentView(root);

        start.setOnClickListener(v -> begin());
        stop.setOnClickListener(v -> running.set(false));
    }

    private LinearLayout.LayoutParams top(int d) { LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,-2); p.topMargin=dp(d); return p; }
    private int dp(int x) { return Math.round(x*getResources().getDisplayMetrics().density); }

    private void registerUsbReceiver() {
        IntentFilter f=new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION); f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if (Build.VERSION.SDK_INT>=33) registerReceiver(usbReceiver,f,Context.RECEIVER_NOT_EXPORTED); else registerReceiver(usbReceiver,f);
    }

    private final BroadcastReceiver usbReceiver=new BroadcastReceiver(){
        @Override public void onReceive(Context c, Intent i) {
            if (ACTION_USB_PERMISSION.equals(i.getAction())) {
                boolean ok=i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED,false);
                append(ok?"USB permission: OK":"USB permission: DENIED");
                if (ok && pendingStart) { pendingStart=false; begin(); }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(i.getAction())) {
                running.set(false); append("RC-N1 AOA disconnected");
            }
        }
    };

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] as=usbManager.getAccessoryList();
        if (as==null || as.length==0) return null;
        for (UsbAccessory a:as) if ("DJI".equalsIgnoreCase(a.getManufacturer())) return a;
        return as[0];
    }

    private void requestPermission(UsbAccessory a) {
        pendingStart=true;
        PendingIntent pi=PendingIntent.getBroadcast(this,0,new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                PendingIntent.FLAG_MUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
        usbManager.requestPermission(a,pi);
    }

    private void begin() {
        if (running.get()) return;
        UsbAccessory a=chooseAccessory();
        if (a==null) { append("AOA DJI не найден. Телефон -> верхний порт RC-N1; DJI Fly закрыть."); return; }
        if (!usbManager.hasPermission(a)) { requestPermission(a); return; }
        running.set(true);
        runOnUiThread(() -> { start.setEnabled(false); stop.setEnabled(true); status.setText("Подключение...\nWRITE/CONFIG COMMANDS: 0"); });
        io.submit(() -> runProbe(a));
    }

    private void runProbe(UsbAccessory a) {
        AoaSession s=null;
        try {
            s=AoaSession.open(usbManager,a,seq); activeSession=s;
            if (s==null) { setStatus("AOA pipe не открылся. DJI Fly должен быть закрыт."); return; }
            s.startProtocol(); sleep(350);
            append("=== GNSS CHIP / BUS PROBE v2.0 ===");
            append("AOA OPEN route="+s.routeString());
            append("PERSISTENT PARAM WRITES: 0; CONFIG WRITES: 0");

            Stats st=new Stats();
            setStatus("1/3 PASSIVE BUS DISCOVERY — 6 сек...\nМоторы OFF. Ничего не меняется.");
            capture(s,st,6000);

            setStatus("2/3 GPS DEVICE VERSION PROBE...\nТолько COMMON/Version read, GPS indices 0..2.");
            for (int idx=0; idx<3 && running.get(); idx++) {
                DumlV2.Frame r=transact(s,DEV_GPS,idx,DumlV2.CMDSET_GENERAL,0x01,new byte[0],1100);
                if (r==null) {
                    st.gpsVersion[idx]="NO RESPONSE";
                    append("GPS["+idx+"] COMMON/Version: no response");
                } else {
                    String ascii=printable(r.payload);
                    st.gpsVersion[idx]="RSP from "+r.senderType+":"+r.senderIndex+" len="+r.payload.length+
                            " ascii='"+ascii+"' raw="+shortHex(r.payload,96);
                    append("GPS["+idx+"] COMMON/Version: "+st.gpsVersion[idx]);
                    st.signature=mergeSignature(st.signature,detectSignature(ascii));
                }
            }

            setStatus("3/3 PASSIVE CONFIRMATION — 4 сек...");
            capture(s,st,4000);
            String result=st.render();
            setStatus(result);
            append(result.replace('\n',' '));
        } catch (Throwable t) {
            setStatus("PROBE ERROR: "+t.getClass().getSimpleName()+": "+t.getMessage());
            append("PROBE ERROR: "+t);
        } finally {
            if (s!=null) s.close(); activeSession=null; running.set(false);
            runOnUiThread(() -> { start.setEnabled(true); stop.setEnabled(false); });
            append("AOA CLOSED; WRITE/CONFIG COMMANDS: 0");
        }
    }

    private void capture(AoaSession s, Stats st, long ms) throws Exception {
        long end=System.currentTimeMillis()+ms;
        while (running.get() && System.currentTimeMillis()<end) {
            DumlV2.Frame f=s.poll(250);
            if (f==null) continue;
            st.add(f);
        }
    }

    private DumlV2.Frame transact(AoaSession s,int dev,int devIdx,int set,int id,byte[] payload,int timeoutMs) throws Exception {
        int qseq=seq.getAndIncrement()&0xFFFF;
        s.sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,dev,devIdx,qseq,set,id,payload,false));
        long end=System.currentTimeMillis()+timeoutMs;
        while (System.currentTimeMillis()<end) {
            DumlV2.Frame f=s.poll(Math.min(120,Math.max(1,end-System.currentTimeMillis())));
            if (f==null) continue;
            if (f.response && f.seq==qseq && f.cmdSet==set && f.cmdId==id) return f;
        }
        return null;
    }

    private static final class Stats {
        int total, gpsSenderFrames, flyc57, agpsA1, snr45, osd43;
        int lastNumSv=-1, lastGlnsCount=-1; float lastHdop=Float.NaN;
        long agpsTimestamp=-1, agpsLength=-1; int agpsCrc=-1;
        String signature="";
        String[] gpsVersion=new String[]{"not probed","not probed","not probed"};
        Map<String,Integer> gpsCmds=new LinkedHashMap<>();

        void add(DumlV2.Frame f) {
            total++;
            if (f.senderType==DEV_GPS) {
                gpsSenderFrames++;
                String k="GPS["+f.senderIndex+"] set=0x"+Integer.toHexString(f.cmdSet)+" id=0x"+Integer.toHexString(f.cmdId)+(f.response?" RSP":" PUSH");
                gpsCmds.put(k,gpsCmds.getOrDefault(k,0)+1);
                String a=printable(f.payload);
                signature=mergeSignature(signature,detectSignature(a));
            }
            if (f.senderType==DumlV2.DEV_FLYCONTROLLER && f.cmdSet==DumlV2.CMDSET_FLYC && !f.response) {
                if (f.cmdId==0x57) {
                    flyc57++;
                    if (f.payload!=null && f.payload.length>=32) {
                        lastHdop=ByteBuffer.wrap(f.payload,24,4).order(ByteOrder.LITTLE_ENDIAN).getFloat();
                        lastNumSv=u16(f.payload,28); lastGlnsCount=u16(f.payload,30);
                    }
                } else if (f.cmdId==0xA1) {
                    agpsA1++;
                    if (f.payload!=null && f.payload.length>=10) {
                        agpsTimestamp=u32(f.payload,0); agpsLength=u32(f.payload,4); agpsCrc=u16(f.payload,8);
                    }
                } else if (f.cmdId==0x45) snr45++;
                else if (f.cmdId==0x43) osd43++;
            }
        }

        String render() {
            StringBuilder x=new StringBuilder();
            x.append("GNSS CHIP / BUS PROBE RESULT\n\n");
            x.append("Direct DUML GPS device (type 26) passive frames: ").append(gpsSenderFrames).append("\n");
            x.append("GPS[0] version: ").append(gpsVersion[0]).append("\n");
            x.append("GPS[1] version: ").append(gpsVersion[1]).append("\n");
            x.append("GPS[2] version: ").append(gpsVersion[2]).append("\n");
            x.append("Chip/vendor signature: ").append(signature.isEmpty()?"NOT EXPOSED":signature).append("\n\n");
            x.append("FLYC 0x57 GPS/GLNS pushes: ").append(flyc57).append("\n");
            if (flyc57>0) x.append("0x57 latest: NumSV=").append(lastNumSv).append(" HDOP=").append(Float.isNaN(lastHdop)?"N/A":String.format(Locale.US,"%.2f",lastHdop)).append(" counter=").append(lastGlnsCount).append("\n");
            x.append("A-GPS status 0xA1 pushes: ").append(agpsA1).append("\n");
            if (agpsA1>0) x.append("A-GPS: timestamp=").append(agpsTimestamp).append(" dataLen=").append(agpsLength).append(" CRC16=0x").append(Integer.toHexString(agpsCrc)).append("\n");
            x.append("SNR 0x45 pushes: ").append(snr45).append(" | OSD 0x43: ").append(osd43).append("\n");
            x.append("Total captured DUML frames: ").append(total).append("\n");
            if (!gpsCmds.isEmpty()) {
                x.append("\nDirect GPS endpoint traffic:\n");
                for (Map.Entry<String,Integer> e:gpsCmds.entrySet()) x.append(e.getKey()).append(" x").append(e.getValue()).append("\n");
            }
            x.append("\nWRITE/CONFIG COMMANDS: 0\n");
            x.append("Если Chip/vendor=NOT EXPOSED, это означает, что FC скрывает модель GNSS за внутренним интерфейсом; тогда следующий путь — анализ firmware/board, а не случайные write-команды.");
            return x.toString();
        }
    }

    private static String detectSignature(String a) {
        if (a==null) return "";
        String s=a.toLowerCase(Locale.US);
        String[] keys={"u-blox","ublox","neo-m","m8","m9","m10","mediatek","mtk","unicore","um9","casic","atgm","quectel","septentrio","skytraq","broadcom","sony"};
        for (String k:keys) if (s.contains(k)) return k;
        return "";
    }
    private static String mergeSignature(String a,String b) { if (b==null||b.isEmpty()) return a==null?"":a; if (a==null||a.isEmpty()) return b; return a.contains(b)?a:a+", "+b; }
    private static String printable(byte[] p) {
        if (p==null) return "";
        StringBuilder s=new StringBuilder();
        for (byte q:p) { int v=q&0xFF; if (v>=32&&v<=126) s.append((char)v); else if (v==0 && s.length()>0) s.append(' '); }
        return s.toString().trim();
    }
    private static String shortHex(byte[] p,int max) { if (p==null) return ""; StringBuilder s=new StringBuilder(); int n=Math.min(max,p.length); for(int i=0;i<n;i++){ if(i>0)s.append(' '); s.append(String.format(Locale.US,"%02X",p[i]&0xFF)); } if(p.length>n)s.append(" ..."); return s.toString(); }
    private static int u16(byte[] p,int o){ return (p[o]&0xFF)|((p[o+1]&0xFF)<<8); }
    private static long u32(byte[] p,int o){ return ((long)p[o]&0xFF)|(((long)p[o+1]&0xFF)<<8)|(((long)p[o+2]&0xFF)<<16)|(((long)p[o+3]&0xFF)<<24); }
    private void setStatus(String s){ runOnUiThread(() -> status.setText(s)); }
    private void append(String s){ runOnUiThread(() -> log.append(s+"\n")); }
    private static void sleep(long ms){ try{Thread.sleep(ms);}catch(InterruptedException e){Thread.currentThread().interrupt();} }

    private static final class AoaSession {
        private final ParcelFileDescriptor pfd; private final FileInputStream in; private final FileOutputStream out; private final AtomicInteger seq;
        private final AtomicBoolean alive=new AtomicBoolean(true); private final LinkedBlockingQueue<DumlV2.Frame> rx=new LinkedBlockingQueue<>(5000);
        private final Object writeLock=new Object(); private final Thread reader; private Thread keepalive; private volatile int route=0x5749;
        private int headerPos; private final byte[] header=new byte[8]; private long bodyLeft; private int bodyType=-1; private ByteArrayOutputStream body;
        static AoaSession open(UsbManager manager,UsbAccessory a,AtomicInteger sequence){ try{ParcelFileDescriptor p=manager.openAccessory(a); return p==null?null:new AoaSession(p,sequence);}catch(Exception e){return null;} }
        private AoaSession(ParcelFileDescriptor p,AtomicInteger sequence){ pfd=p;seq=sequence;in=new FileInputStream(p.getFileDescriptor());out=new FileOutputStream(p.getFileDescriptor());reader=new Thread(this::readLoop,"mini4k-gnss-chip-rx");reader.setDaemon(true);reader.start(); }
        void startProtocol() throws IOException { byte[] boot=new byte[]{0,0,1}; sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_AIRCRAFT_PROXY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false)); sleep(4); sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_ANY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false)); sleep(8); startKeepalive(); }
        void startKeepalive(){ keepalive=new Thread(() -> { sleep(2500); byte[] p=new byte[]{1,1,0,(byte)0xFF,(byte)0xFF,0x20,0,0}; while(alive.get()){ try{ sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_REMOTE_RADIO,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false)); sleep(4); sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,14,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false)); }catch(Exception e){break;} sleep(2500); } },"mini4k-gnss-chip-ka"); keepalive.setDaemon(true); keepalive.start(); }
        DumlV2.Frame poll(long ms)throws InterruptedException{return rx.poll(ms,TimeUnit.MILLISECONDS);} String routeString(){return String.format(Locale.US,"0x%04X",route);} 
        void sendDuml(byte[] duml)throws IOException{int n=duml.length;byte[] w=new byte[8+n];w[0]=0x55;w[1]=(byte)0xCC;w[2]=(byte)(route&0xFF);w[3]=(byte)((route>>>8)&0xFF);w[4]=(byte)(n&0xFF);w[5]=(byte)((n>>>8)&0xFF);w[6]=(byte)((n>>>16)&0xFF);w[7]=(byte)((n>>>24)&0xFF);System.arraycopy(duml,0,w,8,n);synchronized(writeLock){out.write(w);out.flush();}sleep(3);} 
        void readLoop(){byte[] b=new byte[16384];try{while(alive.get()){int n=in.read(b);if(n<0)break;if(n>0)feed(b,n);}}catch(Exception ignored){}finally{alive.set(false);}}
        void feed(byte[] a,int n){for(int i=0;i<n;i++){int x=a[i]&0xFF;if(bodyLeft>0){if(body!=null)body.write(x);bodyLeft--;if(bodyLeft==0)finishUnit();continue;}if(headerPos==0){if(x==0x55){header[0]=0x55;headerPos=1;}continue;}if(headerPos==1){if(x==0xCC){header[1]=(byte)0xCC;headerPos=2;}else if(x==0x55){header[0]=0x55;headerPos=1;}else headerPos=0;continue;}header[headerPos++]=(byte)x;if(headerPos==8){int type=(header[2]&0xFF)|((header[3]&0xFF)<<8);long len=((long)header[4]&0xFF)|(((long)header[5]&0xFF)<<8)|(((long)header[6]&0xFF)<<16)|(((long)header[7]&0xFF)<<24);headerPos=0;if(len<0||len>0x200000L){bodyLeft=0;body=null;bodyType=-1;continue;}bodyType=type;bodyLeft=len;if(type==0x5749||type==0x7530){route=type;body=new ByteArrayOutputStream((int)Math.min(len,16384));}else body=null;if(bodyLeft==0)finishUnit();}}}
        void finishUnit(){if((bodyType==0x5749||bodyType==0x7530)&&body!=null){for(DumlV2.Frame f:DumlV2.frames(body.toByteArray())){if(!rx.offer(f)){rx.poll();rx.offer(f);}}}bodyType=-1;body=null;bodyLeft=0;}
        void close(){if(!alive.getAndSet(false))return;try{if(keepalive!=null)keepalive.interrupt();}catch(Exception ignored){}try{reader.interrupt();}catch(Exception ignored){}try{in.close();}catch(Exception ignored){}try{out.close();}catch(Exception ignored){}try{pfd.close();}catch(Exception ignored){}}
    }
}

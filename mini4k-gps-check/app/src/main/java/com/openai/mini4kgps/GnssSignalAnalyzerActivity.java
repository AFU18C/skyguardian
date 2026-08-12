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
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/** Mini 4K GNSS signal/SNR analyzer. No persistent FC parameter writes. */
public class GnssSignalAnalyzerActivity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.GNSS_SIGNAL_USB_PERMISSION";
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger seq = new AtomicInteger(0x8A00);
    private final AtomicBoolean running = new AtomicBoolean(false);

    private UsbManager usbManager;
    private TextView status, log;
    private Button start, stop;
    private volatile boolean pendingStart;
    private volatile AoaSession activeSession;

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        usbManager = (UsbManager) getSystemService(Context.USB_SERVICE);
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
        root.setPadding(pad, pad, pad, pad);

        TextView title = new TextView(this);
        title.setText("Mini 4K GNSS SIGNAL ANALYZER v1.8");
        title.setTextSize(21);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        start = new Button(this);
        start.setText("START GNSS ANALYZER");
        root.addView(start, top(12));
        stop = new Button(this);
        stop.setText("STOP"); stop.setEnabled(false);
        root.addView(stop, top(8));

        status = new TextView(this);
        status.setTextSize(17); status.setTextIsSelectable(true); status.setPadding(0, dp(12), 0, dp(10));
        status.setText("Моторы OFF. Лучше вынести дрон под открытое небо.\n\n" +
                "Анализатор включает штатный временный DJI SNR push (0x46→0x45), затем показывает SNR каналов, used-сигналы, satellites/GPS level и штатные jamming/spoofing flags.\n\n" +
                "Это НЕ усиление антенны/приёмника и не меняет постоянные параметры GNSS.");
        root.addView(status, new LinearLayout.LayoutParams(-1, -2));

        log = new TextView(this);
        log.setTextSize(13); log.setTextIsSelectable(true);
        log.setText("PERSISTENT PARAM WRITES: 0\nSNR telemetry control is temporary: enable on START, disable on STOP.\n\n");
        ScrollView sc = new ScrollView(this); sc.addView(log, new ScrollView.LayoutParams(-1, -2));
        root.addView(sc, new LinearLayout.LayoutParams(-1, 0, 1f));
        setContentView(root);

        start.setOnClickListener(v -> begin());
        stop.setOnClickListener(v -> running.set(false));
    }

    private LinearLayout.LayoutParams top(int px) { LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1, -2); p.topMargin = dp(px); return p; }
    private int dp(int x) { return Math.round(x * getResources().getDisplayMetrics().density); }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION); f.addAction(UsbManager.ACTION_USB_ACCESSORY_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(usbReceiver, f, Context.RECEIVER_NOT_EXPORTED); else registerReceiver(usbReceiver, f);
    }

    private final BroadcastReceiver usbReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            if (ACTION_USB_PERMISSION.equals(intent.getAction())) {
                boolean ok = intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false);
                append(ok ? "USB permission: OK" : "USB permission: DENIED");
                if (ok && pendingStart) { pendingStart = false; begin(); }
            } else if (UsbManager.ACTION_USB_ACCESSORY_DETACHED.equals(intent.getAction())) {
                running.set(false); append("RC-N1 AOA отключён.");
            }
        }
    };

    private UsbAccessory chooseAccessory() {
        UsbAccessory[] as = usbManager.getAccessoryList();
        if (as == null || as.length == 0) return null;
        for (UsbAccessory a : as) if ("DJI".equalsIgnoreCase(a.getManufacturer())) return a;
        return as[0];
    }

    private void requestPermission(UsbAccessory a) {
        pendingStart = true;
        PendingIntent pi = PendingIntent.getBroadcast(this, 0, new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),
                PendingIntent.FLAG_MUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        usbManager.requestPermission(a, pi);
    }

    private void begin() {
        if (running.get()) return;
        UsbAccessory a = chooseAccessory();
        if (a == null) { append("AOA DJI не найден. Закрой DJI Fly и подключи телефон к верхнему порту RC-N1."); return; }
        if (!usbManager.hasPermission(a)) { append("Запрашиваю USB permission..."); requestPermission(a); return; }
        running.set(true);
        runOnUiThread(() -> { start.setEnabled(false); stop.setEnabled(true); status.setText("Подключение к FC..."); });
        io.submit(() -> runAnalyzer(a));
    }

    private void runAnalyzer(UsbAccessory a) {
        AoaSession s = null;
        boolean enableSent = false;
        int telemetryCommands = 0;
        try {
            s = AoaSession.open(usbManager, a, seq); activeSession = s;
            if (s == null) { setStatus("AOA pipe не открылся. DJI Fly должен быть полностью закрыт."); return; }
            s.startProtocol(); sleep(350);
            append("AOA OPEN route=" + s.routeString());

            // DJI DataFlycSetPushGpsSnr: FLYC cmd 0x46, one byte 1=enable, 0=disable.
            int enSeq = seq.getAndIncrement() & 0xFFFF;
            s.sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0,
                    enSeq, DumlV2.CMDSET_FLYC, 0x46, new byte[]{1}, false));
            enableSent = true; telemetryCommands++;
            append("TEMP SNR STREAM ENABLE sent: FLYC 0x46 payload=01. Persistent parameter writes=0.");

            int snrPackets = 0, osdPackets = 0, homePackets = 0;
            int sats = -1, gpsLevel = -1; boolean gpsUsed = false;
            int jam = -1, spoof = -1; boolean gpsAbnormal = false, navAbnormal = false;
            Snr lastSnr = null;
            long startMs = System.currentTimeMillis(), lastUi = 0;
            boolean ackSeen = false;

            while (running.get()) {
                DumlV2.Frame f = s.poll(400);
                long now = System.currentTimeMillis();
                if (f != null && f.cmdSet == DumlV2.CMDSET_FLYC) {
                    int id = f.cmdId & 0xFF;
                    if (id == 0x46 && f.response) { ackSeen = true; if (f.payload != null) append("0x46 ACK len=" + f.payload.length + " raw=" + shortHex(f.payload, 16)); }
                    if (id == 0x45 && !f.response) {
                        snrPackets++;
                        lastSnr = Snr.parse(f.payload);
                        if (snrPackets == 1) append("0x45 SNR push detected, len=" + (f.payload == null ? 0 : f.payload.length));
                    } else if (id == 0x43 && !f.response) {
                        Osd o = Osd.parse(f.payload);
                        if (o != null) { osdPackets++; sats = o.sats; gpsLevel = o.gpsLevel; gpsUsed = o.gpsUsed; }
                    } else if (id == 0x44 && !f.response) {
                        Home h = Home.parse(f.payload);
                        if (h != null) { homePackets++; jam = h.jam; spoof = h.spoof; gpsAbnormal |= h.gpsAbnormal; navAbnormal |= h.navAbnormal; }
                    }
                }

                if (now - lastUi >= 400) {
                    lastUi = now;
                    setStatus(render(lastSnr, sats, gpsLevel, gpsUsed, jam, spoof, gpsAbnormal, navAbnormal,
                            snrPackets, osdPackets, homePackets, ackSeen, telemetryCommands, now - startMs));
                }
                if (now - startMs > 6000 && snrPackets == 0 && (now - startMs) < 6500) {
                    append("6 сек: 0x45 SNR push пока не пришёл. 0x43/0x44 всё равно продолжают анализироваться.");
                }
            }
        } catch (Throwable t) {
            append("GNSS ANALYZER ERROR: " + t.getClass().getSimpleName() + ": " + t.getMessage());
            setStatus("Ошибка анализатора: " + t.getMessage());
        } finally {
            if (s != null && enableSent) {
                try {
                    s.sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_FLYCONTROLLER, 0,
                            seq.getAndIncrement() & 0xFFFF, DumlV2.CMDSET_FLYC, 0x46, new byte[]{0}, false));
                    append("TEMP SNR STREAM DISABLE sent: 0x46 payload=00.");
                } catch (Exception e) { append("SNR disable not sent because link closed: " + e.getMessage()); }
            }
            if (s != null) s.close(); activeSession = null; running.set(false);
            runOnUiThread(() -> { start.setEnabled(true); stop.setEnabled(false); });
            append("AOA CLOSED. PERSISTENT PARAM WRITES: 0");
        }
    }

    private String render(Snr snr, int sats, int level, boolean used, int jam, int spoof,
                          boolean gpsAbn, boolean navAbn, int snrPackets, int osdPackets, int homePackets,
                          boolean ackSeen, int controlCommands, long elapsed) {
        StringBuilder x = new StringBuilder();
        x.append("GNSS SIGNAL ANALYZER\n\n");
        x.append("Satellites: ").append(sats < 0 ? "N/A" : sats).append("\n");
        x.append("GPS Level: ").append(level < 0 ? "N/A" : level).append("\n");
        x.append("GPS Used/Valid: ").append(osdPackets == 0 ? "N/A" : (used ? "YES" : "NO")).append("\n");
        x.append("Jamming: ").append(stateName(jam)).append(" [").append(jam < 0 ? "N/A" : jam).append("]\n");
        x.append("Spoofing: ").append(stateName(spoof)).append(" [").append(spoof < 0 ? "N/A" : spoof).append("]\n");
        x.append("GPS abnormal flag: ").append(gpsAbn ? "YES" : "NO").append("\n");
        x.append("Navigation abnormal flag: ").append(navAbn ? "YES" : "NO").append("\n\n");
        if (snr == null) {
            x.append("SNR 0x45: WAITING / NOT EXPOSED\n");
        } else {
            x.append("GPS SNR: visible=").append(snr.gpsVisible).append(" used=").append(snr.gpsUsed)
                    .append(" avg=").append(fmt(snr.gpsAvg)).append(" max=").append(snr.gpsMax).append("\n");
            x.append("GPS top: ").append(snr.gpsTop).append("\n");
            x.append("GLONASS SNR: visible=").append(snr.gloVisible).append(" used=").append(snr.gloUsed)
                    .append(" avg=").append(fmt(snr.gloAvg)).append(" max=").append(snr.gloMax).append("\n");
            x.append("GLONASS top: ").append(snr.gloTop).append("\n");
            if (snr.payloadLen < 64) x.append("SNR payload shorter than legacy 64-byte layout: ").append(snr.payloadLen).append("\n");
        }
        x.append("\n0x45/0x43/0x44 packets: ").append(snrPackets).append("/").append(osdPackets).append("/").append(homePackets).append("\n");
        x.append("0x46 ACK: ").append(ackSeen ? "YES" : "not seen").append("\n");
        x.append("Temporary telemetry control cmds: ").append(controlCommands).append("\n");
        x.append("Persistent parameter writes: 0\n");
        x.append("Elapsed: ").append(elapsed / 1000).append(" s\n\n");
        x.append("Важно: SNR monitor НЕ увеличивает RF gain. Он показывает качество сигнала, которое реально видит GNSS-приёмник.");
        return x.toString();
    }

    private static String fmt(double v) { return v < 0 ? "N/A" : String.format(Locale.US, "%.1f", v); }
    private static String stateName(int v) { if (v < 0) return "N/A"; if (v == 0) return "UNKNOWN"; if (v == 1) return "OK"; if (v == 2) return "WARNING"; if (v == 3) return "CRITICAL"; return "?"; }

    private static final class Snr {
        int payloadLen, gpsVisible, gpsUsed, gpsMax, gloVisible, gloUsed, gloMax;
        double gpsAvg = -1, gloAvg = -1;
        String gpsTop = "—", gloTop = "—";
        static Snr parse(byte[] p) {
            if (p == null || p.length == 0) return null;
            Snr s = new Snr(); s.payloadLen = p.length;
            int gpsEnd = Math.min(32, p.length); int sum = 0;
            StringBuilder gt = new StringBuilder();
            for (int i = 0; i < gpsEnd; i++) {
                int raw = p[i] & 0xFF, q = raw & 0x7F;
                if (q > 0) { s.gpsVisible++; sum += q; s.gpsMax = Math.max(s.gpsMax, q); if (gt.length() < 80) { if (gt.length()>0) gt.append(' '); gt.append(i+1).append(':').append(q).append((raw&0x80)!=0?'*':' '); } }
                if ((raw & 0x80) != 0) s.gpsUsed++;
            }
            if (s.gpsVisible > 0) s.gpsAvg = sum / (double)s.gpsVisible; s.gpsTop = gt.length()==0 ? "—" : gt.toString();
            int ge = Math.min(64, p.length); sum = 0; StringBuilder gl = new StringBuilder();
            for (int i = 32; i < ge; i++) {
                int raw = p[i] & 0xFF, q = raw & 0x7F;
                if (q > 0) { s.gloVisible++; sum += q; s.gloMax = Math.max(s.gloMax, q); if (gl.length() < 80) { if (gl.length()>0) gl.append(' '); gl.append(i-31).append(':').append(q).append((raw&0x80)!=0?'*':' '); } }
                if ((raw & 0x80) != 0) s.gloUsed++;
            }
            if (s.gloVisible > 0) s.gloAvg = sum / (double)s.gloVisible; s.gloTop = gl.length()==0 ? "—" : gl.toString();
            return s;
        }
    }

    private static final class Osd {
        int sats, gpsLevel; boolean gpsUsed;
        static Osd parse(byte[] p) {
            if (p == null || p.length < 42) return null; int b = base43(p); if (b < 0 || p.length < b+42) return null;
            Osd o = new Osd(); long cs = u32(p, b+32); o.gpsUsed = (cs & 0x8000L) != 0; o.gpsLevel = (int)((cs>>>18)&0x0F); o.sats = p[b+36]&0xFF; return o;
        }
        static int base43(byte[] p) { if (p.length==50 || p.length==55 || p.length>=50) return 0; if ((p.length==51 || p.length==56) && (p[0]&0xFF)==0) return 1; return p.length>=42?0:-1; }
    }

    private static final class Home {
        int jam, spoof; boolean gpsAbnormal, navAbnormal;
        static Home parse(byte[] p) {
            if (p == null || p.length < 76) return null; int b = 0;
            if ((p.length==79 || p.length==80) && (p[0]&0xFF)==0) b=1;
            if (p.length < b+76) return null;
            Home h = new Home(); long r = p.length >= b+50 ? u32(p,b+46) : 0;
            h.gpsAbnormal = ((r>>>5)&1)!=0 || ((r>>>15)&1)!=0;
            h.navAbnormal = ((r>>>6)&1)!=0 || ((r>>>16)&1)!=0;
            int q = p[b+75]&0xFF; h.jam = (q>>>3)&3; h.spoof=(q>>>5)&3; return h;
        }
    }

    private void setStatus(String s) { runOnUiThread(() -> status.setText(s)); }
    private void append(String s) { runOnUiThread(() -> log.append(s + "\n")); }
    private static void sleep(long ms) { try { Thread.sleep(ms); } catch (InterruptedException e) { Thread.currentThread().interrupt(); } }
    private static long u32(byte[] a, int off) { return ((long)a[off]&0xFF)|(((long)a[off+1]&0xFF)<<8)|(((long)a[off+2]&0xFF)<<16)|(((long)a[off+3]&0xFF)<<24); }
    private static String shortHex(byte[] b, int n) { StringBuilder s=new StringBuilder(); int m=Math.min(n,b.length); for(int i=0;i<m;i++){ if(i>0)s.append(' '); s.append(String.format(Locale.US,"%02X",b[i]&0xFF)); } return s.toString(); }

    private static final class AoaSession {
        private final ParcelFileDescriptor pfd; private final FileInputStream in; private final FileOutputStream out; private final AtomicInteger seq;
        private final AtomicBoolean alive = new AtomicBoolean(true); private final LinkedBlockingQueue<DumlV2.Frame> rx = new LinkedBlockingQueue<>(5000);
        private final Object writeLock = new Object(); private final Thread reader; private Thread keepalive; private volatile int route=0x5749;
        private int headerPos; private final byte[] header=new byte[8]; private long bodyLeft; private int bodyType=-1; private ByteArrayOutputStream body;
        static AoaSession open(UsbManager manager, UsbAccessory a, AtomicInteger sequence) { try { ParcelFileDescriptor p=manager.openAccessory(a); return p==null?null:new AoaSession(p,sequence); } catch(Exception e){ return null; } }
        private AoaSession(ParcelFileDescriptor p, AtomicInteger sequence) { pfd=p; seq=sequence; in=new FileInputStream(p.getFileDescriptor()); out=new FileOutputStream(p.getFileDescriptor()); reader=new Thread(this::readLoop,"mini4k-gnss-rx"); reader.setDaemon(true); reader.start(); }
        void startProtocol() throws IOException { byte[] boot=new byte[]{0,0,1}; sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_AIRCRAFT_PROXY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false)); sleep(4); sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_ANY,0,seq.getAndIncrement()&0xFFFF,DumlV2.CMDSET_GENERAL,0x00,boot,false)); sleep(8); startKeepalive(); }
        private void startKeepalive() { keepalive=new Thread(() -> { sleep(2500); byte[] p=new byte[]{1,1,0,(byte)0xFF,(byte)0xFF,0x20,0,0}; while(alive.get()){ try { sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,DumlV2.DEV_REMOTE_RADIO,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false)); sleep(4); sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP,0,14,0,seq.getAndIncrement()&0xFFFF,0x06,0x77,p,false)); } catch(Exception e){ break; } sleep(2500); } },"mini4k-gnss-keepalive"); keepalive.setDaemon(true); keepalive.start(); }
        DumlV2.Frame poll(long ms) throws InterruptedException { return rx.poll(ms, TimeUnit.MILLISECONDS); }
        String routeString(){ return String.format(Locale.US,"0x%04X",route); }
        void sendDuml(byte[] duml) throws IOException { int n=duml.length; byte[] w=new byte[8+n]; w[0]=0x55;w[1]=(byte)0xCC;w[2]=(byte)(route&0xFF);w[3]=(byte)((route>>>8)&0xFF);w[4]=(byte)(n&0xFF);w[5]=(byte)((n>>>8)&0xFF);w[6]=(byte)((n>>>16)&0xFF);w[7]=(byte)((n>>>24)&0xFF);System.arraycopy(duml,0,w,8,n); synchronized(writeLock){out.write(w);out.flush();} sleep(3); }
        private void readLoop(){ byte[] b=new byte[16384]; try { while(alive.get()){ int n=in.read(b); if(n<0)break; if(n>0)feed(b,n); } } catch(Exception ignored){} finally{alive.set(false);} }
        private void feed(byte[] a,int n){ for(int i=0;i<n;i++){ int x=a[i]&0xFF; if(bodyLeft>0){if(body!=null)body.write(x);bodyLeft--;if(bodyLeft==0)finishUnit();continue;} if(headerPos==0){if(x==0x55){header[0]=0x55;headerPos=1;}continue;} if(headerPos==1){if(x==0xCC){header[1]=(byte)0xCC;headerPos=2;}else if(x==0x55){header[0]=0x55;headerPos=1;}else headerPos=0;continue;} header[headerPos++]=(byte)x; if(headerPos==8){int type=(header[2]&0xFF)|((header[3]&0xFF)<<8);long len=((long)header[4]&0xFF)|(((long)header[5]&0xFF)<<8)|(((long)header[6]&0xFF)<<16)|(((long)header[7]&0xFF)<<24);headerPos=0;if(len<0||len>0x200000L){bodyLeft=0;body=null;bodyType=-1;continue;}bodyType=type;bodyLeft=len;if(type==0x5749||type==0x7530){route=type;body=new ByteArrayOutputStream((int)Math.min(len,16384));}else body=null;if(bodyLeft==0)finishUnit();} } }
        private void finishUnit(){ if((bodyType==0x5749||bodyType==0x7530)&&body!=null){for(DumlV2.Frame f:DumlV2.frames(body.toByteArray())){if(!rx.offer(f)){rx.poll();rx.offer(f);}}}bodyType=-1;body=null;bodyLeft=0; }
        void close(){ if(!alive.getAndSet(false))return; try{if(keepalive!=null)keepalive.interrupt();}catch(Exception ignored){} try{reader.interrupt();}catch(Exception ignored){} try{in.close();}catch(Exception ignored){} try{out.close();}catch(Exception ignored){} try{pfd.close();}catch(Exception ignored){} }
    }
}

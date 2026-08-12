package com.openai.mini4kgps;

import android.app.Activity;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.hardware.usb.UsbConstants;
import android.hardware.usb.UsbDevice;
import android.hardware.usb.UsbDeviceConnection;
import android.hardware.usb.UsbEndpoint;
import android.hardware.usb.UsbInterface;
import android.hardware.usb.UsbManager;
import android.os.Build;
import android.os.Bundle;
import android.text.method.ScrollingMovementMethod;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.nio.charset.StandardCharsets;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;

public class V5Activity extends Activity {
    private static final String ACTION_USB_PERMISSION = "com.openai.mini4kgps.USB_PERMISSION";
    private static final int DJI_VID = 0x2CA3;
    private static final int AMBA_A9_VID = 0x070A;
    private static final int AMBA_A9_PID = 0x4026;

    private UsbManager usbManager;
    private TextView status;
    private Button check;
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final AtomicInteger tag = new AtomicInteger(0x4B340001);

    @Override protected void onCreate(Bundle b) {
        super.onCreate(b);
        usbManager = (UsbManager)getSystemService(Context.USB_SERVICE);
        buildUi();
        registerUsbReceiver();
        intro();
    }

    @Override protected void onDestroy() {
        super.onDestroy();
        try { unregisterReceiver(receiver); } catch (Exception ignored) {}
        io.shutdownNow();
    }

    private void buildUi() {
        int p = dp(16);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(p,p,p,p);
        TextView title = new TextView(this);
        title.setText("Mini 4K USB CHECK v0.5");
        title.setTextSize(23);
        title.setGravity(Gravity.CENTER);
        root.addView(title, new LinearLayout.LayoutParams(-1,-2));
        check = new Button(this);
        check.setText("IDENTIFY USB");
        LinearLayout.LayoutParams bp = new LinearLayout.LayoutParams(-1,-2); bp.topMargin=dp(12);
        root.addView(check,bp);
        status = new TextView(this);
        status.setTextSize(13);
        status.setTextIsSelectable(true);
        status.setMovementMethod(new ScrollingMovementMethod());
        ScrollView sv = new ScrollView(this);
        sv.addView(status,new ScrollView.LayoutParams(-1,-2));
        root.addView(sv,new LinearLayout.LayoutParams(-1,0,1f));
        setContentView(root);
        check.setOnClickListener(v -> {
            check.setEnabled(false);
            io.submit(() -> { try { identify(); } catch(Throwable t){ append("ERROR: "+t); } finally { runOnUiThread(() -> check.setEnabled(true)); }});
        });
    }

    private void intro() {
        status.setText("");
        append("v0.5 — только чтение USB-дескрипторов и безопасный SCSI INQUIRY, если дрон находится в USB Mass Storage mode.");
        append("НИЧЕГО В ПАМЯТЬ/ПРОШИВКУ/ПАРАМЕТРЫ НЕ ЗАПИСЫВАЕТСЯ.");
        append("Подключение: телефон -> DATA USB-C -> дрон. Дрон включен.");
    }

    private void registerUsbReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(ACTION_USB_PERMISSION);
        f.addAction(UsbManager.ACTION_USB_DEVICE_ATTACHED);
        f.addAction(UsbManager.ACTION_USB_DEVICE_DETACHED);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(receiver,f,Context.RECEIVER_NOT_EXPORTED); else registerReceiver(receiver,f);
    }

    private final BroadcastReceiver receiver = new BroadcastReceiver(){
        @Override public void onReceive(Context c, Intent i){
            if (ACTION_USB_PERMISSION.equals(i.getAction())) append(i.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED,false) ? "USB permission OK. Нажмите IDENTIFY USB ещё раз." : "USB permission DENIED");
            else if (UsbManager.ACTION_USB_DEVICE_ATTACHED.equals(i.getAction())) append("USB connected");
            else if (UsbManager.ACTION_USB_DEVICE_DETACHED.equals(i.getAction())) append("USB disconnected");
        }
    };

    private void identify() throws Exception {
        runOnUiThread(() -> status.setText(""));
        append("=== USB IDENTIFY v0.5 ===");
        UsbDevice target = null;
        for (UsbDevice d: usbManager.getDeviceList().values()) {
            append(String.format(Locale.US,"DEVICE VID=%04X PID=%04X class=%02X sub=%02X proto=%02X ifaces=%d",
                    d.getVendorId(),d.getProductId(),d.getDeviceClass(),d.getDeviceSubclass(),d.getDeviceProtocol(),d.getInterfaceCount()));
            if (d.getVendorId()==AMBA_A9_VID && d.getProductId()==AMBA_A9_PID) target=d;
            else if (target==null && d.getVendorId()==DJI_VID) target=d;
        }
        if (target==null) { append("Подходящее USB-устройство не найдено."); return; }

        if (!usbManager.hasPermission(target)) {
            PendingIntent pi=PendingIntent.getBroadcast(this,0,new Intent(ACTION_USB_PERMISSION).setPackage(getPackageName()),PendingIntent.FLAG_MUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
            usbManager.requestPermission(target,pi);
            append("Android запросил USB permission. Разрешите и повторите IDENTIFY USB.");
            return;
        }

        append("TARGET selected");
        try { append("manufacturer='"+target.getManufacturerName()+"'"); } catch(Exception e){ append("manufacturer: "+e.getMessage()); }
        try { append("product='"+target.getProductName()+"'"); } catch(Exception e){ append("product: "+e.getMessage()); }
        try { append("serial='"+target.getSerialNumber()+"'"); } catch(Exception e){ append("serial: "+e.getMessage()); }
        if (target.getVendorId()==AMBA_A9_VID && target.getProductId()==AMBA_A9_PID) {
            append("MATCH: 070A:4026 = Ambarella A9 USB profile. Теперь проверяю класс интерфейса.");
        }

        UsbInterface msc=null; UsbEndpoint in=null,out=null;
        for(int i=0;i<target.getInterfaceCount();i++){
            UsbInterface f=target.getInterface(i);
            append(String.format(Locale.US,"IF%d id=%d class=0x%02X sub=0x%02X proto=0x%02X eps=%d",i,f.getId(),f.getInterfaceClass(),f.getInterfaceSubclass(),f.getInterfaceProtocol(),f.getEndpointCount()));
            UsbEndpoint fi=null,fo=null;
            for(int e=0;e<f.getEndpointCount();e++){
                UsbEndpoint ep=f.getEndpoint(e);
                append(String.format(Locale.US,"  EP%d addr=0x%02X type=%d dir=%s max=%d",e,ep.getAddress(),ep.getType(),ep.getDirection()==UsbConstants.USB_DIR_IN?"IN":"OUT",ep.getMaxPacketSize()));
                if(ep.getType()==UsbConstants.USB_ENDPOINT_XFER_BULK){ if(ep.getDirection()==UsbConstants.USB_DIR_IN) fi=ep; else fo=ep; }
            }
            if(f.getInterfaceClass()==UsbConstants.USB_CLASS_MASS_STORAGE && fi!=null && fo!=null){ msc=f; in=fi; out=fo; }
        }

        if(msc!=null){
            append("USB MODE: MASS STORAGE. Выполняю только SCSI INQUIRY (read-only).");
            scsiInquiry(target,msc,in,out);
            append("ИТОГ: дрон сейчас не предоставляет DUML/FlyC интерфейс напрямую — USB работает как накопитель/Ambarella profile.");
            append("Ничего не изменено. Пришлите этот экран полностью.");
        } else {
            append("Mass Storage interface не найден. Пришлите этот экран — по class/subclass выберу транспорт.");
        }
    }

    private void scsiInquiry(UsbDevice dev, UsbInterface intf, UsbEndpoint in, UsbEndpoint out) {
        UsbDeviceConnection c=usbManager.openDevice(dev);
        if(c==null){ append("SCSI: openDevice failed"); return; }
        try{
            if(!c.claimInterface(intf,true)){ append("SCSI: claimInterface failed"); return; }
            int t=tag.getAndIncrement();
            ByteBuffer b=ByteBuffer.allocate(31).order(ByteOrder.LITTLE_ENDIAN);
            b.putInt(0x43425355); // USBC
            b.putInt(t);
            b.putInt(36);
            b.put((byte)0x80); // IN
            b.put((byte)0); // LUN
            b.put((byte)6); // CDB len
            b.put((byte)0x12); b.put((byte)0); b.put((byte)0); b.put((byte)0); b.put((byte)36); b.put((byte)0);
            while(b.position()<31) b.put((byte)0);
            int w=c.bulkTransfer(out,b.array(),31,1200);
            append("SCSI CBW write="+w);
            if(w!=31) return;
            byte[] data=new byte[36];
            int r=c.bulkTransfer(in,data,data.length,1500);
            append("SCSI INQUIRY read="+r);
            if(r>=36){
                String vendor=ascii(data,8,8); String product=ascii(data,16,16); String rev=ascii(data,32,4);
                append("SCSI vendor='"+vendor+"' product='"+product+"' rev='"+rev+"'");
            } else if(r>0) append("SCSI data="+hex(data,r));
            byte[] csw=new byte[13];
            int cr=c.bulkTransfer(in,csw,13,1200);
            append("SCSI CSW read="+cr+(cr>0?" data="+hex(csw,cr):""));
        } catch(Throwable e){ append("SCSI inquiry error: "+e.getClass().getSimpleName()+": "+e.getMessage()); }
        finally { try{c.releaseInterface(intf);}catch(Exception ignored){} c.close(); }
    }

    private String ascii(byte[] d,int o,int n){ return new String(d,o,n, StandardCharsets.US_ASCII).trim(); }
    private String hex(byte[] d,int n){ StringBuilder s=new StringBuilder(); for(int i=0;i<Math.min(n,d.length);i++){if(i>0)s.append(' ');s.append(String.format(Locale.US,"%02X",d[i]&255));} return s.toString(); }
    private int dp(int x){ return Math.round(x*getResources().getDisplayMetrics().density); }
    private void append(String x){ runOnUiThread(() -> { status.append(x+"\n"); View p=(View)status.getParent(); if(p instanceof ScrollView)((ScrollView)p).post(() -> ((ScrollView)p).fullScroll(View.FOCUS_DOWN)); }); }
}

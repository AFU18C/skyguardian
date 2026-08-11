package dji.fcc;

import android.content.Context;
import android.hardware.usb.UsbAccessory;
import android.hardware.usb.UsbManager;
import android.os.ParcelFileDescriptor;
import android.util.Log;

import java.io.FileOutputStream;

/**
 * Minimal preflight FCC helper intended to run inside DJI Fly before its real
 * application is initialized. It uses the same top-port AOA/RCLink transport
 * DJI Fly uses, sends a fast FCC sequence, closes the accessory, then lets
 * DJI Fly continue normally.
 */
public final class AutoFcc {
    private static final String TAG = "DJI_FCC_AUTO";
    private static int seq = 1;

    // Exact two legacy frames from M4TH1EU/DJI-FCC-HACK 1.1.
    private static final byte[] LEGACY_1 = new byte[] {
            85, 13, 4, 33, 42, 31, 0, 0, 0, 0, 1, (byte)134, 32
    };
    private static final byte[] LEGACY_2 = new byte[] {
            85, 24, 4, 32, 2, 9, 0, 0, 64, 9, 39, 0, 2, 72, 0,
            (byte)255, (byte)255, 2, 0, 0, 0, 0, (byte)129, 31
    };

    private AutoFcc() {}

    public static void install(Context context) {
        ParcelFileDescriptor pfd = null;
        FileOutputStream out = null;
        try {
            UsbManager usb = (UsbManager) context.getSystemService(Context.USB_SERVICE);
            if (usb == null) return;

            UsbAccessory accessory = findDjiAccessory(usb);
            if (accessory == null) {
                Log.i(TAG, "No DJI AOA accessory at startup; normal DJI Fly startup continues");
                return;
            }
            if (!usb.hasPermission(accessory)) {
                Log.i(TAG, "DJI accessory present but USB permission is not granted yet");
                return;
            }

            pfd = usb.openAccessory(accessory);
            if (pfd == null) {
                Log.w(TAG, "openAccessory returned null");
                return;
            }
            out = new FileOutputStream(pfd.getFileDescriptor());

            // AOA bootstrap used by RC-N1/RC-N2/RC-N3.
            send(out, build(0x02, 0x40, 0x00, 0x00, 0x1F, hex("000001")));
            send(out, build(0x02, 0x40, 0x00, 0x00, 0x00, hex("000001")));

            // Preserve the exact Mini 4K/RC-N1C legacy patch that is already
            // proven on the user's hardware, but carry it inside RCLink/AOA.
            send(out, LEGACY_1);
            send(out, LEGACY_2);

            // WLM FCC command seen in DJI's own command path.
            send(out, build(0xA2, 0x40, 0x51, 0x04, 0xEE, new byte[0]));

            // Compact universal FCC sequence: region + 2.4/5.8 power + commit.
            send(out, build(0x02, 0x20, 0x06, 0x72, 0x06, hex("00000000000100")));
            send(out, build(0x02, 0x20, 0x09, 0x27, 0x09, hex("00024800ffff0200000000")));
            send(out, build(0x02, 0x20, 0x09, 0x27, 0x09, hex("00026300ffff0300000000")));
            send(out, build(0x02, 0x20, 0x06, 0x72, 0x06, hex("000000000001ff")));

            out.flush();
            sleep(180);
            Log.i(TAG, "FCC preflight sequence sent successfully");
        } catch (Throwable t) {
            // Never prevent DJI Fly from starting if FCC preflight fails.
            Log.e(TAG, "FCC preflight failed", t);
        } finally {
            try { if (out != null) out.close(); } catch (Throwable ignored) {}
            try { if (pfd != null) pfd.close(); } catch (Throwable ignored) {}
        }
    }

    private static UsbAccessory findDjiAccessory(UsbManager usb) {
        UsbAccessory[] list = usb.getAccessoryList();
        if (list == null) return null;
        for (UsbAccessory a : list) {
            String manufacturer = a.getManufacturer();
            if (manufacturer != null && manufacturer.toLowerCase().contains("dji")) return a;
        }
        return list.length > 0 ? list[0] : null;
    }

    private static void send(FileOutputStream out, byte[] duml) throws Exception {
        byte[] wire = wrapRclink(duml);
        out.write(wire);
        out.flush();
        sleep(70);
    }

    private static byte[] wrapRclink(byte[] duml) {
        byte[] out = new byte[8 + duml.length];
        out[0] = 0x55;
        out[1] = (byte) 0xCC;
        out[2] = 0x49;
        out[3] = 0x57;
        int n = duml.length;
        out[4] = (byte) n;
        out[5] = (byte) (n >>> 8);
        out[6] = (byte) (n >>> 16);
        out[7] = (byte) (n >>> 24);
        System.arraycopy(duml, 0, out, 8, n);
        return out;
    }

    private static byte[] build(int sender, int cmdType, int cmdSet, int cmdId, int dst, byte[] payload) {
        int total = payload.length + 13;
        byte[] out = new byte[total];
        out[0] = 0x55;
        out[1] = (byte) (total & 0xFF);
        out[2] = (byte) (((total >>> 8) & 0x03) | 0x04);
        out[3] = (byte) crc8(out, 3);
        out[4] = (byte) sender;
        out[5] = (byte) dst;
        int s = seq++ & 0xFFFF;
        out[6] = (byte) s;
        out[7] = (byte) (s >>> 8);
        out[8] = (byte) cmdType;
        out[9] = (byte) cmdSet;
        out[10] = (byte) cmdId;
        System.arraycopy(payload, 0, out, 11, payload.length);
        int crc = crc16(out, 11 + payload.length);
        out[total - 2] = (byte) crc;
        out[total - 1] = (byte) (crc >>> 8);
        return out;
    }

    private static int crc8(byte[] data, int len) {
        int c = 0x77;
        for (int i = 0; i < len; i++) {
            c ^= data[i] & 0xFF;
            for (int b = 0; b < 8; b++) {
                c = ((c & 1) != 0) ? ((c >>> 1) ^ 0x8C) : (c >>> 1);
            }
        }
        return c & 0xFF;
    }

    private static int crc16(byte[] data, int len) {
        int c = 0x3692;
        for (int i = 0; i < len; i++) {
            c ^= data[i] & 0xFF;
            for (int b = 0; b < 8; b++) {
                c = ((c & 1) != 0) ? ((c >>> 1) ^ 0x8408) : (c >>> 1);
            }
        }
        return c & 0xFFFF;
    }

    private static byte[] hex(String s) {
        int n = s.length() / 2;
        byte[] out = new byte[n];
        for (int i = 0; i < n; i++) {
            out[i] = (byte) Integer.parseInt(s.substring(i * 2, i * 2 + 2), 16);
        }
        return out;
    }

    private static void sleep(long ms) {
        try { Thread.sleep(ms); } catch (InterruptedException ignored) { Thread.currentThread().interrupt(); }
    }
}

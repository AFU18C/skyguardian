package com.openai.mini4kgps;

import android.hardware.usb.UsbAccessory;
import android.hardware.usb.UsbManager;
import android.os.ParcelFileDescriptor;

import java.io.ByteArrayOutputStream;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.util.Locale;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/** Package-private AOA/RCLink session used by read-only discovery activities. */
final class ProbeAoaSession {
    private final ParcelFileDescriptor pfd;
    private final FileInputStream in;
    private final FileOutputStream out;
    private final AtomicInteger seq;
    private final AtomicBoolean alive = new AtomicBoolean(true);
    private final LinkedBlockingQueue<DumlV2.Frame> rx = new LinkedBlockingQueue<>(12000);
    private final Object writeLock = new Object();
    private final Thread reader;
    private Thread keepalive;
    private volatile int route = 0x5749;
    private int headerPos;
    private final byte[] header = new byte[8];
    private long bodyLeft;
    private int bodyType = -1;
    private ByteArrayOutputStream body;

    static ProbeAoaSession open(UsbManager manager, UsbAccessory accessory, AtomicInteger seq) {
        try {
            ParcelFileDescriptor p = manager.openAccessory(accessory);
            if (p == null) return null;
            return new ProbeAoaSession(p, seq);
        } catch (Exception e) {
            return null;
        }
    }

    private ProbeAoaSession(ParcelFileDescriptor p, AtomicInteger seq) {
        this.pfd = p;
        this.seq = seq;
        in = new FileInputStream(p.getFileDescriptor());
        out = new FileOutputStream(p.getFileDescriptor());
        reader = new Thread(this::readLoop, "probe-aoa-rx");
        reader.setDaemon(true);
        reader.start();
    }

    void startProtocol() throws IOException {
        byte[] boot = new byte[]{0, 0, 1};
        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_AIRCRAFT_PROXY, 0,
                seq.getAndIncrement() & 0xFFFF, DumlV2.CMDSET_GENERAL, 0x00, boot, false));
        sleep(5);
        sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_ANY, 0,
                seq.getAndIncrement() & 0xFFFF, DumlV2.CMDSET_GENERAL, 0x00, boot, false));
        sleep(10);
        startKeepalive();
    }

    private void startKeepalive() {
        keepalive = new Thread(() -> {
            sleep(2000);
            byte[] p = new byte[]{1, 1, 0, (byte) 0xFF, (byte) 0xFF, 0x20, 0, 0};
            while (alive.get()) {
                try {
                    sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, DumlV2.DEV_REMOTE_RADIO, 0,
                            seq.getAndIncrement() & 0xFFFF, 0x06, 0x77, p, false));
                    sleep(4);
                    sendDuml(DumlV2.packet(DumlV2.DEV_MOBILE_APP, 0, 14, 0,
                            seq.getAndIncrement() & 0xFFFF, 0x06, 0x77, p, false));
                } catch (Exception e) {
                    break;
                }
                sleep(2400);
            }
        }, "probe-aoa-keepalive");
        keepalive.setDaemon(true);
        keepalive.start();
    }

    DumlV2.Frame poll(long ms) throws InterruptedException {
        return rx.poll(ms, TimeUnit.MILLISECONDS);
    }

    String routeString() {
        return String.format(Locale.US, "0x%04X", route);
    }

    void sendDuml(byte[] duml) throws IOException {
        int n = duml.length;
        byte[] w = new byte[8 + n];
        w[0] = 0x55;
        w[1] = (byte) 0xCC;
        w[2] = (byte) (route & 0xFF);
        w[3] = (byte) ((route >>> 8) & 0xFF);
        w[4] = (byte) (n & 0xFF);
        w[5] = (byte) ((n >>> 8) & 0xFF);
        w[6] = (byte) ((n >>> 16) & 0xFF);
        w[7] = (byte) ((n >>> 24) & 0xFF);
        System.arraycopy(duml, 0, w, 8, n);
        synchronized (writeLock) {
            out.write(w);
            out.flush();
        }
        sleep(2);
    }

    private void readLoop() {
        byte[] b = new byte[16384];
        try {
            while (alive.get()) {
                int n = in.read(b);
                if (n < 0) break;
                if (n > 0) feed(b, n);
            }
        } catch (Exception ignored) {
        } finally {
            alive.set(false);
        }
    }

    private void feed(byte[] a, int n) {
        for (int i = 0; i < n; i++) {
            int x = a[i] & 0xFF;
            if (bodyLeft > 0) {
                if (body != null) body.write(x);
                bodyLeft--;
                if (bodyLeft == 0) finishUnit();
                continue;
            }
            if (headerPos == 0) {
                if (x == 0x55) {
                    header[0] = 0x55;
                    headerPos = 1;
                }
                continue;
            }
            if (headerPos == 1) {
                if (x == 0xCC) {
                    header[1] = (byte) 0xCC;
                    headerPos = 2;
                } else if (x == 0x55) {
                    header[0] = 0x55;
                    headerPos = 1;
                } else {
                    headerPos = 0;
                }
                continue;
            }
            header[headerPos++] = (byte) x;
            if (headerPos == 8) {
                int type = (header[2] & 0xFF) | ((header[3] & 0xFF) << 8);
                long len = ((long) header[4] & 0xFF)
                        | (((long) header[5] & 0xFF) << 8)
                        | (((long) header[6] & 0xFF) << 16)
                        | (((long) header[7] & 0xFF) << 24);
                headerPos = 0;
                if (len < 0 || len > 0x200000L) {
                    bodyLeft = 0;
                    body = null;
                    bodyType = -1;
                    continue;
                }
                bodyType = type;
                bodyLeft = len;
                if (type == 0x5749 || type == 0x7530) {
                    route = type;
                    body = new ByteArrayOutputStream((int) Math.min(len, 32768));
                } else {
                    body = null;
                }
                if (bodyLeft == 0) finishUnit();
            }
        }
    }

    private void finishUnit() {
        if ((bodyType == 0x5749 || bodyType == 0x7530) && body != null) {
            for (DumlV2.Frame f : DumlV2.frames(body.toByteArray())) {
                if (!rx.offer(f)) {
                    rx.poll();
                    rx.offer(f);
                }
            }
        }
        bodyType = -1;
        body = null;
        bodyLeft = 0;
    }

    void close() {
        alive.set(false);
        try {
            if (keepalive != null) keepalive.interrupt();
        } catch (Exception ignored) {
        }
        try {
            reader.interrupt();
        } catch (Exception ignored) {
        }
        try {
            in.close();
        } catch (Exception ignored) {
        }
        try {
            out.close();
        } catch (Exception ignored) {
        }
        try {
            pfd.close();
        } catch (Exception ignored) {
        }
    }

    private static void sleep(long ms) {
        try {
            Thread.sleep(ms);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }
}

package com.openai.mini4kgps;

import java.io.ByteArrayOutputStream;
import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.nio.charset.StandardCharsets;
import java.util.Arrays;

final class Duml {
    static final int DEV_PC = 10;
    static final int DEV_FLYCONTROLLER = 3;
    static final int CMDSET_FLYC = 3;

    private static final int[] CRC8_TABLE = new int[256];
    private static final int[] CRC16_TABLE = new int[256];

    static {
        for (int i = 0; i < 256; i++) {
            int c = i;
            for (int b = 0; b < 8; b++) c = ((c & 1) != 0) ? ((c >>> 1) ^ 0x8C) : (c >>> 1);
            CRC8_TABLE[i] = c & 0xFF;
        }
        for (int i = 0; i < 256; i++) {
            int c = i;
            for (int b = 0; b < 8; b++) c = ((c & 1) != 0) ? ((c >>> 1) ^ 0x8408) : (c >>> 1);
            CRC16_TABLE[i] = c & 0xFFFF;
        }
    }

    private Duml() {}

    static long parameterHash(String name) {
        byte[] bs = name.getBytes(StandardCharsets.US_ASCII);
        long h = 0;
        for (byte b : bs) {
            h = (((h & 0xFFFFFFFFL) << 8) + (b & 0xFFL)) % 0xFFFFFFFBL;
        }
        return h & 0xFFFFFFFFL;
    }

    static byte[] le32(long v) {
        return ByteBuffer.allocate(4).order(ByteOrder.LITTLE_ENDIAN).putInt((int) v).array();
    }

    static byte[] packet(int seq, int cmdId, byte[] payload) {
        int length = 11 + payload.length + 2;
        byte[] p = new byte[length];
        ByteBuffer bb = ByteBuffer.wrap(p).order(ByteOrder.LITTLE_ENDIAN);
        bb.put((byte) 0x55);
        bb.putShort((short) ((1 << 10) | (length & 0x03FF)));
        bb.put((byte) 0);
        bb.put((byte) DEV_PC);
        bb.put((byte) DEV_FLYCONTROLLER);
        bb.putShort((short) (seq & 0xFFFF));
        bb.put((byte) 0x40);
        bb.put((byte) CMDSET_FLYC);
        bb.put((byte) cmdId);
        bb.put(payload);
        p[3] = (byte) crc8(p, 3);
        int crc = crc16(p, length - 2);
        p[length - 2] = (byte) (crc & 0xFF);
        p[length - 1] = (byte) ((crc >>> 8) & 0xFF);
        return p;
    }

    static int crc8(byte[] p, int len) {
        int c = 0x77;
        for (int i = 0; i < len; i++) c = CRC8_TABLE[((p[i] & 0xFF) ^ c) & 0xFF];
        return c & 0xFF;
    }

    static int crc16(byte[] p, int len) {
        int v = 0x3692;
        for (int i = 0; i < len; i++) v = ((v >>> 8) ^ CRC16_TABLE[((p[i] & 0xFF) ^ v) & 0xFF]) & 0xFFFF;
        return v;
    }

    static Frame findFrame(ByteArrayOutputStream stream, int wantedSeq, int wantedCmdId) {
        byte[] all = stream.toByteArray();
        for (int off = 0; off + 13 <= all.length; off++) {
            if ((all[off] & 0xFF) != 0x55) continue;
            int tag = (all[off + 1] & 0xFF) | ((all[off + 2] & 0xFF) << 8);
            int len = tag & 0x03FF;
            if (len < 13 || off + len > all.length) continue;
            byte[] f = Arrays.copyOfRange(all, off, off + len);
            if ((f[3] & 0xFF) != crc8(f, 3)) continue;
            int gotCrc = (f[len - 2] & 0xFF) | ((f[len - 1] & 0xFF) << 8);
            if (gotCrc != crc16(f, len - 2)) continue;
            int seq = (f[6] & 0xFF) | ((f[7] & 0xFF) << 8);
            boolean response = (f[8] & 0x80) != 0;
            int cmdSet = f[9] & 0xFF;
            int cmdId = f[10] & 0xFF;
            if (response && cmdSet == CMDSET_FLYC && cmdId == wantedCmdId && (seq == wantedSeq || wantedSeq < 0)) {
                return new Frame(seq, cmdId, Arrays.copyOfRange(f, 11, len - 2), f);
            }
        }
        return null;
    }

    static final class Frame {
        final int seq;
        final int cmdId;
        final byte[] payload;
        final byte[] raw;
        Frame(int seq, int cmdId, byte[] payload, byte[] raw) {
            this.seq = seq;
            this.cmdId = cmdId;
            this.payload = payload;
            this.raw = raw;
        }
    }

    static final class ParamInfo {
        final int status;
        final int typeId;
        final int size;
        final int attribute;
        final long min;
        final long max;
        final long def;
        final String name;

        ParamInfo(int status, int typeId, int size, int attribute, long min, long max, long def, String name) {
            this.status = status;
            this.typeId = typeId;
            this.size = size;
            this.attribute = attribute;
            this.min = min;
            this.max = max;
            this.def = def;
            this.name = name;
        }

        static ParamInfo parse2015(byte[] q) {
            if (q == null || q.length < 19) return null;
            int status = q[0] & 0xFF;
            int type = u16(q, 1);
            int size = u16(q, 3);
            int attr = u16(q, 5);
            long min = u32(q, 7);
            long max = u32(q, 11);
            long def = u32(q, 15);
            int end = 19;
            while (end < q.length && q[end] != 0) end++;
            String name = new String(q, 19, Math.max(0, end - 19), StandardCharsets.UTF_8);
            return new ParamInfo(status, type, size, attr, min, max, def, name);
        }
    }

    static long u32(byte[] b, int o) {
        return ((long) b[o] & 0xFF) |
                (((long) b[o + 1] & 0xFF) << 8) |
                (((long) b[o + 2] & 0xFF) << 16) |
                (((long) b[o + 3] & 0xFF) << 24);
    }

    static int u16(byte[] b, int o) {
        return (b[o] & 0xFF) | ((b[o + 1] & 0xFF) << 8);
    }

    static String hex(byte[] a) {
        StringBuilder s = new StringBuilder();
        for (byte b : a) s.append(String.format("%02X ", b & 0xFF));
        return s.toString().trim();
    }
}

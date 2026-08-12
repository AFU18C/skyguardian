package com.openai.mini4kgps;

import java.io.ByteArrayOutputStream;
import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;

final class DumlV2 {
    static final int DEV_MOBILE_APP = 2;
    static final int DEV_FLYCONTROLLER = 3;
    static final int DEV_PC = 10;
    static final int DEV_AIRCRAFT_PROXY = 31;
    static final int CMDSET_GENERAL = 0;
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

    private DumlV2() {}

    static long parameterHash(String name) {
        byte[] bs = name.getBytes(StandardCharsets.US_ASCII);
        long h = 0;
        for (byte b : bs) h = (((h & 0xFFFFFFFFL) << 8) + (b & 0xFFL)) % 0xFFFFFFFBL;
        return h & 0xFFFFFFFFL;
    }

    static byte[] le16(int v) {
        return new byte[]{(byte) (v & 0xFF), (byte) ((v >>> 8) & 0xFF)};
    }

    static byte[] le32(long v) {
        return ByteBuffer.allocate(4).order(ByteOrder.LITTLE_ENDIAN).putInt((int) v).array();
    }

    static byte[] concat(byte[]... parts) {
        int n = 0;
        for (byte[] p : parts) n += p.length;
        byte[] out = new byte[n];
        int o = 0;
        for (byte[] p : parts) {
            System.arraycopy(p, 0, out, o, p.length);
            o += p.length;
        }
        return out;
    }

    static byte[] packet(int senderType, int senderIndex, int receiverType, int receiverIndex,
                         int seq, int cmdSet, int cmdId, byte[] payload) {
        int length = 11 + payload.length + 2;
        byte[] p = new byte[length];
        ByteBuffer bb = ByteBuffer.wrap(p).order(ByteOrder.LITTLE_ENDIAN);
        bb.put((byte) 0x55);
        bb.putShort((short) ((1 << 10) | (length & 0x03FF)));
        bb.put((byte) 0);
        bb.put((byte) ((senderType & 0x1F) | ((senderIndex & 0x07) << 5)));
        bb.put((byte) ((receiverType & 0x1F) | ((receiverIndex & 0x07) << 5)));
        bb.putShort((short) (seq & 0xFFFF));
        bb.put((byte) 0x40);
        bb.put((byte) (cmdSet & 0xFF));
        bb.put((byte) (cmdId & 0xFF));
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

    static List<Frame> frames(ByteArrayOutputStream stream) {
        return frames(stream.toByteArray());
    }

    static List<Frame> frames(byte[] all) {
        List<Frame> out = new ArrayList<>();
        for (int off = 0; off + 13 <= all.length; off++) {
            if ((all[off] & 0xFF) != 0x55) continue;
            int tag = (all[off + 1] & 0xFF) | ((all[off + 2] & 0xFF) << 8);
            int len = tag & 0x03FF;
            if (len < 13 || off + len > all.length) continue;
            byte[] f = Arrays.copyOfRange(all, off, off + len);
            if ((f[3] & 0xFF) != crc8(f, 3)) continue;
            int gotCrc = (f[len - 2] & 0xFF) | ((f[len - 1] & 0xFF) << 8);
            if (gotCrc != crc16(f, len - 2)) continue;
            int sender = f[4] & 0xFF;
            int receiver = f[5] & 0xFF;
            int seq = (f[6] & 0xFF) | ((f[7] & 0xFF) << 8);
            boolean response = (f[8] & 0x80) != 0;
            int cmdSet = f[9] & 0xFF;
            int cmdId = f[10] & 0xFF;
            out.add(new Frame(sender & 0x1F, (sender >>> 5) & 7,
                    receiver & 0x1F, (receiver >>> 5) & 7,
                    seq, response, cmdSet, cmdId,
                    Arrays.copyOfRange(f, 11, len - 2), f));
            off += len - 1;
        }
        return out;
    }

    static Frame findFrame(ByteArrayOutputStream stream, int wantedSeq, int wantedCmdSet,
                           int wantedCmdId, boolean allowDifferentSeq) {
        Frame fallback = null;
        for (Frame f : frames(stream)) {
            if (!f.response || f.cmdSet != wantedCmdSet || f.cmdId != wantedCmdId) continue;
            if (f.seq == wantedSeq) return f;
            if (allowDifferentSeq && fallback == null) fallback = f;
        }
        return fallback;
    }

    static final class Frame {
        final int senderType, senderIndex, receiverType, receiverIndex, seq, cmdSet, cmdId;
        final boolean response;
        final byte[] payload, raw;
        Frame(int senderType, int senderIndex, int receiverType, int receiverIndex,
              int seq, boolean response, int cmdSet, int cmdId, byte[] payload, byte[] raw) {
            this.senderType = senderType;
            this.senderIndex = senderIndex;
            this.receiverType = receiverType;
            this.receiverIndex = receiverIndex;
            this.seq = seq;
            this.response = response;
            this.cmdSet = cmdSet;
            this.cmdId = cmdId;
            this.payload = payload;
            this.raw = raw;
        }
        String shortDescription() {
            return "s=" + senderType + ":" + senderIndex + " r=" + receiverType + ":" + receiverIndex +
                    " seq=" + seq + " " + (response ? "RSP" : "REQ") +
                    " set=0x" + Integer.toHexString(cmdSet) + " id=0x" + Integer.toHexString(cmdId) +
                    " payload=" + hex(payload);
        }
    }

    static final class ParamInfo2015 {
        final int status, typeId, size, attribute;
        final long min, max, def;
        final String name;
        ParamInfo2015(int status, int typeId, int size, int attribute, long min, long max, long def, String name) {
            this.status = status; this.typeId = typeId; this.size = size; this.attribute = attribute;
            this.min = min; this.max = max; this.def = def; this.name = name;
        }
        static ParamInfo2015 parse(byte[] q) {
            if (q == null || q.length < 19) return null;
            return new ParamInfo2015(q[0] & 0xFF, u16(q, 1), u16(q, 3), u16(q, 5),
                    u32(q, 7), u32(q, 11), u32(q, 15), zstr(q, 19));
        }
    }

    static final class TableAttr2017 {
        final int status, tableNo;
        final long entriesCrc, entriesNum;
        TableAttr2017(int status, int tableNo, long entriesCrc, long entriesNum) {
            this.status = status; this.tableNo = tableNo; this.entriesCrc = entriesCrc; this.entriesNum = entriesNum;
        }
        static TableAttr2017 parse(byte[] q) {
            if (q == null || q.length < 12) return null;
            return new TableAttr2017(u16(q, 0), u16(q, 2), u32(q, 4), u32(q, 8));
        }
    }

    static final class ParamInfo2017 {
        final int status, tableNo, paramIndex, typeId, size;
        final long def, min, max;
        final String name;
        ParamInfo2017(int status, int tableNo, int paramIndex, int typeId, int size,
                      long def, long min, long max, String name) {
            this.status = status; this.tableNo = tableNo; this.paramIndex = paramIndex;
            this.typeId = typeId; this.size = size; this.def = def; this.min = min; this.max = max; this.name = name;
        }
        static ParamInfo2017 parse(byte[] q) {
            if (q == null || q.length < 22) return null;
            return new ParamInfo2017(u16(q, 0), u16(q, 2), u16(q, 4), u16(q, 6), u16(q, 8),
                    u32(q, 10), u32(q, 14), u32(q, 18), zstr(q, 22));
        }
    }

    static String zstr(byte[] q, int start) {
        if (q == null || start >= q.length) return "";
        int end = start;
        while (end < q.length && q[end] != 0) end++;
        return new String(q, start, Math.max(0, end - start), StandardCharsets.UTF_8);
    }

    static long u32(byte[] b, int o) {
        if (b == null || o < 0 || o + 4 > b.length) return -1;
        return ((long) b[o] & 0xFF) | (((long) b[o + 1] & 0xFF) << 8) |
                (((long) b[o + 2] & 0xFF) << 16) | (((long) b[o + 3] & 0xFF) << 24);
    }

    static int u16(byte[] b, int o) {
        if (b == null || o < 0 || o + 2 > b.length) return -1;
        return (b[o] & 0xFF) | ((b[o + 1] & 0xFF) << 8);
    }

    static String hex(byte[] a) {
        if (a == null) return "";
        StringBuilder s = new StringBuilder();
        for (byte b : a) {
            if (s.length() > 0) s.append(' ');
            s.append(String.format("%02X", b & 0xFF));
        }
        return s.toString();
    }
}

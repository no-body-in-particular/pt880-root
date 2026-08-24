#!/usr/bin/env python3
"""Map every protocol opcode string in a dex to the method that references it.

Why not a decompiler: we do not need Java source, we need "which handler owns
BPTM, and what else does that handler touch". A const-string cross-reference
gives exactly that and is deterministic - no jadx, no JVM, no download.

Dex instruction stream has to be walked properly, not scanned for byte
patterns: 0x1a happens to be a common data byte, so a naive scan invents
references. The length table below is the standard Dalvik format widths, so
each instruction advances by its real size.
"""
import struct
import sys

# Instruction size in 16-bit code units, indexed by opcode byte.
#
# This is an ODEX (dey), so the dex inside it has been rewritten with Dalvik's
# *quick* opcodes in 0xe3-0xff - the range that is unused in a plain dex.
# Treating those as 1 unit desynced the walk in 84% of methods, which the
# desync guard then correctly threw away. The table below is the full odex set.
SZ = [None] * 256
def _w(lo, hi, n):
    for o in range(lo, hi + 1):
        SZ[o] = n

for _o, _n in {0x00:1,0x01:1,0x02:2,0x03:3,0x04:1,0x05:2,0x06:3,0x07:1,0x08:2,
               0x09:3,0x0a:1,0x0b:1,0x0c:1,0x0d:1,0x0e:1,0x0f:1,0x10:1,0x11:1,
               0x12:1,0x13:2,0x14:3,0x15:2,0x16:2,0x17:3,0x18:5,0x19:2,0x1a:2,
               0x1b:3,0x1c:2,0x1d:1,0x1e:1,0x1f:2,0x20:2,0x21:1,0x22:2,0x23:2,
               0x24:3,0x25:3,0x26:3,0x27:1,0x28:1,0x29:2,0x2a:3,0x2b:3,0x2c:3,
               }.items():
    SZ[_o] = _n
_w(0x2d, 0x31, 2)          # cmpl/cmpg/cmp-long          23x
_w(0x32, 0x37, 2)          # if-<test>                   22t
_w(0x38, 0x3d, 2)          # if-<test>z                  21t
_w(0x44, 0x51, 2)          # aget / aput                 23x
_w(0x52, 0x5f, 2)          # iget / iput                 22c
_w(0x60, 0x6d, 2)          # sget / sput                 21c
_w(0x6e, 0x72, 3)          # invoke-<kind>               35c
_w(0x74, 0x78, 3)          # invoke-<kind>/range         3rc
_w(0x7b, 0x8f, 1)          # unop                        12x
_w(0x90, 0xaf, 2)          # binop                       23x
_w(0xb0, 0xcf, 1)          # binop/2addr                 12x
_w(0xd0, 0xd7, 2)          # binop/lit16                 22s
_w(0xd8, 0xe2, 2)          # binop/lit8                  22b
# --- odex-only quick / volatile opcodes ---
_w(0xe3, 0xeb, 2)          # +iget/+iput/+sget/+sput volatile
SZ[0xec] = 1               # ^breakpoint
SZ[0xed] = 2               # ^throw-verification-error
_w(0xee, 0xef, 3)          # +execute-inline[/range]
SZ[0xf0] = 3               # +invoke-object-init/range
SZ[0xf1] = 1               # +return-void-barrier
_w(0xf2, 0xf7, 2)          # +iget-quick / +iput-quick family
_w(0xf8, 0xfb, 3)          # +invoke-virtual/super-quick[/range]
_w(0xfc, 0xfe, 2)          # +iput-object / +sget-object / +sput-object volatile


def uleb(d, o):
    r = s = 0
    while True:
        b = d[o]; o += 1
        r |= (b & 0x7f) << s
        if not (b & 0x80):
            return r, o
        s += 7


class Dex:
    def __init__(self, path):
        self.d = d = open(path, "rb").read()
        (self.str_n, self.str_off) = struct.unpack_from("<II", d, 0x38)
        (self.type_n, self.type_off) = struct.unpack_from("<II", d, 0x40)
        (self.proto_n, self.proto_off) = struct.unpack_from("<II", d, 0x48)
        (self.fld_n, self.fld_off) = struct.unpack_from("<II", d, 0x50)
        (self.mth_n, self.mth_off) = struct.unpack_from("<II", d, 0x58)
        (self.cls_n, self.cls_off) = struct.unpack_from("<II", d, 0x60)
        self._scache = {}

    def string(self, i):
        if not (0 <= i < self.str_n):
            return None
        if i in self._scache:
            return self._scache[i]
        off, = struct.unpack_from("<I", self.d, self.str_off + i * 4)
        n, p = uleb(self.d, off)
        raw = self.d[p:p + n * 3]
        end = raw.find(b"\x00")
        if end >= 0:
            raw = raw[:end]
        s = raw.decode("utf-8", "replace")[:n]
        self._scache[i] = s
        return s

    def type_name(self, i):
        idx, = struct.unpack_from("<I", self.d, self.type_off + i * 4)
        return self.string(idx)

    def method(self, i):
        cls, proto, name = struct.unpack_from("<HHI", self.d, self.mth_off + i * 8)
        return self.type_name(cls), self.string(name)

    def methods_with_code(self):
        """Yield (class_name, method_name, code_off) for every method with code."""
        d = self.d
        for c in range(self.cls_n):
            base = self.cls_off + c * 32
            cls_idx, = struct.unpack_from("<I", d, base)
            cdata, = struct.unpack_from("<I", d, base + 24)
            if not cdata:
                continue
            cname = self.type_name(cls_idx)
            o = cdata
            sf, o = uleb(d, o); inf, o = uleb(d, o)
            dm, o = uleb(d, o); vm, o = uleb(d, o)
            for _ in range(sf):
                _, o = uleb(d, o); _, o = uleb(d, o)
            for _ in range(inf):
                _, o = uleb(d, o); _, o = uleb(d, o)
            for group in (dm, vm):
                midx = 0
                for _ in range(group):
                    diff, o = uleb(d, o)
                    _acc, o = uleb(d, o)
                    coff, o = uleb(d, o)
                    midx += diff
                    if coff:
                        yield cname, self.method(midx)[1], coff

    def strings_in(self, code_off):
        """Const-string targets in one method, or None if the walk desynced."""
        d = self.d
        insns_size, = struct.unpack_from("<I", d, code_off + 12)
        p = code_off + 16
        end = p + insns_size * 2
        if insns_size == 0 or end > len(d):
            return None
        out = []
        while p < end:
            op = d[p]
            n = SZ[op]
            # An unknown leading byte means the walk has desynced. Everything
            # collected after that point would be invented, so drop the whole
            # method rather than emit plausible-looking noise.
            if not n:
                return None
            if op == 0x1a:
                v = struct.unpack_from("<H", d, p + 2)[0]
                if v >= self.str_n:
                    return None
                out.append(v)
            elif op == 0x1b:
                v = struct.unpack_from("<I", d, p + 2)[0]
                if v >= self.str_n:
                    return None
                out.append(v)
            elif op == 0x00 and p + 2 < end:      # nop / payload tables
                ident, = struct.unpack_from("<H", d, p)
                if ident == 0x0100:               # packed-switch payload
                    sz, = struct.unpack_from("<H", d, p + 2)
                    n = 4 + sz * 2
                elif ident == 0x0200:             # sparse-switch payload
                    sz, = struct.unpack_from("<H", d, p + 2)
                    n = 2 + sz * 4
                elif ident == 0x0300:             # fill-array-data payload
                    w, = struct.unpack_from("<H", d, p + 2)
                    sz, = struct.unpack_from("<I", d, p + 4)
                    n = (w * sz + 1) // 2 + 4
            p += n * 2
        return out


if __name__ == "__main__":
    dx = Dex(sys.argv[1] if len(sys.argv) > 1 else "classes_from_odex.dex")
    print("strings=%d methods_scanned=?" % dx.str_n)
    n = 0
    for cn, mn, co in dx.methods_with_code():
        n += 1
    print("methods with code: %d" % n)

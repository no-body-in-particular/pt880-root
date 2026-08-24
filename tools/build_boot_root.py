#!/usr/bin/env python3
"""Rooted boot image: stock ramdisk + default.prop edits + neutered adbd drop.

Why patch the syscall wrappers rather than adbd's logic
-------------------------------------------------------
This adbd is vendor-modified and does NOT reference ro.secure at all - only
ro.debuggable, service.adb.root and ro.adb.secure. All of those are satisfied
on the device (ro.debuggable=1, service.adb.root=1, SELinux Disabled) and it
still drops to uid 2000. It is statically linked ET_EXEC ARM32 with no PLT, and
its string references resolve through neither literal pools nor movw/movt, so
locating the decision branch was proving slow.

The wrappers are a much better target. bionic's syscall stubs look like:

    0002378c  b   #0x262a0        (tail of the previous stub)
    00023790  mov ip, r7          <-- ENTRY
    00023794  mov r7, #0xd5       (__NR_setuid32)
    00023798  svc #0
    0002379c  mov r7, ip
    000237a0  cmn r0, #0x1000
    000237a4  bxls lr             (success: return r0)

Overwriting the entry with "mov r0,#0 / bx lr" makes the call return success
without performing the syscall. Whatever path adbd takes to drop privileges, it
silently fails to, and adbd stays root. This is independent of the decision
logic entirely.

adbd is a static binary, so this libc copy is private to it - nothing else on
the system is affected. All three drop-related calls are neutered:

    setgid32     file 0x010104
    setgroups32  file 0x010144
    setuid32     file 0x01b790

Size is unchanged (in-place, 8 bytes each), so the ramdisk budget is unaffected
and sbin/sec_openssl stays intact.
"""
import struct
import gzip
import hashlib
import io
import sys

STOCK = r"C:\wpull\dump_watch2\boot_stock_exact.img"
OUT = r"C:\wpull\dump_watch2\boot_root_fdl.img"

MOV_R0_0 = 0xE3A00000
BX_LR = 0xE12FFF1E
# entry offsets inside sbin/adbd (file offsets in the extracted binary)
ADBD_SITES = [(0x010104, "setgid32"), (0x010144, "setgroups32"),
              (0x01b790, "setuid32"),
              # prctl is the one that matters for a writable /system. adbd keeps
              # FULL CapPrm/CapEff for itself and only shrinks its BOUNDING set
              # to 0xc0 (CAP_SETUID|CAP_SETGID) via prctl(PR_CAPBSET_DROP).
              # execve masks a child's permitted set by the bounding set, so
              # every shell adbd spawns inherits 0xc0 and cannot remount.
              # Measured: init CapBnd=3fffffffff, adbd CapBnd=c0, shell Cap*=c0.
              (0x010164, "prctl")]

d = open(STOCK, "rb").read()
q = d[0x200:]
ks, ka, rs, ra, ss, sa, tags, psz = struct.unpack("<8I", q[8:40])
ds = struct.unpack("<I", q[40:44])[0]
al = lambda n: (n + psz - 1) // psz * psz
ko = al(psz); ro = ko + al(ks); do = ro + al(rs) + al(ss)
kernel = q[ko:ko + ks]; dt = q[do:do + ds]
raw = gzip.decompress(q[ro:ro + rs])

# ---- walk the cpio, edit default.prop and sbin/adbd ----
out = bytearray()
i = 0
edited = {}
while i + 110 <= len(raw):
    if raw[i:i + 6] != b"070701":
        break
    nsz = int(raw[i + 94:i + 102], 16)
    fsz = int(raw[i + 54:i + 62], 16)
    nm = raw[i + 110:i + 110 + nsz - 1].decode("latin-1")
    hs = (110 + nsz + 3) & ~3
    hdr = bytearray(raw[i:i + hs])
    data = raw[i + hs:i + hs + fsz]

    if nm == "default.prop":
        import re
        t = data.decode("latin-1")
        t = t.replace("ro.secure=1", "ro.secure=0")
        t = t.replace("ro.debuggable=0", "ro.debuggable=1")
        if "ro.adb.secure" in t:
            t = re.sub(r"ro\.adb\.secure=\d", "ro.adb.secure=0", t)
        else:
            t = t.rstrip("\n") + "\nro.adb.secure=0\n"
        if "persist.sys.usb.config" in t:
            t = re.sub(r"persist\.sys\.usb\.config=[^\n]*",
                       "persist.sys.usb.config=mtp,adb", t)
        else:
            t = t.rstrip("\n") + "\npersist.sys.usb.config=mtp,adb\n"
        data = t.encode("latin-1")
        struct.pack_into("8s", hdr, 54, ("%08X" % len(data)).encode())
        edited["default.prop"] = len(data)

    elif nm == "sbin/adbd":
        b = bytearray(data)
        for off, sym in ADBD_SITES:
            cur = struct.unpack("<I", b[off:off + 4])[0]
            # bionic uses two stub prologues: "mov ip, r7" for <=4-arg calls,
            # and "mov ip, sp" for stubs that reload args off the stack (prctl).
            if cur not in (0xE1A0C007, 0xE1A0C00D):
                sys.exit("adbd 0x%06x is 0x%08x, expected mov ip,r7 or mov ip,sp (%s)"
                         % (off, cur, sym))
            struct.pack_into("<I", b, off, MOV_R0_0)
            struct.pack_into("<I", b, off + 4, BX_LR)
            print("  adbd 0x%06x  %-12s -> mov r0,#0 / bx lr" % (off, sym))
        data = bytes(b)
        edited["sbin/adbd"] = len(data)

    out += hdr + data
    while len(out) % 4:
        out += b"\x00"
    i += hs + ((fsz + 3) & ~3)
    if nm == "TRAILER!!!":
        break

print("edited:", ", ".join("%s (%d bytes)" % (k, v) for k, v in edited.items()))
if len(out) % 512:
    out += b"\x00" * (512 - len(out) % 512)

buf = io.BytesIO()
with gzip.GzipFile(fileobj=buf, mode="wb", compresslevel=9, mtime=0) as g:
    g.write(bytes(out))
newrd = buf.getvalue()

budget = 0xa41800 - psz - al(ks) - al(ds)
print("ramdisk gzip %d (stock %d), budget %d" % (len(newrd), rs, budget))
if al(len(newrd)) > budget:
    sys.exit("DOES NOT FIT - over by %d" % (al(len(newrd)) - budget))
print("fits with %d bytes spare" % (budget - al(len(newrd))))

hdr = bytearray(q[:psz])
struct.pack_into("<I", hdr, 16, len(newrd))
sha = hashlib.sha1()
for part, ln in ((kernel, ks), (newrd, len(newrd)), (b"", 0)):
    sha.update(part); sha.update(struct.pack("<I", ln))
sha.update(dt); sha.update(struct.pack("<I", ds))
hdr[576:596] = sha.digest(); hdr[596:608] = b"\x00" * 12

img = bytearray(); img += hdr
for part in (kernel, newrd, b"", dt):
    img += part
    while len(img) % psz:
        img += b"\x00"
pl = struct.unpack("<I", d[0x30:0x34])[0]
img += b"\x00" * (pl - len(img))
sig = 0x200 + pl
ssz = struct.unpack("<I", d[sig + 0x20:sig + 0x24])[0]
res = bytes(d[:0x200]) + bytes(img) + d[sig:sig + 0x60 + ssz]
res += d[len(res):(len(res) + 0xfff) & ~0xfff]
open(OUT, "wb").write(res)
print("wrote %s (%d bytes)" % (OUT, len(res)))

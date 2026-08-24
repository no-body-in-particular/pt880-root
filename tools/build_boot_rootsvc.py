#!/usr/bin/env python3
"""Rooted boot image + an init-started root service with FULL capabilities.

Why a service instead of another adbd patch
-------------------------------------------
Measured on the device (178 processes surveyed):

    init, vold, netd, healthd, zygote, debuggerd, rild, installd,
    servicemanager, ... : CapBnd = 0000003fffffffff   (FULL)
    adbd                : CapBnd = 00000000000000c0
    sh (child of adbd)  : CapBnd = 00000000000000c0
    sh (child of init)  : CapBnd = 0000003fffffffff   (FULL)

Only adbd and its children are restricted; init does not restrict anything.
So a service started by init inherits a full bounding set and can do what an
adb shell cannot - notably `mount -o remount,rw /system`, which needs
CAP_SYS_ADMIN (bit 21, absent from 0xc0).

SUPERSEDED by build_boot_capbnd.py. Keep this only for the capability survey
above, which is still accurate.

The claim this file used to make - that adbd's prctl stub is unreferenced dead
code and the drop mechanism is unidentified - was WRONG, and wrong for a dull
reason: the scan behind it decoded ARM BL only. adbd is Thumb-2 and reaches the
ARM stub through BLX. Decoded properly the stub has three callers and the drop
is AOSP drop_capabilities_bounding_set_if_needed() inlined at va 0x91f8. One
byte disables it, so a helper service is not needed at all.

The service runs a script shipped INSIDE the ramdisk, so it always exists at
boot. That script polls a command file on /data, which adb can write, so the
behaviour can be changed without ever reflashing again.
"""
import struct
import gzip
import hashlib
import io
import re
import sys

STOCK = r"C:\wpull\dump_watch2\boot_stock_exact.img"
OUT = r"C:\wpull\dump_watch2\boot_rootsvc_fdl.img"

MOV_R0_0 = 0xE3A00000
BX_LR = 0xE12FFF1E
ADBD_SITES = [(0x010104, "setgid32"), (0x010144, "setgroups32"),
              (0x01b790, "setuid32")]

# Root helper. Runs as an init service, so it has the full bounding set.
# Logs its own capabilities first so we MEASURE rather than assume.
ROOTD_SH = """#!/system/bin/sh
L=/data/local/tmp/rootd.log
C=/data/local/tmp/rootcmd
O=/data/local/tmp/rootcmd.out
echo "rootd started" > $L
id >> $L 2>&1
grep Cap /proc/self/status >> $L 2>&1
mount -o remount,rw /system >> $L 2>&1
if mount | grep -q "/system.*rw,"; then
echo "system rw OK" >> $L
else
echo "system rw FAILED" >> $L
fi
while true; do
if [ -f $C ]; then
/system/bin/sh $C > $O 2>&1
echo "exit=$?" >> $O
rm -f $C
fi
sleep 2
done
"""

SERVICE = """
service rootd /system/bin/sh /rootd.sh
    class late_start
    user root
    oneshot
"""


def cpio_iter(raw):
    i = 0
    while i + 110 <= len(raw):
        if raw[i:i + 6] != b"070701":
            break
        nsz = int(raw[i + 94:i + 102], 16)
        fsz = int(raw[i + 54:i + 62], 16)
        nm = raw[i + 110:i + 110 + nsz - 1].decode("latin-1")
        hs = (110 + nsz + 3) & ~3
        yield i, hs, fsz, nm
        i += hs + ((fsz + 3) & ~3)
        if nm == "TRAILER!!!":
            break


d = open(STOCK, "rb").read()
q = d[0x200:]
ks, ka, rs, ra, ss, sa, tags, psz = struct.unpack("<8I", q[8:40])
ds = struct.unpack("<I", q[40:44])[0]
al = lambda n: (n + psz - 1) // psz * psz
ko = al(psz); ro = ko + al(ks); do = ro + al(rs) + al(ss)
kernel = q[ko:ko + ks]; dt = q[do:do + ds]
raw = gzip.decompress(q[ro:ro + rs])

out = bytearray()
tmpl = None
for off, hs, fsz, nm in cpio_iter(raw):
    hdr = bytearray(raw[off:off + hs])
    data = raw[off + hs:off + hs + fsz]

    if nm == "default.prop":
        t = data.decode("latin-1")
        t = t.replace("ro.secure=1", "ro.secure=0")
        t = t.replace("ro.debuggable=0", "ro.debuggable=1")
        t = (re.sub(r"ro\.adb\.secure=\d", "ro.adb.secure=0", t)
             if "ro.adb.secure" in t else t.rstrip("\n") + "\nro.adb.secure=0\n")
        t = (re.sub(r"persist\.sys\.usb\.config=[^\n]*",
                    "persist.sys.usb.config=mtp,adb", t)
             if "persist.sys.usb.config" in t
             else t.rstrip("\n") + "\npersist.sys.usb.config=mtp,adb\n")
        data = t.encode("latin-1")
        struct.pack_into("8s", hdr, 54, ("%08X" % len(data)).encode())
        print("  default.prop -> %d bytes" % len(data))

    elif nm == "sbin/adbd":
        b = bytearray(data)
        for o, sym in ADBD_SITES:
            cur = struct.unpack("<I", b[o:o + 4])[0]
            if cur not in (0xE1A0C007, 0xE1A0C00D):
                sys.exit("adbd 0x%06x is 0x%08x, unexpected stub prologue (%s)"
                         % (o, cur, sym))
            struct.pack_into("<I", b, o, MOV_R0_0)
            struct.pack_into("<I", b, o + 4, BX_LR)
        data = bytes(b)
        print("  sbin/adbd -> uid/gid drop neutered (%d sites)" % len(ADBD_SITES))

    elif nm == "init.usb.rc":
        t = data.decode("latin-1")
        if "service rootd" not in t:
            t = t.rstrip("\n") + "\n" + SERVICE
        data = t.encode("latin-1")
        struct.pack_into("8s", hdr, 54, ("%08X" % len(data)).encode())
        print("  init.usb.rc -> +rootd service (%d bytes)" % len(data))

    if tmpl is None and nm == "adb_keys":
        tmpl = bytearray(hdr)
    out += hdr + data
    while len(out) % 4:
        out += b"\x00"

# ---- append /rootd.sh as a new cpio entry, modelled on an existing one ----
body = ROOTD_SH.encode("latin-1")
name = b"rootd.sh\x00"
h = bytearray(b"070701" + b"0" * 104)
def setf(pos, val):
    h[pos:pos + 8] = ("%08X" % val).encode()
setf(6, 0)            # ino
setf(14, 0o100750)    # mode: rwxr-x---
setf(22, 0)           # uid
setf(30, 0)           # gid
setf(38, 1)           # nlink
setf(46, 0)           # mtime
setf(54, len(body))   # filesize
setf(94, len(name))   # namesize
setf(102, 0)          # check
ent = bytes(h) + name
ent += b"\x00" * ((-len(ent)) % 4)
ent += body
ent += b"\x00" * ((-len(ent)) % 4)

# splice it in before TRAILER!!!
tr = out.rfind(b"070701" + b"0" * 8)
idx = out.rfind(b"TRAILER!!!")
tr_start = out.rfind(b"070701", 0, idx)
newout = bytes(out[:tr_start]) + ent + bytes(out[tr_start:])
print("  + /rootd.sh (%d bytes, mode 0750)" % len(body))

out = bytearray(newout)
if len(out) % 512:
    out += b"\x00" * (512 - len(out) % 512)

buf = io.BytesIO()
with gzip.GzipFile(fileobj=buf, mode="wb", compresslevel=9, mtime=0) as g:
    g.write(bytes(out))
newrd = buf.getvalue()

budget = 0xa41800 - psz - al(ks) - al(ds)
print("ramdisk gzip %d (stock %d), budget %d" % (len(newrd), rs, budget))
if al(len(newrd)) > budget:
    sys.exit("DOES NOT FIT - over by %d bytes" % (al(len(newrd)) - budget))
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

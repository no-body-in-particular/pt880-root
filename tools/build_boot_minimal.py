#!/usr/bin/env python3
"""Minimal rooted boot image: keep EVERY stock file, change only default.prop.

Dropping sbin/sec_openssl was a mistake. sbin/rsa_decrypt shells out to it:

    "/sbin/sec_openssl rsautl -verify -in %s -inkey ..."

so removing it broke an early-boot RSA verification helper. It is 1.18MB
uncompressed and was dropped purely to make room, but the ramdisk budget is
brutal: the payload allows 1,251,328 bytes of (aligned) ramdisk and stock
already uses 1,251,307 - a headroom of 21 bytes.

So this build keeps all 41 stock entries byte-for-byte and edits only
default.prop, which is all that is actually needed for root adb:

    ro.secure=1        -> 0     (adbd runs as root)
    ro.debuggable=0    -> 1
    ro.adb.secure=?    -> 0     (no auth prompt - the watch has one button)
    persist.sys.usb.config -> mtp,adb

No adb_keys, no adb_force.sh, no extra .rc - none of them are required once
ro.secure/ro.adb.secure are off, and every byte counts here.
"""
import struct, gzip, hashlib, io, sys

import paths

STOCK = paths.w2("boot_stock_exact.img")
OUT = paths.w2("boot_min_fdl.img")

d = open(STOCK, "rb").read()
q = d[0x200:]
ks, ka, rs, ra, ss, sa, tags, psz = struct.unpack("<8I", q[8:40])
ds = struct.unpack("<I", q[40:44])[0]
al = lambda n: (n + psz - 1) // psz * psz
ko = al(psz); ro = ko + al(ks); do = ro + al(rs) + al(ss)
kernel = q[ko:ko + ks]; dt = q[do:do + ds]
raw = gzip.decompress(q[ro:ro + rs])

# ---- edit default.prop in place, inside the cpio ----
ents = []; i = 0
while i + 110 <= len(raw):
    if raw[i:i + 6] != b"070701": break
    nsz = int(raw[i + 94:i + 102], 16); fsz = int(raw[i + 54:i + 62], 16)
    nm = raw[i + 110:i + 110 + nsz - 1].decode("latin-1")
    hs = (110 + nsz + 3) & ~3
    ents.append([nm, i, hs, fsz]); 
    i += hs + ((fsz + 3) & ~3)
    if nm == "TRAILER!!!": break

out = bytearray(); changed = None
for nm, off, hs, fsz in ents:
    hdr = bytearray(raw[off:off + hs])
    data = raw[off + hs:off + hs + fsz]
    if nm == "default.prop":
        t = data.decode("latin-1")
        before = t
        t = t.replace("ro.secure=1", "ro.secure=0")
        t = t.replace("ro.debuggable=0", "ro.debuggable=1")
        if "ro.adb.secure" in t:
            import re
            t = re.sub(r"ro\.adb\.secure=\d", "ro.adb.secure=0", t)
        else:
            t = t.rstrip("\n") + "\nro.adb.secure=0\n"
        import re
        if "persist.sys.usb.config" in t:
            t = re.sub(r"persist\.sys\.usb\.config=[^\n]*", "persist.sys.usb.config=mtp,adb", t)
        else:
            t = t.rstrip("\n") + "\npersist.sys.usb.config=mtp,adb\n"
        data = t.encode("latin-1")
        struct.pack_into("8s", hdr, 54, ("%08X" % len(data)).encode())
        changed = (len(before), len(data))
    out += hdr + data
    while len(out) % 4: out += b"\x00"
if changed:
    print("default.prop: %d -> %d bytes (+%d)" % (changed[0], changed[1], changed[1] - changed[0]))

if len(out) % 512:
    out += b"\x00" * (512 - len(out) % 512)
buf = io.BytesIO()
with gzip.GzipFile(fileobj=buf, mode="wb", compresslevel=9, mtime=0) as g:
    g.write(bytes(out))
newrd = buf.getvalue()

budget = 0xa41800 - psz - al(ks) - al(ds)
print("cpio    %d -> %d" % (len(raw), len(out)))
print("gzip    %d -> %d   (stock %d)" % (rs, len(newrd), rs))
print("budget  %d aligned bytes; ours aligned %d" % (budget, al(len(newrd))))
if al(len(newrd)) > budget:
    sys.exit("DOES NOT FIT - over by %d bytes" % (al(len(newrd)) - budget))
print("FITS with %d bytes to spare" % (budget - al(len(newrd))))

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
    while len(img) % psz: img += b"\x00"
pl = struct.unpack("<I", d[0x30:0x34])[0]
img += b"\x00" * (pl - len(img))
sig = 0x200 + pl
ssz = struct.unpack("<I", d[sig + 0x20:sig + 0x24])[0]
res = bytes(d[:0x200]) + bytes(img) + d[sig:sig + 0x60 + ssz]
res += d[len(res):(len(res) + 0xfff) & ~0xfff]
open(OUT, "wb").write(res)
print("wrote %s (%d bytes)" % (OUT, len(res)))

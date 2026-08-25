#!/usr/bin/env python3
"""Rebuild the rooted boot image with the DT section at its STOCK offset.

Problem found: uboot's fn 0x0152a4 parses the SPRD "DT image table" - it reads
12 bytes, checks a magic and a version, and fails the boot if they are wrong
("read dt image table header fail"). Our rebuild moved that table:

    stock   : dt 88064 @ 0xa2c000   (header+kernel+ramdisk+dt fills the payload exactly)
    patched : dt 88064 @ 0x98a800   (smaller ramdisk pulled it ~647KB forward,
                                     tail zero-padded out to 0xa41800)

So the fix is to pad the RAMDISK up to the stock ramdisk length instead of
padding the tail. The gzip stream ends where it ends and the kernel's initramfs
loader ignores trailing bytes, so a zero-padded ramdisk still unpacks
correctly - but every later section lands exactly where stock has it, and the
payload is exactly filled as stock is.

The boot header's id (SHA1) is recomputed over the PADDED ramdisk, because that
is the length the header declares.
"""
import struct
import hashlib
import sys

import paths

STOCK = paths.w2("boot_stock_exact.img")
PATCHED = paths.w2("boot_patched_fdl.img")
OUT = paths.w2("boot_dtfix_fdl.img")


def parse(img):
    p = img[0x200:]
    ks, ka, rs, ra, ss, sa, tags, psz = struct.unpack("<8I", p[8:40])
    ds = struct.unpack("<I", p[40:44])[0]
    al = lambda n: (n + psz - 1) // psz * psz
    ko = al(psz)
    ro = ko + al(ks)
    so = ro + al(rs)
    do = so + al(ss)
    return dict(p=p, ks=ks, rs=rs, ss=ss, ds=ds, psz=psz,
                ko=ko, ro=ro, so=so, do=do, al=al)


s = open(STOCK, "rb").read()
d = open(PATCHED, "rb").read()
S, D = parse(s), parse(d)

print("stock  : ramdisk %d @0x%x   dt %d @0x%x" % (S["rs"], S["ro"], S["ds"], S["do"]))
print("patched: ramdisk %d @0x%x   dt %d @0x%x" % (D["rs"], D["ro"], D["ds"], D["do"]))

if D["rs"] > S["rs"]:
    sys.exit("patched ramdisk is larger than stock - cannot pad to match")

ourrd = D["p"][D["ro"]:D["ro"] + D["rs"]]
dt = D["p"][D["do"]:D["do"] + D["ds"]]
kernel = D["p"][D["ko"]:D["ko"] + D["ks"]]
assert kernel == S["p"][S["ko"]:S["ko"] + S["ks"]], "kernel differs from stock"
assert dt == S["p"][S["do"]:S["do"] + S["ds"]], "dt differs from stock"

# Declare the STOCK ramdisk length; the real gzip is followed by zeros.
padded = ourrd + b"\x00" * (S["rs"] - D["rs"])
assert len(padded) == S["rs"]

hdr = bytearray(D["p"][:D["psz"]])
struct.pack_into("<I", hdr, 16, S["rs"])          # ramdisk_size = stock's

al = S["al"]
sha = hashlib.sha1()
for part, ln in ((kernel, S["ks"]), (padded, S["rs"]),
                 (b"", 0)):
    sha.update(part)
    sha.update(struct.pack("<I", ln))
sha.update(dt)
sha.update(struct.pack("<I", S["ds"]))
hdr[576:576 + 20] = sha.digest()
hdr[576 + 20:576 + 32] = b"\x00" * 12

img = bytearray()
img += hdr
for part in (kernel, padded, b"", dt):
    img += part
    while len(img) % S["psz"]:
        img += b"\x00"

stock_payload = struct.unpack("<I", d[0x30:0x34])[0]
print("new payload used: 0x%x (stock payload field 0x%x)" % (len(img), stock_payload))
if len(img) > stock_payload:
    sys.exit("payload overflow")
img += b"\x00" * (stock_payload - len(img))

sig_off = 0x200 + stock_payload
sign_size = struct.unpack("<I", d[sig_off + 0x20:sig_off + 0x24])[0]
out = bytes(d[:0x200]) + bytes(img) + d[sig_off:sig_off + 0x60 + sign_size]
aligned = (len(out) + 0xfff) & ~0xfff
out += d[len(out):aligned]

open(OUT, "wb").write(out)
N = parse(out)
print()
print("wrote %s (%d bytes)" % (OUT, len(out)))
print("  ramdisk %d @0x%x   dt %d @0x%x" % (N["rs"], N["ro"], N["ds"], N["do"]))
print("  dt offset matches stock: %s" % (N["do"] == S["do"]))
print("  size matches stock     : %s" % (len(out) == len(s)))

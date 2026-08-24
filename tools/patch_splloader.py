#!/usr/bin/env python3
"""Make splloader accept an unsigned uboot.

splloader's chain-loader (file 0x005400-0x005580) loads and verifies each
bootchain image in turn. 0xb6d8 is a thunk to the verify routine and is called
six times:

    0x00547c  sml          0x0054d8  trustos      0x005534  uboot     <-- this one
    0x0054b4  sml_bak      0x005510  trustos_bak  0x005570  uboot_bak

The uboot sequence is:

    00551c  mov x1, #0xfe00 / movk #0x9eff   ; uboot was loaded to 0x9efffe00
    005534  bl  #0xb6d8                      ; verify
    005538  cbz w0, #0xa57c                  ; 0 = pass -> carry on and boot
    00553c  ... "uboot_bak" ... verify ...
    005578  b   #0xa578                      ; both failed -> hang (black screen)

Replacing the call with `movz w0, #0` makes the cbz always taken, so splloader
proceeds without checking uboot's signature. One 32-bit word.

Deliberately NOT patched: sml, trustos and their _bak copies keep full
verification. Those are secure-world images we are not modifying, and leaving
their checks intact limits the blast radius of this change. uboot_bak is left
alone too - with the primary check forced to pass, that path is unreachable.

Safety: the boot ROM was shown NOT to verify at all (a signature-invalid FDL1
ran on a plain EXEC_DATA, no exploit), which is a global secure-boot fuse
state, so it will not verify splloader at power-on either. And the ROM's USB
download mode lives in mask ROM and is always reachable, so a bad splloader is
recoverable - that path has been exercised repeatedly today.
"""
import struct
import sys

SRC = r"C:\wpull\dump_watch2\splloader.img"
DST = r"C:\wpull\dump_watch2\splloader_unlocked.img"

SITE = 0x005534
MOVZ_W0_0 = 0x52800000
BASE = 0x5000

d = bytearray(open(SRC, "rb").read())
cur = struct.unpack("<I", d[SITE:SITE + 4])[0]
if (cur & 0xFC000000) != 0x94000000:
    sys.exit("0x%06x is 0x%08x, not a BL" % (SITE, cur))

imm = cur & 0x03FFFFFF
if imm & 0x02000000:
    imm -= 0x04000000
target = BASE + SITE + imm * 4
if target != 0xb6d8:
    sys.exit("0x%06x targets 0x%x, expected the verify thunk 0xb6d8" % (SITE, target))

struct.pack_into("<I", d, SITE, MOVZ_W0_0)
open(DST, "wb").write(bytes(d))

print("splloader patch")
print("  site      file 0x%06x (addr 0x%x)" % (SITE, BASE + SITE))
print("  was       bl 0x%x        (0x%08x)" % (target, cur))
print("  now       movz w0, #0     (0x%08x)" % MOVZ_W0_0)
print("  untouched sml / sml_bak / trustos / trustos_bak / uboot_bak verify calls")
print("  wrote %s (%d bytes)" % (DST, len(d)))

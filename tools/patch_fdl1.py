#!/usr/bin/env python3
"""Make FDL1 accept an unsigned FDL2.

FDL1 is AArch64 (Cortex-A53 at EL3; only Android userspace is 32-bit). Its
verify-then-jump path, at file offset 0x6990, is:

    006990  mov  x0, #0x5000
    00699c  bl   #0xbb88          <- verify the received image
    0069a0  mov  w20, w0
    0069a4  cbz  w0, #0xb9ac      <- 0 means pass
    0069a8  b    #0xb9a8          <- fail: never reaches the jump
    0069ac  add  w19, w19, #0x200 <- skip the DHTB header
    0069b0  bl   #0x5b78
    0069b4  blr  x19              <- enter FDL2

Replacing the call with `movz w0, #0` makes the cbz always taken, so control
falls straight through to the jump. One 32-bit word; the verify function itself
is left intact, as is every other caller of it.

FDL1's own signature is irrelevant here: CVE-2022-38694 has the boot ROM return
into this image rather than EXEC it, so the ROM never verifies it either.

Nothing is written to the device - FDL1 lives in SRAM for the session only.
"""
import struct
import sys

SRC = r"C:\wpull\fdl_sl8521e\fdl1-sign-STOCK.bin"
DST = r"C:\wpull\fdl_sl8521e\fdl1-noverify.bin"

SITE = 0x699c
MOVZ_W0_0 = 0x52800000

d = bytearray(open(SRC, "rb").read())
cur = struct.unpack("<I", d[SITE:SITE + 4])[0]

if (cur & 0xFC000000) != 0x94000000:
    sys.exit("0x%06x is 0x%08x, not a BL - refusing to patch" % (SITE, cur))

imm26 = cur & 0x03FFFFFF
if imm26 & 0x02000000:
    imm26 -= 0x04000000
target = 0x5000 + SITE + imm26 * 4
print("site 0x%06x (addr 0x%x)" % (SITE, 0x5000 + SITE))
print("  was: bl 0x%x   (0x%08x)" % (target, cur))

struct.pack_into("<I", d, SITE, MOVZ_W0_0)
print("  now: movz w0, #0  (0x%08x)" % MOVZ_W0_0)

open(DST, "wb").write(bytes(d))
print("  wrote %s (%d bytes, 1 word changed)" % (DST, len(d)))

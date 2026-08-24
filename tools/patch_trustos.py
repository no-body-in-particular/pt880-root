#!/usr/bin/env python3
"""Disable the secure-world boot-image check in trustos.

trustos (Trusty TEE, Thumb-2, loaded by splloader to 0x8e01fe00) is what
actually verifies the boot image - not uboot. uboot only issues an SMC and
reads the answer. On failure the SECURE WORLD never returns:

    015d9e  bl  #0x157e0       ; sprd_verify_cert()
    015da2  cbz r0, #0x15dae   ; 0 -> failure path
    015da4  add sp, #0x20      ; non-zero -> return normally
    015dac  pop {r6, pc}
    015dae  printf "call sprd_verify_cert() "
    015dce  printf "sprd_verify_cert() failed , enter while(1) "
    015dd2  b   .              ; spins forever, CPU never leaves EL3

That is why patching uboot could never work: uboot never regains control, the
console log stops at the SMC arguments (p1:9c000000 = secboot-verify-mem), and
the logo stays frozen on screen.

Replacing the cbz with a Thumb NOP makes execution fall through to the normal
return path regardless of the verdict. sprd_verify_cert() still runs; only the
branch to the spin is removed. Two bytes.

Nothing else in trustos is touched - RPMB, efuse, keymaster and the storage
stack are untouched, so the TEE's other services behave exactly as before.
"""
import struct
import sys

SRC = r"C:\wpull\dump_watch2\trustos.img"
DST = r"C:\wpull\dump_watch2\trustos_noverify.img"

SITE = 0x015da2
NOP = b"\x00\xbf"        # Thumb-2 NOP

d = bytearray(open(SRC, "rb").read())
cur = d[SITE:SITE + 2]
# CBZ is 1011 0001 ... -> high byte 0xB1
if cur[1] != 0xB1:
    sys.exit("0x%06x is %s, not a CBZ" % (SITE, cur.hex()))

print("trustos secure-world verify patch")
print("  site 0x%06x (addr 0x%08x)" % (SITE, 0x8e01fe00 + SITE))
print("  was  %s   cbz r0, #0x15dae  (-> print + while(1))" % cur.hex())
d[SITE:SITE + 2] = NOP
print("  now  %s   nop               (-> falls through to return)" % NOP.hex())
open(DST, "wb").write(bytes(d))
print("  wrote %s (%d bytes)" % (DST, len(d)))

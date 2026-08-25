#!/usr/bin/env python3
"""Un-gate 'boot' in uboot's secure-partition table.

uboot carries the same table/gate code as FDL2 (same U-Boot tree). Its table
sits at file 0x04aed0 with the same ordering:

    +0x00 splloader  +0x04 sml  +0x08 trustos  +0x0c uboot
    +0x10 boot   <-- +0x14 recovery ... +0x5c w_gdsp  +0x60 <end>

Repoint the 'boot' entry one byte into its own string so it reads "oot".
strcmp then never matches, the gate returns "not secure", and uboot loads the
boot image without checking its signature - exactly the change that let FDL2
write it. One 32-bit word; the terminator and every other entry are untouched,
so recovery and the bootloader partitions keep their verification.
"""
import struct
import sys

import paths

SRC = paths.w2("uboot.img")
DST = paths.w2("uboot_unlocked.img")
BASE = 0x9efffe00
TABLE = 0x04aed0
BOOT_ENTRY = TABLE + 0x10

d = bytearray(open(SRC, "rb").read())
old = struct.unpack("<I", d[BOOT_ENTRY:BOOT_ENTRY + 4])[0]
off = old - BASE
name = d[off:d.index(b"\x00", off)].decode()
if name != "boot":
    sys.exit("entry at 0x%06x is %r, not 'boot'" % (BOOT_ENTRY, name))

new = old + 1
struct.pack_into("<I", d, BOOT_ENTRY, new)
newname = d[new - BASE:d.index(b"\x00", new - BASE)].decode()
open(DST, "wb").write(bytes(d))

print("uboot patch")
print("  entry   file 0x%06x" % BOOT_ENTRY)
print("  pointer 0x%08x -> 0x%08x" % (old, new))
print("  string  %r -> %r" % (name, newname))
print("  wrote %s (%d bytes)" % (DST, len(d)))
print()
print("  resulting table:")
k = 0
while True:
    v = struct.unpack("<I", d[TABLE + k * 4:TABLE + k * 4 + 4])[0]
    if v == 0:
        break
    o = v - BASE
    print("    +0x%02x  %r" % (k * 4, d[o:d.index(b"\x00", o)].decode()))
    k += 1

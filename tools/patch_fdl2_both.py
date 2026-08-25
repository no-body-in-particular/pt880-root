#!/usr/bin/env python3
"""Un-gate BOTH 'boot' and 'uboot' in FDL2's secure-partition table.

Table at file 0x0554b4, walked with a pre-increment so entries start at +0x04:

    +0x04 splloader  +0x08 sml  +0x0c trustos
    +0x10 uboot  <-- +0x14 boot  <-- +0x18 recovery ...

Both entries are repointed into their own strings so strcmp never matches:
'boot' -> 'oot' (+1) and 'uboot' -> 'oot' (+2). Note the +2 for uboot: adding
only 1 would yield "boot", which is a REAL partition name and would silently
re-gate the boot partition.

Everything else keeps its verification, and FDL2 is RAM-only, so this is
reversible by using fdl2-sign-STOCK.bin.
"""
import struct
import sys

import paths

SRC = paths.fdl("fdl2-sign-STOCK.bin")
DST = paths.fdl("fdl2-patched.bin")
BASE = 0x9efffe00
TABLE = 0x0554b4

d = bytearray(open(SRC, "rb").read())

def repoint(entry_off, expect, step):
    old = struct.unpack("<I", d[entry_off:entry_off + 4])[0]
    o = old - BASE
    name = d[o:d.index(b"\x00", o)].decode()
    if name != expect:
        sys.exit("entry 0x%06x is %r, expected %r" % (entry_off, name, expect))
    new = old + step
    struct.pack_into("<I", d, entry_off, new)
    nn = d[new - BASE:d.index(b"\x00", new - BASE)].decode()
    print("  0x%06x  %-10r -> %-8r  (0x%08x -> 0x%08x)" % (entry_off, name, nn, old, new))
    return nn

print("FDL2 gate patches:")
a = repoint(TABLE + 0x10, "uboot", 2)   # +2: +1 would give the real name "boot"
b = repoint(TABLE + 0x14, "boot", 1)
assert a != "boot" and b != "boot", "a patched entry still reads 'boot'"

open(DST, "wb").write(bytes(d))
print("  wrote %s" % DST)
print()
print("  resulting table:")
k = 1
while True:
    v = struct.unpack("<I", d[TABLE + k * 4:TABLE + k * 4 + 4])[0]
    if v == 0:
        break
    o = v - BASE
    print("    +0x%02x  %r" % (k * 4, d[o:d.index(b"\x00", o)].decode()))
    k += 1

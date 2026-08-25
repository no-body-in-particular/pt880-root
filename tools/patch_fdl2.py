#!/usr/bin/env python3
"""Make FDL2 treat 'boot' as a non-secure partition.

FDL2 keeps a table of partitions that must carry a valid RSA signature when
downloaded. The gate at file 0x0514b4 walks it with `ldr r0,[r4,#4]!` (a
PRE-increment, so the first entry sits at +4), strcmp's each entry against the
partition name, and returns 0 on a match or 1 at the terminator. Callers do
`cmp r0,#1 / popeq`, so 1 means "not secure, write normally".

The table is:
    splloader sml trustos uboot BOOT recovery wl_modem wl_ldsp wmodem
    wl_gdsp wl_warm pm_sys tl_ldsp tl_tgdsp tl_modem l_modem l_ldsp
    l_gdsp l_warm l_tgdsp l_agdsp wdsp w_modem w_gdsp

'boot' is in it; 'cache' is not. That matches every measurement:

    stock payload   + valid signature  -> boot   2626/2626 ACKed, committed
    stock payload   + zeroed signature -> boot   2625/2626 ACKed, stalled
    patched payload + stock signature  -> boot   2625/2626 ACKed, stalled
    patched payload + zeroed signature -> boot   2625/2626 ACKed, stalled
    patched payload + any signature    -> cache  2626/2626 ACKed, committed

So FDL2 needs a signature that actually covers the payload, which we cannot
produce without the vendor's private key.

The patch repoints the table's 'boot' entry one byte into its own string, so
it reads "oot". strcmp then never matches, the gate returns 1, and 'boot' is
handled exactly as 'cache' already is - which we proved writes and reads back
byte-identical. One 32-bit word changes; the terminator and every other entry
are untouched, so recovery/modem/bootloader partitions keep verification.

FDL2 is uploaded to RAM each session and nothing is written to the device, so
this is fully reversible: just use the stock file again.
"""
import struct
import sys

import paths

SRC = paths.fdl("fdl2-sign.bin")
DST = paths.fdl("fdl2-patched.bin")

BASE = 0x9efffe00
TABLE = 0x0554b4
BOOT_ENTRY = TABLE + 0x14

d = bytearray(open(SRC, "rb").read())
old = struct.unpack("<I", d[BOOT_ENTRY:BOOT_ENTRY + 4])[0]
off = old - BASE
name = d[off:d.index(b"\x00", off)].decode()
if name != "boot":
    sys.exit("entry at 0x%06x is %r, not 'boot' - refusing" % (BOOT_ENTRY, name))

new = old + 1
struct.pack_into("<I", d, BOOT_ENTRY, new)
newname = d[new - BASE:d.index(b"\x00", new - BASE)].decode()
open(DST, "wb").write(bytes(d))

print("patched FDL2")
print("  table entry  file 0x%06x" % BOOT_ENTRY)
print("  pointer      0x%08x -> 0x%08x" % (old, new))
print("  string       %r -> %r" % (name, newname))
print("  wrote %s (%d bytes)" % (DST, len(d)))
print()
print("  resulting secure-partition table:")
k = 1
while True:
    v = struct.unpack("<I", d[TABLE + k * 4:TABLE + k * 4 + 4])[0]
    if v == 0:
        break
    o = v - BASE
    print("    +0x%02x  %r" % (k * 4, d[o:d.index(b"\x00", o)].decode()))
    k += 1

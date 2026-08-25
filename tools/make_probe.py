#!/usr/bin/env python3
"""Generate self-identifying probe images for the write-commit test.

Every 4096-byte block carries its own index, so a read-back does not just say
"different" - it says exactly which block was the last one to commit. That is
the number we have never had: all three data-phase runs ACKed all 2464 blocks
and then lost the device, so the ACKs tell us nothing about what reached flash.

Sizes escalate small -> large. If the device dies partway through the sequence
we still keep every result up to that point, which is why the order matters.
"""
import os

import paths

OUT = paths.W2
BLK = 4096

# 10092544 is the exact size of boot_exact.img, so the largest probe reproduces
# the failing write byte-for-byte in size terms - on an expendable partition.
SIZES = [
    ("64k", 64 * 1024),
    ("1m", 1024 * 1024),
    ("4m", 4 * 1024 * 1024),
    ("8m", 8 * 1024 * 1024),
    ("boot", 10092544),
]


def make(size):
    buf = bytearray()
    nblk = (size + BLK - 1) // BLK
    for i in range(nblk):
        b = bytearray(BLK)
        marker = b"PROBE%08d" % i
        b[0:len(marker)] = marker
        # Fill the rest with a value derived from the index so a block written
        # at the wrong offset is as visible as one never written at all.
        fill = ((i * 7) + 0x41) & 0xFF
        for j in range(len(marker), BLK):
            b[j] = fill
        buf += b
    return bytes(buf[:size])


if __name__ == "__main__":
    for name, size in SIZES:
        path = os.path.join(OUT, "probe_%s.img" % name)
        data = make(size)
        with open(path, "wb") as f:
            f.write(data)
        print("  probe_%-5s %10d bytes  %5d blocks" % (name, size, (size + BLK - 1) // BLK))

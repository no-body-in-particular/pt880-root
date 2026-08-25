#!/usr/bin/env python3
"""Compare every probe read-back against what was sent.

Reports the last block that actually committed, which localises the failure
instead of just flagging a mismatch.
"""
import os
import sys

import paths

OUT = paths.W2
BLK = 4096
NAMES = ["64k", "1m", "4m", "8m", "boot"]


def blocks(data):
    return [data[i:i + BLK] for i in range(0, len(data), BLK)]


print("%-8s %12s %12s  %s" % ("PROBE", "SENT", "READ BACK", "RESULT"))
for name in NAMES:
    src = os.path.join(OUT, "probe_%s.img" % name)
    dst = os.path.join(OUT, "probe_%s_back.img" % name)
    if not os.path.isfile(src):
        continue
    if not os.path.isfile(dst):
        print("%-8s %12d %12s  not read back (session ended first)"
              % (name, os.path.getsize(src), "-"))
        continue
    a = open(src, "rb").read()
    b = open(dst, "rb").read()
    if a == b:
        print("%-8s %12d %12d  COMMITTED - identical" % (name, len(a), len(b)))
        continue
    ba, bb = blocks(a), blocks(b)
    good = 0
    for i in range(min(len(ba), len(bb))):
        if ba[i] != bb[i]:
            break
        good += 1
    print("%-8s %12d %12d  MISMATCH - %d/%d blocks committed (0x%x bytes)"
          % (name, len(a), len(b), good, len(ba), good * BLK))
    if good < len(bb):
        got = bb[good][:13]
        want = ba[good][:13]
        print("%-8s %12s %12s    first bad block %d: wanted %r got %r"
              % ("", "", "", good, want, got))

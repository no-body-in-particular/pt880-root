#!/usr/bin/env python3
"""Cut the exact stock boot content out of the 24MB over-read dump.

The stock boot partition is a SIGNED DHTB image:

    0x000000  DHTB header            0x200 bytes
    0x000200  payload                0xa41800 bytes  (ANDROID! boot image)
    0xa41a00  signature block        0x60 bytes
    0xa41a60  signature data         0x234 bytes
    0xa41c94  end of real content

Everything we have flashed so far was structurally wrong:

  boot_exact.img    10092544 bytes, DHTB header declares payload 0x9a0000.
                    0x200 + 0x9a0000 = 0x9a0200 - PAST the end of the file.
                    The header promises more payload than the file contains,
                    and there is no signature block at all.
  boot_patched.img  ends exactly at payload end, still no signature block.

So FDL2 was being handed a boot image whose own header disagreed with its
length, with the signature region simply absent. That is almost certainly why
'boot' died at the end of the data phase while an identical-SIZE write to
'cache' committed perfectly - cache is raw data, boot is parsed.

This script produces the byte-identical stock content so we can test whether
'boot' accepts a well-formed write at all. Writing it back is a no-op: it is
exactly what the partition already holds.
"""
import os
import struct

import paths

W2 = paths.W2
SRC = os.path.join(W2, "boot.img")            # 24MB raw over-read
OUT = os.path.join(W2, "boot_stock_exact.img")

d = open(SRC, "rb").read()
assert d[:4] == b"DHTB", "source is not a DHTB image"

payload = struct.unpack("<I", d[0x30:0x34])[0]
sig_off = 0x200 + payload
sig = d[sig_off:sig_off + 0x60]
data_size, = struct.unpack("<I", sig[0x10:0x14])
data_off, = struct.unpack("<I", sig[0x18:0x1c])
sign_size, = struct.unpack("<I", sig[0x20:0x24])
sign_off, = struct.unpack("<I", sig[0x28:0x2c])

assert data_size == payload and data_off == 0x200, "signature block mismatch"
assert sign_off == sig_off + 0x60, "signature data offset mismatch"

end = sign_off + sign_size
# Round up to the 4096 block size spd_dump sends with. The extra bytes are
# taken from the dump itself, so the result stays byte-identical to flash.
aligned = (end + 0xfff) & ~0xfff

open(OUT, "wb").write(d[:aligned])

print("stock boot content")
print("  DHTB header      0x000000  0x200")
print("  payload          0x000200  0x%x" % payload)
print("  signature block  0x%06x  0x60" % sig_off)
print("  signature data   0x%06x  0x%x" % (sign_off, sign_size))
print("  real content ends at 0x%x (%d bytes)" % (end, end))
print("  written          %s" % OUT)
print("  size             0x%x (%d bytes, %d blocks) - block aligned"
      % (aligned, aligned, aligned // 4096))
print("  identical to the first %d bytes of the device dump" % aligned)

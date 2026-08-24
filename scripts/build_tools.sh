#!/usr/bin/env bash
# Build spd_dump_cve.exe (upstream spd_dump + our three fixes).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${LIBUSB_A:=$ROOT/../_libusb32/libusb-1.0.dll.a}"
: "${LIBUSB_INC:=$ROOT/../include}"
gcc -O2 -DUSE_LIBUSB=1 -I "$LIBUSB_INC" -Dfseeko=fseeko64 -Dftello=ftello64 \
    -o "$ROOT/tools/spd_dump_cve.exe" "$ROOT/tools/spd_dump_cve.c" "$LIBUSB_A"
echo "built tools/spd_dump_cve.exe"

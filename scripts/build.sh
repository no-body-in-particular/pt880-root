#!/usr/bin/env bash
# Build the patched images from YOUR OWN dump. Never uses a donor bootchain -
# splloader/trustos/uboot are per-device signed blobs.
set -euo pipefail
DUMP="${1:-./firmware/mydevice}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
python3 "$ROOT/tools/build_boot_stock.py"   "$DUMP"   # exact-size stock boot
python3 "$ROOT/tools/build_boot_minimal.py" "$DUMP"   # rooted boot image
python3 "$ROOT/tools/patch_splloader.py"    "$DUMP"   # trustos+uboot checks
python3 "$ROOT/tools/patch_trustos.py"      "$DUMP"   # both verify spins
python3 "$ROOT/tools/patch_fdl2.py"         "$ROOT/fdl"
python3 "$ROOT/tools/patch_fdl1.py"         "$ROOT/fdl"
echo "built. next: ./scripts/flash.sh $DUMP"

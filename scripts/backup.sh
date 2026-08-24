#!/usr/bin/env bash
# Dump every partition. READ-ONLY - nothing is written to the watch.
# Ordered irreplaceable-first: if a run is interrupted, prodnv/IMEI is already
# safe and only the bulk images need repeating.
set -euo pipefail
OUT="${1:-./firmware/mydevice}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mkdir -p "$OUT"
for stage in backup1 backup2 backup34; do
    echo "=== $stage - put the watch in boot ROM mode (ID to GND) ==="
    python3 "$ROOT/tools/catch_fdl.py" "$stage" --attempts 300 --wait 240 \
        --outdir "$OUT" --exe "$ROOT/tools/spd_dump_cve.exe"
done
python3 "$ROOT/tools/build_boot_stock.py" "$OUT"
echo "dumped to $OUT"

#!/usr/bin/env bash
# Put the bootchain back to byte-exact factory images.
set -euo pipefail
DUMP="${1:-./firmware/mydevice}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
python3 "$ROOT/tools/catch_fdl.py" restoreall --attempts 300 --wait 240 \
    --write-timeout 120000 --outdir "$DUMP" --exe "$ROOT/tools/spd_dump_cve.exe"
echo "restored. power-cycle normally."

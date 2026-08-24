#!/usr/bin/env bash
# Flash the full unlock: splloader + trustos + stock uboot + rooted boot.
#
# Order matters. splloader first: once patched it accepts BOTH signed and
# unsigned images downstream, so on its own it changes nothing observable.
# trustos second, since it is what actually verifies the boot image. uboot is
# written STOCK on purpose - with trustos patched the SMC reports success and an
# unmodified uboot accepts the image. boot last.
#
# If the session dies partway the watch stays bootable: every image downstream
# of the last successful write is still the stock, signed one, and the patched
# loaders accept those too.
set -euo pipefail

DUMP="${1:-./firmware/mydevice}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXE="$ROOT/tools/spd_dump_cve.exe"

[[ -x "$EXE" ]] || { echo "build the tool first: ./scripts/build_tools.sh" >&2; exit 1; }
for f in splloader_unlocked.img trustos_noverify.img uboot.img boot_min_fdl.img; do
    [[ -f "$DUMP/$f" ]] || { echo "missing $DUMP/$f - run ./scripts/build.sh first" >&2; exit 1; }
done

echo "This writes splloader and trustos. Both are recoverable via download mode,"
echo "but only if $DUMP holds your own stock dumps. Ctrl-C now if unsure."
sleep 3

python3 "$ROOT/tools/catch_fdl.py" unlockall \
    --attempts 300 --wait 240 --write-timeout 120000 \
    --outdir "$DUMP" --exe "$EXE"

echo
echo "Now power-cycle the watch normally (no ID-to-GND)."
echo "Then: adb devices"

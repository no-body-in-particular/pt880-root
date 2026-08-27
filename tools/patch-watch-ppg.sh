#!/usr/bin/env bash
#
# Stop the watch refusing to measure a pulse when it thinks it is off the wrist.
#
#     ./patch-watch-ppg.sh              patch it
#     ./patch-watch-ppg.sh --dry-run    say what would happen, change nothing
#     ./patch-watch-ppg.sh --restore    put the stock file back
#
# HeartRateManager.triggerPPGTest gives up before it reaches the sensor if either
# Anti_off_flag or Cut_off_flag is set - taken off the wrist, or strap cut. Once
# either latches, every later measurement is a silent no-op: the watch goes on
# acknowledging the server's HEARTRATE# and simply never reports a reading, until
# a reboot rebuilds the Runtime and clears the flags.
#
# The firmware already has a path that skips those checks; it just reserves it
# for the case where CoreService is missing. This makes that branch
# unconditional, which is one opcode - if-eqz becomes goto/16, same length, same
# target, same register byte. Everything else in the file stays where it was.
#
# The stock odex is kept twice: next to this script with a timestamp, and on the
# watch as L009_Protocol.odex.orig. --restore uses the one on the watch.
#
# Runs on macOS as-is. Needs adb on PATH, python3, and a rooted boot image -
# specifically one built by tools/build_boot_capbnd.py, because /system has to
# be remounted read-write and the earlier build_boot_root.py image cannot.

set -e

# Piped into bash the script itself is on stdin, and `adb shell` reads stdin - so
# an unguarded call swallows the rest of the script and the run stops dead after
# the first one. Every adb call below closes stdin.
ADB="${ADB:-adb}"
SERIAL="${SERIAL:-}"
[ -n "$SERIAL" ] && ADB="$ADB -s $SERIAL"
adbq() { $ADB "$@" </dev/null; }

BASE="${BASE:-https://coredump.ws/pt880}"

ODEX=/system/priv-app/L009_Protocol.odex
BACKUP=/system/priv-app/L009_Protocol.odex.orig
STAGE=/data/local/tmp/L009_Protocol.odex

DO_DRY=0
DO_RESTORE=0

for a in "$@"; do
    case "$a" in
        --dry-run) DO_DRY=1 ;;
        --restore) DO_RESTORE=1 ;;
        -h|--help) sed -n '3,9p' "$0"; exit 0 ;;
        *) printf 'unknown option: %s\n' "$a" >&2; exit 2 ;;
    esac
done

say() { printf '\n== %s\n' "$*"; }
die() { printf '\nerror: %s\n' "$*" >&2; exit 1; }

HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# macOS has shasum, most Linuxes have sha256sum, and this script is meant to run
# on both without the caller thinking about it.
sha256() {
    if command -v shasum >/dev/null 2>&1; then shasum -a 256 "$1" | cut -d' ' -f1
    else sha256sum "$1" | cut -d' ' -f1
    fi
}

# ---------------------------------------------------------------- the device

say "device"
command -v $ADB >/dev/null 2>&1 || die "adb not found on PATH"
adbq wait-for-device
adbq shell 'echo connected' >/dev/null || die "no device"

UID_OUT="$(adbq shell id | tr -d '\r')"
case "$UID_OUT" in
    uid=0*) ;;
    *) die "adb shell is not root ($UID_OUT).
       This needs a rooted boot image - see apps/watchlauncher/install-root-helper.sh." ;;
esac

# The remount is the part that actually needs the capability, and finding that
# out after the file has been staged is worse than finding out now.
CAPBND="$(adbq shell 'grep CapBnd /proc/self/status' | tr -d '\r')"
case "$CAPBND" in
    *0000001fffffffff*|*3fffffffff*) ;;
    *) printf '  note: CapBnd is %s\n' "${CAPBND#*:}"
       printf '        if the remount below fails, the boot image is build_boot_root.py\n'
       printf '        and needs to be build_boot_capbnd.py instead\n' ;;
esac

adbq shell "[ -f $ODEX ] && echo yes" | grep -q yes || die "$ODEX is not on this device"

# ---------------------------------------------------------------- restore

if [ "$DO_RESTORE" = 1 ]; then
    say "restore"
    adbq shell "[ -f $BACKUP ] && echo yes" | grep -q yes \
        || die "no $BACKUP on the watch - nothing to restore from"

    # One shell, so a failure part way still hits the final remount and does not
    # leave /system writable.
    adbq shell "
      mount -o remount,rw /system &&
      cat $BACKUP > $ODEX &&
      chown 0:0 $ODEX &&
      chmod 644 $ODEX
      mount -o remount,ro /system
    "
    say "done - rebooting"
    adbq reboot
    exit 0
fi

# ---------------------------------------------------------------- the patcher

PATCHER="$HERE/patch_ppg_gate.py"

if [ ! -f "$PATCHER" ]; then
    say "fetching the patcher"
    PATCHER="$WORK/patch_ppg_gate.py"
    curl -fsSL "$BASE/patch_ppg_gate.py" -o "$PATCHER" \
        || die "could not fetch patch_ppg_gate.py from $BASE"
fi

command -v python3 >/dev/null 2>&1 \
    || die "python3 not found - on macOS, 'xcode-select --install' provides it"

# ---------------------------------------------------------------- pull, patch

say "reading the stock file"
adbq pull "$ODEX" "$WORK/stock.odex" >/dev/null || die "could not pull $ODEX"
printf '  %s  %s bytes\n' "$(sha256 "$WORK/stock.odex" | cut -c1-16)" \
    "$(wc -c < "$WORK/stock.odex" | tr -d ' ')"

say "patching"
python3 "$PATCHER" "$WORK/stock.odex" -o "$WORK/patched.odex" | sed 's/^ *//' | sed 's/^/  /'

# The patcher changes one opcode byte plus the dex checksum and signature it has
# to reseal - four bytes and twenty. Anything else means it did not do what this
# script thinks it did, and the file is not going anywhere near /system.
DIFF="$(cmp -l "$WORK/stock.odex" "$WORK/patched.odex" | wc -l | tr -d ' ')"
[ "$DIFF" = 25 ] || die "expected 25 changed bytes (1 opcode + 4 checksum + 20 signature), got $DIFF"
printf '  %s changed bytes, as expected\n' "$DIFF"

KEEP="$HERE/L009_Protocol.odex.stock-$(date +%Y%m%d-%H%M%S)"
cp "$WORK/stock.odex" "$KEEP"
printf '  stock file kept at %s\n' "$KEEP"

if [ "$DO_DRY" = 1 ]; then
    say "dry run - nothing was written to the watch"
    exit 0
fi

# ---------------------------------------------------------------- install

say "installing"
adbq push "$WORK/patched.odex" "$STAGE" >/dev/null || die "could not stage the file"

# Back up on the watch before the first overwrite, and never after: running this
# twice must not replace the stock backup with an already-patched one.
adbq shell "
  mount -o remount,rw /system &&
  { [ -f $BACKUP ] || cat $ODEX > $BACKUP ; } &&
  cat $STAGE > $ODEX &&
  chown 0:0 $ODEX &&
  chmod 644 $ODEX
  mount -o remount,ro /system
"
adbq shell "rm -f $STAGE"

say "checking what is on the watch now"
adbq pull "$ODEX" "$WORK/readback.odex" >/dev/null || die "could not read the file back"

if [ "$(sha256 "$WORK/readback.odex")" != "$(sha256 "$WORK/patched.odex")" ]; then
    die "what is on the watch does not match what was pushed.
       The stock file is still at $BACKUP on the watch and $KEEP here.
       Put it back with: $0 --restore"
fi

printf '  matches\n'

# Confirms the gate is gone in the file that is actually installed, rather than
# trusting that the push landed the same bytes the patcher produced.
python3 "$PATCHER" "$WORK/readback.odex" --verify | grep -q 'already patched' \
    || die "the installed file does not read back as patched"
printf '  gate is gone\n'

say "rebooting"
printf '  the change takes effect when the health app restarts\n'
adbq reboot

cat <<EOF

  Done.

  Give it a few minutes on your wrist and check that readings are arriving. If
  anything misbehaves:

      $0 --restore

  which puts back $BACKUP from the watch and reboots.
EOF

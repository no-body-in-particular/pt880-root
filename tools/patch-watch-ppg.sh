#!/usr/bin/env bash
#
# Stop the watch refusing to measure a pulse when it thinks it is off the wrist.
#
#     ./patch-watch-ppg.sh              patch it
#     ./patch-watch-ppg.sh --dry-run    say what would happen, change nothing
#     ./patch-watch-ppg.sh --status     say what is on the watch, change nothing
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

# The sha256 of tools/patch_ppg_gate.py, re-pinned by publish.sh. This script
# downloads that file and runs it against a system partition, so a truncated or
# tampered download is refused rather than executed. Only checked on a fetched
# copy - a checkout next to this script is whatever the user has checked out.
PATCHER_SHA256="14260d5893177238237623afed2526ce369b68be4b1dfb8b306577b430ceaec4"

# The stock odex, straight out of pt880-firmware-stock.zip, as a last resort for
# --restore on a watch that has no backup of its own. Pinned for the same reason
# the patcher is: this one gets written to /system.
STOCK_SHA256="ae6db546e5abd4393e914a3f3f6eef69837efbb00db2e722343c945b0493fd4e"

ODEX=/system/priv-app/L009_Protocol.odex
BACKUP=/system/priv-app/L009_Protocol.odex.orig
STAGE=/data/local/tmp/L009_Protocol.odex

DO_DRY=0
DO_RESTORE=0
DO_STATUS=0

for a in "$@"; do
    case "$a" in
        --dry-run) DO_DRY=1 ;;
        --restore) DO_RESTORE=1 ;;
        --status) DO_STATUS=1 ;;
        -h|--help) sed -n '3,9p' "$0" 2>/dev/null \
                       || echo "flags: --dry-run --status --restore"; exit 0 ;;
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

# ---------------------------------------------------------------- the patcher

PATCHER="$HERE/patch_ppg_gate.py"

# Called by whichever mode needs it rather than at the top, so --restore from an
# on-device backup still works when the network does not.
need_patcher() {
    [ -f "$PATCHER" ] && return 0

    say "fetching the patcher"
    PATCHER="$WORK/patch_ppg_gate.py"

    # Fetched as .py.txt, not .py. The web server this is published on treats a
    # .py as a CGI script and tries to run it, which answers the download with a
    # 500 rather than the file. The suffix is the whole fix; the content is the
    # same file byte for byte, and the checksum below proves it.
    curl -fsSL "$BASE/patch_ppg_gate.py.txt" -o "$PATCHER" \
        || die "could not fetch patch_ppg_gate.py.txt from $BASE"

    GOT="$(sha256 "$PATCHER")"

    if [ "$GOT" != "$PATCHER_SHA256" ]; then
        die "patch_ppg_gate.py does not match its pinned checksum.
       expected $PATCHER_SHA256
       got      $GOT"
    fi

    command -v python3 >/dev/null 2>&1 \
        || die "python3 not found - on macOS, 'xcode-select --install' provides it"
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

# Reads the installed file and says which of the two it is. Changes nothing, and
# is the thing to run when you are not sure whether an earlier attempt landed.
if [ "$DO_STATUS" = 1 ]; then
    say "status"
    need_patcher
    adbq pull "$ODEX" "$WORK/on-watch.odex" >/dev/null || die "could not pull $ODEX"
    printf '  %s  %s bytes\n' "$(sha256 "$WORK/on-watch.odex" | cut -c1-16)" \
        "$(wc -c < "$WORK/on-watch.odex" | tr -d ' ')"

    if python3 "$PATCHER" "$WORK/on-watch.odex" --verify | grep -q 'already patched'; then
        printf '  PATCHED - the wear and cut checks are bypassed\n'
    else
        printf '  STOCK - the wear and cut checks are active\n'
    fi

    if adbq shell "[ -f $BACKUP ] && echo yes" | grep -q yes; then
        printf '  a backup exists on the watch at %s\n' "$BACKUP"
    else
        printf '  no backup on the watch (expected, if this has never been patched)\n'
    fi

    exit 0
fi

if [ "$DO_RESTORE" = 1 ]; then
    say "restore"

    # Three places the stock file can come from, best first. Without the last
    # two, a watch that was patched by some other means - or whose backup went
    # missing - has nothing to go back to, which is the situation this hit.
    SRC=""

    if adbq shell "[ -f $BACKUP ] && echo yes" | grep -q yes; then
        printf '  using the backup on the watch\n'
        SRC=device

    else
        LOCAL="$(ls -t "$HERE"/L009_Protocol.odex.stock-* 2>/dev/null | head -1 || true)"

        if [ -n "$LOCAL" ]; then
            printf '  no backup on the watch; using %s\n' "$LOCAL"
            cp "$LOCAL" "$WORK/stock.odex"
            SRC=local

        else
            printf '  no backup on the watch and none kept here; fetching the stock file\n'
            curl -fsSL "$BASE/L009_Protocol.odex.stock" -o "$WORK/stock.odex" \
                || die "could not fetch the stock odex from $BASE"
            GOT="$(sha256 "$WORK/stock.odex")"

            if [ "$GOT" != "$STOCK_SHA256" ]; then
                die "the fetched stock odex does not match its pinned checksum.
       expected $STOCK_SHA256
       got      $GOT"
            fi

            SRC=fetched
        fi
    fi

    if [ "$SRC" != device ]; then
        # Only ever restore a file that is genuinely unpatched, whatever it came
        # from - restoring an already-patched copy would look like it worked and
        # change nothing.
        need_patcher
        python3 "$PATCHER" "$WORK/stock.odex" --verify | grep -q 'already patched' \
            && die "the file being restored is itself patched - that is not a stock odex"

        adbq push "$WORK/stock.odex" "$STAGE" >/dev/null || die "could not stage the stock file"
        adbq shell "
          mount -o remount,rw /system &&
          cat $STAGE > $ODEX &&
          chown 0:0 $ODEX &&
          chmod 644 $ODEX
          mount -o remount,ro /system
        "
        adbq shell "rm -f $STAGE"
        say "done - rebooting"
        adbq reboot
        exit 0
    fi

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


# ---------------------------------------------------------------- pull, patch

need_patcher

say "reading the stock file"
adbq pull "$ODEX" "$WORK/stock.odex" >/dev/null || die "could not pull $ODEX"
printf '  %s  %s bytes\n' "$(sha256 "$WORK/stock.odex" | cut -c1-16)" \
    "$(wc -c < "$WORK/stock.odex" | tr -d ' ')"

say "patching"
python3 "$PATCHER" "$WORK/stock.odex" -o "$WORK/patched.odex" | sed 's/^ *//' | sed 's/^/  /'

# Nothing to do is a success, not a failure. The patcher writes no output file
# when the input is already patched, and without this the cmp below dies on a
# missing file and reports "expected 25 changed bytes, got 0" - which reads like
# the patch failed when in fact it was already installed.
if [ ! -f "$WORK/patched.odex" ]; then
    say "the watch is already patched - nothing to do"
    exit 0
fi

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

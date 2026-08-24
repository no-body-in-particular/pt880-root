#!/usr/bin/env bash
# Hand the power key to apps as a second button, and teach AVRCP the volume
# codes the stock layout is missing. Requires the rooted boot image (adb shell
# as uid 0 with a full capability set, so /system can be remounted).
#
# Reversible with restore-power-button.sh.
#
# Read the "Cost of the remap" section of README.md first: after this the power
# button no longer sleeps or powers off the watch.
set -e

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KL=/system/usr/keylayout

adb shell "mount -o rw,remount /system"

# Back up once. Re-running must not overwrite a good .orig with a patched file.
for f in gpio-keys.kl Generic.kl AVRCP.kl; do
  if adb shell "[ -f $KL/$f.orig ] && echo yes" | tr -d '\r' | grep -q yes; then
    echo "backup already present: $f.orig"
  else
    adb shell "cp -f $KL/$f $KL/$f.orig"
    echo "backed up: $f -> $f.orig"
  fi
done

for f in gpio-keys.kl Generic.kl AVRCP.kl; do
  adb push "$HERE/keylayout/$f" "$KL/$f" >/dev/null
  echo "pushed: $f"
done
adb shell "chmod 644 $KL/gpio-keys.kl $KL/Generic.kl $KL/AVRCP.kl"

echo
echo "verifying:"
adb shell "grep -n 'key 116' $KL/gpio-keys.kl $KL/Generic.kl; grep -n VOLUME $KL/AVRCP.kl"

echo
echo "rebooting — key layouts are only read when an input device is added..."
adb reboot

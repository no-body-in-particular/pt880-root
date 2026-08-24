#!/usr/bin/env bash
# Undo the power-button remap: put scancode 116 back to POWER so the button
# sleeps and powers off the watch again.
#
# The originals were copied to *.orig on the device before the remap, so this
# needs nothing from the host but adb.
set -e

echo "restoring original keylayouts..."
adb shell "mount -o rw,remount /system"
adb shell "cp -f /system/usr/keylayout/gpio-keys.kl.orig /system/usr/keylayout/gpio-keys.kl"
adb shell "cp -f /system/usr/keylayout/Generic.kl.orig   /system/usr/keylayout/Generic.kl"
adb shell "cp -f /system/usr/keylayout/AVRCP.kl.orig     /system/usr/keylayout/AVRCP.kl"
adb shell "chmod 644 /system/usr/keylayout/gpio-keys.kl /system/usr/keylayout/Generic.kl /system/usr/keylayout/AVRCP.kl"

echo "verifying:"
adb shell "grep -n 'key 116' /system/usr/keylayout/gpio-keys.kl /system/usr/keylayout/Generic.kl"

echo
echo "rebooting to reload the key layouts..."
adb reboot

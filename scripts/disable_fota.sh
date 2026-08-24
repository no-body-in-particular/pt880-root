#!/usr/bin/env bash
# Disable FOTA / OTA on the watch. Requires root adb (see scripts/flash.sh).
#
# Two clients ship on this device:
#   com.adups.fota.sysoper  /system/app/FotaUpdateReboot.apk   (Adups)
#   com.ic.icfotaclient     /system/app/ICFotaClient.apk       (runs at boot)
#
# Left enabled, either can pull a vendor image over the top of everything we
# patched. Adups also has a documented history of silent data exfiltration.
#
# `pm disable` is persistent: it is stored in
# /data/system/users/0/package-restrictions.xml and survives reboot.
# persist.* properties live in /data/property and survive too.
set -euo pipefail
export MSYS_NO_PATHCONV=1

adb wait-for-device
echo "== disabling packages =="
adb shell 'pm disable com.adups.fota.sysoper'  || true
adb shell 'pm disable com.ic.icfotaclient'     || true

echo "== pointing the OTA endpoint at localhost =="
adb shell 'setprop persist.sys.ota.host2 http://127.0.0.1/disabled'

echo "== stopping the running client =="
adb shell 'am force-stop com.ic.icfotaclient' || true

# Optional, needs a writable /system (adbd prctl patch - see build_boot_root.py).
if adb shell 'mount -o remount,rw /system' 2>/dev/null; then
    echo "== renaming the apks (reversible) =="
    adb shell 'cd /system/app && for f in FotaUpdateReboot.apk FotaUpdateReboot.odex \
        ICFotaClient.apk ICFotaClient.odex; do
        [ -f "$f" ] && mv "$f" "$f.disabled" && echo "  moved $f"; done' || true
    adb shell 'sync; mount -o remount,ro /system' || true
else
    echo "   /system not writable - pm disable alone is still persistent"
fi

echo
echo "== result =="
adb shell 'pm list packages -d | grep -iE "fota|adups"' || echo "  (none disabled?)"
echo -n "  fota processes running: "; adb shell 'ps | grep -c fota' || true

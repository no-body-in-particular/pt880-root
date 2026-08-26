# A minimal OTA that turns on adb over the network

`adb-enable.zip` appends four properties to `/system/build.prop` and does nothing else:

    persist.sys.usb.config=adb
    service.adb.tcp.port=5555
    ro.adb.secure=0
    ro.debuggable=1

It exists because there is no other way onto this watch without a cable. The tracker
application has no APK install path of any kind - no `PackageInstaller`, no `pm install` -
and `#USB#=adb` only sets `sys.usb.config`, which is USB. A signed OTA package is the only
remote route, and this is the smallest one that opens a shell to do everything else with.

## Why it is built this way

`update-binary` is a freestanding ARM program, not the edify interpreter. There is no
`updater` anywhere in `/system` to borrow, and recovery's `/sbin` holds only

    adbd healthd recovery rsa_decrypt sec_openssl ueventd watchdogd

with no shell, so the usual trick of shipping a shell script as the update binary does not
work here either. The alternative was to download a stranger's `update-binary` and let it
run as root on a device that cannot be recovered without hardware. `update-binary.c` is 220
lines of raw syscalls instead - no libc, no allocation, no zip handling - and can be read
end to end.

It **only appends**. A write that dies part way leaves a truncated final line, which
Android's property loader skips, so the failure mode is "the setting did not take", never
"the file is gone". It never truncates, never renames, and touches nothing else. Running it
twice is a no-op: it looks for its own marker first.

`/system` is not verity protected on this device - the normal-boot fstab carries no `verify`
flag, and the ext4 filesystem fills `system.img` exactly, leaving no room for a hash tree -
so editing `build.prop` cannot fail a verity check at boot.

## Signing

Both places that check a package - `RecoverySystem.verifyPackage` against
`/system/etc/security/otacerts.zip`, and recovery again against its own `res/keys` - hold
**the AOSP public test key**, whose private half is published in AOSP. The modulus in
recovery's `res/keys` is byte for byte the modulus of `testkey.x509.pem`, so a package signed
with that key passes both.

`sign-ota.py` applies the whole-file signature, which is not an APK signature: the zip's
trailing comment carries a detached PKCS#7 blob covering everything in front of it, then a
six byte footer saying where that blob starts. SHA1, because this recovery knows nothing
else - its binary carries no SHA256 at all, only *"failed to alloc memory for sha1 buffer"*.

AOSP's `signapk` does the same job but the prebuilt needs a Conscrypt native library that is
not always present.

## Building and checking

    clang --target=arm-linux-gnueabi -march=armv7-a -marm -static -nostdlib \
          -fuse-ld=lld -Os -fno-stack-protector -fno-builtin \
          -o update-binary update-binary.c

    zip -r -X unsigned.zip META-INF
    ./sign-ota.py unsigned.zip adb-enable.zip testkey.x509.pem testkey.pk8
    ./verify-ota.py adb-enable.zip testkey.x509.pem

`verify-ota.py` repeats what recovery does, so a package can be checked before it is sent.
It reports "would be accepted" or "would be REJECTED", and rejects a package with a single
byte altered inside the signed range.

The updater itself was run under `qemu-arm` against a copy of this watch's real
`build.prop`: it appended the block, left the original 234 lines byte for byte identical,
and changed nothing at all on a second run.

## Sending it

    SMS=#setFotaSrv#=http://<your host>/       (through the tracker's BPSM tunnel)
    SMS=#FotaUpdate#

The watch fetches over plain HTTP, and `ICOtaApiUtils` installs a TrustManager whose
`checkServerTrusted` returns unconditionally, so TLS would not be checked either. That is
worth knowing in both directions: it is why this works, and it is why anyone on the network
path can offer this watch a firmware image.

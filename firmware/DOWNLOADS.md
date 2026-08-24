# Firmware downloads

The bootchain images small enough to track live in `stock/` and `patched/` in
this repo. The full images are too large for git and are hosted separately.

| Archive | Size | sha256 of the archive |
|---|---|---|
| [pt880-firmware-stock.zip](http://coredump.ws/files/pt880-firmware-stock.zip) | 220 MB | `f324264310a7494859123db312aa353dfd740274733132d988e4fd2fd85fb612` |
| [pt880-firmware-patched.zip](http://coredump.ws/files/pt880-firmware-patched.zip) | 17 MB | `c26271b84033488dd5ce0b60feb0ac95314b541b92adce5d4fad1728fd1183f3` |

Each archive carries its own `README.txt` and `SHA256SUMS`, so after extracting:

```bash
sha256sum -c SHA256SUMS
```

Or fetch and verify both in one go:

```bash
./scripts/fetch-firmware.sh ./firmware/download
```

## pt880-firmware-stock.zip

A complete stock device: byte-exact dumps of an unmodified watch.

    system.img  vendor.img  l_modem.img  recovery.img  wcnmodem.img
    l_gdsp.img  l_ldsp.img  boot_stock_exact.img  splloader.img
    uboot.img   sml.img     trustos.img  logo.img  fbootlogo.img  misc.img

These partitions are model-generic — the same on every unit of this watch.

## pt880-firmware-patched.zip

The rooted bootchain, plus the `MANIFEST.md` giving the exact patch offset of
each image. `boot_capbnd_fdl.img` is the one to flash: root shell with a full
capability set, so `/system` can be remounted.

    boot_capbnd_fdl.img  boot_root_fdl.img  boot_min_fdl.img
    splloader_unlocked2.img  trustos_noverify2.img  MANIFEST.md

Built from one unit's own dumps, and the bootchain images are per-device signed
blobs. Prefer rebuilding from your own dump with `scripts/build.sh`; flashing
these to a different watch is not supported.

## What is deliberately not published

`prodnv`, `l_fixnv1`, `l_runtimenv1` and `miscdata` are per-unit: they carry
IMEI, the serial number and RF calibration. No donor copy is correct for
another device, so publishing them would be all risk and no use. Dump your own
with `scripts/backup.sh`.

For the same reason there is no `.pac` here. A flashable `.pac` built by the
Spreadtrum tooling bundles `prodnv` inside it, which is easy to miss — scanning
one for a serial number comes back clean because the IMEI is stored in BCD, not
ASCII.

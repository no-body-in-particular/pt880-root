# sl8521e-root

Tooling to dump, patch and reflash a Unisoc **SL8521E** kids' GPS watch
(`sp9820e_1h10` / `sl8521e_1h10ll_watch_native`, Android 4.4.4) in order to get
adb access on your own hardware.

Read [NOTES.md](NOTES.md) first — it documents the verification chain, every
patch offset, and the dead ends worth not repeating.

## Status

| Goal | State |
|---|---|
| Dump all partitions | working |
| Write any partition | working |
| Bootchain unlock (permanent, no PC at boot) | working |
| Boot a modified boot image | working |
| adb over USB | working |
| **root shell over adb** | **open** — see NOTES.md section 6 |

## Layout

    tools/       flashing stack and image builders
    fdl/         donor FDL1/FDL2 + CVE-2022-38694 payloads
    firmware/    byte-exact stock dumps of the bootchain (restore sources)
    scripts/     driver scripts (backup / build / flash / restore)
    analysis/    scratch space for disassembly work

`firmware/stock/` holds the small, irreplaceable partitions. `system.img`
(450 MB), `vendor.img` and the modem/DSP images are **not** committed — dump
them yourself with `scripts/backup.sh`, which writes them alongside.

## Requirements

- Python 3 with `capstone` (`pip install capstone`)
- MinGW gcc (32-bit) and a 32-bit libusb import library, to build `spd_dump_cve.exe`
- A USB cable able to pull the ID pin to GND, to enter boot ROM mode
- `adb` (platform-tools) for the post-boot steps

## Quick start

Build the flashing tool once:

```bash
./scripts/build_tools.sh
```

Dump the device (read-only, safe — repeat until it catches the boot ROM window):

```bash
./scripts/backup.sh ./firmware/mydevice
```

Build a rooted boot image from **your own** dump:

```bash
./scripts/build.sh ./firmware/mydevice
```

Flash the unlock (bootchain + boot image):

```bash
./scripts/flash.sh ./firmware/mydevice
```

Put the watch in boot ROM mode (ID to GND, then power on) whenever a script says
it is armed. The supervisor re-arms automatically, so keep retrying.

Restore to stock at any time:

```bash
./scripts/restore.sh ./firmware/mydevice
```

## Safety

- **Always dump before writing.** `prodnv`, `l_fixnv1` and `miscdata` hold IMEI
  and per-unit calibration; no donor image can replace them.
- Download mode lives in mask ROM and is always reachable, so a bad write is
  recoverable as long as you have the stock dumps. This has been exercised many
  times.
- Never use `partition_list` with this FDL2 — it answers `0xFE`
  (unsupported) and aborts the session before the trailing `reset`, stranding
  the chip. Partition names must come from the vendor XML.
- `written: N` from spd_dump means nothing on its own. Every script here reads
  the partition back and compares hashes.

## Credits

- `spd_dump` by ilyakurdyukov; TomKing062 for the CVE-2022-38694 research and
  the `custom_exec_no_verify` payloads.
- `tools/spd_dump_cve.c` adds three things to upstream: a fix for
  `load_partition()` discarding its receive result, USB endpoint-stall
  recovery, and an `exec_addr` command implementing CVE-2022-38694.

## Legal

For use on hardware you own. Bootloader unlocking will void warranties and can
brick a device.

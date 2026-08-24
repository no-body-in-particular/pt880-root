# Patched firmware

Built from **this unit's own dumps** in `../stock/`. The bootchain images are
per-device signed blobs — do not flash these to a different watch. Rebuild from
your own dump with `scripts/build.sh`.

| Image | Patch |
|---|---|
| `splloader_unlocked2.img` | `0x0054d8` + `0x005534`: `bl 0xb6d8` -> `movz w0,#0` (trustos + uboot verify) |
| `trustos_noverify2.img` | `0x015da2`: `cbz`->`nop`; `0x015cc4`: `bne`->`b` (both verify spins) |
| `boot_capbnd_fdl.img` | as `boot_root_fdl.img`, plus adbd `0x001216`: `18 20` -> `17 20` (`PR_CAPBSET_DROP` -> `PR_CAPBSET_READ`). **This is the one to flash** — root shell with a full capability set. |
| `boot_root_fdl.img` | default.prop props + adbd `setgid32`/`setgroups32`/`setuid32` stubs neutered. uid 0 but `CapBnd=0xc0`, so `/system` cannot be remounted. |
| `boot_min_fdl.img` | default.prop props only (adbd left stock) |

uboot is deliberately **not** patched — use the stock image.

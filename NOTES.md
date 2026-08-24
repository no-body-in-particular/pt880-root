# SL8521E watch — bootchain unlock and root

Target: Unisoc **SL8521E** (board `sp9820e_1h10`, product `sl8521e_1h10ll_watch_native`),
Android 4.4.4 KitKat, 32-bit userspace on a Cortex-A53.
Test unit: `l009_EU_noAnti_Common`.

Everything marked **measured** was confirmed by reading the value back off the
device. Anything else is called out as inference.

---

## 1. The verification chain

The single most important result. Each stage's check lives in *its own code*,
and the root of trust is not enforced:

| Stage | Verifies next? | Evidence |
|---|---|---|
| boot ROM to splloader / FDL1 | **NO** | a signature-invalid FDL1 ran on a plain EXEC_DATA, no exploit |
| splloader to uboot | **YES** | a patched uboot gave a black screen; uboot never ran |
| splloader to trustos | **YES** | assumed by symmetry, bypassed pre-emptively |
| uboot to boot | **YES**, but delegated | uboot issues an SMC; trustos does the actual work |
| trustos to boot image | **YES** | patching it is what finally let a modified boot run |

**Because the boot ROM verifies nothing, every downstream check is patchable.**
That is the entire basis of this unlock. Secure boot is evidently not fused on
this unit.

### The trap that cost the most time

uboot does **not** verify the boot image itself. `uboot_verify_img()` issues
`smc #0` into trustos and only reads back `param->a0`. On failure trustos
**never returns** — it spins in the secure world at EL3. The CPU never comes
back to uboot, so no uboot patch can possibly help. See section 4.

---

## 2. Patches (all verified by read-back)

Addresses are **file offsets** into the partition image.

### splloader — accept unsigned trustos and uboot

Load base `0x5000`, AArch64. The chain-loader at `0x005400..0x005580` calls the
verify thunk `0xb6d8` six times: sml, sml_bak, trustos, trustos_bak, uboot,
uboot_bak.

| Offset | From | To | Effect |
|---|---|---|---|
| `0x0054d8` | `bl 0xb6d8` | `0x52800000` (`movz w0,#0`) | trustos check passes |
| `0x005534` | `bl 0xb6d8` (`0x94000469`) | `0x52800000` (`movz w0,#0`) | uboot check passes |

sml, sml_bak, trustos_bak and uboot_bak are deliberately left verifying.

### trustos — remove the secure-world verification spins

Trusty TEE, **Thumb-2**, load base `0x8e01fe00` (so `addr = 0x8e01fe00 + off`).
Both sites call the same routine `0x157e0` (`sprd_verify_cert`).

| Offset | From | To | Effect |
|---|---|---|---|
| `0x015da2` | `20b1` (`cbz r0,#0x15dae`) | `00bf` (`nop`) | skips print + infinite loop |
| `0x015cc4` | `ebd1` (`bne #0x15c9e`) | `ebe7` (`b #0x15c9e`) | skips a second, **silent** loop |

There are exactly two Thumb `b .` (`0xE7FE`) infinite loops in this binary and
**both** must be bypassed. Patching only one leaves an identical symptom, which
is exactly what happened on the first attempt.

### FDL1 — accept unsigned FDL2 (host side, RAM only)

AArch64. Verify-then-jump at `0x006990`:

    00699c  bl   #0xbb88      -->  0x52800000  movz w0, #0
    0069a4  cbz  w0, #0xb9ac
    0069ac  add  w19, w19, #0x200      (skip DHTB header)
    0069b4  blr  x19                   (enter FDL2)

### FDL2 — un-gate partitions for writing (host side, RAM only)

Secure-partition table at `0x0554b4`, walked with `ldr r0,[r4,#4]!` — a
**pre-increment**, so entries start at `+0x04`:

    +04 splloader  +08 sml  +0c trustos  +10 uboot  +14 boot  +18 recovery ... +60 end

Repoint an entry into its own string so `strcmp` never matches:

| Entry | Name | Step | Result |
|---|---|---|---|
| `+0x04` | splloader | +1 | `plloader` |
| `+0x0c` | trustos | +1 | `rustos` |
| `+0x10` | uboot | **+2** | `oot` |
| `+0x14` | boot | +1 | `oot` |

**uboot must use +2.** `"uboot"+1` is `"boot"`, a real partition name, which
would silently re-gate the boot partition.

`system`, `vendor`, `cache`, `miscdata`, `misc` and `prodnv` appear in **neither**
FDL2's nor uboot's table. They are unverified and freely writable — which is a
much cheaper route to root if the boot path ever proves troublesome.

### uboot — NOT patched

Left **stock**. Once trustos is patched the SMC reports success and an
unmodified uboot accepts the image.

---

## 3. The boot image

The payload budget is tight:

    payload            0xa41800 = 10,754,048
     - header page          2,048
     - kernel (aligned)  9,412,608
     - dt (aligned)         88,064
     => max ramdisk      1,251,328    (stock uses 1,251,307 — 21 bytes spare)

**Do not drop `sbin/sec_openssl`.** It is 1.18 MB and tempting, but
`sbin/rsa_decrypt` invokes it:

    /sbin/sec_openssl rsautl -verify -in %s -inkey ...

That reference lives inside a **binary**, not an init script, so grepping `.rc`
files will not find it. (The kernel does not reference it — 0 occurrences.)

It fits anyway: editing only `default.prop` makes the ramdisk compress to
1,247,575 bytes, *smaller* than stock, leaving about 2 KB spare.

Minimal change set for adb (`tools/build_boot_minimal.py`), all 41 stock files
intact:

    ro.secure=0        ro.debuggable=1
    ro.adb.secure=0    persist.sys.usb.config=mtp,adb

The DHTB wrapper must keep stock geometry: same total size, same payload field
`0xa41800`, and the 0x60 signature block plus 0x234 signature data preserved
from stock. Its contents no longer matter, but the structure does.

---

## 4. Dead ends — do not repeat these

- **Patching uboot to bypass verification.** Cannot work; trustos spins in the
  secure world and uboot never regains control. Four separate uboot patches had
  zero effect: gate table `0x04aee0`, failure sink `0x0157b8`, verdict branch
  `0x015988`, SMC verdict `0x048768` / `0x0487a4`.
- **CVE-2022-38691/38692 cert forgery** (TomKing's `patcher`). It genuinely
  works — the ROM ran our unsigned FDL1 — but it overwrites FDL1's 256-byte RSA
  modulus at `0x8f2c`, which FDL1 then uses to verify FDL2, breaking FDL2.
- **CVE-2022-38694** (stack overwrite: `0x5200` over the saved LR at `0x4ee8`).
  Works, and is implemented in `tools/spd_dump_cve.c`, but turned out to be
  **unnecessary** since the ROM verifies nothing anyway.
- **`gen_fdl1-dl` and the UnisocBypass patchers.** Both scan for AArch64
  encodings (`0x34000040` cbz, `0xD503201F` nop). FDL1/splloader here are
  AArch64 so the approach is right, but that exact pattern does not occur.
  FDL2, uboot and trustos are 32-bit, so those tools cannot match at all.
- **DT table offset.** A smaller ramdisk shifts `dt` forward; making it land at
  stock's offset changed nothing. Not the cause.
- **`WRITE_LIMIT = 0xa41000`.** Never real — an artifact of measuring a write
  that was failing for an unrelated reason.

---

## 5. Two genuine bugs found in spd_dump

Both fixed in `tools/spd_dump_cve.c`:

1. **`load_partition()` discarded the receive result** — the only place in the
   file that did. A missing ACK went undetected, `recv_type()` re-read a stale
   ACK from `io->raw_buf`, and the reported `written:` figure was fiction.
   A read-back proved partitions still held stock data after "successful" writes.
2. **No endpoint-stall recovery.** The bulk IN endpoint halts on large writes
   and upstream calls `libusb_clear_halt()` nowhere.

Rule learned the hard way: `written: N` means nothing without a read-back.
Every claim in this document has one behind it.

---

## 6. Root: adbd and capabilities

adbd is vendor-modified and does **not** reference `ro.secure` at all — only
`ro.debuggable`, `service.adb.root` and `ro.adb.secure`. All were satisfied on
the device and it still dropped to uid 2000. It is statically linked ET_EXEC
ARM32 with no PLT, and its string references resolve through neither literal
pools nor `movw`/`movt`, so locating the decision branch was slow.

Patching bionic's **syscall stubs** sidesteps the logic entirely. Overwrite each
stub entry with `mov r0,#0 / bx lr` and the call returns success without
performing the syscall:

| adbd offset | Syscall | Effect |
|---|---|---|
| `0x010104` | `setgid32` | uid/gid drop fails silently |
| `0x010144` | `setgroups32` | |
| `0x01b790` | `setuid32` | -> adb shell is **uid 0** |
| `0x010164` | `prctl` | -> full capabilities, writable `/system` |

**Two stub prologues exist.** Most are `mov ip, r7` (`0xE1A0C007`); stubs that
reload arguments off the stack — `prctl` — use `mov ip, sp` (`0xE1A0C00D`) and
their entry sits 4 bytes earlier than you would guess. `build_boot_root.py`
guards on both and refuses to patch anything else.

### Why prctl is the one that matters

Measured on the device:

    init (pid 1):   CapPrm=3fffffffff  CapEff=3fffffffff  CapBnd=3fffffffff
    adbd:           CapPrm=3fffffffff  CapEff=3fffffffff  CapBnd=00000000c0
    adb shell:      CapPrm=00c0        CapEff=00c0        CapBnd=00c0

adbd keeps **full** `CapPrm`/`CapEff` for itself and only shrinks its *bounding*
set to `0xc0` (`CAP_SETUID|CAP_SETGID`) via `prctl(PR_CAPBSET_DROP)`. On
`execve` a child's permitted set is masked by the bounding set, so every shell
adbd spawns inherits `0xc0` — no `CAP_SYS_ADMIN`, so `mount -o remount,rw` fails
even as uid 0. Neutering `prctl` stops the bounding-set drop.

## 7. FOTA

Two OTA clients ship on this device and either can push a vendor image over
everything patched here:

    com.adups.fota.sysoper   /system/app/FotaUpdateReboot.apk    (Adups)
    com.ic.icfotaclient      /system/app/ICFotaClient.apk        (runs at boot)

`scripts/disable_fota.sh` disables both. `pm disable` is persistent — stored in
`/data/system/users/0/package-restrictions.xml` — and `persist.sys.ota.host2`
(the endpoint, `http://ota.beehome360.com/checkota.aspx`) is repointed at
localhost and persists in `/data/property`. Renaming the APKs additionally
requires a writable `/system`, i.e. the `prctl` patch above.

## 8. Status

Working:

- Full bootchain unlock, permanent, no PC needed at boot time
- Rooted-properties boot image running on the device
- **adb connects** over USB

Open:

- toybox / dropbear / sshfs / htop not yet installed
- **done:** `adb shell` is `uid=0(root)`. `adbd` is vendor-modified —
  it does **not** reference `ro.secure` at all, only `ro.debuggable`,
  `service.adb.root` and `ro.adb.secure` — and it still drops privileges with
  `ro.debuggable=1` and `service.adb.root=1` both set. SELinux is **Disabled**,
  so that is not the cause. Next step: patch `sbin/adbd`'s privilege drop
  directly, or add an `su` / root service to the ramdisk (about 2 KB of budget
  is available).

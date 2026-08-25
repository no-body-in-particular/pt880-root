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

### What actually drops the capabilities

Measured on the device:

    init (pid 1):   CapPrm=3fffffffff  CapEff=3fffffffff  CapBnd=3fffffffff
    adbd:           CapPrm=3fffffffff  CapEff=3fffffffff  CapBnd=00000000c0
    adb shell:      CapPrm=00c0        CapEff=00c0        CapBnd=00c0

adbd keeps **full** `CapPrm`/`CapEff` and only shrinks its *bounding* set to
`0xc0` (`CAP_SETUID|CAP_SETGID`). On `execve` a child's permitted set is masked
by the bounding set, so every shell adbd spawns inherits `0xc0` — no
`CAP_SYS_ADMIN`, so `mount -o remount,rw` fails even as uid 0.

It is adbd doing this to itself. Two earlier conclusions in this file were
wrong and are worth recording so nobody repeats them:

1. *"Neutering the prctl stub is the fix."* It stops the drop, but also kills
   adbd — the device stops enumerating over USB. The stub has **three** callers
   and only one is the capability drop.
2. *"The prctl stub is unreferenced dead code, so something else must be
   dropping the caps."* Wrong, and for a dull reason: the scan behind it decoded
   **ARM** `BL` only. adbd is Thumb-2 and reaches the ARM stub through **`BLX`**.

The check that catches both errors is to point the scan at a stub whose
reachability is already known. `setuid32` is patched in this very image and the
patch demonstrably works, so it *must* have callers. An ARM-only scan reports
zero for it too — which is the tell that the scan, not the binary, is at fault.

Decoded correctly, the drop is AOSP `drop_capabilities_bounding_set_if_needed()`
inlined at `va 0x91f8`:

    91f8  movs r4, #0        i = 0
    91fe  movs r0, #0x17     PR_CAPBSET_READ   ┐ loop condition
    9206  blx  prctl                           │
    920a  cmp  r0, #0                          │
    920c  blt  0x923a        read fails -> end ┘
    920e  subs r3, r4, #6    ┐ i-6 unsigned <= 1
    9210  cmp  r3, #1        │  <=> i == 6 (CAP_SETGID)
    9212  bls  0x9226        ┘  or i == 7 (CAP_SETUID) -> keep
    9216  movs r0, #0x18     PR_CAPBSET_DROP    <-- the one byte that matters
    921e  blx  prctl

Upstream uses `PR_CAPBSET_READ` as the loop condition and skips `CAP_SETUID` /
`CAP_SETGID` because `/system/bin/run-as` needs them; the compiler folded the
two equality tests into one unsigned range check. Result: `CapBnd` = `0xc0`
exactly, which is what we measured.

Upstream's early return for `ro.debuggable=1` sits inside `#ifdef
ALLOW_ADBD_ROOT`. **This vendor built without it**, so that return is not in the
binary — the only code between `getenv("ADB_EXTERNAL_STORAGE")` and the loop is
the matching `setenv`. That is why setting `ro.debuggable=1`, `ro.adb.secure=0`
and `service.adb.root=1` never helped: the property is read by
`should_drop_privileges()`, but the capability path is not guarded at all.

### The fix: one byte

`build_boot_capbnd.py` rewrites adbd file offset `0x001216` from `18 20` to
`17 20` — `movs r0,#24` (`PR_CAPBSET_DROP`) becomes `movs r0,#23`
(`PR_CAPBSET_READ`). The loop still runs and still calls prctl, so the other two
call sites and every non-capability use of prctl are untouched. Nothing is
dropped.

Result: `adb shell` is uid 0 with `CapBnd=3fffffffff` and can
`mount -o remount,rw /system` on its own. No `su`, no daemon, no helper service.

### Audit: nothing else drops privileges

adbd is statically linked, so a syscall it never calls has **no stub linked in
at all** — absence is proof rather than "no caller found". Every
privilege-relevant stub present:

| Syscall | Status |
|---|---|
| `setuid32` (213) | neutered |
| `setgid32` (214) | neutered |
| `setgroups32` (206) | neutered |
| `prctl` (172) | capbset drop -> read |
| `setsid` (66) | left alone — session leader, not a privilege drop |

**No stub exists** for `capset`, `capget`, `setresuid32`, `setresgid32`,
`setreuid32`, `setregid32`, `setfsuid32`, `setfsgid32`, `setrlimit`,
`prlimit64`, `chroot`, `unshare`, `personality` or `seccomp`. So there is no
second path to uid/gid, no `capset` masking, no `PR_SET_NO_NEW_PRIVS` (which
would have blocked the setuid busybox) and no seccomp filter.

Open: the third prctl call site at `va 0x23fe2` has not been identified — its
option is not a nearby literal and the surrounding bytes did not frame from a
valid instruction boundary. It is outside the capability path, which is fully
accounted for by the two sites above, and the one-byte patch does not touch it.

### Root inside an app, which the adbd patch does not give you

The one-byte capbnd patch makes `adb shell` uid 0. It does nothing for an app:
apps are forked from **zygote**, not from adbd, and zygote has already dropped
to the app's uid before the app's first instruction runs. Nothing an app can
call brings that back.

What makes a setuid helper work here is two properties of this build, both
measured rather than assumed:

- **Android 4.4's zygote never calls `PR_CAPBSET_DROP`.** It sets the app's
  capabilities with `capset()` alone, so an app process keeps
  `CapBnd=3fffffffff`. On `execve` a file's permitted set is masked by the
  bounding set — a full bounding set means a setuid-root binary gets
  everything. Later Android versions do drop it, and this would not work there.
- **SELinux is Disabled and `/system` carries no `nosuid`**, so the setuid bit
  is honoured and no policy objects. The audit in the section above already
  established there is no `PR_SET_NO_NEW_PRIVS` anywhere in adbd's path either.

`apps/watchlauncher/native/wsu.c` is the whole thing: `setgroups(0,NULL)`,
`setgid(0)`, `setuid(0)`, exec a shell. Installed 06755 in `/system/xbin` by
`apps/watchlauncher/install-root-helper.sh`, which needs the
`build_boot_capbnd.py` image to remount `/system` in the first place — the
earlier `build_boot_root.py` image has `CapBnd=0xc0` and cannot.

Order matters inside it: `setgroups` and `setgid` must run before `setuid(0)`
drops the ability to change groups. Getting that backwards is the classic way
to end up with root's uid and the caller's supplementary groups.

This is an unconditional root escalation for **every** app on the device, not
just ours. That is the point of it, and it is the reason it belongs on a
tracker you have taken ownership of and nowhere else.

### How SuperSU solves the same problem

Recorded because it is the obvious next move if the one-byte patch ever stops
working. `PR_CAPBSET_DROP` is irreversible for a process *and all descendants*,
so no `su` binary can restore caps inside adbd's tree. SuperSU sidesteps the
tree instead:

1. `daemonsu` starts at boot from init, so it never inherits the drop — full
   `CapBnd`, and on SELinux devices an unconfined context.
2. The `su` you type is only a **client**. It connects to the daemon over a unix
   socket and passes its stdin/stdout/stderr through `SCM_RIGHTS`, along with
   argv, env, cwd and terminal geometry.
3. The daemon forks a privileged child, `dup2`s those fds onto its stdio and
   execs the shell. Exit status returns over the socket.

The shell is a child of the daemon but wired to your terminal, so it feels like
a normal `su`. `build_boot_rootsvc.py` is a crude version of step 1 without
steps 2-3, which is why it can only run commands from a file rather than give
you a shell. On this device none of it is needed: SELinux is Disabled and the
bounding set was the only obstacle.

## 7. Userland: /system customisation

`customize_shell.py` edits `system_mod.img` offline (no remount needed to build
it) and `install_tools.py` adds busybox, htop, nano, dropbear and sshfs.
Alpine armhf packages are used throughout, so every binary is musl-linked and
is installed as `<name>.bin` plus a wrapper that invokes the musl loader with
`--library-path /system/xbin`.

Three things that are easy to get wrong, all found by running them:

- **No external commands in `/etc/mkshrc`.** The stock image has no `printf`,
  no `id`, no coreutils, and `/system/xbin` is only reachable *after* the rc has
  been sourced. `_e=$(printf '')` fails on every shell start. Literal 0x1b
  bytes are substituted into the file at build time via an `@ESC@` placeholder,
  and `USER_ID` (set by mksh, already used by the stock rc) replaces `id -u`.
- **Quote the whole alias assignment.** `alias $_a="busybox $_a"` expands to two
  words, so alias reads the second as a lookup of an undefined alias and errors
  — ~50 failures per shell start, and not one alias defined. `alias
  "$_a=/system/xbin/busybox $_a"` is one word.
- **`/etc/resolv.conf` does not exist on Android.** bionic resolves through the
  `net.dns1`/`net.dns2` properties. Every musl-linked tool installed here reads
  the file instead and fails with `Error resolving '<host>'` — routing is fine,
  only the resolver is missing. `customize_shell.py` writes one.

`TERM` defaults to `xterm-256color`, not the stock `vt100`: vt100 has no colour
and no ACS line-drawing, so htop and every other ncurses TUI renders as
monochrome soup. The terminfo entries live in `/system/etc/terminfo`.

**This has to be done by rewriting the stock line**, which is the one edit
`customize_shell.py` makes to otherwise byte-preserved upstream text. The stock
rc opens with `: ${TERM:=vt100} ...` and `:=` only assigns when the variable is
unset, so an appended `: ${TERM:=xterm-256color}` can never fire. The first
attempt did exactly that, looked right, and changed nothing.

One more ext4 lesson, from installing nano into an image that had already been
written to several times: an inode holds only **4 inline extents**, and
`ext4mod.alloc_blocks()` used to append every free run it walked past, so a
330 KB binary landed in 5 runs and was rejected. It now looks for a single
contiguous run first and only falls back to the scattered scan. Rebuilding from
the pristine `system.img` also defragments, and is the safer move when an image
has been through many rm/add cycles.

`busybox` is mode `06755` (setuid root). `/system` is mounted `ro,relatime`
with **no** `nosuid`, so setuid is honoured.

## 8. FOTA

Two OTA clients ship on this device and either can push a vendor image over
everything patched here:

    com.adups.fota.sysoper   /system/app/FotaUpdateReboot.apk    (Adups)
    com.ic.icfotaclient      /system/app/ICFotaClient.apk        (runs at boot)

`scripts/disable_fota.sh` disables both. `pm disable` is persistent — stored in
`/data/system/users/0/package-restrictions.xml` — and `persist.sys.ota.host2`
(the endpoint, `http://ota.beehome360.com/checkota.aspx`) is repointed at
localhost and persists in `/data/property`. Renaming the APKs additionally
requires a writable `/system`, i.e. the `prctl` patch above.

## 9. The tracker protocol

Full write-up in [protocol/README.md](protocol/README.md), machine-readable
inventory in `protocol/opcodes.json`. Summary of what it settles:

- The watch speaks **Thinkrace IW** (`IWAP`/`IWBP`), not the widely-posted
  `[3G*...]` SeTracker protocol. The `[3G*]` command set is present in the
  binary only because `protocol_fzd` and `protocol_mqtt` are compiled in too.
- Eight protocol implementations ship in one APK. The live one is
  `protocol_beehome`, chosen by `persist.sys.protocol_no=1`.
- Active protocol: 53 uplink + 53 downlink opcodes against 42 + 42 in the
  public Thinkrace V2.10 spec, so **21 pairs are undocumented**. Semantics for
  most of them were recovered from the dispatcher's own log strings.
- A separate **SMS control plane** (`<password>#<command>#`) carries 34
  commands, 11 documented. The undocumented 23 include remote microphone,
  remote camera, arbitrary modem AT commands, and repointing the device at
  another server.

Two measurement traps are written up there because both produced confidently
wrong answers first: counting the union of all eight protocols against one
protocol's spec, and sizing odex quick opcodes as one code unit.

## 10. Status

Working:

- Full bootchain unlock, permanent, no PC needed at boot time
- Rooted boot image running on the device
- **adb connects** over USB
- **`adb shell` is `uid=0(root)` with `CapBnd=3fffffffff`** — a real root shell,
  no `su` and no daemon. `mount -o remount,rw /system` works from it.
- **`/system` writable at runtime**, and rebuildable offline with
  `customize_shell.py` / `install_tools.py`
- busybox (setuid root), htop, dropbear and sshfs installed
- DNS works for musl-linked tools (`/etc/resolv.conf` added)
- FOTA disabled — see section 8

How root was reached, in order: three bionic syscall stubs neutered in
`sbin/adbd` (`setgid32`, `setgroups32`, `setuid32`) give uid 0, and one byte at
adbd offset `0x001216` turns `PR_CAPBSET_DROP` into `PR_CAPBSET_READ`, which
leaves the capability bounding set intact. Section 6 has the full derivation and
the two wrong turns taken on the way.

Open:

- The third prctl call site at `va 0x23fe2` is unidentified (section 6). Outside
  the capability path, so it does not affect anything here.
- `/etc/resolv.conf` ships static public resolvers, because the DHCP-assigned
  ones change per network. If a network forces its own, rewrite it from
  `getprop net.dns1` — `/system` is remountable now.

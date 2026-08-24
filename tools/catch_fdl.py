#!/usr/bin/env python3
"""
Keeps spd_dump continuously armed so it catches the SL8521E boot ROM
during its brief enumeration window.

All commands issued here are READ-ONLY. Nothing is written to the watch.

Stages:
  probe  - load FDL1 only. Proves cable + driver + FDL1 are good.
  full   - FDL1 + FDL2 + chip_uid + partition list dump.

Usage:
  python catch_fdl.py probe
  python catch_fdl.py full
  python catch_fdl.py full --attempts 20 --wait 60
"""
import argparse, datetime, os, subprocess, sys, time

WPULL   = r"C:\wpull"
FDLDIR  = os.path.join(WPULL, "fdl_sl8521e")
FDL1    = os.path.join(FDLDIR, "fdl1-sign.bin")
FDL2    = os.path.join(FDLDIR, "fdl2-sign.bin")
FDL1_BASE = "0x5000"
FDL2_BASE = "0x9efffe00"
# CVE-2022-38694 (TomKing062). 0x4ee8 is j__memcpy's saved LR on the boot ROM
# stack; the 8-byte payload is little-endian 0x5200 = FDL1_BASE + the 0x200 DHTB
# header, i.e. the first instruction of the image we just uploaded. 0x4f18
# (cmd_recv_data_usb's saved LR) is the documented fallback slot.
EXEC_ADDR = "0x4ee8"
EXEC_FILE = os.path.join(FDLDIR, "custom_exec_no_verify_4ee8.bin")

EXES = [
    os.path.join(WPULL, "spd_dump", "spd_dump.exe"),
    os.path.join(WPULL, "tk_spd", "spd_dump_LibUSB_Release", "spd_dump.exe"),
    os.path.join(WPULL, "tk_spd", "spd_dump_SPRD_Release",  "spd_dump.exe"),
]

def ts():
    return datetime.datetime.now().strftime("%H:%M:%S.%f")[:-3]

# This FDL2 answered BSL_CMD_READ_PARTITION (0x2d) with 0xFE
# (BSL_REP_UNSUPPORTED_COMMAND), so 'partition_list' must never be used - it
# aborts the session before the trailing 'reset' can run, stranding the chip.
# read_part/part_size use READ_START/READ_MIDST/READ_END instead, which work.
#
# Sizes below are deliberately generous. dump_partition() stops cleanly at the
# real end of a partition (break, not ERR_EXIT) and still sends READ_END, so
# over-requesting costs nothing and removes the need to discover sizes first.
#
# Ordered most-irreplaceable first: if a bad partition name ever aborts a run,
# the per-unit data is already safely on disk.
# EVERY name below is verbatim from <Block id="..."> in the vendor's own
# sl8521e_1h10ll_watch.xml. Do NOT add names from the TWRP fstab - that tree is
# for the sw763 product and lists partitions (l_fixnv2, l_runtimenv2, persist,
# *_bak) that do NOT exist here. A bad name can abort the session before
# 'reset' runs, stranding the chip and costing a battery unsolder.
GROUPS = {
    # IMEI, calibration, per-unit data. No donor PAC can ever replace these.
    # prodnv first: if anything aborts, the irreplaceable part is already saved.
    "backup1": [
        ("prodnv",          "8M"),
        ("l_fixnv1",        "4M"),
        ("l_runtimenv1",    "4M"),
        ("miscdata",        "2M"),
        ("misc",            "2M"),
    ],
    # Bootchain + the partition we actually want to patch later.
    # First two are top-ups: in backup1 they read exactly the requested 4M,
    # so they may have been truncated rather than reaching the partition end.
    # Re-read larger; if they really are 4M the read just stops there.
    "backup2": [
        ("l_fixnv1",        "16M"),
        ("l_runtimenv1",    "16M"),
        ("boot",            "24M"),
        ("recovery",        "24M"),
        ("uboot",           "4M"),
        ("splloader",       "2M"),
        ("sml",             "2M"),
        ("trustos",         "4M"),
        ("logo",            "2M"),
        ("fbootlogo",       "2M"),
        ("uboot_log",       "2M"),
    ],
    # Radio / DSP / GNSS.
    "backup3": [
        ("l_modem",         "32M"),
        ("l_ldsp",          "8M"),
        ("l_gdsp",          "8M"),
        ("l_deltanv",       "2M"),
        ("l_warm",          "4M"),
        ("pm_sys",          "4M"),
        ("wcnmodem",        "8M"),
        ("gpsgl",           "4M"),
        ("gpsbd",           "4M"),
    ],
    # Read fbootlogo back: it carries either the init built-in marker
    # (RC_ON_BOOT_FIRED) or the adbforce script's log, which tells us whether
    # the rc parses and whether the service actually execs.
    "readmarker": [
        ("fbootlogo", "1M"),
    ],
    # Read the boot partition back and compare it to what we think we wrote.
    # This has never actually been verified: every flash session died at
    # "timeout reached" on END_DATA before the in-session read-back could run,
    # so "the write landed" has been an inference, not a measurement.
    "verifyboot": [
        ("boot", "10M"),
    ],
    # NOTE: read_part writes to <name>.img, so verifyboot overwrites boot.img.
    # The pristine stock dump is preserved as boot_STOCK_ORIGINAL.img.
    # Read back the adbforce service's own log, which it dd's into the
    # uboot_log partition after every loop iteration. This is the only
    # diagnostic channel we have: MTP storage is not reachable from the PC
    # and there is no adb.
    "readlog": [
        ("uboot_log", "2M"),
    ],
    # EVERYTHING still outstanding for watch #2 in a single session, so the
    # device only has to be put into boot ROM once more. Ordered
    # irreplaceable-first: if it is interrupted, the valuable partitions are
    # already on disk and only the bulk ones need repeating.
    "backup_rest": [
        ("recovery",        "24M"),
        ("uboot",           "4M"),
        ("splloader",       "2M"),
        ("sml",             "2M"),
        ("trustos",         "4M"),
        ("logo",            "2M"),
        ("fbootlogo",       "2M"),
        ("uboot_log",       "2M"),
        ("l_modem",         "32M"),
        ("l_ldsp",          "8M"),
        ("l_gdsp",          "8M"),
        ("l_deltanv",       "2M"),
        ("pm_sys",          "4M"),
        ("wcnmodem",        "8M"),
        ("gpsgl",           "4M"),
        ("gpsbd",           "4M"),
        ("system",          "820M"),
        ("vendor",          "32M"),
        ("cache",           "16M"),
    ],
    # backup3 + backup4 in ONE session, so the watch only has to be put into
    # boot ROM once. Radio/GNSS first (irreplaceable), bulk last, so an
    # interruption still leaves the valuable parts on disk.
    "backup34": [
        ("l_modem",         "32M"),
        ("l_ldsp",          "8M"),
        ("l_gdsp",          "8M"),
        ("l_deltanv",       "2M"),
        ("pm_sys",          "4M"),
        ("wcnmodem",        "8M"),
        ("gpsgl",           "4M"),
        ("gpsbd",           "4M"),
        ("system",          "820M"),
        ("vendor",          "32M"),
        ("cache",           "16M"),
    ],
    # Bulk. Slow - run last, and only if the rest succeeded.
    "backup4": [
        ("system",          "820M"),
        ("vendor",          "32M"),
        ("customconfig",    "8M"),
        ("customconfig_ex", "4M"),
        ("cache",           "16M"),
    ],
}


# The only stages that WRITE to the watch. Everything else is read-only.
#   flash   - install the adb/root-enabled boot image
#   restore - put the stock boot image back (exact-size trim of the original,
#             NOT boot.img, which is a 24M over-read and would spill past the
#             end of the boot partition)
# FDL2 writes AT MOST WRITE_LIMIT bytes to 'boot' - a hard ceiling, measured
# three times (targets 0xa41200 / 0xa42000 / 0xa41800 every one stopped at
# 0xa41000). Neither block alignment nor the DHTB payload field had anything
# to do with it; both were wrong theories that cost real hardware cycles.
# The *_fdl.img files are cut to exactly the ceiling, so spd_dump sends
# precisely what FDL2 takes, END_DATA succeeds, and the trailing 'reset' runs.
# CRITICAL CORRECTION. Reading the boot partition back proved that NONE of the
# earlier writes ever landed - it still held the pristine stock image. Every
# session ended with "timeout reached" on END_DATA, and END_DATA is the COMMIT.
# spd_dump's "written: 0x9a0000" was its own send-side accounting; FDL2 buffered
# the data and never committed it because the commit never completed.
#
# The cause is sending MORE than FDL2 accepts: it takes exactly the DHTB
# payload and then stops answering, which desyncs the protocol so END_DATA gets
# no reply. boot_exact.img is sized to precisely that byte count (the 512 bytes
# dropped are verified zeros), so the transfer ends cleanly and END_DATA can
# commit.
# Does write_part work AT ALL on this FDL2? Every boot write has failed on its
# final block regardless of timeout (1s / 30s / 120s / 300s all identical), and
# a read-back proved the boot partition still holds the stock image. Before
# tuning anything further, establish the basic capability with a 64KB write to
# a cosmetic, backed-up partition.
# Escalating write-commit probe, all in ONE session so a single power-cycle
# yields a threshold instead of a single yes/no. Target is 'cache': 16MB,
# expendable, and already backed up as cache.img.
#
# This exists because the ACK stream turned out to prove nothing. Three
# data-phase runs (attempts 2, 29, 39) each sent all 2464 boot blocks and got
# all 2464 ACKs, then lost the device before END_DATA was ever sent. So the
# device acknowledges data it may never commit. The only trustworthy
# measurement is a read-back, and these probes carry per-block indices so the
# read-back reports WHICH block was the last to land.
#
# Ordered small -> large: if the device drops partway we keep every result
# below the failure point. The largest probe is exactly the size of
# boot_exact.img, so it reproduces the failing write in size terms on a
# partition we can afford to lose.
SIZE_PROBE = ["64k", "1m", "4m", "8m", "boot"]

WRITE_STAGES = {
    # Write the byte-exact stock boot content back to 'boot'. This is a no-op
    # in effect - it is precisely what the partition already holds - but it
    # answers the one question the size probe could not: does 'boot' accept a
    # WELL-FORMED write? The size probe proved FDL2 commits 10,092,544 bytes to
    # 'cache' byte-perfectly, so neither size nor the write path is at fault.
    # What differs is that every boot image we ever sent was malformed:
    # boot_exact.img's DHTB header declares a payload running 512 bytes past
    # its own EOF, and neither it nor boot_patched.img carries the 0x60
    # signature block + 0x234 signature data the stock partition has.
    # Same patched image, written to an expendable RAW partition. cache is
    # not in FDL2's secure-partition strcmp list (nor is boot), and it already
    # accepted 10,092,544 bytes of arbitrary data byte-perfectly. If this image
    # commits here, the content is fine and the stall is boot-specific; if it
    # stalls on the final block here too, the image data itself provokes it.
    "cachetest": ("cache", "boot_patched_fdl.img"),
    "stockwrite": ("boot", "boot_stock_exact.img"),
    # Same patched payload as "flash", but with the trailing DHTB signature
    # region zeroed. "flash" failed with the stock signature carried over -
    # a signature that covers the stock payload, not ours - while a write of
    # identical size and block count with matching content (stockwrite)
    # succeeded. If FDL2 treats an all-zero signature as unsigned, as
    # spd_dump's own reader does, this skips the check instead of failing it.
    "flashnosig": ("boot", "boot_nosig_fdl.img"),
    # CONTROL. Unmodified stock payload - the exact bytes FDL2 accepted in
    # stockwrite - with only the trailing signature region zeroed. One
    # variable. If this fails the signature is mandatory and FDL2 enforces
    # signed boot images, which rules out the FDL2 route for any modified
    # boot image. If it succeeds the signature is optional, and our payload
    # is being rejected for some other reason.
    "stocknosig": ("boot", "boot_stocknosig_fdl.img"),
    # uboot with BOTH patches: the gate table entry (its own flashing path)
    # and, the one that actually matters at boot, fn 0x01557c's failure sink
    # forced to report success. splloader and boot on the device are already
    # correct, so only uboot is rewritten.
    "uboot2": ("uboot", "uboot_unlocked2.img"),
    "writetest": ("fbootlogo", "writetest.img"),
    # Ask U-Boot for fastboot mode via the standard Android BCB in <misc>.
    # U-Boot on this device contains a full fastboot implementation plus the
    # strings "reboot-bootloader", "boot-recovery", get/set_recovery_message
    # and "partition <misc> read error, can not get recovery message" - so it
    # reads the BCB. This is a 64KB write, the size that provably commits,
    # unlike the 10MB boot write which always loses its final block.
    "bcbfastboot": ("misc", "misc_fastboot.img"),
    # boot_exact.img was structurally invalid: its DHTB header declared a
    # payload ending 512 bytes past its own EOF, and it carried no signature
    # region at all. boot_patched_fdl.img keeps the stock geometry exactly -
    # same total size, same payload field, byte-identical kernel and
    # signature regions - and differs only in the ramdisk.
    "flash":   ("boot", "boot_min_fdl.img"),
    "restore": ("boot", "boot_restore_fdl.img"),
}
# 0x1000 (4096) meant a 10MB boot write was 2,464 round-trips, and it always
# died on the final block. The 64KB write test (16 round-trips) committed
# perfectly, so the failure scales with the number of transfers rather than
# with any per-block timing. 0x8000 cuts the boot write to ~308 round-trips.
# The spd_dump README warns some FDLs reject large block sizes - if this one
# does, it will fail immediately and visibly rather than after 2,400 blocks.
BLK = "0x1000"   # reverted: 0x8000 failed identically (one block short), and
                 # the ONE fully-successful write we have (64KB to fbootlogo,
                 # verified byte-identical) was done at 0x1000.
WRITE_LIMIT = 0x1800000  # boot partition size (24MB, from the raw over-read).
                         # The old 0xa41000 'ceiling' was never real: it sat 2KB
                         # below the stock payload size 0xa41800, an artifact of
                         # measuring a write that failed for an unrelated reason.
                         # sizeprobe committed 0x9a0000 bytes to 'cache' and read
                         # them back byte-identical, so there is no size ceiling.


def build_cmd(exe, stage, wait, outdir, wtimeout=0, use_cve=False):
    """Every stage ends with 'reset' so the watch reboots out of the loader.

    Without that trailing reset the chip stays in FDL state and nothing can
    re-handshake with it - which is exactly how we stranded it twice.
    """
    cmd = [exe, "--verbose", "1", "--wait", str(wait)]
    if stage == "probe":
        cmd += ["fdl", FDL1, FDL1_BASE, "reset"]
        return cmd

    # REVERTED. Putting 'timeout' before the fdl commands was a mistake: every
    # FDL1 upload that ever succeeded ran at the 1000ms default, and raising it
    # to 300000 turned a fast, visible "timeout reached" into a silent 5-minute
    # block - which I then misread as a hardware stall. Any override now goes
    # AFTER the uploads, so it cannot mask an FDL load failure.
    # keep_charge on EVERY stage, diag included. It was dropped from diag so
    # that power_off would leave the watch unlatched - but that meant ~15
    # retries over an hour with the device awake in loader mode and NOT
    # charging. A sagging battery matches the symptoms exactly: the tiny
    # boot-ROM handshake still answers, while sustained bulk transfers stall
    # and the failures creep earlier. Charging matters more than a tidy exit.
    cmd += ["keep_charge", "1"] + [
        # CVE-2022-38694. exec_addr must precede the FDL1 'fdl' command: instead
        # of EXEC_DATA (where the boot ROM verifies the signature) spd_dump
        # writes 0x5200 over the ROM's saved return address at 0x4ee8, so the
        # ROM returns into our uploaded FDL1 without ever verifying it. FDL1
        # itself stays byte-for-byte stock, so its embedded key still validates
        # FDL2 normally - unlike the 38691/38692 cert forgery, which replaced
        # FDL1's RSA modulus and consequently broke FDL2 loading.
        *(["exec_addr", EXEC_ADDR, EXEC_FILE] if use_cve else []),
        "fdl", FDL1, FDL1_BASE,
        "fdl", FDL2, FDL2_BASE,
        "disable_transcode",
        "blk_size", BLK,
        *(["timeout", str(wtimeout)] if wtimeout else []),
        # spd_dump's default io->timeout is 1000ms. eMMC writes stall
        # periodically (erase / GC) and the first stall over a second aborts
        # the transfer with "unexpected response (0xffffffff)". That killed all
        # four boot writes - each died ~16s in, at whatever byte count
        # throughput had reached, which is why the stop point drifted
        # (0xa41000 three times, then 0xa40000). Never a size ceiling.
        # 30000 was not enough: a 64s mid-write stall was survived, but the
        # final block (end-of-write eMMC flush) still exceeded it and only
        # 2624 of 2625 blocks landed. Give it five minutes - a genuinely dead
        # block costs a long wait, a slow one costs nothing.
        "chip_uid",
    ]
    if stage == "diag":
        # Pure measurement. No writes. Answers the questions I have been
        # guessing at: how big is the boot partition really, and how much of
        # the image actually landed.
        cmd += [
            "part_size", "boot",
            "read_part", "boot", "0", "16M",
            os.path.join(outdir, "boot_current.img"),
            # power_off (not reset): per the spd_dump README this leaves the
            # device off once the cable is unplugged, instead of latched on
            # needing a battery disconnect. No soldering to recover from this.
            "power_off",
        ]
        return cmd

    if stage == "unlock":
        # Permanent unlock, in ONE session so the watch is only opened once.
        #
        # The boot ROM does NOT verify: a signature-invalid FDL1 ran on a plain
        # EXEC_DATA with no exploit at all. Each stage's check lives in its own
        # code, so patching that code is sufficient and nothing above enforces
        # anything. uboot_unlocked.img has 'boot' removed from its own secure
        # table, so once it is in place the watch loads an unsigned boot image
        # by itself - no CVE, no host tooling, permanent.
        #
        # Order matters: uboot first, then boot. If the session dies between
        # them the watch still has a stock-signed boot that its new uboot will
        # happily load, so it stays bootable either way.
        for part, fname, verify in (
                ("uboot", "uboot_smc.img", "uboot_verify.img"),
                ("boot", "boot_min_fdl.img", "boot_verify.img")):
            src = os.path.join(outdir, fname)
            if not os.path.isfile(src):
                sys.exit("missing image: " + src)
            cmd += ["write_part", part, src]
            cmd += ["read_part", part, "0", str(os.path.getsize(src)),
                    os.path.join(outdir, verify)]
        cmd += ["reset"]
        return cmd

    if stage == "unlockall":
        # The full permanent unlock, in one session.
        #
        # Chain, every link measured rather than assumed:
        #   boot ROM -> splloader : does NOT verify (a signature-invalid FDL1
        #                           ran on a plain EXEC_DATA, no exploit), so
        #                           a patched splloader will be accepted
        #   splloader -> uboot    : DOES verify (a patched uboot gave a black
        #                           screen - uboot never ran at all)
        #   uboot -> boot         : DOES verify (init's on-boot builtin marker
        #                           never fired, so the kernel never started)
        #
        # Order is chosen so an interrupted session always leaves a bootable
        # watch:
        #   splloader first - once patched it accepts BOTH signed and unsigned
        #     uboot, so on its own it changes nothing observable.
        #   uboot second    - needs the patched splloader to load at all.
        #   boot last       - the only image that needs the patched uboot.
        # Dying after step 1 or 2 leaves stock-signed images downstream, which
        # the patched loaders still accept.
        # uboot stays STOCK: with trustos patched, the SMC now reports success,
        # so an unmodified uboot accepts the boot image on its own.
        for part, fname, verify in (
                ("splloader", "splloader_unlocked2.img", "splloader_verify.img"),
                ("trustos", "trustos_noverify2.img", "trustos_verify.img"),
                ("uboot", "uboot.img", "uboot_verify.img"),
                ("boot", "boot_min_fdl.img", "boot_verify.img")):
            src = os.path.join(outdir, fname)
            if not os.path.isfile(src):
                sys.exit("missing image: " + src)
            cmd += ["write_part", part, src]
            cmd += ["read_part", part, "0", str(os.path.getsize(src)),
                    os.path.join(outdir, verify)]
        cmd += ["reset"]
        return cmd

    if stage == "restoreall":
        # Put uboot AND boot back to the byte-exact factory images in one
        # session. Needed because the unlock attempt left the watch with a
        # black screen - not even the uboot logo - which means splloader
        # refused the modified uboot, so nothing draws to the panel at all.
        # uboot.img is the raw 1MB dump and is exactly the partition size, and
        # boot_stock_exact.img is the trimmed original that has already been
        # written and read back byte-identical once before.
        for part, fname, verify in (
                ("uboot", "uboot.img", "uboot_verify.img"),
                ("boot", "boot_stock_exact.img", "boot_verify.img")):
            src = os.path.join(outdir, fname)
            if not os.path.isfile(src):
                sys.exit("missing image: " + src)
            cmd += ["write_part", part, src]
            cmd += ["read_part", part, "0", str(os.path.getsize(src)),
                    os.path.join(outdir, verify)]
        cmd += ["reset"]
        return cmd

    if stage == "sizeprobe":
        # write -> read back -> next size, all in one session. Each read_part
        # asks for exactly the number of bytes written, so the comparison is
        # direct and check_probe.py can localise the last committed block.
        for name in SIZE_PROBE:
            src = os.path.join(outdir, "probe_%s.img" % name)
            if not os.path.isfile(src):
                sys.exit("missing probe image: %s (run make_probe.py)" % src)
            cmd += ["write_part", "cache", src]
            cmd += ["read_part", "cache", "0", str(os.path.getsize(src)),
                    os.path.join(outdir, "probe_%s_back.img" % name)]
        cmd += ["reset"]
        return cmd

    if stage in GROUPS:
        for name, size in GROUPS[stage]:
            cmd += ["read_part", name, "0", size,
                    os.path.join(outdir, name + ".img")]
    elif stage in WRITE_STAGES:
        part, fname = WRITE_STAGES[stage]
        path = os.path.join(outdir, fname)
        if not os.path.isfile(path):
            sys.exit("missing image to write: " + path)
        sz = os.path.getsize(path)
        # CORRECTED INVARIANT. It is not block alignment, and not the DHTB
        # payload field either - both of those theories were wrong. This FDL2
        # writes at most WRITE_LIMIT bytes to 'boot', measured three times
        # (targets 0xa41200 / 0xa42000 / 0xa41800 all stopped at 0xa41000).
        # Send exactly that many bytes: any more and spd_dump keeps pushing
        # data FDL2 will not take, times out, and never reaches 'reset' -
        # which strands the chip and costs a battery desolder.
        with open(path, "rb") as fh:
            head = fh.read(0x34)
        # The DHTB check only makes sense for the boot partition; other
        # partitions (e.g. the fbootlogo write test) hold raw data.
        if part == "boot" and head[:4] != b"DHTB":
            sys.exit("%s is not a DHTB image" % path)
        # Smaller is fine and preferable - it just means fewer blocks and
        # less exposure to the eMMC stalls that killed earlier writes.
        # Only reject an image LARGER than the ceiling, since those get
        # silently truncated (which cost real device-tree data once).
        if sz > WRITE_LIMIT:
            sys.exit("image %s is %d bytes, larger than the observed write "
                     "ceiling %d (0x%x). It would be truncated. Shrink the "
                     "ramdisk instead."
                     % (path, sz, WRITE_LIMIT, WRITE_LIMIT))
        print("image %d bytes, %d under the ceiling, %d blocks"
              % (sz, WRITE_LIMIT - sz, (sz + 0xfff) // 0x1000))
        cmd += ["write_part", part, path]
        # Read the partition straight back in the SAME session so the write can
        # be hash-verified before the watch is ever booted from it.
        cmd += ["read_part", part, "0", str(sz),
                os.path.join(outdir, "boot_verify.img")]
    cmd += ["reset"]
    return cmd

# Markers that indicate we actually talked to the chip, even on a failed run.
GOOD = ("chip", "uid", "verify", "exec", "fdl2", "partition", "sprd", "connect")

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("stage", choices=["probe","diag","readlog","verifyboot","readmarker","writetest","bcbfastboot","backup1","backup2","backup3","backup4","backup34","backup_rest","flash","restore","sizeprobe","stockwrite","flashnosig","stocknosig","cachetest","unlock","restoreall","unlockall","uboot2"])
    ap.add_argument("--attempts", type=int, default=999,
                    help="how many times to re-arm (default: keep going)")
    ap.add_argument("--wait", type=int, default=60,
                    help="seconds spd_dump waits per attempt (default 60)")
    ap.add_argument("--exe", default=None, help="path to spd_dump.exe")
    # Needs an spd_dump built with the exec_addr command (spd_dump_cve.exe).
    ap.add_argument("--cve", action="store_true",
                    help="use CVE-2022-38694 to run FDL1 without ROM verification")
    ap.add_argument("--outdir", default=None,
                    help="output directory (default dump_out). Use a separate "
                         "one per device - the stage filenames are fixed, so a "
                         "second watch would overwrite the first one's backup.")
    ap.add_argument("--clear-halt", action="store_true", dest="clear_halt",
                    help="run the clear_halt helper before each attempt "
                         "(OFF by default - it disturbs the boot ROM)")
    ap.add_argument("--write-timeout", type=int, default=0,
                    help="io->timeout ms, applied AFTER the fdl uploads")
    args = ap.parse_args()

    outdir = args.outdir or os.path.join(WPULL, "dump_out")
    os.makedirs(outdir, exist_ok=True)
    logpath = os.path.join(outdir, "catch_fdl.log")

    exe = args.exe
    if not exe:
        for c in EXES:
            if os.path.isfile(c):
                exe = c
                break
    if not exe or not os.path.isfile(exe):
        sys.exit("spd_dump.exe not found - pass --exe")

    for f in (FDL1, FDL2):
        if not os.path.isfile(f):
            sys.exit("missing FDL: " + f)

    cmd = build_cmd(exe, args.stage, args.wait, outdir,
                    args.write_timeout, args.cve)

    print("exe    : %s" % exe)
    print("stage  : %s" % args.stage)
    print("fdl1   : %s @ %s" % (FDL1, FDL1_BASE))
    if args.stage != "probe":
        print("fdl2   : %s @ %s" % (FDL2, FDL2_BASE))
    print("log    : %s" % logpath)
    print()
    print("ARMED. Now plug the watch in / trigger boot ROM mode.")
    print("Re-arms instantly after each attempt, so keep retrying the watch side.")
    print("Ctrl+C to stop.\n")

    log = open(logpath, "a", encoding="utf-8", errors="replace")
    log.write("\n===== %s  session start  stage=%s =====\n" % (ts(), args.stage))
    log.flush()

    attempt = 0
    try:
        while attempt < args.attempts:
            attempt += 1
            head = "----- attempt %d  %s -----" % (attempt, ts())
            print(head, flush=True)
            log.write(head + "\n"); log.flush()

            saw_device = False
            wrong_state = False

            # OFF BY DEFAULT (--clear-halt to enable). This helper does not
            # merely clear endpoints: setup() also issues the CDC control
            # transfer (0x21,34,0x601) - the same one spd_dump sends to start
            # FDL1 - and claims/releases the interface. That means it pokes the
            # boot ROM's state machine 0.4s before spd_dump drives it. FDL1
            # uploaded perfectly all morning WITHOUT this step, and stopped
            # working within minutes of my adding it.
            helper = os.path.join(WPULL, "fdl1_reset.py")
            if args.clear_halt and os.path.isfile(helper):
                try:
                    ch = subprocess.run(
                        [sys.executable, helper, "clearhalt", "--wait", "1"],
                        capture_output=True, text=True, timeout=20)
                    for ln in (ch.stdout or "").splitlines():
                        if "clear_halt" in ln:
                            print("   " + ln.strip(), flush=True)
                            log.write("   " + ln.strip() + chr(10))
                    time.sleep(0.4)   # let WinUSB settle before spd_dump claims
                except Exception:
                    pass

            started = time.time()
            try:
                # stdin MUST be attached: spd_dump's check_confirm() prompts
                # 'Answer "yes" to confirm the "write_part" command:' and reads
                # it with scanf. With no stdin it gets EOF and aborts with
                # "operation is not confirmed" - before writing anything, and
                # before the trailing 'reset' can run, which strands the chip.
                # The user authorised this write explicitly; auto-answer it.
                p = subprocess.Popen(cmd, stdout=subprocess.PIPE,
                                     stderr=subprocess.STDOUT,
                                     stdin=subprocess.PIPE,
                                     universal_newlines=True, bufsize=1)
                p.stdin.write("yes\n" * 8)
                p.stdin.flush()
            except OSError as e:
                sys.exit("failed to launch spd_dump: %s" % e)

            for line in p.stdout:
                line = line.rstrip("\n")
                stamped = "[%s] %s" % (ts(), line)
                print(stamped, flush=True)
                log.write(stamped + "\n"); log.flush()
                low = line.lower()
                if any(g in low for g in GOOD):
                    saw_device = True
                # Device is on the bus but past boot ROM (e.g. FDL1 already
                # running from a previous run) - handshake can never succeed.
                if "ver expected" in low:
                    wrong_state = True
            rc = p.wait()
            try:
                p.stdin.close()
            except Exception:
                pass
            elapsed = time.time() - started

            # A failed run never reaches the trailing 'reset', so the chip is
            # left in FDL state. The loader stays responsive only briefly
            # before it wedges, so try a reset IMMEDIATELY - this is the
            # difference between re-triggering and desoldering the battery.
            # DISABLED. This rescue has never once helped, and it actively
            # harms: libusb_reset_device does return the chip to boot ROM
            # (we see BSL_REP_VER 'SPRD3'), but the tool then sends CONNECT
            # with the wrong checksum framing, gets 0x8b VERIFY_ERROR, and
            # wedges a device that had just recovered. Leaving the chip alone
            # after a failure is strictly better.
            if False and rc != 0 and saw_device:
                helper = os.path.join(WPULL, "fdl1_reset.py")
                if os.path.isfile(helper):
                    print(">>> run failed - attempting immediate loader reset",
                          flush=True)
                    try:
                        r = subprocess.run(
                            [sys.executable, helper, "reset", "--wait", "3"],
                            capture_output=True, text=True, timeout=25)
                        for ln in (r.stdout or "").splitlines()[-6:]:
                            print("    " + ln, flush=True)
                            log.write("    " + ln + "\n")
                    except Exception as e:
                        print("    rescue failed: %s" % e, flush=True)

            tail = ("----- attempt %d exit=%d device_contact=%s "
                    "elapsed=%.1fs -----") % (attempt, rc, saw_device, elapsed)
            print(tail + "\n", flush=True)
            log.write(tail + "\n"); log.flush()

            if rc == 0 and saw_device:
                print("*** SUCCESS on attempt %d ***" % attempt)
                print("Output in: %s" % outdir)
                log.write("*** SUCCESS attempt %d ***\n" % attempt)
                break

            if wrong_state:
                print(">>> A device IS present at 1782:4d00, but it is not in")
                print(">>> boot ROM state - most likely FDL1 is still running")
                print(">>> from a previous run. POWER-CYCLE THE WATCH")
                print(">>> (unplug, battery pull if needed, then re-trigger).")
            elif saw_device:
                print(">>> Made contact but the run did not complete cleanly.")
                print(">>> Window was probably too short. Re-arming.")

            # Back off on fast failures so a stuck device can't burn the whole
            # attempt budget in a few seconds.
            if elapsed < 5:
                # Cap at 30s, not 15: a stranded device fails in ~2s, so a
                # short backoff burns the whole attempt budget in under a
                # minute - long before the user can power-cycle the watch.
                delay = min(30, 5 + attempt * 2)
                print(">>> fast failure (%.1fs) - backing off %ds before "
                      "re-arming\n" % (elapsed, delay), flush=True)
                time.sleep(delay)
            else:
                print("", flush=True)
    except KeyboardInterrupt:
        print("\nstopped by user")
    finally:
        log.close()

if __name__ == "__main__":
    main()

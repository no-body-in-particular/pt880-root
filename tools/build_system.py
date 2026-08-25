#!/usr/bin/env python3
"""Produce a modified system.img: install tools into /xbin, drop the FOTA apks.

Works on a COPY of the dump. Paths are image-relative, so /system/xbin on the
device is "/xbin" here.

Installed (all verified 32-bit EM_ARM before this runs):
    busybox              busybox.net official static build
    dbclient             Alpine armhf signed repo (Dropbear 2022.83)
    ld-musl-armhf.so.1   Alpine musl - dbclient's loader
    libz.so.1            Alpine zlib - dbclient needs it
    ssh                  wrapper: invokes the musl loader + dbclient

The wrapper exists because dbclient is dynamically linked against
/lib/ld-musl-armhf.so.1 and the device's rootfs is read-only, so PT_INTERP
cannot be satisfied. Invoking the loader explicitly sidesteps that entirely.

Removed: FotaUpdateReboot.{apk,odex} (com.adups.fota.sysoper) and
ICFotaClient.{apk,odex} (com.ic.icfotaclient). Either can push a vendor image
over everything patched here, and Adups has a documented history of silent data
exfiltration.
"""
import os
import shutil
import struct
import sys

import paths
from ext4mod import Ext4RW

SRC = paths.w2("system.img")
DST = paths.w2("system_mod.img")
T = paths.TOOLS_ARM

SSH_WRAPPER = """#!/system/bin/sh
# ssh -> dropbear dbclient
# dbclient is dynamically linked against /lib/ld-musl-armhf.so.1 and rootfs is
# read-only, so the loader is invoked explicitly and LD_LIBRARY_PATH supplies
# libz. Android has no /etc/passwd, so dbclient cannot infer a username -
# pass one with -l or user@host.
X=/system/xbin
exec $X/ld-musl-armhf.so.1 --library-path $X $X/dbclient "$@"
"""

INSTALL = [
    ("busybox", os.path.join(T, "busybox-armv7l"), 0o100755),
    ("dbclient", os.path.join(T, "alpine", "usr", "bin", "dbclient"), 0o100755),
    ("ld-musl-armhf.so.1", os.path.join(T, "alpine", "m", "lib",
                                        "ld-musl-armhf.so.1"), 0o100755),
    ("libz.so.1", os.path.join(T, "alpine", "z", "lib", "libz.so.1.3.1"), 0o100755),
]

REMOVE = [("/app", "FotaUpdateReboot.apk"),
          ("/app", "FotaUpdateReboot.odex"),
          ("/app", "ICFotaClient.apk"),
          ("/app", "ICFotaClient.odex")]

if not os.path.exists(DST) or os.path.getsize(DST) != os.path.getsize(SRC):
    print("copying %s -> %s (%.0f MB)" % (SRC, DST, os.path.getsize(SRC) / 1048576))
    shutil.copyfile(SRC, DST)

fs = Ext4RW(DST)
print("free before: %d blocks (%.1f MB), %d inodes"
      % (fs.free_blocks, fs.free_blocks * fs.block_size / 1048576, fs.free_inodes))
print()

print("=== removing FOTA ===")
freed = 0
for d, n in REMOVE:
    try:
        size, nb = fs.rm(d, n)
        freed += size
        print("  removed %s/%-26s %9d bytes (%d blocks)" % (d, n, size, nb))
    except FileNotFoundError:
        print("  %s/%s : not present (already removed?)" % (d, n))
print("  freed %.2f MB" % (freed / 1048576))
print()

print("=== installing into /xbin ===")
for name, path, mode in INSTALL:
    if not os.path.isfile(path):
        sys.exit("missing source file: " + path)
    data = open(path, "rb").read()
    if data[:4] != b"\x7fELF":
        sys.exit("%s is not an ELF" % path)
    cls = data[4]
    mach = struct.unpack("<H", data[18:20])[0]
    if cls != 1 or mach != 40:
        sys.exit("%s is class=%d machine=%d, need 32-bit EM_ARM" % (name, cls, mach))
    try:
        fs.rm("/xbin", name)
        print("  (replacing existing %s)" % name)
    except FileNotFoundError:
        pass
    ino, nb = fs.add("/xbin", name, data, mode)
    print("  + /xbin/%-22s %9d bytes  ino=%-6d %d blocks  mode=%o"
          % (name, len(data), ino, nb, mode))

try:
    fs.rm("/xbin", "ssh")
except FileNotFoundError:
    pass
ino, nb = fs.add("/xbin", "ssh", SSH_WRAPPER.encode(), 0o100755)
print("  + /xbin/%-22s %9d bytes  ino=%-6d %d blocks  mode=755"
      % ("ssh", len(SSH_WRAPPER), ino, nb))

print()
print("free after : %d blocks (%.1f MB), %d inodes"
      % (fs.free_blocks, fs.free_blocks * fs.block_size / 1048576, fs.free_inodes))
fs.close()

# re-open read-only and verify everything reads back
from ext4tool import Ext4
v = Ext4(DST)
print()
print("=== verification (re-read from the image) ===")
ino = v.resolve("/xbin")
for nm, child, ft, _, _, _ in v.listdir(ino):
    if nm in (".", ".."):
        continue
    node = v.read_inode(child)
    sz = struct.unpack("<I", node[0x04:0x08])[0]
    mode = struct.unpack("<H", node[0x00:0x02])[0]
    print("  %-24s %9d  mode=%o" % (nm, sz, mode))
print()
gone = [n for _, n in REMOVE
        if n not in [e[0] for e in v.listdir(v.resolve("/app"))]]
print("  FOTA entries removed: %d/%d" % (len(gone), len(REMOVE)))
# byte-compare one installed binary against its source
bb = v.read_file(v.read_inode(
    [c for n, c, _, _, _, _ in v.listdir(ino) if n == "busybox"][0]))
src = open(os.path.join(T, "busybox-armv7l"), "rb").read()
print("  busybox reads back identical: %s" % (bb == src))
v.close()
print()
print("wrote %s" % DST)

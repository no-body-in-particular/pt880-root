#!/usr/bin/env python3
"""Install sshfs, htop, nano and their library closure into system_mod.img.

Everything here is Alpine armhf, built against musl, so each binary needs the
musl loader rather than Android's. The loader cannot be reached via PT_INTERP
(/lib/... is read-only rootfs), so each real binary is installed with a .bin
suffix and a small wrapper of the same name invokes:

    ld-musl-armhf.so.1 --library-path /system/xbin <real binary> "$@"

Libraries live flat in /system/xbin rather than /system/lib, so musl's
libraries can never be confused with Android's by the platform linker.

Terminfo goes to /system/etc/terminfo/<letter>/<name>, which is why the ext4
tool needed mkdir().
"""
import os
import struct
import sys

from ext4mod import Ext4RW
from ext4tool import Ext4

import paths

IMG = paths.w2("system_mod.img")
A = paths.arm("alpine")

LIBS = [
    ("libfuse3.so.3", "x_fuse3-libs/usr/lib/libfuse3.so.3.16.2"),
    ("libglib-2.0.so.0", "x_glib/usr/lib/libglib-2.0.so.0.7800.6"),
    ("libncursesw.so.6", "x_nc_v3.17/usr/lib/libncursesw.so.6.3"),
    ("libintl.so.8", "x_libintl/usr/lib/libintl.so.8.4.0"),
    ("libffi.so.8", "x_libffi/usr/lib/libffi.so.8.1.2"),
    ("libpcre2-8.so.0", "x_pcre2/usr/lib/libpcre2-8.so.0.11.2"),
]

BINS = [
    ("sshfs", "x_sshfs/usr/bin/sshfs", True),
    ("htop", "x_htop/usr/bin/htop", True),
    ("nano", "x_nano/usr/bin/nano", True),
    ("fusermount3", "x_fuse3/usr/bin/fusermount3", False),  # needs only musl
]

WRAPPER = """#!/system/bin/sh
X=/system/xbin
exec $X/ld-musl-armhf.so.1 --library-path $X $X/%s.bin "$@"
"""

TERMS = ["vt100", "vt102", "vt220", "xterm", "xterm-256color", "linux", "screen",
         "ansi", "dumb"]


def put(fs, d, name, data, mode=0o100755):
    try:
        fs.rm(d, name)
    except FileNotFoundError:
        pass
    return fs.add(d, name, data, mode)


def main():
    fs = Ext4RW(IMG)

    print("=== libraries -> /xbin ===")
    for name, rel in LIBS:
        p = os.path.join(A, rel.replace("/", os.sep))
        if not os.path.isfile(p):
            sys.exit("missing " + p)
        data = open(p, "rb").read()
        assert data[:4] == b"\x7fELF" and data[4] == 1 and \
            struct.unpack("<H", data[18:20])[0] == 40, name
        ino, nb = put(fs, "/xbin", name, data, 0o100644)
        print("  %-22s %8d  ino=%-6d %d blocks" % (name, len(data), ino, nb))

    print()
    print("=== binaries -> /xbin ===")
    for name, rel, wrap in BINS:
        p = os.path.join(A, rel.replace("/", os.sep))
        data = open(p, "rb").read()
        assert data[:4] == b"\x7fELF" and data[4] == 1
        if wrap:
            ino, nb = put(fs, "/xbin", name + ".bin", data)
            print("  %-22s %8d  ino=%-6d %d blocks" % (name + ".bin", len(data), ino, nb))
            w = (WRAPPER % name).encode()
            ino, nb = put(fs, "/xbin", name, w)
            print("  %-22s %8d  (wrapper)" % (name, len(w)))
        else:
            # fusermount3 must be setuid root for non-root mounts; we are root
            # anyway, but set it so it behaves as upstream expects.
            ino, nb = put(fs, "/xbin", name, data, 0o104755)
            print("  %-22s %8d  ino=%-6d mode=4755" % (name, len(data), ino))

    print()
    print("=== /etc/nanorc ===")
    nrc = os.path.join(A, 'x_nano', 'etc', 'nanorc')
    if os.path.isfile(nrc):
        i, _ = put(fs, '/etc', 'nanorc', open(nrc, 'rb').read(), 0o100644)
        print('  /etc/nanorc %d bytes ino=%d' % (os.path.getsize(nrc), i))

    print()
    print("=== terminfo -> /etc/terminfo ===")
    try:
        fs.mkdir("/etc", "terminfo")
        print("  created /etc/terminfo")
    except FileExistsError:
        print("  /etc/terminfo exists")
    src = os.path.join(A, "x_ncurses-terminfo-base", "etc", "terminfo")
    made = set()
    n = 0
    for t in TERMS:
        letter = t[0]
        sp = os.path.join(src, letter, t)
        if not os.path.isfile(sp):
            continue
        if letter not in made:
            try:
                fs.mkdir("/etc/terminfo", letter)
            except FileExistsError:
                pass
            made.add(letter)
        put(fs, "/etc/terminfo/" + letter, t, open(sp, "rb").read(), 0o100644)
        n += 1
    print("  installed %d terminfo entries in %d subdirs" % (n, len(made)))
    fs.close()

    # ---- verify ----
    v = Ext4(IMG)
    print()
    print("=== /xbin after install ===")
    for nm, ch, ft, _, _, _ in sorted(v.listdir(v.resolve("/xbin"))):
        if nm in (".", ".."):
            continue
        node = v.read_inode(ch)
        sz = struct.unpack("<I", node[0x04:0x08])[0]
        mode = struct.unpack("<H", node[0x00:0x02])[0]
        print("  %-24s %9d  mode=%o" % (nm, sz, mode))
    print()
    for t in ("vt100", "xterm"):
        try:
            v.resolve("/etc/terminfo/%s/%s" % (t[0], t))
            print("  terminfo/%s/%-16s present" % (t[0], t))
        except FileNotFoundError:
            print("  terminfo %s MISSING" % t)
    v.close()


if __name__ == "__main__":
    main()

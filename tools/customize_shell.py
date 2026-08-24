#!/usr/bin/env python3
"""Shell customisation for the modified system image.

Three things:
  1. busybox gets mode 06755 (setuid root) so `busybox su` can actually elevate.
     /system is mounted "ro,relatime" with NO nosuid, so setuid is honoured.
  2. /etc/mkshrc gains a block that puts /system/xbin on PATH, aliases the
     busybox applets the device is missing, turns on colour, and execs
     `busybox su` when the shell is not already root.
  3. TERM defaults to xterm-256color (the stock rc defaults it to vt100, which
     is monochrome and breaks ncurses TUIs like htop).

The existing mkshrc is READ and APPENDED TO, never retyped - its upstream
content and copyright header are preserved byte-for-byte.

The su-exec is guarded twice: it only fires when USER_ID != 0 AND the marker
variable is unset, so it cannot loop (su spawns a shell, which re-reads this
file). In practice adb shell is already uid 0 here because adbd's setuid
wrappers are neutered, so it is a no-op safety net rather than the main path.
"""
import struct
import sys

sys.path.insert(0, r"C:\wpull")
from ext4mod import Ext4RW
from ext4tool import Ext4

IMG = r"C:\wpull\dump_watch2\system_mod.img"

RC_ADD = r"""
# ---- added by sl8521e-root ----
# EVERYTHING below must be pure mksh builtins. The stock image has no printf,
# no id, no coreutils at all - /system/xbin is only reachable once this file
# has already been sourced, so any $(external) here fails at every shell start.
export PATH=/system/xbin:$PATH
: ${TERM:=xterm-256color}
export TERM
# xterm-256color, not vt100: vt100 has no colour and no ACS line-drawing, so
# htop and every other ncurses TUI renders as monochrome soup. The entry is
# installed at /system/etc/terminfo/x/xterm-256color. Only set if the caller
# did not already export a TERM.
# ncurses (htop) looks here for terminal definitions
export TERMINFO=/system/etc/terminfo
export TERMINFO_DIRS=/system/etc/terminfo
# No global LD_LIBRARY_PATH here: the musl wrappers in /system/xbin already
# pass --library-path, and exporting it would also be inherited by every
# bionic binary the shell spawns.

# The stock image has no head/awk/tr/less/find/... - route them to busybox.
if [[ -x /system/xbin/busybox ]]; then
	for _a in head tail awk sed tr find less more wc du df sort uniq \
	          xargs cut nl od stat which whoami hexdump vi top free ps kill; do
		# QUOTE THE WHOLE ASSIGNMENT. Unquoted, alias $_a="busybox $_a" expands
		# to TWO words - "head=/system/xbin/busybox" and "head" - so alias reads
		# the second as a lookup of an undefined alias and errors. That printed
		# ~50 "alias: head not found" lines per shell start and defined nothing.
		alias "$_a=/system/xbin/busybox $_a"
	done
	unset _a
	alias ls='/system/xbin/busybox ls --color=auto'
	alias ll='/system/xbin/busybox ls -l --color=auto'
	alias la='/system/xbin/busybox ls -la --color=auto'
	alias grep='/system/xbin/busybox grep --color=auto'
	alias busybox=/system/xbin/busybox
fi

# vt100 colours.
# CAREFUL: a shell assignment must be ONE word. "VAR=a b c" assigns a and then
# RUNS b with argument c. The first version of this line had spaces between the
# concatenated pieces, so the shell tried to execute the colour code and
# reported: mkshrc[85]: 01;31: not found
export LS_COLORS='di=01;34:ln=01;36:so=01;35:pi=33:ex=01;32:bd=01;33:cd=01;33'
# The prompt embeds literal 0x1b bytes, substituted in at build time by the
# replace() just below this string - so no printf is needed at runtime.
# USER_ID is set by mksh itself and the stock rc above already uses it, so
# no id -u either.
if (( USER_ID )); then _c=32; _p='$ '; else _c=31; _p='# '; fi
PS1="@ESC@[1;${_c}m"'$USER@$HOSTNAME'"@ESC@[0m:@ESC@[1;34m"'${PWD:-?}'"@ESC@[0m$_p"
unset _c _p

# If we somehow land as non-root, become root via busybox su.
# Guarded by the marker so the re-exec cannot recurse.
if (( USER_ID )) && [[ -z $SU_INVOKED && -x /system/xbin/busybox ]]; then
	export SU_INVOKED=1
	exec /system/xbin/busybox su -
fi
"""
RC_ADD = RC_ADD.replace("@ESC@", "\x1b")


def main():
    fs = Ext4RW(IMG)

    # ---- 1. busybox setuid root ----
    ino = fs.resolve("/xbin/busybox")
    node = fs.read_inode(ino)
    old = struct.unpack("<H", node[0x00:0x02])[0]
    new = 0o100000 | 0o6755
    struct.pack_into("<H", node, 0x00, new)
    fs.write_inode(ino, node)
    print("  busybox mode %o -> %o  (setuid+setgid root)" % (old, new))

    # ---- 2. append to /etc/mkshrc ----
    rc_ino = fs.resolve("/etc/mkshrc")
    original = fs.read_file(fs.read_inode(rc_ino))
    print("  /etc/mkshrc original %d bytes (preserved verbatim)" % len(original))
    if b"sl8521e-root" in original:
        print("  already customised - stripping previous block")
        original = original.split(b"\n# ---- added by sl8521e-root ----")[0]
    merged = original.rstrip(b"\n") + b"\n" + RC_ADD.encode("latin-1")
    fs.rm("/etc", "mkshrc")
    ino2, nb = fs.add("/etc", "mkshrc", merged, 0o100644)
    print("  /etc/mkshrc rewritten %d bytes  ino=%d (%d blocks)"
          % (len(merged), ino2, nb))
    # ---- 3. /etc/passwd and /etc/group ----
    # Android ships neither - bionic synthesises IDs internally - so busybox su
    # fails with "unknown user root", and dbclient warned "failed to identify
    # current user". A minimal POSIX passwd fixes both. Android itself ignores
    # these files, so adding them changes nothing else.
    passwd = ("root:x:0:0:root:/data:/system/bin/sh\n"
              "system:x:1000:1000:system:/data:/system/bin/sh\n"
              "shell:x:2000:2000:shell:/data:/system/bin/sh\n")
    group = "root:x:0:\nsystem:x:1000:\nshell:x:2000:\n"
    # Android has NO /etc/resolv.conf - bionic resolves through the net.dns1/
    # net.dns2 system properties instead. Every musl-linked tool we installed
    # (dbclient, sshfs, ...) knows nothing about Android properties and reads
    # /etc/resolv.conf, so DNS failed outright:
    #   dbclient: Error resolving 'host' port '22'. System error
    # Routing was always fine - only the resolver was missing. Public servers
    # are used because the DHCP-assigned ones change per network; once the
    # capbnd boot image lands, /system is remountable and this can be rewritten
    # from `getprop net.dns1` if a network forces its own resolver.
    resolv = ("nameserver 8.8.8.8" + chr(10) +
              "nameserver 1.1.1.1" + chr(10) +
              "nameserver 8.8.4.4" + chr(10) +
              "options timeout:2 attempts:2" + chr(10))
    for nm, text in (("passwd", passwd), ("group", group),
                     ("resolv.conf", resolv)):
        try:
            fs.rm("/etc", nm)
        except FileNotFoundError:
            pass
        i, nb = fs.add("/etc", nm, text.encode(), 0o100644)
        print("  /etc/%-7s %4d bytes  ino=%d" % (nm, len(text), i))
    fs.close()

    # ---- 4. verify ----
    v = Ext4(IMG)
    n = v.read_inode(v.resolve("/xbin/busybox"))
    m = struct.unpack("<H", n[0x00:0x02])[0]
    print()
    print("  verify busybox mode : %o  %s"
          % (m, "setuid OK" if m & 0o4000 else "*** NOT setuid ***"))
    body = v.read_file(v.read_inode(v.resolve("/etc/mkshrc")))
    print("  verify mkshrc bytes : %d" % len(body))
    print("  contains su block   : %s" % (b"SU_INVOKED" in body))
    print("  contains PATH line  : %s" % (b"/system/xbin:$PATH" in body))
    print("  original preserved  : %s" % body.startswith(original[:60]))
    # The added block must run with zero external commands. Scan its CODE
    # only - the word "printf" also appears in its comments, explaining why
    # there is no printf. Comments cost nothing at runtime.
    added = body.split(b"# ---- added by sl8521e-root ----")[-1]
    code = b"".join(l for l in added.splitlines(True)
                    if not l.lstrip().startswith(b"#"))
    print("  TERM default        : %s"
          % ("xterm-256color" if b"xterm-256color" in body else "*** NOT SET ***"))
    print("  no printf in code   : %s" % (b"printf" not in code))
    print("  no $(...) in code   : %s" % (b"$(" not in code))
    print("  no id -u in code    : %s" % (b"id -u" not in code))
    print("  no @ESC@ left       : %s" % (b"@ESC@" not in body))
    print("  literal ESC bytes   : %d" % body.count(bytes([27])))
    for nm in ("resolv.conf", "hosts"):
        try:
            t = v.read_file(v.read_inode(v.resolve("/etc/" + nm)))
            print("  /etc/%-11s   : %d bytes  %r" % (nm, len(t), t[:40]))
        except Exception:
            print("  /etc/%-11s   : *** MISSING ***" % nm)
    v.close()


if __name__ == "__main__":
    main()

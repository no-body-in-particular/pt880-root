#!/usr/bin/env python3
"""Install a modern certificate authority store into system_mod.img.

Android 4.4's trust store was assembled in 2013 and contains no ISRG root, so a
Let's Encrypt chain -- which is most of the web now -- fails to validate. Old
Androids used to survive on Let's Encrypt's cross-signature from DST Root CA
X3, which they accepted despite its expiry; that cross-sign is gone. The
failure is silent and total: every https request on the device simply reports
itself as unreachable, which is a very hard thing to diagnose from an app.

The bundle in system/etc/security/cacerts.pem is the Mozilla CA list, which is
where Android's own store comes from.

Two details decide whether this works at all:

  * Android names each file by the OpenSSL **old** subject hash -- the 0.9.8
    algorithm, `openssl x509 -subject_hash_old`. A certificate installed under
    the modern hash is never consulted, and that failure looks exactly like
    success.
  * Only the PEM is parsed. Android's own files carry a text dump of the
    certificate after it, which is for humans and would treble the size of a
    store going onto a small /system.

Certificates already present are left alone rather than replaced. This adds
roots the device is too old to know about; it does not curate what it already
trusts, because removing a root something on the watch depends on is a much
worse outcome than carrying one that is out of favour.

    python tools/install_cacerts.py
"""
import hashlib
import os
import subprocess
import sys

from ext4mod import Ext4RW
from ext4tool import Ext4

import paths

IMG = paths.w2("system_mod.img")
BUNDLE = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                      "system", "etc", "security", "cacerts.pem")
STORE = "/etc/security/cacerts"


def split_pem(text):
    """The bundle into individual PEM certificates."""
    out = []
    cur = []
    for line in text.splitlines(True):
        if line.startswith("-----BEGIN CERTIFICATE"):
            cur = [line]
        elif line.startswith("-----END CERTIFICATE"):
            cur.append(line)
            out.append("".join(cur))
            cur = []
        elif cur:
            cur.append(line)
    return out


def subject_hash_old(pem):
    """OpenSSL's pre-1.0 subject hash, which is what Android's filenames use.

    Shelled out rather than reimplemented: it is an MD5 over the DER of the
    canonicalised subject, and getting the canonicalisation subtly wrong would
    produce plausible filenames that are never read.
    """
    p = subprocess.run(["openssl", "x509", "-noout", "-subject_hash_old"],
                       input=pem.encode(), capture_output=True)
    if p.returncode != 0:
        return None
    return p.stdout.decode().strip()


def subject_cn(pem):
    p = subprocess.run(["openssl", "x509", "-noout", "-subject"],
                       input=pem.encode(), capture_output=True)
    if p.returncode != 0:
        return "?"
    s = p.stdout.decode().strip()
    return s.split("CN")[-1].lstrip(" =") if "CN" in s else s


def main():
    if not os.path.isfile(BUNDLE):
        sys.exit("missing " + BUNDLE)
    if not os.path.isfile(IMG):
        sys.exit("missing " + IMG + " -- build it with scripts/build.sh first")

    certs = split_pem(open(BUNDLE).read())
    print("bundle: %d certificates" % len(certs))
    if len(certs) < 50:
        sys.exit("that is too few to be the real bundle")

    fs = Ext4RW(IMG)
    ro = Ext4(IMG)

    existing = set()
    try:
        for name, _ino, _ft in ro.listdir(STORE):
            existing.add(name)
    except Exception:
        sys.exit("no %s in the image -- is this the right system.img?" % STORE)
    print("already in the image: %d" % len(existing))

    added = skipped = 0
    for pem in certs:
        h = subject_hash_old(pem)
        if h is None:
            continue
        # Collisions get the next index, as Android itself does.
        i = 0
        while ("%s.%d" % (h, i)) in existing:
            i += 1
        name = "%s.%d" % (h, i)
        if i > 0 and ("%s.0" % h) in existing:
            # Same subject hash and already present: almost certainly the same
            # CA, so leave the device's own copy in place.
            skipped += 1
            continue
        fs.add(STORE, name, pem.encode(), mode=0o100644)
        existing.add(name)
        added += 1
        print("  + %-12s %s" % (name, subject_cn(pem)[:44]))

    print()
    print("added %d, left %d alone, store now %d" % (added, skipped, len(existing)))


if __name__ == "__main__":
    main()

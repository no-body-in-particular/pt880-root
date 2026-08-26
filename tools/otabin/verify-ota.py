#!/usr/bin/env python3
"""Check a whole-file OTA signature the way the device's recovery does.

Mirrors bootable/recovery/verifier.cpp: read the six byte footer, find the end-of-central
directory record from the comment size, take the signature out of the comment, and verify it
over everything in front of it. Its own error strings name each of these steps -
"signature is too short", "signature length doesn't match EOCD marker",
"failed to verify whole-file signature".

  verify-ota.py <signed.zip> <cert.x509.pem>
"""
import struct
import subprocess
import sys
import tempfile

EOCD_MAGIC = b"PK\x05\x06"


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)

    path, certfile = sys.argv[1], sys.argv[2]
    blob = open(path, "rb").read()
    n = len(blob)
    ok = True

    if n < 6:
        raise SystemExit("too small")

    footer = blob[-6:]
    sig_start = footer[0] | (footer[1] << 8)
    comment_size = footer[4] | (footer[5] << 8)
    print(f"  comment is {comment_size} bytes; signature {sig_start} bytes from end")

    if footer[2] != 0xFF or footer[3] != 0xFF:
        print("  FAIL: footer marker is not ff ff")
        ok = False

    eocd_size = comment_size + 22
    eocd = n - eocd_size

    if blob[eocd:eocd + 4] != EOCD_MAGIC:
        print("  FAIL: no end-of-central-directory where the comment size says")
        ok = False
    else:
        print(f"  EOCD found at {eocd}")

    stated = struct.unpack_from("<H", blob, eocd + 20)[0]

    if stated != comment_size:
        print(f"  FAIL: signature length doesn't match EOCD marker ({stated} vs {comment_size})")
        ok = False

    if sig_start <= 6 or sig_start > comment_size:
        print("  FAIL: signature is too short")
        ok = False

    signed_len = n - sig_start
    signature = blob[signed_len:n - 6]
    print(f"  signed range 0..{signed_len}, signature {len(signature)} bytes")

    with tempfile.NamedTemporaryFile() as content, tempfile.NamedTemporaryFile() as sig:
        content.write(blob[:signed_len])
        content.flush()
        sig.write(signature)
        sig.flush()
        r = subprocess.run(
            ["openssl", "cms", "-verify", "-binary", "-inform", "DER", "-in", sig.name,
             "-content", content.name, "-certfile", certfile,
             # the signer certificate is the trust anchor here, exactly as it is on the
             # device, so chain building is neither wanted nor possible
             "-noverify", "-out", "/dev/null"],
            capture_output=True)

    if r.returncode == 0:
        print("  whole-file signature verified against the certificate")
    else:
        print("  FAIL: failed to verify whole-file signature")
        print("       " + r.stderr.decode().strip().splitlines()[-1] if r.stderr else "")
        ok = False

    print("  RESULT:", "would be accepted" if ok else "would be REJECTED")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())

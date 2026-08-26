#!/usr/bin/env python3
"""Apply an Android whole-file OTA signature to a zip.

This is the signature recovery checks, which is not the same thing as an APK signature: the
zip's trailing comment is made to hold a detached PKCS#7 blob covering everything in front of
it, followed by a six byte footer that says where that blob starts.

    [ zip data ][ central directory ][ EOCD, comment length patched ][ PKCS#7 ][ footer ]
                                                                    ^
                                            signature_start counts back to here from the end

    footer = <uint16 signature_start> FF FF <uint16 comment_size>

with signature_start == comment_size == len(signature) + 6, and the signed range running from
byte zero up to the start of the signature - so it takes in the EOCD and its comment length
field, but not the comment itself.

AOSP's signapk does the same job, but the copy in prebuilts needs a Conscrypt native library
that is not always around, and this is short enough to read.

  sign-ota.py <in.zip> <out.zip> <cert.x509.pem> <key.pk8|key.pem>
"""
import struct
import subprocess
import sys
import tempfile


EOCD_MAGIC = b"PK\x05\x06"
EOCD_LEN = 22


def key_as_pem(path):
    """A .pk8 is DER PKCS#8; openssl wants PEM. Returns a path to a PEM copy."""
    data = open(path, "rb").read()

    if b"-----BEGIN" in data:
        return path

    pem = subprocess.run(["openssl", "pkcs8", "-inform", "DER", "-nocrypt"],
                         input=data, capture_output=True, check=True).stdout
    tmp = tempfile.NamedTemporaryFile(suffix=".pem", delete=False)
    tmp.write(pem)
    tmp.close()
    return tmp.name


def find_eocd(blob):
    """Offset of the end-of-central-directory record. The input must have no comment yet."""
    start = max(0, len(blob) - EOCD_LEN)
    if blob[start:start + 4] != EOCD_MAGIC:
        raise SystemExit("input zip has a comment already, or is not a zip")
    return start


def main():
    if len(sys.argv) != 5:
        raise SystemExit(__doc__)

    src, dst, certfile, keyfile = sys.argv[1:5]
    blob = bytearray(open(src, "rb").read())
    eocd = find_eocd(blob)

    keypem = key_as_pem(keyfile)

    # The size of the comment has to be known before the signature is made, because the
    # comment length field sits inside the range the signature covers. So the signature is
    # made once to measure it, the length is written, and it is made again over the final
    # bytes. The second signature is the same size as the first - same key, same digest, same
    # certificate - and that is asserted rather than assumed.
    # SHA1, because this device's recovery only knows that one - its binary carries no
    # SHA256 anywhere, only "failed to alloc memory for sha1 buffer". Detached, no
    # authenticated attributes, no S/MIME capabilities: the blob has to be a bare
    # SignedData over the bytes, which is what the verifier hashes and compares.
    def sign(data):
        with tempfile.NamedTemporaryFile() as f:
            f.write(bytes(data))
            f.flush()
            return subprocess.run(
                ["openssl", "cms", "-sign", "-binary", "-in", f.name,
                 "-signer", certfile, "-inkey", keypem,
                 "-md", "sha1", "-noattr", "-nosmimecap", "-outform", "DER"],
                capture_output=True, check=True).stdout

    probe = sign(blob)
    comment_size = len(probe) + 6
    struct.pack_into("<H", blob, eocd + 20, comment_size)

    signature = sign(blob)
    if len(signature) != len(probe):
        # pad rather than loop: the comment length is already committed to
        signature = signature + b"\x00" * (len(probe) - len(signature))
        if len(signature) != len(probe):
            raise SystemExit("signature size moved between passes")

    footer = struct.pack("<H", comment_size) + b"\xff\xff" + struct.pack("<H", comment_size)
    open(dst, "wb").write(bytes(blob) + signature + footer)
    print(f"signed {dst}: {len(blob) + len(signature) + 6} bytes, "
          f"signature {len(signature)}, comment {comment_size}")


if __name__ == "__main__":
    main()

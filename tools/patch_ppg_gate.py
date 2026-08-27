#!/usr/bin/env python3
"""
Stop the watch refusing to measure a pulse when it thinks it is off the wrist.

    This did not fix the problem it was written for. Read the note below before
    reaching for it.

    ./patch_ppg_gate.py L009_Protocol.odex [-o patched.odex]
    ./patch_ppg_gate.py L009_Protocol.odex --verify        # report only, write nothing

HeartRateManager.triggerPPGTest opens with a check that gives up before it ever
reaches the sensor:

    if (runtime.Anti_off_flag == 1 || runtime.Cut_off_flag == 1) {
        ICLogger.i("未佩戴 or 剪断，取消 triggerPPGtest！");   // "not worn or strap-cut, cancel"
        return;
    }

Two latched flags - taken off the wrist, strap cut - and either one makes every
later measurement a no-op, silently: the watch goes on acknowledging the
server's HEARTRATE# exactly as before and simply never reports a reading. Only a
reboot rebuilds the Runtime and clears them, which is why rebooting looks like
it fixes a sensor that was never broken.

The method begins:

    invoke-static {}, CoreService->getInstance()
    move-result-object v0
    if-eqz v0, :cond_2a          <- no CoreService? skip the flag checks entirely
    ... the two flag tests, and the return ...
    :cond_2a
    ... bind the work service and start the measurement ...

The firmware already has a path that skips the flags - it just reserves it for
the case where CoreService is missing. Making that branch unconditional takes
it every time.

That is one byte. "if-eqz vAA, +BBBB" is format 21t: opcode 0x38, a register
byte, then a 16 bit branch offset. "goto/16 +AAAA" is format 20t: opcode 0x29,
an unused byte that must be zero, then the same 16 bit offset in the same place.
Same length, same target. The register here is v0, so the register byte is
already zero and only the opcode changes.

Nothing else moves, so every other offset in the file stays valid. The dex
checksum and signature are recomputed anyway, because they cover the bytes that
changed and a mismatch is the kind of thing that fails much later and much less
clearly than it should.

The code after :cond_2a does not use the CoreService reference the null check
was guarding - it builds a log line, binds the hardware work service and starts
a thread - so taking the branch unconditionally cannot dereference null.

What it did not do
------------------

It did not stop the readings stalling, and the gate was never the cause. Heart
rate and temperature stop within a minute of each other and come back together,
and temperature does not go through triggerPPGTest at all - so whatever stops
them is downstream of both. It is com.ic.work, which runs one work queue for
both sensors with no timeout on the item at its head.

It may well be a no-op on this hardware. The firmware reports itself as
l009-EU-noAnti-Common-V3.70, and if that build never sets Anti_off_flag then the
branch this rewrites was already being taken every time.

It was also blamed, by me, for leaking threads into the stalled queue - on the
grounds that triggerPPGTest spawns one per call and the patch makes it run more
often. The first half is true. The second is unverified: the thread's body is
entirely unresolved quick opcodes, so what it does is not known from here, and
stock firmware spawns the same thread whenever the gate passes anyway. Treat
that claim as withdrawn.

The patch is correct in what it does. It is kept because the analysis is worth
having and the tooling around it - reading, verifying and restoring a system
odex safely - is reusable. It is not a fix for the stalls.
"""

import argparse
import hashlib
import sys
import zlib

CLASS = "Lcom/enqualcomm/support/service/HeartRateManager;"
METHOD = "triggerPPGTest"

OP_IF_EQZ = 0x38
OP_GOTO_16 = 0x29
OP_INVOKE_STATIC = 0x71
OP_MOVE_RESULT_OBJECT = 0x0C

ODEX_MAGIC = b"dey\n"
DEX_MAGIC = b"dex\n"


def u16(b, o):
    return int.from_bytes(b[o:o + 2], "little")


def u32(b, o):
    return int.from_bytes(b[o:o + 4], "little")


def uleb128(b, o):
    """Returns (value, new_offset)."""
    result = 0
    shift = 0

    while True:
        byte = b[o]
        o += 1
        result |= (byte & 0x7F) << shift

        if not byte & 0x80:
            return result, o

        shift += 7


class Dex:
    """Just enough of the format to walk from a class name to a method's code."""

    def __init__(self, buf, base):
        self.buf = buf
        self.base = base                     # where the dex starts inside the file

        if buf[base:base + 4] != DEX_MAGIC:
            raise ValueError("no dex header at offset %d" % base)

        self.string_ids_size = u32(buf, base + 0x38)
        self.string_ids_off = u32(buf, base + 0x3C)
        self.type_ids_off = u32(buf, base + 0x44)
        self.proto_ids_off = u32(buf, base + 0x4C)
        self.method_ids_off = u32(buf, base + 0x5C)
        self.class_defs_size = u32(buf, base + 0x60)
        self.class_defs_off = u32(buf, base + 0x64)

    def abs(self, off):
        return self.base + off

    def string(self, idx):
        off = u32(self.buf, self.abs(self.string_ids_off) + idx * 4)
        length, o = uleb128(self.buf, self.abs(off))
        # MUTF-8; the names we care about are plain ASCII
        end = self.buf.index(b"\x00", o)
        return self.buf[o:end].decode("utf-8", "replace")

    def type_name(self, idx):
        return self.string(u32(self.buf, self.abs(self.type_ids_off) + idx * 4))

    def method_name(self, idx):
        # method_id_item: class_idx u16, proto_idx u16, name_idx u32
        return self.string(u32(self.buf, self.abs(self.method_ids_off) + idx * 8 + 4))

    def find_method_code(self, class_name, method_name):
        """Absolute file offset of the method's insns, and its size in code units."""
        for i in range(self.class_defs_size):
            cd = self.abs(self.class_defs_off) + i * 32

            if self.type_name(u32(self.buf, cd)) != class_name:
                continue

            data_off = u32(self.buf, cd + 24)

            if not data_off:
                raise ValueError("%s has no class data" % class_name)

            o = self.abs(data_off)
            static_n, o = uleb128(self.buf, o)
            instance_n, o = uleb128(self.buf, o)
            direct_n, o = uleb128(self.buf, o)
            virtual_n, o = uleb128(self.buf, o)

            for _ in range(static_n + instance_n):        # encoded_field: idx_diff, flags
                _, o = uleb128(self.buf, o)
                _, o = uleb128(self.buf, o)

            for count in (direct_n, virtual_n):
                idx = 0

                for _ in range(count):
                    diff, o = uleb128(self.buf, o)
                    idx += diff
                    _, o = uleb128(self.buf, o)           # access_flags
                    code_off, o = uleb128(self.buf, o)

                    if self.method_name(idx) != method_name or not code_off:
                        continue

                    code = self.abs(code_off)
                    insns_size = u32(self.buf, code + 12)
                    return code + 16, insns_size

            raise ValueError("%s not found in %s" % (method_name, class_name))

        raise ValueError("%s not found" % class_name)


def find_gate(buf, insns_off, insns_size):
    """
    The first if-eqz in the method, checked against the shape around it.

    Matching on the opcode alone would be reckless - if-eqz is one of the
    commonest instructions in any method. The three instruction preamble is what
    identifies this one: a static call, its result into v0, then the branch on
    that result.
    """
    if buf[insns_off] != OP_INVOKE_STATIC:
        raise ValueError("method does not open with invoke-static (got 0x%02x)" % buf[insns_off])

    o = insns_off + 6                                     # invoke-static is 3 code units

    if buf[o] != OP_MOVE_RESULT_OBJECT or buf[o + 1] != 0x00:
        raise ValueError("expected move-result-object v0 after the call")

    o += 2

    # goto/16 is accepted as well as if-eqz, because that is what this writes: a
    # file that has already been through here has to be recognised rather than
    # rejected, or running it twice fails and so does reading back an installed
    # file to confirm the patch took.
    if buf[o] not in (OP_IF_EQZ, OP_GOTO_16):
        raise ValueError("expected if-eqz or goto/16 at the third instruction "
                         "(got 0x%02x)" % buf[o])

    if buf[o + 1] != 0x00:
        raise ValueError("branch is on v%d, not v0 - the register byte must be zero "
                         "for goto/16" % buf[o + 1])

    target = int.from_bytes(buf[o + 2:o + 4], "little", signed=True)

    if target <= 0:
        raise ValueError("branch goes backwards (%d) - not the gate" % target)

    if o + target * 2 >= insns_off + insns_size * 2:
        raise ValueError("branch leaves the method")

    return o, target


def reseal(buf, base):
    """
    Recompute the dex signature and checksum over the patched body.

    Both are bounded by the dex's own file_size rather than the end of the file.
    In an odex the dex is only the first section - the dependency and optimised
    tables follow it - and hashing to EOF would fold those in and produce a
    signature that is wrong in a way nothing reports until load time.

    Order matters: the signature covers everything after it, and the checksum
    then covers the signature too, so the signature has to be written first.
    """
    size = u32(buf, base + 32)
    end = base + size

    buf[base + 12:base + 32] = hashlib.sha1(bytes(buf[base + 32:end])).digest()
    buf[base + 8:base + 12] = zlib.adler32(bytes(buf[base + 12:end])).to_bytes(4, "little")


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("odex", help="L009_Protocol.odex from /system/priv-app")
    ap.add_argument("-o", "--out", help="where to write the patched file")
    ap.add_argument("--verify", action="store_true", help="report only, write nothing")
    args = ap.parse_args()

    buf = bytearray(open(args.odex, "rb").read())

    if buf[:4] == ODEX_MAGIC:
        base = u32(buf, 8)
        print("    odex container, dex at 0x%x" % base)

    elif buf[:4] == DEX_MAGIC:
        base = 0
        print("    bare dex")

    else:
        sys.exit("    not a dex or odex: %r" % bytes(buf[:8]))

    dex = Dex(buf, base)
    insns_off, insns_size = dex.find_method_code(CLASS, METHOD)
    print("    %s%s: insns at 0x%x, %d code units" % (CLASS, METHOD, insns_off, insns_size))

    off, target = find_gate(buf, insns_off, insns_size)
    print("    gate branch at 0x%x: %s +%d" %
          (off, "if-eqz v0" if buf[off] == OP_IF_EQZ else "goto/16", target))

    if buf[off] == OP_GOTO_16:
        print("    already patched - nothing to do")
        return

    if args.verify:
        print("    verify only: would change 0x%x from 0x38 (if-eqz v0) to 0x29 (goto/16)" % off)
        return

    buf[off] = OP_GOTO_16
    reseal(buf, base)

    out = args.out or (args.odex + ".patched")
    open(out, "wb").write(buf)
    print("    wrote %s" % out)
    print("    one byte changed at 0x%x; dex signature and checksum resealed" % off)


if __name__ == "__main__":
    main()

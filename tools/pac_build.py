#!/usr/bin/env python3
"""Build a Spreadtrum PAC container from loose partition images.

Format reverse-engineered from the Wonlex KT24S PAC (same board,
sl8521e_1h10ll_watch) and verified field-by-field against unpac's output:

  header, 0x84c bytes
    0x0000 version[24]  UTF-16LE  "BP_R1.0.0"
    0x0030 pac_size     u32       total file size
    0x0034 product[256] UTF-16LE
    0x0234 fw_name[256] UTF-16LE
    0x0434 file_count   u32
    0x0438 file_offset  u32       = 0x84c
    0x043c mode, flash_type, nand_strategy, is_nv_backup, nand_page_type
    0x0450 alias[100]   UTF-16LE
    0x0518 oma_dm_flag, is_omadm, is_preload
    0x0524 reserved[200] u32
    0x0844 magic        u32       0xfffafffa
    0x0848 crc1 u16, crc2 u16

  entry, 2580 bytes each, immediately after the header
    0x0000 length u32 = 2580
    0x0004 file_id[256]   UTF-16LE
    0x0204 file_name[256] UTF-16LE
    0x0404 file_path[256] UTF-16LE   (empty in the reference PAC)
    0x0604 file_size   u32
    0x0608 type        u32   0x101 = FDL, 0x001 = image, 0x002 = xml
    0x060c check_flag  u32   1
    0x0610 data_offset u32
    0x0614 can_omit    u32
    0x0618 addr_num    u32
    0x061c addr[5]     u32   load address for FDLs, 0 for images

  crc1 = CRC-16/ARC over 0x000..0x848
  crc2 = CRC-16/ARC over 0x84c..EOF
"""
import os
import struct

HDR_LEN = 0x84c
ENTRY_LEN = 2580
MAGIC = 0xfffafffa

TYPE_FDL = 0x101
TYPE_IMAGE = 0x001
TYPE_XML = 0x002

_TBL = []
for _i in range(256):
    _c = _i
    for _ in range(8):
        _c = (_c >> 1) ^ 0xA001 if _c & 1 else _c >> 1
    _TBL.append(_c)


def crc_arc(data, crc=0):
    for b in data:
        crc = (crc >> 8) ^ _TBL[(crc ^ b) & 0xFF]
    return crc


def _u16(s, nchars):
    b = s.encode("utf-16-le")
    return b.ljust(nchars * 2, b"\x00")[:nchars * 2]


def build(out_path, product, fw_name, entries, alias=None,
          flash_type=1, is_nv_backup=1):
    """entries: list of dicts {id, name, path, type, addr}"""
    alias = alias if alias is not None else product

    data_off = HDR_LEN + ENTRY_LEN * len(entries)
    placed = []
    for e in entries:
        sz = os.path.getsize(e["path"])
        placed.append((e, sz, data_off))
        data_off += sz
    total = data_off

    hdr = bytearray(HDR_LEN)
    hdr[0x0000:0x0030] = _u16("BP_R1.0.0", 24)
    struct.pack_into("<I", hdr, 0x0030, total)
    hdr[0x0034:0x0234] = _u16(product, 256)
    hdr[0x0234:0x0434] = _u16(fw_name, 256)
    struct.pack_into("<II", hdr, 0x0434, len(entries), HDR_LEN)
    struct.pack_into("<5I", hdr, 0x043c, 0, flash_type, 0, is_nv_backup, 0)
    hdr[0x0450:0x0518] = _u16(alias, 100)
    struct.pack_into("<3I", hdr, 0x0518, 0, 0, 0)
    struct.pack_into("<I", hdr, 0x0844, MAGIC)

    tbl = bytearray()
    for e, sz, off in placed:
        b = bytearray(ENTRY_LEN)
        struct.pack_into("<I", b, 0x0000, ENTRY_LEN)
        b[0x0004:0x0204] = _u16(e["id"], 256)
        b[0x0204:0x0404] = _u16(e["name"], 256)
        b[0x0404:0x0604] = _u16(e.get("fpath", ""), 256)
        struct.pack_into("<I", b, 0x0604, sz)
        struct.pack_into("<I", b, 0x0608, e["type"])
        struct.pack_into("<I", b, 0x060c, 1)
        struct.pack_into("<I", b, 0x0610, off)
        struct.pack_into("<I", b, 0x0614, e.get("can_omit", 0))
        struct.pack_into("<I", b, 0x0618, 1 if e.get("addr") else 0)
        struct.pack_into("<I", b, 0x061c, e.get("addr", 0))
        tbl += b

    # crc2 covers everything after the header, so stream it while writing
    with open(out_path, "wb") as fo:
        fo.write(bytes(hdr))          # rewritten below with real CRCs
        fo.write(bytes(tbl))
        crc2 = crc_arc(bytes(tbl))
        for e, sz, off in placed:
            with open(e["path"], "rb") as fi:
                while True:
                    chunk = fi.read(1 << 20)
                    if not chunk:
                        break
                    fo.write(chunk)
                    crc2 = crc_arc(chunk, crc2)

    struct.pack_into("<H", hdr, 0x084a, crc2)
    crc1 = crc_arc(bytes(hdr[:0x848]))
    struct.pack_into("<H", hdr, 0x0848, crc1)
    with open(out_path, "r+b") as fo:
        fo.seek(0)
        fo.write(bytes(hdr))

    return {"size": total, "count": len(entries), "crc1": crc1, "crc2": crc2}

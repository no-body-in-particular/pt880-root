#!/usr/bin/env python3
"""Minimal offline ext4 reader/writer for the watch's system.img.

Scope is deliberately narrow: list a directory, add a regular file, remove a
regular file. That is all we need to install binaries into /system/xbin and
drop the FOTA apks, without mounting anything.

The image is favourable for this:
    feature_incompat  = filetype | extents
    feature_ro_compat = sparse_super | large_file | gdt_csum
    NO metadata_csum, NO 64bit, NO flex_bg, block size 4096, inode size 256

So the only checksums to maintain are the crc16 group-descriptor checksums
(gdt_csum). Inodes, bitmaps and directory blocks carry none.

Paths here are relative to the image root, i.e. /system/xbin on the device is
"/xbin" in this image.
"""
import struct
import sys

import paths

SB_OFF = 1024
ROOT_INO = 2

EXT4_FT_REG = 1
EXT4_FT_DIR = 2

# crc16 as used by ext4 gdt_csum (the "crc16" in linux/lib/crc16.c)
_CRC16_TAB = []
for _i in range(256):
    _c = _i
    for _ in range(8):
        _c = (_c >> 1) ^ 0xA001 if _c & 1 else _c >> 1
    _CRC16_TAB.append(_c)


def crc16(crc, data):
    for b in data:
        crc = (crc >> 8) ^ _CRC16_TAB[(crc ^ b) & 0xFF]
    return crc


class Ext4:
    def __init__(self, path, writable=False):
        self.f = open(path, "r+b" if writable else "rb")
        self.f.seek(SB_OFF)
        sb = self.f.read(1024)
        self.sb = sb
        u32 = lambda o: struct.unpack("<I", sb[o:o + 4])[0]
        u16 = lambda o: struct.unpack("<H", sb[o:o + 2])[0]
        self.inodes_count = u32(0x00)
        self.blocks_count = u32(0x04)
        self.free_blocks = u32(0x0C)
        self.free_inodes = u32(0x10)
        self.first_data_block = u32(0x14)
        self.block_size = 1024 << u32(0x18)
        self.blocks_per_group = u32(0x20)
        self.inodes_per_group = u32(0x28)
        self.inode_size = u16(0x58)
        self.uuid = sb[0x68:0x78]
        self.desc_size = 32
        self.groups = (self.blocks_count - self.first_data_block +
                       self.blocks_per_group - 1) // self.blocks_per_group
        gd_block = self.first_data_block + 1
        self.gd_off = gd_block * self.block_size

    # ---- raw block IO ----
    def rd(self, blk, n=1):
        self.f.seek(blk * self.block_size)
        return self.f.read(self.block_size * n)

    def wr(self, blk, data):
        self.f.seek(blk * self.block_size)
        self.f.write(data)

    # ---- group descriptors ----
    def gd(self, g):
        self.f.seek(self.gd_off + g * self.desc_size)
        return bytearray(self.f.read(self.desc_size))

    def put_gd(self, g, d):
        """Write a group descriptor, recomputing its gdt_csum.

        Mirrors ext4_group_desc_csum() in fs/ext4/super.c: the checksum covers
        the UUID, the group number, the descriptor bytes BEFORE bg_checksum,
        and the bytes AFTER it - the 2 checksum bytes are SKIPPED, not zeroed
        and included. Getting this wrong produces values that disagree with a
        stock image on every group; verified against the untouched dump, this
        version reproduces all four stored checksums exactly.
        """
        d = bytearray(d)
        c = crc16(0xFFFF, self.uuid)
        c = crc16(c, struct.pack("<I", g))
        c = crc16(c, bytes(d[0:0x1E]))
        if len(d) > 0x20:
            c = crc16(c, bytes(d[0x20:]))
        struct.pack_into("<H", d, 0x1E, c & 0xFFFF)
        self.f.seek(self.gd_off + g * self.desc_size)
        self.f.write(bytes(d))

    def gd_field(self, g, off, size=4):
        d = self.gd(g)
        return (struct.unpack("<I", d[off:off + 4])[0] if size == 4
                else struct.unpack("<H", d[off:off + 2])[0])

    # ---- inodes ----
    def inode_pos(self, ino):
        g = (ino - 1) // self.inodes_per_group
        idx = (ino - 1) % self.inodes_per_group
        tbl = self.gd_field(g, 0x08)
        return tbl * self.block_size + idx * self.inode_size

    def read_inode(self, ino):
        self.f.seek(self.inode_pos(ino))
        return bytearray(self.f.read(self.inode_size))

    def write_inode(self, ino, data):
        self.f.seek(self.inode_pos(ino))
        self.f.write(bytes(data))

    # ---- extents ----
    def inode_blocks(self, inode):
        """Return the list of data blocks for an extent-mapped inode."""
        i_block = inode[0x28:0x28 + 60]
        if struct.unpack("<H", i_block[0:2])[0] != 0xF30A:
            raise NotImplementedError("not an extent inode")
        depth = struct.unpack("<H", i_block[6:8])[0]
        entries = struct.unpack("<H", i_block[2:4])[0]
        if depth != 0:
            raise NotImplementedError("extent depth > 0 not supported")
        out = []
        for k in range(entries):
            o = 12 + k * 12
            ee_len = struct.unpack("<H", i_block[o + 4:o + 6])[0]
            lo = struct.unpack("<I", i_block[o + 8:o + 12])[0]
            hi = struct.unpack("<H", i_block[o + 6:o + 8])[0]
            start = lo | (hi << 32)
            out.extend(range(start, start + ee_len))
        return out

    def read_file(self, inode):
        size = struct.unpack("<I", inode[0x04:0x08])[0]
        data = b"".join(self.rd(b) for b in self.inode_blocks(inode))
        return data[:size]

    # ---- directories ----
    def listdir(self, ino):
        inode = self.read_inode(ino)
        out = []
        for blk in self.inode_blocks(inode):
            buf = self.rd(blk)
            off = 0
            while off < len(buf) - 8:
                child, rec, nlen, ftype = struct.unpack("<IHBB", buf[off:off + 8])
                if rec < 8:
                    break
                name = buf[off + 8:off + 8 + nlen].decode("latin-1")
                if child:
                    out.append((name, child, ftype, blk, off, rec))
                off += rec
        return out

    def resolve(self, path):
        ino = ROOT_INO
        for part in [p for p in path.strip("/").split("/") if p]:
            hit = None
            for name, child, ftype, _, _, _ in self.listdir(ino):
                if name == part:
                    hit = child
                    break
            if hit is None:
                raise FileNotFoundError(path)
            ino = hit
        return ino

    def close(self):
        self.f.close()


if __name__ == "__main__":
    img = sys.argv[1] if len(sys.argv) > 1 else \
        paths.w2("system.img")
    fs = Ext4(img)
    print("block size %d, %d blocks, %d free, %d groups"
          % (fs.block_size, fs.blocks_count, fs.free_blocks, fs.groups))
    for d in ("/", "/xbin", "/bin", "/app"):
        try:
            ino = fs.resolve(d)
        except FileNotFoundError:
            print("\n%s : not present" % d)
            continue
        ents = fs.listdir(ino)
        print("\n%s  (inode %d, %d entries)" % (d, ino, len(ents)))
        for name, child, ftype, _, _, _ in ents[:14]:
            if name in (".", ".."):
                continue
            node = fs.read_inode(child)
            sz = struct.unpack("<I", node[0x04:0x08])[0]
            print("    %-34s ino=%-6d %9d  ft=%d" % (name, child, sz, ftype))
    fs.close()

#!/usr/bin/env python3
"""Add and remove regular files in the watch's system.img, offline.

Builds on ext4tool.Ext4. Safe here only because the filesystem has NO
metadata_csum - the sole checksums are the crc16 group-descriptor ones, which
put_gd() recomputes. Do not reuse this on a metadata_csum filesystem.

Operations are deliberately minimal:
  rm(dirpath, name)              unlink a regular file, free its blocks+inode
  add(dirpath, name, data, mode) create a regular file with an extent tree

Always run against a COPY; the pristine dump stays untouched.
"""
import struct
import sys
import time

sys.path.insert(0, r"C:\wpull")
from ext4tool import Ext4, ROOT_INO, EXT4_FT_REG

EXT4_EXTENTS_FL = 0x80000


class Ext4RW(Ext4):
    def __init__(self, path):
        super().__init__(path, writable=True)

    # ---------- bitmaps ----------
    def _bm_get(self, blk, idx):
        self.f.seek(blk * self.block_size + idx // 8)
        return (self.f.read(1)[0] >> (idx % 8)) & 1

    def _bm_set(self, blk, idx, val):
        pos = blk * self.block_size + idx // 8
        self.f.seek(pos)
        b = self.f.read(1)[0]
        b = (b | (1 << (idx % 8))) if val else (b & ~(1 << (idx % 8)))
        self.f.seek(pos)
        self.f.write(bytes([b]))

    def _free_runs(self, g):
        """Yield (start_block, length) for every free run in group g."""
        bmp = self.gd_field(g, 0x00)
        base = self.first_data_block + g * self.blocks_per_group
        end = min(self.blocks_per_group, self.blocks_count - base)
        self.f.seek(bmp * self.block_size)
        bits = self.f.read(self.block_size)
        start = None
        for i in range(end):
            free = not ((bits[i // 8] >> (i % 8)) & 1)
            if free and start is None:
                start = i
            elif not free and start is not None:
                yield base + start, i - start
                start = None
        if start is not None:
            yield base + start, end - start

    def alloc_blocks(self, n):
        """Find n free blocks, strongly preferring ONE contiguous run.

        An inode holds at most 4 inline extents, so an allocation split across
        5+ runs cannot be represented and add() rejects it. The original scan
        appended every free run it walked past, so it happily returned scattered
        blocks even when a single big run was available - which is how
        reinstalling a 330 KB binary into an image whose free space had been
        chopped up by earlier rm/add cycles hit "file too fragmented (5 runs,
        max 4)". Try for one run first; only fall back to the greedy scan (and
        the 4-run ceiling) when no single run is large enough.
        """
        for g in range(self.groups):
            for start, ln in self._free_runs(g):
                if ln >= n:
                    return self._claim(list(range(start, start + n)))

        got = []
        for g in range(self.groups):
            if len(got) >= n:
                break
            bmp = self.gd_field(g, 0x00)
            base = self.first_data_block + g * self.blocks_per_group
            end = min(self.blocks_per_group,
                      self.blocks_count - base)
            self.f.seek(bmp * self.block_size)
            bits = bytearray(self.f.read(self.block_size))
            run = []
            for i in range(end):
                if len(got) + len(run) >= n:
                    break
                if not ((bits[i // 8] >> (i % 8)) & 1):
                    run.append(base + i)
                elif run:
                    got.extend(run); run = []
            if run:
                got.extend(run)
        if len(got) < n:
            raise RuntimeError("not enough free blocks (%d/%d)" % (len(got), n))
        return self._claim(got[:n])

    def _claim(self, got):
        """Mark blocks used and update the group / superblock counters."""
        per_group = {}
        for b in got:
            g = (b - self.first_data_block) // self.blocks_per_group
            per_group.setdefault(g, []).append(b)
        for g, blks in per_group.items():
            bmp = self.gd_field(g, 0x00)
            for b in blks:
                self._bm_set(bmp, (b - self.first_data_block) % self.blocks_per_group, 1)
            d = self.gd(g)
            free = struct.unpack("<H", d[0x0C:0x0E])[0] - len(blks)
            struct.pack_into("<H", d, 0x0C, free)
            self.put_gd(g, d)
        self._sb_add_free_blocks(-len(got))
        return got

    def free_blocks_list(self, blks):
        per_group = {}
        for b in blks:
            g = (b - self.first_data_block) // self.blocks_per_group
            per_group.setdefault(g, []).append(b)
        for g, bl in per_group.items():
            bmp = self.gd_field(g, 0x00)
            for b in bl:
                self._bm_set(bmp, (b - self.first_data_block) % self.blocks_per_group, 0)
            d = self.gd(g)
            struct.pack_into("<H", d, 0x0C,
                             struct.unpack("<H", d[0x0C:0x0E])[0] + len(bl))
            self.put_gd(g, d)
        self._sb_add_free_blocks(len(blks))

    def alloc_inode(self):
        for g in range(self.groups):
            d = self.gd(g)
            if struct.unpack("<H", d[0x0E:0x10])[0] == 0:
                continue
            bmp = struct.unpack("<I", d[0x04:0x08])[0]
            self.f.seek(bmp * self.block_size)
            bits = bytearray(self.f.read(self.block_size))
            for i in range(self.inodes_per_group):
                if not ((bits[i // 8] >> (i % 8)) & 1):
                    ino = g * self.inodes_per_group + i + 1
                    if ino < 11:
                        continue
                    self._bm_set(bmp, i, 1)
                    struct.pack_into("<H", d, 0x0E,
                                     struct.unpack("<H", d[0x0E:0x10])[0] - 1)
                    struct.pack_into("<H", d, 0x1C, 0)   # itable_unused: rescan
                    self.put_gd(g, d)
                    self._sb_add_free_inodes(-1)
                    return ino
        raise RuntimeError("no free inodes")

    def free_inode(self, ino):
        g = (ino - 1) // self.inodes_per_group
        i = (ino - 1) % self.inodes_per_group
        d = self.gd(g)
        bmp = struct.unpack("<I", d[0x04:0x08])[0]
        self._bm_set(bmp, i, 0)
        struct.pack_into("<H", d, 0x0E,
                         struct.unpack("<H", d[0x0E:0x10])[0] + 1)
        self.put_gd(g, d)
        self._sb_add_free_inodes(1)
        self.write_inode(ino, bytearray(self.inode_size))

    def _sb_add_free_blocks(self, delta):
        self.f.seek(SB := 1024 + 0x0C)
        cur = struct.unpack("<I", self.f.read(4))[0]
        self.f.seek(SB)
        self.f.write(struct.pack("<I", cur + delta))

    def _sb_add_free_inodes(self, delta):
        self.f.seek(SB := 1024 + 0x10)
        cur = struct.unpack("<I", self.f.read(4))[0]
        self.f.seek(SB)
        self.f.write(struct.pack("<I", cur + delta))

    # ---------- directory entries ----------
    @staticmethod
    def _dlen(nlen):
        return (8 + nlen + 3) & ~3

    def add_dirent(self, dir_ino, name, child_ino, ftype):
        nb = name.encode("latin-1")
        need = self._dlen(len(nb))
        inode = self.read_inode(dir_ino)
        for blk in self.inode_blocks(inode):
            buf = bytearray(self.rd(blk))
            off = 0
            while off < len(buf) - 8:
                ino_, rec, nlen, ft = struct.unpack("<IHBB", buf[off:off + 8])
                used = self._dlen(nlen) if ino_ else 0
                if rec - used >= need:
                    if ino_:
                        struct.pack_into("<H", buf, off + 4, used)
                        noff = off + used
                        nrec = rec - used
                    else:
                        noff = off
                        nrec = rec
                    struct.pack_into("<IHBB", buf, noff, child_ino, nrec,
                                     len(nb), ftype)
                    buf[noff + 8:noff + 8 + len(nb)] = nb
                    self.wr(blk, bytes(buf))
                    return
                off += rec
        raise RuntimeError("no room in directory for %r" % name)

    def rm_dirent(self, dir_ino, name):
        inode = self.read_inode(dir_ino)
        for blk in self.inode_blocks(inode):
            buf = bytearray(self.rd(blk))
            off = 0
            prev = None
            while off < len(buf) - 8:
                ino_, rec, nlen, ft = struct.unpack("<IHBB", buf[off:off + 8])
                if rec < 8:
                    break
                nm = buf[off + 8:off + 8 + nlen].decode("latin-1")
                if ino_ and nm == name:
                    if prev is None:
                        struct.pack_into("<I", buf, off, 0)
                    else:
                        prec = struct.unpack("<H", buf[prev + 4:prev + 6])[0]
                        struct.pack_into("<H", buf, prev + 4, prec + rec)
                    self.wr(blk, bytes(buf))
                    return ino_
                prev = off
                off += rec
        raise FileNotFoundError(name)

    # ---------- high level ----------
    def rm(self, dirpath, name):
        dino = self.resolve(dirpath)
        target = None
        for nm, child, ft, _, _, _ in self.listdir(dino):
            if nm == name:
                target = child
                break
        if target is None:
            raise FileNotFoundError("%s/%s" % (dirpath, name))
        node = self.read_inode(target)
        blks = self.inode_blocks(node)
        size = struct.unpack("<I", node[0x04:0x08])[0]
        self.rm_dirent(dino, name)
        self.free_blocks_list(blks)
        self.free_inode(target)
        return size, len(blks)

    def add(self, dirpath, name, data, mode=0o100755, uid=0, gid=0):
        dino = self.resolve(dirpath)
        for nm, _, _, _, _, _ in self.listdir(dino):
            if nm == name:
                raise FileExistsError("%s/%s already exists" % (dirpath, name))
        nblk = (len(data) + self.block_size - 1) // self.block_size
        blks = self.alloc_blocks(nblk)
        for i in range(nblk):
            chunk = data[i * self.block_size:(i + 1) * self.block_size]
            self.wr(blks[i], chunk.ljust(self.block_size, b"\x00"))
        # build extents (coalesce contiguous runs; inode holds up to 4)
        runs = []
        for b in blks:
            if runs and b == runs[-1][0] + runs[-1][1]:
                runs[-1][1] += 1
            else:
                runs.append([b, 1])
        if len(runs) > 4:
            self.free_blocks_list(blks)
            raise RuntimeError("file too fragmented (%d runs, max 4)" % len(runs))
        ib = bytearray(60)
        struct.pack_into("<HHHHI", ib, 0, 0xF30A, len(runs), 4, 0, 0)
        logical = 0
        for k, (start, ln) in enumerate(runs):
            o = 12 + k * 12
            struct.pack_into("<IHHI", ib, o, logical, ln,
                             (start >> 32) & 0xFFFF, start & 0xFFFFFFFF)
            logical += ln
        ino = self.alloc_inode()
        node = bytearray(self.inode_size)
        now = int(time.time())
        struct.pack_into("<H", node, 0x00, mode)
        struct.pack_into("<H", node, 0x02, uid)
        struct.pack_into("<I", node, 0x04, len(data))
        struct.pack_into("<I", node, 0x08, now)
        struct.pack_into("<I", node, 0x0C, now)
        struct.pack_into("<I", node, 0x10, now)
        struct.pack_into("<H", node, 0x18, gid)
        struct.pack_into("<H", node, 0x1A, 1)
        struct.pack_into("<I", node, 0x1C, nblk * (self.block_size // 512))
        struct.pack_into("<I", node, 0x20, EXT4_EXTENTS_FL)
        node[0x28:0x28 + 60] = ib
        if self.inode_size > 128:
            struct.pack_into("<H", node, 0x80, 32)
        self.write_inode(ino, node)
        self.add_dirent(dino, name, ino, EXT4_FT_REG)
        return ino, nblk

    def mkdir(self, dirpath, name, mode=0o040755):
        """Create a directory: inode + one block holding '.' and '..'.

        Also bumps the parent's link count (the new '..' points at it) and the
        group's used_dirs counter, both of which e2fsck checks.
        """
        parent = self.resolve(dirpath)
        for nm, _, _, _, _, _ in self.listdir(parent):
            if nm == name:
                raise FileExistsError("%s/%s" % (dirpath, name))
        blk = self.alloc_blocks(1)[0]
        ino = self.alloc_inode()

        buf = bytearray(self.block_size)
        # "." -> self
        struct.pack_into("<IHBB", buf, 0, ino, 12, 1, 2)
        buf[8:9] = b"."
        # ".." -> parent, occupying the rest of the block
        struct.pack_into("<IHBB", buf, 12, parent, self.block_size - 12, 2, 2)
        buf[20:22] = b".."
        self.wr(blk, bytes(buf))

        ib = bytearray(60)
        struct.pack_into("<HHHHI", ib, 0, 0xF30A, 1, 4, 0, 0)
        struct.pack_into("<IHHI", ib, 12, 0, 1, (blk >> 32) & 0xFFFF, blk & 0xFFFFFFFF)

        node = bytearray(self.inode_size)
        now = int(time.time())
        struct.pack_into("<H", node, 0x00, mode)
        struct.pack_into("<I", node, 0x04, self.block_size)
        struct.pack_into("<I", node, 0x08, now)
        struct.pack_into("<I", node, 0x0C, now)
        struct.pack_into("<I", node, 0x10, now)
        struct.pack_into("<H", node, 0x1A, 2)          # '.' and the parent entry
        struct.pack_into("<I", node, 0x1C, self.block_size // 512)
        struct.pack_into("<I", node, 0x20, EXT4_EXTENTS_FL)
        node[0x28:0x28 + 60] = ib
        if self.inode_size > 128:
            struct.pack_into("<H", node, 0x80, 32)
        self.write_inode(ino, node)

        self.add_dirent(parent, name, ino, 2)          # EXT4_FT_DIR

        pn = self.read_inode(parent)
        struct.pack_into("<H", pn, 0x1A,
                         struct.unpack("<H", pn[0x1A:0x1C])[0] + 1)
        self.write_inode(parent, pn)

        g = (ino - 1) // self.inodes_per_group
        d = self.gd(g)
        struct.pack_into("<H", d, 0x10,
                         struct.unpack("<H", d[0x10:0x12])[0] + 1)
        self.put_gd(g, d)
        return ino

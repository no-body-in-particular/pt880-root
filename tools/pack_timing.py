#!/usr/bin/env python3
"""Measure the watch's tile download rate from the web server's access log.

Every pack request carries &s=1 or &s=0 for whether the backlight was on, so
the screen-on and screen-off cases can be compared against the real workload
instead of a synthetic transfer.

    python3 pack_timing.py [/var/log/hiawatha.log]

Rate is derived from the gap between consecutive requests, which is what the
watch actually experiences: the server is not the bottleneck once its tile
cache is warm. Blocks are bucketed by payload size, because an empty block
over the North Sea and a dense one over Rotterdam are not the same work and
mixing them hides the effect being measured.
"""

import datetime
import re
import statistics
import sys

LOG = sys.argv[1] if len(sys.argv) > 1 else "/var/log/hiawatha.log"

# A gap longer than this is a pause between runs, not the cost of a request.
MAX_GAP_S = 60


def bucket(size):
    if size < 60000:
        return "blank"
    if size < 150000:
        return "light"
    return "dense"


def main():
    rows = []
    for line in open(LOG, errors="replace"):
        if "pack.php" not in line or "Dalvik" not in line:
            continue
        f = line.split("|")
        if len(f) < 5:
            continue
        m = re.search(r"[?&]s=([01])", f[4])
        if not m:
            continue                      # written by a build before tagging
        tn = re.search(r"[?&]tn=(\d+)", f[4])
        tw = re.search(r"[?&]tw=(\d+)", f[4])
        try:
            t = datetime.datetime.strptime(f[1].strip(), "%a %d %b %Y %H:%M:%S %z")
        except ValueError:
            continue
        rows.append((t, int(f[3]), m.group(1) == "1",
                     int(tn.group(1)) if tn else -1,
                     int(tw.group(1)) if tw else -1))

    if not rows:
        print("no tagged requests yet -- needs a download from v5.5 or later")
        return

    rows.sort(key=lambda r: r[0])
    print("%d tagged requests, %s to %s"
          % (len(rows), rows[0][0].strftime("%d %b %H:%M"),
             rows[-1][0].strftime("%d %b %H:%M")))

    # Where a block's time actually goes, as reported by the watch itself.
    net = [r[3] for r in rows if r[3] >= 0]
    wri = [r[4] for r in rows if r[4] >= 0]
    if net and wri:
        print("\nself-reported per block: network %dms median, card %dms median"
              % (statistics.median(net), statistics.median(wri)))
        print("  card is %.0f%% of the two"
              % (100.0 * statistics.median(wri)
                 / max(1, statistics.median(wri) + statistics.median(net))))

    # gaps[(screen_on, bucket)] -> seconds per block
    gaps = {}
    bytes_by = {}
    for i in range(1, len(rows)):
        t, size, on = rows[i][0], rows[i][1], rows[i][2]
        gap = (t - rows[i - 1][0]).total_seconds()
        if gap <= 0 or gap > MAX_GAP_S:
            continue
        # Only compare like with like: a request straddling a screen change
        # belongs to neither case.
        if on != rows[i - 1][2]:
            continue
        key = (on, bucket(size))
        gaps.setdefault(key, []).append(gap)
        bytes_by.setdefault(key, []).append(size)

    print("\n%-8s %-7s %6s  %8s %8s  %10s" %
          ("screen", "block", "n", "median", "mean", "kB/s"))
    for b in ("blank", "light", "dense"):
        for on in (True, False):
            g = gaps.get((on, b))
            if not g:
                continue
            med = statistics.median(g)
            mean = sum(g) / len(g)
            kbs = (statistics.median(bytes_by[(on, b)]) / 1024.0) / med
            print("%-8s %-7s %6d  %7.2fs %7.2fs  %10.1f"
                  % ("on" if on else "OFF", b, len(g), med, mean, kbs))

    # The headline: same bucket, screen on versus off.
    print()
    for b in ("blank", "light", "dense"):
        a = gaps.get((True, b))
        c = gaps.get((False, b))
        if a and c and len(a) >= 3 and len(c) >= 3:
            ratio = statistics.median(c) / statistics.median(a)
            print("%-6s blocks: screen off is %.1fx slower  (%.2fs vs %.2fs, n=%d/%d)"
                  % (b, ratio, statistics.median(c), statistics.median(a),
                     len(c), len(a)))
        elif a or c:
            print("%-6s blocks: only %s seen so far (n=%d)"
                  % (b, "screen on" if a else "screen off", len(a or c)))


if __name__ == "__main__":
    main()

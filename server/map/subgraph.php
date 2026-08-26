<?php
/**
 * Cut a routing graph down to a bounding box.
 *
 * A whole country is the wrong unit for a watch. The Netherlands is 36MB,
 * which is tolerable; Germany is eight times the area and would be nearer
 * 300MB, which is not - neither to download over a watch's wifi nor to search
 * without a working set larger than the heap. What is actually wanted is the
 * area you are in.
 *
 * Nodes in the full graph are numbered in grid-cell order, so a rectangle of
 * cells is a handful of contiguous runs of ids. Extracting one is therefore a
 * copy and a renumbering rather than a search, and the result is a WGR2 graph
 * in its own right - same reader, same router, smaller.
 *
 * Arcs leaving the box are dropped. A route that would have left it cannot be
 * found, which is why the watch asks for a box around where it is going as
 * well as where it is.
 */
require_once __DIR__ . '/lib.php';

function subgraph_bytes(string $country, float $w, float $s, float $e, float $n): ?string {
    $f = DATA_DIR . "/$country.graph";
    if (!is_file($f)) { return null; }
    $raw = file_get_contents($f);
    if ($raw === false || substr($raw, 0, 4) !== 'WGR2') { return null; }

    $h = unpack('Nnodes/Narcs/Ncols/Nrows', substr($raw, 8, 16));
    $bb = unpack('E4', substr($raw, 24, 32));
    [$minx, $miny] = [$bb[1], $bb[2]];
    $N = $h['nodes']; $A = $h['arcs']; $cols = $h['cols']; $rows = $h['rows'];

    $nodesAt = 56;
    $adjAt   = $nodesAt + $N * 8;
    $arcsAt  = $adjAt + ($N + 1) * 4;
    $gridAt  = $arcsAt + $A * 6;

    $cell = function (int $c) use ($raw, $gridAt): int {
        return unpack('N', substr($raw, $gridAt + $c * 4, 4))[1];
    };

    $clamp = function (int $v, int $lo, int $hi) { return $v < $lo ? $lo : ($v > $hi ? $hi : $v); };
    $x0 = $clamp((int) floor(($w - $minx) / CELL_DEG), 0, $cols - 1);
    $x1 = $clamp((int) floor(($e - $minx) / CELL_DEG), 0, $cols - 1);
    $y0 = $clamp((int) floor(($s - $miny) / CELL_DEG), 0, $rows - 1);
    $y1 = $clamp((int) floor(($n - $miny) / CELL_DEG), 0, $rows - 1);
    if ($x1 < $x0 || $y1 < $y0) { return null; }

    $ncols = $x1 - $x0 + 1;
    $nrows = $y1 - $y0 + 1;

    // One run of node ids per row of cells, in the order they will be written.
    $runFrom = []; $runTo = []; $runBase = [];
    $kept = 0;
    for ($y = $y0; $y <= $y1; $y++) {
        $from = $cell($y * $cols + $x0);
        $to   = $cell($y * $cols + $x1 + 1);
        $runFrom[] = $from; $runTo[] = $to; $runBase[] = $kept;
        $kept += max(0, $to - $from);
    }
    if ($kept === 0) { return null; }

    $runs = count($runFrom);
    $newOf = function (int $id) use ($runFrom, $runTo, $runBase, $runs): int {
        $lo = 0; $hi = $runs - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($id < $runFrom[$mid]) { $hi = $mid - 1; }
            elseif ($id >= $runTo[$mid]) { $lo = $mid + 1; }
            else { return $runBase[$mid] + ($id - $runFrom[$mid]); }
        }
        return -1;
    };

    // Nodes, and the bounding box they actually occupy.
    $nodes = '';
    $bx0 = 180; $by0 = 90; $bx1 = -180; $by1 = -90;
    for ($r = 0; $r < $runs; $r++) {
        for ($id = $runFrom[$r]; $id < $runTo[$r]; $id++) {
            $rec = substr($raw, $nodesAt + $id * 8, 8);
            $nodes .= $rec;
            $v = unpack('Nlat/Nlon', $rec);
            $la = ($v['lat'] >= 0x80000000 ? $v['lat'] - 0x100000000 : $v['lat']) / 1e7;
            $lo = ($v['lon'] >= 0x80000000 ? $v['lon'] - 0x100000000 : $v['lon']) / 1e7;
            if ($lo < $bx0) { $bx0 = $lo; } if ($lo > $bx1) { $bx1 = $lo; }
            if ($la < $by0) { $by0 = $la; } if ($la > $by1) { $by1 = $la; }
        }
    }

    // Arcs, dropping any that leave the box.
    $adj = ''; $arcs = ''; $at = 0;
    for ($r = 0; $r < $runs; $r++) {
        for ($id = $runFrom[$r]; $id < $runTo[$r]; $id++) {
            $adj .= pack('N', $at);
            $from = unpack('N', substr($raw, $adjAt + $id * 4, 4))[1];
            $to   = unpack('N', substr($raw, $adjAt + ($id + 1) * 4, 4))[1];
            for ($k = $from; $k < $to; $k++) {
                $tgt = unpack('N', substr($raw, $arcsAt + $k * 6, 4))[1];
                $nt = $newOf($tgt);
                if ($nt < 0) { continue; }
                $arcs .= pack('N', $nt) . substr($raw, $arcsAt + $k * 6 + 4, 2);
                $at++;
            }
        }
    }
    $adj .= pack('N', $at);

    // The grid for the cut-down graph. Node order is already (row, column),
    // which is the new cell order, so this is a count per cell.
    $counts = array_fill(0, $ncols * $nrows + 1, 0);
    for ($y = $y0; $y <= $y1; $y++) {
        for ($x = $x0; $x <= $x1; $x++) {
            $c = $cell($y * $cols + $x);
            $cnext = $cell($y * $cols + $x + 1);
            $counts[(($y - $y0) * $ncols + ($x - $x0)) + 1] = max(0, $cnext - $c);
        }
    }
    for ($c = 1; $c <= $ncols * $nrows; $c++) { $counts[$c] += $counts[$c - 1]; }
    $grid = '';
    for ($c = 0; $c <= $ncols * $nrows; $c++) { $grid .= pack('N', $counts[$c]); }

    $originX = $minx + $x0 * CELL_DEG;
    $originY = $miny + $y0 * CELL_DEG;

    return 'WGR2' . pack('CCn', 2, 0, 0)
        . pack('NNNN', $kept, $at, $ncols, $nrows)
        . pack('E4', $originX, $originY,
                     $originX + $ncols * CELL_DEG, $originY + $nrows * CELL_DEG)
        . $nodes . $adj . $arcs . $grid;
}

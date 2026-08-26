<?php
/**
 * Prove the graph routes, on a machine with a debugger, before the watch
 * ever sees it.
 *
 *     php test_route.php netherlands 52.3702 4.8952 52.0859 5.1089
 *
 * Plain bidirectional Dijkstra. The watch will use A*, but a straight
 * Dijkstra is the thing to check a graph with: if this cannot find a route,
 * no heuristic is going to rescue it.
 */
require_once __DIR__ . '/lib.php';
ini_set('memory_limit', '4G');

$country = $argv[1] ?? 'netherlands';
$flat = (float) ($argv[2] ?? 52.3702); $flon = (float) ($argv[3] ?? 4.8952);
$tlat = (float) ($argv[4] ?? 52.0859); $tlon = (float) ($argv[5] ?? 5.1089);

$raw = file_get_contents(DATA_DIR . "/$country.graph");
if (substr($raw, 0, 4) !== 'WGR2') { exit("not a WGR2 graph\n"); }
$h = unpack('Nnodes/Narcs/Ncols/Nrows', substr($raw, 8, 16));
$bb = unpack('E4', substr($raw, 24, 32));
[$minx, $miny, $maxx, $maxy] = [$bb[1], $bb[2], $bb[3], $bb[4]];
$N = $h['nodes']; $A = $h['arcs']; $cols = $h['cols']; $rows = $h['rows'];

$nodesAt = 56;
$adjAt   = $nodesAt + $N * 8;
$arcsAt  = $adjAt + ($N + 1) * 4;      // six bytes each now
$gridAt  = $arcsAt + $A * 6;
// Nodes are numbered in cell order, so a cell's nodes are the ids between
// its offset and the next one - there is no separate list of them.

printf("graph: %d nodes, %d arcs, %d x %d cells, %.1f MB\n",
    $N, $A, $cols, $rows, strlen($raw) / 1048576);

function i32(string $r, int $off): int {
    $v = unpack('N', substr($r, $off, 4))[1];
    return $v >= 0x80000000 ? $v - 0x100000000 : $v;
}
function u32(string $r, int $off): int { return unpack('N', substr($r, $off, 4))[1]; }

function node_lat(string $r, int $base, int $i): float { return i32($r, $base + $i * 8) / 1e7; }
function node_lon(string $r, int $base, int $i): float { return i32($r, $base + $i * 8 + 4) / 1e7; }

/** Nearest node, via the grid, widening until something is found. */
function snap(string $raw, int $nodesAt, int $gridAt,
              int $cols, int $rows, float $minx, float $miny,
              float $lat, float $lon): int {
    $cx = (int) (($lon - $minx) / CELL_DEG);
    $cy = (int) (($lat - $miny) / CELL_DEG);
    $best = -1; $bestD = INF;
    for ($ring = 0; $ring < 40 && $best < 0; $ring++) {
        for ($dy = -$ring; $dy <= $ring; $dy++) {
            for ($dx = -$ring; $dx <= $ring; $dx++) {
                if ($ring > 0 && abs($dx) !== $ring && abs($dy) !== $ring) { continue; }
                $x = $cx + $dx; $y = $cy + $dy;
                if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows) { continue; }
                $c = $y * $cols + $x;
                $from = u32($raw, $gridAt + $c * 4);
                $to = u32($raw, $gridAt + ($c + 1) * 4);
                for ($id = $from; $id < $to; $id++) {
                    $dla = node_lat($raw, $nodesAt, $id) - $lat;
                    $dlo = (node_lon($raw, $nodesAt, $id) - $lon) * cos(deg2rad($lat));
                    $d = $dla * $dla + $dlo * $dlo;
                    if ($d < $bestD) { $bestD = $d; $best = $id; }
                }
            }
        }
    }
    return $best;
}

$t0 = microtime(true);
$s = snap($raw, $nodesAt, $gridAt, $cols, $rows, $minx, $miny, $flat, $flon);
$t = snap($raw, $nodesAt, $gridAt, $cols, $rows, $minx, $miny, $tlat, $tlon);
printf("snapped: %d (%.5f,%.5f) -> %d (%.5f,%.5f) in %.0f ms\n",
    $s, node_lat($raw,$nodesAt,$s), node_lon($raw,$nodesAt,$s),
    $t, node_lat($raw,$nodesAt,$t), node_lon($raw,$nodesAt,$t),
    (microtime(true)-$t0)*1000);
if ($s < 0 || $t < 0) { exit("could not snap\n"); }

// A*: same search, but ordered by cost-so-far plus a lower bound on what is
// left. The bound is the straight-line distance at the fastest speed on the
// network, which can never overstate the remainder - so the first time the
// target comes off the queue, it is still the shortest route.
$tla = node_lat($raw,$nodesAt,$t); $tlo = node_lon($raw,$nodesAt,$t);
$kx = cos(deg2rad($tla));
// From the graph rather than guessed: see the header note in build_graph.php.
// A graph built before that field existed says zero, and then a conservative
// bound is the only safe choice.
$hdrKmh = unpack('n', substr($raw, 6, 2))[1];
$MAXMPS = ($hdrKmh > 0 ? $hdrKmh : 140) / 3.6;
$hOf = function (int $n) use ($raw, $nodesAt, $tla, $tlo, $kx, $MAXMPS): int {
    return (int) (ground(node_lat($raw,$nodesAt,$n), node_lon($raw,$nodesAt,$n),
                         $tla, $tlo) / $MAXMPS * 10);
};
$t0 = microtime(true);
$dist = [];
$prev = [];
$dist[$s] = 0;
$pq = new SplPriorityQueue();
$pq->insert($s, -$hOf($s));
$settled = [];
$visits = 0;

while (!$pq->isEmpty()) {
    $u = $pq->extract();
    if (isset($settled[$u])) { continue; }
    $settled[$u] = true;
    $visits++;
    if ($u === $t) { break; }
    $du = $dist[$u];
    $from = u32($raw, $adjAt + $u * 4);
    $to = u32($raw, $adjAt + ($u + 1) * 4);
    for ($k = $from; $k < $to; $k++) {
        $v = u32($raw, $arcsAt + $k * 6);
        $c = unpack('n', substr($raw, $arcsAt + $k * 6 + 4, 2))[1];
        $nd = $du + $c;
        if (!isset($dist[$v]) || $nd < $dist[$v]) {
            $dist[$v] = $nd; $prev[$v] = $u;
            $pq->insert($v, -($nd + $hOf($v)));
        }
    }
}
$ms = (microtime(true) - $t0) * 1000;

if (!isset($dist[$t])) { printf("NO ROUTE (%d settled, %.0f ms)\n", $visits, $ms); exit(1); }

$path = []; $u = $t;
while ($u !== $s) { $path[] = $u; $u = $prev[$u]; }
$path[] = $s;
$path = array_reverse($path);

$metres = 0;
for ($i = 1; $i < count($path); $i++) {
    $a = $path[$i-1]; $b = $path[$i];
    $metres += ground(node_lat($raw,$nodesAt,$a), node_lon($raw,$nodesAt,$a),
                      node_lat($raw,$nodesAt,$b), node_lon($raw,$nodesAt,$b));
}

// The graph has a node only where ways meet, so $metres above is a chain of
// chords across the bends in between - about 5% short across this network.
// With TRUELEN=1 the arcs are measured again from the shapefile, which is the
// distance a driver would actually cover.
$trueKm = null;
if (getenv('TRUELEN')) {
    require_once __DIR__ . '/truelen.php';
    $tab = arc_lengths_cached($country);
    $sum = 0.0; $missed = 0;
    for ($i = 1; $i < count($path); $i++) {
        $a = $path[$i-1]; $b = $path[$i];
        $ka = key_of(node_lon($raw,$nodesAt,$a), node_lat($raw,$nodesAt,$a));
        $kb = key_of(node_lon($raw,$nodesAt,$b), node_lat($raw,$nodesAt,$b));
        $pair = $ka < $kb ? "$ka,$kb" : "$kb,$ka";
        if (isset($tab[$pair])) { $sum += $tab[$pair]; continue; }
        // No entry: fall back to the chord rather than dropping the hop.
        $missed++;
        $sum += ground(node_lat($raw,$nodesAt,$a), node_lon($raw,$nodesAt,$a),
                       node_lat($raw,$nodesAt,$b), node_lon($raw,$nodesAt,$b));
    }
    $trueKm = $sum / 1000;
    if ($missed) { fwrite(STDERR, "truelen: $missed of " . (count($path)-1) . " hops fell back to the chord\n"); }
}

printf("route: %.1f km, %.0f min, %d hops, %d nodes settled, %.0f ms\n",
    $trueKm ?? $metres/1000, $dist[$t]/600, count($path), $visits, $ms);
// GEOJSON=1 prints the path so it can be laid over a reference router's, which
// is the only way to tell "a different road" from "the same road measured
// differently".
if (getenv('GEOJSON')) {
    $c = [];
    foreach ($path as $n) {
        $c[] = sprintf('[%.6f,%.6f]', node_lon($raw,$nodesAt,$n), node_lat($raw,$nodesAt,$n));
    }
    file_put_contents(getenv('GEOJSON'), '[' . implode(',', $c) . ']');
}
if ($trueKm !== null) {
    printf("chord: %.1f km (%.1f%% short)\n", $metres/1000, ($trueKm - $metres/1000)/$trueKm*100);
}

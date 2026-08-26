<?php
/**
 * A routable graph the watch can carry.
 *
 *     php build_graph.php netherlands
 *
 * The roads shapefile is geometry, not topology: ways cross without sharing a
 * record, and a single way can run through twenty junctions. So this makes
 * two passes. The first counts how often every point appears across every
 * way; a point in two or more ways is a junction. The second cuts each way at
 * its junctions and emits one edge per stretch between them.
 *
 * That cutting is the whole trick for size. Seven points in ten are just a
 * way being drawn round a bend, not a place a decision can be made, and a
 * graph that keeps them is three times bigger and three times slower to
 * search for no benefit whatsoever.
 *
 * The output is read on the watch by memory-mapping it, so nothing here may
 * need inflating into objects: fixed-width records, big-endian, offsets
 * rather than pointers.
 *
 *     "WGR1"  u8 version  u8 0  u16 0
 *     u32 nodes  u32 arcs  u32 cols  u32 rows
 *     f64 minx, miny, maxx, maxy
 *     nodes:  i32 lat*1e7, i32 lon*1e7                    8 bytes each
 *     adj:    u32 first-arc index, one per node, plus a tail     4 each
 *     arcs:   u32 target node, u32 cost in deciseconds     8 bytes each
 *     grid:   u32 first-node index per cell, plus a tail   4 each
 *             u32 node id, grouped by cell                 4 each
 *
 * The grid is a spatial index for snapping a position to the nearest node,
 * which otherwise means scanning every node on the device.
 */
require_once __DIR__ . '/lib.php';

ini_set('memory_limit', '6G');

$country = $argv[1] ?? 'netherlands';
$src = DATA_DIR . "/$country/gis_osm_roads_free_1";
if (!is_file("$src.shp")) { fwrite(STDERR, "no roads shapefile for $country\n"); exit(1); }

/** Assumed speeds where the data does not say, km/h. Anything absent from
 *  this table is not drivable and is left out of the graph entirely. */
function drive_speeds(): array {
    /*
     * What a car actually averages on each kind of road, not what the sign
     * says.
     *
     * The first version used the speed limit, and the routes came out 25 to
     * 30 per cent quicker than the reference router - Amsterdam to Utrecht in
     * 31 minutes against 43. That is not only a wrong arrival time. Cost
     * decides the route, and overrating town roads against motorways makes
     * the search prefer the direct way through everywhere rather than the
     * long way round on a road built for it, which is why our distances came
     * out shorter than they should have been.
     *
     * These are roughly what OSRM's car profile uses, which is derived from
     * measurement rather than from signage.
     */
    return [
        'motorway' => 95, 'motorway_link' => 60,
        'trunk' => 80, 'trunk_link' => 50,
        'primary' => 60, 'primary_link' => 40,
        'secondary' => 50, 'secondary_link' => 35,
        'tertiary' => 40, 'tertiary_link' => 30,
        'unclassified' => 30, 'residential' => 22, 'living_street' => 8,
        'service' => 12, 'track' => 10, 'unknown' => 25,
    ];
}

/*
 * What a real junction costs, in seconds.
 *
 * Charged only where three or more ways meet. Most nodes in this graph are
 * not junctions at all - they are a way split because an attribute changed,
 * or because another way ends there - and charging at every one made a
 * journey through a town cost far more than it does, which pushed routes onto
 * long ways round: Vlissingen to Rotterdam went 16 per cent further than the
 * reference router rather than 3.
 *
 * Four seconds is the give-way, the lights and the turn, averaged.
 */
const JUNCTION_SEC = 4.0;

/** Quantised to about a centimetre; shapefile coordinates of the same OSM
 *  node are bit-identical, but rounding guards against a rebuilt extract. */
function key_of(float $lon, float $lat): int {
    return ((int) round($lon * 1e7)) * 4000000000 + ((int) round($lat * 1e7));
}

$speeds = drive_speeds();

// ---------------------------------------------------------------- pass one

$dbf = fopen("$src.dbf", 'rb');
$h = fread($dbf, 32);
$hd = unpack('Vrecords/vheaderLen/vrecordLen', substr($h, 4, 8));
$fields = []; $off = 1;
while (true) {
    $d = fread($dbf, 32);
    if ($d === false || ord($d[0]) === 0x0D) { break; }
    $nm = rtrim(substr($d, 0, 11), "\0");
    $fields[$nm] = ['off' => $off, 'len' => ord($d[16])];
    $off += ord($d[16]);
}

function attrs($dbf, array $hd, array $fields, int $rec): array {
    fseek($dbf, $hd['headerLen'] + ($rec - 1) * $hd['recordLen']);
    $row = fread($dbf, $hd['recordLen']);
    if ($row === false || strlen($row) < $hd['recordLen']) { return []; }
    $get = function ($n) use ($row, $fields) {
        return isset($fields[$n])
            ? trim(substr($row, $fields[$n]['off'], $fields[$n]['len'])) : '';
    };
    return ['fclass' => $get('fclass'), 'oneway' => $get('oneway'),
            'maxspeed' => (int) $get('maxspeed')];
}

$t0 = microtime(true);
$seen = [];          // point key => times seen
$shp = fopen("$src.shp", 'rb');
fseek($shp, 24);
$fileLen = unpack('Nlen', fread($shp, 4))['len'] * 2;
fseek($shp, 100);
$rec = 0; $ways = 0;

while (ftell($shp) < $fileLen) {
    $rh = fread($shp, 8);
    if ($rh === false || strlen($rh) < 8) { break; }
    $r = unpack('Nnum/Nlen', $rh);
    $content = fread($shp, $r['len'] * 2);
    if ($content === false || strlen($content) < 44) { break; }
    $rec++;
    if (unpack('Vt', substr($content, 0, 4))['t'] !== 3) { continue; }

    $a = attrs($dbf, $hd, $fields, $rec);
    if (!isset($speeds[$a['fclass']])) { continue; }

    $hdr = unpack('dxmin/dymin/dxmax/dymax/Vparts/Vpoints', substr($content, 4, 40));
    $n = $hdr['points'];
    if ($n < 2) { continue; }
    $ptsOff = 44 + $hdr['parts'] * 4;
    $raw = unpack('d' . ($n * 2), substr($content, $ptsOff, $n * 16));
    for ($i = 0; $i < $n; $i++) {
        $k = key_of($raw[$i * 2 + 1], $raw[$i * 2 + 2]);
        // Endpoints are always nodes; interior points only if shared.
        $bump = ($i === 0 || $i === $n - 1) ? 2 : 1;
        $seen[$k] = ($seen[$k] ?? 0) + $bump;
    }
    $ways++;
    if (($ways % 200000) === 0) {
        fwrite(STDERR, sprintf("\rpass 1: %d ways, %d points, %.0fs   ",
            $ways, count($seen), microtime(true) - $t0));
    }
}
fwrite(STDERR, sprintf("\npass 1: %d drivable ways, %d distinct points, %.0fs\n",
    $ways, count($seen), microtime(true) - $t0));

// A point is a node if two ways touch it, or if it ends a way.
$nodeId = [];
$next = 0;
foreach ($seen as $k => $c) {
    if ($c >= 2) { $nodeId[$k] = $next++; }
}
unset($seen);
fwrite(STDERR, sprintf("nodes: %d (%.0fs)\n", $next, microtime(true) - $t0));

// ---------------------------------------------------------------- pass two

$lat = array_fill(0, $next, 0);
$lon = array_fill(0, $next, 0);
$arcsFrom = [];      // node => list of [target, cost]
// The same links the other way round, used once to work out which pieces of
// the network touch. A one-way street still joins what it connects.
$backFrom = [];
$arcCount = 0;

fseek($shp, 100);
$rec = 0; $edges = 0;

while (ftell($shp) < $fileLen) {
    $rh = fread($shp, 8);
    if ($rh === false || strlen($rh) < 8) { break; }
    $r = unpack('Nnum/Nlen', $rh);
    $content = fread($shp, $r['len'] * 2);
    if ($content === false || strlen($content) < 44) { break; }
    $rec++;
    if (unpack('Vt', substr($content, 0, 4))['t'] !== 3) { continue; }

    $a = attrs($dbf, $hd, $fields, $rec);
    if (!isset($speeds[$a['fclass']])) { continue; }
    $kmh = $a['maxspeed'] > 0 ? $a['maxspeed'] : $speeds[$a['fclass']];
    if ($kmh < 5) { $kmh = 5; }
    $mps = $kmh / 3.6;

    // 'B' both ways, 'F' along the geometry, 'T' against it.
    $ow = $a['oneway'];
    $fwd = ($ow !== 'T');
    $bwd = ($ow !== 'F');

    $hdr = unpack('dxmin/dymin/dxmax/dymax/Vparts/Vpoints', substr($content, 4, 40));
    $n = $hdr['points'];
    if ($n < 2) { continue; }
    $ptsOff = 44 + $hdr['parts'] * 4;
    $raw = unpack('d' . ($n * 2), substr($content, $ptsOff, $n * 16));

    $prevNode = null;
    $runM = 0.0;
    $px = null; $py = null;

    for ($i = 0; $i < $n; $i++) {
        $x = $raw[$i * 2 + 1];
        $y = $raw[$i * 2 + 2];
        if ($px !== null) {
            // Along the ground, not across the bounding box: a way bent round
            // a corner is longer than the line between its ends.
            $dx = ($x - $px) * 111320 * cos(deg2rad(($y + $py) / 2));
            $dy = ($y - $py) * 110540;
            $runM += sqrt($dx * $dx + $dy * $dy);
        }
        $px = $x; $py = $y;

        $k = key_of($x, $y);
        if (!isset($nodeId[$k])) { continue; }       // not a junction

        $id = $nodeId[$k];
        $lat[$id] = (int) round($y * 1e7);
        $lon[$id] = (int) round($x * 1e7);

        if ($prevNode !== null && $prevNode !== $id && $runM > 0) {
            // The junction charge is added in a later pass, once every node's
            // degree is known - a node is only a junction if things meet there.
            $cost = (int) round(($runM / $mps) * 10);      // deciseconds
            if ($cost < 1) { $cost = 1; }
            if ($fwd) {
                $arcsFrom[$prevNode][] = [$id, $cost];
                $backFrom[$id][] = $prevNode;
                $arcCount++;
            }
            if ($bwd) {
                $arcsFrom[$id][] = [$prevNode, $cost];
                $backFrom[$prevNode][] = $id;
                $arcCount++;
            }
            $edges++;
        }
        $prevNode = $id;
        $runM = 0.0;
    }

    if (($rec % 200000) === 0) {
        fwrite(STDERR, sprintf("\rpass 2: %d records, %d edges, %.0fs   ",
            $rec, $edges, microtime(true) - $t0));
    }
}
fclose($shp); fclose($dbf);
fwrite(STDERR, sprintf("\npass 2: %d edges, %d arcs, %.0fs\n",
    $edges, $arcCount, microtime(true) - $t0));

// ------------------------------------------------ charge for real junctions

/*
 * Now that every arc exists, the degree of each node is known, and an arc
 * that ends where three or more ways meet is charged for the junction.
 */
$degree = array_fill(0, $next, 0);
foreach ($arcsFrom as $u => $list) {
    foreach ($list as [$v, $c]) { $degree[$v]++; $degree[$u]++; }
}
$charged = 0;
$penalty = (int) round(JUNCTION_SEC * 10);
foreach ($arcsFrom as $u => $list) {
    foreach ($list as $i => [$v, $c]) {
        if ($degree[$v] >= 3) {
            $nc = $c + $penalty;
            $arcsFrom[$u][$i] = [$v, $nc > 65535 ? 65535 : $nc];
            $charged++;
        }
    }
}
fwrite(STDERR, sprintf("junction charge applied to %d of %d arcs\n", $charged, $arcCount));
unset($degree);

// -------------------------------------------------- keep what can be reached

/*
 * Everything not connected to the main road network is dropped.
 *
 * The network is not one piece. Built from the Netherlands, it comes out as
 * 8,506 separate components: one of 1.42 million nodes that is the road
 * network, and 8,505 fragments - driveways, car parks, service loops, stubs
 * whose connecting way was not drivable and so was never imported.
 *
 * That matters because snapping picks the nearest node. Asked to route to
 * Enschede, it landed on a seven-node island with one arc out and the search
 * then explored all 1.42 million nodes of the real network before reporting
 * no route - which is exactly what it should say about that node, and exactly
 * the wrong answer for the request. Which destinations fail is decided by
 * whatever fragment happens to be nearest, so it looks random.
 *
 * The fragments cannot be routed to or from, so they are not worth carrying.
 * Dropping them means every node in the file is reachable from every other,
 * and snapping cannot pick somewhere useless.
 */
fwrite(STDERR, "finding the connected network...\n");

$comp = array_fill(0, $next, -1);
$bestComp = -1; $bestSize = 0; $components = 0;

for ($seed = 0; $seed < $next; $seed++) {
    if ($comp[$seed] >= 0) { continue; }
    $stack = [$seed];
    $comp[$seed] = $components;
    $size = 0;
    while ($stack) {
        $u = array_pop($stack);
        $size++;
        if (isset($arcsFrom[$u])) {
            foreach ($arcsFrom[$u] as [$v, $c]) {
                if ($comp[$v] < 0) { $comp[$v] = $components; $stack[] = $v; }
            }
        }
        // Arcs are directed; connectivity here is about whether the pieces
        // touch at all, so the reverse direction counts too.
        if (isset($backFrom[$u])) {
            foreach ($backFrom[$u] as $v) {
                if ($comp[$v] < 0) { $comp[$v] = $components; $stack[] = $v; }
            }
        }
    }
    if ($size > $bestSize) { $bestSize = $size; $bestComp = $components; }
    $components++;
}

fwrite(STDERR, sprintf("%d components; keeping the largest, %d of %d nodes (%.1f%%)\n",
    $components, $bestSize, $next, 100.0 * $bestSize / $next));

$keep = [];
$renum = array_fill(0, $next, -1);
$kept = 0;
for ($i = 0; $i < $next; $i++) {
    if ($comp[$i] === $bestComp) { $renum[$i] = $kept++; $keep[] = $i; }
}

$newArcs = [];
$dropped = 0;
foreach ($arcsFrom as $u => $list) {
    if ($renum[$u] < 0) { $dropped += count($list); continue; }
    foreach ($list as [$v, $c]) {
        if ($renum[$v] < 0) { $dropped++; continue; }
        $newArcs[$renum[$u]][] = [$renum[$v], $c];
    }
}
$newLat = []; $newLon = [];
foreach ($keep as $pos => $old) { $newLat[$pos] = $lat[$old]; $newLon[$pos] = $lon[$old]; }

$arcsFrom = $newArcs;
$lat = $newLat;
$lon = $newLon;
$next = $kept;
$arcCount -= $dropped;
unset($comp, $renum, $keep, $newArcs, $newLat, $newLon, $backFrom);
fwrite(STDERR, sprintf("dropped %d arcs into fragments; %d nodes, %d arcs remain\n",
    $dropped, $next, $arcCount));

// ---------------------------------------------------------------- write out

$minx = 180; $miny = 90; $maxx = -180; $maxy = -90;
for ($i = 0; $i < $next; $i++) {
    $x = $lon[$i] / 1e7; $y = $lat[$i] / 1e7;
    if ($x < $minx) { $minx = $x; } if ($x > $maxx) { $maxx = $x; }
    if ($y < $miny) { $miny = $y; } if ($y > $maxy) { $maxy = $y; }
}

$cols = max(1, (int) ceil(($maxx - $minx) / CELL_DEG));
$rows = max(1, (int) ceil(($maxy - $miny) / CELL_DEG));

// Nodes grouped by grid cell, so the watch can snap a position without
// walking every node.
$cellIdx2 = [];
for ($i = 0; $i < $next; $i++) {
    $cx = (int) (($lon[$i] / 1e7 - $minx) / CELL_DEG);
    $cy = (int) (($lat[$i] / 1e7 - $miny) / CELL_DEG);
    if ($cx < 0) { $cx = 0; } if ($cx >= $cols) { $cx = $cols - 1; }
    if ($cy < 0) { $cy = 0; } if ($cy >= $rows) { $cy = $rows - 1; }
    $cellIdx2[$i] = $cy * $cols + $cx;
}

/*
 * Nodes are renumbered into grid-cell order before writing.
 *
 * That makes the cell offsets alone enough to say which nodes are in a cell -
 * they are a contiguous run of ids - so the array listing them, four bytes
 * per node, does not need to exist. Five and a half megabytes for a sort.
 */
$order = range(0, $next - 1);
usort($order, function ($a, $b) use ($cellIdx2) {
    return $cellIdx2[$a] <=> $cellIdx2[$b] ?: $a <=> $b;
});
$newOf = array_fill(0, $next, 0);
foreach ($order as $pos => $old) { $newOf[$old] = $pos; }

$cellOf = array_fill(0, $cols * $rows + 1, 0);
foreach ($order as $pos => $old) { $cellOf[$cellIdx2[$old] + 1]++; }
for ($c = 1; $c <= $cols * $rows; $c++) { $cellOf[$c] += $cellOf[$c - 1]; }

$out = fopen(DATA_DIR . "/$country.graph", 'wb');
fwrite($out, 'WGR2');
fwrite($out, pack('CCn', 2, 0, 0));
fwrite($out, pack('NNNN', $next, $arcCount, $cols, $rows));
fwrite($out, pack('E4', $minx, $miny, $maxx, $maxy));

foreach ($order as $old) {
    fwrite($out, pack('NN', $lat[$old] & 0xFFFFFFFF, $lon[$old] & 0xFFFFFFFF));
}

// Arcs are six bytes, not eight: the cost is deciseconds and the longest
// stretch between two junctions in the country is 34 minutes, so sixteen
// bits is ample and the other two bytes were being spent on zeroes.
$at = 0;
$adj = '';
$arcs = '';
foreach ($order as $old) {
    $adj .= pack('N', $at);
    if (isset($arcsFrom[$old])) {
        foreach ($arcsFrom[$old] as [$t, $c]) {
            if ($c > 65535) { $c = 65535; }
            $arcs .= pack('Nn', $newOf[$t], $c);
            $at++;
        }
    }
}
$adj .= pack('N', $at);
fwrite($out, $adj);
fwrite($out, $arcs);
unset($adj, $arcs);

$g = '';
for ($c = 0; $c <= $cols * $rows; $c++) { $g .= pack('N', $cellOf[$c]); }
fwrite($out, $g);
fclose($out);

// Compressed here, once, rather than by the web server on demand. The data
// directory is not writable by it - and 36MB of deflate inside a request is
// a CGI timeout waiting to happen.
$gz = DATA_DIR . "/$country.graph.gz";
$fp = gzopen($gz, 'wb6');
$in = fopen(DATA_DIR . "/$country.graph", 'rb');
while (!feof($in)) { gzwrite($fp, fread($in, 1 << 20)); }
fclose($in);
gzclose($fp);
@chmod($gz, 0644);

$size = filesize(DATA_DIR . "/$country.graph");
fwrite(STDERR, sprintf("wrote %s.graph: %d nodes, %d arcs, %.1f MB (%.1f MB gzipped), %.0fs\n",
    $country, $next, $at, $size / 1048576, filesize($gz) / 1048576,
    microtime(true) - $t0));

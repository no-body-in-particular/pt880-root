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

/* drive_speeds() lives in lib.php: truelen.php has to filter ways by exactly
 * the same table this builder does, and two copies of it would drift. */


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

/** The fewest nodes a piece of the network can have and still be somewhere
 *  rather than an artifact. See the pruning pass for where this comes from. */
const MIN_COMPONENT = 200;

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
$maxKmh = 0;

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
    // The fastest speed anywhere in this graph, recorded so the router does
    // not have to guess one. See the header write below.
    if ($kmh > $maxKmh) { $maxKmh = $kmh; }
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
            $runM += ground($py, $px, $y, $x);
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
fwrite(STDERR, sprintf("fastest road in this graph: %d km/h\n", $maxKmh));
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
$compSize = [];
$components = 0;

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
    $compSize[$components] = $size;
    $components++;
}

/*
 * Everything big enough to be a road network, not only the biggest one.
 *
 * This kept the largest component and dropped the rest, which is right for a
 * car park and wrong for an island: it deleted Texel, Terschelling and the
 * rest of the Wadden from the Dutch graph, leaving a watch on Texel snapping
 * to a node ten kilometres away across the water and routing through the sea
 * to reach it. Scotland is worse - Lewis, Orkney, Shetland and Islay are
 * between them a twentieth of its roads, and all of them went.
 *
 * A ferry is not a road and the search should still refuse to cross one. But
 * refusing to cross it is a different thing from pretending the far side has
 * no roads on it.
 *
 * The line between an island and an artifact is size. Scotland's components
 * fall into twelve with a thousand nodes or more and eight thousand with
 * fewer than fifty; there is nothing in between to argue about.
 */
$keptNodes = 0;
foreach ($compSize as $size) { if ($size >= MIN_COMPONENT) { $keptNodes += $size; } }
$keptComps = count(array_filter($compSize, function ($n) { return $n >= MIN_COMPONENT; }));

fwrite(STDERR, sprintf("%d components; keeping %d of them with %d or more nodes, "
    . "%d of %d nodes (%.1f%%)\n",
    $components, $keptComps, MIN_COMPONENT, $keptNodes, $next,
    100.0 * $keptNodes / $next));

/*
 * Ferries.
 *
 * The road data has no ferries in it - Geofabrik's roads layer is highways
 * and nothing else - so without this an island is a piece of network with no
 * way in. Keeping the islands rather than deleting them fixed half of that:
 * a watch on Texel now finds a road under it. This is the other half, so that
 * it can also be told how to get home.
 *
 * What the data does have is ferry terminals, as points: 732 of them in the
 * Netherlands. A terminal on its own says nothing about where it sails to,
 * but a pair of them does, given one more fact the graph already knows -
 * which piece of the network each one stands on.
 *
 *   - Two terminals on opposite banks of a river that is bridged upstream sit
 *     on the same piece. There is a ferry there, and a car can already get
 *     across without it, so nothing is added and nothing is lost.
 *   - Two terminals on pieces that do not otherwise touch are the interesting
 *     case, and the shortest such pair between any two pieces is the crossing
 *     that joins them.
 *
 * That is a guess, and it can be wrong - two islands whose terminals happen
 * to face each other with no service between them would be joined. It is
 * bounded by only ever adding one crossing per pair of pieces, by refusing
 * any longer than MAX_FERRY_M, and by charging what a ferry actually costs.
 *
 * The charge matters as much as the link. A crossing is not a fast road: it
 * is a wait for a sailing, then a slow boat. FERRY_WAIT_SEC is the half of a
 * timetable you expect to lose on average plus loading, and at that price the
 * search takes a ferry when there is no road and never as a shortcut.
 */

/** How far a terminal can be from a road before it is not that road's. */
const FERRY_SNAP_M = 1500;

/**
 * The longest crossing this will infer.
 *
 * Two numbers meet here and the smaller wins. The first is judgement: a pair
 * of terminals a few kilometres apart across a strait is good evidence of a
 * ferry, and a pair three hundred kilometres apart is not evidence of
 * anything - Aberdeen and Lerwick are not something to infer from a map.
 *
 * The second is arithmetic, and it is the binding one. An arc costs sixteen
 * bits of deciseconds, so the most any arc can be worth is 109 minutes. Half
 * an hour of that is the wait, leaving 46 km of sailing before the cost
 * silently clamps - and a clamped ferry is worse than no ferry, because it
 * reads as cheaper than it is and the search will choose it over a drive.
 *
 * So 45 km, which covers every crossing in the Wadden and the Channel and
 * most of the Hebrides. Ullapool to Stornoway is 70 and is not inferred; Lewis
 * stays a piece of network you can drive around but not reach, which is what
 * the data actually supports.
 */
const MAX_FERRY_M = 45000;

/** A car ferry does about 35 km/h, and you wait for it. */
const FERRY_MPS = 35 / 3.6;
const FERRY_WAIT_SEC = 1800;

/**
 * Join pieces of the network that have ferry terminals facing each other.
 *
 * @param array $comp      component id per node
 * @param array $compSize  node count per component
 * @return int how many crossings were added
 */
function add_ferries(string $country, array &$arcsFrom, array &$backFrom,
                     array $lat, array $lon, int $next,
                     array $comp, array $compSize, int &$arcCount): int {
    $src = DATA_DIR . "/$country/gis_osm_transport_free_1";
    if (!is_file("$src.shp") || !is_file("$src.dbf")) {
        fwrite(STDERR, "no transport layer for $country; no ferries added\n");
        return 0;
    }

    // ---- terminals
    $dbf = fopen("$src.dbf", 'rb');
    $h = fread($dbf, 32);
    $hd = unpack('Vrecords/vheaderLen/vrecordLen', substr($h, 4, 8));
    $fields = []; $off = 1;
    while (true) {
        $d = fread($dbf, 32);
        if ($d === false || ord($d[0]) === 0x0D) { break; }
        $fields[rtrim(substr($d, 0, 11), "\0")] = ['off' => $off, 'len' => ord($d[16])];
        $off += ord($d[16]);
    }
    $isFerry = [];
    fseek($dbf, $hd['headerLen']);
    for ($i = 1; $i <= $hd['records']; $i++) {
        $row = fread($dbf, $hd['recordLen']);
        if ($row === false || strlen($row) < $hd['recordLen']) { break; }
        $v = trim(substr($row, $fields['fclass']['off'], $fields['fclass']['len']));
        if ($v === 'ferry_terminal') { $isFerry[$i] = true; }
    }
    fclose($dbf);
    if (!$isFerry) { return 0; }

    $shp = fopen("$src.shp", 'rb');
    $head = fread($shp, 100);
    $fileLen = unpack('N', substr($head, 24, 4))[1] * 2;
    $pts = [];
    $rec = 0;
    while (ftell($shp) < $fileLen) {
        $rh = fread($shp, 8);
        if ($rh === false || strlen($rh) < 8) { break; }
        $r = unpack('Nnum/Nlen', $rh);
        $c = fread($shp, $r['len'] * 2);
        if ($c === false || strlen($c) < 4) { break; }
        $rec++;
        if (unpack('Vt', substr($c, 0, 4))['t'] !== 1) { continue; }   // point
        if (!isset($isFerry[$rec])) { continue; }
        $xy = unpack('dx/dy', substr($c, 4, 16));
        $pts[] = [$xy['y'], $xy['x']];
    }
    fclose($shp);
    if (count($pts) < 2) { return 0; }

    // ---- nearest kept node to each terminal, through a coarse grid
    $grid = [];
    for ($i = 0; $i < $next; $i++) {
        if ($compSize[$comp[$i]] < MIN_COMPONENT) { continue; }
        $gx = (int) floor($lon[$i] / 1e5);
        $gy = (int) floor($lat[$i] / 1e5);
        $grid[$gx * 100000 + $gy][] = $i;
    }
    $anchor = [];      // [node, component, lat, lon] per terminal that snapped
    foreach ($pts as [$tla, $tlo]) {
        $gx = (int) floor($tlo * 1e7 / 1e5);
        $gy = (int) floor($tla * 1e7 / 1e5);
        $best = -1; $bestD = INF;
        for ($dx = -2; $dx <= 2; $dx++) {
            for ($dy = -2; $dy <= 2; $dy++) {
                $cell = $grid[($gx + $dx) * 100000 + ($gy + $dy)] ?? null;
                if ($cell === null) { continue; }
                foreach ($cell as $n) {
                    $d = ground($lat[$n] / 1e7, $lon[$n] / 1e7, $tla, $tlo);
                    if ($d < $bestD) { $bestD = $d; $best = $n; }
                }
            }
        }
        if ($best >= 0 && $bestD <= FERRY_SNAP_M) {
            $anchor[] = [$best, $comp[$best], $tla, $tlo];
        }
    }
    if (count($anchor) < 2) { return 0; }

    /*
     * Shortest crossing first, merging as it goes.
     *
     * The first version added the shortest crossing between every pair of
     * pieces, which for the Wadden meant joining each island to every other
     * as well as to the coast: fifteen crossings where there are five, and
     * among them a ninety-four kilometre sailing from Texel to
     * Schiermonnikoog that has never existed.
     *
     * Taking them in order of length and skipping any whose two ends are
     * already joined fixes it by construction. The shortest crossing from
     * Texel reaches Den Helder, and from that moment Texel is part of the
     * mainland - so when Vlieland's turn comes, its shortest crossing to
     * anywhere it is not already connected to is Harlingen, which is the
     * ferry that exists. What comes out is the cheapest set of crossings that
     * joins everything joinable, and real ferry networks are shaped that way
     * because they were built for the same reason.
     */
    $cand = [];
    $n = count($anchor);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if ($anchor[$i][1] === $anchor[$j][1]) { continue; }
            $d = ground($anchor[$i][2], $anchor[$i][3], $anchor[$j][2], $anchor[$j][3]);
            if ($d > MAX_FERRY_M) { continue; }
            $cand[] = [$d, $anchor[$i][0], $anchor[$j][0], $anchor[$i][1], $anchor[$j][1]];
        }
    }
    /*
     * The coast first, then whatever is left.
     *
     * Shortest-first alone builds a chain: Terschelling's nearest other piece
     * of land is Vlieland, so it joined there, and a drive from Utrecht came
     * out at five and three quarter hours by way of two islands instead of
     * four by way of Harlingen. Ferries are not laid out to minimise total
     * sailing; they run from the mainland to each island, because that is
     * where everybody is going.
     *
     * So crossings that touch the largest piece are offered first, and only
     * what cannot reach it that way is joined island to island.
     */
    $mainland = array_keys($compSize, max($compSize))[0];
    usort($cand, function ($x, $y) use ($mainland) {
        $xm = ($x[3] === $mainland || $x[4] === $mainland) ? 0 : 1;
        $ym = ($y[3] === $mainland || $y[4] === $mainland) ? 0 : 1;
        if ($xm !== $ym) { return $xm <=> $ym; }
        return $x[0] <=> $y[0];
    });

    $set = [];
    $find = function ($x) use (&$set, &$find) {
        while (($set[$x] ?? $x) !== $x) {
            $set[$x] = $set[$set[$x]] ?? $set[$x];
            $x = $set[$x];
        }
        return $x;
    };

    $link = [];
    foreach ($cand as [$d, $u, $v, $ca, $cb]) {
        $ra = $find($ca); $rb = $find($cb);
        if ($ra === $rb) { continue; }
        $set[$ra] = $rb;
        $link[] = [$u, $v, $d];
    }

    $added = 0;
    foreach ($link as [$u, $v, $d]) {
        $cost = (int) round((FERRY_WAIT_SEC + $d / FERRY_MPS) * 10);
        if ($cost > 65535) {
            // Cannot happen while MAX_FERRY_M holds, and must not pass
            // silently if it stops holding: an under-costed crossing is
            // chosen over roads that are genuinely quicker.
            fwrite(STDERR, sprintf("  ferry too long to cost (%.1f km); skipped\n",
                $d / 1000));
            continue;
        }
        $arcsFrom[$u][] = [$v, $cost];
        $arcsFrom[$v][] = [$u, $cost];
        $backFrom[$v][] = $u;
        $backFrom[$u][] = $v;
        $arcCount += 2;
        $added++;
        fwrite(STDERR, sprintf("  ferry: %.4f,%.4f to %.4f,%.4f  %.1f km, %d min\n",
            $lat[$u] / 1e7, $lon[$u] / 1e7, $lat[$v] / 1e7, $lon[$v] / 1e7,
            $d / 1000, round($cost / 600)));
    }
    return $added;
}

$ferries = add_ferries($country, $arcsFrom, $backFrom, $lat, $lon, $next,
                       $comp, $compSize, $arcCount);
fwrite(STDERR, sprintf("%d ferry crossings added\n", $ferries));

$keep = [];
$renum = array_fill(0, $next, -1);
$kept = 0;
for ($i = 0; $i < $next; $i++) {
    if ($compSize[$comp[$i]] >= MIN_COMPONENT) { $renum[$i] = $kept++; $keep[] = $i; }
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
unset($comp, $compSize, $renum, $keep, $newArcs, $newLat, $newLon, $backFrom);
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

/*
 * Written beside the live file and moved into place at the end.
 *
 * This used to open the graph the server is serving and truncate it. A build
 * that runs out of memory - and England's is a hundred and sixty megabytes,
 * held in PHP arrays before any of it is written - then leaves that country
 * unroutable, with a file that is the right name and the wrong length, until
 * somebody notices and builds it again. rename() on the same filesystem is
 * atomic, so a reader sees either the old graph or the new one.
 */
$finalPath = DATA_DIR . "/$country.graph";
$tmpPath = $finalPath . '.new';
$out = fopen($tmpPath, 'wb');
if ($out === false) { fwrite(STDERR, "cannot write $tmpPath\n"); exit(1); }
/*
 * The header's spare 16 bits carry the fastest speed in the graph, in km/h.
 *
 * A* is only guaranteed to find the best route if its estimate of what is
 * left never exceeds the truth, and that estimate is the straight-line
 * distance divided by the fastest anything can be driven. The router had 110
 * km/h written into it as a guess. This extract has 1,589 ways tagged 120 or
 * 130, so the guess was wrong and the search was not, strictly, finding the
 * best route - it happened to anyway on every pair tested, which is luck
 * rather than a property.
 *
 * Writing the real figure makes it a property, and makes it tight: a graph
 * whose fastest road is a 50 km/h town gets a much better estimate than any
 * constant a router could safely assume. The field was already there and
 * already zero, so a reader that ignores it is unaffected, and a reader that
 * sees zero knows it is looking at a graph built before this and can fall
 * back to something conservative.
 */
fwrite($out, 'WGR2');
fwrite($out, pack('CCn', 2, 0, $maxKmh > 65535 ? 65535 : $maxKmh));
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
// Same permissions the web server needs, before it becomes visible.
@chmod($tmpPath, 0644);
if (!rename($tmpPath, $finalPath)) {
    fwrite(STDERR, "could not move $tmpPath into place\n");
    @unlink($tmpPath);
    exit(1);
}

// Compressed here, once, rather than by the web server on demand. The data
// directory is not writable by it - and 36MB of deflate inside a request is
// a CGI timeout waiting to happen.
// Same care as the graph itself: this is what the watch downloads, and a
// half-written one is worse than an old one.
$gz = DATA_DIR . "/$country.graph.gz";
$gzTmp = $gz . '.new';
$fp = gzopen($gzTmp, 'wb6');
$in = fopen($finalPath, 'rb');
while (!feof($in)) { gzwrite($fp, fread($in, 1 << 20)); }
fclose($in);
gzclose($fp);
@chmod($gzTmp, 0644);
if (!rename($gzTmp, $gz)) {
    fwrite(STDERR, "could not move $gzTmp into place\n");
    @unlink($gzTmp);
    exit(1);
}

$size = filesize($finalPath);
fwrite(STDERR, sprintf("wrote %s.graph: %d nodes, %d arcs, %.1f MB (%.1f MB gzipped), %.0fs\n",
    $country, $next, $at, $size / 1048576, filesize($gz) / 1048576,
    microtime(true) - $t0));

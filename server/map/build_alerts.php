<?php
/**
 * The things beside the road, packed for the watch.
 *
 *     php build_alerts.php netherlands
 *
 * Speed cameras, level crossings and fuel, as points. None of them change how
 * a route is chosen - they are what the watch says while you drive along it -
 * so they live in their own small file rather than in the graph, and a watch
 * without one simply says nothing.
 *
 * <h3>Format: WAL1</h3>
 *
 *     "WAL1"  u8 version  u8 kinds  u16 names   u32 count
 *     f64 minx, miny, maxx, maxy      the area covered
 *     u32 cols, rows                  cell grid over that area
 *     u32 cell[cols*rows + 1]         first point of each cell
 *     per point: i32 lat, i32 lon (x1e7), u8 kind, u16 name
 *     then the name table: u8 length, then that many bytes, `names` times
 *
 * A name of 0xFFFF is none. The table is shared, so the several hundred
 * filling stations called Shell cost one string between them.
 *
 * Points are ordered by cell, so a cell's points are the ones between its
 * offset and the next - the same shape as the road graph's grid, for the same
 * reason: a watch looking for what is near it should read one range, not scan
 * a country.
 *
 * The Netherlands has 481 cameras. Even a continent's worth is a few hundred
 * kilobytes, so there is no attempt to compress it.
 */
require_once __DIR__ . '/lib.php';
ini_set('memory_limit', '2G');

const KIND_CAMERA   = 1;
const KIND_EXIT     = 2;    // motorway junction, 98% of them named
const KIND_FUEL     = 3;

/** Rather coarser than the graph's cells: there are four orders of magnitude
 *  fewer points, so a fine grid would be mostly empty offsets. */
const ALERT_CELL_DEG = 0.05;

$country = $argv[1] ?? '';
if ($country === '') { fwrite(STDERR, "usage: build_alerts.php <country>\n"); exit(1); }

require_once __DIR__ . '/layers.php';

/*
 * What is worth saying, and what is not.
 *
 * The traffic layer also holds 34,802 pedestrian crossings and 14,118 street
 * lamps for this country alone. Announcing those is not a feature; a warning
 * that fires constantly is one nobody hears when it matters.
 *
 * These three each earn their place. A camera is worth knowing about before
 * you reach it. A motorway junction carries the name on the sign - 98% of
 * them do - so the watch can say where the exit goes rather than which way to
 * turn off. Fuel is what you want to find when you need it rather than be
 * told about when you do not, so it is here to be searched, not announced.
 */
$want = [
    'speed_camera'      => KIND_CAMERA,
    'motorway_junction' => KIND_EXIT,
    'fuel'              => KIND_FUEL,
];

$pts = [];
$names = [];      // name => index into the string table
foreach (layer_points($country, 'traffic', array_keys($want), true) as $p) {
    [$la, $lo, $kind, $name] = $p;
    if (!is_finite($la) || !is_finite($lo)) { continue; }
    if ($la < -90 || $la > 90 || $lo < -180 || $lo > 180) { continue; }
    $ni = 0xFFFF;
    // A name costs two bytes a point and the table is shared, so the hundreds
    // of Shells cost one string between them.
    $name = trim($name);
    if ($name !== '' && strlen($name) <= 60) {
        if (!isset($names[$name])) {
            if (count($names) < 0xFFFF) { $names[$name] = count($names); }
        }
        if (isset($names[$name])) { $ni = $names[$name]; }
    }
    $pts[] = [$la, $lo, $want[$kind], $ni];
}
if (!$pts) { fwrite(STDERR, "no alert points for $country\n"); exit(1); }

$minx = $miny = INF; $maxx = $maxy = -INF;
foreach ($pts as [$la, $lo, $k, $ni]) {
    if ($lo < $minx) { $minx = $lo; }
    if ($lo > $maxx) { $maxx = $lo; }
    if ($la < $miny) { $miny = $la; }
    if ($la > $maxy) { $maxy = $la; }
}
$cols = max(1, (int) ceil(($maxx - $minx) / ALERT_CELL_DEG));
$rows = max(1, (int) ceil(($maxy - $miny) / ALERT_CELL_DEG));

$cell = [];
foreach ($pts as $i => [$la, $lo, $k, $ni]) {
    $cx = min($cols - 1, max(0, (int) (($lo - $minx) / ALERT_CELL_DEG)));
    $cy = min($rows - 1, max(0, (int) (($la - $miny) / ALERT_CELL_DEG)));
    $cell[$cy * $cols + $cx][] = $i;
}
ksort($cell);

$order = [];
$offset = array_fill(0, $cols * $rows + 1, 0);
$at = 0;
for ($c = 0; $c < $cols * $rows; $c++) {
    $offset[$c] = $at;
    foreach ($cell[$c] ?? [] as $i) { $order[] = $i; $at++; }
}
$offset[$cols * $rows] = $at;

$tmp = DATA_DIR . "/$country.alerts.new";
$out = fopen($tmp, 'wb');
fwrite($out, 'WAL1');
fwrite($out, pack('CCn', 1, count($want), count($names)));
fwrite($out, pack('N', count($order)));
fwrite($out, pack('E4', $minx, $miny, $maxx, $maxy));
fwrite($out, pack('NN', $cols, $rows));
foreach ($offset as $o) { fwrite($out, pack('N', $o)); }
$hist = [];
foreach ($order as $i) {
    [$la, $lo, $k, $ni] = $pts[$i];
    fwrite($out, pack('NN', ((int) round($la * 1e7)) & 0xFFFFFFFF,
                            ((int) round($lo * 1e7)) & 0xFFFFFFFF));
    fwrite($out, pack('Cn', $k, $ni));
    $hist[$k] = ($hist[$k] ?? 0) + 1;
}

// The string table last, so everything before it is fixed width and can be
// indexed without reading it.
foreach (array_keys($names) as $name) {
    fwrite($out, pack('C', strlen($name)));
    fwrite($out, $name);
}
fclose($out);
@chmod($tmp, 0644);
$final = DATA_DIR . "/$country.alerts";
if (!rename($tmp, $final)) { fwrite(STDERR, "could not move into place\n"); exit(1); }

$label = [KIND_CAMERA => 'speed cameras', KIND_EXIT => 'motorway exits',
          KIND_FUEL => 'fuel stations'];
ksort($hist);
foreach ($hist as $k => $n) { fwrite(STDERR, sprintf("  %-18s %d\n", $label[$k] ?? $k, $n)); }
fwrite(STDERR, sprintf("  %-18s %d\n", 'distinct names', count($names)));
fwrite(STDERR, sprintf("wrote %s.alerts: %d points, %d x %d cells, %.1f kB\n",
    $country, count($order), $cols, $rows, filesize($final) / 1024));

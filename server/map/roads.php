<?php
/**
 * The roads in a tile, as vectors the watch can draw and snap to.
 *
 *     roads.php?c=netherlands&z=15&x=16848&y=10814
 *
 * The raster tile gives the eye context; this gives the app geometry. It needs
 * both: a line drawn from vectors stays sharp when the view rotates or the
 * position moves between tile boundaries, and a route cannot be followed, nor
 * a position snapped to a street, from a picture.
 *
 * Binary rather than JSON. A tile of central Utrecht is a couple of thousand
 * segments, and parsing that as text on a 1 GHz watch costs more than the
 * radio time saved.
 *
 *   "WRD1"  u16 segments
 *   per segment:
 *     u8  class            1 motorway .. 10 path
 *     u8  name length      0 for none
 *     .. name, utf-8
 *     u16 points
 *     i32 lon, i32 lat     first point, degrees x 1e7
 *     (i16,i16) x n-1      deltas, degrees x 1e6
 *
 * All big-endian, which is what DataInputStream reads without being asked.
 */

require_once __DIR__ . '/lib.php';

$c = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['c'] ?? ''));
$z = (int) ($_GET['z'] ?? 15);
$x = (int) ($_GET['x'] ?? -1);
$y = (int) ($_GET['y'] ?? -1);

if ($z < 1 || $z > 18 || $x < 0 || $y < 0 || !store_exists($c)) {
    http_response_code(400);
    exit('bad request');
}

$db = open_store($c);
[$w, $s, $e, $n] = tile_bbox($z, $x, $y);
$mw = ($e - $w) * 0.15;
$mh = ($n - $s) * 0.15;
$segs = segments_in($db, $w - $mw, $s - $mh, $e + $mw, $n + $mh, $z);
$db->close();

// Most important first: if the watch has to stop early it should stop on the
// footpaths, not on the motorway.
usort($segs, function ($a, $b) { return $a['cls'] <=> $b['cls']; });
if (count($segs) > 4000) { $segs = array_slice($segs, 0, 4000); }

$body = '';
$written = 0;
foreach ($segs as $seg) {
    $pts = $seg['pts'];
    $n2 = count($pts);
    if ($n2 < 2 || $n2 > 65535) { continue; }

    // Names are deliberately not sent. At 240x240 there is nowhere to put one,
    // and the turn instructions say "turn left" rather than naming a street,
    // so carrying them would be bytes and storage spent on nothing.
    $name = '';

    $rec = pack('C', $seg['cls']) . pack('C', strlen($name)) . $name . pack('n', $n2);
    $rec .= pack('NN', enc32($pts[0][0]), enc32($pts[0][1]));

    $px = $pts[0][0]; $py = $pts[0][1];
    $ok = true;
    for ($i = 1; $i < $n2; $i++) {
        $dx = (int) round(($pts[$i][0] - $px) * 1e6);
        $dy = (int) round(($pts[$i][1] - $py) * 1e6);
        if ($dx < -32768 || $dx > 32767 || $dy < -32768 || $dy > 32767) {
            // A jump longer than 32 km inside one way: the store should never
            // hold one, and emitting a truncated delta would draw a line
            // across the country.
            $ok = false;
            break;
        }
        $rec .= pack('nn', $dx & 0xFFFF, $dy & 0xFFFF);
        $px += $dx / 1e6;
        $py += $dy / 1e6;
    }
    if (!$ok) { continue; }
    $body .= $rec;
    $written++;
}

$out = 'WRD1' . pack('n', $written) . $body;
header('Content-Type: application/octet-stream');
header('Content-Length: ' . strlen($out));
header('Cache-Control: public, max-age=2592000');
echo $out;

/** Degrees to a big-endian int32 at 1e7, two's complement. */
function enc32(float $deg): int {
    return ((int) round($deg * 1e7)) & 0xFFFFFFFF;
}

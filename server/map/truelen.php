<?php
/**
 * The real length of a route, as opposed to the length the graph can report.
 *
 * The graph stores a node only where ways meet, so an arc is a chord across
 * however many bends the road makes in between. That is right for the search
 * - the arc's cost was measured along the ground when the graph was built -
 * but any distance computed from node coordinates afterwards is short. Across
 * the Dutch network the chords are 4.9% short in total, and the worst single
 * arc is 12 km short.
 *
 * This walks the shapefile again and records how long each arc really is, so
 * a benchmark can report what a driver's odometer would show. Two passes over
 * a 700 MB shapefile and a table of three million entries: this belongs on
 * the server, and exists to tell us how wrong the cheap number is. The watch
 * keeps using the graph.
 *
 * The passes below must stay identical to build_graph.php's, or the arcs will
 * not line up: same fclass filter, same "seen twice, endpoints count double"
 * node rule. When they diverge, lookups miss and the caller silently falls
 * back to chords - which is exactly the error being measured.
 */
require_once __DIR__ . '/lib.php';
ini_set('memory_limit', '8G');

if (!function_exists('key_of')) {
    function key_of(float $lon, float $lat): int {
        return ((int) round($lon * 1e7)) * 4000000000 + ((int) round($lat * 1e7));
    }
}

/** Walk every drivable way, handing each record's points to $fn. */
function _shp_walk(string $src, callable $fn): void {
    $speeds = drive_speeds();
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
    $shp = fopen("$src.shp", 'rb');
    fseek($shp, 24);
    $fileLen = unpack('Nlen', fread($shp, 4))['len'] * 2;
    fseek($shp, 100);
    $rec = 0;
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
        $fn(unpack('d' . ($n * 2), substr($content, 44 + $hdr['parts'] * 4, $n * 16)), $n);
    }
    fclose($shp); fclose($dbf);
}

if (!function_exists('attrs')) {
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
}

/** @return array<string,float> "keyA,keyB" (ascending) => metres along the road */
function arc_lengths(string $country): array {
    $src = DATA_DIR . "/$country/gis_osm_roads_free_1";

    $seen = [];
    _shp_walk($src, function (array $raw, int $n) use (&$seen) {
        for ($i = 0; $i < $n; $i++) {
            $k = key_of($raw[$i*2+1], $raw[$i*2+2]);
            $seen[$k] = ($seen[$k] ?? 0) + (($i === 0 || $i === $n-1) ? 2 : 1);
        }
    });
    $node = [];
    foreach ($seen as $k => $c) { if ($c >= 2) { $node[$k] = 1; } }
    unset($seen);

    $len = [];
    _shp_walk($src, function (array $raw, int $n) use (&$len, $node) {
        $run = 0.0; $px = null; $py = null; $ak = null;
        for ($i = 0; $i < $n; $i++) {
            $x = $raw[$i*2+1]; $y = $raw[$i*2+2];
            if ($px !== null) { $run += ground($py, $px, $y, $x); }
            $px = $x; $py = $y;
            $k = key_of($x, $y);
            if (!isset($node[$k])) { continue; }
            if ($ak !== null && $ak !== $k && $run > 0) {
                // Unordered: an arc is the same road whichever way it is driven.
                $pair = $ak < $k ? "$ak,$k" : "$k,$ak";
                // Two ways between the same pair of junctions do happen - a
                // dual carriageway modelled as one link, a service loop. The
                // search would have taken the cheaper, so keep the shorter.
                if (!isset($len[$pair]) || $run < $len[$pair]) { $len[$pair] = $run; }
            }
            $ak = $k; $run = 0.0;
        }
    });
    return $len;
}

/** Cached: two passes over 700 MB is not a per-run cost. */
function arc_lengths_cached(string $country): array {
    $f = DATA_DIR . "/$country.arclen";
    if (is_file($f)) {
        $t = @unserialize(file_get_contents($f));
        if (is_array($t)) { return $t; }
    }
    $t = arc_lengths($country);
    file_put_contents($f, serialize($t));
    return $t;
}

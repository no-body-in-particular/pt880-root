<?php
/**
 * The point layers: what sits beside a road rather than being one.
 *
 * OpenStreetMap keeps signals, cameras, fuel and level crossings as points in
 * their own layer, not as attributes of the way they are on. Two consumers
 * need them and must read them the same way - build_graph, which charges a
 * signalised junction what it is worth, and build_alerts, which packs the
 * ones the watch announces - so they live here rather than in either.
 */
require_once __DIR__ . '/lib.php';

/**
 * Points of one class from a shapefile layer, as [lat, lon].
 *
 * The traffic layer is where OpenStreetMap keeps the things that are on a
 * road rather than part of one: signals, cameras, fuel, motorway junctions.
 *
 * With $withName, each point carries its name as a fourth element. Only some
 * layers have useful ones - a motorway junction is named after where the exit
 * goes, which is what the sign says and what a driver is looking for; a
 * traffic signal is never named at all.
 */
function layer_points(string $country, string $layer, array $classes,
                      bool $withName = false): array {
    $src = DATA_DIR . "/$country/gis_osm_${layer}_free_1";
    if (!is_file("$src.shp") || !is_file("$src.dbf")) { return []; }

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
    if (!isset($fields['fclass'])) { fclose($dbf); return []; }
    $nameField = ($withName && isset($fields['name'])) ? $fields['name'] : null;
    $want = [];
    $names = [];
    fseek($dbf, $hd['headerLen']);
    for ($i = 1; $i <= $hd['records']; $i++) {
        $row = fread($dbf, $hd['recordLen']);
        if ($row === false || strlen($row) < $hd['recordLen']) { break; }
        $v = trim(substr($row, $fields['fclass']['off'], $fields['fclass']['len']));
        if (!in_array($v, $classes, true)) { continue; }
        $want[$i] = $v;
        if ($nameField !== null) {
            $names[$i] = trim(substr($row, $nameField['off'], $nameField['len']));
        }
    }
    fclose($dbf);
    if (!$want) { return []; }

    $shp = fopen("$src.shp", 'rb');
    $head = fread($shp, 100);
    $fileLen = unpack('N', substr($head, 24, 4))[1] * 2;
    $out = [];
    $rec = 0;
    while (ftell($shp) < $fileLen) {
        $rh = fread($shp, 8);
        if ($rh === false || strlen($rh) < 8) { break; }
        $r = unpack('Nnum/Nlen', $rh);
        $c = fread($shp, $r['len'] * 2);
        if ($c === false || strlen($c) < 4) { break; }
        $rec++;
        if (unpack('Vt', substr($c, 0, 4))['t'] !== 1) { continue; }    // point
        if (!isset($want[$rec])) { continue; }
        $xy = unpack('dx/dy', substr($c, 4, 16));
        $out[] = $withName
            ? [$xy['y'], $xy['x'], $want[$rec], $names[$rec] ?? '']
            : [$xy['y'], $xy['x'], $want[$rec]];
    }
    fclose($shp);
    return $out;
}

/**
 * Which node each point belongs to, by proximity.
 *
 * Exact coordinates will not do. A traffic signal is a node on a way, and the
 * graph only keeps a node where ways meet, so 94% of them have no graph node
 * at their coordinates at all - and OpenStreetMap puts a junction's signals
 * on its approach arms rather than in its middle, a few tens of metres out.
 *
 * @return array node id => list of classes found near it
 */
function snap_points(array $pts, array $lat, array $lon, int $next, float $within): array {
    $grid = [];
    for ($i = 0; $i < $next; $i++) {
        $grid[((int) floor($lon[$i] / 1e4)) * 100000000 + (int) floor($lat[$i] / 1e4)][] = $i;
    }
    $at = [];
    foreach ($pts as [$tla, $tlo, $kind]) {
        $gx = (int) floor($tlo * 1e7 / 1e4);
        $gy = (int) floor($tla * 1e7 / 1e4);
        $best = -1; $bd = INF;
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                foreach ($grid[($gx + $dx) * 100000000 + ($gy + $dy)] ?? [] as $n) {
                    $d = ground($lat[$n] / 1e7, $lon[$n] / 1e7, $tla, $tlo);
                    if ($d < $bd) { $bd = $d; $best = $n; }
                }
            }
        }
        if ($best >= 0 && $bd <= $within) { $at[$best][] = $kind; }
    }
    return $at;
}

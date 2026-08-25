<?php
/**
 * Railways and waterways into the segment table.
 *
 *     php import_lines.php netherlands
 *
 * Same shape of data as roads - a line with a class - so they share the seg
 * table and road_classes(), which carries entries for them. They are given
 * class codes above every road so that render_tile, which draws in
 * descending code order, puts them underneath.
 *
 * Appends. Roads are not touched, so this can be run against a store that
 * already has them without a two hour reimport.
 */
require_once __DIR__ . '/lib.php';

$country = $argv[1] ?? 'netherlands';
$dbPath = DATA_DIR . "/$country.db";
if (!is_file($dbPath)) { fwrite(STDERR, "no store at $dbPath\n"); exit(1); }

$classes = road_classes();
$db = new SQLite3($dbPath);
$db->exec('PRAGMA journal_mode = OFF');
$db->exec('PRAGMA synchronous = OFF');

// Anything from a previous run of this script, so it is repeatable.
$db->exec('DELETE FROM seg WHERE cls IN (11, 12)');

$ins = $db->prepare('INSERT INTO seg(cx, cy, cls, minzoom, name, geom)
                     VALUES(?, ?, ?, ?, NULL, ?)');
$kept = 0; $rows = 0; $skipped = 0;
$t0 = microtime(true);
$db->exec('BEGIN');

foreach (['gis_osm_railways_free_1', 'gis_osm_waterways_free_1'] as $base) {
    $shpPath = DATA_DIR . "/$country/$base.shp";
    if (!is_file($shpPath)) { fwrite(STDERR, "skip $base\n"); continue; }

    $dbf = fopen(DATA_DIR . "/$country/$base.dbf", 'rb');
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

    $shp = fopen($shpPath, 'rb');
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
        if (unpack('Vt', substr($content, 0, 4))['t'] !== 3) { $skipped++; continue; }

        fseek($dbf, $hd['headerLen'] + ($rec - 1) * $hd['recordLen']);
        $arow = fread($dbf, $hd['recordLen']);
        if ($arow === false || strlen($arow) < $hd['recordLen']) { break; }
        $fc = rtrim(substr($arow, $fields['fclass']['off'], $fields['fclass']['len']));
        if (!isset($classes[$fc])) { $skipped++; continue; }
        [$code, $width, $colour, $minzoom] = $classes[$fc];
        if ($code < 11) { $skipped++; continue; }        // a road, not ours

        $hdr = unpack('dxmin/dymin/dxmax/dymax/Vparts/Vpoints', substr($content, 4, 40));
        $numParts = $hdr['parts'];
        $numPoints = $hdr['points'];
        if ($numPoints < 2) { $skipped++; continue; }
        $partsOff = 44;
        $ptsOff = $partsOff + $numParts * 4;
        $parts = array_values(unpack('V' . $numParts, substr($content, $partsOff, $numParts * 4)));
        $parts[] = $numPoints;

        for ($p = 0; $p < $numParts; $p++) {
            $n = $parts[$p + 1] - $parts[$p];
            if ($n < 2) { continue; }
            $raw = unpack('d' . ($n * 2), substr($content, $ptsOff + $parts[$p] * 16, $n * 16));
            $pts = [];
            for ($i = 0; $i < $n; $i++) { $pts[] = [$raw[$i * 2 + 1], $raw[$i * 2 + 2]]; }
            $pts = simplify($pts, 4.0);
            if (count($pts) < 2) { continue; }

            $geom = pack_geom($pts);
            foreach (cells_for($pts) as [$cx, $cy]) {
                $ins->bindValue(1, $cx, SQLITE3_INTEGER);
                $ins->bindValue(2, $cy, SQLITE3_INTEGER);
                $ins->bindValue(3, $code, SQLITE3_INTEGER);
                $ins->bindValue(4, $minzoom, SQLITE3_INTEGER);
                $ins->bindValue(5, $geom, SQLITE3_BLOB);
                $ins->execute(); $ins->reset();
                $rows++;
            }
            $kept++;
        }
    }
    fclose($shp); fclose($dbf);
    fwrite(STDERR, sprintf("%s: %d records\n", $base, $rec));
}

$db->exec('COMMIT');
$db->close();
fwrite(STDERR, sprintf("done: %d lines, %d cell rows, %d skipped, %.0fs\n",
    $kept, $rows, $skipped, microtime(true) - $t0));

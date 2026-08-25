<?php
/**
 * Ground cover - forest, park, farmland, water - into the road store.
 *
 *     php import_areas.php netherlands
 *
 * Unlike buildings these cannot be reduced to bounding boxes: a house is
 * three pixels and its box is within one of its outline, but a forest's box
 * is not remotely its shape. So these carry real geometry, simplified to
 * about two pixels at the zoom they are drawn at.
 *
 * Polygons are filed by the cells they touch, as the roads are. A polygon
 * touching more than a few dozen cells goes to a separate table instead and
 * is found by bounding box - otherwise one lake the size of the IJsselmeer
 * would be copied into hundreds of rows.
 */
require_once __DIR__ . '/lib.php';

ini_set('memory_limit', '3G');

$country = $argv[1] ?? 'netherlands';
$dbPath = DATA_DIR . "/$country.db";
if (!is_file($dbPath)) { fwrite(STDERR, "no store at $dbPath\n"); exit(1); }

/** Ten metres: about two pixels at z15, below which detail cannot be seen. */
const AREA_TOLERANCE_M = 10.0;

/** Square metres below which a patch is not worth drawing. */
const MIN_AREA_M2 = 2000.0;

/** More cells than this and the polygon is filed by bounding box instead. */
const MAX_CELLS = 24;

$layers = [
    ['gis_osm_water_a_free_1',   'water'],     // no dbf: all of it is water
    ['gis_osm_landuse_a_free_1', 'fclass'],
    ['gis_osm_natural_a_free_1', 'fclass'],
];

$classes = area_classes();

$db = new SQLite3($dbPath);
$db->exec('PRAGMA journal_mode = OFF');
$db->exec('PRAGMA synchronous = OFF');
$db->exec('DROP TABLE IF EXISTS area');
$db->exec('DROP TABLE IF EXISTS bigarea');
$db->exec('CREATE TABLE area(cx INTEGER, cy INTEGER, cls INTEGER, geom BLOB)');
$db->exec('CREATE TABLE bigarea(cls INTEGER, minx REAL, miny REAL,
                                maxx REAL, maxy REAL, geom BLOB)');
$ins = $db->prepare('INSERT INTO area(cx, cy, cls, geom) VALUES(?, ?, ?, ?)');
$insBig = $db->prepare('INSERT INTO bigarea(cls, minx, miny, maxx, maxy, geom)
                        VALUES(?, ?, ?, ?, ?, ?)');

$kept = 0; $big = 0; $rows = 0; $skipped = 0;
$t0 = microtime(true);
$db->exec('BEGIN');

foreach ($layers as [$base, $how]) {
    $shpPath = DATA_DIR . "/$country/$base.shp";
    if (!is_file($shpPath)) { fwrite(STDERR, "skip $base (not extracted)\n"); continue; }

    // Attributes, where the class has to be read rather than assumed.
    $dbf = null; $fields = []; $dbfHeaderLen = 0; $dbfRecordLen = 0;
    if ($how === 'fclass') {
        $dbf = fopen(DATA_DIR . "/$country/$base.dbf", 'rb');
        $h = fread($dbf, 32);
        $hd = unpack('Vrecords/vheaderLen/vrecordLen', substr($h, 4, 8));
        $dbfHeaderLen = $hd['headerLen'];
        $dbfRecordLen = $hd['recordLen'];
        $off = 1;
        while (true) {
            $d = fread($dbf, 32);
            if ($d === false || ord($d[0]) === 0x0D) { break; }
            $nm = rtrim(substr($d, 0, 11), "\0");
            $len = ord($d[16]);
            $fields[$nm] = ['off' => $off, 'len' => $len];
            $off += $len;
        }
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
        $contentLen = $r['len'] * 2;
        $content = fread($shp, $contentLen);
        if ($content === false || strlen($content) < 44) { break; }
        $rec++;

        if (unpack('Vt', substr($content, 0, 4))['t'] !== 5) { $skipped++; continue; }

        $cls = 1;                                     // water layer default
        if ($how === 'fclass') {
            fseek($dbf, $dbfHeaderLen + ($rec - 1) * $dbfRecordLen);
            $arow = fread($dbf, $dbfRecordLen);
            if ($arow === false || strlen($arow) < $dbfRecordLen) { break; }
            $fc = rtrim(substr($arow, $fields['fclass']['off'], $fields['fclass']['len']));
            if (!isset($classes[$fc])) { $skipped++; continue; }
            $cls = $classes[$fc];
        }

        $hdr = unpack('dxmin/dymin/dxmax/dymax/Vparts/Vpoints', substr($content, 4, 40));
        $midY = ($hdr['ymin'] + $hdr['ymax']) / 2;
        $mx = ($hdr['xmax'] - $hdr['xmin']) * 111320 * cos(deg2rad($midY));
        $my = ($hdr['ymax'] - $hdr['ymin']) * 110540;
        if ($mx * $my < MIN_AREA_M2) { $skipped++; continue; }

        $numParts = $hdr['parts'];
        $numPoints = $hdr['points'];
        $partsOff = 44;
        $ptsOff = $partsOff + $numParts * 4;
        $parts = array_values(unpack('V' . $numParts, substr($content, $partsOff, $numParts * 4)));
        $parts[] = $numPoints;

        // Only the outer ring. Holes would need a second pass and a hole in a
        // wood is smaller than the ten metres this is simplified to anyway.
        $n = $parts[1] - $parts[0];
        if ($n < 4) { $skipped++; continue; }
        $raw = unpack('d' . ($n * 2), substr($content, $ptsOff + $parts[0] * 16, $n * 16));
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $pts[] = [$raw[$i * 2 + 1], $raw[$i * 2 + 2]];
        }
        $pts = simplify($pts, AREA_TOLERANCE_M);
        if (count($pts) < 4) { $skipped++; continue; }

        $geom = pack_geom($pts);
        $cells = cells_for($pts);
        $kept++;

        if (count($cells) > MAX_CELLS) {
            $insBig->bindValue(1, $cls, SQLITE3_INTEGER);
            $insBig->bindValue(2, $hdr['xmin']); $insBig->bindValue(3, $hdr['ymin']);
            $insBig->bindValue(4, $hdr['xmax']); $insBig->bindValue(5, $hdr['ymax']);
            $insBig->bindValue(6, $geom, SQLITE3_BLOB);
            $insBig->execute(); $insBig->reset();
            $big++;
        } else {
            foreach ($cells as [$cx, $cy]) {
                $ins->bindValue(1, $cx, SQLITE3_INTEGER);
                $ins->bindValue(2, $cy, SQLITE3_INTEGER);
                $ins->bindValue(3, $cls, SQLITE3_INTEGER);
                $ins->bindValue(4, $geom, SQLITE3_BLOB);
                $ins->execute(); $ins->reset();
                $rows++;
            }
        }

        if (($kept % 50000) === 0) {
            fwrite(STDERR, sprintf("\r%s: %d kept, %d big, %d rows, %.0fs   ",
                $base, $kept, $big, $rows, microtime(true) - $t0));
        }
    }
    fclose($shp);
    if ($dbf) { fclose($dbf); }
    fwrite(STDERR, sprintf("\n%s: %d records read\n", $base, $rec));
}

$db->exec('COMMIT');
$db->exec('CREATE INDEX area_cell ON area(cx, cy)');
$db->exec('CREATE INDEX bigarea_box ON bigarea(minx, maxx)');
$db->exec("INSERT OR REPLACE INTO meta(k, v) VALUES('areas', '$kept')");
$db->close();

fwrite(STDERR, sprintf("done: %d polygons (%d big), %d cell rows, %d skipped, %.0fs\n",
    $kept, $big, $rows, $skipped, microtime(true) - $t0));

<?php
/**
 * Building footprints into the road store.
 *
 *     php import_buildings.php netherlands data/$country/gis_osm_buildings_a_free_1.shp
 *
 * Three decisions, all forced by the screen this ends up on.
 *
 * A footprint is stored as its bounding box, not its outline. At z15 a pixel
 * is about five metres, so a house is two or three pixels across and the
 * difference between its shape and its box is well under one. The box is in
 * the shapefile's own record header, which also means the points never have
 * to be parsed - ten and a half million records go by at the speed of the
 * disk rather than of PHP.
 *
 * Boxes are packed together by cell rather than stored a row each. Ten
 * million rows would be a gigabyte of SQLite for eighty megabytes of numbers;
 * grouped into the same 0.01 degree cells the roads already use, it is one
 * blob of a few kilobytes per cell and one query per tile.
 *
 * Anything under a few tens of square metres is dropped. A garden shed is a
 * quarter of a pixel, and there are a great many of them.
 */
require_once __DIR__ . '/lib.php';

ini_set('memory_limit', '2G');

$country = $argv[1] ?? 'netherlands';
$shpPath = $argv[2] ?? (DATA_DIR . "/$country/gis_osm_buildings_a_free_1.shp");

/** Square metres below which a building is not worth a pixel. */
const MIN_AREA_M2 = 40.0;

/** Sub-cell resolution: 0.01 degrees over 65536 steps is under two
 *  centimetres, far finer than anything here needs. */
const STEPS = 65536;

$dbPath = DATA_DIR . "/$country.db";
if (!is_file($dbPath)) { fwrite(STDERR, "no store at $dbPath\n"); exit(1); }
$shp = fopen($shpPath, 'rb');
if (!$shp) { fwrite(STDERR, "cannot open $shpPath\n"); exit(1); }

fseek($shp, 24);
$fileLen = unpack('Nlen', fread($shp, 4))['len'] * 2;
fseek($shp, 100);
fwrite(STDERR, sprintf("%s: %.1f GB of polygons\n", basename($shpPath), $fileLen / 1e9));

$cells = [];          // "cx,cy" => packed boxes
$kept = 0; $small = 0; $rec = 0;
$t0 = microtime(true);

while (ftell($shp) < $fileLen) {
    $rh = fread($shp, 8);
    if ($rh === false || strlen($rh) < 8) { break; }
    $r = unpack('Nnum/Nlen', $rh);
    $contentLen = $r['len'] * 2;
    if ($contentLen < 44) { fseek($shp, $contentLen, SEEK_CUR); continue; }

    // Type and bounding box only; the points are skipped without being read.
    $head = fread($shp, 44);
    fseek($shp, $contentLen - 44, SEEK_CUR);
    $rec++;

    $type = unpack('Vt', substr($head, 0, 4))['t'];
    if ($type !== 5) { continue; }                       // not a polygon

    $b = unpack('dxmin/dymin/dxmax/dymax', substr($head, 4, 32));
    $cx0 = ($b['xmin'] + $b['xmax']) / 2;
    $cy0 = ($b['ymin'] + $b['ymax']) / 2;

    $mx = ($b['xmax'] - $b['xmin']) * 111320 * cos(deg2rad($cy0));
    $my = ($b['ymax'] - $b['ymin']) * 110540;
    if ($mx * $my < MIN_AREA_M2) { $small++; continue; }

    $cx = (int) floor($cx0 / CELL_DEG);
    $cy = (int) floor($cy0 / CELL_DEG);
    $ox = $cx * CELL_DEG;
    $oy = $cy * CELL_DEG;

    $q = function (float $v, float $origin): int {
        $n = (int) round((($v - $origin) / CELL_DEG) * STEPS);
        return $n < 0 ? 0 : ($n > STEPS - 1 ? STEPS - 1 : $n);
    };

    $cells["$cx,$cy"] = ($cells["$cx,$cy"] ?? '') . pack('vvvv',
        $q($b['xmin'], $ox), $q($b['ymin'], $oy),
        $q($b['xmax'], $ox), $q($b['ymax'], $oy));
    $kept++;

    if (($rec % 250000) === 0) {
        fwrite(STDERR, sprintf("\r%.0fM records, %.0fM kept, %d cells, %.0fs   ",
            $rec / 1e6, $kept / 1e6, count($cells), microtime(true) - $t0));
    }
}
fclose($shp);
fwrite(STDERR, sprintf("\n%d records: %d kept, %d too small, %d cells, %.0fs\n",
    $rec, $kept, $small, count($cells), microtime(true) - $t0));

$db = new SQLite3($dbPath);
$db->exec('PRAGMA journal_mode = OFF');
$db->exec('PRAGMA synchronous = OFF');
$db->exec('DROP TABLE IF EXISTS bldg');
$db->exec('CREATE TABLE bldg(cx INTEGER, cy INTEGER, boxes BLOB)');
$db->exec('BEGIN');
$ins = $db->prepare('INSERT INTO bldg(cx, cy, boxes) VALUES(?, ?, ?)');
foreach ($cells as $key => $blob) {
    [$cx, $cy] = array_map('intval', explode(',', $key));
    $ins->bindValue(1, $cx, SQLITE3_INTEGER);
    $ins->bindValue(2, $cy, SQLITE3_INTEGER);
    $ins->bindValue(3, $blob, SQLITE3_BLOB);
    $ins->execute();
    $ins->reset();
}
$db->exec('COMMIT');
$db->exec('CREATE INDEX bldg_cell ON bldg(cx, cy)');
$db->exec("INSERT OR REPLACE INTO meta(k, v) VALUES('buildings', '$kept')");
$db->close();

fwrite(STDERR, sprintf("stored %d buildings in %d cells, %.0fs total\n",
    $kept, count($cells), microtime(true) - $t0));

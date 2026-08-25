<?php
/**
 * Turn a Geofabrik roads shapefile into the watch's road store.
 *
 *     php import.php netherlands data/$country/gis_osm_roads_free_1
 *
 * Geofabrik's "free" shapefile extract is used rather than the .osm.pbf
 * because the roads are already separated out and a shapefile is a documented
 * binary format a hundred lines can read, where PBF is protobuf inside zlib
 * and needs a library this server does not have.
 *
 * The store is one SQLite file per country, bucketed into a fixed grid so a
 * tile request reads a handful of rows rather than searching a country. Each
 * way is stored once per cell it touches; ways are short - two million of them
 * across a few hundred thousand kilometres - so that duplication is small.
 *
 * Geometry is simplified to four metres on the way in. A tile pixel at the
 * zooms this serves is several metres across, so finer detail cannot be drawn,
 * and carrying it would cost the watch storage it has to spend on area
 * instead.
 */

require_once __DIR__ . '/lib.php';

ini_set('memory_limit', '512M');

if ($argc < 3) {
    fwrite(STDERR, "usage: php import.php <country> <path/to/gis_osm_roads_free_1>\n");
    exit(1);
}
$country = preg_replace('/[^a-z0-9_-]/', '', strtolower($argv[1]));
$base = $argv[2];

$shpPath = $base . '.shp';
$dbfPath = $base . '.dbf';
foreach ([$shpPath, $dbfPath] as $f) {
    if (!is_file($f)) { fwrite(STDERR, "missing $f\n"); exit(1); }
}

// ---------------------------------------------------------------- dbf

$dbf = fopen($dbfPath, 'rb');
$h = fread($dbf, 32);
$hd = unpack('Vrecords/vheaderLen/vrecordLen', substr($h, 4, 8));
$dbfRecords = $hd['records'];
$dbfHeaderLen = $hd['headerLen'];
$dbfRecordLen = $hd['recordLen'];

$fields = [];
$offset = 1;                       // past the deletion flag
fseek($dbf, 32);
while (true) {
    $d = fread($dbf, 32);
    if ($d === false || strlen($d) < 32 || $d[0] === "\r") { break; }
    $name = rtrim(substr($d, 0, 11), "\0");
    $len = ord($d[16]);
    $fields[$name] = ['off' => $offset, 'len' => $len];
    $offset += $len;
}
foreach (['fclass', 'name'] as $need) {
    if (!isset($fields[$need])) { fwrite(STDERR, "dbf has no $need column\n"); exit(1); }
}
fwrite(STDERR, "dbf: $dbfRecords records, {$dbfRecordLen} bytes each\n");

// ---------------------------------------------------------------- store

@mkdir(DATA_DIR, 0755, true);
$dbPath = DATA_DIR . '/' . $country . '.db';
@unlink($dbPath);
$db = new SQLite3($dbPath);
$db->exec('PRAGMA journal_mode=OFF');
$db->exec('PRAGMA synchronous=OFF');
$db->exec('PRAGMA cache_size=-64000');
$db->exec('CREATE TABLE seg(
              cx INTEGER, cy INTEGER, cls INTEGER, minzoom INTEGER,
              name TEXT, geom BLOB)');
$db->exec('CREATE TABLE meta(k TEXT PRIMARY KEY, v TEXT)');

$ins = $db->prepare('INSERT INTO seg(cx,cy,cls,minzoom,name,geom)
                     VALUES(:cx,:cy,:cls,:mz,:nm,:g)');

// ---------------------------------------------------------------- shp

$shp = fopen($shpPath, 'rb');
fseek($shp, 100);                  // past the file header

$kept = 0; $skipped = 0; $rows = 0; $rec = 0;
$minx = 180; $miny = 90; $maxx = -180; $maxy = -90;

$db->exec('BEGIN');

while (!feof($shp)) {
    $rh = fread($shp, 8);
    if ($rh === false || strlen($rh) < 8) { break; }
    $r = unpack('Nnum/Nlen', $rh);
    $content = fread($shp, $r['len'] * 2);
    if ($content === false || strlen($content) < 4) { break; }
    $rec++;

    $type = unpack('Vt', substr($content, 0, 4))['t'];
    if ($type !== 3) { $skipped++; continue; }        // not a polyline

    // The attributes for this record sit at the same ordinal in the dbf.
    fseek($dbf, $dbfHeaderLen + ($rec - 1) * $dbfRecordLen);
    $arow = fread($dbf, $dbfRecordLen);
    if ($arow === false || strlen($arow) < $dbfRecordLen) { break; }
    $fclass = rtrim(substr($arow, $fields['fclass']['off'], $fields['fclass']['len']));
    $name = rtrim(substr($arow, $fields['name']['off'], $fields['name']['len']));

    $cls = road_class($fclass);
    if ($cls === null) { $skipped++; continue; }
    [$code, $width, $grey, $minzoom] = $cls;

    $hdr = unpack('dxmin/dymin/dxmax/dymax/Vparts/Vpoints', substr($content, 4, 40));
    $numParts = $hdr['parts'];
    $numPoints = $hdr['points'];
    if ($numPoints < 2) { $skipped++; continue; }

    $partsOff = 44;
    $ptsOff = $partsOff + $numParts * 4;
    $parts = array_values(unpack('V' . $numParts, substr($content, $partsOff, $numParts * 4)));
    $parts[] = $numPoints;

    for ($p = 0; $p < $numParts; $p++) {
        $from = $parts[$p];
        $to = $parts[$p + 1];
        $n = $to - $from;
        if ($n < 2) { continue; }

        $raw = unpack('d' . ($n * 2), substr($content, $ptsOff + $from * 16, $n * 16));
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $pts[] = [$raw[$i * 2 + 1], $raw[$i * 2 + 2]];   // lon, lat
        }
        $pts = simplify($pts, 4.0);
        if (count($pts) < 2) { continue; }

        $geom = pack_geom($pts);
        $storeName = ($code <= 5 && $name !== '') ? $name : null;

        foreach (cells_for($pts) as $cell) {
            $ins->bindValue(':cx', $cell[0], SQLITE3_INTEGER);
            $ins->bindValue(':cy', $cell[1], SQLITE3_INTEGER);
            $ins->bindValue(':cls', $code, SQLITE3_INTEGER);
            $ins->bindValue(':mz', $minzoom, SQLITE3_INTEGER);
            $ins->bindValue(':nm', $storeName, $storeName === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $ins->bindValue(':g', $geom, SQLITE3_BLOB);
            $ins->execute();
            $ins->reset();
            $rows++;
        }
        foreach ($pts as $pt) {
            if ($pt[0] < $minx) { $minx = $pt[0]; }
            if ($pt[0] > $maxx) { $maxx = $pt[0]; }
            if ($pt[1] < $miny) { $miny = $pt[1]; }
            if ($pt[1] > $maxy) { $maxy = $pt[1]; }
        }
        $kept++;
    }

    if (($rec % 100000) === 0) {
        $db->exec('COMMIT');
        $db->exec('BEGIN');
        fwrite(STDERR, sprintf("  %d/%d records, %d ways, %d rows\n",
                $rec, $dbfRecords, $kept, $rows));
    }
}
$db->exec('COMMIT');
fclose($shp);
fclose($dbf);

fwrite(STDERR, "indexing...\n");
$db->exec('CREATE INDEX seg_cell ON seg(cx, cy, minzoom)');

foreach ([['country', $country], ['minx', $minx], ['miny', $miny],
          ['maxx', $maxx], ['maxy', $maxy], ['ways', $kept],
          ['rows', $rows], ['built', gmdate('c')]] as $kv) {
    $st = $db->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(:k,:v)');
    $st->bindValue(':k', $kv[0], SQLITE3_TEXT);
    $st->bindValue(':v', (string) $kv[1], SQLITE3_TEXT);
    $st->execute();
}
$db->close();

printf("%s: %d ways, %d rows, bbox %.4f %.4f %.4f %.4f, %.0f MB\n",
        $country, $kept, $rows, $minx, $miny, $maxx, $maxy,
        filesize($dbPath) / 1048576);

// ---------------------------------------------------------------- helpers



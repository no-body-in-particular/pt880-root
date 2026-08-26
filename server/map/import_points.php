<?php
/**
 * Speed cameras, motorway junctions and fuel into the store.
 *
 *     php import_points.php netherlands
 *
 * These are the things beside the road rather than the road itself: nothing
 * here changes which way to go, it is what the watch says while going that
 * way. They used to be packed into their own file by a build step. They are
 * points with a cell and a class, which is what every other table here holds,
 * so they belong in the store with the rest - and then mapd can answer for any
 * box of them without a country's worth being precomputed, kept in step and
 * downloaded whole.
 *
 * Appends into its own table, so this can be run against a store that already
 * has roads without a two hour reimport.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/layers.php';

/** Kept in step with Alerts.java on the watch and alerts() in mapd. */
const PT_CAMERA = 1;
const PT_EXIT   = 2;
const PT_FUEL   = 3;

/*
 * What is worth carrying, and what is not.
 *
 * The same layer holds 34,802 pedestrian crossings and 14,118 street lamps for
 * the Netherlands alone. Announcing those is not a feature: a warning that
 * fires constantly is one nobody hears when it matters.
 *
 * These three earn their place. A camera is worth knowing about before you
 * reach it. A motorway junction carries the name on the sign - 98% of them do
 * - so the watch can say where the exit goes rather than which way to turn
 * off. Fuel is for finding when you need it, not for being told about.
 */
const PT_CLASSES = [
    'speed_camera'      => PT_CAMERA,
    'motorway_junction' => PT_EXIT,
    'fuel'              => PT_FUEL,
];

$country = $argv[1] ?? '';
if ($country === '') { fwrite(STDERR, "usage: import_points.php <country>\n"); exit(1); }
$dbPath = DATA_DIR . "/$country.db";
if (!is_file($dbPath)) { fwrite(STDERR, "no store at $dbPath\n"); exit(1); }

$db = new SQLite3($dbPath);
$db->exec('PRAGMA journal_mode = OFF');
$db->exec('PRAGMA synchronous = OFF');
$db->exec('CREATE TABLE IF NOT EXISTS pt(
               cx INTEGER, cy INTEGER, kind INTEGER,
               lat REAL, lon REAL, name TEXT)');
$db->exec('CREATE INDEX IF NOT EXISTS pt_cell ON pt(cx, cy)');
$db->exec('DELETE FROM pt');

$pts = layer_points($country, 'traffic', array_keys(PT_CLASSES), true);
if (!$pts) {
    fwrite(STDERR, "no traffic layer for $country; no points imported\n");
    exit(0);            // not an error: a country may simply not have one
}

$ins = $db->prepare('INSERT INTO pt(cx, cy, kind, lat, lon, name) VALUES(?, ?, ?, ?, ?, ?)');
$db->exec('BEGIN');
$hist = [];
$n = 0;
foreach ($pts as [$la, $lo, $kind, $name]) {
    if (!is_finite($la) || !is_finite($lo)) { continue; }
    if ($la < -90 || $la > 90 || $lo < -180 || $lo > 180) { continue; }
    $k = PT_CLASSES[$kind];
    $name = trim($name);
    if (strlen($name) > 60) { $name = substr($name, 0, 60); }
    $ins->bindValue(1, (int) floor($lo / CELL_DEG), SQLITE3_INTEGER);
    $ins->bindValue(2, (int) floor($la / CELL_DEG), SQLITE3_INTEGER);
    $ins->bindValue(3, $k, SQLITE3_INTEGER);
    $ins->bindValue(4, $la, SQLITE3_FLOAT);
    $ins->bindValue(5, $lo, SQLITE3_FLOAT);
    $ins->bindValue(6, $name === '' ? null : $name, $name === '' ? SQLITE3_NULL : SQLITE3_TEXT);
    $ins->execute();
    $ins->reset();
    $hist[$k] = ($hist[$k] ?? 0) + 1;
    $n++;
}
$db->exec('COMMIT');

$label = [PT_CAMERA => 'speed cameras', PT_EXIT => 'motorway exits',
          PT_FUEL => 'fuel stations'];
ksort($hist);
foreach ($hist as $k => $c) { fwrite(STDERR, sprintf("  %-18s %d\n", $label[$k], $c)); }
fwrite(STDERR, sprintf("imported %d points into %s.db\n", $n, $country));

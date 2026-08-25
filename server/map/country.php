<?php
/**
 * Which map covers this position, and what else is available.
 *
 *     country.php?lat=52.06&lon=5.10     -> the country to download
 *     country.php                        -> everything on offer
 *
 * The watch asks this after its first fix so it can fetch the right country
 * without being told where it is. A bounding box is enough: countries overlap
 * only at their corners, and being handed a neighbour's map at a border costs
 * a download, not correctness.
 */

require_once __DIR__ . '/lib.php';

header('Content-Type: text/plain');

$list = [];
foreach (countries() as $c) {
    $db = open_store($c);
    if ($db === null) { continue; }
    $meta = [];
    $r = $db->query('SELECT k, v FROM meta');
    while ($m = $r->fetchArray(SQLITE3_ASSOC)) { $meta[$m['k']] = $m['v']; }
    $db->close();
    $path = DATA_DIR . '/' . $c . '.db';
    $list[] = [
        'name' => $c,
        'minx' => (float) ($meta['minx'] ?? 0),
        'miny' => (float) ($meta['miny'] ?? 0),
        'maxx' => (float) ($meta['maxx'] ?? 0),
        'maxy' => (float) ($meta['maxy'] ?? 0),
        'ways' => (int) ($meta['ways'] ?? 0),
        'bytes' => is_file($path) ? filesize($path) : 0,
    ];
}

if (isset($_GET['lat']) && isset($_GET['lon'])) {
    $lat = (float) $_GET['lat'];
    $lon = (float) $_GET['lon'];
    foreach ($list as $c) {
        if ($lon >= $c['minx'] && $lon <= $c['maxx']
                && $lat >= $c['miny'] && $lat <= $c['maxy']) {
            printf("%s,%.5f,%.5f,%.5f,%.5f,%d\n",
                   $c['name'], $c['minx'], $c['miny'], $c['maxx'], $c['maxy'], $c['ways']);
            exit;
        }
    }
    echo "none\n";
    exit;
}

foreach ($list as $c) {
    printf("%s,%.5f,%.5f,%.5f,%.5f,%d\n",
           $c['name'], $c['minx'], $c['miny'], $c['maxx'], $c['maxy'], $c['ways']);
}

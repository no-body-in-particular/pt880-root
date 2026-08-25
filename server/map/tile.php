<?php
/**
 * A map tile as a 4-bit greyscale PNG.
 *
 *     tile.php?c=netherlands&z=15&x=16848&y=10814
 *
 * Four bits because the watch has a 240x240 screen and 2.3 GB of storage it
 * must spend on covering a country rather than on colour it cannot show
 * usefully. Sixteen greys is more than enough to separate a motorway from a
 * footpath, and a palette PNG with sixteen entries is written by GD at bit
 * depth 4 without being asked.
 *
 * Rendered on demand and cached to disk. A country is millions of tiles at
 * z15 and nothing pre-renders that; the watch's bulk download drives the
 * rendering of the ones it actually wants.
 */

require_once __DIR__ . '/lib.php';

$c = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['c'] ?? ''));
$z = (int) ($_GET['z'] ?? 0);
$x = (int) ($_GET['x'] ?? -1);
$y = (int) ($_GET['y'] ?? -1);

if ($z < 1 || $z > 18 || $x < 0 || $y < 0 || $x >= (1 << $z) || $y >= (1 << $z)) {
    http_response_code(400);
    exit('bad tile');
}
if (!store_exists($c)) {
    http_response_code(404);
    exit('no such map');
}

$cacheFile = TILE_DIR . "/$c/$z/$x/$y.png";
if (is_file($cacheFile)) {
    send_png(file_get_contents($cacheFile));
    exit;
}

$png = render_tile($c, $z, $x, $y);
// Not cached per tile any more: blocks are the storage unit, and this
// endpoint exists for looking at one tile by hand rather than for the watch,
// which fetches whole blocks. See pack.php.
send_png($png);

function send_png(string $data): void {
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($data));
    // Tiles change only when the country is re-imported, so let the watch keep
    // them for as long as it likes; its own cache is the point.
    header('Cache-Control: public, max-age=2592000');
    echo $data;
}

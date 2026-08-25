<?php
/**
 * Render a country's tiles ahead of time, into the shared cache.
 *
 *     php warm.php netherlands 15
 *
 * A cold block costs the watch one to three and a half seconds while the
 * server draws 256 tiles from the road database; the same block warm is 45ms.
 * Since the tiles are identical whoever asks for them, there is no reason for
 * the watch to be the one waiting - it can all be done here once, and then
 * every download of that country is served from disk.
 *
 * Run as the web server's user so the cache files come out owned by it.
 */
require_once __DIR__ . '/lib.php';

$country = $argv[1] ?? 'netherlands';
$z = (int) ($argv[2] ?? 15);
// Shard n of m: this worker takes every m-th column of tiles. Columns rather
// than a contiguous range, so every worker meets the same mix of empty sea
// and dense city and they finish together instead of one grinding through
// the Randstad while the others sit idle over the North Sea.
$shard  = (int) ($argv[3] ?? 0);
$shards = max(1, (int) ($argv[4] ?? 1));
if (!store_exists($country)) { fwrite(STDERR, "no store for $country\n"); exit(1); }

$db = open_store($country);
$meta = [];
$r = $db->query('SELECT k, v FROM meta');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $meta[$row['k']] = $row['v']; }
$minx = (float) $meta['minx']; $miny = (float) $meta['miny'];
$maxx = (float) $meta['maxx']; $maxy = (float) $meta['maxy'];
$x0 = (int) floor(lon_to_tile($minx, $z));
$x1 = (int) floor(lon_to_tile($maxx, $z));
$y0 = (int) floor(lat_to_tile($maxy, $z));      // north is the smaller y
$y1 = (int) floor(lat_to_tile($miny, $z));

$total = ($x1 - $x0 + 1) * ($y1 - $y0 + 1);
if ($shards > 1) {
    $total = (int) ceil($total / $shards);
}
fwrite(STDERR, sprintf("%s z%d shard %d/%d: x %d..%d  y %d..%d  = ~%d tiles\n",
    $country, $z, $shard, $shards, $x0, $x1, $y0, $y1, $total));

/** Is the web server serving anything at this moment? */
function busy(): bool {
    $n = (int) trim(shell_exec('pgrep -c php-cgi 2>/dev/null') ?: '0');
    return $n > 0;
}

$done = 0; $made = 0; $t0 = microtime(true);
for ($x = $x0; $x <= $x1; $x++) {
    if ($shards > 1 && ($x % $shards) !== $shard) { continue; }

    // Yield to the watch.
    //
    // This is the whole reason the first run had to be killed: rendering a
    // block for a live request and rendering the same country in the
    // background are the same work on the same cores, and the background job
    // does not care if it takes an hour while the watch gives up after sixty
    // seconds. nice() is not enough - it does not help once the request is
    // already queued behind a render that has started. So: if anything is
    // being served right now, wait for it to finish.
    $waited = 0;
    while (busy() && $waited < 60) { usleep(250000); $waited++; }

    for ($y = $y0; $y <= $y1; $y++) {
        $done++;
        $f = TILE_DIR . "/$country/$z/$x/$y.png";
        if (is_file($f)) { continue; }
        $png = render_tile($country, $z, $x, $y);
        @mkdir(dirname($f), 0755, true);
        @file_put_contents($f, $png);
        $made++;
    }
    $el = microtime(true) - $t0;
    // Remaining time from the render rate, not the walk rate: most tiles are
    // already cached and cost a stat, so counting those makes the estimate
    // wildly optimistic and then wildly pessimistic.
    $rate = $made > 0 ? $el / $made : 0;
    fwrite(STDERR, sprintf("\r[%d] %d/%d walked, %d rendered, %.0fs elapsed   ",
        $shard, $done, $total, $made, $el));
}
fwrite(STDERR, sprintf("\ndone: %d rendered in %.0fs\n", $made, microtime(true) - $t0));

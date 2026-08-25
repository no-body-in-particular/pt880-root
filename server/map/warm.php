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
fwrite(STDERR, sprintf("%s z%d shard %d/%d: x %d..%d  y %d..%d\n",
    $country, $z, $shard, $shards, $x0, $x1, $y0, $y1));

/** Is the web server serving anything at this moment? */
function busy(): bool {
    $n = (int) trim(shell_exec('pgrep -c php-cgi 2>/dev/null') ?: '0');
    return $n > 0;
}

$done = 0; $made = 0; $t0 = microtime(true);

// Block coordinates, since a block is the unit that is stored and served.
$bx0 = $x0 >> 4; $bx1 = $x1 >> 4;
$by0 = $y0 >> 4; $by1 = $y1 >> 4;
$total = ($bx1 - $bx0 + 1) * ($by1 - $by0 + 1);
if ($shards > 1) { $total = (int) ceil($total / $shards); }

for ($bx = $bx0; $bx <= $bx1; $bx++) {
    if ($shards > 1 && ($bx % $shards) !== $shard) { continue; }

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
    while (busy() && $waited < 120) { usleep(250000); $waited++; }

    for ($by = $by0; $by <= $by1; $by++) {
        $done++;
        if (is_file(block_file($country, $z, $bx, $by))) { continue; }
        block_bytes($country, $z, $bx, $by);
        $made++;
        if (($made % 5) === 0) {
            $el = microtime(true) - $t0;
            fwrite(STDERR, sprintf("\r[%d] %d/%d blocks walked, %d built, %.0fs, %.0f min left   ",
                $shard, $done, $total, $made, $el,
                $made ? ($el / $made) * max(0, $total - $done) / 60 : 0));
        }
    }
}

fwrite(STDERR, sprintf("\ndone: %d blocks built in %.0f min\n",
    $made, (microtime(true) - $t0) / 60));

<?php
/**
 * Many tiles in one request.
 *
 *     pack.php?c=netherlands&z=15&x=16840&y=10808&w=16&h=16
 *
 * A country at z15 is a hundred and fifty thousand tiles. Fetched one at a
 * time that is a hundred and fifty thousand round trips, and at even fifty
 * milliseconds each the download takes two hours - almost all of it waiting
 * rather than transferring, since the average tile is under half a kilobyte.
 *
 * A 16x16 block is 256 tiles and about a hundred kilobytes, so the whole
 * country becomes six hundred requests instead. The transfer is then bounded
 * by the data rather than by the latency, which is the only way this finishes
 * on a watch.
 *
 *   "WPK1"  u8 zoom  u32 count
 *   per tile:  u32 x  u32 y  u32 length  bytes
 *
 * Big-endian throughout, to match everything else the watch reads.
 */

require_once __DIR__ . '/lib.php';

/** Enough to amortise the round trip, small enough that a failure costs
 *  little and the server never holds much in memory. */
const MAX_TILES = 400;

$c = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['c'] ?? ''));
$z = (int) ($_GET['z'] ?? 15);
$x = (int) ($_GET['x'] ?? -1);
$y = (int) ($_GET['y'] ?? -1);
$w = max(1, min(32, (int) ($_GET['w'] ?? 16)));
$h = max(1, min(32, (int) ($_GET['h'] ?? 16)));

if ($z < 1 || $z > 18 || $x < 0 || $y < 0 || !store_exists($c)) {
    http_response_code(400);
    exit('bad request');
}
if ($w * $h > MAX_TILES) {
    http_response_code(400);
    exit('too many tiles');
}

$span = 1 << $z;

/*
 * An assembled block is cached as one file.
 *
 * The per-tile cache was 150,000 files for a country: at a 4kB filesystem
 * block and a mean tile of 515 bytes that is 600MB of disk holding 42MB of
 * tiles, and every one of them encrypted on the way out, since this
 * filesystem is dm-crypt. Serving a block meant opening 256 of them.
 *
 * Blocks are the unit the watch asks for and now the unit it stores, so they
 * are the sensible unit here too: one open, one read, one file.
 *
 * The per-tile cache is still read when a block has to be assembled for the
 * first time, so nothing already rendered is thrown away.
 */
$aligned = ($w === 16 && $h === 16 && ($x % 16) === 0 && ($y % 16) === 0);

if ($aligned) {
    // The common case, and the only one the watch asks for: hand it straight
    // to the shared block builder, which caches.
    send(block_bytes($c, $z, $x >> 4, $y >> 4));
    exit;
}

// An unaligned or partial request - by hand, or from an older build. Rendered
// but not cached, since it does not correspond to a stored block.
$span = 1 << $z;
$parts = [];
$count = 0;

for ($i = 0; $i < $w; $i++) {
    for ($j = 0; $j < $h; $j++) {
        $tx = $x + $i;
        $ty = $y + $j;
        if ($tx < 0 || $ty < 0 || $tx >= $span || $ty >= $span) { continue; }
        $png = render_tile($c, $z, $tx, $ty);
        $parts[] = pack('NNN', $tx, $ty, strlen($png)) . $png;
        $count++;
    }
}

$body = 'WPK1' . pack('C', $z) . pack('N', $count) . implode('', $parts);

if ($blockFile !== null) {
    @mkdir(dirname($blockFile), 0755, true);
    // Written under a temporary name and moved, so a request that arrives
    // while this one is still writing cannot read a half block.
    $tmp = $blockFile . '.' . getmypid();
    if (@file_put_contents($tmp, $body) === strlen($body)) {
        @rename($tmp, $blockFile);
    } else {
        @unlink($tmp);
    }
}

send($body);

function send(string $body): void {
    header('Content-Type: application/octet-stream');
    header('Cache-Control: public, max-age=2592000');

/*
 * Worth compressing even though PNG already is.
 *
 * A block over sea or off the edge of the data is 256 *identical* blank
 * tiles, and deflate collapses the repeats to almost nothing. Roughly a third
 * of a country's bounding box is water, so this is a third of the download
 * that need never have been sent. Dense blocks barely shrink, which is fine.
 *
 * Dalvik's HttpURLConnection asks for gzip on its own and unwraps it
 * transparently, so the watch needs no change to benefit.
 */
    if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
        $gz = gzencode($body, 6);
        if ($gz !== false && strlen($gz) < strlen($body)) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            header('Content-Length: ' . strlen($gz));
            echo $gz;
            return;
        }
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
}

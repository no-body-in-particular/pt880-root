<?php
/**
 * Turn the old per-tile cache into block files, then let it be deleted.
 *
 *     php convert.php netherlands 15
 *
 * Purely a repack: it reads tiles that were already rendered and writes them
 * out as the blocks pack.php now serves. Nothing is re-rendered, so none of
 * the work already done is thrown away, and a block that is only partly
 * covered by the old cache is left alone for pack.php to finish properly
 * rather than being written half empty.
 *
 * Yields to live requests, like warm.php - a repack that starves a download
 * is worse than no repack.
 */
require_once __DIR__ . '/lib.php';

$c = $argv[1] ?? 'netherlands';
$z = (int) ($argv[2] ?? 15);
$old = TILE_DIR . "/$c/$z";
if (!is_dir($old)) { fwrite(STDERR, "no per-tile cache at $old\n"); exit(0); }

function busy(): bool {
    return ((int) trim(shell_exec('pgrep -c php-cgi 2>/dev/null') ?: '0')) > 0;
}

// Gather the tiles present, grouped by the block they belong to.
$blocks = [];
foreach (scandir($old) as $xs) {
    if (!ctype_digit($xs)) { continue; }
    foreach (scandir("$old/$xs") as $ys) {
        if (!preg_match('/^(\d+)\.png$/', $ys, $m)) { continue; }
        $x = (int) $xs; $y = (int) $m[1];
        $blocks[(($x >> 4) . '_' . ($y >> 4))][] = [$x, $y];
    }
}
fwrite(STDERR, sprintf("%d blocks touched by the old cache\n", count($blocks)));

$full = 0; $partial = 0; $n = 0;
foreach ($blocks as $key => $tiles) {
    $n++;
    if (count($tiles) < 256) { $partial++; continue; }   // let pack.php do it

    [$bx, $by] = array_map('intval', explode('_', $key));
    $out = TILE_DIR . "/$c/b$z/$key.wpk";
    if (is_file($out)) { $full++; continue; }

    $waited = 0;
    while (busy() && $waited < 40) { usleep(250000); $waited++; }

    // Same order pack.php emits: x outer, y inner.
    sort($tiles);
    $parts = ''; $count = 0;
    foreach ($tiles as [$x, $y]) {
        $png = @file_get_contents("$old/$x/$y.png");
        if ($png === false) { continue; }
        $parts .= pack('NNN', $x, $y, strlen($png)) . $png;
        $count++;
    }
    if ($count !== 256) { $partial++; continue; }

    $body = 'WPK1' . pack('C', $z) . pack('N', $count) . $parts;
    @mkdir(dirname($out), 0755, true);
    $tmp = $out . '.tmp';
    if (@file_put_contents($tmp, $body) === strlen($body)) {
        @rename($tmp, $out);
        $full++;
    } else {
        @unlink($tmp);
    }
    if ($n % 25 === 0) {
        fwrite(STDERR, sprintf("\r%d/%d  %d packed, %d left to pack.php   ",
            $n, count($blocks), $full, $partial));
    }
}
fwrite(STDERR, sprintf("\ndone: %d blocks packed, %d partial and left alone\n",
    $full, $partial));

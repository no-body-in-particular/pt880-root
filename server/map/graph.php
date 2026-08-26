<?php
/**
 * The routing graph for a country.
 *
 *     graph.php?c=netherlands          the file
 *     graph.php?c=netherlands&info=1   its size, so the watch can decide
 *
 * Served whole. It is tens of megabytes, but it is fetched once per country
 * and it is what lets the watch route with no network at all - which is the
 * entire point, since the moment you need a new route is rarely the moment
 * you have signal.
 */
require_once __DIR__ . '/lib.php';

$c = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['c'] ?? ''));

// As with tiles: if the watch does not name a country, work it out from the
// middle of the box it is asking about.
if ($c === '' && isset($_GET['w'], $_GET['s'], $_GET['e'], $_GET['n'])) {
    $c = country_at(((float) $_GET['w'] + (float) $_GET['e']) / 2,
                    ((float) $_GET['s'] + (float) $_GET['n']) / 2) ?? '';
}

$f = DATA_DIR . "/$c.graph";
if ($c === '' || !is_file($f)) {
    http_response_code(404);
    exit('no graph');
}

/*
 * A box rather than the whole country, when asked for one.
 *
 * Cached on disk beside the tiles - the cut is cheap but not free, and a
 * watch that loses the download will ask again for exactly the same box.
 */
if (isset($_GET['w'], $_GET['s'], $_GET['e'], $_GET['n'])) {
    require_once __DIR__ . '/subgraph.php';
    $w = (float) $_GET['w']; $so = (float) $_GET['s'];
    $e = (float) $_GET['e']; $no = (float) $_GET['n'];

    $key = sprintf('%s_%.3f_%.3f_%.3f_%.3f', $c, $w, $so, $e, $no);
    $cacheDir = TILE_DIR . '/graphs';
    $cached = "$cacheDir/$key.graph";

    if (is_file($cached) && filemtime($cached) >= filemtime($f)) {
        $body = file_get_contents($cached);
    } else {
        $body = subgraph_bytes($c, $w, $so, $e, $no);
        if ($body === null) { http_response_code(404); exit('empty box'); }
        @mkdir($cacheDir, 0755, true);
        $tmp = "$cached." . getmypid();
        if (@file_put_contents($tmp, $body) === strlen($body)) { @rename($tmp, $cached); }
        else { @unlink($tmp); }
    }

    header('Content-Type: application/octet-stream');
    header('Cache-Control: public, max-age=2592000');
    if (isset($_GET['info'])) {
        header('Content-Type: text/plain');
        echo strlen($body) . "\n";
        exit;
    }
    if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
        $gz = gzencode($body, 6);
        if ($gz !== false && strlen($gz) < strlen($body)) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            header('Content-Length: ' . strlen($gz));
            echo $gz;
            exit;
        }
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

if (isset($_GET['info'])) {
    header('Content-Type: text/plain');
    printf("%d %d\n", filesize($f), filemtime($f));
    exit;
}

header('Content-Type: application/octet-stream');
header('Cache-Control: public, max-age=2592000');

/*
 * Worth gzipping after all.
 *
 * It looks like dense binary, but it is not random: node ids in an
 * adjacency list are near each other, coordinates share high bytes with
 * their neighbours, and half the arc costs are small numbers with a zero
 * byte in front. Measured on the Netherlands it comes down from 36MB to
 * about 22, which over the watch's wifi is a couple of minutes saved on a
 * download it only ever does once per country.
 *
 * Dalvik asks for gzip and unwraps it transparently, so the watch writes
 * the plain file to the card without knowing.
 */
/*
 * The compressed copy is made by build_graph.php, not here.
 *
 * Thirty-six megabytes of deflate inside a request is a CGI timeout waiting
 * to happen, and the data directory is not writable by the web server
 * anyway - a cache that silently fails to be written is worse than none,
 * because nothing says so.
 *
 * It is worth having: the file looks like dense binary but is not random -
 * adjacent node ids share high bytes, and half the arc costs are small
 * numbers behind a zero - so it comes down by about a third.
 */
if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false
        && is_file("$f.gz") && filemtime("$f.gz") >= filemtime($f)) {
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    header('Content-Length: ' . filesize("$f.gz"));
    readfile("$f.gz");
    exit;
}

header('Content-Length: ' . filesize($f));
readfile($f);

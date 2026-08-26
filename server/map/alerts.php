<?php
/**
 * The alert layer for a country: speed cameras, motorway exits, fuel.
 *
 * mapd serves this; this exists so the PHP fallback described in
 * server/mapd/README.md is complete, and answers identically.
 */
require_once __DIR__ . '/lib.php';

$c = $_GET['c'] ?? '';
if ($c === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $c)) {
    http_response_code(400);
    exit('bad country');
}
$p = DATA_DIR . "/$c.alerts";
if (!is_file($p)) {
    http_response_code(404);
    exit('no alerts');
}
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($p));
readfile($p);

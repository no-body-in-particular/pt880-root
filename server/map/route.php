<?php
/**
 * A route, as geometry to draw and turns to speak.
 *
 *     route.php?flat=52.0619&flon=5.1084&tlat=52.0850&tlon=5.3051
 *
 * Routing is done here rather than on the watch because a road graph for a
 * country is not something a 1 GHz watch should search, and because the route
 * is computed once and then followed for an hour. The watch downloads it, the
 * tiles along it, and then needs no network at all.
 *
 * The turns carry a direction and a distance and no street name: at 240x240,
 * spoken through a wrist, "in two hundred metres, turn left" is the whole of
 * what is useful, and the name is what makes the sentence too long to finish
 * before the junction.
 *
 * Answers, big-endian:
 *
 *   "WRT2"  u32 metres  u16 steps  u16 points
 *   per step:  u8 turn  u8 exit  u16 metres  i32 lat  i32 lon      (x1e7)
 *   geometry:  i32 lat  i32 lon  then (i16,i16) x n-1             (deltas x1e6)
 *
 * The exit byte is which exit to take at a roundabout, and 0 everywhere else. OSRM has always
 * reported it and this threw it away, so the watch could only ever say "at the roundabout" -
 * true, and not the part a driver needs. Counting exits is the whole instruction at a
 * roundabout, and it is the one manoeuvre where the direction alone says nothing.
 *
 * WRT1 was the same without that byte. The watch still reads it, so a cached route written
 * before this, or a server that has not been updated, keeps working and simply says less.
 */

require_once __DIR__ . '/lib.php';

const ROUTER = 'https://router.project-osrm.org/route/v1/driving/';
const CACHE_DIR = __DIR__ . '/routes';

// The turn vocabulary the watch speaks. Deliberately short: every one of these
// has a natural spoken form, and anything subtler than "slight left" is not
// worth saying to someone looking at a road rather than a screen.
const TURN_DEPART = 0, TURN_STRAIGHT = 1, TURN_SLIGHT_LEFT = 2, TURN_LEFT = 3,
      TURN_SHARP_LEFT = 4, TURN_SLIGHT_RIGHT = 5, TURN_RIGHT = 6,
      TURN_SHARP_RIGHT = 7, TURN_UTURN = 8, TURN_ROUNDABOUT = 9, TURN_ARRIVE = 10;

$flat = (float) ($_GET['flat'] ?? 0);
$flon = (float) ($_GET['flon'] ?? 0);
$tlat = (float) ($_GET['tlat'] ?? 0);
$tlon = (float) ($_GET['tlon'] ?? 0);

if (!$flat || !$flon || !$tlat || !$tlon) {
    http_response_code(400);
    exit('need flat, flon, tlat, tlon');
}

@mkdir(CACHE_DIR, 0755, true);
$key = sprintf('%.4f_%.4f_%.4f_%.4f', $flat, $flon, $tlat, $tlon);
// The suffix is part of the format, not decoration: without it the day of cached WRT1 files
// written before this change would keep being served, and the exits would appear tomorrow
// rather than now.
$cacheFile = CACHE_DIR . '/' . $key . '.v2.bin';

// A route is only worth reusing while it is fresh; roads do not move but
// a stale one hides a closure, and the file is a couple of kilobytes.
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    send(file_get_contents($cacheFile));
    exit;
}

$url = ROUTER . sprintf('%.6f,%.6f;%.6f,%.6f', $flon, $flat, $tlon, $tlat)
     . '?overview=full&geometries=geojson&steps=true';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_USERAGENT => 'pt880-watch-map/1.0',
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $code !== 200) {
    http_response_code(502);
    exit('router unreachable');
}
$j = json_decode($body, true);
if (($j['code'] ?? '') !== 'Ok' || empty($j['routes'])) {
    http_response_code(404);
    exit('no route');
}

$route = $j['routes'][0];
$coords = $route['geometry']['coordinates'] ?? [];      // [lon, lat] pairs
if (count($coords) < 2) {
    http_response_code(404);
    exit('no geometry');
}

$steps = [];
foreach (($route['legs'][0]['steps'] ?? []) as $s) {
    $m = $s['maneuver'] ?? [];
    $loc = $m['location'] ?? null;
    if (!$loc) { continue; }
    $steps[] = [
        'turn' => turn_code($m['type'] ?? '', $m['modifier'] ?? ''),
        // OSRM puts the exit number on roundabout and rotary manoeuvres, counting from the
        // entry. Taken whenever it is offered rather than only for the turn codes we mapped to
        // TURN_ROUNDABOUT, because the two do not have to agree: a rotary that came through as
        // a plain left is still a rotary, and the number is still right.
        'exit' => max(0, min(255, (int) ($m['exit'] ?? 0))),
        'dist' => min(65535, (int) round($s['distance'] ?? 0)),
        'lat'  => $loc[1],
        'lon'  => $loc[0],
    ];
    if (count($steps) >= 250) { break; }
}

$out = 'WRT2'
     . pack('N', (int) round($route['distance']))
     . pack('n', count($steps))
     . pack('n', min(65535, count($coords)));

foreach ($steps as $s) {
    $out .= pack('C', $s['turn'])
          . pack('C', $s['exit'])
          . pack('n', $s['dist'])
          . pack('N', enc32($s['lat']))
          . pack('N', enc32($s['lon']));
}

$out .= pack('NN', enc32($coords[0][1]), enc32($coords[0][0]));
$plat = $coords[0][1];
$plon = $coords[0][0];
$count = min(65535, count($coords));
for ($i = 1; $i < $count; $i++) {
    $dlat = (int) round(($coords[$i][1] - $plat) * 1e6);
    $dlon = (int) round(($coords[$i][0] - $plon) * 1e6);
    // Clamped rather than dropped: a route is one continuous line and a
    // missing vertex would put a straight cut across it.
    $dlat = max(-32768, min(32767, $dlat));
    $dlon = max(-32768, min(32767, $dlon));
    $out .= pack('nn', $dlat & 0xFFFF, $dlon & 0xFFFF);
    $plat += $dlat / 1e6;
    $plon += $dlon / 1e6;
}

@file_put_contents($cacheFile, $out);
send($out);

function send(string $data): void {
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($data));
    echo $data;
}

function enc32(float $deg): int {
    return ((int) round($deg * 1e7)) & 0xFFFFFFFF;
}

function turn_code(string $type, string $modifier): int {
    if ($type === 'depart') { return TURN_DEPART; }
    if ($type === 'arrive') { return TURN_ARRIVE; }
    if ($type === 'rotary' || $type === 'roundabout'
            || $type === 'exit rotary' || $type === 'exit roundabout') {
        return TURN_ROUNDABOUT;
    }
    switch ($modifier) {
        case 'left':         return TURN_LEFT;
        case 'slight left':  return TURN_SLIGHT_LEFT;
        case 'sharp left':   return TURN_SHARP_LEFT;
        case 'right':        return TURN_RIGHT;
        case 'slight right': return TURN_SLIGHT_RIGHT;
        case 'sharp right':  return TURN_SHARP_RIGHT;
        case 'uturn':        return TURN_UTURN;
        default:             return TURN_STRAIGHT;
    }
}

<?php
/**
 * Shared map machinery: tile arithmetic, the road store, and the class table.
 *
 * The watch has a 240x240 screen, two buttons and no touchscreen, so this
 * serves two things and nothing else: a 4-bit greyscale raster tile to give
 * the eye context, and the road geometry as vectors for drawing sharply,
 * snapping a position to a road, and following a route. Everything else OSM
 * knows about is dropped at import.
 */

const MAP_DIR   = __DIR__;
const DATA_DIR  = __DIR__ . '/data';
const TILE_DIR  = __DIR__ . '/tiles';

/*
 * php-cgi runs here with umask 0117, so mkdir(0755) lands as rw-r----- -- a
 * directory with no execute bit, which cannot be written into. Every cached
 * tile write failed silently behind its @, and each request re-rendered all
 * 256 tiles from the road database. Set a sane mask once, where every writer
 * shares it.
 */
umask(0022);
const TILE_PX   = 256;

/** The grid the road store is bucketed into, in degrees. About 1.1 km of
 *  latitude: fine enough that a z15 tile reads one or two cells, coarse enough
 *  that a country is a hundred thousand of them rather than millions. */
const CELL_DEG  = 0.01;

/**
 * Road classes, most important first. The number is what gets stored; the
 * width is how many pixels it is drawn with, and the grey is its shade in the
 * 16-level ramp - motorways brightest, footpaths barely there.
 *
 * minzoom keeps a city's service roads out of a tile showing forty kilometres,
 * which is the difference between a map and a grey smear.
 */
/**
 * The sixteen colours a tile may use.
 *
 * Costs nothing over greyscale: the file is four bits a pixel either way, the
 * palette itself is 48 bytes, and deflate never sees the colours - only the
 * indices, which are unchanged. So this is clarity for free.
 *
 * Two rules shape it. Brightness still rises with importance, because that is
 * what makes a motorway findable at a glance on a 240px screen. And every
 * road sits in the cool half of the wheel, which leaves the warm half
 * entirely to the route line the watch draws on top - amber on blue-grey is
 * about as far apart as two colours get, so the road you are meant to take
 * never reads as just another street.
 *
 * Index is the "grey" column of road_classes(), so that table needs no edit.
 */
function palette(): array {
    return [
        0  => [0x08, 0x0B, 0x10],   // background
        1  => [0x0E, 0x1B, 0x26],   // water, once there is any
        2  => [0x0E, 0x1F, 0x14],   // park
        3  => [0x16, 0x1A, 0x20],   // built-up fill
        4  => [0x3F, 0x4A, 0x44],   // footway, path, steps
        5  => [0x4A, 0x51, 0x58],   // service, track
        6  => [0x4E, 0x6B, 0x52],   // cycleway, pedestrian - green: not for cars
        7  => [0x66, 0x6E, 0x78],   // living street
        8  => [0x7E, 0x87, 0x94],   // residential, unclassified
        9  => [0x8E, 0x99, 0xA6],   // tertiary link
        10 => [0x9E, 0xAA, 0xB8],   // tertiary, secondary link
        11 => [0xB0, 0xBE, 0xCE],   // secondary
        12 => [0x7F, 0xA8, 0xD8],   // trunk link   - blue marks a through road
        13 => [0x93, 0xBE, 0xEA],   // primary, motorway link
        14 => [0xA9, 0xD2, 0xF5],   // trunk
        15 => [0xC8, 0xE8, 0xFF],   // motorway
    ];
}

function road_classes(): array {
    return [
        // fclass                     => [code, width, grey, minzoom]
        'motorway'                    => [1, 3, 15, 7],
        'motorway_link'               => [1, 2, 13, 11],
        'trunk'                       => [2, 3, 14, 7],
        'trunk_link'                  => [2, 2, 12, 11],
        'primary'                     => [3, 2, 13, 8],
        'primary_link'                => [3, 2, 11, 12],
        'secondary'                   => [4, 2, 11, 10],
        'secondary_link'              => [4, 1, 10, 12],
        'tertiary'                    => [5, 2, 10, 11],
        'tertiary_link'               => [5, 1,  9, 13],
        'unclassified'                => [6, 1,  8, 13],
        'residential'                 => [6, 1,  8, 13],
        'living_street'               => [6, 1,  7, 14],
        'pedestrian'                  => [7, 1,  6, 14],
        'service'                     => [8, 1,  5, 15],
        'track'                       => [8, 1,  5, 15],
        'cycleway'                    => [9, 1,  6, 14],
        'footway'                     => [10, 1, 4, 16],
        'path'                        => [10, 1, 4, 16],
        'steps'                       => [10, 1, 4, 16],
        'bridleway'                   => [10, 1, 4, 16],
        'unknown'                     => [6, 1,  6, 14],
    ];
}

/** @return array{0:int,1:int,2:int,3:int}|null code, width, grey, minzoom */
function road_class(string $fclass): ?array {
    static $t = null;
    if ($t === null) { $t = road_classes(); }
    return $t[$fclass] ?? null;
}

// ---------------------------------------------------------------- tiles

/** Slippy-map tile x for a longitude. */
function lon_to_tile(float $lon, int $z): float {
    return ($lon + 180.0) / 360.0 * (1 << $z);
}

/** Slippy-map tile y for a latitude, Web Mercator. */
function lat_to_tile(float $lat, int $z): float {
    $r = deg2rad($lat);
    return (1.0 - log(tan($r) + 1.0 / cos($r)) / M_PI) / 2.0 * (1 << $z);
}

function tile_to_lon(float $x, int $z): float {
    return $x / (1 << $z) * 360.0 - 180.0;
}

function tile_to_lat(float $y, int $z): float {
    $n = M_PI - 2.0 * M_PI * $y / (1 << $z);
    return rad2deg(atan(0.5 * (exp($n) - exp(-$n))));
}

/** @return array{0:float,1:float,2:float,3:float} west, south, east, north */
function tile_bbox(int $z, int $x, int $y): array {
    return [
        tile_to_lon($x, $z),
        tile_to_lat($y + 1, $z),
        tile_to_lon($x + 1, $z),
        tile_to_lat($y, $z),
    ];
}

// ---------------------------------------------------------------- store

/** The road store for one country, opened read-only. */
function open_store(string $country): ?SQLite3 {
    $path = DATA_DIR . '/' . basename($country) . '.db';
    if (!is_file($path)) { return null; }
    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(5000);
    return $db;
}

function store_exists(string $country): bool {
    return is_file(DATA_DIR . '/' . basename($country) . '.db');
}

/** Every country with an imported store. */
function countries(): array {
    $out = [];
    foreach (glob(DATA_DIR . '/*.db') as $f) {
        $out[] = basename($f, '.db');
    }
    sort($out);
    return $out;
}

/**
 * Segments overlapping a bounding box.
 *
 * Geometry is delta-encoded: the first point as two int32 at 1e7 degrees, then
 * int16 deltas at 1e6. Four bytes a point rather than sixteen, which is the
 * difference between a country fitting on the watch and not.
 *
 * @return array of ['cls'=>int, 'name'=>?string, 'pts'=>[[lon,lat],...]]
 */
function segments_in(SQLite3 $db, float $w, float $s, float $e, float $n,
                     int $maxzoom = 99, int $limit = 20000): array {
    $cx0 = (int) floor($w / CELL_DEG);
    $cx1 = (int) floor($e / CELL_DEG);
    $cy0 = (int) floor($s / CELL_DEG);
    $cy1 = (int) floor($n / CELL_DEG);

    $st = $db->prepare(
        'SELECT cls, name, geom FROM seg
          WHERE cx BETWEEN :x0 AND :x1 AND cy BETWEEN :y0 AND :y1
            AND minzoom <= :z
          LIMIT :lim');
    $st->bindValue(':x0', $cx0, SQLITE3_INTEGER);
    $st->bindValue(':x1', $cx1, SQLITE3_INTEGER);
    $st->bindValue(':y0', $cy0, SQLITE3_INTEGER);
    $st->bindValue(':y1', $cy1, SQLITE3_INTEGER);
    $st->bindValue(':z', $maxzoom, SQLITE3_INTEGER);
    $st->bindValue(':lim', $limit, SQLITE3_INTEGER);

    $res = $st->execute();
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $pts = unpack_geom($row['geom']);
        if (count($pts) >= 2) {
            $out[] = ['cls' => (int) $row['cls'], 'name' => $row['name'], 'pts' => $pts];
        }
    }
    return $out;
}

/** int32 lon, int32 lat, then int16 delta pairs. */
function pack_geom(array $pts): string {
    $first = $pts[0];
    $s = pack('ll', (int) round($first[0] * 1e7), (int) round($first[1] * 1e7));
    $px = $first[0];
    $py = $first[1];
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        $dx = (int) round(($pts[$i][0] - $px) * 1e6);
        $dy = (int) round(($pts[$i][1] - $py) * 1e6);
        $s .= pack('ss', $dx, $dy);
        // Walk the quantised position, not the real one, so rounding cannot
        // accumulate along a way with hundreds of points.
        $px += $dx / 1e6;
        $py += $dy / 1e6;
    }
    return $s;
}

function unpack_geom(string $b): array {
    if (strlen($b) < 8) { return []; }
    $head = unpack('lx/ly', substr($b, 0, 8));
    $x = $head['x'] / 1e7;
    $y = $head['y'] / 1e7;
    $pts = [[$x, $y]];
    for ($o = 8, $len = strlen($b); $o + 3 < $len; $o += 4) {
        $d = unpack('sdx/sdy', substr($b, $o, 4));
        $x += $d['dx'] / 1e6;
        $y += $d['dy'] / 1e6;
        $pts[] = [$x, $y];
    }
    return $pts;
}

// ---------------------------------------------------------------- render

/**
 * Draw one tile.
 *
 * Lives here rather than in tile.php so anything can render: the HTTP wrapper,
 * a bulk pre-render, or a script measuring what a country would cost. Roads are
 * drawn least important first, so a motorway is never buried under the service
 * road that crosses it.
 */
function render_tile(string $country, int $z, int $x, int $y): string {
    // A palette image, not truecolour: GD writes a palette PNG at the smallest
    // bit depth that fits, so sixteen colours becomes a 4-bit file.
    $im = imagecreate(TILE_PX, TILE_PX);
    $grey = [];
    foreach (palette() as $i => $rgb) {
        $grey[$i] = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    }
    imagefill($im, 0, 0, $grey[0]);          // index 0 is the background

    $db = open_store($country);
    if ($db === null) { return png_of($im); }

    [$w, $s, $e, $n] = tile_bbox($z, $x, $y);
    // A margin so a road crossing the edge is drawn up to it rather than
    // stopping short and leaving a seam between tiles.
    $mw = ($e - $w) * 0.15;
    $mh = ($n - $s) * 0.15;

    $classes = [];
    foreach (road_classes() as $spec) { $classes[$spec[0]] = $spec; }

    $segs = segments_in($db, $w - $mw, $s - $mh, $e + $mw, $n + $mh, $z);

    // Drawn least important first, so a motorway is never buried under the
    // service road that crosses it.
    usort($segs, function ($a, $b) { return $b['cls'] <=> $a['cls']; });

    $scale = (1 << $z) * TILE_PX;
    foreach ($segs as $seg) {
        $spec = $classes[$seg['cls']] ?? [6, 1, 8, 13];
        imagesetthickness($im, $spec[1]);
        $col = $grey[max(1, min(15, $spec[2]))];

        $pts = $seg['pts'];
        $px = null; $py = null;
        foreach ($pts as $pt) {
            $tx = (lon_to_tile($pt[0], $z) - $x) * TILE_PX;
            $ty = (lat_to_tile($pt[1], $z) - $y) * TILE_PX;
            if ($px !== null) {
                imageline($im, (int) $px, (int) $py, (int) $tx, (int) $ty, $col);
            }
            $px = $tx; $py = $ty;
        }
    }
    $db->close();
    return png_of($im);
}

function png_of($im): string {
    ob_start();
    imagepng($im, null, 9);
    $data = ob_get_clean();
    imagedestroy($im);
    return $data;
}

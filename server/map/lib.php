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
    // A test harness can substitute a palette to measure what a different
    // bit depth costs; nothing in normal operation sets this.
    if (isset($GLOBALS['PALETTE_OVERRIDE'])) { return $GLOBALS['PALETTE_OVERRIDE']; }

    /*
     * Thirty-two colours, which makes these 8-bit palette PNGs rather than
     * 4-bit ones.
     *
     * PNG has no 5-bit depth - the choices are 1, 2, 4 and 8 - so going past
     * sixteen colours doubles the raw pixel data. Measured over a spread of
     * terrain it costs 6%: a dense city tile grows 2%, an empty one 41% but
     * only from 192 to 270 bytes, and a country goes from about 42MB to 44.
     * That is a fair price for being able to tell a forest from a field.
     *
     * Two groups. Ground cover in 1..15, drawn first and dark, because it is
     * context and must never compete with what is drawn on top of it. Roads
     * in 16..27, brightening with importance, all in the cool half of the
     * wheel so the amber route line the watch draws stays unmistakable.
     */
    return [
        0  => [0x08, 0x0B, 0x10],   // background
        1  => [0x0D, 0x1A, 0x26],   // water
        2  => [0x11, 0x22, 0x30],   // river, canal, stream
        3  => [0x16, 0x1A, 0x20],   // building
        4  => [0x0D, 0x21, 0x14],   // forest
        5  => [0x12, 0x29, 0x1A],   // park, nature reserve, recreation
        6  => [0x17, 0x31, 0x1F],   // grass, meadow, heath, scrub
        7  => [0x1E, 0x24, 0x13],   // farmland, orchard, allotment
        8  => [0x1C, 0x1C, 0x22],   // industrial, commercial, retail
        9  => [0x13, 0x15, 0x1A],   // residential landuse
        10 => [0x1A, 0x23, 0x18],   // cemetery
        11 => [0x2A, 0x28, 0x1E],   // beach, sand, dune
        12 => [0x0F, 0x22, 0x26],   // wetland, marsh
        13 => [0x24, 0x1E, 0x24],   // quarry, military, landfill
        14 => [0x36, 0x3B, 0x45],   // railway
        15 => [0x20, 0x20, 0x20],   // spare
        16 => [0x3F, 0x4A, 0x44],   // footway, path, steps
        17 => [0x4A, 0x51, 0x58],   // service, track
        18 => [0x4E, 0x6B, 0x52],   // cycleway, pedestrian - not for cars
        19 => [0x66, 0x6E, 0x78],   // living street
        20 => [0x7E, 0x87, 0x94],   // residential, unclassified
        21 => [0x8E, 0x99, 0xA6],   // tertiary link
        22 => [0x9E, 0xAA, 0xB8],   // tertiary, secondary link
        23 => [0xB0, 0xBE, 0xCE],   // secondary
        24 => [0x7F, 0xA8, 0xD8],   // trunk link - blue marks a through road
        25 => [0x93, 0xBE, 0xEA],   // primary, motorway link
        26 => [0xA9, 0xD2, 0xF5],   // trunk
        27 => [0xC8, 0xE8, 0xFF],   // motorway
        28 => [0x30, 0x30, 0x30],   // spare
        29 => [0x30, 0x30, 0x30],
        30 => [0x30, 0x30, 0x30],
        31 => [0x30, 0x30, 0x30],
    ];
}

/**
 * Ground cover class to palette index.
 *
 * Everything not named here is left undrawn rather than guessed at: an
 * unrecognised landuse painted a default colour is worse than bare
 * background, because it reads as a real feature.
 */
function area_classes(): array {
    return [
        'forest' => 4, 'wood' => 4,
        'park' => 5, 'nature_reserve' => 5, 'recreation_ground' => 5,
        'village_green' => 5, 'garden' => 5,
        'grass' => 6, 'meadow' => 6, 'heath' => 6, 'scrub' => 6,
        'grassland' => 6, 'moor' => 6,
        'farmland' => 7, 'farmyard' => 7, 'orchard' => 7, 'vineyard' => 7,
        'allotments' => 7,
        'industrial' => 8, 'commercial' => 8, 'retail' => 8,
        'cemetery' => 10, 'graveyard' => 10,
        'residential' => 9,
        'beach' => 11, 'sand' => 11, 'dune' => 11,
        'wetland' => 12, 'marsh' => 12, 'mud' => 12,
        'quarry' => 13, 'military' => 13, 'landfill' => 13,
        'water' => 1, 'reservoir' => 1, 'basin' => 1, 'lake' => 1,
        'pond' => 1, 'dock' => 1, 'wastewater' => 1,
        'river' => 2, 'canal' => 2, 'stream' => 2, 'drain' => 2,
    ];
}

function road_classes(): array {
    return [
        // fclass                     => [code, width, colour, minzoom]
        'motorway'                    => [1, 3, 27, 7],
        'motorway_link'               => [1, 2, 25, 11],
        'trunk'                       => [2, 3, 26, 7],
        'trunk_link'                  => [2, 2, 24, 11],
        'primary'                     => [3, 2, 25, 8],
        'primary_link'                => [3, 2, 23, 12],
        'secondary'                   => [4, 2, 23, 10],
        'secondary_link'              => [4, 1, 22, 12],
        'tertiary'                    => [5, 2, 22, 11],
        'tertiary_link'               => [5, 1,  21, 13],
        'unclassified'                => [6, 1,  20, 13],
        'residential'                 => [6, 1,  20, 13],
        'living_street'               => [6, 1,  19, 14],
        'pedestrian'                  => [7, 1,  18, 14],
        'service'                     => [8, 1,  17, 15],
        'track'                       => [8, 1,  17, 15],
        'cycleway'                    => [9, 1,  18, 14],
        'footway'                     => [10, 1, 16, 16],
        'path'                        => [10, 1, 16, 16],
        'steps'                       => [10, 1, 16, 16],
        'bridleway'                   => [10, 1, 16, 16],
        'unknown'                     => [6, 1,  18, 14],

        // Not roads, but the same shape of thing: a line with a class and a
        // width. Given codes above every road so they are drawn underneath -
        // see the sort in render_tile - and their own palette entries.
        'rail'                        => [11, 1, 14, 11],
        'light_rail'                  => [11, 1, 14, 12],
        'subway'                      => [11, 1, 14, 13],
        'tram'                        => [11, 1, 14, 13],
        'narrow_gauge'                => [11, 1, 14, 13],
        'river'                       => [12, 2,  2, 10],
        'canal'                       => [12, 2,  2, 11],
        'stream'                      => [12, 1,  2, 13],
        'drain'                       => [12, 1,  2, 14],
        'ditch'                       => [12, 1,  2, 15],
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

/**
 * Which country's data covers this point.
 *
 * Tile coordinates are global - a z15 tile in Germany has the same numbers
 * whoever asks for it - so the watch has no business knowing which database a
 * tile comes out of. It asks for a place; the server works out which store
 * holds it. That is also what lets a cached tile from one trip be reused on
 * another, and what makes crossing a border a non-event.
 *
 * @return country name, or null if nothing here covers it
 */
function country_at(float $lon, float $lat): ?string {
    static $boxes = null;
    if ($boxes === null) {
        $boxes = [];
        foreach (countries() as $c) {
            $db = open_store($c);
            if ($db === null) { continue; }
            $m = [];
            $r = $db->query('SELECT k, v FROM meta');
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $m[$row['k']] = $row['v']; }
            if (!isset($m['minx'])) { continue; }
            $boxes[$c] = [(float) $m['minx'], (float) $m['miny'],
                          (float) $m['maxx'], (float) $m['maxy']];
        }
    }
    $best = null; $bestArea = INF;
    foreach ($boxes as $c => $b) {
        if ($lon < $b[0] || $lon > $b[2] || $lat < $b[1] || $lat > $b[3]) { continue; }
        // Overlapping extracts: prefer the smaller, which is the more local.
        $area = ($b[2] - $b[0]) * ($b[3] - $b[1]);
        if ($area < $bestArea) { $bestArea = $area; $best = $c; }
    }
    return $best;
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
function cells_for(array $pts): array {
    $seen = [];
    foreach ($pts as $pt) {
        $seen[((int) floor($pt[0] / CELL_DEG)) . ',' . ((int) floor($pt[1] / CELL_DEG))] = true;
    }
    $out = [];
    foreach (array_keys($seen) as $k) {
        [$x, $y] = explode(',', $k);
        $out[] = [(int) $x, (int) $y];
    }
    return $out;
}
function simplify(array $pts, float $tolM): array {
    $n = count($pts);
    if ($n < 3) { return $pts; }

    $lat = $pts[0][1];
    $kx = 111320.0 * cos(deg2rad($lat));
    $ky = 110540.0;

    $keep = array_fill(0, $n, false);
    $keep[0] = true;
    $keep[$n - 1] = true;
    $stack = [[0, $n - 1]];

    while ($stack) {
        [$i, $j] = array_pop($stack);
        if ($j <= $i + 1) { continue; }
        $ax = $pts[$i][0] * $kx; $ay = $pts[$i][1] * $ky;
        $bx = $pts[$j][0] * $kx; $by = $pts[$j][1] * $ky;
        $dx = $bx - $ax; $dy = $by - $ay;
        $len = $dx * $dx + $dy * $dy;

        $worst = 0.0; $wi = -1;
        for ($k = $i + 1; $k < $j; $k++) {
            $px = $pts[$k][0] * $kx - $ax;
            $py = $pts[$k][1] * $ky - $ay;
            if ($len > 0) {
                $t = max(0.0, min(1.0, ($px * $dx + $py * $dy) / $len));
                $d = hypot($px - $t * $dx, $py - $t * $dy);
            } else {
                $d = hypot($px, $py);
            }
            if ($d > $worst) { $worst = $d; $wi = $k; }
        }
        if ($worst > $tolM && $wi > 0) {
            $keep[$wi] = true;
            $stack[] = [$i, $wi];
            $stack[] = [$wi, $j];
        }
    }

    $out = [];
    for ($i = 0; $i < $n; $i++) { if ($keep[$i]) { $out[] = $pts[$i]; } }
    return $out;
}

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
/**
 * Building boxes overlapping a bounding box.
 *
 * Stored packed by cell - see import_buildings.php - so this is one row per
 * cell rather than one per building, and a tile touches only a handful of
 * cells. Returns [lon0, lat0, lon1, lat1] quads.
 */
function buildings_in(SQLite3 $db, float $w, float $s, float $e, float $n): array {
    $cx0 = (int) floor($w / CELL_DEG); $cx1 = (int) floor($e / CELL_DEG);
    $cy0 = (int) floor($s / CELL_DEG); $cy1 = (int) floor($n / CELL_DEG);

    $out = [];
    for ($cx = $cx0; $cx <= $cx1; $cx++) {
        for ($cy = $cy0; $cy <= $cy1; $cy++) {
            foreach (bldg_cell($db, $cx, $cy) as $b) {
                if ($b[2] < $w || $b[0] > $e || $b[3] < $s || $b[1] > $n) { continue; }
                $out[] = $b;
            }
        }
    }
    return $out;
}

/**
 * One cell's buildings, decoded once and kept.
 *
 * pack.php renders 256 tiles in a request and a cell is about the size of a
 * tile, so without this every cell is decoded a dozen times over. Decoding
 * is also done in one unpack of the whole blob rather than one per building:
 * a dense city cell holds thousands, and PHP function call overhead was most
 * of the cost of drawing a tile.
 */
function bldg_cell(SQLite3 $db, int $cx, int $cy): array {
    static $cache = [];
    static $order = [];

    $key = "$cx,$cy";
    if (isset($cache[$key])) { return $cache[$key]; }

    $st = $db->prepare('SELECT boxes FROM bldg WHERE cx = ? AND cy = ?');
    $st->bindValue(1, $cx, SQLITE3_INTEGER);
    $st->bindValue(2, $cy, SQLITE3_INTEGER);
    $row = $st->execute()->fetchArray(SQLITE3_ASSOC);

    $list = [];
    if ($row !== false && $row['boxes'] !== null) {
        $blob = $row['boxes'];
        $vals = unpack('v*', $blob);            // one call for the whole cell
        $ox = $cx * CELL_DEG;
        $oy = $cy * CELL_DEG;
        $scale = CELL_DEG / 65536;
        $count = count($vals) >> 2;
        for ($i = 0, $k = 1; $i < $count; $i++, $k += 4) {
            $list[] = [
                $ox + $vals[$k] * $scale,
                $oy + $vals[$k + 1] * $scale,
                $ox + $vals[$k + 2] * $scale,
                $oy + $vals[$k + 3] * $scale,
            ];
        }
    }

    // Bounded, so a country-wide render does not accumulate every cell.
    $cache[$key] = $list;
    $order[] = $key;
    if (count($order) > 64) {
        unset($cache[array_shift($order)]);
    }
    return $list;
}

/**
 * Ground cover overlapping a bounding box, in drawing order.
 *
 * Two tables. Most polygons are filed by the cells they touch, as roads are.
 * The few that span more than a couple of dozen cells - a large lake, a
 * national park - are held once and found by bounding box instead, because
 * copying one of those into every cell it covers is how a road database
 * becomes a hundred gigabytes.
 */
function areas_in(SQLite3 $db, float $w, float $s, float $e, float $n): array {
    $out = [];

    $cx0 = (int) floor($w / CELL_DEG); $cx1 = (int) floor($e / CELL_DEG);
    $cy0 = (int) floor($s / CELL_DEG); $cy1 = (int) floor($n / CELL_DEG);
    $st = $db->prepare('SELECT cls, geom FROM area
                        WHERE cx BETWEEN ? AND ? AND cy BETWEEN ? AND ?');
    $st->bindValue(1, $cx0, SQLITE3_INTEGER); $st->bindValue(2, $cx1, SQLITE3_INTEGER);
    $st->bindValue(3, $cy0, SQLITE3_INTEGER); $st->bindValue(4, $cy1, SQLITE3_INTEGER);
    $r = $st->execute();
    $seen = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        // A polygon spanning several cells comes back once per cell.
        $k = crc32($row['geom']);
        if (isset($seen[$k])) { continue; }
        $seen[$k] = true;
        $out[] = [$row['cls'], unpack_geom($row['geom'])];
    }

    $st = $db->prepare('SELECT cls, geom FROM bigarea
                        WHERE minx <= ? AND maxx >= ? AND miny <= ? AND maxy >= ?');
    $st->bindValue(1, $e); $st->bindValue(2, $w);
    $st->bindValue(3, $n); $st->bindValue(4, $s);
    $r = $st->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $out[] = [$row['cls'], unpack_geom($row['geom'])];
    }

    // Water over land, so a lake inside a wood is drawn as a lake; otherwise
    // the order polygons happen to arrive in decides what you see.
    usort($out, function ($a, $b) {
        $rank = function ($c) { return ($c === 1 || $c === 2) ? 1 : 0; };
        return $rank($a[0]) <=> $rank($b[0]);
    });
    return $out;
}

/** Does this store have ground cover? Older ones do not. */
function has_areas(SQLite3 $db): bool {
    return table_exists($db, 'area');
}

function table_exists(SQLite3 $db, string $name): bool {
    static $seen = [];
    $key = spl_object_hash($db) . '/' . $name;
    if (!isset($seen[$key])) {
        $r = @$db->querySingle("SELECT name FROM sqlite_master
                                WHERE type='table' AND name='" . $name . "'");
        $seen[$key] = ($r !== null && $r !== false);
    }
    return $seen[$key];
}

/** Does this store have building footprints? Older ones do not. */
function has_buildings(SQLite3 $db): bool {
    return table_exists($db, 'bldg');
}

/** Where a block's assembled bytes are cached. */
function block_file(string $country, int $z, int $bx, int $by): string {
    return TILE_DIR . "/$country/b$z/{$bx}_{$by}.wpk";
}

/**
 * One 16x16 block, assembled and cached.
 *
 * The single place a block is built, so the warmer and the live request
 * cannot drift apart in what they produce - which they did when warming
 * still wrote per-tile files nothing read any more.
 *
 *     "WPK1"  u8 zoom  u32 count
 *     per tile:  u32 x  u32 y  u32 length  bytes
 */
function block_bytes(string $country, int $z, int $bx, int $by): string {
    $f = block_file($country, $z, $bx, $by);
    if (is_file($f)) {
        $b = file_get_contents($f);
        if ($b !== false && strlen($b) > 9) { return $b; }
    }

    $span = 1 << $z;
    $x0 = $bx << 4;
    $y0 = $by << 4;
    $parts = '';
    $count = 0;
    for ($i = 0; $i < 16; $i++) {
        for ($j = 0; $j < 16; $j++) {
            $tx = $x0 + $i;
            $ty = $y0 + $j;
            if ($tx < 0 || $ty < 0 || $tx >= $span || $ty >= $span) { continue; }
            $png = render_tile($country, $z, $tx, $ty);
            $parts .= pack('NNN', $tx, $ty, strlen($png)) . $png;
            $count++;
        }
    }
    $body = 'WPK1' . pack('C', $z) . pack('N', $count) . $parts;

    @mkdir(dirname($f), 0755, true);
    // Written under a temporary name and moved, so a request arriving while
    // this one is still writing cannot read a half block.
    $tmp = $f . '.' . getmypid();
    if (@file_put_contents($tmp, $body) === strlen($body)) {
        @rename($tmp, $f);
    } else {
        @unlink($tmp);
    }
    return $body;
}

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

    // First occurrence wins, not last.
    //
    // road_classes() lists a road and then its slip road under the same class
    // code - motorway then motorway_link - so assigning blindly left every
    // motorway drawn with the slip road's width and colour, and palette
    // entries 26 and 27, the two brightest, were never used at all. The
    // hierarchy the palette was built around simply did not reach the screen.
    $classes = [];
    foreach (road_classes() as $spec) {
        if (!isset($classes[$spec[0]])) { $classes[$spec[0]] = $spec; }
    }

    // Ground cover under everything else, from z12 up. Below that a wood is
    // a smudge and the shape of the coast is all that reads.
    if ($z >= 12 && has_areas($db)) {
        foreach (areas_in($db, $w - $mw, $s - $mh, $e + $mw, $n + $mh) as [$cls, $pts]) {
            $poly = [];
            foreach ($pts as $pt) {
                $poly[] = (int) ((lon_to_tile($pt[0], $z) - $x) * TILE_PX);
                $poly[] = (int) ((lat_to_tile($pt[1], $z) - $y) * TILE_PX);
            }
            if (count($poly) >= 6) {
                imagefilledpolygon($im, $poly, count($poly) >> 1,
                        $grey[max(1, min(15, $cls))]);
            }
        }
    }

    // Buildings first, under everything. They are context, not detail: at
    // z15 a house is two or three pixels, so what they give is the texture of
    // a built-up area against open ground - which is most of what tells you
    // where you are on a screen this size. Only from z14 up, below which they
    // would be a smear.
    if ($z >= 14 && has_buildings($db)) {
        $fill = $grey[3];
        foreach (buildings_in($db, $w, $s, $e, $n) as $b) {
            $px0 = (int) ((lon_to_tile($b[0], $z) - $x) * TILE_PX);
            $py0 = (int) ((lat_to_tile($b[3], $z) - $y) * TILE_PX);   // north
            $px1 = (int) ((lon_to_tile($b[2], $z) - $x) * TILE_PX);
            $py1 = (int) ((lat_to_tile($b[1], $z) - $y) * TILE_PX);
            // imagefilledrectangle covers both endpoints, so a box drawn
            // from px0 to px1 is one pixel wider than it is. Left uncorrected
            // that inflates every building by a pixel in each direction,
            // which at three pixels across is a third again - and a city tile
            // came out as one solid block of fill.
            if ($px1 > $px0) { $px1--; }
            if ($py1 > $py0) { $py1--; }
            imagefilledrectangle($im, $px0, $py0, $px1, $py1, $fill);
        }
    }

    $segs = segments_in($db, $w - $mw, $s - $mh, $e + $mw, $n + $mh, $z);

    // Drawn least important first, so a motorway is never buried under the
    // service road that crosses it.
    usort($segs, function ($a, $b) { return $b['cls'] <=> $a['cls']; });

    $scale = (1 << $z) * TILE_PX;
    foreach ($segs as $seg) {
        $spec = $classes[$seg['cls']] ?? [6, 1, 8, 13];
        imagesetthickness($im, $spec[1]);
        $col = $grey[max(1, min(31, $spec[2]))];

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

/** Assumed speeds where the data does not say, km/h. Anything absent from
 *  this table is not drivable and is left out of the graph entirely. */
function drive_speeds(): array {
    /*
     * What a car actually averages on each kind of road, not what the sign
     * says.
     *
     * The first version used the speed limit, and the routes came out 25 to
     * 30 per cent quicker than the reference router - Amsterdam to Utrecht in
     * 31 minutes against 43. That is not only a wrong arrival time. Cost
     * decides the route, and overrating town roads against motorways makes
     * the search prefer the direct way through everywhere rather than the
     * long way round on a road built for it, which is why our distances came
     * out shorter than they should have been.
     *
     * These are roughly what OSRM's car profile uses, which is derived from
     * measurement rather than from signage.
     */
    return [
        'motorway' => 95, 'motorway_link' => 60,
        'trunk' => 80, 'trunk_link' => 50,
        'primary' => 60, 'primary_link' => 40,
        'secondary' => 50, 'secondary_link' => 35,
        'tertiary' => 40, 'tertiary_link' => 30,
        'unclassified' => 30, 'residential' => 22, 'living_street' => 8,
        'service' => 12, 'track' => 10, 'unknown' => 25,
    ];
}

/*
 * Distances on the ground.
 *
 * Everything here used to measure a degree of latitude as 110540 metres. That
 * is the value at the equator: it is 111267 in the Netherlands and 111412 in
 * Scotland, so every distance the server computed was 0.65% short, and short
 * by more the further north the country. The error is one-sided - it never
 * cancels - and it fed straight into arc costs, route lengths and arrival
 * times, all of which came out optimistic.
 *
 * These are the usual WGS84 series, good to about a metre in a degree, which
 * is well past what a road measured between junctions can make use of.
 */

/** Metres in a degree of latitude, at this latitude. */
function metres_per_lat(float $lat): float {
    $p = deg2rad($lat);
    return 111132.954 - 559.822 * cos(2 * $p) + 1.175 * cos(4 * $p);
}

/** Metres in a degree of longitude, at this latitude. */
function metres_per_lon(float $lat): float {
    $p = deg2rad($lat);
    return 111412.84 * cos($p) - 93.5 * cos(3 * $p) + 0.118 * cos(5 * $p);
}

/** Between two points. Flat-earth at the midpoint, which over anything
 *  shorter than a country is indistinguishable from the great circle. */
function ground(float $la1, float $lo1, float $la2, float $lo2): float {
    $mid = ($la1 + $la2) / 2;
    $dy = ($la2 - $la1) * metres_per_lat($mid);
    $dx = ($lo2 - $lo1) * metres_per_lon($mid);
    return sqrt($dx * $dx + $dy * $dy);
}

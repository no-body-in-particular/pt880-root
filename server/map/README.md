# Map server

What the watch talks to. Renders road tiles and serves them in blocks.

Deployed at `/var/www/hiawatha/map/`; the watch reaches it as
`https://coredump.ws/map/`, set per-device in `/sdcard/Documents/map.txt`.

## Adding a country

    ./build_country.sh germany europe/germany
    ./warm.sh germany 15

Downloads from Geofabrik, extracts only the layers that are drawn, and
imports roads, ground cover, buildings, railways and waterways. Nothing in
the pipeline is specific to any one country: the watch asks country.php which
map covers its position, and country.php lists every .db in data/.

## Layers

| | drawn as |
|---|---|
| roads | twelve widths and colours by importance, brightest for motorway |
| buildings | bounding boxes, filled |
| landuse, natural | forest, park, grass, farmland, industrial, sand, wetland |
| water areas | lakes, docks, wide rivers |
| waterways | rivers, canals and streams, as lines |
| railways | one colour, under the roads |

Tiles are 8-bit palette PNGs with a 32-colour palette. PNG has no 5-bit depth
- 1, 2, 4 and 8 are the choices - so going past sixteen colours doubles the
raw pixel data, which measured over a spread of terrain costs 6%: a dense
city tile grows 2%, an empty one 41% but only from 192 to 270 bytes. A
country goes from about 42MB to 44. That buys telling a forest from a field.

Ground cover occupies palette 1..15 and stays dark, because it is context and
must never compete with the route. Roads keep 16..27 and the cool half of the
wheel, so the amber route line the watch draws on top is unmistakable.

## Endpoints

| | |
|---|---|
| `pack.php` | a 16x16 block of tiles in one response - what the watch actually uses |
| `tile.php` | a single tile, for looking at one by hand |
| `roads.php` | road vectors for a tile |
| `route.php` | a route, proxied from the OSRM demo server and cached a day |
| `country.php` | which country covers a position, and its bounds |

## Tools

| | |
|---|---|
| `build_country.sh` | the whole pipeline for a new country, from download |
| `import.php` | roads shapefile into SQLite |
| `import_areas.php` | landuse, water and natural polygons |
| `import_buildings.php` | building footprints, as bounding boxes |
| `import_lines.php` | railways and waterways |
| `warm.php` / `warm.sh` | render a country ahead of time, sharded, yielding to live requests |
| `convert.php` | repack an old per-tile cache into block files |

## Why blocks

A country at z15 is about 150,000 tiles averaging 515 bytes. One file per
tile is 150,000 directory entries holding 42MB of data in 600MB of disk,
since the filesystem block is 4kB and the tile is not - and on this host
every one of those writes goes through dm-crypt. Serving a block meant
opening 256 files.

So a block is one file, on the server and on the watch alike. Measured on the
watch: writing a block fell from 445ms to 4ms, and a country download from
about 37 minutes to about 4.

`pack.php` still reads a per-tile cache where one exists, so a machine
upgrading from the old layout loses none of the rendering already done, but
it never writes one.

## Data

`data/<country>.db` is built by `import.php` and is not in this repository -
the Netherlands alone is 119MB. Rendered tiles live in `tiles/` and are a
cache: deleting them costs time, not data.

The tile directory must be writable by the web server user. Note that
php-cgi here runs with umask 0117, so a plain `mkdir(0755)` lands as
`rw-r-----` - a directory with no execute bit, which cannot be written into.
`lib.php` sets a sane umask for that reason; without it every cache write
fails silently behind its `@` and every request re-renders 256 tiles.

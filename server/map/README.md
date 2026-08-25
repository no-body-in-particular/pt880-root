# Map server

What the watch talks to. Renders road tiles and serves them in blocks.

Deployed at `/var/www/hiawatha/map/`; the watch reaches it as
`https://coredump.ws/map/`, set per-device in `/sdcard/Documents/map.txt`.

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
| `import.php` | Geofabrik roads shapefile into SQLite |
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

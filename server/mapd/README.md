# mapd

The map server the watch talks to: tiles, road vectors and routing graphs.

Replaces the PHP that used to serve `/map/`. It runs on localhost and
hiawatha reverse proxies to it, so the watch keeps talking to
`https://coredump.ws/map/` and sees no change at all. That arrangement is
deliberate: the watch's TLS is BouncyCastle speaking to hiawatha's exact
cipher configuration, and getting that working on a 2013 device took long
enough that it is not worth risking to save a hop.

## Why

A block of 256 tiles, dense city, measured on the same machine and the same
data:

| | time | bytes |
|---|---|---|
| PHP + GD | 16,320 ms | 1,441,575 |
| mapd, cold caches | 664 ms | 1,414,675 |
| mapd, warm caches | 408 ms | |

Two things account for it, and neither is Rust being fast at arithmetic.

The tiles in a block are independent and there are 256 of them, but `php-cgi`
is one process per request and rendered them one after another while seven of
the eight cores sat idle. And nothing survived a request: a decoded map cell
was thrown away when the process exited, so a block decoded the same cells a
dozen times over. Here a cell stays decoded for as long as the service runs,
and the tiles of a block are rendered across every core.

The output is an 8-bit palette PNG written without filtering. Filtering helps
a photograph, where neighbouring bytes are nearly equal, and hurts a palette
image, where they are unrelated indices - left on the encoder's default,
tiles came out nearly twice the size GD manages.

## Tiles are not cached to disk

Measured on this data: an average block renders in 120ms and reads back from
disk in 40ms, while transferring it to the watch over wifi takes 2.2 seconds.
So the cache saved four per cent of a download and cost 215MB per country -
which for Europe and America would have been tens of gigabytes.

It also had to be wiped by hand every time the rendering changed, and twice
in one week that was noticed only after the watch had already downloaded the
stale version. Rendering afresh is always correct and nearly always faster
than the network it feeds. A dense city block takes 110ms with the cell
caches warm, which they stay, because this is a service rather than a script.

Twenty recent blocks are kept in memory, which covers the watch retrying one
it failed to read - the only repeat that actually happens, since the watch
stores what it downloads.

Set `MAP_DISK_CACHE=1` to put the disk cache back.

## Running

    rc-service mapd start

Reads `MAP_ROOT` (default `/var/www/hiawatha/map`) and `MAP_ADDR` (default
`127.0.0.1:8088`). Runs as `hiawatha` so the tile cache it writes is the one
the web server can read.

`MAP_OSRM` (default `https://router.project-osrm.org`) is where `/route.php`
goes. The watch routes on the graph on its own card whenever it has one, so
this endpoint only answers for a country that has not been downloaded - but
that is exactly the case where the watch cannot fall back on itself, so it is
worth pointing at an OSRM you run. The default is the project's demo server,
which is explicitly not offered for production use: it rate-limits, it makes
no uptime promise, and it sees every destination the watch is sent to.

## Going back

The PHP is still in `/var/www/hiawatha/map` and still works. Comment out the
`ReverseProxy = ^/map/` line in hiawatha.conf and reload.

## Endpoints

Identical to the PHP, including the `.php` in the paths - the watch has those
URLs compiled in, and a rename would be a flag day for no benefit.

| | |
|---|---|
| `pack.php` | a 16x16 block of tiles in one response |
| `tile.php` | a single tile |
| `country.php` | which country covers a position, or every country present |
| `graph.php` | a routing graph, whole or cut to a bounding box |
| `route.php` | a route, proxied from OSRM and cached a day |
| `health` | for checking it is up |

A country need not be named: tile numbers are global, so `mapd` works out
which database to render from by where the request is asking about.

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

## Running

    rc-service mapd start

Reads `MAP_ROOT` (default `/var/www/hiawatha/map`) and `MAP_ADDR` (default
`127.0.0.1:8088`). Runs as `hiawatha` so the tile cache it writes is the one
the web server can read.

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

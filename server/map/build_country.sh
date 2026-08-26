#!/usr/bin/env bash
# Build a country's map from scratch.
#
#     ./build_country.sh germany europe/germany
#     ./build_country.sh netherlands europe/netherlands
#
# The first argument is the name the watch will use - it appears in
# country.php and in /sdcard/maps/<name> on the card. The second is the
# Geofabrik path, without the -free.shp.zip suffix. Anything Geofabrik
# publishes as a region works: europe/germany, europe/france,
# north-america/us/california.
#
# Downloads, extracts only the layers that are drawn, and imports roads,
# ground cover, buildings, railways and waterways. Nothing here is specific
# to any one country.
set -e

NAME="${1:?usage: build_country.sh <name> <geofabrik-path>}"
REGION="${2:?e.g. europe/germany}"
Z="${Z:-15}"

HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"
DATA="$HERE/data"
SRC="$DATA/$NAME"
ZIP="$DATA/$NAME.shp.zip"
URL="https://download.geofabrik.de/$REGION-latest-free.shp.zip"

mkdir -p "$SRC"

if [ ! -s "$ZIP" ]; then
    echo "== downloading $URL"
    curl -fL --retry 3 -o "$ZIP.part" "$URL"
    mv "$ZIP.part" "$ZIP"
else
    echo "== $ZIP already here"
fi

# Only what is drawn. The attribute tables are only extracted where the class
# has to be read: buildings are drawn whatever they are, so their 1.7GB of
# attributes are left in the archive.
echo "== extracting"
unzip -o -q "$ZIP" -d "$SRC" \
    "gis_osm_roads_free_1.*" \
    "gis_osm_landuse_a_free_1.shp" "gis_osm_landuse_a_free_1.shx" "gis_osm_landuse_a_free_1.dbf" \
    "gis_osm_water_a_free_1.shp"   "gis_osm_water_a_free_1.shx" \
    "gis_osm_natural_a_free_1.shp" "gis_osm_natural_a_free_1.shx" "gis_osm_natural_a_free_1.dbf" \
    "gis_osm_buildings_a_free_1.shp" "gis_osm_buildings_a_free_1.shx" \
    "gis_osm_railways_free_1.*" \
    "gis_osm_waterways_free_1.*" || true

echo "== roads"
php import.php "$NAME" "$SRC/gis_osm_roads_free_1"
echo "== ground cover"
php import_areas.php "$NAME"
echo "== buildings"
php import_buildings.php "$NAME"
echo "== railways and waterways"
php import_lines.php "$NAME"
echo "== routing graph"
php build_graph.php "$NAME"

# The web server has to be able to write the tile cache for this country.
if id hiawatha >/dev/null 2>&1; then
    chown -R hiawatha:hiawatha "$DATA/$NAME.db" 2>/dev/null || true
fi
chmod 644 "$DATA/$NAME.db"
chmod 644 "$DATA/$NAME.graph" "$DATA/$NAME.graph.gz" 2>/dev/null || true

echo
echo "== done: $NAME"
sqlite3 "$DATA/$NAME.db" "SELECT k || ' = ' || v FROM meta;" 2>/dev/null || true
echo
echo "the watch will find it automatically - country.php lists every .db here."
echo "to render it ahead of time:  ./warm.sh $NAME $Z"

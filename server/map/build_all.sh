#!/usr/bin/env bash
# Build every country the watch might be taken to, one after another.
#
#     ./build_all.sh britain          the British Isles
#     ./build_all.sh europe           the countries most likely to be driven
#     ./build_all.sh america          the United States and Canada
#     ./build_all.sh all
#
# Sequential on purpose. Each import holds its whole layer in memory - ten
# million building boxes for the Netherlands alone - so two at once is how a
# machine starts swapping. Each build removes its own extract afterwards,
# which matters when Germany unpacks to fifteen gigabytes.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"

# Geofabrik publishes shapefiles only for regions under a size limit, and
# files Britain under united-kingdom rather than great-britain - which is why
# an obvious-looking guess at the path downloads an HTML error page. Checked
# against their index-v1.json; see list_regions.sh.
BRITAIN="england:europe/united-kingdom/england
         scotland:europe/united-kingdom/scotland
         wales:europe/united-kingdom/wales
         ireland-and-northern-ireland:europe/ireland-and-northern-ireland"

# Germany, France and Italy have no whole-country shapefile - only their
# states and regions do - so the neighbours you would actually drive to come
# first, and the big three are added a piece at a time.
EUROPE="belgium:europe/belgium luxembourg:europe/luxembourg
        switzerland:europe/switzerland austria:europe/austria
        denmark:europe/denmark czech-republic:europe/czech-republic
        poland:europe/poland spain:europe/spain portugal:europe/portugal
        sweden:europe/sweden norway:europe/norway
        nordrhein-westfalen:europe/germany/nordrhein-westfalen
        niedersachsen:europe/germany/niedersachsen
        bayern:europe/germany/bayern
        baden-wuerttemberg:europe/germany/baden-wuerttemberg
        hessen:europe/germany/hessen
        rheinland-pfalz:europe/germany/rheinland-pfalz
        nord-pas-de-calais:europe/france/nord-pas-de-calais
        picardie:europe/france/picardie
        ile-de-france:europe/france/ile-de-france"

# The United States has no whole-country shapefile either, and California is
# published as two halves. States, then, rather than regions.
AMERICA="new-york:north-america/us/new-york
         new-jersey:north-america/us/new-jersey
         massachusetts:north-america/us/massachusetts
         pennsylvania:north-america/us/pennsylvania
         florida:north-america/us/florida
         texas:north-america/us/texas
         illinois:north-america/us/illinois
         washington:north-america/us/washington
         oregon:north-america/us/oregon
         norcal:north-america/us/california/norcal
         socal:north-america/us/california/socal"

case "${1:-all}" in
    britain) LIST="$BRITAIN" ;;
    europe)  LIST="$EUROPE" ;;
    america) LIST="$AMERICA" ;;
    all)     LIST="$BRITAIN $EUROPE $AMERICA" ;;
    *)       LIST="$1" ;;
esac

ok=0; failed=0
for entry in $LIST; do
    name="${entry%%:*}"
    region="${entry#*:}"
    if [ -f "data/$name.graph" ]; then
        echo "== $name already built, skipping"
        ok=$((ok + 1))
        continue
    fi
    free=$(df --output=avail -BG / | tail -1 | tr -dc '0-9')
    if [ "$free" -lt 40 ]; then
        echo "!! only ${free}G free, stopping before $name"
        break
    fi
    echo
    echo "######## $name ($region), ${free}G free"
    if ./build_country.sh "$name" "$region"; then
        ok=$((ok + 1))
    else
        echo "!! $name failed"
        failed=$((failed + 1))
        rm -rf "data/$name"
    fi
done

echo
echo "built $ok, failed $failed"
ls -la data/*.graph 2>/dev/null | awk '{printf "  %-40s %.0f MB\n",$9,$5/1048576}'

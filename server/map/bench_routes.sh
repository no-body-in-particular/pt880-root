#!/usr/bin/env bash
# The watch's router against two independent ones.
#
# OSRM and Valhalla are both built on OpenStreetMap and both optimise time,
# and they disagree with each other by about a tenth - which is the useful
# yardstick. Being within the spread between two professional routers is the
# standard to hold ours to; matching either exactly is neither possible nor
# meaningful, because they differ in what they think a road costs.
set -u
cd "$(dirname "$0")"

osrm() {
  curl -s --max-time 40 "https://router.project-osrm.org/route/v1/driving/$2,$1;$4,$3?overview=false" \
   | python3 -c "
import json,sys
try:
    r=json.load(sys.stdin)['routes'][0]; print('%.1f %.0f' % (r['distance']/1000, r['duration']/60))
except Exception: print('- -')"
}
valhalla() {
  curl -s --max-time 40 "https://valhalla1.openstreetmap.de/route" -X POST -H 'Content-Type: application/json' \
   -d "{\"locations\":[{\"lat\":$1,\"lon\":$2},{\"lat\":$3,\"lon\":$4}],\"costing\":\"auto\",\"directions_options\":{\"units\":\"kilometers\"}}" \
   | python3 -c "
import json,sys
try:
    s=json.load(sys.stdin)['trip']['summary']; print('%.1f %.0f' % (s['length'], s['time']/60))
except Exception: print('- -')"
}
ours() {
  php test_astar.php netherlands "$1" "$2" "$3" "$4" 2>/dev/null \
   | awk '/^route:/ {gsub(",","",$2); gsub(",","",$4); gsub(",","",$8); print $2" "$4" "$8}
          /NO ROUTE/ {print "0 0 0"}'
}

pairs=(
"52.3702 4.8952 52.0859 5.1089 Amsterdam-Utrecht"
"51.9244 4.4777 52.0907 5.1214 Rotterdam-Utrecht"
"52.0859 5.1089 51.4311 5.4800 Utrecht-Eindhoven"
"53.2194 6.5665 52.2215 6.8937 Groningen-Enschede"
"51.4426 3.5736 51.9244 4.4777 Vlissingen-Rotterdam"
"52.1561 5.3878 52.0859 5.1089 Amersfoort-Utrecht"
"52.0907 5.1214 52.0616 5.1084 Utrecht-local"
"51.5719 4.7683 51.6978 5.3037 Breda-DenBosch"
"53.2012 5.7999 52.5168 6.0830 Leeuwarden-Meppel"
"52.3874 4.6462 52.1601 4.4970 Haarlem-Noordwijk"
"51.8433 5.8544 52.2215 6.8937 Nijmegen-Enschede"
"52.9908 6.5642 52.3702 4.8952 Assen-Amsterdam"
)

printf "%-22s %7s %7s %7s  %7s %6s %6s  %7s %6s\n" \
  "route" "ours" "osrm" "valh" "ours-m" "osrm-m" "valh-m" "settled" "ms"
fail=0
for p in "${pairs[@]}"; do
  set -- $p; fl=$1; fo=$2; tl=$3; to=$4; name=$5
  set -- $(ours "$fl" "$fo" "$tl" "$to"); okm=$1; omin=$2; set=$3
  set -- $(osrm "$fl" "$fo" "$tl" "$to"); rkm=$1; rmin=$2
  sleep 1
  set -- $(valhalla "$fl" "$fo" "$tl" "$to"); vkm=$1; vmin=$2
  sleep 1
  [ "$okm" = "0" ] && fail=$((fail+1))
  printf "%-22s %7s %7s %7s  %7s %6s %6s  %7s\n" \
    "$name" "$okm" "$rkm" "$vkm" "$omin" "$rmin" "$vmin" "$set"
done
echo
echo "routes we failed to find: $fail of ${#pairs[@]}"

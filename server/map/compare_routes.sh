#!/usr/bin/env bash
# What the watch's own router finds, against what the routing service does.
#
# The watch routes on a graph built here from the same road data, with a
# simpler cost model and no turn restrictions. It will not match OSRM exactly
# and does not need to - but it does need to be close, because a route that
# is materially worse is a route that sends someone the wrong way.
set -u
cd "$(dirname "$0")"

pairs=(
  "52.3702 4.8952 52.0859 5.1089 Amsterdam-Utrecht"
  "51.9244 4.4777 52.0907 5.1214 Rotterdam-Utrecht"
  "52.0859 5.1089 51.4311 5.4800 Utrecht-Eindhoven"
  "53.2194 6.5665 52.2215 6.8937 Groningen-Enschede"
  "51.4426 3.5736 51.9244 4.4777 Vlissingen-Rotterdam"
  "52.1561 5.3878 52.0859 5.1089 Amersfoort-Utrecht"
)

printf "  %-24s %10s %10s %8s   %8s %8s\n" "route" "ours km" "osrm km" "diff" "ours min" "osrm min"
for p in "${pairs[@]}"; do
  set -- $p
  flat=$1; flon=$2; tlat=$3; tlon=$4; name=$5

  ours=$(php test_astar.php netherlands "$flat" "$flon" "$tlat" "$tlon" 2>/dev/null \
         | awk '/^route:/ {gsub(",","",$2); gsub(",","",$4); print $2" "$4}')
  ok=$(curl -s --max-time 40 \
      "https://router.project-osrm.org/route/v1/driving/$flon,$flat;$tlon,$tlat?overview=false" \
      | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    r=d['routes'][0]
    print('%.1f %.0f' % (r['distance']/1000.0, r['duration']/60.0))
except Exception:
    print('- -')")

  set -- $ours; okm=${1:-0}; omin=${2:-0}
  set -- $ok;   rkm=${1:-0}; rmin=${2:-0}
  diff=$(python3 -c "
o=float('${okm:-0}' or 0); r=float('${rkm:-0}' or 0)
print('%+.1f%%' % (100*(o-r)/r) if r else 'n/a')")
  printf "  %-24s %10s %10s %8s   %8s %8s\n" "$name" "$okm" "$rkm" "$diff" "$omin" "$rmin"
  sleep 1
done

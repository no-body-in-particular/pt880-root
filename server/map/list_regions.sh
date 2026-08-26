#!/usr/bin/env bash
# What can actually be built, according to Geofabrik's own index.
#
#     ./list_regions.sh              everything with a shapefile
#     ./list_regions.sh germany      just what matches
#
# Worth having because guessing at region paths does not work: Geofabrik
# publishes shapefiles only for regions under a size limit, so Germany, France
# and the United States have none as whole countries while every one of their
# states does - and Britain is filed under united-kingdom, not great-britain.
# A wrong guess downloads an HTML apology rather than failing.
set -e

HERE="$(cd "$(dirname "$0")" && pwd)"
INDEX="$HERE/data/geofabrik-index.json"
MATCH="${1:-}"

if [ ! -s "$INDEX" ] || [ -n "$(find "$INDEX" -mtime +30 2>/dev/null)" ]; then
    echo "fetching the region index..." >&2
    curl -sL -o "$INDEX" "https://download.geofabrik.de/index-v1.json"
fi

python3 - "$INDEX" "$MATCH" <<'PY'
import json, sys
index, match = sys.argv[1], sys.argv[2].lower()
by = {p['id']: p for p in (f['properties'] for f in json.load(open(index))['features'])}

def path(pid):
    out = []
    while pid:
        out.append(pid)
        pid = by.get(pid, {}).get('parent')
    return "/".join(reversed(out))

rows = []
for pid, p in by.items():
    if 'shp' not in (p.get('urls') or {}):
        continue
    full = path(pid)
    if match and match not in full.lower() and match not in p['name'].lower():
        continue
    rows.append((full, p['name']))

for full, name in sorted(rows):
    print("  %-52s %s" % (full, name))
print("\n%d region%s with shapefiles%s"
      % (len(rows), "" if len(rows) == 1 else "s",
         (" matching %r" % match) if match else ""))
PY

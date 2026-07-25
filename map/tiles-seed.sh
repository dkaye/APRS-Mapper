#!/usr/bin/env bash
# Pre-seed the PERMANENT base tile cache for a lat/lon box and zoom range, by
# fetching from OpenStreetMap politely (single-threaded, small delay, proper
# User-Agent). Run once per area — these tiles are then served locally forever,
# so event offline downloads never touch OpenStreetMap.
#
# Usage: tiles-seed.sh <minLat> <maxLat> <minLon> <maxLon> <minZoom> <maxZoom>
# Marin (matches the app's offline download range):
#   tiles-seed.sh 37.80 38.25 -123.05 -122.30 10 14
#
# ©2026 Doug Kaye, K6DRK <doug@rds.com>
set -u

minlat="${1:?minLat}"; maxlat="${2:?maxLat}"; minlon="${3:?minLon}"; maxlon="${4:?maxLon}"
minz="${5:?minZoom}";   maxz="${6:?maxZoom}"

BASE="$(cd "$(dirname "$0")" && pwd)/tiles/base"
OSM="https://tile.openstreetmap.org"
UA="MARS-APRS-tile-seed/1.0 (+https://marsaprs.org; doug@rds.com)"
DELAY=0.25          # seconds between OSM requests — stay polite
mkdir -p "$BASE"

# lon/lat -> tile x/y at zoom z (slippy-map math via python for the trig)
tiles_for_zoom() {  # $1=z  -> prints "xmin xmax ymin ymax"
  python3 - "$1" "$minlat" "$maxlat" "$minlon" "$maxlon" <<'PY'
import sys, math
z=int(sys.argv[1]); minlat,maxlat,minlon,maxlon=map(float,sys.argv[2:6])
n=2**z
def xtile(lon): return int((lon+180.0)/360.0*n)
def ytile(lat):
    r=math.radians(lat)
    return int((1.0-math.asinh(math.tan(r))/math.pi)/2.0*n)
xs=sorted((xtile(minlon),xtile(maxlon)))
ys=sorted((ytile(maxlat),ytile(minlat)))  # y grows southward
print(xs[0],xs[1],ys[0],ys[1])
PY
}

total=0; fetched=0; skipped=0; failed=0
for z in $(seq "$minz" "$maxz"); do
  read xmin xmax ymin ymax < <(tiles_for_zoom "$z")
  n=$(( (xmax-xmin+1)*(ymax-ymin+1) ))
  total=$((total+n))
  echo "z$z: x $xmin..$xmax  y $ymin..$ymax  ($n tiles)"
  for x in $(seq "$xmin" "$xmax"); do
    mkdir -p "$BASE/$z/$x"
    for y in $(seq "$ymin" "$ymax"); do
      f="$BASE/$z/$x/$y.png"
      [ -s "$f" ] && { skipped=$((skipped+1)); continue; }
      code=$(curl -s -o "$f" -w '%{http_code}' -A "$UA" --max-time 15 "$OSM/$z/$x/$y.png")
      if [ "$code" = "200" ] && [ -s "$f" ]; then fetched=$((fetched+1));
      else rm -f "$f"; failed=$((failed+1)); echo "  FAIL $z/$x/$y (HTTP $code)"; fi
      sleep "$DELAY"
    done
  done
done
echo "seed done: $total total, $fetched fetched, $skipped already present, $failed failed"

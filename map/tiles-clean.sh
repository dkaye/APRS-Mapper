#!/usr/bin/env bash
# Trim the on-demand tile browse cache: delete tiles not (re)fetched in 90 days.
# Old, unused areas drop out (bounding the size); popular areas get re-fetched
# fresh on next view, keeping the map current. NEVER touches tiles/base — the
# permanent, pre-seeded event areas.
#
# Run nightly from cron on the tile server (aprs-pi):
#   17 4 * * * /var/www/html/tiles-clean.sh >> /var/log/tiles-clean.log 2>&1
#
# ©2026 Doug Kaye, K6DRK <doug@rds.com>
set -u

CACHE="$(cd "$(dirname "$0")" && pwd)/tiles/cache"
[ -d "$CACHE" ] || exit 0

BEFORE=$(find "$CACHE" -type f -name '*.png' 2>/dev/null | wc -l | tr -d ' ')
find "$CACHE" -type f -name '*.png' -mtime +90 -delete 2>/dev/null
find "$CACHE" -type d -empty -delete 2>/dev/null
AFTER=$(find "$CACHE" -type f -name '*.png' 2>/dev/null | wc -l | tr -d ' ')
SIZE=$(du -sh "$CACHE" 2>/dev/null | cut -f1)

echo "$(date '+%Y-%m-%d %H:%M:%S') tiles-clean: browse cache $BEFORE -> $AFTER tiles, $SIZE on disk"

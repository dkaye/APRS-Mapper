#!/bin/bash
# Sync local aprs/ to the Pi's /var/www/html/.
#
# PUSHES:   all PHP/JS/CSS source files, course files (geojson/json/gpx/kml)
# PROTECTS: config.yaml, trackers.json, configs/*.yaml (Pi's live data & versions)
# SKIPS:    Pi-only files (aprs-daemon.service, aprs-daemon.sh, temp/)

set -euo pipefail

SRC="/Users/doug/aprs/"
DST="aprs-pi:/var/www/html/"

rsync -avz --delete --omit-dir-times --no-perms \
  --exclude='.DS_Store' \
  --exclude='trackers.json' \
  --exclude='config.yaml' \
  --exclude='aprs-daemon.service' \
  --exclude='aprs-daemon.sh' \
  --exclude='temp/' \
  --exclude='configs/*.yaml' \
  --exclude='configs/*.yml' \
  --exclude='sync-to-pi.sh' \
  --exclude='admin/password.txt' \
  "$SRC" "$DST"

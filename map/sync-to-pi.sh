#!/bin/bash
# Sync local map/ to the Pi's /var/www/html/.
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

# PUSHES:   all PHP/JS/CSS source files, course files (geojson/json/gpx/kml)
# PROTECTS: config.yaml, trackers.json, events/ (Pi's live data & versions)
# SKIPS:    Pi-only overlay dirs (netbird/, admin/, igate/, display/, server/, wifi/)

set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)/"
DST="pi@192.168.0.180:/var/www/html/"

rsync -avz --delete --omit-dir-times --no-perms \
  --exclude='.DS_Store' \
  --exclude='.git/' \
  --exclude='*.MD' \
  --exclude='*.md' \
  --exclude='*.py' \
  --exclude='trackers.json' \
  --exclude='config.yaml' \
  --exclude='aprs-daemon.service' \
  --exclude='aprs-daemon.sh' \
  --exclude='temp/' \
  --exclude='events/' \
  --exclude='pi-tools/' \
  --exclude='sync-to-pi.sh' \
  --exclude='netbird/' \
  --exclude='admin/' \
  --exclude='igate/' \
  --exclude='display/' \
  --exclude='server/' \
  --exclude='wifi/' \
  --exclude='tests/' \
  --exclude='vendor/' \
  "$SRC" "$DST"

#!/usr/bin/env bash
# Deploy server files to the Pi.
# Uploaded course files (courses/*.gpx, *.geojson) are excluded so they survive deploys.
set -e

REMOTE="aprs-pi:/var/www/html/static"

rsync -av \
  --exclude 'courses/*.gpx' \
  --exclude 'courses/*.geojson' \
  --exclude 'courses/*.json' \
  --exclude 'config.json' \
  "$(dirname "$0")/" "$REMOTE/"

echo "Deploy complete."

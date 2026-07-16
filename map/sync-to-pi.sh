#!/bin/bash
# Sync local map/ to the Pi's /var/www/html/.
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/README.md
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

# PUSHES:   all PHP/JS/CSS source files, course files (geojson/json/gpx/kml)
# PROTECTS: config.yaml, trackers.json, events/ (Pi's live data & versions)
# SKIPS:    Pi-only overlay dirs (netbird/, admin/, igate/, display/, server/, wifi/)
#
# NOTE: the Pi's admin/*.php are owned by www-data, so this rsync (run as pi)
#       cannot overwrite them — admin/ changes must be deployed manually, e.g.:
#         scp admin/index.php pi@<host>:/tmp/ && \
#           ssh pi@<host> 'sudo install -o www-data -g www-data -m 755 \
#             /tmp/index.php /var/www/html/admin/index.php'

set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)/"
DST="pi@100.101.158.149:/var/www/html/"

rsync -avz --omit-dir-times --no-perms \
  --exclude='.DS_Store' \
  --exclude='.git/' \
  --exclude='*.MD' \
  --exclude='*.md' \
  --exclude='*.py' \
  --exclude='trackers.json' \
  --exclude='igates.json' \
  --exclude='aidstations.json' \
  --exclude='mobile_trackers.json' \
  --exclude='mobile_history.yaml' \
  --exclude='config.yaml' \
  --exclude='aprs-daemon.service' \
  --exclude='aprs-daemon.sh' \
  --exclude='temp/' \
  --exclude='events/' \
  --exclude='tickets/tickets.json' \
  --exclude='tickets/config.json' \
  --exclude='tickets/versions.json' \
  --exclude='TKT-*/' \
  --exclude='pi-tools/' \
  --exclude='sync-to-pi.sh' \
  --exclude='netbird/' \
  --exclude='admin/password.txt' \
  --exclude='igate/' \
  --exclude='display/' \
  --exclude='server/' \
  --exclude='wifi/' \
  --exclude='tests/' \
  --exclude='vendor/' \
  "$SRC" "$DST"

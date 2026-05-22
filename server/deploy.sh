#!/usr/bin/env bash
# Deploy server files to aprs-pi (marsaprs.org).
#
# Run this on your Mac after editing any files in this directory.
# Builds files.tar.gz and uploads it along with install.sh.
#
# Usage: ./deploy.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

SERVER_DIR="$(cd "$(dirname "$0")" && pwd)"
MAP_DIR="$(cd "$SERVER_DIR/../map" && pwd)"
REMOTE="aprs-pi"
REMOTE_DIR="/var/www/html/server"

echo "=== Server deploy to marsaprs.org ==="

# Create remote directory if needed
ssh "$REMOTE" "mkdir -p $REMOTE_DIR"

# Sync source files to aprs-pi staging, then build the archive there.
# Building on Linux avoids macOS extended-attribute noise in the tarball.
STAGING="$REMOTE_DIR/.staging"
echo "Syncing files to aprs-pi..."
ssh "$REMOTE" "mkdir -p $STAGING/home $STAGING/www $STAGING/bin $STAGING/systemd $STAGING/apache $STAGING/cloudflared"
rsync -a --delete "$SERVER_DIR/home/"        "$REMOTE:$STAGING/home/"
rsync -a --delete "$SERVER_DIR/bin/"         "$REMOTE:$STAGING/bin/"
rsync -a --delete "$SERVER_DIR/systemd/"     "$REMOTE:$STAGING/systemd/"
rsync -a --delete "$SERVER_DIR/apache/"      "$REMOTE:$STAGING/apache/"
rsync -a --delete "$SERVER_DIR/cloudflared/" "$REMOTE:$STAGING/cloudflared/"

# Bundle the map web app as the base www content, then overlay server-specific
# subdirs (netbird/, admin/, wifi/) on top. No git clone needed on fresh install.
echo "Bundling map web app into www staging..."
rsync -a --delete \
    --exclude='.DS_Store' \
    --exclude='.git/' \
    --exclude='trackers.json' \
    --exclude='aprs-daemon.service' \
    --exclude='aprs-daemon.sh' \
    --exclude='backup-from-pi.sh' \
    --exclude='sync-to-pi.sh' \
    --exclude='temp/' \
    --exclude='pi-tools/' \
    "$MAP_DIR/" "$REMOTE:$STAGING/www/"
rsync -a "$SERVER_DIR/www/" "$REMOTE:$STAGING/www/"

echo "Building files.tar.gz on aprs-pi..."
ssh "$REMOTE" "tar -czf $REMOTE_DIR/files.tar.gz -C $STAGING ."

# Upload scripts served directly
echo "Uploading scripts..."
scp "$SERVER_DIR/install.sh" "$REMOTE:$REMOTE_DIR/install.sh"

echo ""
echo "Done. Files live at:"
echo "  https://marsaprs.org/server/install.sh    (bootstrap for new server)"
echo "  https://marsaprs.org/server/files.tar.gz  (server archive)"

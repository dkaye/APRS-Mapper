#!/usr/bin/env bash
# Deploy iGate files to aprs-pi (marsaprs.org).
#
# Run this on your Mac after editing any files in this directory.
# Builds files.tar.gz and uploads it along with install.sh and auto-update.sh.
#
# Usage: ./deploy.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

IGATE_DIR="$(cd "$(dirname "$0")" && pwd)"
REMOTE="aprs-pi"
REMOTE_DIR="/var/www/html/igate"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "=== iGate deploy to marsaprs.org ==="

# Create remote directory if needed
ssh "$REMOTE" "mkdir -p $REMOTE_DIR"

# Sync source files to aprs-pi staging directory, then build the archive there.
# Building on Linux avoids macOS extended-attribute noise in the tarball.
STAGING="$REMOTE_DIR/.staging"
echo "Syncing files to aprs-pi..."
ssh "$REMOTE" "sudo chown -R pi:www-data $REMOTE_DIR 2>/dev/null || true && mkdir -p $STAGING/home $STAGING/direwatch $STAGING/www $STAGING/systemd $STAGING/etc/logrotate.d"
rsync -a --delete "$IGATE_DIR/home/"      "$REMOTE:$STAGING/home/"
rsync -a --delete "$IGATE_DIR/direwatch/" "$REMOTE:$STAGING/direwatch/"
rsync -a --delete "$IGATE_DIR/www/"       "$REMOTE:$STAGING/www/"
rsync -a --delete "$IGATE_DIR/systemd/"   "$REMOTE:$STAGING/systemd/"
rsync -a --delete "$IGATE_DIR/udev/"      "$REMOTE:$STAGING/udev/"
rsync -a --delete "$IGATE_DIR/etc/"       "$REMOTE:$STAGING/etc/"
# auto-update.sh goes in the archive so iGates can update it nightly
scp "$IGATE_DIR/auto-update.sh"  "$REMOTE:$STAGING/home/auto-update.sh"

echo "Building files.tar.gz on aprs-pi..."
ssh "$REMOTE" "tar -czf $REMOTE_DIR/files.tar.gz -C $STAGING ."

# Upload the scripts that are served directly
echo "Uploading scripts..."
scp "$IGATE_DIR/install.sh"     "$REMOTE:$REMOTE_DIR/install.sh"
scp "$IGATE_DIR/auto-update.sh" "$REMOTE:$REMOTE_DIR/auto-update.sh"

# Sync server-side web content (wifi token endpoint served by aprs-pi)
echo "Syncing www/wifi/ to aprs-pi..."
ssh "$REMOTE" "mkdir -p $REMOTE_DIR/wifi"
rsync -a --delete "$IGATE_DIR/www/wifi/" "$REMOTE:$REMOTE_DIR/wifi/"
ssh "$REMOTE" "sudo chown -R pi:www-data $REMOTE_DIR && sudo find $REMOTE_DIR -type d -exec chmod 775 {} + && sudo find $REMOTE_DIR -type f -exec chmod 664 {} +"

echo ""
echo "Done. Files live at:"
echo "  https://marsaprs.org/igate/install.sh      (bootstrap for new iGates)"
echo "  https://marsaprs.org/igate/files.tar.gz    (nightly update archive)"

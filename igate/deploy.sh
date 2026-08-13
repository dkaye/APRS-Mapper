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

# The self-noise analyzer is shared with the Transcribers, so a change here reaches two
# fleets at once and the grades it produces drive real decisions about hardware.
python3 "$IGATE_DIR/../sdr/test_sdr_selftest.py" >/dev/null || {
    echo "SDR SELFTEST TESTS FAILED — not deploying" >&2
    exit 1
}

# Create remote directory if needed
ssh "$REMOTE" "mkdir -p $REMOTE_DIR"

# Sync source files to aprs-pi staging directory, then build the archive there.
# Building on Linux avoids macOS extended-attribute noise in the tarball.
# Build scratch, deliberately outside the web root — see server/deploy.sh.
STAGING="/home/pi/.marsaprs-staging/igate"
echo "Syncing files to aprs-pi..."
ssh "$REMOTE" "sudo chown -R pi:www-data $REMOTE_DIR 2>/dev/null || true && mkdir -p $STAGING/home $STAGING/direwatch $STAGING/www $STAGING/systemd $STAGING/etc/logrotate.d"
rsync -a --delete "$IGATE_DIR/home/"      "$REMOTE:$STAGING/home/"
# The self-noise test is shared with the Transcribers, so it lives in sdr/ rather than
# in either device's tree. Copied in at deploy time: one source file, two archives, and
# no chance of the two fleets drifting onto different versions of the same measurement.
rsync -a --exclude='test_*' --exclude='__pycache__' "$IGATE_DIR/../sdr/" "$REMOTE:$STAGING/home/"
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
scp "$IGATE_DIR/install.sh"        "$REMOTE:$REMOTE_DIR/install.sh"
scp "$IGATE_DIR/auto-update.sh"    "$REMOTE:$REMOTE_DIR/auto-update.sh"
scp "$IGATE_DIR/migrate-to-v5.sh"  "$REMOTE:$REMOTE_DIR/migrate-to-v5.sh"

# Sync server-side web content (wifi token endpoint served by aprs-pi)
echo "Syncing www/ to aprs-pi..."
ssh "$REMOTE" "mkdir -p $REMOTE_DIR/wifi"
rsync -a --delete "$IGATE_DIR/www/wifi/" "$REMOTE:$REMOTE_DIR/wifi/"

# Self-noise dashboard + upload endpoint. The data/ dir holds gate uploads and
# must survive deploys (hence no --delete of it) and be web-server writable.
echo "Syncing self-noise dashboard..."
ssh "$REMOTE" "mkdir -p $REMOTE_DIR/selftest/data"
rsync -a --exclude=data/ "$IGATE_DIR/www/selftest/" "$REMOTE:$REMOTE_DIR/selftest/"

# No .htaccess is copied here. The server's web root sets no AllowOverride, so Apache
# never read the one this used to place, and the no-cache headers it appeared to set
# were in fact coming from a LocationMatch in the vhost all along. Copying it back would
# only restore the appearance of a rule that does nothing. www/.htaccess stays in the
# tree because it also ships to each iGate's own Apache, which does read it.
ssh "$REMOTE" "sudo chown -R pi:www-data $REMOTE_DIR && sudo find $REMOTE_DIR -type d -exec chmod 775 {} + && sudo find $REMOTE_DIR -type f -exec chmod 664 {} + && sudo chmod 775 $REMOTE_DIR/selftest/data"

echo ""
echo "Done. Files live at:"
echo "  https://marsaprs.org/igate/install.sh      (bootstrap for new iGates)"
echo "  https://marsaprs.org/igate/migrate-to-v5.sh (v4→v5 migration)"
echo "  https://marsaprs.org/igate/files.tar.gz    (nightly update archive)"

#!/usr/bin/env bash
# Deploy Transcriber files to aprs-pi (marsaprs.org).
#
# Run this on your Mac after editing any files in this directory.
# Builds files.tar.gz and uploads it along with install.sh and auto-update.sh.
#
# Usage: ./deploy.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
REMOTE="aprs-pi"
REMOTE_DIR="/var/www/html/transcriber"
STAGING="/home/pi/.marsaprs-staging/transcriber"

echo "=== Transcriber deploy to marsaprs.org ==="

# Refuse to ship code the tests do not pass. The guard against whisper's invented
# speech is the whole reason those tests exist, and shipping past it would fill an
# event's log with lines nobody said.
echo "Running tests..."
python3 "$SRC_DIR/tests/test_transcriber.py" >/dev/null || {
    echo "TESTS FAILED — not deploying" >&2
    exit 1
}
# The updater replaces itself and re-execs, unattended, at 4:11am. Both ways that can go
# wrong are silent from here: hand over to the wrong copy and the change never lands,
# hand over in a loop and the device spends the night re-executing itself.
"$SRC_DIR/tests/test_auto_update.sh" >/dev/null || {
    echo "AUTO-UPDATE TESTS FAILED — not deploying" >&2
    exit 1
}
# Shared with the iGates, so a change here reaches two fleets.
echo "  tests pass"

ssh "$REMOTE" "mkdir -p $REMOTE_DIR"

# Build the archive on the Pi, not the Mac: building on Linux keeps macOS
# extended-attribute noise out of the tarball. Scratch lives outside the web root,
# as in server/deploy.sh and igate/deploy.sh.
echo "Syncing files to aprs-pi..."
ssh "$REMOTE" "sudo chown -R pi:www-data $REMOTE_DIR 2>/dev/null || true
               rm -rf $STAGING && mkdir -p $STAGING/{bin,systemd,etc/transcriber,home}"

rsync -az --ignore-times --exclude='__pycache__' "$SRC_DIR/bin/" "$REMOTE:$STAGING/bin/"
rsync -az --ignore-times "$SRC_DIR/systemd/" "$REMOTE:$STAGING/systemd/"
rsync -az --ignore-times "$SRC_DIR/etc/transcriber/" "$REMOTE:$STAGING/etc/transcriber/"
rsync -az --ignore-times "$SRC_DIR/home/"    "$REMOTE:$STAGING/home/"
# auto-update.sh goes into the archive as well as being uploaded on its own. install.sh
# curls the standalone copy onto a bare Pi; the archived copy is how an already-deployed
# device gets a newer updater, which it otherwise never would — it was fetched once at
# install and then kept running forever, so no change made here could ever reach it.
# One source file, delivered two ways, so the two cannot drift.
rsync -az --ignore-times "$SRC_DIR/auto-update.sh" "$REMOTE:$STAGING/home/"
# Shared with the iGates — see igate/deploy.sh for why it lives outside both trees.
# Fleet-wide tools shared by every device type — see common/power-check.sh. Kept out of
# each device's own home/ so there is one copy to fix rather than four to drift.
rsync -a --exclude='test_*' --exclude='__pycache__' "$SRC_DIR/../common/" "$REMOTE:$STAGING/home/"

echo "Building files.tar.gz on aprs-pi..."
ssh "$REMOTE" "chmod +x $STAGING/bin/*.py $STAGING/home/*.sh && tar -czf $REMOTE_DIR/files.tar.gz -C $STAGING ."

echo "Uploading installer and updater..."
rsync -az --ignore-times "$SRC_DIR/install.sh" "$SRC_DIR/auto-update.sh" "$REMOTE:$REMOTE_DIR/"
ssh "$REMOTE" "sudo chown -R www-data:www-data $REMOTE_DIR && sudo chmod 644 $REMOTE_DIR/*"

echo
echo "Deployed:"
echo "  https://marsaprs.org/transcriber/files.tar.gz    (nightly update archive)"
echo "  https://marsaprs.org/transcriber/install.sh"
echo "  https://marsaprs.org/transcriber/auto-update.sh"

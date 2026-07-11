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
ANALYZER_DIR="$(cd "$SERVER_DIR/../analyzer" && pwd)"
REMOTE="aprs-pi"
REMOTE_DIR="/var/www/html/server"

echo "=== Server deploy to marsaprs.org ==="

echo "Building HTML docs..."
python3 "$MAP_DIR/make-docs-html.py"

# Create remote directory if needed
ssh "$REMOTE" "mkdir -p $REMOTE_DIR"

# Stop the daemon before changing ownership so it doesn't hit a permission
# error mid-deploy. Restarted at the end after ownership is restored.
ssh "$REMOTE" "sudo systemctl stop aprs-daemon 2>/dev/null || true"

# Pre-chown the entire web tree to pi so all subsequent rsyncs (to staging and
# to the live root) can write files. The post-chown at the end of this script
# restores www-data ownership for Apache/PHP/daemon writes.
ssh "$REMOTE" "sudo chown -R pi:www-data /var/www/html"

# Sync source files to aprs-pi staging, then build the archive there.
# Building on Linux avoids macOS extended-attribute noise in the tarball.
STAGING="$REMOTE_DIR/.staging"
echo "Syncing files to aprs-pi..."
ssh "$REMOTE" "mkdir -p $STAGING/home $STAGING/www $STAGING/bin $STAGING/systemd $STAGING/apache $STAGING/cloudflared $STAGING/etc/logrotate.d"
rsync -a --delete "$SERVER_DIR/home/"        "$REMOTE:$STAGING/home/"
rsync -a --delete "$SERVER_DIR/bin/"         "$REMOTE:$STAGING/bin/"
rsync -a --delete "$SERVER_DIR/systemd/"     "$REMOTE:$STAGING/systemd/"
rsync -a --delete "$SERVER_DIR/apache/"      "$REMOTE:$STAGING/apache/"
rsync -a --delete "$SERVER_DIR/cloudflared/" "$REMOTE:$STAGING/cloudflared/"
rsync -a --delete "$SERVER_DIR/etc/"         "$REMOTE:$STAGING/etc/"
ssh "$REMOTE" "mkdir -p $STAGING/home/analyzer/src"
rsync -a --delete \
    --exclude='.DS_Store' \
    --exclude='__pycache__/' \
    --exclude='*.pyc' \
    --exclude='aprs.db' \
    --exclude='latest_packets.json' \
    --exclude='heartbeat.txt' \
    "$ANALYZER_DIR/src/"          "$REMOTE:$STAGING/home/analyzer/src/"
rsync -a "$ANALYZER_DIR/requirements.txt" "$REMOTE:$STAGING/home/analyzer/requirements.txt"

# Bundle the map web app as the base www content, then overlay server-specific
# subdirs (netbird/, admin/, wifi/) on top. No git clone needed on fresh install.
echo "Bundling map web app into www staging..."
rsync -a --delete \
    --exclude='.DS_Store' \
    --exclude='.git/' \
    --exclude='*.MD' \
    --exclude='*.md' \
    --exclude='*.py' \
    --exclude='trackers.json' \
    --exclude='mobile_trackers.json' \
    --exclude='aprs-daemon.service' \
    --exclude='aprs-daemon.sh' \
    --exclude='backup-from-pi.sh' \
    --exclude='sync-to-pi.sh' \
    --exclude='temp/' \
    --exclude='pi-tools/' \
    --exclude='netbird/' \
    --exclude='admin/password.txt' \
    --exclude='wifi/' \
    "$MAP_DIR/" "$REMOTE:$STAGING/www/"
rsync -a "$SERVER_DIR/www/" "$REMOTE:$STAGING/www/"

echo "Installing logrotate config..."
ssh "$REMOTE" "sudo cp $STAGING/etc/logrotate.d/aprs /etc/logrotate.d/aprs && sudo chmod 644 /etc/logrotate.d/aprs"

echo "Building files.tar.gz on aprs-pi..."
ssh "$REMOTE" "tar -czf $REMOTE_DIR/files.tar.gz -C $STAGING ."

# Upload scripts served directly
echo "Uploading scripts..."
scp "$SERVER_DIR/install.sh" "$REMOTE:$REMOTE_DIR/install.sh"

# Push staged web content to the live web root.
# Protects live runtime files that must not be overwritten.
# Pre-chown to pi so rsync (running as pi) can create temp files anywhere;
# post-chown restores www-data ownership for Apache/PHP/daemon writes.
echo "Updating live web root..."
ssh "$REMOTE" "rsync -a \
        --exclude='trackers.json' \
        --exclude='mobile_trackers.json' \
        --exclude='config.yaml' \
        --exclude='events/' \
        --exclude='netbird/addresses.yaml' \
        --exclude='netbird/toggle_state.json' \
        $STAGING/www/ /var/www/html/ && \
    sudo chown -R www-data:www-data /var/www/html && \
    sudo chown pi:www-data /var/www/html && \
    sudo chmod 775 /var/www/html && \
    sudo chown pi:www-data /var/www/html/netbird/addresses.yaml 2>/dev/null || true && \
    sudo chmod 664 /var/www/html/netbird/addresses.yaml 2>/dev/null || true && \
    sudo systemctl start aprs-daemon"

echo "Ensuring user database..."
ssh "$REMOTE" "sudo mkdir -p /var/lib/marsaprs && \
    sudo chown www-data:www-data /var/lib/marsaprs && \
    sudo chmod 770 /var/lib/marsaprs && \
    sudo -u www-data php /var/www/html/auth/init_db.php && \
    sudo chown www-data:www-data /var/lib/marsaprs/users.db 2>/dev/null || true && \
    sudo chmod 660 /var/lib/marsaprs/users.db 2>/dev/null || true"

echo "Updating Apache config..."
ssh "$REMOTE" "sudo rsync -a $STAGING/apache/ /etc/apache2/sites-available/ && \
    sudo a2enmod proxy proxy_http 2>/dev/null || true && \
    sudo systemctl reload apache2"

echo "Deploying netbird poller..."
ssh "$REMOTE" "sudo cp $STAGING/bin/netbird-poller.py /usr/local/bin/netbird-poller.py && \
    sudo chmod +x /usr/local/bin/netbird-poller.py && \
    sudo systemctl disable --now stats-listener 2>/dev/null || true && \
    sudo systemctl restart netbird-poller"

echo "Deploying analyzer..."
ssh "$REMOTE" "rsync -a \
        --exclude='aprs.db' \
        --exclude='latest_packets.json' \
        --exclude='heartbeat.txt' \
        $STAGING/home/analyzer/ /home/pi/analyzer/ && \
    sudo cp $STAGING/systemd/analyzer.service /etc/systemd/system/analyzer.service && \
    sudo cp $STAGING/systemd/analyzer-daemon.service /etc/systemd/system/analyzer-daemon.service && \
    sudo chgrp www-data /home/pi/analyzer/src && \
    sudo chmod g+w /home/pi/analyzer/src && \
    sudo touch /home/pi/analyzer/src/aprs.db && \
    sudo chown www-data:www-data /home/pi/analyzer/src/aprs.db && \
    python3 -m venv /home/pi/analyzer/venv && \
    /home/pi/analyzer/venv/bin/pip install -q -r /home/pi/analyzer/requirements.txt && \
    sudo mkdir -p /var/log/analyzer && \
    sudo chown www-data:www-data /var/log/analyzer && \
    sudo systemctl daemon-reload && \
    sudo systemctl enable analyzer analyzer-daemon && \
    sudo systemctl restart analyzer analyzer-daemon && \
    echo 'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start analyzer-daemon, /usr/bin/systemctl stop analyzer-daemon, /usr/bin/systemctl is-active analyzer-daemon' | sudo tee /etc/sudoers.d/021_www-data-analyzer > /dev/null && \
    sudo chmod 440 /etc/sudoers.d/021_www-data-analyzer"

echo ""
echo "Done. Files live at:"
echo "  https://marsaprs.org/readme.html"
echo "  https://marsaprs.org/userguide.html"
echo "  https://marsaprs.org/server/install.sh    (bootstrap for new server)"
echo "  https://marsaprs.org/server/files.tar.gz  (server archive)"

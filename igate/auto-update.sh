#!/usr/bin/env bash
# iGate nightly update — K6DRK iGate v5.0
#
# Downloads files.tar.gz from marsaprs.org and applies it.
# Run daily at 4:01am via cron. Safe to run manually at any time.
# Does NOT touch direwolf.conf or /var/www/html/config.php (site-specific).
#
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

BASE="https://marsaprs.org/igate"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" | tee -a /var/log/direwolf/watchdog.log; }

log "=== iGate auto-update starting ==="

# Download the update archive
log "Downloading files.tar.gz..."
wget -qO "$TMP/files.tar.gz" --header="Pragma: no-cache" --header="Cache-Control: no-cache" "$BASE/files.tar.gz" || { log "Download failed"; exit 1; }
tar -xzf "$TMP/files.tar.gz" --warning=no-unknown-keyword -C "$TMP"

# Home directory scripts and utilities
log "Updating /home/pi/ scripts..."
rsync -a "$TMP/home/" /home/pi/
chmod +x /home/pi/*.sh /home/pi/*.php

# Direwatch display scripts
log "Updating direwatch scripts..."
rsync -a "$TMP/direwatch/" /home/pi/direwatch/

# Web dashboard (excludes config.php — site-specific)
log "Updating web dashboard..."
sudo rsync -a --exclude='config.php' "$TMP/www/" /var/www/html/

# Systemd service files
log "Updating systemd services..."
sudo rsync -a "$TMP/systemd/" /etc/systemd/system/
sudo systemctl daemon-reload

# Log rotation config
if [ -f "$TMP/etc/logrotate.d/aprs" ]; then
    sudo cp "$TMP/etc/logrotate.d/aprs" /etc/logrotate.d/aprs
fi

# Download iGate list from marsaprs.org
log "Downloading iGate list..."
sudo wget -qO /var/www/html/igate-stations.json \
    --header="Pragma: no-cache" --header="Cache-Control: no-cache" \
    "https://marsaprs.org/netbird/igate-list.php" \
    && log "iGate list updated" || log "iGate list download failed (non-fatal)"

# Download latest WiFi list from marsaprs.org
log "Downloading WiFi list..."
if [ -f /home/pi/.wifi-token ]; then
    wget -qO /home/pi/wifi.yaml \
        --header="Pragma: no-cache" --header="Cache-Control: no-cache" \
        "$BASE/wifi/get.php?token=$(cat /home/pi/.wifi-token)" \
        && log "WiFi list updated" || log "WiFi list download failed (non-fatal)"
else
    log "No .wifi-token — skipping WiFi list download"
fi

# Update WiFi connections
log "Updating WiFi connections..."
/home/pi/update-wifi.php

log "=== iGate auto-update complete ==="
date > /home/pi/LastUpdate

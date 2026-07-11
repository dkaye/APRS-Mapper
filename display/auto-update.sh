#!/usr/bin/env bash
# Display Pi nightly update
#
# Downloads files.tar.gz from marsaprs.org and applies it.
# Run daily at 4:01am via cron. Safe to run manually at any time.
# Does NOT touch site-specific config files.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

BASE="https://marsaprs.org/display"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" | tee -a /home/pi/update.log; }

log "=== Display auto-update starting ==="

# Download the update archive
log "Downloading files.tar.gz..."
wget -qO "$TMP/files.tar.gz" \
    --header="Pragma: no-cache" --header="Cache-Control: no-cache" \
    "$BASE/files.tar.gz?v=$(date +%s)" || { log "Download failed"; exit 1; }
tar -xzf "$TMP/files.tar.gz" --warning=no-unknown-keyword -C "$TMP"

# Home directory scripts and utilities
log "Updating /home/pi/ scripts..."
rsync -a --ignore-times "$TMP/home/" /home/pi/
chmod +x /home/pi/*.sh /home/pi/*.php 2>/dev/null || true

# Desktop shortcut — no execute bit
mkdir -p /home/pi/Desktop
cp /home/pi/start-aprs.desktop /home/pi/Desktop/start-aprs.desktop
chmod 644 /home/pi/Desktop/start-aprs.desktop

# libfm quick_exec suppresses the "Executable Script" dialog on Trixie
mkdir -p /home/pi/.config/libfm
printf '[config]\nquick_exec=1\n' > /home/pi/.config/libfm/libfm.conf

# Systemd service files
log "Updating systemd services..."
sudo rsync -a "$TMP/systemd/" /etc/systemd/system/
sudo systemctl daemon-reload

# Journald volatile storage (reduces SD card writes; /tmp is already tmpfs on Trixie)
sudo mkdir -p /etc/systemd/journald.conf.d
printf '[Journal]\nStorage=volatile\n' | sudo tee /etc/systemd/journald.conf.d/volatile.conf > /dev/null
sudo systemctl restart systemd-journald 2>/dev/null || true

# UFW: ensure DNS response packets (UDP src port 53) are not blocked
sudo ufw allow in proto udp from any port 53 to any 2>/dev/null || true

# Daemon scripts to /usr/local/bin
log "Updating daemon scripts..."
for script in aprs-monitor.sh wifi-watchdog.sh wifi-restored.sh; do
    if [ -f "/home/pi/$script" ]; then
        sudo cp "/home/pi/$script" "/usr/local/bin/$script"
        sudo chmod +x "/usr/local/bin/$script"
    fi
done
sudo systemctl restart aprs-monitor 2>/dev/null || true

# Download latest WiFi list from marsaprs.org
log "Downloading WiFi list..."
if [ -f /home/pi/.wifi-token ]; then
    if wget -qO /tmp/wifi.yaml.new \
            --header="Pragma: no-cache" --header="Cache-Control: no-cache" \
            "$BASE/wifi/get.php?token=$(cat /home/pi/.wifi-token)" \
        && grep -q "^- name:" /tmp/wifi.yaml.new; then
        mv /tmp/wifi.yaml.new /home/pi/wifi.yaml
        log "WiFi list updated"
    else
        rm -f /tmp/wifi.yaml.new
        log "WiFi list download failed (non-fatal)"
    fi
else
    log "No .wifi-token — skipping WiFi list download"
fi

# Update WiFi connections
log "Updating WiFi connections..."
/home/pi/update-wifi.php

log "=== Display auto-update complete ==="
date > /home/pi/LastUpdate

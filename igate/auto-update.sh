#!/usr/bin/env bash
# iGate nightly update — K6DRK iGate v5.1
#
# Downloads files.tar.gz from marsaprs.org and applies it.
# Run daily at 4:01am via cron. Safe to run manually at any time.
# Does NOT touch direwolf.conf or /var/www/html/config.php (site-specific).
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

BASE="https://marsaprs.org/igate"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" | tee -a /var/log/direwolf/watchdog.log; }

# Save today's RAM logs to SD card before the nightly reboot wipes them
save_logs() {
    local SAVED="/var/log-saved/$(date +%Y-%m-%d)"
    sudo mkdir -p "$SAVED"
    [ -d /var/log/direwolf ] && sudo cp -rp /var/log/direwolf/. "$SAVED/direwolf/" 2>/dev/null || true
    # Prune saved log directories older than 14 days
    find /var/log-saved -maxdepth 1 -mindepth 1 -type d -mtime +14 \
        -exec sudo rm -rf {} \; 2>/dev/null || true
}

log "=== iGate auto-update starting ==="
save_logs

# Download the update archive
log "Downloading files.tar.gz..."
wget -qO "$TMP/files.tar.gz" --header="Pragma: no-cache" --header="Cache-Control: no-cache" "$BASE/files.tar.gz" || { log "Download failed"; exit 1; }
tar -xzf "$TMP/files.tar.gz" --warning=no-unknown-keyword -C "$TMP"

# Home directory scripts and utilities
log "Updating /home/pi/ scripts..."
rsync -a --ignore-times "$TMP/home/" /home/pi/
chmod +x /home/pi/*.sh /home/pi/*.php

# Direwatch display scripts
log "Updating direwatch scripts..."
rsync -a --ignore-times "$TMP/direwatch/" /home/pi/direwatch/

# Web dashboard (excludes config.php — site-specific)
log "Updating web dashboard..."
sudo rsync -a --exclude='config.php' "$TMP/www/" /var/www/html/

# Systemd service files
log "Updating systemd services..."
sudo rsync -a "$TMP/systemd/" /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl restart stats-listener 2>/dev/null || true

# ── Headless: drop the unused desktop ────────────────────────────────────────
# The TFT is driven by direwatch over GPIO, not X, so lightdm has nothing to do
# but fail at every boot. Beyond the wasted RAM and boot time, a permanently
# failed unit hides real ones — `systemctl --failed` stops being a useful health
# check when there is always something sitting in it. Idempotent: no-ops once
# converged, so it costs nothing on subsequent nightly runs.
if systemctl is-enabled lightdm >/dev/null 2>&1; then
    log "Disabling unused desktop manager (lightdm)..."
    sudo systemctl disable lightdm >/dev/null 2>&1 || true
    sudo systemctl reset-failed lightdm >/dev/null 2>&1 || true
fi
if [ "$(systemctl get-default 2>/dev/null)" = "graphical.target" ]; then
    log "Setting default boot target to multi-user (headless)..."
    sudo systemctl set-default multi-user.target >/dev/null 2>&1 || true
fi

# ── Version stamp in the two site-specific files ─────────────────────────────
# config.php and direwolf.conf are deliberately never overwritten wholesale —
# they carry each gate's callsign, location and radio setup. But both also embed
# the iGate version, which is product-level rather than site-level, so without
# this a gate reports and beacons its install-time version forever. These
# rewrite only the version substring and leave callsign/location untouched.
# The beacon text takes effect at the 04:10 reboot that follows this run.
IGATE_VERSION="5.1"

CFG=/var/www/html/config.php
if [ -f "$CFG" ] && ! grep -q "dashboardversion = \"$IGATE_VERSION\"" "$CFG"; then
    log "Stamping dashboard version $IGATE_VERSION into config.php..."
    sudo sed -i -E 's/(\$dashboardversion *= *")[0-9]+\.[0-9]+(")/\1'"$IGATE_VERSION"'\2/' "$CFG"
fi

# Direwolf version is detected, not hard-coded: these gates build direwolf from
# source, so `apt-cache policy` reports "(none)" and the dashboard's auto-detect
# gives up. Read it from the binary's own banner instead (falling back to the
# running log), then stamp the real value into config.php so the dashboard shows
# the truth and stays correct across direwolf upgrades with no code change.
DW_BIN=$(command -v direwolf 2>/dev/null || echo /usr/local/bin/direwolf)
DW_VER=$({ timeout 3 "$DW_BIN" -c /dev/null 2>&1 || true; } \
    | grep -oE 'Dire Wolf Release [0-9]+\.[0-9]+(\.[0-9]+)?' | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -1)
[ -z "$DW_VER" ] && DW_VER=$(sudo grep -ohE 'Dire Wolf Release [0-9]+\.[0-9]+(\.[0-9]+)?' \
    /var/log/direwolf/console.log 2>/dev/null | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -1)
if [ -n "$DW_VER" ] && [ -f "$CFG" ] && ! grep -q "direwolfversion = \"$DW_VER\"" "$CFG"; then
    log "Stamping detected Dire Wolf version $DW_VER into config.php..."
    sudo sed -i -E 's/(\$direwolfversion *= *")[^"]*(")/\1'"$DW_VER"'\2/' "$CFG"
fi

DWC=/home/pi/direwolf.conf
if [ -f "$DWC" ] && grep -qE 'comment="iGate [0-9]+\.[0-9]+ by' "$DWC" \
                 && ! grep -q "comment=\"iGate $IGATE_VERSION by" "$DWC"; then
    log "Stamping beacon version $IGATE_VERSION into direwolf.conf..."
    sed -i -E 's/(comment="iGate )[0-9]+\.[0-9]+( by)/\1'"$IGATE_VERSION"'\2/' "$DWC"
fi

# Log rotation config
if [ -f "$TMP/etc/logrotate.d/aprs" ]; then
    sudo cp "$TMP/etc/logrotate.d/aprs" /etc/logrotate.d/aprs
fi

# tmpfiles.d config (recreates /var/log subdirs in tmpfs at each boot)
if [ -f "$TMP/etc/tmpfiles.d/igate-logs.conf" ]; then
    sudo mkdir -p /etc/tmpfiles.d
    sudo cp "$TMP/etc/tmpfiles.d/igate-logs.conf" /etc/tmpfiles.d/igate-logs.conf
    sudo systemd-tmpfiles --create /etc/tmpfiles.d/igate-logs.conf 2>/dev/null || true
fi

# RAM log setup (idempotent: adds /var/log tmpfs to fstab if not present)
/home/pi/ramlog-setup.sh

# Remove obsolete v4 scripts superseded by v5 equivalents
rm -f /home/pi/direwolf-start.sh \
      /home/pi/direwatch-start.sh \
      /home/pi/CheckNetBird.sh \
      /home/pi/NetbirdUp.sh \
      /home/pi/StatsRequestListener.php \
      /home/pi/StatsRequestListener-start.sh \
      /home/pi/add_wifi.php \
      /home/pi/getIgateList.sh \
      /home/pi/check-swapping.sh \
      /home/pi/auto-update2.sh \
      /home/pi/install.sh \
      /home/pi/StartAllApps.sh

# Download iGate list from marsaprs.org
log "Downloading iGate list..."
sudo wget -qO /var/www/html/igate-stations.json \
    --header="Pragma: no-cache" --header="Cache-Control: no-cache" \
    "https://marsaprs.org/netbird/igate-list.php" \
    && log "iGate list updated" || log "iGate list download failed (non-fatal)"

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

log "=== iGate auto-update complete ==="
date > /home/pi/LastUpdate

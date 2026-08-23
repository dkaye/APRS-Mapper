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

# Band-pin cron entry. install.sh writes it for new devices, but existing displays
# only ever run auto-update, so add it here if missing (idempotent).
# Always target pi's crontab explicitly: this script is normally run as pi from
# cron, but running it by hand as `sudo ./auto-update.sh` would otherwise edit
# root's crontab, where the entry would never fire the way the others do.
if [ -f /home/pi/wifi-band-pin.sh ]; then
    chmod +x /home/pi/wifi-band-pin.sh
    if ! sudo -u pi crontab -l 2>/dev/null | grep -q wifi-band-pin.sh; then
        log "Adding wifi-band-pin.sh to crontab..."
        ( sudo -u pi crontab -l 2>/dev/null; echo '*/5 * * * * /home/pi/wifi-band-pin.sh' ) \
            | sudo -u pi crontab -
    fi
fi

# Kiosk re-arm cron entry, same idempotent pattern as the band pin above: install.sh
# writes it for new devices, and this is how displays already in the field get it.
# A reboot must always bring the kiosk back regardless of what the Exit button was told
# beforehand — /tmp is tmpfs so the flag is normally gone anyway, and this makes the
# guarantee explicit rather than a side effect of how /tmp happens to be mounted.
if ! sudo -u pi crontab -l 2>/dev/null | grep -q 'aprs-kiosk-off'; then
    log "Adding kiosk re-arm to crontab..."
    ( sudo -u pi crontab -l 2>/dev/null; echo '@reboot rm -f /tmp/aprs-kiosk-off' ) \
        | sudo -u pi crontab -
fi

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

# ARP flux guard, idempotently — install.sh writes this for new displays, and this is
# how the ones already in the field get it. See install.sh for the full reasoning; the
# short version is that a wired display whose WiFi is on the same subnet answers ARP for
# both addresses on both interfaces, and inbound connections then fail while everything
# the Pi initiates keeps working. Written only when it differs, so the nightly run does
# not reload sysctl for no reason.
ARP_CONF=/etc/sysctl.d/99-arp-flux.conf
ARP_WANT='net.ipv4.conf.all.arp_ignore = 1
net.ipv4.conf.all.arp_announce = 2'
if [ "$(cat "$ARP_CONF" 2>/dev/null)" != "$ARP_WANT" ]; then
    printf '%s\n' "$ARP_WANT" | sudo tee "$ARP_CONF" > /dev/null
    sudo sysctl --system > /dev/null 2>&1 || true
    log "ARP flux guard installed (arp_ignore=1 arp_announce=2)"
fi

# Power check, nightly, the same as the iGates run. Costs nothing — it reads two
# counters and a device-tree node, frees nothing and stops nothing — and it catches the
# fault that otherwise presents as whatever else was happening at the time.
#
# On a display that fault has a name: BigTV browned out in August 2026 and again in
# August 2026 after being re-cabled, and both times the first useful number was this
# one. The bits it reads are latched SINCE BOOT and cleared by every reboot, so a
# display that reboots nightly at 4:10 reports its previous day's verdict here, minutes
# before that reboot wipes it — which is the only moment it can be caught without
# somebody being logged in at the time.
if [ -x /home/pi/power-check.sh ]; then
    /home/pi/power-check.sh 2>&1 | grep -aiE '^power:' \
        | while read -r l; do log "$l"; done || log "power check skipped (non-fatal)"
fi

log "=== Display auto-update complete ==="
date > /home/pi/LastUpdate

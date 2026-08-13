#!/usr/bin/env bash
# iGate nightly update — K6DRK iGate v5.2
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

    # Prune direwolf APRS-traffic logs (LOGDIR ~/aprslogs) older than 14 days.
    # Direwolf creates a new dated file per day but never deletes the old ones,
    # and no logrotate stanza covers this dir — so they accrue on the SD forever.
    find /home/pi/aprslogs -maxdepth 1 -name '*.log' -mtime +14 -delete 2>/dev/null || true
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
sudo ln -sf /home/pi/sdr-usb-test.sh /usr/local/bin/sdr-usb-test   # run 'sdr-usb-test' from anywhere

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

# ── Fleet relay enrollment (central control) ─────────────────────────────────
# A single server-side file decides which gates opt into the aggregation relay, so
# the fleet can be enrolled or withdrawn centrally without SSHing to each gate.
# isproxy-enroll.txt holds one token per line: "ALL" (whole fleet) and/or specific
# MYCALLs; "#" lines are comments. This just creates/removes the sentinel; the
# v5-guarded activation block below does the real work (and no-ops on v4 gates).
# Fail-safe: if the file can't be fetched (or is empty), the current sentinel is
# left untouched, so a network blip never mass-disables the fleet.
ENROLL=$(wget -qO- --timeout=15 --header="Cache-Control: no-cache" "$BASE/isproxy-enroll.txt" 2>/dev/null || true)
if [ -n "$ENROLL" ]; then
    ENROLL_MYCALL=$(awk '$1=="MYCALL"{print $2; exit}' /home/pi/direwolf.conf 2>/dev/null)
    if [ -n "$ENROLL_MYCALL" ] && echo "$ENROLL" | grep -vE '^[[:space:]]*#' \
         | grep -qxE "[[:space:]]*(ALL|${ENROLL_MYCALL})[[:space:]]*"; then
        [ -f /home/pi/.isproxy-enabled ] || { touch /home/pi/.isproxy-enabled; log "relay: enrolled ($ENROLL_MYCALL) via fleet flag"; }
    else
        [ -f /home/pi/.isproxy-enabled ] && { rm -f /home/pi/.isproxy-enabled; log "relay: withdrawn ($ENROLL_MYCALL) via fleet flag"; }
    fi
fi

# ── Unique per-gate IGLOGIN (promote the base call to the full MYCALL) ───────
# APRS-IS enforces one connection per callsign-SSID. Historically these gates
# logged in with the BARE base call (e.g. every MARS gate as "MARS"), which makes
# the aggregation relay's per-gate upstreams collide and kick each other. The
# APRS-IS passcode is derived from the base call (SSID-independent), so promoting
# IGLOGIN to the full MYCALL keeps the SAME passcode valid while giving each gate a
# unique login. Idempotent; only acts when the current IGLOGIN base matches
# MYCALL's base, so it can never introduce a passcode mismatch. Config edit only —
# takes effect at the 04:10 reboot (or the isproxy block's direwolf restart below).
IGLOG_DWC=/home/pi/direwolf.conf
if [ -f "$IGLOG_DWC" ]; then
    IGLOG_MYCALL=$(awk '$1=="MYCALL"{print $2; exit}' "$IGLOG_DWC")
    IGLOG_IGCALL=$(awk '$1=="IGLOGIN"{print $2; exit}' "$IGLOG_DWC")
    if [ -n "$IGLOG_MYCALL" ] && [ -n "$IGLOG_IGCALL" ] \
       && [ "$IGLOG_IGCALL" != "$IGLOG_MYCALL" ] \
       && [ "${IGLOG_MYCALL%%-*}" = "${IGLOG_IGCALL%%-*}" ]; then
        sed -i -E "s|^([[:space:]]*IGLOGIN[[:space:]]+)[A-Za-z0-9]+(-[0-9]+)?([[:space:]]+[0-9]+)|\1${IGLOG_MYCALL}\3|" "$IGLOG_DWC"
        log "IGLOGIN callsign -> $IGLOG_MYCALL (was $IGLOG_IGCALL; unique per-gate APRS-IS login)"
    fi
fi

# ── Relay proxy activation (opt-in, sentinel-guarded) ────────────────────────
# Route this gate's gating through our aggregation relay so the server sees its
# RF->IS traffic BEFORE APRS-IS dedup. The isproxy.py/.json and its service unit
# ship on every gate (via the rsyncs above) but stay INERT: this block does
# nothing unless /home/pi/.isproxy-enabled exists, and it cleanly REVERTS when
# that sentinel is removed. isproxy always fails over to public APRS-IS if the
# relay is unreachable, so gating never depends on our server. v5-only: it drives
# direwolf via systemd (a v4 gate runs direwolf in screen, so we guard on the unit).
IGCFG=/home/pi/direwolf.conf
ISP_SENTINEL=/home/pi/.isproxy-enabled
ISP_ORIGSAVE=/home/pi/.isproxy-orig-igserver
if systemctl cat direwolf.service >/dev/null 2>&1 && [ -f "$IGCFG" ]; then
    if [ -f "$ISP_SENTINEL" ]; then
        if ! grep -qE '^[[:space:]]*IGSERVER[[:space:]]+127\.0\.0\.1\b' "$IGCFG"; then
            # Save the pre-proxy IGSERVER line once (for clean rollback) + a dated backup
            [ -f "$ISP_ORIGSAVE" ] || grep -E '^[[:space:]]*IGSERVER\b' "$IGCFG" | head -1 > "$ISP_ORIGSAVE" || true
            cp -a "$IGCFG" "$IGCFG.bak-preproxy-$(date +%Y%m%d%H%M%S)"
            sed -i -E 's/^[[:space:]]*IGSERVER[[:space:]]+.*/IGSERVER 127.0.0.1/' "$IGCFG"
            log "isproxy: IGSERVER -> 127.0.0.1 (relay proxy activated)"
        fi
        sudo systemctl enable --now igate-isproxy 2>/dev/null \
            && sudo systemctl restart igate-isproxy 2>/dev/null || log "isproxy: enable/start failed (non-fatal)"
        sudo systemctl restart direwolf 2>/dev/null || true
    elif systemctl is-enabled igate-isproxy >/dev/null 2>&1 \
         || grep -qE '^[[:space:]]*IGSERVER[[:space:]]+127\.0\.0\.1\b' "$IGCFG"; then
        # Sentinel removed → roll back to the public APRS-IS path we saved
        ISP_ORIG="IGSERVER noam.aprs2.net"
        [ -s "$ISP_ORIGSAVE" ] && ISP_ORIG=$(sed -E 's/^[[:space:]]+//' "$ISP_ORIGSAVE")
        sed -i -E "s#^[[:space:]]*IGSERVER[[:space:]]+.*#${ISP_ORIG}#" "$IGCFG"
        sudo systemctl disable --now igate-isproxy 2>/dev/null || true
        sudo systemctl restart direwolf 2>/dev/null || true
        rm -f "$ISP_ORIGSAVE"
        log "isproxy: deactivated, IGSERVER restored to '${ISP_ORIG#IGSERVER }'"
    fi
fi

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
IGATE_VERSION="5.2"

CFG=/var/www/html/config.php
if [ -f "$CFG" ] && ! grep -q "dashboardversion = \"$IGATE_VERSION\"" "$CFG"; then
    log "Stamping dashboard version $IGATE_VERSION into config.php..."
    # Match ANY current value ([^"]*), not just a dotted N.N version — old v4
    # gates carry a date-style version (e.g. "20250430") with no dot, which the
    # previous [0-9]+\.[0-9]+ pattern silently failed to rewrite, so they were
    # stuck reporting the date forever. Mirrors the direwolfversion stamp below.
    sudo sed -i -E 's/(\$dashboardversion *= *")[^"]*(")/\1'"$IGATE_VERSION"'\2/' "$CFG"
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

# ── SDR self-noise test ───────────────────────────────────────────────────────
# Measures the internal-birdie level near the APRS channel (see the fleet
# dashboard at marsaprs.org/igate/selftest/). Frees the SDR for ~1 min; the
# 04:10 reboot restarts everything anyway. Time-bounded and non-fatal.
# Renamed from igate-selftest.sh: the same measurement now runs on the Transcribers
# too, from one shared copy, so the name no longer says iGate. Remove the old pair
# rather than leaving them to be found and run years from now.
rm -f /home/pi/igate-selftest.sh /home/pi/igate-selftest.py
if [ -x /home/pi/sdr-selftest.sh ]; then
    log "Running SDR self-noise test..."
    timeout -k 15 200 /home/pi/sdr-selftest.sh 2>&1 | grep -aiE 'selftest:' \
        | while read -r l; do log "$l"; done || log "self-test skipped (non-fatal)"
fi

log "=== iGate auto-update complete ==="
date > /home/pi/LastUpdate

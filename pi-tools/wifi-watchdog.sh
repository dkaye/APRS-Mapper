#!/bin/bash
# Generic WiFi watchdog — monitors NetworkManager connectivity and triggers
# hook scripts on loss and restore.
#
# Hooks (optional, must be executable):
#   /usr/local/bin/wifi-lost.sh     — called when connection is lost
#   /usr/local/bin/wifi-restored.sh — called when connection is restored
#
# Deploy:
#   1. Copy this script to /usr/local/bin/wifi-watchdog.sh
#   2. Copy wifi-watchdog.service to /etc/systemd/system/
#   3. sudo systemctl daemon-reload && sudo systemctl enable --now wifi-watchdog
#   4. Optionally create wifi-lost.sh and/or wifi-restored.sh for device-specific actions

INTERVAL=30   # seconds between checks

was_connected=true

run_hook() {
    local hook="/usr/local/bin/$1"
    if [[ -x "$hook" ]]; then
        logger -t wifi-watchdog "Running $1"
        "$hook"
    fi
}

while true; do
    sleep "$INTERVAL"
    STATE=$(nmcli -t -f STATE g 2>/dev/null)
    if [[ "$STATE" != *connected* ]]; then
        if $was_connected; then
            logger -t wifi-watchdog "Connection lost (state=$STATE)"
            was_connected=false
            run_hook wifi-lost.sh
        fi
        nmcli device wifi rescan ifname wlan0 2>/dev/null
        sleep 5
        nmcli device up wlan0 2>/dev/null
    else
        if ! $was_connected; then
            logger -t wifi-watchdog "Connection restored"
            was_connected=true
            run_hook wifi-restored.sh
        fi
    fi
done

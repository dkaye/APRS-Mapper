#!/usr/bin/env bash
# iGate health watchdog — run from cron every minute.
#
# SDR check:      every minute — restarts direwolf if SDR reappears
# IP check:       every minute — logs if no address
# Internet check: every 5 minutes, only when NetBird is connected
#
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
LOGFILE=/var/log/direwolf/watchdog.log

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOGFILE"; }

display=0
pinctrl get 23 | grep -q hi && display=1

# ── SDR check ─────────────────────────────────────────────────────────────────
if lsusb | grep -qiE '0bda:2838|0bda:2832|RTL28'; then
    if ! systemctl is-active --quiet direwolf.service; then
        log "SDR found, direwolf not running — restarting."
        sudo systemctl restart direwolf.service
    fi
else
    log "No SDR found."
    [ "$display" -eq 1 ] && python3 /home/pi/direwatch/dw-nosdr.py
fi

# ── IP address check ──────────────────────────────────────────────────────────
if [ -z "$(hostname -I | awk '{print $1}')" ]; then
    log "No IP address."
fi

# ── Internet check (every 5 minutes, only when NetBird is connected) ──────────
MIN=$(date +%M)
if [ $(( 10#$MIN % 5 )) -eq 0 ]; then
    if command -v netbird &>/dev/null && netbird status 2>/dev/null | grep -q "NetBird IP:"; then
        if ! ping -c1 -W3 8.8.8.8 &>/dev/null; then
            log "No internet. Rebooting."
            [ "$display" -eq 1 ] && python3 /home/pi/direwatch/dw-nointernet.py
            sudo reboot
        fi
    fi
fi

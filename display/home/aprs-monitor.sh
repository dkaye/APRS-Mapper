#!/bin/bash
# NetControl: monitor APRS server reachability while Chromium is running.
# When unreachable: immediately reload the connecting page so the user has
# a graceful retry UI instead of a stuck browser error page.
# When reachable again after a connecting-page reload: restart in normal mode.
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

INTERVAL=30
was_reachable=true

check_aprs() {
    curl -sk --max-time 5 https://marsaprs.org/ >/dev/null 2>&1
}

start_connecting() {
    sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority nohup chromium \
        --password-store=basic --kiosk --noerrdialogs --disable-infobars \
        --disable-dev-shm-usage --incognito \
        --disable-features=BlockInsecurePrivateNetworkRequests \
        'http://localhost:8080/' >/tmp/chromium.log 2>&1 &
}

start_kiosk() {
    sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority \
        XCURSOR_SIZE=48 /home/pi/start-kiosk.sh >/tmp/chromium.log 2>&1 &
}

while true; do
    sleep "$INTERVAL"

    if ! pgrep -x chromium >/dev/null; then
        was_reachable=true
        continue
    fi

    if check_aprs; then
        if ! $was_reachable; then
            logger -t aprs-monitor "APRS reachable — switching back to kiosk"
            was_reachable=true
            pkill chromium 2>/dev/null
            sleep 2
            start_kiosk
        fi
    else
        if $was_reachable; then
            logger -t aprs-monitor "APRS unreachable — switching to connecting page"
            was_reachable=false
            pkill chromium 2>/dev/null
            sleep 2
            start_connecting
        fi
    fi
done

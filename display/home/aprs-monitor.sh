#!/bin/bash
# NetControl: monitor APRS server reachability while Chromium is running.
# When unreachable: immediately reload the connecting page so the user has
# a graceful retry UI instead of a stuck browser error page.
# When reachable again after a connecting-page reload: restart in normal mode.
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

INTERVAL=30
CHECK_TIMEOUT=10   # a satellite link routinely answers in 3-8s; 5s was too tight
FAIL_STREAK=3      # consecutive failures before declaring the server unreachable
MISSING_STREAK=2   # cycles with no browser before we start the kiosk ourselves
was_reachable=true
fails=0
missing=0

# Tearing the browser down is disruptive and visible, so it must not hinge on one
# sample. Over Starlink the fetch does occasionally take 3-8s while the link is
# perfectly healthy — measured 2026-08-07: of three "failures" in a day, two
# returned HTTP 200 (in 8.0s and 3.4s) and the third coincided with successful
# pings to 1.1.1.1, i.e. nothing was actually down. With INTERVAL=30 this now
# needs ~90s of sustained failure before switching to the connecting page.
check_aprs() {
    curl -sk --max-time "$CHECK_TIMEOUT" https://marsaprs.org/ >/dev/null 2>&1
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

    # No browser at all. Normally the desktop autostart launches it at login, so
    # give that a couple of cycles before stepping in — but do step in, because
    # nothing else will: a kiosk that dies (or gets killed with the monitor, as
    # happened before KillMode=process) otherwise leaves the display black until
    # someone reboots the Pi.
    if ! pgrep -x chromium >/dev/null; then
        was_reachable=true
        fails=0
        missing=$((missing + 1))
        if [ "$missing" -ge "$MISSING_STREAK" ]; then
            logger -t aprs-monitor "Chromium not running after $missing checks — starting kiosk"
            missing=0
            start_kiosk
        fi
        continue
    fi
    missing=0

    if check_aprs; then
        fails=0
        if ! $was_reachable; then
            logger -t aprs-monitor "APRS reachable — switching back to kiosk"
            was_reachable=true
            pkill chromium 2>/dev/null
            sleep 2
            start_kiosk
        fi
    else
        fails=$((fails + 1))
        if $was_reachable; then
            if [ "$fails" -ge "$FAIL_STREAK" ]; then
                logger -t aprs-monitor "APRS unreachable ($fails consecutive checks) — switching to connecting page"
                was_reachable=false
                fails=0
                pkill chromium 2>/dev/null
                sleep 2
                start_connecting
            else
                logger -t aprs-monitor "APRS check failed ($fails/$FAIL_STREAK) — holding"
            fi
        fi
    fi
done

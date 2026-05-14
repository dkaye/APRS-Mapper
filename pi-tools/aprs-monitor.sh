#!/bin/bash
# NetControl: monitor APRS server reachability while Chromium is running.
# When unreachable: immediately reload the connecting page so the user has
# a graceful retry UI instead of a stuck browser error page.
# When reachable again after a connecting-page reload: restart in normal mode.

INTERVAL=30
was_reachable=true

check_aprs() {
    curl -sk --max-time 5 https://mars-aprs.ddns.net/ >/dev/null 2>&1
}

start_chromium() {
    sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority nohup chromium \
        --password-store=basic --noerrdialogs --disable-infobars \
        --disable-dev-shm-usage --incognito \
        --disable-features=BlockInsecurePrivateNetworkRequests \
        'http://localhost:8080/' >/tmp/chromium.log 2>&1 &
}

while true; do
    sleep "$INTERVAL"

    if ! pgrep -x chromium >/dev/null; then
        was_reachable=true
        continue
    fi

    if check_aprs; then
        was_reachable=true
    else
        if $was_reachable; then
            logger -t aprs-monitor "APRS unreachable — switching to connecting page"
            was_reachable=false
            /usr/local/bin/update-aprs-host.sh
            pkill chromium 2>/dev/null
            sleep 2
            start_chromium
        else
            # Still unreachable — keep refreshing hosts in case IP changed
            /usr/local/bin/update-aprs-host.sh
        fi
    fi
done

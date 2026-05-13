#!/bin/bash
# NetControl-specific actions on WiFi restore.
# Called by wifi-watchdog.sh when connectivity is re-established.

/usr/local/bin/update-aprs-host.sh
sleep 2
pkill chromium 2>/dev/null
sleep 2
sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority nohup chromium \
    --password-store=basic --kiosk --noerrdialogs --disable-infobars \
    --disable-dev-shm-usage --incognito \
    'http://localhost:8080/' >/tmp/chromium.log 2>&1 &

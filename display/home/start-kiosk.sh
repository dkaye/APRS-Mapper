#!/bin/bash
# start-kiosk.sh — MARS APRS Display Pi
#
# Launches Chromium in kiosk mode. If ~/autologin.txt exists, bypasses the
# event gate (?autologin). Line 1 of the file, if present, sets the operator
# name for messaging auto-subscribe.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

URL="https://marsaprs.org/"

if [ -f ~/autologin.txt ]; then
    mapfile -t lines < ~/autologin.txt
    operator="${lines[0]:-}"
    URL="https://marsaprs.org/?autologin"
    if [ -n "$operator" ]; then
        enc_op=$(python3 -c "import sys,urllib.parse; print(urllib.parse.quote(sys.argv[1]))" "$operator")
        URL="${URL}&operator=${enc_op}"
    fi
fi

export XCURSOR_SIZE=48
rm -f ~/.config/chromium/Singleton*
exec chromium --password-store=basic --kiosk --noerrdialogs --disable-infobars \
    --disable-dev-shm-usage --incognito \
    --disable-features=BlockInsecurePrivateNetworkRequests \
    --force-renderer-accessibility --enable-gpu-rasterization \
    --use-angle=gles \
    --user-data-dir=/tmp/chromium \
    "$URL"

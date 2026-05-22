#!/bin/bash
# start-kiosk.sh — MARS APRS Display Pi
#
# Launches Chromium in kiosk mode pointing at marsaprs.org.
# Called by LXDE autostart on boot.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

rm -f ~/.config/chromium/Singleton*
exec chromium --password-store=basic --kiosk --noerrdialogs --disable-infobars --disable-dev-shm-usage --incognito --disable-features=BlockInsecurePrivateNetworkRequests https://marsaprs.org/

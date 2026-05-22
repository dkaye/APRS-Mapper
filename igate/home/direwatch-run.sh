#!/usr/bin/env bash
# Start direwatch only if the TFT display is present (GPIO23 high).
# Exits 0 (success) if no display so systemd does not restart it.
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

pinctrl get 23 | grep -q hi || exit 0

if grep -q "CONFIGURE_ME" /home/pi/direwolf.conf 2>/dev/null; then
    exec python3 /home/pi/direwatch/dw-unconfigured.py
fi

exec python3 /home/pi/direwatch/direwatch.py \
    --log /var/log/direwolf/console.log \
    --title_text "$(hostname)"

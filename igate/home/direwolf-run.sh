#!/usr/bin/env bash
# Wrapper for direwolf.service.
# Suppresses direwolf on unconfigured units (exits 0 so systemd does not restart).
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

if grep -q "CONFIGURE_ME" /home/pi/direwolf.conf 2>/dev/null; then
    echo "iGate not configured — direwolf suppressed. Run /home/pi/configure.sh."
    exit 0
fi
exec /bin/bash -c 'rtl_fm -f 144.39M -E dc - | nice -n 5 direwolf -c /home/pi/direwolf.conf -r 24000 -d i - > /var/log/direwolf/console.log 2>&1'

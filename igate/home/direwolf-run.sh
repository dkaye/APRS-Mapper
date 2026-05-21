#!/usr/bin/env bash
# Wrapper for direwolf.service.
# Suppresses direwolf on unconfigured units (exits 0 so systemd does not restart).
if grep -q "CONFIGURE_ME" /home/pi/direwolf.conf 2>/dev/null; then
    echo "iGate not configured — direwolf suppressed. Run /home/pi/configure.sh."
    exit 0
fi
exec /bin/bash -c 'rtl_fm -f 144.39M - | direwolf -c /home/pi/direwolf.conf -r 24000 -d i -d o - > /var/log/direwolf/console.log 2>&1'

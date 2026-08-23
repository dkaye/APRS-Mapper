#!/bin/bash
# wifi-on.sh — MARS APRS Display Pi
#
# Turns the WiFi radio back on and waits for it to associate, so the answer to "did
# that work?" is on screen rather than something to go and check.
#
# `nmcli radio wifi off` persists across reboots, so a display switched off during
# maintenance stays off until something does this. That is the failure this exists to
# prevent: a wired display with no fallback, which nobody notices until the cable is
# pulled months later.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

echo "WiFi was: $(nmcli radio wifi 2>/dev/null)"
sudo nmcli radio wifi on

# Association takes a few seconds, and longer on a Pi that has just been told to
# rescan. Report what actually happened rather than assuming.
for i in $(seq 1 20); do
    SSID=$(nmcli -t -f active,ssid dev wifi 2>/dev/null | sed -n 's/^yes://p' | head -1)
    [ -n "$SSID" ] && break
    sleep 1
done

echo "WiFi is now: $(nmcli radio wifi 2>/dev/null)"
if [ -n "$SSID" ]; then
    echo "Associated:  $SSID"
    echo "Address:     $(ip -4 -br addr show wlan0 2>/dev/null | awk '{print $3}')"
else
    echo "Associated:  (not yet — radio is on but nothing has connected)"
    echo "             Check /home/pi/available-wifi.sh for what is in range."
fi
echo
ip route | grep '^default' | sed 's/^/route:    /'
echo
# The reason both interfaces on one subnet is worth noticing — see the ARP flux guard.
if ip route | grep -q '^default .* dev eth0' && ip route | grep -q '^default .* dev wlan0'; then
    E=$(ip route | awk '/^default .* dev eth0/{print $3; exit}')
    W=$(ip route | awk '/^default .* dev wlan0/{print $3; exit}')
    if [ "$E" = "$W" ]; then
        echo "NOTE: eth0 and wlan0 are on the SAME network (gateway $E)."
        echo "      That is the ARP flux case; the guard in /etc/sysctl.d/99-arp-flux.conf"
        echo "      is what keeps it working. Verify with:"
        echo "        sysctl net.ipv4.conf.all.arp_ignore   # expect 1"
    fi
fi

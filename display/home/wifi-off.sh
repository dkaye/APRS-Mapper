#!/bin/bash
# wifi-off.sh — MARS APRS Display Pi
#
# Turns the WiFi radio off, but only when there is a wired path to fall back on.
#
# The refusal is the whole point. `nmcli radio wifi off` on a display whose only link
# is WiFi does not fail, warn, or ask — it disconnects the machine and leaves no way
# back except physically attending to it. NetControl is exactly that machine. So this
# checks for a working eth0 carrying the default route first, and declines otherwise.
#
# Why turn it off at all, on a display that is wired:
#   - Two interfaces on ONE subnet make the Pi answer ARP for both addresses on both,
#     and inbound connections then fail while everything the Pi initiates still works
#     (see the ARP flux guard in install.sh). Off is the simplest cure.
#   - A Pi 4 shares one 2.4 GHz radio between WiFi and Bluetooth, so a busy link costs
#     Bluetooth range and reliability — which matters when pairing a mouse.
# The cost is the WiFi fallback: with the radio off, pulling the cable takes the
# display off the network entirely. That is the trade this script makes explicit.
#
# Usage: wifi-off.sh [--force]
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

wired_ok() {
    # Carrier AND the default route. Carrier alone is not enough: a cable into a dead
    # switch has carrier and no path, and that is precisely when losing WiFi hurts.
    [ "$(cat /sys/class/net/eth0/carrier 2>/dev/null)" = "1" ] || return 1
    ip route | grep -q '^default .* dev eth0' || return 1
    return 0
}

echo "WiFi:     $(nmcli radio wifi 2>/dev/null)"
echo "eth0:     carrier=$(cat /sys/class/net/eth0/carrier 2>/dev/null || echo none)"
ip route | grep '^default' | sed 's/^/route:    /'
echo

if ! wired_ok; then
    echo "REFUSED: no working wired connection — turning WiFi off would take this"
    echo "         display off the network with no way back but a keyboard and screen."
    if [ "$FORCE" -eq 1 ]; then
        echo "         --force given; proceeding anyway."
    else
        echo "         Use --force if you are standing in front of it."
        exit 1
    fi
fi

sudo nmcli radio wifi off
sleep 2
echo "WiFi is now: $(nmcli radio wifi 2>/dev/null)"
echo "Reachable over: $(ip route | awk '/^default/{print $5; exit}')"
echo
echo "This survives a reboot. Turn it back on with /home/pi/wifi-on.sh"

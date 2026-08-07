#!/usr/bin/env bash
# wifi-band-pin.sh — MARS APRS Display Pi
#
# Keeps a dual-band display Pi off a weak 5 GHz radio when the same access point
# offers a much stronger 2.4 GHz one.
#
# Why: at a Starlink site the AP's band steering parked BigTV on its 5 GHz radio at
# -69 dBm, where the rate collapsed to 6-13 Mbit/s and ~20% of packets were lost —
# enough that NetBird's ICE sessions died after ~70s and the device was unreachable,
# while outbound traffic kept working so it still looked online. The same AP's
# 2.4 GHz radio was ~12 dB stronger. (Diagnosed 2026-08-06.)
#
# The rule is measured, not hardcoded to any SSID: only when we are actually
# associated on 5 GHz AND the same SSID is visible on 2.4 GHz at least MIN_GAIN
# signal points stronger do we pin the profile to the 2.4 GHz band and reconnect.
# A good 5 GHz link is left alone.
#
# Safety: if wlan0 cannot associate at all, any band pin we previously applied is
# cleared, so a device moved to a 5 GHz-only network can never be stranded offline.
#
# Only matters on dual-band hardware (Pi 4 / Pi 5). A Pi Zero 2 W is 2.4 GHz only
# and can never be steered, so this is a no-op there.
#
# Runs every 5 minutes from cron. update-wifi.php spares the active connection but
# rebuilds the others bare, so the setting needs re-asserting after a roam.
#
# Usage: /home/pi/wifi-band-pin.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

MIN_GAIN=12          # 2.4 GHz must beat 5 GHz by this many nmcli signal points
WEAK_MAX=65          # ...and the 5 GHz link must itself be this weak or worse
LOG=/home/pi/wifi-band-pin.log

# Both conditions are required. MIN_GAIN alone is not enough: nmcli's signal scale
# saturates near 100, so a perfectly good 5 GHz link can still read "12 points
# worse" than a 2.4 GHz one and get needlessly demoted from 433 Mbit/s to 72 —
# which is exactly what happened to BigTV on TerraceLan2 at -33 dBm (100 vs 88).
# The failure this script exists to fix looked like 50 (-69 dBm), far below
# WEAK_MAX, so gating on absolute weakness keeps the intervention narrow.

log() { echo "$(date '+%F %T') $*" >> "$LOG"; }
trim() { tail -n 200 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG" 2>/dev/null; }

con=$(nmcli -t -f NAME,DEVICE connection show --active 2>/dev/null | awk -F: '$2=="wlan0"{print $1; exit}')

# ── Not associated: undo any pin so a 5 GHz-only network is still reachable ────
if [ -z "$con" ]; then
    nmcli -t -f NAME connection show 2>/dev/null | while read -r c; do
        [ "$(nmcli -g 802-11-wireless.band connection show "$c" 2>/dev/null)" = "bg" ] || continue
        sudo nmcli connection modify "$c" 802-11-wireless.band ""
        log "wlan0 down — cleared 2.4 GHz pin on '$c' so it can try 5 GHz"
    done
    trim; exit 0
fi

# Power save off costs nothing and is what actually delayed inbound packets.
save=$(nmcli -g 802-11-wireless.powersave connection show "$con" 2>/dev/null)
case "$save" in
    2|disable) ;;
    *) sudo nmcli connection modify "$con" 802-11-wireless.powersave 2 ;;
esac

# SSID last: nmcli -t escapes ':' inside values, which would break field splitting
# on any earlier column. Fields 1-3 are safe; everything after is the SSID.
scan=$(nmcli -t -f IN-USE,CHAN,SIGNAL,SSID dev wifi list 2>/dev/null)
[ -n "$scan" ] || { trim; exit 0; }

cur=$(echo "$scan" | awk -F: '$1=="*"{print; exit}')
[ -n "$cur" ] || { trim; exit 0; }
cur_chan=$(echo "$cur"   | cut -d: -f2)
cur_sig=$(echo "$cur"    | cut -d: -f3)
cur_ssid=$(echo "$cur"   | cut -d: -f4-)

# Already on 2.4 GHz — nothing to steer away from.
[ "${cur_chan:-0}" -gt 14 ] 2>/dev/null || { trim; exit 0; }

# A healthy 5 GHz link is faster than anything 2.4 GHz can offer; leave it alone.
[ "${cur_sig:-100}" -le "$WEAK_MAX" ] 2>/dev/null || { trim; exit 0; }

# Strongest 2.4 GHz radio advertising this same SSID.
best24=$(echo "$scan" | awk -F: -v s="$cur_ssid" '
    { chan=$2; sig=$3; ssid=$0; sub(/^[^:]*:[^:]*:[^:]*:/, "", ssid) }
    ssid==s && chan+0 >= 1 && chan+0 <= 14 && sig+0 > best { best=sig+0 }
    END { print best+0 }')

if [ "$best24" -eq 0 ]; then
    trim; exit 0   # this SSID has no 2.4 GHz radio in range — leave 5 GHz alone
fi

gain=$(( best24 - cur_sig ))
if [ "$gain" -ge "$MIN_GAIN" ]; then
    log "'$cur_ssid' 2.4 GHz is ${gain} pts stronger (${best24} vs ${cur_sig} on ch ${cur_chan}) — pinning '$con' to 2.4 GHz"
    sudo nmcli connection modify "$con" 802-11-wireless.band bg
    sudo nmcli connection up "$con" >/dev/null 2>&1
fi

trim
exit 0

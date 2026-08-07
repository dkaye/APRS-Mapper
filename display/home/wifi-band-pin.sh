#!/usr/bin/env bash
# wifi-band-pin.sh — MARS APRS Display Pi
#
# Moves a dual-band display Pi off a weak 5 GHz radio onto the same AP's 2.4 GHz
# radio — but only if that actually measures better.
#
# Why: at a Starlink site the AP's band steering parked BigTV on its 5 GHz radio at
# -69 dBm, where the rate collapsed to 6-13 Mbit/s and ~20% of packets were lost.
# That is far outside what WireGuard tolerates, so NetBird ICE sessions died after
# ~70s and the device was unreachable — while outbound traffic kept working, so it
# still looked online and the fault looked like a VPN problem. (Diagnosed 2026-08-06.)
#
# Why it measures instead of trusting signal: signal strength cannot see co-channel
# interference. On the same day, BigTV's 2.4 GHz radio read *stronger* than its
# 5 GHz one, but sat on a channel shared with three APs at full signal — switching
# to it produced 16% loss to the gateway and 100% loss to the internet. So a switch
# is now treated as a proposal: measure, switch, measure again, and revert unless it
# genuinely improved.
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
WEAK_MAX=65          # ...and the 5 GHz link must itself be this weak or worse.
                     # nmcli's scale saturates near 100, so relative gain alone
                     # would demote a healthy 5 GHz link (BigTV: 100 vs 88 at
                     # -34 dBm). The failure this exists to fix read 50 (-69 dBm).
COOLDOWN_SECS=21600  # after a failed attempt, wait 6h before trying that AP again
LOG=/home/pi/wifi-band-pin.log
LOCK=/tmp/wifi-band-pin.lock
COOLDOWN=/home/pi/.wifi-band-pin-cooldown

log()  { echo "$(date '+%F %T') $*" >> "$LOG"; }
trim() { tail -n 200 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG" 2>/dev/null; }
done_() { trim; rm -rf "$LOCK"; exit 0; }

# A run can take ~45s (two link bounces); never let cron overlap them.
mkdir "$LOCK" 2>/dev/null || exit 0
trap 'rm -rf "$LOCK"' EXIT

# Loss% and average RTT to the default gateway. Prints "<loss> <avg_ms>";
# 100 9999 when nothing comes back.
measure() {
    local gw out loss avg
    gw=$(ip route 2>/dev/null | awk '/^default/{print $3; exit}')
    [ -n "$gw" ] || { echo "100 9999"; return; }
    out=$(ping -c 10 -i 0.3 -W 2 "$gw" 2>/dev/null)
    loss=$(echo "$out" | grep -o '[0-9]*% packet loss' | grep -o '^[0-9]*')
    avg=$(echo "$out" | awk -F'/' '/rtt|round-trip/{printf "%d", $5}')
    echo "${loss:-100} ${avg:-9999}"
}

con=$(nmcli -t -f NAME,DEVICE connection show --active 2>/dev/null | awk -F: '$2=="wlan0"{print $1; exit}')

# ── Not associated: undo any pin so a 5 GHz-only network is still reachable ────
if [ -z "$con" ]; then
    nmcli -t -f NAME connection show 2>/dev/null | while read -r c; do
        [ "$(nmcli -g 802-11-wireless.band connection show "$c" 2>/dev/null)" = "bg" ] || continue
        sudo nmcli connection modify "$c" 802-11-wireless.band ""
        log "wlan0 down — cleared 2.4 GHz pin on '$c' so it can try 5 GHz"
    done
    done_
fi

# Power save off costs nothing and is what actually delayed inbound packets.
case "$(nmcli -g 802-11-wireless.powersave connection show "$con" 2>/dev/null)" in
    2|disable) ;;
    *) sudo nmcli connection modify "$con" 802-11-wireless.powersave 2 ;;
esac

# SSID last: nmcli -t escapes ':' inside values, which would break field splitting
# on any earlier column. Fields 1-3 are safe; everything after is the SSID.
scan=$(nmcli -t -f IN-USE,CHAN,SIGNAL,SSID dev wifi list 2>/dev/null)
cur=$(echo "$scan" | awk -F: '$1=="*"{print; exit}')
[ -n "$cur" ] || done_
cur_chan=$(echo "$cur" | cut -d: -f2)
cur_sig=$(echo "$cur"  | cut -d: -f3)
cur_ssid=$(echo "$cur" | cut -d: -f4-)

[ "${cur_chan:-0}" -gt 14 ]   2>/dev/null || done_   # already on 2.4 GHz
[ "${cur_sig:-100}" -le "$WEAK_MAX" ] 2>/dev/null || done_   # 5 GHz is fine, leave it

# Don't retry an AP that already failed this test recently.
if [ -f "$COOLDOWN" ]; then
    read -r until_ts failed_ssid < "$COOLDOWN"
    if [ "$failed_ssid" = "$cur_ssid" ] && [ "$(date +%s)" -lt "${until_ts:-0}" ]; then
        done_
    fi
fi

best24=$(echo "$scan" | awk -F: -v s="$cur_ssid" '
    { chan=$2; sig=$3; ssid=$0; sub(/^[^:]*:[^:]*:[^:]*:/, "", ssid) }
    ssid==s && chan+0 >= 1 && chan+0 <= 14 && sig+0 > best { best=sig+0 }
    END { print best+0 }')
[ "$best24" -ge $(( cur_sig + MIN_GAIN )) ] 2>/dev/null || done_

# ── Propose the switch, then prove it ─────────────────────────────────────────
read -r loss_before avg_before <<EOF
$(measure)
EOF

sudo nmcli connection modify "$con" 802-11-wireless.band bg
sudo nmcli connection up "$con" >/dev/null 2>&1
sleep 10

read -r loss_after avg_after <<EOF
$(measure)
EOF

if [ "$loss_after" -lt "$loss_before" ] || \
   { [ "$loss_after" -eq "$loss_before" ] && [ "$avg_after" -le "$avg_before" ]; }; then
    log "'$cur_ssid': 2.4 GHz verified better (loss ${loss_before}%->${loss_after}%, rtt ${avg_before}->${avg_after}ms) — keeping pin on '$con'"
else
    sudo nmcli connection modify "$con" 802-11-wireless.band ""
    sudo nmcli connection up "$con" >/dev/null 2>&1
    echo "$(( $(date +%s) + COOLDOWN_SECS )) $cur_ssid" > "$COOLDOWN"
    log "'$cur_ssid': 2.4 GHz was NOT better (loss ${loss_before}%->${loss_after}%, rtt ${avg_before}->${avg_after}ms) — reverted, cooling down $(( COOLDOWN_SECS / 3600 ))h"
fi

done_

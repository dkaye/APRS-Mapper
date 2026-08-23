#!/usr/bin/env bash
# Network reliability probe.
#
# Shared by every device type: iGates, Transcribers, the server and the Display Pis.
# Moved here from igate/home/ on 2026-08-19 — a display that wanders between access
# points asks exactly the same question an iGate on a cellular hotspot does, and one
# copy in common/ is one to fix rather than four to drift.
# Separates the WiFi link (Pi -> hotspot) from the WAN path (hotspot -> internet)
# so a flaky cellular backhaul can be told apart from a flaky wireless link.
#
# Usage: nettest.sh <seconds> <label>
# Runs detached-safe: no controlling terminal needed, all output to files.

DUR=${1:-900}
LABEL=${2:-run}
OUT=/home/pi/nettest
STAMP=$(date +%Y%m%d-%H%M%S)
D="$OUT/${LABEL}-${STAMP}"
mkdir -p "$D"

GW=$(ip route | awk '/^default/{print $3; exit}')

{
    echo "host=$(hostname)"
    echo "start=$(date -Is)"
    echo "duration_s=$DUR"
    echo "label=$LABEL"
    echo "gateway=$GW"
    echo "--- wifi ---"
    nmcli -f IN-USE,SSID,SIGNAL,RATE,CHAN dev wifi 2>/dev/null | head -6
    echo "--- active connections ---"
    nmcli -t -f NAME,DEVICE,STATE con show --active 2>/dev/null
    echo "--- addr ---"
    ip -4 addr show wlan0 2>/dev/null | grep inet
} > "$D/context.txt" 2>&1

# 1 s pings. -D timestamps each reply so gaps can be located in wall-clock time.
# Gateway isolates the WiFi hop; the two public anycast targets exercise the
# cellular backhaul via different upstream networks.
ping -D -i 1 -W 2 "$GW"   > "$D/ping-gw.txt"   2>&1 &
P1=$!
ping -D -i 1 -W 2 1.1.1.1 > "$D/ping-cf.txt"   2>&1 &
P2=$!
ping -D -i 1 -W 2 8.8.8.8 > "$D/ping-goog.txt" 2>&1 &
P3=$!

# Every 10 s: radio signal plus a real DNS+TCP+TLS transaction. Pings alone can
# look fine while sessions still stall, so this catches connection-setup pain.
(
    end=$((SECONDS + DUR))
    while [ $SECONDS -lt $end ]; do
        ts=$(date -Is)
        sig=$(awk 'NR==3{gsub(/\./,"",$3); print $3"/70 "$4"dBm"}' /proc/net/wireless)
        m=$(curl -sS -o /dev/null -m 10 \
            -w '%{time_namelookup} %{time_connect} %{time_appconnect} %{time_total} %{http_code}' \
            https://cloudflare.com/cdn-cgi/trace 2>/dev/null) || m="- - - - 000"
        echo "$ts signal=$sig curl=$m"
        sleep 10
    done
) > "$D/samples.txt" 2>&1 &
P4=$!

sleep "$DUR"

# SIGINT so ping prints its packet-loss/RTT summary before exiting.
kill -INT $P1 $P2 $P3 2>/dev/null
kill $P4 2>/dev/null
sleep 2
wait 2>/dev/null

echo "end=$(date -Is)" >> "$D/context.txt"
echo "$D" > "$OUT/last-run"

#!/bin/bash
# Pin mars-aprs.ddns.net to the best available path to aprs-pi:
#   1. Public DDNS   — works when port 443 is forwarded at aprs-pi's network
#   2. Local LAN ARP — NAT hairpin bypass when both Pis are on the same home LAN

APRS_MAC="2c:cf:67:80:ab:83"
TARGET="mars-aprs.ddns.net"

CURRENT_IP=$(grep "$TARGET" /etc/hosts | awk '{print $1}' | head -1)
sed -i "/$TARGET/d" /etc/hosts

# 1. Test public DDNS (no hosts override — uses real DNS)
if curl -sk --max-time 5 "https://$TARGET/" -o /dev/null 2>&1; then
    logger -t aprs-host "DDNS reachable — no local override needed"
    exit 0
fi

# 2. Try local LAN via ARP (home network NAT hairpin bypass)
NEW_IP=$(ip neighbor show | grep -i "$APRS_MAC" | grep -v ' FAILED' | awk '{print $1}' | head -1)
if [[ -z "$NEW_IP" ]] && [[ -n "$CURRENT_IP" ]]; then
    ping -c2 -W2 "$CURRENT_IP" >/dev/null 2>&1 || true
    NEW_IP=$(ip neighbor show | grep -i "$APRS_MAC" | grep -v ' FAILED' | awk '{print $1}' | head -1)
fi

if [[ -n "$NEW_IP" ]]; then
    echo "$NEW_IP $TARGET" >> /etc/hosts
    logger -t aprs-host "Pinned $TARGET to local IP $NEW_IP (NAT hairpin bypass)"
    exit 0
fi

logger -t aprs-host "No path to aprs-pi found — will retry"

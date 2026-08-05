#!/usr/bin/env bash
# igate-relay-sync — pull the relay's igate_relay.json from the VPS to aprs-pi
#
# The aggregation relay (igate-isrelay) runs on a public VPS because the home
# origin is unreachable inbound (Comcast DS-Lite + eero). map/index.php still
# reads /var/www/html/igate_relay.json locally to show proxied-gate activity, so
# this loop pulls that file every SYNC_SECS via a least-privilege forced-command
# SSH key (relaypull@VPS can only `cat` the one file). JSON is validated before
# an atomic replace, so a transient/garbage read never clobbers the live file.
#
# (c)2026 Doug Kaye, K6DRK <doug@rds.com>

set -u
KEY=/home/pi/.ssh/relaypull
REMOTE=relaypull@64.23.166.192
DEST=/var/www/html/igate_relay.json
SYNC_SECS="${SYNC_SECS:-20}"

while true; do
    if OUT=$(ssh -i "$KEY" -o BatchMode=yes -o ConnectTimeout=8 \
                 -o StrictHostKeyChecking=accept-new "$REMOTE" 2>/dev/null) \
       && printf '%s' "$OUT" | python3 -c 'import sys,json; json.load(sys.stdin)' 2>/dev/null; then
        printf '%s' "$OUT" > "$DEST.tmp" && mv "$DEST.tmp" "$DEST"
    fi
    sleep "$SYNC_SECS"
done

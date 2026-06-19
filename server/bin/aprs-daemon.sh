#!/bin/bash
#
# APRS Tracker Map — daemon wrapper
#
# Install: sudo cp aprs-daemon.sh /usr/local/bin/
#          sudo chmod +x /usr/local/bin/aprs-daemon.sh
#
# Normally launched by systemd (see aprs-daemon.service).
# Can also be run manually; the PID-file guard prevents a second
# instance if one is already running under systemd or by hand.
#
# Log lines are written to stdout; systemd appends them to
# /var/log/aprs-daemon/daemon.log as configured in the service unit.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

PHP=/usr/bin/php
DAEMON=/var/www/html/aprsDaemon.php
PIDFILE=/run/aprs-daemon/pid

ts() { date '+%Y-%m-%d %H:%M:%S'; }

# ── Sanity checks ─────────────────────────────────────────────────────────────

if [ ! -x "$PHP" ]; then
    echo "$(ts)  ERROR php not found at $PHP"
    exit 1
fi

if [ ! -f "$DAEMON" ]; then
    echo "$(ts)  ERROR daemon not found at $DAEMON"
    exit 1
fi

# ── Duplicate-start guard ─────────────────────────────────────────────────────

if [ -f "$PIDFILE" ]; then
    OLD_PID=$(cat "$PIDFILE")
    if kill -0 "$OLD_PID" 2>/dev/null; then
        echo "$(ts)  WARN  already running as PID $OLD_PID — aborting duplicate start"
        exit 1
    fi
    echo "$(ts)  INFO  stale PID file (PID $OLD_PID no longer running) — removing"
    rm -f "$PIDFILE"
fi

# ── Wait for DNS ─────────────────────────────────────────────────────────────
# Retry up to 60 s so a transient DNS outage at boot doesn't kill the daemon.

APRS_HOST="noam.aprs2.net"
DNS_RETRIES=12
DNS_WAIT=5

echo $$ > "$PIDFILE"

for i in $(seq 1 $DNS_RETRIES); do
    if getent hosts "$APRS_HOST" >/dev/null 2>&1; then
        break
    fi
    if [ "$i" -eq "$DNS_RETRIES" ]; then
        echo "$(ts)  ERROR DNS for $APRS_HOST failed after $((DNS_WAIT * DNS_RETRIES)) s — giving up"
        rm -f "$PIDFILE"
        exit 1
    fi
    echo "$(ts)  WAIT  DNS not ready (attempt $i/$DNS_RETRIES) — retrying in ${DNS_WAIT}s"
    sleep "$DNS_WAIT"
done

# ── Start ─────────────────────────────────────────────────────────────────────

echo "$(ts)  START PID $$ | php $DAEMON"

# ── Run ───────────────────────────────────────────────────────────────────────

"$PHP" "$DAEMON"
EXIT=$?

# ── Exit ──────────────────────────────────────────────────────────────────────

rm -f "$PIDFILE"

if [ "$EXIT" -eq 0 ]; then
    echo "$(ts)  STOP  PID $$ exited cleanly (code 0)"
else
    echo "$(ts)  FAIL  PID $$ exited with code $EXIT — systemd will restart in 10 s"
fi

exit $EXIT

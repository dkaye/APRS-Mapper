#!/bin/bash
#
# APRS Tracker Map — local launcher (macOS)
#
# @author    Doug Kaye
# @copyright 2026 Doug Kaye. All Rights Reserved.
#
# Starts the PHP built-in web server on port 80 and the APRS daemon.
# Port 80 requires root; the script re-invokes itself with sudo if needed.
#

PHP=/usr/local/bin/php
DIR="$(cd "$(dirname "$0")" && pwd)"
PID_FILE="$DIR/.local-pids"
WEB_LOG=/tmp/aprs-web.log
DAEMON_LOG=/tmp/aprs-daemon.log

# ── Root check ────────────────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    echo "Port 80 requires root — re-running with sudo..."
    exec sudo "$0" "$@"
fi

# ── Kill any existing instances ───────────────────────────────────────────
if [ -f "$PID_FILE" ]; then
    read -r OLD_WEB OLD_DAEMON < "$PID_FILE"
    kill "$OLD_WEB" "$OLD_DAEMON" 2>/dev/null && echo "Stopped previous instances."
    rm -f "$PID_FILE"
fi

# ── Start web server ──────────────────────────────────────────────────────
"$PHP" -S 0.0.0.0:80 -t "$DIR" >> "$WEB_LOG" 2>&1 &
WEB_PID=$!
echo "Web server  PID $WEB_PID  →  http://localhost/"
echo "            log: $WEB_LOG"

# ── Start APRS daemon ─────────────────────────────────────────────────────
cd "$DIR"
"$PHP" "$DIR/aprsDaemon.php" >> "$DAEMON_LOG" 2>&1 &
DAEMON_PID=$!
echo "APRS daemon PID $DAEMON_PID"
echo "            log: $DAEMON_LOG"

echo "$WEB_PID $DAEMON_PID" > "$PID_FILE"

# ── Stop both on Ctrl-C or termination ───────────────────────────────────
cleanup() {
    echo ""
    echo "Stopping web server (PID $WEB_PID) and daemon (PID $DAEMON_PID)..."
    kill "$WEB_PID" "$DAEMON_PID" 2>/dev/null
    rm -f "$PID_FILE"
    exit 0
}
trap cleanup INT TERM

echo ""
echo "Press Ctrl-C to stop."
wait

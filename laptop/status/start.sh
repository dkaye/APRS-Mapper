#!/usr/bin/env bash
# Start the MARS APRS Stats daemon
# Usage: ./start.sh [port=N] [repeat=N] [timeout=N] [debug]

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PID_FILE="$SCRIPT_DIR/daemon.pid"
LOG_FILE="$SCRIPT_DIR/daemon.log"

if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if kill -0 "$PID" 2>/dev/null; then
        echo "Daemon is already running (PID $PID)"
        exit 1
    else
        echo "Stale PID file removed"
        rm -f "$PID_FILE"
    fi
fi

echo "Starting stats daemon…"
sudo php "$SCRIPT_DIR/daemon.php" "$@" >> "$LOG_FILE" 2>&1 &
DPID=$!

# Brief pause so the daemon can write its PID file
sleep 1

if kill -0 "$DPID" 2>/dev/null; then
    echo "Daemon started (PID $DPID)"
    echo "Log: $LOG_FILE"
else
    echo "Daemon failed to start. Check $LOG_FILE"
    exit 1
fi

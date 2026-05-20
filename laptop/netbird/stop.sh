#!/usr/bin/env bash
# Stop the MARS APRS Stats daemon

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PID_FILE="$SCRIPT_DIR/daemon.pid"

if [ ! -f "$PID_FILE" ]; then
    echo "No PID file found — daemon is probably not running"
    exit 1
fi

PID=$(cat "$PID_FILE")
if kill -0 "$PID" 2>/dev/null; then
    sudo kill "$PID"
    echo "Stopped daemon (PID $PID)"
    rm -f "$PID_FILE"
else
    echo "Process $PID not found (already stopped?)"
    rm -f "$PID_FILE"
fi

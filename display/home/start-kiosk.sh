#!/bin/bash
# start-kiosk.sh — MARS APRS Display Pi
#
# Launches Chromium in kiosk mode. If ~/autologin.txt exists, bypasses the
# event gate (?autologin). Line 1 of the file, if present, sets the operator
# name for messaging auto-subscribe.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

URL="https://marsaprs.org/"

if [ -f ~/autologin.txt ]; then
    mapfile -t lines < ~/autologin.txt
    # Blank first line means "use this machine's name". Without the fallback an
    # empty autologin.txt logged in with no operator at all, and the safe default
    # is the hostname — the two only diverge when someone deliberately sets it.
    operator="${lines[0]:-$(hostname)}"
    URL="https://marsaprs.org/?autologin"
    if [ -n "$operator" ]; then
        enc_op=$(python3 -c "import sys,urllib.parse; print(urllib.parse.quote(sys.argv[1]))" "$operator")
        URL="${URL}&operator=${enc_op}"
    fi
fi

export XCURSOR_SIZE=48

# ── Single instance ───────────────────────────────────────────────────────────
# Several things launch the kiosk — the desktop autostart at login, aprs-monitor
# when it switches pages or finds no browser, and hands at the keyboard — and
# nothing stopped them stacking up. Two kiosks means two browsers loading the
# same map: double the tile requests, double the CPU and memory, and a display
# that looks "slow" with a perfectly healthy network.
#
# Chromium's own guard was disabled below by an `rm` of its Singleton files, and
# that rm pointed at ~/.config/chromium while the browser actually runs with
# --user-data-dir=/tmp/chromium, so it had been a no-op for good measure.
#
# Two guards: pgrep catches an instance already running (including one started
# before this change, which holds no lock), and flock closes the race between two
# launches firing at once. flock execs chromium directly, so the lock is held for
# the browser's lifetime and released when it exits.
# Count real browser instances, not Chromium's children: every child process
# carries --type=, the main process does not, and the flock wrapper's arguments
# include --kiosk too. Matching on command line alone counted all of them, which
# made a single browser look like two.
kiosk_count() {
    local p n=0
    for p in $(pgrep -f -- '--kiosk' 2>/dev/null); do
        [ "$(cat "/proc/$p/comm" 2>/dev/null)" = "chromium" ] || continue
        grep -qz -- '--type=' "/proc/$p/cmdline" 2>/dev/null || n=$((n + 1))
    done
    echo "$n"
}

if [ "$(kiosk_count)" -gt 0 ]; then
    echo "start-kiosk: a kiosk is already running — not starting another"
    exit 0
fi

# Clear a stale Singleton left by a crash, now that we know none is running, and
# in the directory the browser actually uses.
rm -f /tmp/chromium/Singleton*

exec flock -n /tmp/mars-kiosk.lock \
    chromium --password-store=basic --kiosk --noerrdialogs --disable-infobars \
    --disable-dev-shm-usage --incognito \
    --disable-features=BlockInsecurePrivateNetworkRequests \
    --force-renderer-accessibility --enable-gpu-rasterization \
    --use-angle=gles \
    --user-data-dir=/tmp/chromium \
    "$URL"

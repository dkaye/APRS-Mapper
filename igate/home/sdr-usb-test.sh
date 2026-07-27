#!/usr/bin/env bash
# sdr-usb-test — check an iGate's RTL-SDR dongle for a flaky USB connection.
#
# Flaky RTL-SDR dongles (cracked USB-A solder joints, worn connectors) drop off
# the USB bus intermittently. On an iGate that shows up as "SDR Not Found" and
# reboot loops. This test isolates the dongle and watches the kernel's USB log
# for spontaneous disconnects, then for disconnects induced by physically
# wiggling the connector.
#
# It is standalone: it stops direwolf and pauses the health watchdog for the
# duration (so nothing else touches the SDR or restarts direwolf), streams from
# the dongle to load the USB link like real operation, and restores everything
# on exit. The boot-time "SDR Not Found" reboot in dw-startup.py only runs at
# boot, so it can't interfere with a test on a running gate.
#
# Usage (run over SSH):
#   sdr-usb-test                 # 5-min passive test, then a 20-s wiggle test
#   sdr-usb-test 120 15          # custom passive / wiggle seconds
#   sdr-usb-test --no-wiggle     # passive only (also auto-selected with no TTY)
#   sdr-usb-test --quick         # 60-s passive + 15-s wiggle (fast check)
#
# Verdict: a healthy dongle shows ZERO USB disconnects in both phases.
#   FAIL    — drops on its own              → replace the dongle
#   SUSPECT — only drops when wiggled       → reseat plug/adapter, else replace
#   PASS    — no disconnects either phase   → healthy
#
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

set -u

PASSIVE=300; WIGGLE=20; DO_WIGGLE=1
for a in "$@"; do
  case "$a" in
    --no-wiggle) DO_WIGGLE=0 ;;
    --quick)     PASSIVE=60; WIGGLE=15 ;;
    [0-9]*)      if [ "$PASSIVE" = 300 ] && [ "$1" = "$a" ]; then PASSIVE="$a"; else WIGGLE="$a"; fi ;;
    *) echo "unknown arg: $a"; exit 2 ;;
  esac
done
# no controlling terminal → nobody can wiggle, so passive-only
[ -t 0 ] || DO_WIGGLE=0

LOG=/home/pi/sdr-usb-test.log
PAUSE=/tmp/sdr-usb-test.pause   # igate-watchdog.sh stands down while this exists
HOST=$(hostname)
say() { echo "$*"; echo "$(date '+%F %T') $*" >> "$LOG"; }

command -v rtl_fm >/dev/null 2>&1 || { echo "rtl_fm not found — cannot test."; exit 1; }

# ── Locate the dongle on the USB bus (works on any Pi model) ──────────────────
find_rtl() {
  local d v p
  for d in /sys/bus/usb/devices/*; do
    [ -f "$d/idVendor" ] || continue
    v=$(cat "$d/idVendor" 2>/dev/null); p=$(cat "$d/idProduct" 2>/dev/null)
    [ "$v" = "0bda" ] && { [ "$p" = "2838" ] || [ "$p" = "2832" ]; } && { basename "$d"; return 0; }
  done
  return 1
}
RTL=$(find_rtl) || { echo "No RTL-SDR on the USB bus. Is the dongle plugged in?"; exit 1; }
SERIAL=$(cat "/sys/bus/usb/devices/$RTL/serial" 2>/dev/null || echo "?")

# ── Count true USB disconnects for this dongle's port since a timestamp ───────
disconnects() { sudo journalctl -k --since "$1" --no-pager 2>/dev/null | grep -icE "usb ${RTL}: USB disconnect"; }
events()      { sudo journalctl -k --since "$1" --no-pager 2>/dev/null | grep -icE "usb ${RTL}:.*(USB disconnect|new high-speed)|error -110|error -71|not accepting|device descriptor"; }
# Broad "is the device still churning?" check, for the post-open settle: any
# enumerate/detach/reset chatter (USB core, DVB driver, or librtlsdr reset).
activity()    { sudo journalctl -k --since "$1" --no-pager 2>/dev/null | grep -icE "usb ${RTL}|dvb_usb|rtl2832|reset high-speed|USB disconnect|new high-speed"; }

# ── Restore everything on exit ────────────────────────────────────────────────
LOAD=""
restore() {
  rm -f "$PAUSE"                          # stop the load loop from relaunching rtl_fm
  [ -n "$LOAD" ] && kill "$LOAD" 2>/dev/null
  pkill -x rtl_fm 2>/dev/null             # kill the test's stream — safe here: direwolf is stopped
  sleep 1
  sudo systemctl start direwolf >/dev/null 2>&1 || true
  echo
  echo "Restored: watchdog un-paused, direwolf $(systemctl is-active direwolf 2>/dev/null)."
}
trap restore EXIT INT TERM

echo    "=============================================================="
say     "sdr-usb-test on $HOST  |  dongle port $RTL  serial $SERIAL"
echo    "=============================================================="

# ── Quiesce: pause watchdog, stop direwolf, then load the link ────────────────
echo "Pausing health watchdog and stopping direwolf (this test owns the SDR)..."
touch "$PAUSE"
sudo systemctl stop direwolf >/dev/null 2>&1
sleep 2
# Stream from the dongle to /dev/null so the USB link is loaded like real use.
# Launched ONCE (not relaunched): opening the device detaches the kernel DVB
# driver and, on some dongles, triggers a one-time libusb reset that the kernel
# logs as a USB disconnect+reconnect. That open transient is NOT a fault, so we
# must let it finish before counting (see the settle loop below). If rtl_fm
# later dies, that death is a real drop the kernel already recorded.
rtl_fm -f 144390000 -M fm -s 24000 - >/dev/null 2>&1 &
LOAD=$!

# Wait for the device-open transient (DVB detach / reset / re-enumerate) to fully
# settle before Phase 1 starts — otherwise it gets miscounted as a passive drop.
echo "Letting the SDR settle after opening it..."
S0=$(date '+%F %T'); prev=-1; quiet=0
for _ in $(seq 1 20); do          # up to ~40 s
  sleep 2
  cur=$(activity "$S0")
  if [ "$cur" = "$prev" ]; then quiet=$((quiet+2)); else quiet=0; fi
  prev=$cur
  [ "$quiet" -ge 8 ] && break     # 8 s with no new USB activity == settled
done

# ── Phase 1: passive ──────────────────────────────────────────────────────────
echo
say  "Phase 1: PASSIVE — leave everything ALONE for ${PASSIVE}s ($((PASSIVE/60))m$((PASSIVE%60))s)."
P1=$(date '+%F %T'); e=0
while [ $e -lt "$PASSIVE" ]; do
  sleep 15; e=$((e+15)); [ $e -gt "$PASSIVE" ] && e=$PASSIVE
  printf "\r  %4ds / %ds  —  disconnects so far: %s     " "$e" "$PASSIVE" "$(disconnects "$P1")"
done
echo
P1D=$(disconnects "$P1"); P1E=$(events "$P1")
say "Phase 1 result: $P1D disconnect(s), $P1E total USB event(s) in ${PASSIVE}s."

# ── Phase 2: wiggle ───────────────────────────────────────────────────────────
P2D=0
if [ "$DO_WIGGLE" = 1 ]; then
  echo
  echo "Phase 2: WIGGLE test."
  echo "  Gently flex the dongle body, its USB plug, and the adapter/cable."
  read -rp "  Press Enter to start the ${WIGGLE}s wiggle window... " _
  P2=$(date '+%F %T'); echo "  >>> WIGGLE NOW <<<"; e=0
  while [ $e -lt "$WIGGLE" ]; do
    sleep 3; e=$((e+3)); [ $e -gt "$WIGGLE" ] && e=$WIGGLE
    printf "\r  %3ds / %ds  —  disconnects: %s     " "$e" "$WIGGLE" "$(disconnects "$P2")"
  done
  echo
  P2D=$(disconnects "$P2")
  say "Phase 2 result: $P2D disconnect(s) while wiggling (${WIGGLE}s)."
else
  say "Phase 2 (wiggle) skipped (no terminal or --no-wiggle)."
fi

# ── Verdict ───────────────────────────────────────────────────────────────────
echo
echo    "===================== RESULT ($HOST) ====================="
echo    " dongle port $RTL  serial $SERIAL"
echo    " passive ${PASSIVE}s: $P1D disconnect(s)   wiggle ${WIGGLE}s: $P2D disconnect(s)"
if [ "$P1D" -gt 0 ]; then
  V="FAIL — dongle disconnects on its own. Replace it."
elif [ "$P2D" -gt 0 ]; then
  V="SUSPECT — stable at rest, drops when disturbed. Reseat the plug/adapter; if it recurs, replace the dongle."
else
  V="PASS — no USB disconnects in either phase. Healthy."
fi
echo    " VERDICT: $V"
echo    " (detail: sudo journalctl -k --since \"$P1\" | grep 'usb $RTL')"
echo    "=========================================================="
say "VERDICT: $V"

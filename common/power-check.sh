#!/usr/bin/env bash
# Power supply check — is this Pi actually getting the current it needs?
#
# Shared by every device type: iGates, Transcribers, the server and the Display Pis.
# Unlike the SDR self-noise test it frees nothing and stops nothing, so it is safe to
# run at any moment, including mid-event.
#
# This exists because marginal power does not announce itself. It presents as the
# symptom of whatever else was going on at the time — a USB drive that "does not work",
# a receiver that "goes deaf", a display that "reboots at random" — and the real cause
# is a supply sagging under load. A Pi 5 migration cost most of an evening to exactly
# that, and the tell was two commands nobody had thought to run.
#
# Three things are checked, and the third only exists on the Pi 5:
#
#   1. The throttle word. `vcgencmd get_throttled` reports both what is happening now
#      and what has happened since boot, and the "has happened" bits are the valuable
#      ones — a brownout at 3am leaves a mark that is still readable at noon.
#
#   2. The kernel's own under-voltage messages, counted. The throttle word says
#      something happened; these say how often, which separates one transient at
#      power-on from a supply that sags every time the CPU is asked for anything.
#
#   3. What was negotiated over USB-PD. THIS is the one that catches the mistake
#      everybody makes: a Pi 5 needs 5V at 5A, and "100W" on a charger is a rating at
#      20V. Most laptop supplies top out at 3A on the 5V rail, which is 15W — less than
#      the official 27W supply — and the Pi silently accepts it and limits total USB
#      current to 600mA. The board looks fine and the SSD and the SDR fight over a
#      trickle. Nothing about the wattage on the box tells you this; the negotiated
#      profile does.
#
# Usage: /home/pi/power-check.sh          (add --json for machine-readable only)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>
set -u

OUT=/home/pi/power-check.json
JSON_ONLY=0
[ "${1:-}" = "--json" ] && JSON_ONLY=1

say() { [ "$JSON_ONLY" -eq 1 ] || echo "power: $*"; }

command -v vcgencmd >/dev/null 2>&1 || { say "no vcgencmd — not a Pi, skipping"; exit 0; }

MODEL=$(tr -d '\0' < /proc/device-tree/model 2>/dev/null || echo unknown)
HOST=$(hostname)

# ── the throttle word ────────────────────────────────────────────────────────
# Bits 0-3 are now, bits 16-19 are since boot. The pairs mean the same things, and
# reporting only the live ones would call a machine healthy ten seconds after it
# brownedout.
RAW=$(vcgencmd get_throttled 2>/dev/null | sed 's/.*=//')
T=$((RAW))
bit() { [ $(( (T >> $1) & 1 )) -eq 1 ] && echo 1 || echo 0; }
UV_NOW=$(bit 0);   CAP_NOW=$(bit 1);  THR_NOW=$(bit 2);  TEMP_NOW=$(bit 3)
UV_EVER=$(bit 16); CAP_EVER=$(bit 17); THR_EVER=$(bit 18); TEMP_EVER=$(bit 19)

# ── the kernel's own account ─────────────────────────────────────────────────
# dmesg may be restricted to root; an unreadable log is not evidence of health, so it
# is reported as unknown rather than zero.
if UVLOG=$(dmesg 2>/dev/null | grep -ci 'under-voltage\|undervoltage'); then
    :
else
    UVLOG=-1
fi

TEMP=$(vcgencmd measure_temp 2>/dev/null | sed 's/[^0-9.]//g')

# ── what the supply actually offered ─────────────────────────────────────────
# Pi 5 only: earlier boards do no USB-PD negotiation at all, so their absence here is
# normal and not a fault.
PD_DIR=/sys/firmware/devicetree/base/chosen/power
MAX_CURRENT=""
USB_UNLOCKED=""
PROFILES=""
if [ -r "$PD_DIR/max_current" ] && command -v python3 >/dev/null 2>&1; then
    MAX_CURRENT=$(python3 -c "
import struct
print(struct.unpack('>I', open('$PD_DIR/max_current','rb').read())[0])" 2>/dev/null || echo "")
    [ -r "$PD_DIR/usb_max_current_enable" ] && USB_UNLOCKED=$(python3 -c "
import struct
print(struct.unpack('>I', open('$PD_DIR/usb_max_current_enable','rb').read())[0])" 2>/dev/null || echo "")
    # Fixed-supply PDOs: bits 19-10 are volts in 50mV units, bits 9-0 amps in 10mA.
    [ -r "$PD_DIR/usbpd_power_data_objects" ] && PROFILES=$(python3 -c "
import struct
d = open('$PD_DIR/usbpd_power_data_objects','rb').read()
out = []
for i in range(0, len(d) - 3, 4):
    w = struct.unpack('>I', d[i:i+4])[0]
    if w == 0 or (w >> 30) & 3: continue
    out.append('%.1fV/%.1fA' % (((w >> 10) & 0x3FF) * 0.05, (w & 0x3FF) * 0.01))
print(' '.join(out))" 2>/dev/null || echo "")
fi

# ── grade ────────────────────────────────────────────────────────────────────
GRADE=GOOD
WHY=""
add() { WHY="${WHY:+$WHY; }$1"; }

if [ "$UV_NOW" = 1 ] || [ "$THR_NOW" = 1 ]; then
    GRADE=FAIL
    [ "$UV_NOW" = 1 ]  && add "under-voltage RIGHT NOW"
    [ "$THR_NOW" = 1 ] && add "throttled right now"
elif [ "$UV_EVER" = 1 ] || [ "$THR_EVER" = 1 ]; then
    GRADE=WARN
    [ "$UV_EVER" = 1 ]  && add "under-voltage has occurred since boot"
    [ "$THR_EVER" = 1 ] && add "throttling has occurred since boot"
fi
# A Pi 5 on a 3A supply is a warning even with a clean throttle word, because the
# consequence is a 600mA cap on ALL USB rather than anything the CPU reports.
case "$MODEL" in
  *"Pi 5"*)
    if [ -n "$MAX_CURRENT" ] && [ "$MAX_CURRENT" -lt 5000 ]; then
        [ "$GRADE" = GOOD ] && GRADE=WARN
        add "only ${MAX_CURRENT}mA negotiated — a Pi 5 wants 5000mA (5V/5A), and below that USB is capped to 600mA total"
    fi
    ;;
esac
[ "$TEMP_EVER" = 1 ] && [ "$GRADE" = GOOD ] && { GRADE=WARN; add "soft temperature limit has been reached — check cooling"; }

cat > "$OUT" <<EOF
{"host":"$HOST","model":"$MODEL","grade":"$GRADE","why":"$WHY",
 "throttled":"$RAW","undervoltage_now":$UV_NOW,"undervoltage_ever":$UV_EVER,
 "throttled_now":$THR_NOW,"throttled_ever":$THR_EVER,
 "temp_c":"${TEMP:-}","undervoltage_log_lines":$UVLOG,
 "max_current_ma":"${MAX_CURRENT:-}","usb_unlocked":"${USB_UNLOCKED:-}",
 "supply_offers":"$PROFILES"}
EOF

if [ "$JSON_ONLY" -eq 1 ]; then cat "$OUT"; exit 0; fi

say "$HOST ($MODEL)"
say "  $GRADE${WHY:+ — $WHY}"
say "  throttled=$RAW  temp=${TEMP:-?}C  kernel under-voltage lines: $([ "$UVLOG" -ge 0 ] && echo "$UVLOG" || echo 'unreadable')"
[ -n "$MAX_CURRENT" ] && say "  negotiated ${MAX_CURRENT}mA${USB_UNLOCKED:+, usb_max_current_enable=$USB_UNLOCKED}"
[ -n "$PROFILES" ]    && say "  supply offers: $PROFILES"
[ "$GRADE" = GOOD ]   && say "  nothing to do"
exit 0

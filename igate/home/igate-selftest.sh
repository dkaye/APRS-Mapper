#!/usr/bin/env bash
# iGate SDR self-noise test — quantifies internal birdies/spurs near the APRS
# channel (the thing that quietly deafens a gate). Frees the SDR, runs a few
# rtl_power sweeps, analyses them with igate-selftest.py, and writes
# /home/pi/selftest.json plus a history line. Safe to run any time; invoked
# nightly by auto-update.sh before the reboot.
#
# Antenna can stay connected — the analyzer min-holds across sweeps to reject
# real signals, leaving only the always-present internal spurs.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>
set -u

OUT=/home/pi/selftest.json
HIST=/home/pi/selftest-history.csv
PY=/home/pi/igate-selftest.py

command -v rtl_power >/dev/null 2>&1 || { echo "selftest: no rtl_power — skipping"; exit 0; }
command -v python3   >/dev/null 2>&1 || { echo "selftest: no python3 — skipping"; exit 0; }
[ -f "$PY" ] || { echo "selftest: $PY missing — skipping"; exit 0; }

TMP=$(mktemp -d)
# Always clean up and hand the SDR back to direwolf, whatever happens — including
# force-killing any rtl_* left stuck in a blocking USB read.
trap 'sudo pkill -9 -f rtl_power >/dev/null 2>&1; rm -rf "$TMP"; sudo systemctl start direwolf >/dev/null 2>&1 || true' EXIT

# ── Metadata (gathered BEFORE freeing the SDR) ────────────────────────────────
# The dongle string comes from direwolf's own boot log, not from probing the
# device — running rtl_test here would wedge the tuner and break the sweeps.
HOST=$(hostname)
IPADDR=$(hostname -I 2>/dev/null | awk '{print $1}')
MODEL=$(tr -d '\0' < /proc/device-tree/model 2>/dev/null)
# -o cat prints just the message body (no "Jul 23 20:41:45 host proc:" prefix),
# so the "0:" in a timestamp like 20:41:45 can't be mistaken for the device index.
DONGLE=$(sudo journalctl -u direwolf -b -o cat --no-pager 2>/dev/null \
    | grep -aoE '[0-9]+:[[:space:]]+[A-Za-z].+SN:[[:space:]]*[0-9A-Fa-f]+' | tail -1 \
    | sed -E 's/^[0-9]+:[[:space:]]*//')
IGVER=$(grep -oE 'dashboardversion *= *"[^"]*"' /var/www/html/config.php 2>/dev/null | grep -oE '[0-9.]+' | head -1)
META=$(printf '{"host":"%s","ip":"%s","pi_model":"%s","dongle":"%s","igate_version":"%s","ts":"%s"}' \
    "$HOST" "$IPADDR" "$MODEL" "$DONGLE" "$IGVER" "$(date '+%Y-%m-%dT%H:%M:%S')")

sudo systemctl stop direwolf >/dev/null 2>&1
sleep 2

# ── 5 sweeps of the 2 m band at fixed gain (spread over ~60 s) ────────────────
# Several sweeps let the analyzer's max-hold + occurrence filter catch an
# intermittent birdie while rejecting one-off over-the-air transmissions.
for i in 1 2 3 4 5; do
    # -k 5: if rtl_power ignores SIGTERM (stuck in a USB read), SIGKILL 5 s later.
    timeout -k 5 15 rtl_power -f 144M:148M:1000 -g 40 -i 5 -1 "$TMP/s$i.csv" 2>/dev/null || true
    sleep 1   # let the USB device settle before reopening it
done

# ── Analyse ───────────────────────────────────────────────────────────────────
if python3 "$PY" "$META" "$TMP"/s*.csv > "$TMP/out.json" 2>/dev/null && [ -s "$TMP/out.json" ]; then
    cp "$TMP/out.json" "$OUT"
    # Append a one-line history record.
    python3 - "$OUT" >> "$HIST" 2>/dev/null <<'PYEOF'
import json, sys
d = json.load(open(sys.argv[1]))
print("%s,%s,%s,%s,%s,%s,%s" % (
    d.get('ts',''), d.get('host',''), d.get('grade',''),
    d.get('aprs_guard_spur_db',''), d.get('aprs_guard_spur_mhz',''),
    d.get('worst_band_spur_db',''), d.get('worst_band_spur_mhz','')))
PYEOF
    echo "selftest: $(python3 -c "import json;d=json.load(open('$OUT'));print('%s  APRS-guard spur %.1f dB @ %s MHz'%(d['grade'],d['aprs_guard_spur_db'],d['aprs_guard_spur_mhz']))" 2>/dev/null)"
    # Upload to the fleet dashboard (non-fatal; the local copy is kept regardless).
    curl -fsS --max-time 20 -X POST -H 'Content-Type: application/json' \
        --data-binary @"$OUT" "https://marsaprs.org/igate/selftest/upload.php" >/dev/null 2>&1 \
        && echo "selftest: uploaded to fleet dashboard" || echo "selftest: upload failed (non-fatal)"
else
    echo "selftest: analysis failed"
fi
# direwolf restored by trap

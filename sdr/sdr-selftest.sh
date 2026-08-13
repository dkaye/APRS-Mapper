#!/usr/bin/env bash
# SDR self-noise test — quantifies internal birdies/spurs near the frequency this
# receiver actually listens on, which is the thing that quietly deafens it.
#
# Shared by iGates and Transcribers. Both are a Pi with an RTL-SDR and a service holding
# the dongle; the only differences are which service to stop, which frequency matters,
# and where the metadata comes from. Those are the two profiles below — selected by what
# is installed, so cron invokes the same command on every device in the fleet.
#
# Frees the SDR, runs a few rtl_power sweeps, analyses them with sdr-selftest.py, and
# writes /home/pi/selftest.json plus a history line. Safe to run any time; invoked
# nightly by auto-update.sh before the reboot.
#
# The antenna can stay connected — the analyzer min-holds across sweeps to reject real
# signals, leaving only the always-present internal spurs.
#
# Usage: /home/pi/sdr-selftest.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>
set -u

OUT=/home/pi/selftest.json
HIST=/home/pi/selftest-history.csv
PY=/home/pi/sdr-selftest.py
UPLOAD="https://marsaprs.org/igate/selftest/upload.php"

command -v rtl_power >/dev/null 2>&1 || { echo "selftest: no rtl_power — skipping"; exit 0; }
command -v python3   >/dev/null 2>&1 || { echo "selftest: no python3 — skipping"; exit 0; }
[ -f "$PY" ] || { echo "selftest: $PY missing — skipping"; exit 0; }

jesc() { printf '%s' "$1" | sed -E 's/\\/\\\\/g; s/"/\\"/g'; }

HOST=$(hostname)
IPADDR=$(hostname -I 2>/dev/null | awk '{print $1}')
MODEL=$(tr -d '\0' < /proc/device-tree/model 2>/dev/null)

# ── Which kind of device is this? ────────────────────────────────────────────
if [ -f /home/pi/direwolf.conf ]; then
    PROFILE=igate
elif [ -f /etc/transcriber/channels.json ]; then
    PROFILE=transcriber
else
    echo "selftest: neither an iGate nor a Transcriber — skipping"; exit 0
fi
echo "selftest: $PROFILE profile"

# ── One run: stop the holder, sweep, analyse, upload ─────────────────────────
# $1 host key (one report per receiver on the dashboard)
# $2 friendly name   $3 watch Hz   $4 sweep range   $5 dongle serial ("" for default)
# $6 extra metadata fields, already JSON-escaped and comma-prefixed, or ""
run_one() {
    local key="$1" name="$2" watch="$3" range="$4" serial="$5" extra="$6"
    local tmp; tmp=$(mktemp -d)
    local meta
    meta=$(printf '{"host":"%s","name":"%s","ip":"%s","pi_model":"%s","watch_hz":"%s","ts":"%s"%s}' \
        "$(jesc "$key")" "$(jesc "$name")" "$IPADDR" "$MODEL" "$watch" \
        "$(date '+%Y-%m-%dT%H:%M:%S')" "$extra")

    local dev=()
    [ -n "$serial" ] && dev=(-d "$serial")

    # Several sweeps let the analyzer's max-hold + occurrence filter catch an
    # intermittent birdie while rejecting one-off over-the-air transmissions.
    local i
    for i in 1 2 3 4 5; do
        # -k 5: if rtl_power ignores SIGTERM (stuck in a USB read), SIGKILL 5 s later.
        timeout -k 5 15 rtl_power "${dev[@]}" -f "$range" -g 40 -i 5 -1 "$tmp/s$i.csv" 2>/dev/null || true
        sleep 1   # let the USB device settle before reopening it
    done

    if python3 "$PY" --watch "$watch" --meta "$meta" "$tmp"/s*.csv > "$tmp/out.json" 2>/dev/null \
       && [ -s "$tmp/out.json" ]; then
        # Per-receiver, plus the well-known path the iGate has always written. A
        # Transcriber with two channels would otherwise overwrite its own first result
        # before anybody could read it.
        cp "$tmp/out.json" "/home/pi/selftest-$(printf '%s' "$key" | tr -c 'A-Za-z0-9_.-' '_').json"
        cp "$tmp/out.json" "$OUT"
        python3 - "$OUT" >> "$HIST" 2>/dev/null <<'PYEOF'
import json, sys
d = json.load(open(sys.argv[1]))
print("%s,%s,%s,%s,%s,%s,%s" % (
    d.get('ts',''), d.get('host',''), d.get('grade',''),
    d.get('guard_spur_db',''), d.get('guard_spur_mhz',''),
    d.get('worst_band_spur_db',''), d.get('worst_band_spur_mhz','')))
PYEOF
        echo "selftest: $(python3 -c "
import json;d=json.load(open('$OUT'));f=d.get('guard_spur_mhz')
print('%s  %s  %s' % (d.get('host',''), d['grade'],
      'spur %.1f dB @ %s MHz' % (d['guard_spur_db'], f) if f not in (None,'') else 'no measurable guard spur'))" 2>/dev/null)"
        curl -fsS --max-time 20 -X POST -H 'Content-Type: application/json' \
            --data-binary @"$OUT" "$UPLOAD" >/dev/null 2>&1 \
            && echo "selftest: uploaded to fleet dashboard" || echo "selftest: upload failed (non-fatal)"
    else
        echo "selftest: analysis failed for $key"
    fi
    rm -rf "$tmp"
}

# ── iGate ────────────────────────────────────────────────────────────────────
if [ "$PROFILE" = igate ]; then
    # The dongle string comes from direwolf's own boot log, not from probing the device —
    # running rtl_test here would wedge the tuner and break the sweeps.
    # -o cat prints just the message body (no "Jul 23 20:41:45 host proc:" prefix), so
    # the "0:" in a timestamp like 20:41:45 can't be mistaken for the device index.
    DONGLE=$(sudo journalctl -u direwolf -b -o cat --no-pager 2>/dev/null \
        | grep -aoE '[0-9]+:[[:space:]]+[A-Za-z].+SN:[[:space:]]*[0-9A-Fa-f]+' | tail -1 \
        | sed -E 's/^[0-9]+:[[:space:]]*//')
    IGVER=$(grep -oE 'dashboardversion *= *"[^"]*"' /var/www/html/config.php 2>/dev/null \
        | grep -oE '[0-9.]+' | head -1)
    MYCALL=$(grep -iE '^MYCALL[[:space:]]' /home/pi/direwolf.conf 2>/dev/null | awk '{print $2}' | head -1)
    # The location tail of the PBEACON comment, e.g.
    #   comment="iGate 5.2 by MARS, Marconi Center, California" → "Marconi Center, California"
    NAME=$(grep -iE '^PBEACON' /home/pi/direwolf.conf 2>/dev/null | grep -oE 'comment="[^"]*"' | head -1 \
        | sed -E 's/^comment="//; s/"$//; s/^iGate[^,]*,[[:space:]]*//')

    # Always hand the SDR back, whatever happens — including force-killing any rtl_power
    # left stuck in a blocking USB read.
    # NB: match by exact process name (-x), NOT -f. A -f pattern of "rtl_power" also
    # matches this very "sudo pkill -9 ... rtl_power" command line, so pkill would
    # SIGKILL its own sudo wrapper — which bash then reports as a stray "Killed" line.
    trap 'sudo pkill -9 -x rtl_power >/dev/null 2>&1; sudo systemctl start direwolf >/dev/null 2>&1 || true' EXIT
    sudo systemctl stop direwolf >/dev/null 2>&1
    sleep 2

    # No -d: an iGate has one dongle, and rtl_power's default device is it.
    run_one "$HOST" "$NAME" 144390000 144M:148M:1000 "" \
        ",\"callsign\":\"$(jesc "$MYCALL")\",\"igate_version\":\"$IGVER\",\"device_version\":\"$IGVER\",\"dongle\":\"$(jesc "$DONGLE")\",\"kind\":\"igate\""
    exit 0
fi

# ── Transcriber ──────────────────────────────────────────────────────────────
# One report per CHANNEL, not per device: each channel has its own dongle and its own
# frequency, so they are separate receivers that happen to share a Pi, and a spur that
# deafens one says nothing about the other. The dashboard stores one report per `host`
# key, so the channel id is what goes there.
TRVER=$(cat /etc/transcriber/version 2>/dev/null)

# What to put back afterwards. A glob cannot do it: `systemctl start 'transcriber@*'`
# matches only units systemd already has loaded, and stopping them is what unloads them,
# so the restore would silently start nothing and leave the receiver off the air.
RUNNING=$(systemctl list-units --plain --no-legend 'transcriber@*.service' 2>/dev/null | awk '{print $1}')
trap 'sudo pkill -9 -x rtl_power >/dev/null 2>&1;
      for u in $RUNNING; do sudo systemctl start "$u" >/dev/null 2>&1 || true; done' EXIT
for u in $RUNNING; do sudo systemctl stop "$u" >/dev/null 2>&1; done
sleep 2

# id, label, frequency and dongle serial for every enabled channel, one per line.
python3 - <<'PYEOF' > /tmp/sdr-selftest-channels.$$ 2>/dev/null
import json
for c in json.load(open('/etc/transcriber/channels.json')).get('channels', []):
    if c.get('enabled', True):
        print('%s\t%s\t%s\t%s' % (c.get('id',''), c.get('label',''),
                                  c.get('frequency',''), c.get('serial','')))
PYEOF

if [ ! -s "/tmp/sdr-selftest-channels.$$" ]; then
    echo "selftest: no enabled channels — nothing to measure"
    rm -f "/tmp/sdr-selftest-channels.$$"
    exit 0
fi

while IFS=$'\t' read -r ID LABEL FREQ SERIAL; do
    [ -n "$FREQ" ] || continue
    # +/-2 MHz around the channel at 1 kHz bins, so the sweep follows the receiver
    # wherever it is tuned instead of assuming the 2 m band.
    RANGE=$(python3 -c "f=int('$FREQ'); print('%d:%d:1000' % (f-2_000_000, f+2_000_000))")
    echo "selftest: measuring $ID at $(python3 -c "print(int('$FREQ')/1e6)") MHz"
    run_one "$ID" "$LABEL" "$FREQ" "$RANGE" "$SERIAL" \
        ",\"transcriber_version\":\"$TRVER\",\"device_version\":\"$TRVER\",\"device\":\"$(jesc "$HOST")\",\"kind\":\"transcriber\""
done < "/tmp/sdr-selftest-channels.$$"
rm -f "/tmp/sdr-selftest-channels.$$"

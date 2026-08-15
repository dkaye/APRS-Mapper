#!/usr/bin/env bash
# Transcriber channel calibration — measures one channel's tuner gain and squelch.
#
# Started by transcriber-calibrate@<channel>.service, which the 60-second config poll
# starts when somebody presses Recalibrate in the channel manager. Never at boot and never
# on a timer: measuring holds the dongle for a couple of minutes, and a channel going deaf
# at an hour nobody chose — during a net, say — is a worse thing than one running numbers
# measured a month ago.
#
#   stop the channel  →  transcriber.py --calibrate  →  report each line to the server
#                                                    →  start the channel again
#
# The worker does the radio work and prints what it is doing, a line of JSON at a time.
# This forwards each line as it appears, which is what makes the manager's countdown
# honest: the device says when the measurement actually STARTED, up to a minute after the
# button was pressed, rather than the page counting down from a moment that meant nothing.
#
# Runs as root, and holds the DEVICE token — the same one auto-update.sh fetches with.
# That is the whole reason this is a separate script rather than part of the worker: the
# worker runs as pi and holds the CHANNEL token, which writes log entries and must not be
# able to do anything else. Neither token can do the other's job, and this keeps it that
# way.
#
# Usage: sudo /opt/transcriber/bin/calibrate.sh <channel-id>
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

# No `set -e`. Whatever happens in here, the channel has to go back on the air — see the
# trap below — and an unexpected non-zero exit from a curl must not skip it.
set -uo pipefail

CHANNEL="${1:-}"
if [ -z "$CHANNEL" ]; then
    echo "usage: calibrate.sh <channel-id>" >&2
    exit 2
fi

BASE="${BASE:-https://marsaprs.org/transcriber}"
TOKEN_FILE="${TOKEN_FILE:-/home/pi/.transcriber-token}"
WORKER="${WORKER:-/opt/transcriber/bin/transcriber.py}"
UNIT="transcriber@$CHANNEL.service"
# The same flag compare-models.py uses, for the same reason: while it exists the config
# poll leaves the channels alone. Without it the poll would start this channel again
# within a minute of us stopping it, and the two would fight over the one dongle for the
# rest of the measurement — which would then be a measurement of nothing in particular.
PAUSE=/tmp/transcriber-bench.pause
# The same log the updates go to, so the story of a device reads in one place. Overridable
# only so this can be exercised in a sandbox; nothing in the field should set it.
LOG="${LOG:-/var/log/transcriber/update.log}"

mkdir -p "$(dirname "$LOG")"
TMP=$(mktemp -d)

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') calibrate $CHANNEL: $*" | tee -a "$LOG"; }

# One report to the server. Never fatal: a measurement of this site is worth keeping even
# if nobody could be told about it, and the channel must go back on the air either way.
#
# The token goes through the environment rather than the command line, where `ps` would
# show it to anybody logged in, and the request body is built by python because these
# lines are JSON the worker wrote and welding strings together to add three fields is how
# a body ends up malformed on exactly the channel whose id has something odd in it.
report() {
    [ -f "$TOKEN_FILE" ] || return 0
    # $(hostname), not python's socket.gethostname(): the device identifies itself to the
    # server with exactly what auto-update.sh fetches with, and a report under a name the
    # registry does not hold is a 403 that looks like a bad token.
    TRANSCRIBER_TOKEN="$(cat "$TOKEN_FILE")" CHANNEL="$CHANNEL" DEVICE="$(hostname)" \
    LINE="$1" python3 - > "$TMP/report.json" <<'PYEOF' 2>/dev/null || return 0
import json, os, sys
d = json.loads(os.environ["LINE"])
d.update(channel=os.environ["CHANNEL"], device=os.environ["DEVICE"],
         token=os.environ["TRANSCRIBER_TOKEN"])
json.dump(d, sys.stdout)
PYEOF
    curl -fsS --max-time 20 -H 'Content-Type: application/json' \
         --data-binary "@$TMP/report.json" "$BASE/report.php" >/dev/null 2>&1 \
        || log "could not tell the server (it will be retried by nobody; press the button again)"
}

# Whether it was listening when we arrived, so it is left as it was found. A channel
# switched off in the manager, or stopped by hand, must not be started by a measurement —
# and one that was listening must be listening again however this ends.
WAS_RUNNING=""
systemctl is-active --quiet "$UNIT" && WAS_RUNNING=1

# Whatever happens — a failure, a timeout, systemd killing us — the receiver goes back on
# the air. A channel left stopped by a measurement is a silent log during an event.
#
# Only from the shell that set it. A subshell that dies — bash runs an inherited EXIT trap
# when one exits on a fatal error — would otherwise put the channel back on the air in the
# middle of the measurement, with the dongle still held. Seen, while this was being
# written, from nothing worse than an unset variable.
MAIN_SHELL="${BASHPID:-$$}"

restore() {
    [ "${BASHPID:-$$}" = "$MAIN_SHELL" ] || return 0
    rm -f "$PAUSE"
    if [ -n "$WAS_RUNNING" ]; then
        systemctl start "$UNIT" || log "could not start $UNIT again"
    else
        log "leaving $UNIT stopped, which is how it was found"
    fi
    rm -rf "$TMP"
}
trap restore EXIT

log "measuring — the channel is off the air until this finishes"
echo "$UNIT" > "$PAUSE"
systemctl stop "$UNIT"
# rtl_fm does not release the dongle the instant systemd asks it to, and opening a device
# that is still held reads as a receiver producing nothing at all — which this would then
# report as a wedged tuner. Two seconds is what the bench tool waits, for the same reason.
sleep 2

# The measurement itself, as pi. Who runs it is not a detail: the worker creates
# /var/spool/transcriber/<channel> if it is not there, and one created by root is one the
# channel then cannot put its outbox in — a receiver that dies at startup, on the one
# channel that had never run before somebody calibrated it.
measure() {
    if [ "$(id -u)" = 0 ]; then
        runuser -u pi -- "$WORKER" --channel "$CHANNEL" --calibrate 2>>"$LOG"
    else
        "$WORKER" --channel "$CHANNEL" --calibrate 2>>"$LOG"
    fi
}

# Line by line, forwarded as they arrive rather than collected and sent at the end. The
# first line is the one the manager is waiting for.
#
# Redirected from a process substitution rather than piped into: a `cmd | while` puts the
# loop in a subshell, where REPORTED would be set in a copy of this shell and lost.
REPORTED=""
while IFS= read -r line; do
    REPORTED=1
    log "$line"
    report "$line"
done < <(measure)

# A worker that died before saying anything — a missing interpreter, a channel id that is
# not in the config — would otherwise leave the manager counting down to a report that is
# never coming. Say so instead.
if [ -z "$REPORTED" ]; then
    log "the worker produced no result"
    # One line, and it has to stay one line: this is parsed as JSON, where a literal
    # newline inside a string is invalid — and a report that fails to parse is a report
    # that silently never arrives, which is the exact failure this branch exists to avoid.
    report '{"state":"failed","error":"the receiver did not run the measurement — see the update log on the device"}'
fi

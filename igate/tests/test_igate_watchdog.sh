#!/usr/bin/env bash
# Tests for the decode check in igate-watchdog.sh.
#
# This runs from cron on every gate in the fleet and it can restart direwolf, so the
# failure that matters is not "missed a dead receiver" — it is "restarted a healthy one,
# everywhere, on a schedule." Most of what follows is therefore about the cases where it
# must do nothing: a quiet band, a gate that just booted, a log that logrotate truncated,
# a diagnostic holding the SDR.
#
# Runs entirely on the local machine. The script under test is copied into a sandbox with
# its paths rewritten and driven against stub commands — no Pi, no SDR, no root.
#
# Usage: igate/tests/test_igate_watchdog.sh   (exit 0 = pass)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

SRC="$(cd "$(dirname "$0")/.." && pwd)/home/igate-watchdog.sh"
FAILURES=0
HOUR=3600

check() {   # check <label> <got> <want>
    if [ "$2" = "$3" ]; then
        echo "  ok  $1"
    else
        echo "  FAIL $1: got '$2', want '$3'"
        FAILURES=$((FAILURES + 1))
    fi
}

# ── a sandbox that looks enough like an iGate ────────────────────────────────
# Real time, faked file ages: the thresholds are hours, so rather than mock the clock we
# backdate the things the script measures. UPTIME is the machine's; the direwolf start
# time the script derives is UPTIME minus whatever each case sets.
UPTIME=172800

setup() {
    SANDBOX=$(mktemp -d)
    mkdir -p "$SANDBOX/bin" "$SANDBOX/log" "$SANDBOX/tmp" "$SANDBOX/aprslogs"
    : > "$SANDBOX/calls.txt"
    echo "$UPTIME 0.00" > "$SANDBOX/uptime"

    cp "$SRC" "$SANDBOX/igate-watchdog.sh"
    sed -i.bak \
        -e "s|^PATH=.*|PATH=\"$SANDBOX/bin:/usr/bin:/bin:/usr/sbin:/sbin\"|" \
        -e "s|/var/log/direwolf|$SANDBOX/log|g" \
        -e "s|/home/pi/aprslogs|$SANDBOX/aprslogs|g" \
        -e "s|/tmp/|$SANDBOX/tmp/|g" \
        -e "s|/proc/uptime|$SANDBOX/uptime|g" \
        -e 's|^MIN=.*|MIN=${MIN:-00}|' \
        "$SANDBOX/igate-watchdog.sh"

    # systemctl: reports direwolf active and answers the monotonic start-time query;
    # everything else is recorded so a restart can be counted.
    cat > "$SANDBOX/bin/systemctl" <<STUB
#!/usr/bin/env bash
[ "\$1 \$2" = "is-active --quiet" ] && exit \${DW_ACTIVE:-0}
[ "\$1" = "show" ] && { echo "\${DW_MONO:-0}"; exit 0; }
echo "\$*" >> "$SANDBOX/calls.txt"
exit 0
STUB

    # stat -c is GNU; these tests run on a Mac.
    cat > "$SANDBOX/bin/stat" <<'STUB'
#!/usr/bin/env bash
if [ "$1" = "-c" ] && [ "$2" = "%Y" ]; then
    if [ "$(uname)" = "Darwin" ]; then exec /usr/bin/stat -f %m "$3"; fi
    exec /usr/bin/stat -c %Y "$3"
fi
exec /usr/bin/stat "$@"
STUB

    printf '#!/usr/bin/env bash\nexec "$@"\n'                                  > "$SANDBOX/bin/sudo"
    printf '#!/usr/bin/env bash\necho "Bus 001 Device 004: ID 0bda:2838 Realtek RTL2838UHIDIR"\n' \
                                                                               > "$SANDBOX/bin/lsusb"
    printf '#!/usr/bin/env bash\necho "23: op -- pd | lo // GPIO23"\n'          > "$SANDBOX/bin/pinctrl"
    printf '#!/usr/bin/env bash\nexit 1\n'                                     > "$SANDBOX/bin/pgrep"
    printf '#!/usr/bin/env bash\necho "192.168.1.50"\n'                        > "$SANDBOX/bin/hostname"
    printf '#!/usr/bin/env bash\nexit 1\n'                                     > "$SANDBOX/bin/netbird"
    chmod +x "$SANDBOX/bin"/*

    # Direwolf has been up since boot, less the seconds systemd takes to get there —
    # exactly 0 would mean "never started" and the script rightly refuses to judge that.
    DW_UP=$(( UPTIME - 30 ))
    DW_ACTIVE=0
}

teardown() { rm -rf "$SANDBOX"; }

now() { date +%s; }

age() { echo $(( $(now) - $1 )); }        # age <seconds ago> → epoch

set_mtime() {   # set_mtime <file> <epoch>
    touch -d "@$2" "$1" 2>/dev/null || touch -t "$(date -r "$2" +%Y%m%d%H%M.%S)" "$1"
}

# heard <seconds ago> — direwolf logged a packet that long ago
heard() {
    echo "chan,utime,isotime,source" > "$SANDBOX/aprslogs/2026-08-14.log"
    set_mtime "$SANDBOX/aprslogs/2026-08-14.log" "$(age "$1")"
}

# console <line count> — a console.log holding that many decoded-frame lines
console() {
    : > "$SANDBOX/log/console.log"
    local i=0
    while [ "$i" -lt "$1" ]; do
        echo "K6DRK-1 audio level = 42(15/14)   [NONE]   |||||||||" >> "$SANDBOX/log/console.log"
        i=$((i + 1))
    done
}

# tracker <count> <evidence seconds ago> <reset seconds ago>
tracker() { echo "$1 $(age "$2") $(age "$3")" > "$SANDBOX/tmp/igate-rx.state"; }

run() { MIN="${MIN:-10}" DW_ACTIVE="$DW_ACTIVE" DW_MONO=$(( (UPTIME - DW_UP) * 1000000 )) \
        bash "$SANDBOX/igate-watchdog.sh"; }

restarts() { grep -c 'restart direwolf.service' "$SANDBOX/calls.txt" | tr -d ' '; }
logged()   { grep -c "$1" "$SANDBOX/log/watchdog.log" 2>/dev/null | tr -d ' '; }

# ── a receiver that has gone deaf gets one restart ───────────────────────────
# The whole point: dongle enumerated, direwolf active, and nothing decoded for hours.
echo "silent receiver"
setup
heard $(( 7 * HOUR )); console 3; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
run
check "restarts direwolf" "$(restarts)" "1"
check "and says why" "$(logged 'Nothing decoded for 7h')" "1"
check "marker records the attempt" "$([ -f "$SANDBOX/tmp/igate-rx-restarted" ] && echo yes)" "yes"

# ── and only one, no matter how many times cron runs ─────────────────────────
# The failure that would take out the fleet. Nothing has been decoded since the restart,
# so the silence is still there to be found — and must not be acted on again.
run; run; run
check "never restarts twice" "$(restarts)" "1"
teardown

# ── a gate that is merely quiet is left alone ────────────────────────────────
echo "recent traffic"
setup
heard 600; console 3; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
run
check "does nothing" "$(restarts)" "0"
teardown

echo "direwolf started recently"
# Backdated traffic log, but direwolf has only been listening an hour — it has not been
# given long enough to hear anything, so silence proves nothing yet.
setup
DW_UP=$HOUR
heard $(( 7 * HOUR )); console 3; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
run
check "waits" "$(restarts)" "0"
teardown

echo "first run after a reboot"
# No state file at all. The console tracker cannot compare a count against nothing, so it
# starts the clock now rather than treating an old traffic log as proof of silence.
setup
heard $(( 7 * HOUR )); console 3
run
check "starts the clock instead of restarting" "$(restarts)" "0"
teardown

echo "console log truncated"
# logrotate's copytruncate empties console.log daily without restarting direwolf. The
# count drops, which is not evidence of anything, and must not read as silence.
setup
heard $(( 7 * HOUR )); console 2; tracker 100 $(( 7 * HOUR )) $(( 25 * HOUR ))
run
check "resets rather than restarts" "$(restarts)" "0"
check "and re-seeds the count" "$(cut -d' ' -f1 < "$SANDBOX/tmp/igate-rx.state")" "2"
teardown

echo "console log shows decodes the traffic log has not flushed"
# The traffic log's mtime can lag on a quiet gate whose stdio buffer has not filled.
# A rising console count is a decode, and outranks the stale mtime.
setup
heard $(( 7 * HOUR )); console 5; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
run
check "believes the console log" "$(restarts)" "0"
teardown

echo "a diagnostic owns the SDR"
setup
heard $(( 7 * HOUR )); console 3; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
: > "$SANDBOX/tmp/sdr-usb-test.pause"
run
check "does not fight sdr-usb-test" "$(restarts)" "0"
teardown

echo "no LOGDIR configured"
# Without direwolf's traffic log there is nothing solid to measure, so it declines to guess.
setup
console 3; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
rm -rf "$SANDBOX/aprslogs"
run
check "abstains" "$(restarts)" "0"
teardown

echo "checked every ten minutes, not every minute"
setup
heard $(( 7 * HOUR )); console 3; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
MIN=13 run
check "skips the other nine" "$(restarts)" "0"
teardown

# ── after the restart: report once, then stop ────────────────────────────────
echo "restart did not help"
setup
heard $(( 7 * HOUR )); console 3; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
DW_UP=$(( 4 * HOUR ))
echo "$(age $(( 4 * HOUR )))" > "$SANDBOX/tmp/igate-rx-restarted"
run
check "says the receiver is dead" "$(logged 'RECEIVER DEAD')" "1"
check "names the fix" "$(logged 'power cycle or a replug')" "1"
check "and does not restart again" "$(restarts)" "0"
run; run
check "says it once, not every ten minutes" "$(logged 'RECEIVER DEAD')" "1"
teardown

echo "restart worked"
setup
heard 600; console 3; tracker 3 $(( 7 * HOUR )) $(( 25 * HOUR ))
DW_UP=$(( 4 * HOUR ))
echo "$(age $(( 4 * HOUR )))" > "$SANDBOX/tmp/igate-rx-restarted"
: > "$SANDBOX/tmp/igate-rx-dead"
run
check "logs the recovery" "$(logged 'receiver recovered')" "1"
check "clears the restart marker" "$([ -f "$SANDBOX/tmp/igate-rx-restarted" ] && echo yes || echo no)" "no"
check "clears the dead marker" "$([ -f "$SANDBOX/tmp/igate-rx-dead" ] && echo yes || echo no)" "no"
check "restarts nothing" "$(restarts)" "0"
teardown

echo ""
if [ "$FAILURES" -eq 0 ]; then
    echo "all passed"
    exit 0
fi
echo "$FAILURES failed"
exit 1

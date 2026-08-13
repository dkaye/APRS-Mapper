#!/usr/bin/env bash
# Tests for auto-update.sh's self-replacement.
#
# This is the part that runs unattended at 4:11am on a device nobody wants to drive to,
# and its two failure modes are both silent: hand over to the wrong copy and the change
# never lands, hand over in a loop and the device spends the night re-executing itself.
#
# Runs entirely on the local machine against a fake server directory — no Pi, no network.
# The script under test is copied into a sandbox and pointed at file:// URLs.
#
# Usage: transcriber/tests/test_auto_update.sh   (exit 0 = pass)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

SRC="$(cd "$(dirname "$0")/.." && pwd)"
FAILURES=0

check() {   # check <label> <got> <want>
    if [ "$2" = "$3" ]; then
        echo "  ok  $1"
    else
        echo "  FAIL $1: got '$2', want '$3'"
        FAILURES=$((FAILURES + 1))
    fi
}

# ── a sandbox that looks enough like a Transcriber ───────────────────────────
# The real script installs into /opt, /etc/systemd and /home/pi and drives systemctl.
# Rather than mock a Pi, we cut the script down to the part under test — everything up to
# and including the re-exec — and let the rest go. That keeps the test about hand-over
# and means it does not need root.
setup() {
    SANDBOX=$(mktemp -d)
    mkdir -p "$SANDBOX/server/home" "$SANDBOX/pi" "$SANDBOX/log"

    # Cut at the marker: keep the download, the extract, and the re-exec block. The cut
    # lands inside the "full update only" conditional, so close it — see TEST-CUT in
    # auto-update.sh. A mismatch here is a bash syntax error, which is loud.
    sed -n '1,/^# TEST-CUT/p' "$SRC/auto-update.sh" > "$SANDBOX/pi/auto-update.sh"
    echo 'fi' >> "$SANDBOX/pi/auto-update.sh"
    # Point it at the fake server and a writable log, and record each run.
    sed -i.bak \
        -e "s|^BASE=.*|BASE=\"file://$SANDBOX/server\"|" \
        -e "s|/var/log/transcriber|$SANDBOX/log|g" \
        -e "s|-o pi -g pi ||" \
        "$SANDBOX/pi/auto-update.sh"
    # Log on ENTRY, not at the end: the first process execs before it reaches the end, so
    # a marker there would count hand-overs as though they had never happened.
    #
    # awk, not sed. BSD sed turns \n in a replacement into a literal "n" instead of a
    # newline, and does it silently — the first version of this test built a fixture with
    # two statements welded into one line, then reported a pass on the wreckage.
    awk -v out="$SANDBOX/runs.txt" '
        {print}
        /^mkdir -p .*log$/ && !done {
            print "echo \"RAN version=${VERSION_MARKER:-base} reexec=${TRANSCRIBER_REEXEC:-no}\" >> " out
            done = 1
        }' "$SANDBOX/pi/auto-update.sh" > "$SANDBOX/pi/marked.sh"
    mv "$SANDBOX/pi/marked.sh" "$SANDBOX/pi/auto-update.sh"
    chmod +x "$SANDBOX/pi/auto-update.sh"
    : > "$SANDBOX/runs.txt"
}

teardown() { rm -rf "$SANDBOX"; }

# Build files.tar.gz whose home/auto-update.sh is the sandbox script stamped `$1`.
publish() {
    local version="$1" stage="$SANDBOX/stage"
    rm -rf "$stage"; mkdir -p "$stage/home" "$stage/bin" "$stage/systemd"
    awk -v v="$version" '
        /^SELF=/ && !done { print "VERSION_MARKER=" v; done = 1 }
        {print}' "$SANDBOX/pi/auto-update.sh" > "$stage/home/auto-update.sh"
    chmod +x "$stage/home/auto-update.sh"
    tar -czf "$SANDBOX/server/files.tar.gz" -C "$stage" .
}

# ── the change lands on THIS run, not the next one ───────────────────────────
echo "self-replacement"
setup
publish "v2"
"$SANDBOX/pi/auto-update.sh" >/dev/null 2>&1
runs=$(wc -l < "$SANDBOX/runs.txt" | tr -d ' ')
check "two processes: the old one and the new" "$runs" "2"
check "the first is the copy that was installed" \
      "$(sed -n '1p' "$SANDBOX/runs.txt")" "RAN version=base reexec=no"
check "the second is the version just published" \
      "$(sed -n '2p' "$SANDBOX/runs.txt")" "RAN version=v2 reexec=1"
check "and it is now what is installed" \
      "$(grep -c '^VERSION_MARKER=v2' "$SANDBOX/pi/auto-update.sh")" "1"
teardown

# ── an unchanged updater must not hand over at all ───────────────────────────
# Otherwise every nightly run would execute twice, which is the kind of waste that
# hides in a log nobody reads.
echo "no change"
setup
# Publish the sandbox script unchanged, so the archived copy already matches.
mkdir -p "$SANDBOX/stage/home"
cp "$SANDBOX/pi/auto-update.sh" "$SANDBOX/stage/home/auto-update.sh"
tar -czf "$SANDBOX/server/files.tar.gz" -C "$SANDBOX/stage" .
"$SANDBOX/pi/auto-update.sh" >/dev/null 2>&1
check "runs once" "$(wc -l < "$SANDBOX/runs.txt" | tr -d ' ')" "1"
check "and does not claim to have handed over" \
      "$(grep -c 'reexec=1' "$SANDBOX/runs.txt")" "0"
teardown

# ── the guard bounds it to one hand-over ─────────────────────────────────────
# The comparison is against file contents, so a script that never quite matches what was
# published — a stamped build date, a line rewritten on install — would re-exec forever
# without this. Unattended, at four in the morning.
echo "loop guard"
setup
publish "v2"
# The guard's job is to stop the SECOND hand-over. Enter as though one has already
# happened, with an archive that still differs, and nothing further may occur — otherwise
# any script that never quite matches what was published (a stamped build date, a line
# rewritten on install) would re-exec until morning.
TRANSCRIBER_REEXEC=1 timeout 30 "$SANDBOX/pi/auto-update.sh" >/dev/null 2>&1
rc=$?
check "terminates rather than looping" "$([ $rc -ne 124 ] && echo yes || echo no)" "yes"
check "runs exactly once" "$(wc -l < "$SANDBOX/runs.txt" | tr -d ' ')" "1"
check "and does not hand over again" \
      "$(grep -c '^VERSION_MARKER=v2' "$SANDBOX/pi/auto-update.sh")" "0"
teardown

# ── a failed download must leave the device alone ────────────────────────────
# The one behaviour that must never regress: a receiver on a marginal link keeps
# running what it has rather than being taken off the air by a broken update.
echo "server unreachable"
setup
rm -f "$SANDBOX/server/files.tar.gz"
"$SANDBOX/pi/auto-update.sh" >/dev/null 2>&1
check "exits 0" "$?" "0"
# It started, found nothing to fetch, and stopped — one entry, no hand-over, and the
# installed updater untouched. Exit 0 matters as much as the rest: a non-zero status
# from cron every night is an alert nobody reads by the second week.
check "starts and stops once" "$(wc -l < "$SANDBOX/runs.txt" | tr -d ' ')" "1"
check "hands over to nothing" "$(grep -c 'reexec=1' "$SANDBOX/runs.txt")" "0"
check "leaves the installed updater alone" \
      "$(grep -c '^VERSION_MARKER=' "$SANDBOX/pi/auto-update.sh")" "0"
teardown

# ── reconciling units against the channel list ───────────────────────────────
# The second half of the script: enable exactly what the manager says, stop what it no
# longer says. Driven here by a stub systemctl that records what it was asked to do.

recon_setup() {
    SANDBOX=$(mktemp -d)
    mkdir -p "$SANDBOX/bin" "$SANDBOX/wants" "$SANDBOX/etc"

    cat > "$SANDBOX/bin/systemctl" <<STUB
#!/usr/bin/env bash
# Records every call. \`list-units\` replies with whatever RUNNING holds.
if [ "\$1" = "list-units" ]; then
    for u in \$RUNNING; do
        echo "transcriber@\$u.service loaded active running Transcriber channel \$u"
    done
    exit 0
fi
echo "\$*" >> "$SANDBOX/calls.txt"
exit 0
STUB
    chmod +x "$SANDBOX/bin/systemctl"
    : > "$SANDBOX/calls.txt"

    # Just the reconciliation, with the surrounding script's variables supplied.
    { echo 'CONFIG="'"$SANDBOX"'/etc/channels.json"'
      echo 'WANTS_DIR="'"$SANDBOX"'/wants"'
      echo 'log() { :; }'
      sed -n '/^# ── restart what is configured/,$p' "$SRC/auto-update.sh"
    } > "$SANDBOX/recon.sh"
}

# recon <json channel list> <running instances> <enabled instances>
recon() {
    echo "$1" > "$SANDBOX/etc/channels.json"
    rm -f "$SANDBOX/wants"/*
    for u in $3; do : > "$SANDBOX/wants/transcriber@$u.service"; done
    : > "$SANDBOX/calls.txt"
    RUNNING="$2" PATH="$SANDBOX/bin:$PATH" bash "$SANDBOX/recon.sh" >/dev/null 2>&1
}

echo "reconciliation — a stopped-but-enabled channel"
recon_setup
# The regression. The old unit is NOT running (configure.sh stopped it to write a dongle
# serial) but is still enabled, so the next boot would start it — and its id is gone from
# the config, so it would fail every ten seconds until somebody noticed.
recon '{"channels":[{"id":"Transcriber-147465","enabled":true}]}' "" "transcriberClone-147465"
check "disables the stale enable" \
      "$(grep -c 'disable --now transcriber@transcriberClone-147465.service' "$SANDBOX/calls.txt")" "1"
check "and starts the channel that is configured" \
      "$(grep -c 'restart transcriber@Transcriber-147465.service' "$SANDBOX/calls.txt")" "1"

echo "reconciliation — a running channel that was removed"
recon '{"channels":[]}' "old-146700" ""
check "stops it" "$(grep -c 'disable --now transcriber@old-146700.service' "$SANDBOX/calls.txt")" "1"

echo "reconciliation — running and enabled are the same unit"
recon '{"channels":[]}' "dup-147465" "dup-147465"
check "counted once, not twice" \
      "$(grep -c 'disable --now transcriber@dup-147465.service' "$SANDBOX/calls.txt")" "1"

echo "reconciliation — nothing to do"
recon '{"channels":[{"id":"Transcriber-147465","enabled":true}]}' "Transcriber-147465" "Transcriber-147465"
check "does not disable the channel it should be running" \
      "$(grep -c 'disable' "$SANDBOX/calls.txt")" "0"

echo "reconciliation — the 60-second poll leaves a healthy channel alone"
# The invariant the poller lives or dies by. Restarting on a schedule would re-measure the
# squelch and lose whatever was being said, sixty times an hour, forever — a receiver that
# is never quite listening.
recon_setup
echo '{"channels":[{"id":"Transcriber-147465","enabled":true}]}' > "$SANDBOX/etc/channels.json"
: > "$SANDBOX/wants/transcriber@Transcriber-147465.service"
{ echo 'CONFIG="'"$SANDBOX"'/etc/channels.json"'
  echo 'WANTS_DIR="'"$SANDBOX"'/wants"'
  echo 'CHANNELS_ONLY=1'          # a poll, not a full update
  echo 'CHANNELS_CHANGED=""'      # and nothing changed
  echo 'log() { :; }'
  sed -n '/^# ── restart what is configured/,$p' "$SRC/auto-update.sh"
} > "$SANDBOX/recon.sh"
: > "$SANDBOX/calls.txt"
RUNNING="Transcriber-147465" PATH="$SANDBOX/bin:$PATH" bash "$SANDBOX/recon.sh" >/dev/null 2>&1
check "does not restart it" "$(grep -c 'restart transcriber@' "$SANDBOX/calls.txt")" "0"
check "start is a no-op that keeps it enabled" \
      "$(grep -c 'start transcriber@Transcriber-147465.service' "$SANDBOX/calls.txt")" "1"

# But a poll that DID find a change must restart, or the new setting never takes effect —
# which is the entire reason the poller exists.
: > "$SANDBOX/calls.txt"
sed -i.bak 's/^CHANNELS_CHANGED=""/CHANNELS_CHANGED=1/' "$SANDBOX/recon.sh"
RUNNING="Transcriber-147465" PATH="$SANDBOX/bin:$PATH" bash "$SANDBOX/recon.sh" >/dev/null 2>&1
check "a changed channel list does restart it" \
      "$(grep -c 'restart transcriber@Transcriber-147465.service' "$SANDBOX/calls.txt")" "1"
teardown

echo "reconciliation — no channels assigned"
# The state a device lands in after being renamed: the token still works, so the fetch
# succeeds and reports nothing wrong, while the receiver sits there deaf. The log has to
# name the cause or the next person spends the evening on it, as we did.
recon_setup
TOKEN_FILE=$(mktemp); echo tok > "$TOKEN_FILE"
echo '{"channels":[]}' > "$SANDBOX/etc/channels.json"
rm -f "$SANDBOX/wants"/*
{ echo 'CONFIG="'"$SANDBOX"'/etc/channels.json"'
  echo 'WANTS_DIR="'"$SANDBOX"'/wants"'
  echo 'TOKEN_FILE="'"$TOKEN_FILE"'"'
  echo 'log() { echo "$*" >> "'"$SANDBOX"'/log.txt"; }'
  sed -n '/^# ── restart what is configured/,$p' "$SRC/auto-update.sh"
} > "$SANDBOX/recon.sh"
: > "$SANDBOX/log.txt"
PATH="$SANDBOX/bin:$PATH" bash "$SANDBOX/recon.sh" >/dev/null 2>&1
check "says which manager column to fix" \
      "$(grep -c 'Receiver' "$SANDBOX/log.txt")" "1"
rm -f "$TOKEN_FILE"
teardown

echo "reconciliation — a channel switched off in the manager"
recon_setup
# enabled:false means stop it, not leave it running. It is how the manager takes a
# receiver off the air without deleting it.
recon '{"channels":[{"id":"Transcriber-147465","enabled":false}]}' "Transcriber-147465" "Transcriber-147465"
check "stops a disabled channel" \
      "$(grep -c 'disable --now transcriber@Transcriber-147465.service' "$SANDBOX/calls.txt")" "1"
teardown

echo ""
if [ "$FAILURES" -eq 0 ]; then
    echo "all passed"
    exit 0
fi
echo "$FAILURES failed"
exit 1

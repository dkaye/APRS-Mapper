#!/usr/bin/env bash
# Transcriber nightly update — v1.0.
#
# Downloads files.tar.gz and this device's configuration — its channels, and the event
# vocabulary read off the assignment sheet — from marsaprs.org, and applies both.
# Run daily via cron. Safe to run manually at any time.
#
# Does NOT touch /opt/transcriber/models — the whisper models are large, rarely
# change, and are installed once by install.sh.
#
# Replaces itself and re-execs when a newer copy is published, so a change to this file
# takes effect on the run that fetched it rather than the one after. See the re-exec
# block below for why that is not a two-stage loader.
#
# Usage: sudo /home/pi/auto-update.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

BASE="https://marsaprs.org/transcriber"
CONFIG="/etc/transcriber/channels.json"
TOKEN_FILE="/home/pi/.transcriber-token"
# Where systemd records an enabled unit. Overridable only so the tests can point it at a
# sandbox; nothing in the field should ever set it.
WANTS_DIR="${WANTS_DIR:-/etc/systemd/system/multi-user.target.wants}"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# An absolute path to this script, for the self-replacement below. $0 alone is whatever
# the caller typed: `bash auto-update.sh` leaves it relative, and `exec` would then search
# PATH and fail to find it. cron gives an absolute path, a person may not.
SELF=$(cd "$(dirname "$0")" && pwd)/$(basename "$0")

# --channels-only is the fast path a timer runs every half minute: fetch this device's
# channels and act on them, skipping the archive, the self-replacement and the power
# check. It exists so that a change made in the manager reaches the receiver by itself,
# rather than waiting for 04:11 or for somebody to SSH in and say so.
CHANNELS_ONLY=""
[ "${1:-}" = "--channels-only" ] && CHANNELS_ONLY=1

# The quiet path logs only when something actually changed. Forty-eight polls an hour,
# every hour, would otherwise bury every real line in the update log.
LAST_UPDATE_SEEN=/etc/transcriber/last-update-request


log() {
    if [ -n "$CHANNELS_ONLY" ] && [ -z "${FORCE_LOG:-}" ]; then
        echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> /var/log/transcriber/update.log
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') $*" | tee -a /var/log/transcriber/update.log
    fi
}

mkdir -p /var/log/transcriber

# ── files ────────────────────────────────────────────────────────────────────

if [ -z "$CHANNELS_ONLY" ]; then

log "Downloading files.tar.gz"
if ! curl -fsS --max-time 120 -o "$TMP/files.tar.gz" "$BASE/files.tar.gz?t=$(date +%s)"; then
    log "download failed; keeping what is installed"
    exit 0        # a failed update must never take a working receiver off the air
fi

tar -xzf "$TMP/files.tar.gz" -C "$TMP"

# ── replace ourselves first, then hand over ──────────────────────────────────
# Before anything else is touched, so that everything below runs from the version that
# was just published rather than from whatever this device happened to install months
# ago. Without this, a change to THIS file took effect only on the following run: the
# copy already executing finished the run it was in, and the new one waited for the next
# one. That is invisible and it lies — twice in one afternoon a deploy looked like it had
# silently done nothing, when it had worked and simply would not take effect until later.
#
# exec, not a second download of a stage-2 script. A thin loader that fetches its own
# logic every run would get the same immediacy at the cost of making every nightly run
# depend on the network for its code and not just its content — and a device on a
# marginal link must degrade to "keep running what is installed", which is the one
# behaviour that must never regress.
#
# The guard variable bounds it to a single hand-over: if the comparison were ever wrong
# this would otherwise re-exec forever, at four in the morning, unattended.
if [ -z "${TRANSCRIBER_REEXEC:-}" ] && [ -f "$TMP/home/auto-update.sh" ] \
   && ! cmp -s "$TMP/home/auto-update.sh" "$SELF"; then
    log "updater changed; installing it and re-running from the new one"
    # Non-fatal on purpose. Under `set -e` a failure here — an unwritable path, a full
    # card — would abort the whole run, and refusing to update a receiver because the
    # updater could not update itself is the wrong way round.
    if install -m 755 -o pi -g pi "$TMP/home/auto-update.sh" "$SELF"; then
        rm -rf "$TMP"      # exec replaces this process, so the EXIT trap never fires
        TRANSCRIBER_REEXEC=1 exec "$SELF" "$@"
    fi
    log "could not replace $SELF; carrying on with the version already installed"
fi
# TEST-CUT — tests/test_auto_update.sh truncates the script here to exercise the
# hand-over without needing a Pi. It appends the "fi" that closes the CHANNELS_ONLY
# guard opened above; if this marker moves, move that too.

# --ignore-times, not the default size+mtime comparison: tar restores the archive's
# timestamps, so a file whose size did not change looks unchanged to rsync and is
# silently skipped. This has bitten this project before.
rsync -a --ignore-times "$TMP/bin/"     /opt/transcriber/bin/
rsync -a --ignore-times "$TMP/systemd/" /etc/systemd/system/
chmod +x /opt/transcriber/bin/*.py
# rsync carries the mode across, but a repository checkout that
# lost the bit would produce a worker that fails with "permission denied" on the
# device and nowhere else.
chmod +x /opt/transcriber/bin/*.sh

# ── files this device is no longer supposed to have ──────────────────────────
# rsync only adds and overwrites; nothing here ever deletes, so a file that stops being
# shipped stays on the device forever. That is normally harmless clutter. sdr-selftest.sh
# was not: this updater ran it on every nightly pass, and its first act is to stop every
# transcriber@* unit so it can have the dongle to itself. On a Transcriber there is no
# dongle any more — the audio comes from a sound card — so each night it stopped the
# receiver to measure hardware that is not there. Measured on 2026-08-29, the outage was
# about two seconds: with no dongle the sweep fails immediately and the trap puts the
# channel back. The bound is 400 s, and that is what it would have cost had the sweep
# ever hung. What it reliably produced was "analysis failed" in the log every night,
# which is worse than nothing — a permanent failure line is where a real one goes to
# hide. Deleting the script is what actually stops it, because the block that called it
# was guarded on the file being present.
#
# Deliberately not a general "remove anything not in the archive": the Transcribers keep
# hand-placed files, and a sweeping delete at 4am is a worse failure than the clutter.
# One explicit list, each entry with a reason.
for retired in \
    /home/pi/sdr-selftest.sh \
    /home/pi/sdr-selftest.py \
    /opt/transcriber/bin/calibrate.sh \
    /etc/systemd/system/transcriber-calibrate@.service
do
    [ -e "$retired" ] || continue
    rm -f "$retired" && log "removed $retired (retired with the SDR)"
done

# ── packages the new code needs ──────────────────────────────────────────────
# This script carries new CODE to a device that already exists, and new code can want a
# package the device was never given. install.sh gained ffmpeg when channels learned to
# send their audio; nothing installed it on the receivers already in the field, so those
# would have taken the new worker, offered the Audio setting in the manager, and then
# quietly logged text only — one warning line in a journal nobody reads, and a feature
# that looks switched on and is not.
#
# Only what is actually missing, and never a general upgrade: an unattended 4am apt
# that decides to replace the kernel on a receiver is a far worse failure than the one
# being fixed here. A device with no network simply keeps what it has.
for pkg in ffmpeg; do
    command -v "$pkg" >/dev/null 2>&1 && continue
    log "$pkg is missing; installing it"
    if DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$pkg" \
           >/dev/null 2>&1; then
        log "  installed $pkg"
    else
        log "  could not install $pkg — channels will run without it"
    fi
done

# configure.sh, kept current so a device that has been in the field for a year still has
# today's wizard on it when somebody finally SSHes in to move it. Everything it writes
# lives outside itself — hostname, token, dongle serials — so replacing it is safe.
#
# auto-update.sh is in here too, but by now it is already identical: either it matched to
# begin with, or the block above installed it and this is the new copy running.
if [ -d "$TMP/home" ]; then
    rsync -a --ignore-times "$TMP/home/" /home/pi/
    chmod +x /home/pi/*.sh
    chown pi:pi /home/pi/*.sh
fi

# Stamp the running version somewhere greppable, the way an iGate stamps
# IGATE_VERSION into its dashboard. Read from the worker itself so there is one source
# of truth and this cannot drift from what is actually installed.
#
# mkdir first: with set -e, the redirect below would abort the whole update on a device
# where install.sh has not run yet, which is precisely the device you would be running
# this on by hand to fix.
mkdir -p /etc/transcriber
/opt/transcriber/bin/transcriber.py --version 2>/dev/null \
    | awk '{print $2}' > /etc/transcriber/version || true
log "running version $(cat /etc/transcriber/version 2>/dev/null || echo unknown)"

fi   # end of the full-update section, skipped by --channels-only

# ── channels ─────────────────────────────────────────────────────────────────
# Written by the channel manager on the server; this device fetches only its own.

CHANNELS_CHANGED=""
if [ -f "$TOKEN_FILE" ]; then
    HOST=$(hostname)
    if curl -fsS --max-time 30 -o "$TMP/response.json" \
            "$BASE/get.php?token=$(cat "$TOKEN_FILE")&device=$HOST&t=$(date +%s)"; then
        # Validate before installing. A truncated or error-page response would
        # otherwise stop every channel on this device at the next restart.
        #
        # The channels and the event vocabulary are written to $CONFIG; the "update
        # requested" stamp the response also carries is deliberately left out. Put that in
        # the same file and every press of the button looks like a changed channel list
        # and restarts every receiver on the device for no reason.
        #
        # The vocabulary — the callsigns and tactical calls off the event's assignment
        # sheet, the place names stated in its Vocabulary section, and the corrections
        # somebody typed after hearing one go wrong — belongs in here precisely because a
        # change to it SHOULD restart the channels: the worker builds its whisper prompt
        # and its lookup tables from it once, at startup, so a vocabulary it never reloads
        # is a vocabulary it never uses. Unlike the update stamp, it only changes when the
        # document or the manager's box does.
        #
        # sort_keys, and defaults for a key an older server does not send, so the file is
        # byte-identical run to run and `cmp -s` below stays the change detector. A device
        # that stopped being able to tell "unchanged" from "changed" would either restart
        # its receivers every minute or never pick anything up. The defaults are also what
        # makes a mixed-version day ordinary: the archive lands on the nightly run and the
        # server is deployed separately, so a device runs new code against an old server
        # for a while and must simply see empty lists.
        if python3 - "$TMP/response.json" "$TMP/channels.json" <<'PYEOF' 2>/dev/null
import json, sys
r = json.load(open(sys.argv[1]))
vocab = r.get("vocabulary") or {}
json.dump({"channels": r["channels"],
           "vocabulary": {"callsigns":   vocab.get("callsigns")   or [],
                          "tactical":    vocab.get("tactical")    or [],
                          "terms":       vocab.get("terms")       or [],
                          "corrections": vocab.get("corrections") or {}}},
          open(sys.argv[2], "w"), indent=4, sort_keys=True)
PYEOF
        then
            mkdir -p "$(dirname "$CONFIG")"
            if ! cmp -s "$TMP/channels.json" "$CONFIG"; then
                install -m 640 -o root -g pi "$TMP/channels.json" "$CONFIG"
                FORCE_LOG=1 log "configuration updated (channels or vocabulary)"
                CHANNELS_CHANGED=1
            fi
        else
            log "channel list from server was not valid JSON; keeping the current one"
        fi

        # "Update devices now", pressed in the manager. The server hands out a timestamp
        # and each device remembers the last one it acted on, so nothing has to be
        # written back and a device that was switched off catches up when it returns.
        REQ=$(python3 -c "import json,sys; print(int(json.load(open(sys.argv[1])).get('update_requested') or 0))" \
              "$TMP/response.json" 2>/dev/null || echo 0)
        SEEN=$(cat "$LAST_UPDATE_SEEN" 2>/dev/null || echo 0)
        if [ "$REQ" -gt "$SEEN" ] 2>/dev/null; then
            mkdir -p "$(dirname "$LAST_UPDATE_SEEN")"
            echo "$REQ" > "$LAST_UPDATE_SEEN"
            if [ -n "$CHANNELS_ONLY" ]; then
                FORCE_LOG=1 log "full update requested from the manager"
                # Hand over to a complete run — software as well as configuration — and
                # let it finish the job rather than doing half of it here.
                exec "$SELF"
            fi
        fi

    else
        log "channel list download failed; keeping the current one"
    fi
else
    log "no $TOKEN_FILE; skipping channel list (set one to manage this device centrally)"
fi

# ── restart what is configured ───────────────────────────────────────────────

systemctl daemon-reload

# The health responder answers the NetBird monitor's UDP poll, which is the whole of
# what makes a device appear at /netbird/admin.php. Enabled here rather than only in
# install.sh so devices installed before it existed pick it up on the next update.
systemctl enable --now stats-listener.service >/dev/null 2>&1 || true

# The 60-second config poll. Enabled here as well as in install.sh so a device deployed
# before this existed starts collecting settings on its own after one nightly run.
# Never from the poller itself — restarting the timer that is running you is a good way
# to have it not run again.
if [ -z "$CHANNELS_ONLY" ]; then
    systemctl enable --now transcriber-config.timer >/dev/null 2>&1 || true
fi

# Enable exactly the channels in the config and stop any that were removed, so a
# channel deleted in the manager actually stops rather than lingering until reboot.
WANT=$(python3 -c "
import json
print(' '.join(c['id'] for c in json.load(open('$CONFIG'))['channels'] if c.get('enabled', True)))
" 2>/dev/null || echo "")

# Running instances AND enabled ones, which are not the same set. A channel that is
# stopped but still enabled is invisible to `list-units`, so it survived this cleanup and
# then came back at the next boot — where, its id having been removed from the config, it
# failed and was restarted every ten seconds forever.
#
# Not hypothetical: configure.sh stops the channels before writing a dongle serial, so
# renaming a device and setting a serial in the same sitting produced exactly this. The
# unit for the old name outlived the rename, and the reboot at the end of the wizard is
# what started it failing.
#
# Enabled template instances do not appear in `list-unit-files` either — the enable is a
# symlink in the target's .wants directory, so that is what has to be read.
HAVE=$( { systemctl list-units --plain --no-legend 'transcriber@*.service' 2>/dev/null \
            | awk '{print $1}'
          ls "$WANTS_DIR" 2>/dev/null | grep '^transcriber@' || true
        } | sed 's/transcriber@\(.*\)\.service/\1/' | sort -u)

for id in $HAVE; do
    case " $WANT " in
        *" $id "*) ;;
        *) log "stopping removed channel $id"
           systemctl disable --now "transcriber@$id.service" || true ;;
    esac
done

# A bench tool may be holding the dongle on purpose — compare-models.py stops a channel
# so it can listen with both models. Starting it again underneath would leave the two
# fighting over one dongle for however long the test runs. The flag is ignored once it is
# older than eight hours, so a tool that died without cleaning up cannot keep a receiver
# off the air indefinitely.
BENCH=/tmp/transcriber-bench.pause
if [ -f "$BENCH" ] && [ -z "$(find "$BENCH" -mmin +480 2>/dev/null)" ]; then
    FORCE_LOG=1 log "a bench test is holding the dongle ($(cat "$BENCH" 2>/dev/null)); leaving channels alone"
    exit 0
fi

# Restart only when there is a reason to. A poller running every minute must leave a
# working receiver alone; restarting it on a schedule would mean re-measuring the squelch
# and missing whatever was said during the gap, sixty times an hour, forever.
#
# A full update is always a reason, because it may have just replaced the worker itself.
RESTART=""
[ -n "$CHANNELS_CHANGED" ] && RESTART=1
[ -z "$CHANNELS_ONLY" ] && RESTART=1

for id in $WANT; do
    systemctl enable "transcriber@$id.service" >/dev/null 2>&1 || true
    if [ -n "$RESTART" ]; then
        systemctl restart "transcriber@$id.service" || log "channel $id failed to start"
    else
        systemctl start "transcriber@$id.service" 2>/dev/null || true   # no-op if running
    fi
done

# "No channels" after a successful fetch is a specific situation, not a vague one: the
# token was accepted, so this device exists in the registry — it simply has nothing
# assigned to it. Renaming a device is how that happens, because the channels store the
# device name as a string and do not follow the rename. Say so, rather than leaving the
# reader to work out why a receiver that reports success is deaf.
if [ -z "$WANT" ] && [ -f "$TOKEN_FILE" ]; then
    log "the manager lists no channels for '$(hostname)' — if this device was renamed," \
        "re-pick the Receiver on each channel row at marsaprs.org/transcriber/"
fi

# Power check. Costs nothing — it reads two counters and a device-tree node, frees
# nothing and stops nothing — and it catches the fault that otherwise presents as
# whatever else was happening at the time: a USB drive that "does not work", a receiver
# that "goes deaf", a display that "reboots at random". Skipped on a channels-only poll,
# which runs every minute and is not the place for it.
if [ -z "$CHANNELS_ONLY" ] && [ -x /home/pi/power-check.sh ]; then
    /home/pi/power-check.sh 2>&1 | grep -aiE '^power:' \
        | while read -r l; do log "$l"; done || log "power check skipped (non-fatal)"
fi

if [ -z "$CHANNELS_ONLY" ] || [ -n "$CHANNELS_CHANGED" ]; then
    FORCE_LOG=1 log "update complete: ${WANT:-no channels configured}"
fi

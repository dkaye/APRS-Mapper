#!/usr/bin/env bash
# Transcriber nightly update — v1.0.
#
# Downloads files.tar.gz and the channel list from marsaprs.org and applies both.
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

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" | tee -a /var/log/transcriber/update.log; }

mkdir -p /var/log/transcriber

# ── files ────────────────────────────────────────────────────────────────────

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

# --ignore-times, not the default size+mtime comparison: tar restores the archive's
# timestamps, so a file whose size did not change looks unchanged to rsync and is
# silently skipped. This has bitten this project before.
rsync -a --ignore-times "$TMP/bin/"     /opt/transcriber/bin/
rsync -a --ignore-times "$TMP/systemd/" /etc/systemd/system/
[ -d "$TMP/udev" ] && rsync -a --ignore-times "$TMP/udev/" /etc/udev/rules.d/ || true
chmod +x /opt/transcriber/bin/*.py

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

# ── channels ─────────────────────────────────────────────────────────────────
# Written by the channel manager on the server; this device fetches only its own.

if [ -f "$TOKEN_FILE" ]; then
    HOST=$(hostname)
    if curl -fsS --max-time 30 -o "$TMP/channels.json" \
            "$BASE/get.php?token=$(cat "$TOKEN_FILE")&device=$HOST&t=$(date +%s)"; then
        # Validate before installing. A truncated or error-page response would
        # otherwise stop every channel on this device at the next restart.
        if python3 -c "import json,sys; json.load(open(sys.argv[1]))['channels']" \
                   "$TMP/channels.json" 2>/dev/null; then
            mkdir -p "$(dirname "$CONFIG")"
            if ! cmp -s "$TMP/channels.json" "$CONFIG"; then
                install -m 640 -o root -g pi "$TMP/channels.json" "$CONFIG"
                log "channel list updated"
                CHANNELS_CHANGED=1
            fi
        else
            log "channel list from server was not valid JSON; keeping the current one"
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

for id in $WANT; do
    systemctl enable "transcriber@$id.service" >/dev/null 2>&1 || true
    systemctl restart "transcriber@$id.service" || log "channel $id failed to start"
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

# ── SDR self-noise test ──────────────────────────────────────────────────────
# Measures the internal-birdie level near each channel's own frequency — the thing that
# quietly deafens a receiver without ever looking like a fault. Frees each dongle for
# about a minute and puts the channels back afterwards, so it runs last, after everything
# that could leave the device in a worse state has already succeeded. Non-fatal and
# time-bounded: a receiver must never be off the air because a measurement hung.
if [ -x /home/pi/sdr-selftest.sh ]; then
    log "Running SDR self-noise test..."
    timeout -k 15 400 /home/pi/sdr-selftest.sh 2>&1 | grep -aiE 'selftest:' \
        | while read -r l; do log "$l"; done || log "self-test skipped (non-fatal)"
fi

log "update complete: ${WANT:-no channels configured}"

#!/usr/bin/env bash
# marsaprs-backup.sh — MARS APRS Mac
#
# Nightly pull backup. This Mac reaches out to each Pi over rsync/SSH and keeps
# hardlinked snapshots under ~/aprs-backups. Replaces the retired ftp.w6sg.net
# push backup, which failed silently for eight weeks because nothing on this end
# knew it was supposed to have happened.
#
# Pull, not push, because this Mac sleeps: launchd runs a missed calendar job on
# wake, so a backup arrives late rather than never, and this Mac — the machine
# that owns the schedule — is the one that notices a Pi it could not reach.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

# ── Config ────────────────────────────────────────────────────────────────────

DEST_ROOT="${MARSAPRS_BACKUP_ROOT:-/Users/doug/aprs-backups}"
KEEP_DAYS=30

# The clip manifests are always pulled — they are the ground truth and are tiny.
# Set this to "yes" to also pull the .wav files themselves (~296 MB today, growing
# while a record_until window is open), which are needed only while a measurement
# that reads the audio is still open. Snapshots are hardlinked, so after the first
# night unchanged clips cost no additional disk.
TRANSCRIBER_INCLUDE_AUDIO="no"

RSYNC=/usr/bin/rsync
SSH=/usr/bin/ssh
SSH_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=15"

STAMP=$(date +%Y%m%d-%H%M%S)
LOG_DIR="$DEST_ROOT/logs"
LOG="$LOG_DIR/marsaprs-backup.log"
STATUS="$DEST_ROOT/STATUS.txt"

FAILED=""
TOTAL_OK=0

# ── Helpers ───────────────────────────────────────────────────────────────────

log() { echo "$(date '+%Y-%m-%d %H:%M:%S')  $*" | tee -a "$LOG"; }

notify() {
    /usr/bin/osascript -e "display notification \"$1\" with title \"MARS APRS backup\"" 2>/dev/null || true
}

# Most recent existing snapshot for a device, so rsync can hardlink against it.
prev_snapshot() {
    ls -1d "$1"/20*-* 2>/dev/null | sort | tail -1
}

# backup_device <name> <sshhost> <exclude-args...> -- <path...>
backup_device() {
    local name="$1" host="$2"; shift 2
    local excludes=() paths=() seen_sep=""
    local a
    for a in "$@"; do
        if [ "$a" = "--" ]; then seen_sep=1; continue; fi
        if [ -n "$seen_sep" ]; then paths+=("$a"); else excludes+=("$a"); fi
    done

    local devdir="$DEST_ROOT/$name"
    local dest="$devdir/$STAMP"
    local prev; prev=$(prev_snapshot "$devdir")
    local linkopt=()
    [ -n "$prev" ] && linkopt=(--link-dest="$prev")

    log "[$name] starting (host=$host)"

    if ! $SSH $SSH_OPTS "$host" true 2>>"$LOG"; then
        log "[$name] ERROR: unreachable over SSH — no backup taken"
        FAILED="$FAILED $name"
        return 1
    fi

    mkdir -p "$dest"

    local rc_any=0 p rc
    for p in "${paths[@]}"; do
        # -R keeps the full source path under the snapshot, so the tree mirrors
        # the Pi's layout relative to / — the same shape the old tarball had.
        $RSYNC -aR --no-specials --no-devices \
            "${linkopt[@]}" "${excludes[@]}" \
            -e "$SSH $SSH_OPTS" \
            "$host:$p" "$dest/" >>"$LOG" 2>&1
        rc=$?
        if [ $rc -ne 0 ]; then
            log "[$name] WARNING: rsync rc=$rc for $p"
            rc_any=1
        fi
    done

    # Capture the crontab too — it is not a file under any of the paths above,
    # and it is the first thing you need when rebuilding a Pi from bare metal.
    $SSH $SSH_OPTS "$host" 'crontab -l 2>/dev/null' > "$dest/crontab.txt" 2>/dev/null || true

    if [ $rc_any -ne 0 ]; then
        log "[$name] completed WITH WARNINGS -> $dest"
        FAILED="$FAILED $name(partial)"
    else
        log "[$name] ok -> $dest  ($(du -sh "$dest" 2>/dev/null | cut -f1))"
        TOTAL_OK=$((TOTAL_OK + 1))
    fi

    rm -f "$devdir/latest"
    ln -s "$STAMP" "$devdir/latest"

    # Prune old snapshots. Hardlinks mean deleting one frees only what was
    # unique to it, so retention is cheap.
    find "$devdir" -maxdepth 1 -type d -name '20*-*' -mtime +${KEEP_DAYS} \
        -exec rm -rf {} + 2>/dev/null || true

    return 0
}

# ── Run ───────────────────────────────────────────────────────────────────────

mkdir -p "$LOG_DIR"
log "=== marsaprs backup start ($STAMP) ==="

# --- aprs-pi: everything that changes after install and is not in the repo ---
backup_device "aprs-pi" "aprs-pi" -- \
    /var/www/html/events \
    /var/www/html/config.yaml \
    /var/www/html/netbird/addresses.yaml \
    /var/www/html/wifi/wifi.yaml \
    /var/www/html/admin/password.txt \
    /var/www/html/tickets/tickets.json \
    /var/www/html/tickets/uploads \
    /home/pi/.wifi-token

# --- Transcriber: channel config, calibration, logs ---
# /var/spool/transcriber is pulled for the small per-channel json (calibration,
# squelch) that lives beside the audio; the recordings themselves are excluded
# unless TRANSCRIBER_INCLUDE_AUDIO=yes above.
tx_excludes=()
if [ "$TRANSCRIBER_INCLUDE_AUDIO" != "yes" ]; then
    # Exclude the audio, never the manifests. manifest.jsonl is hand-labeled
    # ground truth — what whisper returned, what reached the log, why anything was
    # dropped, and what each proposed filter WOULD have dropped. It is a few tens
    # of KB and cannot be reconstructed. The .wav files can be re-recorded, and are
    # only needed while a measurement that reads the audio itself is still open
    # (level_db and zcr are computed at runtime and never written to the manifest).
    tx_excludes=(--exclude '*.wav' --exclude 'audio' --exclude 'outbox')
fi

backup_device "transcriber" "transcriber" "${tx_excludes[@]}" -- \
    /etc/transcriber \
    /var/log/transcriber \
    /var/spool/transcriber \
    /home/pi/selftest.json \
    /home/pi/selftest-history.csv \
    /home/pi/power-check.json

# ── Report ────────────────────────────────────────────────────────────────────

if [ -n "$FAILED" ]; then
    log "=== FINISHED WITH PROBLEMS:$FAILED ==="
    {
        echo "last run    : $(date '+%Y-%m-%d %H:%M:%S')"
        echo "result      : PROBLEMS —$FAILED"
        echo "devices ok  : $TOTAL_OK"
        echo "log         : $LOG"
    } > "$STATUS"
    notify "Problems:$FAILED"
    exit 1
fi

log "=== finished ok ($TOTAL_OK devices) ==="
{
    echo "last run    : $(date '+%Y-%m-%d %H:%M:%S')"
    echo "result      : OK"
    echo "devices ok  : $TOTAL_OK"
    echo "log         : $LOG"
} > "$STATUS"
exit 0

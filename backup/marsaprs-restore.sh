#!/usr/bin/env bash
# marsaprs-restore.sh — MARS APRS Mac
#
# Push a snapshot taken by marsaprs-backup.sh back onto a Pi. Replaces
# aprs-recover.sh, which pulled from the retired ftp.w6sg.net.
#
# Dry-run by default: it shows exactly what would change and writes nothing
# until you add --apply. Restoring overwrites live config on a running server,
# so the confirmation is deliberate.
#
# Usage:
#   marsaprs-restore.sh --list                       list snapshots
#   marsaprs-restore.sh aprs-pi                      dry-run, newest snapshot
#   marsaprs-restore.sh aprs-pi 20260826-205850      dry-run, that snapshot
#   marsaprs-restore.sh aprs-pi --apply              actually restore
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -uo pipefail

DEST_ROOT="${MARSAPRS_BACKUP_ROOT:-/Users/doug/aprs-backups}"
RSYNC=/usr/bin/rsync
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=15"

die() { echo "ERROR: $*" >&2; exit 1; }

list_snapshots() {
    local d
    for d in "$DEST_ROOT"/*/; do
        local name; name=$(basename "$d")
        [ "$name" = "logs" ] && continue
        ls -1d "$d"20*-* >/dev/null 2>&1 || continue
        echo "$name:"
        ls -1d "$d"20*-* 2>/dev/null | sort -r | while read -r s; do
            printf "   %-20s %s\n" "$(basename "$s")" "$(du -sh "$s" 2>/dev/null | cut -f1)"
        done
    done
}

[ $# -eq 0 ] && { echo "Usage: $(basename "$0") --list | <device> [snapshot] [--apply]"; exit 1; }
[ "$1" = "--list" ] && { list_snapshots; exit 0; }

DEVICE="$1"; shift
SNAP=""
APPLY="no"
for a in "$@"; do
    case "$a" in
        --apply) APPLY="yes" ;;
        *) SNAP="$a" ;;
    esac
done

DEVDIR="$DEST_ROOT/$DEVICE"
[ -d "$DEVDIR" ] || die "no backups for '$DEVICE' (try --list)"

if [ -z "$SNAP" ]; then
    SNAP=$(ls -1d "$DEVDIR"/20*-* 2>/dev/null | sort | tail -1)
    SNAP=$(basename "$SNAP")
fi
SRC="$DEVDIR/$SNAP"
[ -d "$SRC" ] || die "snapshot '$SNAP' not found under $DEVDIR"

case "$DEVICE" in
    aprs-pi)     HOST="aprs-pi";     SERVICE="aprs-daemon" ;;
    transcriber) HOST="transcriber"; SERVICE="" ;;
    *) die "unknown device '$DEVICE'" ;;
esac

DRY=(--dry-run)
[ "$APPLY" = "yes" ] && DRY=()

echo "Device   : $DEVICE  ($HOST)"
echo "Snapshot : $SNAP"
echo "Mode     : $([ "$APPLY" = "yes" ] && echo "APPLY — will overwrite live files" || echo "dry run — nothing will be written")"
echo ""

if [ "$APPLY" = "yes" ]; then
    echo "This overwrites live configuration on $HOST."
    read -rp "Proceed? [y/N] " C
    [ "$C" = "y" ] || [ "$C" = "Y" ] || { echo "Cancelled."; exit 0; }
    echo ""
    if [ -n "$SERVICE" ]; then
        echo "Stopping $SERVICE..."
        ssh $SSH_OPTS "$HOST" "sudo systemctl stop $SERVICE" 2>/dev/null || true
    fi
fi

# The snapshot mirrors the Pi's tree relative to /, so pushing its contents to /
# puts every file back exactly where it came from.
$RSYNC -av "${DRY[@]}" --no-perms --no-owner --no-group \
    -e "ssh $SSH_OPTS" \
    --exclude 'crontab.txt' \
    "$SRC"/ "$HOST:/"

RC=$?
echo ""

if [ "$APPLY" != "yes" ]; then
    echo "Dry run only. Re-run with --apply to write these files."
    exit 0
fi

[ $RC -ne 0 ] && die "rsync failed (rc=$RC) — service may still be stopped"

echo "Fixing ownership and permissions..."
if [ "$DEVICE" = "aprs-pi" ]; then
    ssh $SSH_OPTS "$HOST" '
        sudo chown -R www-data:www-data /var/www/html/events 2>/dev/null
        sudo chmod -R 755 /var/www/html/events 2>/dev/null
        for f in /var/www/html/netbird/addresses.yaml /var/www/html/wifi/wifi.yaml; do
            [ -f "$f" ] && sudo chown pi:www-data "$f" && sudo chmod 664 "$f"
        done
        [ -f /var/www/html/admin/password.txt ] && \
            sudo chown pi:www-data /var/www/html/admin/password.txt && \
            sudo chmod 640 /var/www/html/admin/password.txt
        [ -f /home/pi/.wifi-token ] && \
            sudo chown pi:pi /home/pi/.wifi-token && sudo chmod 600 /home/pi/.wifi-token
        [ -d /var/www/html/tickets ] && sudo chown -R www-data:www-data /var/www/html/tickets
        true'
    echo "Starting $SERVICE..."
    ssh $SSH_OPTS "$HOST" "sudo systemctl start $SERVICE" 2>/dev/null \
        && echo "$SERVICE running" \
        || echo "WARNING: $SERVICE did not start — check: sudo systemctl status $SERVICE"
else
    # The Transcriber does not have passwordless sudo, so this needs a TTY and
    # will prompt for the pi password.
    echo "(the Transcriber prompts for the pi password — sudo is not NOPASSWD there)"
    ssh -t $SSH_OPTS "$HOST" '
        sudo chown -R root:root /etc/transcriber
        sudo chmod 644 /etc/transcriber/version /etc/transcriber/last-update-request 2>/dev/null
        sudo chown root:pi /etc/transcriber/channels.json && sudo chmod 640 /etc/transcriber/channels.json
        sudo systemctl restart "transcriber@*" 2>/dev/null || true
        true'
fi

echo ""
echo "=== Restore complete ==="

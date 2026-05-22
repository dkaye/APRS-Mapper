#!/bin/bash
#
# aprs-recover.sh — MARS APRS Server
#
# Restores a backup from FTP. Run manually when needed.
#
# Usage:
#   /home/pi/aprs-recover.sh                                        # restore most recent
#   /home/pi/aprs-recover.sh aprs-backup-20260522_123555.tar.gz     # restore specific file
#   /home/pi/aprs-recover.sh --list                                 # show available backups
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

FTP_HOST="ftp.w6sg.net"
FTP_USER="aprs@w6sg.net"
FTP_PASS="Where@m1now"
FTP_DIR="APRS-Server-Backups"
TMP_FILE="/tmp/aprs-restore.tar.gz"

# ── Helpers ───────────────────────────────────────────────────────────────────

log()  { echo "$(date '+%Y-%m-%d %H:%M:%S')  $*"; }
die()  { echo ""; echo "ERROR: $*" >&2; exit 1; }
bold() { printf '\033[1m%s\033[0m\n' "$*"; }

lftp_open() {
    lftp -c "
set ftp:ssl-allow yes
set ssl:verify-certificate no
set net:timeout 30
set net:max-retries 3
open -u '${FTP_USER}','${FTP_PASS}' '${FTP_HOST}'
cd '${FTP_DIR}'
$1
"
}

# ── Fetch remote file list ────────────────────────────────────────────────────

log "Fetching backup list from ${FTP_HOST}/${FTP_DIR}/..."

REMOTE_RAW=$(lftp_open "cls -1" 2>/dev/null) || die "Cannot connect to FTP server"

# Normalise: strip any path prefix, keep only matching filenames, sort oldest→newest
REMOTE_LIST=""
while IFS= read -r line; do
    fname="${line##*/}"
    [[ "$fname" =~ ^aprs-backup-[0-9]{8}_[0-9]{6}\.tar\.gz$ ]] && REMOTE_LIST+="${fname}"$'\n'
done <<< "$REMOTE_RAW"
REMOTE_LIST="${REMOTE_LIST%$'\n'}"   # trim trailing newline

if [ -z "$REMOTE_LIST" ]; then
    die "No backups found on FTP server"
fi

# ── --list mode ───────────────────────────────────────────────────────────────

if [ "${1:-}" = "--list" ]; then
    echo ""
    bold "Available backups on ${FTP_HOST}/${FTP_DIR}/:"
    echo ""
    # Print newest first with a marker on the most recent
    SORTED=$(echo "$REMOTE_LIST" | sort -r)
    FIRST=true
    while IFS= read -r f; do
        if $FIRST; then
            printf '  %-50s  ← most recent\n' "$f"
            FIRST=false
        else
            printf '  %s\n' "$f"
        fi
    done <<< "$SORTED"
    echo ""
    exit 0
fi

# ── Select target file ────────────────────────────────────────────────────────

if [ -n "${1:-}" ]; then
    TARGET="${1##*/}"    # accept either bare name or full path
    if ! grep -qF "$TARGET" <<< "$REMOTE_LIST"; then
        echo ""
        bold "Available backups:"
        echo "$REMOTE_LIST" | sort -r | sed 's/^/  /'
        echo ""
        die "Not found on FTP server: ${TARGET}"
    fi
    log "Selected: ${TARGET}"
else
    TARGET=$(echo "$REMOTE_LIST" | sort | tail -1)
    log "Most recent backup: ${TARGET}"
fi

# ── Confirm ───────────────────────────────────────────────────────────────────

echo ""
bold "About to restore: ${TARGET}"
echo ""
echo "  This will overwrite:"
echo "    /var/www/html/events/"
echo "    /var/www/html/config.yaml"
echo "    /var/www/html/netbird/addresses.yaml"
echo "    /var/www/html/wifi/wifi.yaml"
echo "    /var/www/html/admin/password.txt"
echo "    /home/pi/.wifi-token"
echo ""
read -rp "Proceed? [y/N] " CONFIRM
[[ "${CONFIRM,,}" == "y" ]] || { echo "Cancelled."; exit 0; }
echo ""

# ── Download ──────────────────────────────────────────────────────────────────

log "Downloading ${TARGET}..."
lftp_open "get '${TARGET}' -o '${TMP_FILE}'" || die "Download failed"
SIZE=$(du -sh "$TMP_FILE" 2>/dev/null | cut -f1)
log "Downloaded ${SIZE}"

# ── Stop daemon ───────────────────────────────────────────────────────────────

log "Stopping aprs-daemon..."
sudo systemctl stop aprs-daemon.service 2>/dev/null || true

# ── Restore ───────────────────────────────────────────────────────────────────

log "Restoring files..."

sudo tar -xzf "$TMP_FILE" \
    --overwrite \
    --warning=no-unknown-keyword \
    -C / 2>/dev/null

rm -f "$TMP_FILE"

log "Files restored"

# ── Fix permissions ───────────────────────────────────────────────────────────

log "Fixing permissions..."

# events/ — writable by www-data (the daemon and web server write here)
sudo chown -R www-data:www-data /var/www/html/events 2>/dev/null || true
sudo chmod -R 755 /var/www/html/events 2>/dev/null || true

# addresses.yaml and wifi.yaml — pi owns, www-data can read/write
for f in /var/www/html/netbird/addresses.yaml /var/www/html/wifi/wifi.yaml; do
    [ -f "$f" ] || continue
    sudo chown pi:www-data "$f"
    sudo chmod 664 "$f"
done

# password.txt — readable by web server
[ -f /var/www/html/admin/password.txt ] && \
    sudo chown pi:www-data /var/www/html/admin/password.txt && \
    sudo chmod 640 /var/www/html/admin/password.txt

# .wifi-token — pi only
[ -f /home/pi/.wifi-token ] && \
    sudo chown pi:pi /home/pi/.wifi-token && \
    sudo chmod 600 /home/pi/.wifi-token

# ── Restart daemon ────────────────────────────────────────────────────────────

log "Starting aprs-daemon..."
sudo systemctl start aprs-daemon.service 2>/dev/null && \
    log "aprs-daemon running" || \
    log "WARNING: aprs-daemon did not start — check: sudo systemctl status aprs-daemon"

echo ""
log "=== Recovery complete ==="

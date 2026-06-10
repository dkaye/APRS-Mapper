#!/bin/bash
#
# aprs-backup.sh — MARS APRS Server
#
# Nightly FTP backup of all dynamic data (events, credentials, passwords).
# Keeps backups from the last 5 days; deletes older ones from the remote server.
#
# Cron: 0 2 * * * /home/pi/aprs-backup.sh >> /var/log/aprs-backup/aprs-backup.log 2>&1
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

# ── Config ────────────────────────────────────────────────────────────────────

FTP_HOST="ftp.w6sg.net"
FTP_USER="aprs@w6sg.net"
FTP_PASS="Where@m1now"
FTP_DIR="APRS-Server-Backups"
KEEP_DAYS=5

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="aprs-backup-${TIMESTAMP}.tar.gz"
TMP_FILE="/tmp/${BACKUP_NAME}"

# ── Helpers ───────────────────────────────────────────────────────────────────

log() { echo "$(date '+%Y-%m-%d %H:%M:%S')  $*"; }

log "=== APRS backup start ==="

# ── Verify lftp is available ──────────────────────────────────────────────────

if ! command -v lftp &>/dev/null; then
    log "Installing lftp..."
    sudo apt-get install -y lftp &>/dev/null
fi

# ── Build file list ───────────────────────────────────────────────────────────
# Back up everything that can change after installation:
#   events/            - event configs, course files, tracker history
#   config.yaml        - symlink pointing to the active event
#   addresses.yaml     - NetBird device list
#   wifi.yaml          - WiFi credentials
#   password.txt       - admin password
#   .wifi-token        - WiFi endpoint authentication token

PATHS=()
[ -d /var/www/html/events ]                 && PATHS+=(var/www/html/events)
[ -e /var/www/html/config.yaml ]            && PATHS+=(var/www/html/config.yaml)
[ -f /var/www/html/netbird/addresses.yaml ] && PATHS+=(var/www/html/netbird/addresses.yaml)
[ -f /var/www/html/wifi/wifi.yaml ]         && PATHS+=(var/www/html/wifi/wifi.yaml)
[ -f /var/www/html/admin/password.txt ]     && PATHS+=(var/www/html/admin/password.txt)
[ -f /home/pi/.wifi-token ]                 && PATHS+=(home/pi/.wifi-token)

if [ ${#PATHS[@]} -eq 0 ]; then
    log "ERROR: no data files found — nothing to back up"
    exit 1
fi

# ── Create archive ────────────────────────────────────────────────────────────

log "Creating ${BACKUP_NAME}..."

# Exit code 1 from tar means "some files changed during archiving" (e.g. tracker_history
# being written by the daemon) — treat that as success.
tar -czf "$TMP_FILE" \
    --ignore-failed-read \
    --warning=no-file-changed \
    -C / \
    "${PATHS[@]}" 2>/dev/null; TAR_RC=$?
if [ $TAR_RC -gt 1 ]; then
    log "ERROR: tar failed (rc=${TAR_RC})"
    rm -f "$TMP_FILE"
    exit 1
fi

SIZE=$(du -sh "$TMP_FILE" 2>/dev/null | cut -f1)
log "Archive size: ${SIZE}"

# ── Upload ────────────────────────────────────────────────────────────────────

log "Uploading to ${FTP_HOST}/${FTP_DIR}/..."

# Create directory if needed — suppress error if it already exists
lftp -c "
set ftp:ssl-allow yes
set ssl:verify-certificate no
set net:timeout 30
open -u '${FTP_USER}','${FTP_PASS}' '${FTP_HOST}'
mkdir '${FTP_DIR}'
" 2>/dev/null || true

lftp -c "
set ftp:ssl-allow yes
set ssl:verify-certificate no
set net:timeout 30
set net:max-retries 3
open -u '${FTP_USER}','${FTP_PASS}' '${FTP_HOST}'
cd '${FTP_DIR}'
put -O . '${TMP_FILE}'
"
UPLOAD_RC=$?

rm -f "$TMP_FILE"

if [ $UPLOAD_RC -ne 0 ]; then
    log "ERROR: upload failed (rc=${UPLOAD_RC})"
    exit 1
fi

log "Upload complete"

# ── Prune old backups from FTP ────────────────────────────────────────────────

CUTOFF=$(date -d "${KEEP_DAYS} days ago" +%Y%m%d)
log "Pruning backups older than ${KEEP_DAYS} days (before ${CUTOFF})..."

REMOTE_LIST=$(lftp -c "
set ftp:ssl-allow yes
set ssl:verify-certificate no
set net:timeout 30
open -u '${FTP_USER}','${FTP_PASS}' '${FTP_HOST}'
cd '${FTP_DIR}'
cls -1
" 2>/dev/null)

DELETE_CMDS=""
DELETED=0
while IFS= read -r fname; do
    fname="${fname##*/}"   # strip any path prefix the server may add
    [[ "$fname" =~ ^aprs-backup-([0-9]{8})_[0-9]{6}\.tar\.gz$ ]] || continue
    fdate="${BASH_REMATCH[1]}"
    if [[ "$fdate" < "$CUTOFF" ]]; then
        DELETE_CMDS+="rm '${fname}';"$'\n'
        log "  Deleting: ${fname}"
        DELETED=$((DELETED + 1))
    fi
done <<< "$REMOTE_LIST"

if [ -n "$DELETE_CMDS" ]; then
    lftp -c "
set ftp:ssl-allow yes
set ssl:verify-certificate no
set net:timeout 30
open -u '${FTP_USER}','${FTP_PASS}' '${FTP_HOST}'
cd '${FTP_DIR}'
${DELETE_CMDS}
" 2>/dev/null
    log "Deleted ${DELETED} old backup(s)"
else
    log "No old backups to delete"
fi

log "=== APRS backup complete ==="

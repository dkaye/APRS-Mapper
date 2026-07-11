#!/usr/bin/env bash
# migrate-to-v5.sh — Migrate an old iGate to v5.0
#
# Backs up direwolf.conf and config.php, runs install.sh, restores configs, reboots.
# Safe to run on a live iGate — config is preserved, direwolf will be briefly offline
# during the Direwolf build (~10 min on Pi 4, ~21 min on Pi Zero 2W).
#
# Usage (run from your Mac):
#   bash migrate-to-v5.sh <ip-or-hostname> [ssh-password]
#
# Example:
#   bash migrate-to-v5.sh 100.101.197.70 guacamole
#
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

HOST="${1:?Usage: $0 <host> [password]}"
PASS="${2:-guacamole}"
SSHOPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10"
SSH="sshpass -p $PASS ssh $SSHOPTS -T pi@$HOST"
SCP="sshpass -p $PASS scp $SSHOPTS"
BACKUP="/tmp/igate-v5-migration"

echo ""
echo "=== iGate v5.0 Migration: $HOST ==="
echo ""

# ── Step 1: Back up configs ───────────────────────────────────────────────────
echo "--- Backing up direwolf.conf and config.php..."
mkdir -p "$BACKUP/$HOST"

$SSH "cat /home/pi/direwolf.conf 2>/dev/null || true" \
    > "$BACKUP/$HOST/direwolf.conf"
sleep 2

$SSH "sudo cat /var/www/html/config.php 2>/dev/null || true" \
    > "$BACKUP/$HOST/config.php"
sleep 2

if [[ ! -s "$BACKUP/$HOST/direwolf.conf" ]]; then
    echo "WARNING: direwolf.conf is empty or missing — will not restore it."
fi
if [[ ! -s "$BACKUP/$HOST/config.php" ]]; then
    echo "WARNING: config.php is empty or missing — will not restore it."
fi

echo "    Backed up to $BACKUP/$HOST/"

# ── Step 2: Kill any screen sessions running direwolf ────────────────────────
echo "--- Stopping screen sessions..."
$SSH "sudo pkill -f 'SCREEN.*direwolf' 2>/dev/null || true; \
      sudo pkill -f 'direwolf-start' 2>/dev/null || true; \
      sudo pkill -f 'direwolf -c' 2>/dev/null || true; \
      true"

# ── Step 3: Run install.sh in background (survives SSH timeout/reboot) ───────
sleep 2
echo "--- Launching install.sh in background (takes 7–21 min on Pi 4)..."
$SSH "nohup bash -c 'bash <(curl -fsSL https://marsaprs.org/igate/install.sh) \
    > /home/pi/install-v5.log 2>&1; echo DONE >> /home/pi/install-v5.log' \
    &>/dev/null &"

echo "--- Polling for completion (checking every 30s)..."
while true; do
    sleep 30
    TAIL=$($SSH "strings /home/pi/install-v5.log 2>/dev/null | tail -3" 2>/dev/null || echo "unreachable")
    echo "    [$(date +%H:%M)] $TAIL"
    if echo "$TAIL" | grep -q "DONE\|Skipping configuration\|Installation complete"; then
        echo "--- Install complete."
        $SSH "cat /home/pi/install-v5.log" > "$BACKUP/$HOST/install.log" 2>/dev/null || true
        break
    fi
done

# ── Step 4: Restore configs ───────────────────────────────────────────────────
sleep 2
echo "--- Restoring configs..."

if [[ -s "$BACKUP/$HOST/direwolf.conf" ]]; then
    $SCP "$BACKUP/$HOST/direwolf.conf" "pi@$HOST:/home/pi/direwolf.conf"
    echo "    direwolf.conf restored."
fi
sleep 2

if [[ -s "$BACKUP/$HOST/config.php" ]]; then
    $SCP "$BACKUP/$HOST/config.php" "pi@$HOST:/tmp/config.php.restore"
    sleep 2
    $SSH "sudo mv /tmp/config.php.restore /var/www/html/config.php && \
          sudo chown www-data:www-data /var/www/html/config.php"
    echo "    config.php restored."
fi

# ── Step 5: Replace v4 crontab and remove StartAllApps.sh ────────────────────
sleep 2
echo "--- Installing v5 crontab..."
$SSH "crontab - << 'EOF'
# K6DRK iGate v5.0
#
# Health watchdog: SDR + IP every minute, internet every 5 min
* * * * * /home/pi/igate-watchdog.sh
# Nightly update at 4:01am
1 4 * * * /home/pi/auto-update.sh
# Nightly reboot at 4:10am (after updates)
10 4 * * * sudo reboot
# Check every 5 minutes whether to enable/disable NetBird
*/5 * * * * /home/pi/check-netbird.sh
# Enable NetBird after any reboot
@reboot /home/pi/netbird-up.sh
EOF
rm -f /home/pi/direwolf-start.sh \
         /home/pi/direwatch-start.sh \
         /home/pi/CheckNetBird.sh \
         /home/pi/NetbirdUp.sh \
         /home/pi/StatsRequestListener.php \
         /home/pi/StatsRequestListener-start.sh \
         /home/pi/add_wifi.php \
         /home/pi/getIgateList.sh \
         /home/pi/check-swapping.sh \
         /home/pi/auto-update2.sh \
         /home/pi/install.sh \
         /home/pi/StartAllApps.sh"

# ── Step 6: Reboot ────────────────────────────────────────────────────────────
echo "--- Rebooting $HOST..."
$SSH "sudo reboot" || true

# ── Step 7: Update known_hosts (fresh OS generates new SSH host keys) ─────────
echo "--- Waiting 75s for reboot, then updating known_hosts..."
sleep 75
ssh-keyscan -H "$HOST" >> ~/.ssh/known_hosts 2>/dev/null && \
    echo "    known_hosts updated for $HOST." || \
    echo "    WARNING: ssh-keyscan failed — may need to run manually: ssh-keyscan -H $HOST >> ~/.ssh/known_hosts"

echo ""
echo "=== Migration complete. $HOST is rebooting. ==="
echo "    Install log saved to: $BACKUP/$HOST/install.log"
echo "    Config backups in:    $BACKUP/$HOST/"
echo ""
echo "    After reboot (~60s), verify with:"
echo "    sshpass -p $PASS ssh $SSHOPTS pi@$HOST 'systemctl is-active direwolf direwatch stats-listener'"

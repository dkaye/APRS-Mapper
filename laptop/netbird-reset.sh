#!/bin/bash
# NetBird full identity reset
# Run on each Pi separately with its own setup key:
#   sudo bash netbird-reset.sh <SETUP_KEY>

set -e

SETUP_KEY="${1:-}"
if [ -z "$SETUP_KEY" ]; then
    echo "Usage: sudo bash netbird-reset.sh <SETUP_KEY>"
    exit 1
fi

echo "==> Stopping NetBird..."
netbird down 2>/dev/null || true
systemctl stop netbird 2>/dev/null || true

echo "==> Removing NetBird identity and state..."
rm -f /var/lib/netbird/default.json
rm -f /var/lib/netbird/state.json
rm -f /var/lib/netbird/active_profile.json
rm -f /var/lib/netbird/service.json

echo "==> Regenerating machine-id..."
rm -f /etc/machine-id
systemd-machine-id-setup
echo "    New machine-id: $(cat /etc/machine-id)"

echo "==> Re-enrolling with NetBird..."
netbird up --setup-key "$SETUP_KEY" --foreground-mode &
NB_PID=$!
sleep 10
kill $NB_PID 2>/dev/null || true

echo "==> Starting NetBird service..."
systemctl start netbird
sleep 5

echo "==> Done. New NetBird IP:"
netbird status 2>/dev/null | grep -i 'IP\|ip' | head -5

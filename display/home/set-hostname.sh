#!/bin/bash
# Change the system hostname everywhere it needs to be set.
# Usage: sudo set-hostname.sh <new-hostname>
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -e

if [[ $EUID -ne 0 ]]; then
    echo "Run as root: sudo $0 <new-hostname>"
    exit 1
fi

if [[ -z "$1" ]]; then
    echo "Usage: sudo $0 <new-hostname>"
    exit 1
fi

NEW="$1"
OLD="$(cat /etc/hostname | tr -d '[:space:]')"

if [[ "$NEW" == "$OLD" ]]; then
    echo "Hostname is already '$NEW' — nothing to do."
    exit 0
fi

echo "Changing hostname: '$OLD' → '$NEW'"

# /boot/firmware/user-data — cloud-init applies this on every boot
USERDATA=/boot/firmware/user-data
if [[ -f "$USERDATA" ]]; then
    sed -i "s/^hostname: .*/hostname: $NEW/" "$USERDATA"
    echo "  ✓ /boot/firmware/user-data"
else
    echo "  ! /boot/firmware/user-data not found — skipping (hostname may reset on reboot)"
fi

# /etc/hostname
echo "$NEW" > /etc/hostname
echo "  ✓ /etc/hostname"

# /etc/hosts — replace all occurrences of the old hostname
sed -i "s/\b${OLD}\b/${NEW}/g" /etc/hosts
echo "  ✓ /etc/hosts"

# Apply to the running kernel (updates shell prompt immediately)
hostname "$NEW"
echo "  ✓ kernel hostname"

# Update systemd's view
hostnamectl set-hostname "$NEW"
echo "  ✓ hostnamectl"

echo ""
echo "Done. New hostname: $(hostname)"
echo "Run 'exec \$SHELL' to refresh your shell prompt."

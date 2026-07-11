#!/usr/bin/env bash
# ramlog-setup.sh — iGate RAM log setup (idempotent)
#
# Configures /var/log as tmpfs to reduce SD card writes.
# Logs are saved to /var/log-saved/YYYY-MM-DD/ by auto-update.sh before each
# nightly reboot, giving 14 days of rolling history on the SD card.
#
# Safe to run multiple times — checks before making any change.
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

FSTAB_LINE="tmpfs /var/log tmpfs defaults,noatime,nosuid,nodev,size=64M 0 0"

# Add tmpfs /var/log entry to /etc/fstab if not already present
if grep -qE '^tmpfs[[:space:]]+/var/log[[:space:]]' /etc/fstab; then
    echo "ramlog-setup: /var/log tmpfs already in /etc/fstab"
else
    echo "$FSTAB_LINE" | sudo tee -a /etc/fstab > /dev/null
    echo "ramlog-setup: added /var/log tmpfs to /etc/fstab (active after next reboot)"
fi

# Create persistent save directory on the SD card
sudo mkdir -p /var/log-saved
echo "ramlog-setup: /var/log-saved ready"

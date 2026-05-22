#!/usr/bin/env bash
# set-hostname.sh — MARS APRS Server
#
# Sets the system hostname persistently via hostnamectl and /etc/hosts.
# Must be run as root (called via sudo from configure.sh).
#
# Usage: sudo /home/pi/set-hostname.sh <new-hostname>
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

NEW="$1"
OLD=$(hostname)

hostnamectl set-hostname "$NEW"

# Update /etc/hosts so 127.0.1.1 resolves to the new name
if grep -q "127\.0\.1\.1" /etc/hosts; then
    sed -i "s/127\.0\.1\.1.*/127.0.1.1\t$NEW/" /etc/hosts
else
    echo -e "127.0.1.1\t$NEW" >> /etc/hosts
fi

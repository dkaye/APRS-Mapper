#!/usr/bin/bash
# netbird-up.sh — MARS APRS Display Pi
#
# Enables NetBird and its prerequisites. Run at boot via @reboot cron.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

echo "Enabling NetBird"
sudo systemctl enable rpcbind.socket
sudo systemctl enable rpcbind.service
sudo systemctl start rpcbind.socket
sudo systemctl start rpcbind.service
sudo systemctl enable avahi-daemon
sudo systemctl start avahi-daemon
sudo systemctl enable --now systemd-timesyncd
sudo timedatectl set-ntp true
sudo /usr/bin/netbird up --enable-lazy-connection
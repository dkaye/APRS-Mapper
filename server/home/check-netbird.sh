#!/usr/bin/bash
# check-netbird.sh — MARS APRS Server Pi
#
# Called every 5 minutes via cron. Asks marsaprs.org/netbird/ whether NetBird
# should be enabled or disabled for this device, then acts accordingly.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

RESPONSE=$(wget -qO- "https://marsaprs.org/netbird/?hostname=$HOSTNAME" 2>/dev/null)

if [[ "$RESPONSE" == "1" ]]; then
	echo "Enabling NetBird"
	sudo systemctl enable rpcbind.socket rpcbind.service avahi-daemon
	sudo systemctl start rpcbind.socket rpcbind.service avahi-daemon
	sudo systemctl enable --now systemd-timesyncd
	sudo timedatectl set-ntp true
	sudo /usr/bin/netbird up
elif [[ "$RESPONSE" == "0" ]]; then
	echo "Disabling NetBird"
	sudo /usr/bin/netbird down
	sudo systemctl stop rpcbind.service rpcbind.socket avahi-daemon
	sudo systemctl disable rpcbind.service rpcbind.socket avahi-daemon
	sudo timedatectl set-ntp false
	sudo systemctl disable --now systemd-timesyncd
else
	echo "NetBird check: no change (response: ${RESPONSE:-empty/error})"
fi
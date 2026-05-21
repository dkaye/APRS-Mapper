#!/usr/bin/bash
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
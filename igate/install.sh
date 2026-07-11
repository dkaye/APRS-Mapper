#!/usr/bin/env bash
# K6DRK iGate v5.0 — Installation Script
#
# Run once on a fresh Raspberry Pi OS installation.
# Pi Imager should have already configured: hostname, pi/guacamole, WiFi, SSH, timezone.
#
# Usage:
#   bash <(curl -fsSL https://marsaprs.org/igate/install.sh) 2>&1 | tee install.log
#
# At the end, the script asks whether to run configure.sh.
# Answer No when building a master SD card image.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

BASE="https://marsaprs.org/igate"

msg()  { printf "\n\033[1;34m=== %s ===\033[0m\n" "$1"; }
ok()   { printf "\033[0;32m✓ %s\033[0m\n" "$1"; }
warn() { printf "\033[0;33m⚠ %s\033[0m\n" "$1"; }

msg "iGate v5.0 Installation"
echo "This takes about 7 minutes on a Pi 4, 21 minutes on a Pi Zero 2W."
echo ""

# ── System update ────────────────────────────────────────────────────────────
msg "Updating system packages"
sudo apt-get update -y
sudo DEBIAN_FRONTEND=noninteractive apt-get upgrade -y \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" || true
ok "System up to date"

# ── SPI (required for TFT display) ───────────────────────────────────────────
msg "Enabling SPI interface"
if grep -q "^#dtparam=spi=on" /boot/firmware/config.txt; then
    sudo sed -i 's/^#dtparam=spi=on/dtparam=spi=on/' /boot/firmware/config.txt
    ok "SPI enabled"
elif grep -q "^dtparam=spi=on" /boot/firmware/config.txt; then
    ok "SPI already enabled"
else
    echo "dtparam=spi=on" | sudo tee -a /boot/firmware/config.txt
    ok "SPI enabled (appended)"
fi

# ── Hardware watchdog ─────────────────────────────────────────────────────────
msg "Configuring hardware watchdog"
if ! grep -q "dtparam=watchdog=on" /boot/firmware/config.txt; then
    echo "dtparam=watchdog=on" | sudo tee -a /boot/firmware/config.txt
fi
ok "Watchdog config.txt updated"

# ── Install all apt packages ──────────────────────────────────────────────────
msg "Installing packages"
sudo apt-get install -y \
    git gcc g++ make cmake rsync \
    libasound2-dev libudev-dev libavahi-client-dev libgpiod-dev \
    rtl-sdr \
    lighttpd php8.4-common php8.4-cgi php \
    python3-pip python3-pil python3-pyinotify \
    python3-numpy python3-libgpiod python3-lgpio fonts-dejavu \
    ufw watchdog nethogs
ok "Packages installed"

# ── Configure watchdog daemon ─────────────────────────────────────────────────
msg "Configuring watchdog daemon"
grep -q "watchdog-device" /etc/watchdog.conf 2>/dev/null || \
    sudo bash -c 'cat >> /etc/watchdog.conf' << 'EOF'
watchdog-device = /dev/watchdog
watchdog-timeout = 15
max-load-1 = 24
EOF
sudo systemctl enable watchdog
sudo systemctl start watchdog
ok "Watchdog running"

# ── Build Direwolf from source ────────────────────────────────────────────────
msg "Building Direwolf (this takes a few minutes)"
cd /home/pi
rm -rf direwolf
git clone https://github.com/wb2osz/direwolf
cd direwolf
mkdir build && cd build
cmake ..
make -j"$(nproc)"
sudo make install
make install-conf
cd /home/pi
rm -f dw-start.sh telem-*
ok "Direwolf built and installed"

# ── Python pip packages (for TFT display) ────────────────────────────────────
msg "Installing Python display libraries"
sudo pip3 install --break-system-packages \
    adafruit-circuitpython-rgb-display Adafruit-Blinka aprslib
ok "Python libraries installed"

# ── Clone direwatch ───────────────────────────────────────────────────────────
msg "Cloning direwatch"
cd /home/pi
rm -rf direwatch
git clone https://github.com/craigerl/direwatch.git
ok "Direwatch cloned"

# ── Web dashboard ─────────────────────────────────────────────────────────────
msg "Installing web dashboard"
rm -rf /home/pi/Direwolf-APRS-Web-Dashboard
git clone https://github.com/PC7MM/Direwolf-APRS-Web-Dashboard \
    /home/pi/Direwolf-APRS-Web-Dashboard
sudo mkdir -p /var/www/html
sudo cp /home/pi/Direwolf-APRS-Web-Dashboard/code/* /var/www/html/
ok "Web dashboard base installed"

# ── Download and apply iGate files ───────────────────────────────────────────
msg "Downloading iGate files from marsaprs.org"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
wget -qO "$TMP/files.tar.gz" --header="Pragma: no-cache" --header="Cache-Control: no-cache" "$BASE/files.tar.gz"
tar -xzf "$TMP/files.tar.gz" --warning=no-unknown-keyword -C "$TMP"

rsync -a "$TMP/home/"     /home/pi/
rsync -a "$TMP/direwatch/" /home/pi/direwatch/
sudo rsync -a "$TMP/www/" /var/www/html/
sudo rsync -a "$TMP/systemd/" /etc/systemd/system/
sudo rsync -a "$TMP/udev/" /etc/udev/rules.d/
sudo udevadm control --reload-rules
ok "iGate files applied"

# ── Download iGate list ───────────────────────────────────────────────────────
msg "Downloading iGate list"
sudo wget -qO /var/www/html/igate-stations.json \
    "https://marsaprs.org/netbird/igate-list.php" \
    && ok "iGate list downloaded" || warn "iGate list download failed (non-fatal)"

# ── Download WiFi list ────────────────────────────────────────────────────────
msg "Downloading WiFi list"
if [ -f /home/pi/.wifi-token ]; then
    if wget -qO /home/pi/wifi.yaml \
            "$BASE/wifi/get.php?token=$(cat /home/pi/.wifi-token)"; then
        ok "WiFi list downloaded"
        php /home/pi/update-wifi.php || warn "WiFi list apply failed (non-fatal)"
    else
        warn "WiFi list download failed (non-fatal)"
    fi
else
    warn "No .wifi-token found — skipping WiFi list download"
fi

# ── Install template configs (placeholders — edit after reboot) ───────────────
msg "Installing config templates"
wget -qO /home/pi/direwolf.conf       "$BASE/templates/direwolf.conf.template"
sudo wget -qO /var/www/html/config.php "$BASE/templates/config.php.template"
ok "Config templates installed (edit these after reboot)"

# ── Log rotation ──────────────────────────────────────────────────────────────
if [ -f "$TMP/etc/logrotate.d/aprs" ]; then
    sudo cp "$TMP/etc/logrotate.d/aprs" /etc/logrotate.d/aprs
    ok "Logrotate configured"
fi

# ── Journald volatile storage (reduces SD card writes) ────────────────────────
sudo mkdir -p /etc/systemd/journald.conf.d
printf '[Journal]\nStorage=volatile\n' | sudo tee /etc/systemd/journald.conf.d/volatile.conf > /dev/null
sudo systemctl restart systemd-journald
ok "Journald configured for volatile (RAM) storage"

# ── RAM log setup (/var/log as tmpfs to reduce SD card writes) ────────────────
msg "Configuring RAM log filesystem"
if [ -f "$TMP/etc/tmpfiles.d/igate-logs.conf" ]; then
    sudo mkdir -p /etc/tmpfiles.d
    sudo cp "$TMP/etc/tmpfiles.d/igate-logs.conf" /etc/tmpfiles.d/igate-logs.conf
fi
chmod +x /home/pi/ramlog-setup.sh
/home/pi/ramlog-setup.sh
# Create /var/log/direwolf now (tmpfs not yet active — activates after first reboot)
sudo mkdir -p /var/log/direwolf
sudo chown pi:pi /var/log/direwolf
ok "RAM logs configured (/var/log → tmpfs after reboot; saves to /var/log-saved/ nightly)"

# ── Lighttpd (web server) ─────────────────────────────────────────────────────
msg "Configuring lighttpd"
sudo lighty-enable-mod fastcgi
sudo lighty-enable-mod fastcgi-php
sudo service lighttpd force-reload
ok "Lighttpd configured"

# ── Systemd services ──────────────────────────────────────────────────────────
msg "Enabling systemd services"
sudo systemctl daemon-reload
sudo systemctl enable dw-startup.service
sudo systemctl enable direwolf.service
sudo systemctl enable direwatch.service
sudo systemctl enable --now stats-listener.service
ok "Services enabled (start on next reboot)"

# ── Crontab ───────────────────────────────────────────────────────────────────
msg "Installing crontab"
crontab - << 'EOF'
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
ok "Crontab installed"

# ── Passwordless sudo for pi ──────────────────────────────────────────────────
msg "Configuring passwordless sudo"
echo "pi ALL=(ALL) NOPASSWD: ALL" | sudo tee /etc/sudoers.d/010_pi-nopasswd > /dev/null
sudo chmod 440 /etc/sudoers.d/010_pi-nopasswd
ok "Passwordless sudo configured"

# ── SSH hardening ─────────────────────────────────────────────────────────────
msg "Hardening SSH"
grep -q "AllowUsers pi" /etc/ssh/sshd_config || \
    echo "AllowUsers pi" | sudo tee -a /etc/ssh/sshd_config
grep -q "PermitRootLogin no" /etc/ssh/sshd_config || \
    echo "PermitRootLogin no" | sudo tee -a /etc/ssh/sshd_config
ok "SSH restricted to user pi, root login disabled"

# ── Firewall ──────────────────────────────────────────────────────────────────
msg "Configuring firewall"
sudo ufw allow ssh
sudo ufw allow http
sudo ufw allow https
sudo ufw allow 1235/udp
sudo ufw default deny incoming
sudo ufw --force enable
ok "UFW firewall enabled"

# ── File permissions ──────────────────────────────────────────────────────────
msg "Setting permissions"
sudo chown -R pi:pi /home/pi
sudo chmod 755 /home/pi
chmod +x /home/pi/*.sh /home/pi/*.php
ok "Permissions set"

# ── Time sync ─────────────────────────────────────────────────────────────────
sudo timedatectl set-ntp on

# ── Remove unneeded packages ──────────────────────────────────────────────────
msg "Cleaning up"
sudo apt-get purge packagekit -y 2>/dev/null || true
sudo apt-get autoremove -y
ok "Cleanup done"

# ── Configure site-specific settings ─────────────────────────────────────────
chmod +x /home/pi/configure.sh
msg "Installation complete"
echo ""
read -rp "Run configure.sh now to set callsign, location, and NetBird key? [y/N]: " RUN_CONFIGURE < /dev/tty || RUN_CONFIGURE=""
if [[ "${RUN_CONFIGURE,,}" == "y" ]]; then
    /home/pi/configure.sh
else
    ok "Skipping configuration — CONFIGURE_ME placeholders are in place."
    echo ""
    echo "  To configure this iGate later:  /home/pi/configure.sh"
    echo "  To build a master image: shut down cleanly, then clone the SD card."
fi

#!/usr/bin/env bash
# MARS APRS Server (aprs-pi) — Installation Script
#
# Run once on a fresh Raspberry Pi OS Desktop installation.
# Pi Imager should have already configured: hostname, pi/guacamole, WiFi, SSH, timezone.
#
# Usage:
#   bash <(curl -fsSL https://marsaprs.org/server/install.sh) 2>&1 | tee install.log
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

BASE="https://marsaprs.org/server"

msg()  { printf "\n\033[1;34m=== %s ===\033[0m\n" "$1"; }
ok()   { printf "\033[0;32m✓ %s\033[0m\n" "$1"; }
warn() { printf "\033[0;33m⚠ %s\033[0m\n" "$1"; }

msg "MARS APRS Server Installation"
echo "Installs the APRS Tracker Map server (aprsDaemon, Apache, Cloudflare, NetBird)."
echo ""

# ── System update ─────────────────────────────────────────────────────────────
msg "Updating system packages"
sudo apt-get update -y
sudo apt-get upgrade -y
ok "System up to date"

# ── Install apt packages ──────────────────────────────────────────────────────
msg "Installing packages"
sudo apt-get install -y \
    apache2 php libapache2-mod-php php8.4-cgi php-ssh2 \
    python3-paramiko \
    avahi-daemon \
    curl rsync \
    lftp \
    ufw
ok "Packages installed"

# ── Cloudflared ───────────────────────────────────────────────────────────────
msg "Installing cloudflared"
if ! command -v cloudflared &>/dev/null; then
    ARCH=$(dpkg --print-architecture)
    curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${ARCH}.deb" \
        -o /tmp/cloudflared.deb
    sudo dpkg -i /tmp/cloudflared.deb
    rm /tmp/cloudflared.deb
    ok "cloudflared installed"
else
    ok "cloudflared already installed"
fi

# ── Download and apply server files ──────────────────────────────────────────
msg "Downloading server files from marsaprs.org"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
LOCAL_TAR="/var/www/html/server/files.tar.gz"
if [ -f "$LOCAL_TAR" ]; then
    ok "Using local files.tar.gz"
    cp "$LOCAL_TAR" "$TMP/files.tar.gz"
else
    wget -qO "$TMP/files.tar.gz" \
        --header="Pragma: no-cache" --header="Cache-Control: no-cache" \
        "$BASE/files.tar.gz"
fi
tar -xzf "$TMP/files.tar.gz" --warning=no-unknown-keyword -C "$TMP"

rsync -a "$TMP/home/"     /home/pi/
sudo rsync -a \
    --ignore-times \
    --exclude='config.yaml' \
    --exclude='events/' \
    --exclude='trackers.json' \
    --exclude='netbird/addresses.yaml' \
    --exclude='netbird/toggle_state.json' \
    "$TMP/www/" /var/www/html/
sudo rsync -a "$TMP/bin/"        /usr/local/bin/
sudo rsync -a "$TMP/systemd/"    /etc/systemd/system/
sudo rsync -a "$TMP/apache/"     /etc/apache2/sites-available/
sudo mkdir -p /etc/cloudflared
sudo rsync -a "$TMP/cloudflared/" /etc/cloudflared/
ok "Server files applied"

# ── Daemon scripts permissions ────────────────────────────────────────────────
sudo chmod +x /usr/local/bin/aprs-daemon.sh \
              /usr/local/bin/wifi-watchdog.sh \
              /usr/local/bin/wifi-restored.sh

# Remove Apache default page so index.php takes precedence
sudo rm -f /var/www/html/index.html

# ── Web root permissions ──────────────────────────────────────────────────────
msg "Configuring web root permissions"
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html
# Allow www-data to write runtime data files
sudo chmod 664 /var/www/html/netbird/addresses.yaml 2>/dev/null || true
sudo chown pi:www-data /var/www/html/netbird/addresses.yaml 2>/dev/null || true
# pi user needs write access for git pulls and config edits
sudo chown pi:www-data /var/www/html
sudo chmod 775 /var/www/html
ok "Web root permissions set"

# ── Apache ────────────────────────────────────────────────────────────────────
msg "Configuring Apache"
sudo a2enmod rewrite ssl php8.4 headers
sudo a2ensite 000-default
sudo a2dissite 000-default-le-ssl 2>/dev/null || true
sudo systemctl enable apache2
sudo systemctl restart apache2
ok "Apache configured"

# ── Download WiFi list ────────────────────────────────────────────────────────
msg "Downloading WiFi list"
if [ -f /home/pi/.wifi-token ]; then
    if wget -qO /home/pi/wifi.conf \
            "$BASE/wifi/get.php?token=$(cat /home/pi/.wifi-token)"; then
        ok "WiFi list downloaded"
        php /home/pi/update-wifi.php || warn "WiFi apply failed (non-fatal)"
    else
        warn "WiFi list download failed (non-fatal)"
    fi
else
    warn "No .wifi-token found — skipping WiFi list download"
fi

# ── Systemd services ──────────────────────────────────────────────────────────
msg "Enabling systemd services"
sudo mkdir -p /var/log/aprs-daemon /run/aprs-daemon
sudo chown www-data:www-data /var/log/aprs-daemon /run/aprs-daemon
sudo systemctl daemon-reload
sudo systemctl enable aprs-daemon.service
sudo systemctl enable wifi-watchdog.service
sudo systemctl enable netbird-poller.service
ok "Services enabled (start on next reboot)"

# ── Crontab ───────────────────────────────────────────────────────────────────
msg "Installing crontab"
crontab - << 'EOF'
# MARS APRS Server
# Check every 5 minutes whether to enable/disable NetBird
*/5 * * * * /home/pi/check-netbird.sh >> /tmp/checknetbird.log 2>&1
# Enable NetBird after any reboot
@reboot /home/pi/netbird-up.sh
# Nightly backup at 2:00am
0 2 * * * /home/pi/aprs-backup.sh >> /var/log/aprs-backup.log 2>&1
# Nightly WiFi update at 4:00am
0 4 * * * php /home/pi/update-wifi.php ssids=/var/www/html/wifi/wifi.yaml >> /tmp/server-wifi-update.log 2>&1
# Nightly reboot at 4:10am (after any updates)
10 4 * * * sudo reboot
EOF
ok "Crontab installed"

# ── Passwordless sudo for pi ──────────────────────────────────────────────────
msg "Configuring passwordless sudo"
echo "pi ALL=(ALL) NOPASSWD: ALL" | sudo tee /etc/sudoers.d/010_pi-nopasswd > /dev/null
sudo chmod 440 /etc/sudoers.d/010_pi-nopasswd
echo "www-data ALL=(pi) NOPASSWD: /usr/bin/php /home/pi/update-wifi.php *" | sudo tee /etc/sudoers.d/020_www-data-wifi > /dev/null
sudo chmod 440 /etc/sudoers.d/020_www-data-wifi
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
sudo ufw allow 1235/udp   # StatsRequestListener
sudo ufw default deny incoming
sudo ufw --force enable
ok "UFW firewall enabled"

# ── File permissions ──────────────────────────────────────────────────────────
msg "Setting home permissions"
sudo chown -R pi:pi /home/pi
chmod +x /home/pi/*.sh /home/pi/*.php 2>/dev/null || true
ok "Permissions set"

# ── Log rotation ──────────────────────────────────────────────────────────────
if [ -f "$TMP/etc/logrotate.d/aprs" ]; then
    sudo cp "$TMP/etc/logrotate.d/aprs" /etc/logrotate.d/aprs
    ok "Logrotate configured"
fi

# ── Cleanup ───────────────────────────────────────────────────────────────────
msg "Cleaning up"
sudo apt-get autoremove -y
ok "Cleanup done"

# ── Configure site-specific settings ─────────────────────────────────────────
chmod +x /home/pi/configure.sh
msg "Installation complete"
echo ""
read -rp "Run configure.sh now to set hostname and NetBird key? [y/N]: " RUN_CONFIGURE
if [[ "${RUN_CONFIGURE,,}" == "y" ]]; then
    /home/pi/configure.sh
else
    ok "Skipping configuration — CONFIGURE_ME placeholders are in place."
    echo ""
    echo "  To configure this server later:  /home/pi/configure.sh"
    echo "  To build a master image: shut down cleanly, then clone the SD card."
fi

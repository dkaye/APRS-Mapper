#!/usr/bin/env bash
# MARS APRS Display Pi (NetControl / BigTV) — Installation Script
#
# Run once on a fresh Raspberry Pi OS Desktop installation.
# Pi Imager should have already configured: hostname, pi/guacamole, WiFi, SSH, timezone.
#
# Usage:
#   bash <(curl -fsSL https://marsaprs.org/display/install.sh) 2>&1 | tee install.log
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

BASE="https://marsaprs.org/display"

msg()  { printf "\n\033[1;34m=== %s ===\033[0m\n" "$1"; }
ok()   { printf "\033[0;32m✓ %s\033[0m\n" "$1"; }
warn() { printf "\033[0;33m⚠ %s\033[0m\n" "$1"; }

msg "Display Pi Installation"
echo "Installs the MARS APRS kiosk display software (NetControl / BigTV)."
echo ""

# ── System update ─────────────────────────────────────────────────────────────
msg "Updating system packages"
sudo apt-get update -y
sudo apt-get upgrade -y
ok "System up to date"

# ── Install packages ──────────────────────────────────────────────────────────
msg "Installing packages"
sudo apt-get install -y \
    apache2 php libapache2-mod-php \
    x11vnc \
    avahi-daemon \
    ufw \
    curl
ok "Packages installed"

# ── Apache ────────────────────────────────────────────────────────────────────
msg "Configuring Apache"
sudo systemctl enable apache2
sudo systemctl restart apache2
ok "Apache running"

# ── Download and apply display files ─────────────────────────────────────────
msg "Downloading display files from marsaprs.org"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
wget -qO "$TMP/files.tar.gz" \
    --header="Pragma: no-cache" --header="Cache-Control: no-cache" \
    "$BASE/files.tar.gz"
tar -xzf "$TMP/files.tar.gz" --warning=no-unknown-keyword -C "$TMP"

rsync -a "$TMP/home/"     /home/pi/
sudo rsync -a "$TMP/systemd/" /etc/systemd/system/
ok "Display files applied"

# ── Deploy daemon scripts to /usr/local/bin ───────────────────────────────────
msg "Installing daemon scripts to /usr/local/bin"
for script in aprs-monitor.sh wifi-watchdog.sh wifi-restored.sh; do
    sudo cp "/home/pi/$script" "/usr/local/bin/$script"
    sudo chmod +x "/usr/local/bin/$script"
done
ok "Daemon scripts installed"

# ── HDMI hotplug ─────────────────────────────────────────────────────────────
# Without this the GPU skips HDMI init if no monitor is connected at boot,
# so plugging one in later produces no video.
msg "Enabling HDMI hotplug"
BOOT_CFG=/boot/firmware/config.txt
if ! grep -q "hdmi_force_hotplug" "$BOOT_CFG"; then
    echo "" | sudo tee -a "$BOOT_CFG" > /dev/null
    echo "# Always init HDMI so a monitor can be connected after boot" | sudo tee -a "$BOOT_CFG" > /dev/null
    echo "hdmi_force_hotplug=1" | sudo tee -a "$BOOT_CFG" > /dev/null
fi
ok "HDMI hotplug enabled (takes effect after reboot)"

# ── Force X11 session (rpd-x, not Wayland rpd-labwc) ─────────────────────────
msg "Configuring X11 autologin session"
sudo sed -i 's/autologin-session=rpd-labwc/autologin-session=rpd-x/' /etc/lightdm/lightdm.conf
# Add if line not present at all
grep -q "^autologin-session=" /etc/lightdm/lightdm.conf || \
    sudo sed -i '/^autologin-user=/a autologin-session=rpd-x' /etc/lightdm/lightdm.conf
ok "lightdm autologin session: rpd-x (X11)"

# ── Kiosk autostart ───────────────────────────────────────────────────────────
msg "Configuring kiosk autostart"
mkdir -p /home/pi/.config/lxsession/rpd-x
cp /home/pi/lxde-autostart /home/pi/.config/lxsession/rpd-x/autostart
ok "Kiosk autostart configured (~/.config/lxsession/rpd-x/autostart)"

# ── Desktop shortcut ──────────────────────────────────────────────────────────
mkdir -p /home/pi/Desktop
cp /home/pi/start-aprs.desktop /home/pi/Desktop/
chmod 644 /home/pi/Desktop/start-aprs.desktop
mkdir -p /home/pi/.config/libfm
printf '[config]\nquick_exec=1\n' > /home/pi/.config/libfm/libfm.conf
ok "Desktop shortcut installed"

# ── WiFi list ─────────────────────────────────────────────────────────────────
msg "Downloading WiFi list"
if [ -f /home/pi/.wifi-token ]; then
    if wget -qO /home/pi/wifi.yaml \
            --header="Pragma: no-cache" --header="Cache-Control: no-cache" \
            "$BASE/wifi/get.php?token=$(cat /home/pi/.wifi-token)" 2>/dev/null; then
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
sudo systemctl daemon-reload
sudo systemctl enable aprs-monitor.service
sudo systemctl enable kill-server.service
sudo systemctl enable stats-listener.service
sudo systemctl enable wifi-watchdog.service
sudo systemctl enable x11vnc.service
ok "Services enabled (start on next reboot)"

# ── Crontab ───────────────────────────────────────────────────────────────────
msg "Installing crontab"
crontab - << 'EOF'
# MARS APRS Display Pi
# Check every 5 minutes whether to enable/disable NetBird
*/5 * * * * /home/pi/check-netbird.sh >> /tmp/checknetbird.log 2>&1
# Enable NetBird after any reboot
@reboot /home/pi/netbird-up.sh
# Nightly auto-update at 4:01am
1 4 * * * /home/pi/auto-update.sh >> /home/pi/update.log 2>&1
# Nightly reboot at 4:10am (after updates)
10 4 * * * sudo reboot
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
sudo ufw allow 1235/udp   # StatsRequestListener
sudo ufw allow 5900/tcp   # x11vnc VNC
sudo ufw default deny incoming
sudo ufw --force enable
ok "UFW firewall enabled"

# ── File permissions ──────────────────────────────────────────────────────────
msg "Setting permissions"
sudo chown -R pi:pi /home/pi
sudo chmod 755 /home/pi
chmod +x /home/pi/*.sh /home/pi/*.php /home/pi/*.py 2>/dev/null || true
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
    echo "  To configure this display later:  /home/pi/configure.sh"
    echo "  To build a master image: shut down cleanly, then clone the SD card."
fi

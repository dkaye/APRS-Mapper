#!/usr/bin/env bash
# MARS APRS Server — Configuration Wizard
#
# Sets hostname, Cloudflare tunnel, and NetBird.
# Safe to run multiple times; press Enter to keep the current value.
#
# Usage:
#   /home/pi/configure.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

BOLD='\033[1m'
CYAN='\033[1;36m'
GREEN='\033[0;32m'
RED='\033[0;31m'
RESET='\033[0m'

header() { echo -e "\n${CYAN}══ $1 ══${RESET}"; }
ok()     { echo -e "${GREEN}✓ $1${RESET}"; }
err()    { echo -e "${RED}✗ $1${RESET}"; }
warn()   { echo -e "\033[0;33m⚠ $1${RESET}"; }
prompt() { echo -en "${BOLD}$1${RESET}"; }

cur_hostname=$(hostname)

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════╗${RESET}"
echo -e "${CYAN}║   APRS Server — Configuration            ║${RESET}"
echo -e "${CYAN}╚══════════════════════════════════════════╝${RESET}"
echo ""
echo "Press Enter at any prompt to keep the current value shown in [brackets]."
echo ""

# ── Hostname ──────────────────────────────────────────────────────────────────
header "Hostname"
echo "  System hostname. Must match the 'host' field in addresses.yaml (e.g. aprs)."
echo ""
while true; do
    prompt "  Hostname [${cur_hostname}]: "
    read -r INPUT
    HOSTNAME_NEW="${INPUT:-$cur_hostname}"
    if [[ "$HOSTNAME_NEW" =~ ^[A-Za-z0-9][A-Za-z0-9-]*$ ]]; then
        break
    else
        err "  Must contain only letters, digits, and hyphens. Try again."
    fi
done

# ── Cloudflare tunnel ─────────────────────────────────────────────────────────
header "Cloudflare Tunnel"
echo "  Routes marsaprs.org → this Pi via Cloudflare."
echo "  Get the tunnel token from Cloudflare Zero Trust dashboard:"
echo "    Networks → Tunnels → <tunnel> → Configure → Install and run connector"
echo "  Or press Enter to skip (configure later with: sudo cloudflared service install <token>)"
echo ""
CLOUDFLARED_RUNNING=$(systemctl is-active cloudflared 2>/dev/null || echo "inactive")
if [ "$CLOUDFLARED_RUNNING" = "active" ]; then
    echo "  cloudflared is already running."
    prompt "  Re-install with a new token? [y/N]: "
    read -r REDO_CF
    [[ "${REDO_CF,,}" == "y" ]] && prompt "  Tunnel token: " && read -rs CF_TOKEN && echo "" || CF_TOKEN=""
else
    prompt "  Tunnel token: "
    read -rs CF_TOKEN; echo ""
fi

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}══ Review — press Enter to apply, Ctrl-C to cancel ══${RESET}"
echo ""
echo "  Hostname:      $HOSTNAME_NEW"
[ -n "${CF_TOKEN:-}" ] && echo "  Cloudflare:    token provided" || echo "  Cloudflare:    skip"
echo ""
prompt "Apply these settings? [Y/n]: "
read -r CONFIRM
if [[ "${CONFIRM,,}" == "n" ]]; then
    echo "Cancelled. No changes made."
    exit 0
fi

# ── Apply hostname ────────────────────────────────────────────────────────────
OLD_HOSTNAME=$(hostname)
if [[ "$HOSTNAME_NEW" != "$OLD_HOSTNAME" ]]; then
    sudo /home/pi/set-hostname.sh "$HOSTNAME_NEW"
    ok "Hostname changed: $OLD_HOSTNAME → $HOSTNAME_NEW"
else
    ok "Hostname unchanged: $HOSTNAME_NEW"
fi

# ── Apply Cloudflare tunnel ───────────────────────────────────────────────────
if [ -n "${CF_TOKEN:-}" ]; then
    sudo cloudflared service install "$CF_TOKEN"
    sudo systemctl enable cloudflared
    sudo systemctl start cloudflared
    sleep 3
    if systemctl is-active --quiet cloudflared; then
        ok "Cloudflare tunnel running"
    else
        warn "cloudflared may not be connected — check: sudo systemctl status cloudflared"
    fi
fi

# ── NetBird VPN ───────────────────────────────────────────────────────────────
header "NetBird VPN"
if ! command -v netbird &>/dev/null; then
    echo "  NetBird is not installed. Installing now..."
    curl -fsSL https://pkgs.netbird.io/install.sh | sh
    ok "NetBird installed"
fi

NB_IP=$(netbird status 2>/dev/null | grep 'NetBird IP' | awk '{print $3}' | cut -d/ -f1)
if [ -n "$NB_IP" ]; then
    echo "  NetBird already connected: $NB_IP"
    prompt "  Re-enroll with a new setup key? [y/N]: "
    read -r REENROLL
    if [[ "${REENROLL,,}" == "y" ]]; then
        prompt "  Setup key: "; read -rs NETBIRD_KEY; echo ""
    else
        NETBIRD_KEY=""
    fi
else
    echo "  Enter the NetBird setup key, or press Enter to skip:"
    prompt "  Setup key: "; read -rs NETBIRD_KEY; echo ""
fi

if [ -n "${NETBIRD_KEY:-}" ]; then
    sudo systemctl enable rpcbind.socket rpcbind.service avahi-daemon
    sudo systemctl start  rpcbind.socket rpcbind.service avahi-daemon
    sudo systemctl enable --now systemd-timesyncd
    sudo timedatectl set-ntp true
    sudo netbird up -k "$NETBIRD_KEY"
    ok "NetBird enrolled and connected"
    netbird status 2>/dev/null | head -5
else
    warn "NetBird skipped — run later: sudo netbird up -k <setup-key>"
fi

# ── Start APRS daemon ─────────────────────────────────────────────────────────
header "Starting APRS daemon"
sudo systemctl start aprs-daemon
sleep 2
if systemctl is-active --quiet aprs-daemon; then
    ok "APRS daemon running"
else
    warn "APRS daemon failed to start — check: sudo systemctl status aprs-daemon"
fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}Configuration applied.${RESET}"
echo ""
echo "  Hostname:        $(hostname)"
IP=$(hostname -I | awk '{print $1}')
echo "  SSH:             ssh pi@${IP}"
echo "  marsaprs.org:    https://marsaprs.org  (via Cloudflare tunnel)"
NB_IP=$(netbird status 2>/dev/null | grep 'NetBird IP' | awk '{print $3}' | cut -d/ -f1)
[ -n "$NB_IP" ] && echo "  NetBird address: $NB_IP"
echo ""
echo "  Next steps if not done:"
echo "    sudo netbird up -k <setup-key>"
echo ""
prompt "Reboot now? [y/N]: "
read -r REBOOT
if [[ "${REBOOT,,}" == "y" ]]; then
    echo "Rebooting..."
    sudo reboot
fi

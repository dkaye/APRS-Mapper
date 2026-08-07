#!/usr/bin/env bash
# MARS APRS Display Pi — Configuration Wizard
#
# Sets the hostname and enrolls in NetBird.
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
echo -e "${CYAN}║   Display Pi — Configuration             ║${RESET}"
echo -e "${CYAN}╚══════════════════════════════════════════╝${RESET}"
echo ""
echo "Press Enter at any prompt to keep the current value shown in [brackets]."
echo ""

# ── Hostname ──────────────────────────────────────────────────────────────────
header "Hostname"
echo "  Hostname for this display device."
echo "  Use the same name as its entry in addresses.yaml (e.g. NetControl, BigTV)."
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

# ── Operator name ─────────────────────────────────────────────────────────────
# autologin.txt holds the operator the kiosk logs in as, and nothing kept it in
# step with the hostname: a display renamed on 2026-08-07 went on announcing
# itself to the app under its old name for the rest of the day, because the two
# are only ever set independently. Default it to the hostname, but let it differ
# — the operator is a messaging identity, not necessarily the machine name.
cur_operator=$(head -1 /home/pi/autologin.txt 2>/dev/null)
header "Operator name"
echo "  Name this display logs into marsaprs.org as (messaging auto-subscribe)."
echo "  Leave blank to use the hostname. Type - to disable autologin entirely."
echo ""
prompt "  Operator [${cur_operator:-$HOSTNAME_NEW}]: "
read -r INPUT
OPERATOR="${INPUT:-${cur_operator:-$HOSTNAME_NEW}}"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}══ Review — press Enter to apply, Ctrl-C to cancel ══${RESET}"
echo ""
echo "  Hostname: $HOSTNAME_NEW"
if [[ "$OPERATOR" == "-" ]]; then
    echo "  Operator: (autologin disabled)"
else
    echo "  Operator: $OPERATOR"
fi
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

# ── Apply operator name ───────────────────────────────────────────────────────
if [[ "$OPERATOR" == "-" ]]; then
    rm -f /home/pi/autologin.txt
    ok "Autologin disabled (autologin.txt removed)"
else
    printf '%s\n' "$OPERATOR" > /home/pi/autologin.txt
    ok "Operator set: $OPERATOR  (takes effect at the next kiosk start)"
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
    echo ""
    prompt "  Re-enroll with a new setup key? [y/N]: "
    read -r REENROLL
    [[ "${REENROLL,,}" != "y" ]] && NETBIRD_KEY="" || {
        prompt "  Setup key: "
        read -r NETBIRD_KEY
    }
else
    echo "  Enter the NetBird setup key (ask Doug), or press Enter to skip:"
    prompt "  Setup key: "
    read -r NETBIRD_KEY
fi

if [ -n "${NETBIRD_KEY:-}" ]; then
    sudo systemctl enable rpcbind.socket rpcbind.service avahi-daemon
    sudo systemctl start  rpcbind.socket rpcbind.service avahi-daemon
    sudo systemctl enable --now systemd-timesyncd
    sudo timedatectl set-ntp true
    sudo netbird up -k "$NETBIRD_KEY"
    echo ""
    ok "NetBird enrolled and connected"
    netbird status 2>/dev/null | head -5
else
    warn "NetBird skipped — run manually: sudo netbird up -k <setup-key>"
fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}Configuration applied.${RESET}"
echo ""
echo "  Hostname:        $(hostname)"
IP=$(hostname -I | awk '{print $1}')
echo "  SSH:             ssh pi@${IP}"
echo "  VNC:             vnc://$(hostname):5900  (password: guacamole)"
NB_IP=$(netbird status 2>/dev/null | grep 'NetBird IP' | awk '{print $3}' | cut -d/ -f1)
[ -n "$NB_IP" ] && echo "  NetBird address: $NB_IP"
echo ""
prompt "Reboot now? [y/N]: "
read -r REBOOT
if [[ "${REBOOT,,}" == "y" ]]; then
    echo "Rebooting..."
    sudo reboot
fi

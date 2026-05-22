#!/usr/bin/env bash
# iGate configuration wizard — K6DRK iGate v5.0
#
# Sets site-specific values in direwolf.conf and /var/www/html/config.php.
# Safe to run multiple times. Press Enter at any prompt to keep the current value.
#
# Usage:
#   /home/pi/configure.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

DIREWOLF_CONF="/home/pi/direwolf.conf"
WEB_CONFIG="/var/www/html/config.php"

# ── Colors ────────────────────────────────────────────────────────────────────
BOLD='\033[1m'
CYAN='\033[1;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
RESET='\033[0m'

header() { echo -e "\n${CYAN}══ $1 ══${RESET}"; }
ok()     { echo -e "${GREEN}✓ $1${RESET}"; }
err()    { echo -e "${RED}✗ $1${RESET}"; }
prompt() { echo -e "${BOLD}$1${RESET}"; }

# ── APRS passcode calculator ──────────────────────────────────────────────────
calc_passcode() {
    python3 -c "
c = '$1'.upper()
h = 0x73e2
for i in range(0, len(c), 2):
    h ^= ord(c[i]) << 8
    if i+1 < len(c): h ^= ord(c[i+1])
print(h & 0x7fff)
"
}

# ── Read current values from config files ─────────────────────────────────────
cur_callsign=$(grep    "^MYCALL "   "$DIREWOLF_CONF" 2>/dev/null | awk '{print $2}')
cur_iglogin_call=$(grep "^IGLOGIN " "$DIREWOLF_CONF" 2>/dev/null | awk '{print $2}')
cur_lat=$(grep        "^PBEACON " "$DIREWOLF_CONF" 2>/dev/null | grep -oP 'lat=\K[0-9.-]+')
cur_lon=$(grep        "^PBEACON " "$DIREWOLF_CONF" 2>/dev/null | grep -oP 'long=\K[0-9.-]+')
cur_location=$(grep   "^PBEACON " "$DIREWOLF_CONF" 2>/dev/null | grep -oP 'comment="iGate [^,]+, \K[^"]+')
cur_apikey=$(grep     'apikey'    "$WEB_CONFIG"    2>/dev/null | grep -oP '\$apikey = "\K[^"]+')

# ── Banner ────────────────────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}╔══════════════════════════════════════════╗${RESET}"
echo -e "${CYAN}║   iGate v5.0 — Configuration       ║${RESET}"
echo -e "${CYAN}╚══════════════════════════════════════════╝${RESET}"
echo ""
echo "Press Enter at any prompt to keep the current value shown in [brackets]."
echo ""

# ── Prompt: callsign / hostname ──────────────────────────────────────────────
header "Callsign / Hostname"
echo "  Your station callsign including the -N suffix (e.g. K6DRK-6 or MARS-5)."
echo "  Used as both MYCALL in direwolf.conf and the system hostname."
echo ""
while true; do
    prompt "  Callsign [${cur_callsign:-none}]: "
    read -r INPUT
    CALLSIGN="${INPUT:-$cur_callsign}"
    CALLSIGN="${CALLSIGN^^}"   # uppercase
    if [[ "$CALLSIGN" =~ ^[A-Z0-9]+-[0-9]+$ ]]; then
        break
    else
        err "  Must be in the form CALLSIGN-N (e.g. K6DRK-6). Try again."
    fi
done
HOSTNAME_NEW="$CALLSIGN"

echo ""
ok "  Callsign / Hostname: $CALLSIGN"

# ── Prompt: IGLOGIN callsign ──────────────────────────────────────────────────
header "IGLOGIN"
echo "  Callsign used to authenticate with the APRS-IS server."
echo "  Typically your callsign without the -N suffix (e.g. K6DRK)."
echo ""
DEFAULT_IGLOGIN="${cur_iglogin_call:-${CALLSIGN%-*}}"
while true; do
    prompt "  IGLOGIN callsign [${DEFAULT_IGLOGIN}]: "
    read -r INPUT
    IGLOGIN_CALL="${INPUT:-$DEFAULT_IGLOGIN}"
    IGLOGIN_CALL="${IGLOGIN_CALL^^}"
    if [[ "$IGLOGIN_CALL" =~ ^[A-Z0-9]+(-[0-9]+)?$ ]]; then
        break
    else
        err "  Must be a valid callsign (e.g. K6DRK). Try again."
    fi
done

# Derive base callsign for beacon comment and calculate passcode
BASE_CALL="${IGLOGIN_CALL%-*}"
PASSCODE=$(calc_passcode "$IGLOGIN_CALL")
echo ""
ok "  IGLOGIN callsign: $IGLOGIN_CALL"
ok "  APRS-IS passcode: $PASSCODE"

# ── Prompt: latitude ─────────────────────────────────────────────────────────
header "Location"
echo "  Decimal degrees. Positive = North, Negative = South."
echo "  Example: 37.955970"
echo ""
while true; do
    prompt "  Latitude [${cur_lat:-none}]: "
    read -r INPUT
    LAT="${INPUT:-$cur_lat}"
    if [[ "$LAT" =~ ^-?[0-9]+(\.[0-9]+)?$ ]] && \
       python3 -c "exit(0 if -90 <= float('$LAT') <= 90 else 1)" 2>/dev/null; then
        break
    else
        err "  Must be a decimal number between -90 and 90. Try again."
    fi
done

# ── Prompt: longitude ────────────────────────────────────────────────────────
echo ""
echo "  Decimal degrees. Positive = East, Negative = West."
echo "  Example: -122.544160"
echo ""
while true; do
    prompt "  Longitude [${cur_lon:-none}]: "
    read -r INPUT
    LON="${INPUT:-$cur_lon}"
    if [[ "$LON" =~ ^-?[0-9]+(\.[0-9]+)?$ ]] && \
       python3 -c "exit(0 if -180 <= float('$LON') <= 180 else 1)" 2>/dev/null; then
        break
    else
        err "  Must be a decimal number between -180 and 180. Try again."
    fi
done

# ── Prompt: location description ─────────────────────────────────────────────
echo ""
echo "  Short description of your location (city, landmark, etc.)."
echo "  Appears in your APRS beacon comment. Example: Richmond CA"
echo ""
while true; do
    prompt "  Location description [${cur_location:-none}]: "
    read -r INPUT
    LOCATION="${INPUT:-$cur_location}"
    if [ -n "$LOCATION" ]; then
        break
    else
        err "  Location cannot be empty. Try again."
    fi
done

# ── Prompt: aprs.fi API key ───────────────────────────────────────────────────
header "aprs.fi API Key"
echo "  Log in at https://aprs.fi/account/ and copy your API key."
echo ""
while true; do
    prompt "  aprs.fi API key [${cur_apikey:-none}]: "
    read -r INPUT
    APIKEY="${INPUT:-$cur_apikey}"
    if [ -n "$APIKEY" ] && [ "$APIKEY" != "CONFIGURE_ME" ]; then
        break
    else
        err "  API key cannot be empty. Try again."
    fi
done

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}══ Review — press Enter to apply, Ctrl-C to cancel ══${RESET}"
echo ""
echo "  Callsign / Hostname:    $CALLSIGN"
echo "  IGLOGIN:                $IGLOGIN_CALL $PASSCODE"
echo "  Latitude:               $LAT"
echo "  Longitude:              $LON"
echo "  Location:               $LOCATION"
echo "  Operator callsign:      $BASE_CALL"
echo "  aprs.fi API key:        $APIKEY"
echo ""
echo "  /etc/hostname → hostname"
echo "  direwolf.conf → MYCALL, IGLOGIN, PBEACON"
echo "  config.php    → sysopcallsign, stationlat, stationlon, apikey"
echo ""
read -rp "Apply these settings? [Y/n]: " CONFIRM
if [[ "${CONFIRM,,}" == "n" ]]; then
    echo "Cancelled. No changes made."
    exit 0
fi

# ── Backup ────────────────────────────────────────────────────────────────────
TS=$(date '+%Y%m%d-%H%M%S')
cp "$DIREWOLF_CONF" "${DIREWOLF_CONF}.bak-$TS"
sudo cp "$WEB_CONFIG" "${WEB_CONFIG}.bak-$TS"

# ── Apply hostname ────────────────────────────────────────────────────────────
OLD_HOSTNAME=$(hostname)
if [[ "$HOSTNAME_NEW" != "$OLD_HOSTNAME" ]]; then
    echo "$HOSTNAME_NEW" | sudo tee /etc/hostname > /dev/null
    sudo sed -i "s/\b${OLD_HOSTNAME}\b/${HOSTNAME_NEW}/g" /etc/hosts
    sudo hostnamectl set-hostname "$HOSTNAME_NEW"
    # cloud-init resets hostname on boot if user-data has a hostname line
    if grep -q "^hostname:" /boot/firmware/user-data 2>/dev/null; then
        sudo sed -i "s/^hostname:.*/hostname: ${HOSTNAME_NEW}/" /boot/firmware/user-data
    fi
    ok "Hostname changed: $OLD_HOSTNAME → $HOSTNAME_NEW"
fi

# ── Apply to direwolf.conf ────────────────────────────────────────────────────
sed -i "s|^MYCALL .*|MYCALL $CALLSIGN|" "$DIREWOLF_CONF"
sed -i "s|^IGLOGIN .*|IGLOGIN $IGLOGIN_CALL $PASSCODE|" "$DIREWOLF_CONF"
sed -i "s|lat=[^ ]*|lat=$LAT|g" "$DIREWOLF_CONF"
sed -i "s|long=[^ ]*|long=$LON|g" "$DIREWOLF_CONF"
sed -i "s|comment=\"[^\"]*\"|comment=\"iGate 5.0 by $BASE_CALL, $LOCATION\"|" "$DIREWOLF_CONF"

# ── Apply to config.php ───────────────────────────────────────────────────────
sudo sed -i "s|\(\$sysopcallsign = \)\"[^\"]*\"|\1\"$BASE_CALL\"|" "$WEB_CONFIG"
sudo sed -i "s|\(\$stationlat = \)[^;]*|\1$LAT|"                   "$WEB_CONFIG"
sudo sed -i "s|\(\$stationlon = \)[^;]*|\1$LON|"                   "$WEB_CONFIG"
sudo sed -i "s|\(\$apikey = \)\"[^\"]*\"|\1\"$APIKEY\"|"           "$WEB_CONFIG"

# ── Restart direwolf so it picks up the new config ───────────────────────────
echo ""
echo "Restarting direwolf..."
sudo systemctl restart direwolf.service
sleep 2
STATUS=$(systemctl is-active direwolf.service)
if [ "$STATUS" = "active" ]; then
    ok "Direwolf restarted successfully"
else
    err "Direwolf failed to start — check: sudo systemctl status direwolf.service"
fi

# ── NetBird VPN (skipped if already installed) ───────────────────────────────
if ! command -v netbird &>/dev/null; then
    header "NetBird VPN"
    echo "  NetBird is not installed. Installing now..."
    curl -fsSL https://pkgs.netbird.io/install.sh | sh
    ok "NetBird installed"
    echo ""
    echo "  Enter the NetBird setup key (ask Doug), or press Enter to skip:"
    prompt "  Setup key: "
    read -r NETBIRD_KEY
    if [ -n "$NETBIRD_KEY" ]; then
        sudo systemctl enable rpcbind.socket rpcbind.service avahi-daemon
        sudo systemctl start rpcbind.socket rpcbind.service avahi-daemon
        sudo systemctl enable --now systemd-timesyncd
        sudo timedatectl set-ntp true
        sudo netbird up --enable-lazy-connection -k "$NETBIRD_KEY"
        echo ""
        ok "NetBird enrolled and connected"
        netbird status | head -5
    else
        warn "NetBird skipped — run manually: sudo netbird up -k <setup-key>"
    fi
fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}Configuration applied.${RESET}"
echo ""
echo "  Verify direwolf:   sudo systemctl status direwolf.service"
echo "  View beacon log:   tail -f /var/log/direwolf/console.log"
echo "  Web dashboard:     http://$(hostname -I | awk '{print $1}')"
if command -v netbird &>/dev/null; then
    NB_IP=$(netbird status 2>/dev/null | grep 'NetBird IP' | awk '{print $3}' | cut -d/ -f1)
    [ -n "$NB_IP" ] && echo "  NetBird address:   $NB_IP"
fi
echo ""
echo "  Backups saved:"
echo "    ${DIREWOLF_CONF}.bak-$TS"
echo "    ${WEB_CONFIG}.bak-$TS"
echo ""
read -rp "Reboot now? [y/N]: " REBOOT
if [[ "${REBOOT,,}" == "y" ]]; then
    echo "Rebooting..."
    sudo reboot
fi

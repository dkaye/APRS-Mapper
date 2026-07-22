#!/usr/bin/env bash
# iGate configuration wizard — K6DRK iGate v5.1
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

# Ensure all interactive prompts read from the terminal even when stdin is a pipe
exec < /dev/tty

# ── Bootstrap: ensure current hostname is in /etc/hosts ──────────────────────
# Pi Imager sets /etc/hostname but not /etc/hosts; sudo warns "unable to resolve host"
_bh=$(hostname)
if ! grep -qE "^\S+\s+${_bh}(\s|$)" /etc/hosts 2>/dev/null; then
    echo "127.0.1.1 $_bh" | sudo tee -a /etc/hosts > /dev/null
fi
unset _bh

# ── Bootstrap: ensure direwolf.conf has the expected template lines ───────────
if ! grep -q "^MYCALL " "$DIREWOLF_CONF" 2>/dev/null; then
    echo "direwolf.conf is missing or empty — downloading template..."
    wget -qO "$DIREWOLF_CONF" "https://marsaprs.org/igate/templates/direwolf.conf.template" 2>/dev/null \
        || { echo "Download failed. Is the iGate connected to the internet?"; exit 1; }
    echo "Template installed."
fi

# ── Bootstrap: ensure log directory exists ────────────────────────────────────
if [ ! -d /var/log/direwolf ]; then
    sudo mkdir -p /var/log/direwolf
    sudo chown pi:pi /var/log/direwolf
fi

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

# ── APRS-IS filter validator + explainer ─────────────────────────────────────
# Prints a plain-English breakdown. Exits 1 (with error messages) if invalid.
check_filter() {
    python3 - "$1" << 'PYEOF'
import sys, re

expr = sys.argv[1].strip() if len(sys.argv) > 1 else ''
if not expr:
    print("    (no filter — all received packets are forwarded)")
    sys.exit(0)

TYPE_NAMES = {
    'p': 'position', 'o': 'object', 'm': 'message', 'w': 'weather',
    's': 'status',   't': 'telemetry', 'i': 'item',  'n': 'NWS weather',
    'q': 'query',    'u': 'user-defined',
}
VALID_PREFIXES = ('t/', 'd/', 'b/', 'r/', 'f/', 's/')

errors = []

def validate_token(raw):
    tok = raw.strip()
    if not tok:
        return None
    neg = tok.startswith('!')
    base = tok[1:].strip() if neg else tok

    if not any(base.startswith(p) for p in VALID_PREFIXES):
        errors.append(f"Unknown filter type '{base}' (valid: t/ d/ b/ r/ f/ s/)")
        return None

    if base.startswith('t/'):
        codes = base[2:].replace('/', '')
        if not codes:
            errors.append("t/ needs type letters, e.g. t/p or t/pom")
            return None
        bad = [c for c in codes if c not in TYPE_NAMES]
        if bad:
            errors.append(f"Unknown type code(s) '{''.join(bad)}' in '{base}' "
                          f"(valid: {' '.join(sorted(TYPE_NAMES))})")
            return None
        names = ', '.join(TYPE_NAMES[c] for c in codes)
        desc = f"only {names} packets"
        return ("exclude " + names + " packets") if neg else desc

    if base.startswith('d/'):
        via = base[2:]
        noun = ('any station' if via in ('*', '') else
                ', '.join(via.split('/')))
        return (f"exclude packets digipeated via {noun}") if neg else (f"include packets digipeated via {noun}")

    if base.startswith('b/'):
        calls = base[2:]
        if not calls:
            errors.append("b/ needs at least one callsign, e.g. b/K6DRK")
            return None
        stations = ', '.join(calls.split('/'))
        return (f"exclude stations: {stations}") if neg else (f"only from: {stations}")

    if base.startswith('r/'):
        parts = base[2:].split('/')
        if len(parts) < 3:
            errors.append(f"r/ needs lat/lon/dist, e.g. r/37.9/-122.5/50")
            return None
        try:
            float(parts[0]); float(parts[1]); float(parts[2])
        except ValueError:
            errors.append(f"r/ lat/lon/dist must be numbers: '{base}'")
            return None
        desc = f"within {parts[2]} km of {parts[0]}, {parts[1]}"
        return ("exclude " + desc) if neg else ("only " + desc)

    if base.startswith('f/'):
        parts = base[2:].split('/')
        if len(parts) < 2:
            errors.append(f"f/ needs call/dist, e.g. f/K6DRK/50")
            return None
        desc = f"within {parts[1]} km of {parts[0]}"
        return ("exclude " + desc) if neg else ("only " + desc)

    if base.startswith('s/'):
        sym = base[2:]
        return (f"exclude symbol {sym}") if neg else (f"symbol {sym} only")

    return None

parts  = re.split(r'\s*([&|])\s*', expr)
lines  = []
cur_op = None
for p in parts:
    p = p.strip()
    if p in ('&', '|'):
        cur_op = 'AND' if p == '&' else 'OR'
    elif p:
        desc = validate_token(p)
        if desc is not None:
            lines.append((cur_op, desc))

if errors:
    print("    Errors in filter expression:")
    for e in errors:
        print(f"      • {e}")
    sys.exit(1)

print("    This means:")
for i, (op, desc) in enumerate(lines):
    bullet = '    •' if i == 0 else f'    {op}'
    print(f"{bullet} {desc}")
sys.exit(0)
PYEOF
}

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
cur_filter=$(grep "^FILTER " "$DIREWOLF_CONF" 2>/dev/null | sed 's/^FILTER[[:space:]]*0[[:space:]]*IG[[:space:]]*//')

# ── Banner ────────────────────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}╔══════════════════════════════════════════╗${RESET}"
echo -e "${CYAN}║        iGate v5.1 — Configuration        ║${RESET}"
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

# ── Prompt: APRS-IS filter ───────────────────────────────────────────────────
header "APRS-IS Filter"
echo "  Controls which received packets are forwarded to the APRS-IS server."
echo "  See: https://groups.io/g/direwolf/topic/igate_filtering/118685542"
echo ""
echo "  Edit the expression below, or clear the line to remove the filter."
echo ""
FILTER_EXPR="$cur_filter"
while true; do
    if [ -n "$FILTER_EXPR" ]; then
        check_filter "$FILTER_EXPR"
    else
        echo "  (no filter — all received packets are forwarded to APRS-IS)"
    fi
    echo ""
    read -re -i "$FILTER_EXPR" -p "  Filter: " INPUT
    INPUT="$(printf '%s' "$INPUT" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    if [ -z "$INPUT" ]; then
        if [ -n "$FILTER_EXPR" ]; then
            read -rp "  Remove the filter (all packets forwarded)? [y/N]: " CONFIRM
            [[ "${CONFIRM,,}" == "y" ]] || { echo ""; continue; }
            FILTER_EXPR=""
        fi
        echo ""
        ok "  No filter — all packets forwarded."
        break
    elif [ "$INPUT" = "$FILTER_EXPR" ]; then
        echo ""
        ok "  APRS-IS filter unchanged."
        break
    else
        echo ""
        if check_filter "$INPUT"; then
            echo ""
            read -rp "  Accept this filter? [Y/n]: " FILTER_CONFIRM
            if [[ "${FILTER_CONFIRM,,}" == "n" ]]; then
                echo ""
                continue
            fi
            FILTER_EXPR="$INPUT"
            echo ""
            ok "  Filter updated."
            break
        else
            echo ""
            err "  Invalid filter expression — please try again."
            echo ""
        fi
    fi
done

# ── Prompt: latitude (also accepts pasted "lat, lon") ────────────────────────
header "Location"
echo "  Decimal degrees. Positive = North, Negative = South."
echo "  Tip: paste 'lat, lon' here to fill both fields at once."
echo "  Example: 37.955970  or  37.955970, -122.544160"
echo ""
LON_PASTED=""
while true; do
    prompt "  Latitude [${cur_lat:-none}]: "
    read -re INPUT
    # Check for combined lat,lon paste (comma / semicolon / slash / space-separated)
    PARSED=$(echo "$INPUT" | python3 -c "
import re, sys
s = sys.stdin.read().strip()
m = re.match(r'^([+\-]?[0-9]+\.?[0-9]*)\s*[,;/\t]\s*([+\-]?[0-9]+\.?[0-9]*)$', s)
if not m:
    parts = re.split(r'\s+(?=[+\-]?\d)', s)
    if len(parts) == 2:
        m2 = re.fullmatch(r'[+\-]?[0-9]+\.?[0-9]*', parts[0].strip())
        m3 = re.fullmatch(r'[+\-]?[0-9]+\.?[0-9]*', parts[1].strip())
        if m2 and m3: m = type('M', (), {'group': lambda self,i: parts[i-1].strip()})()
if m:
    lat, lon = float(m.group(1)), float(m.group(2))
    if -90 <= lat <= 90 and -180 <= lon <= 180:
        print(lat, lon)
" 2>/dev/null)
    if [ -n "$PARSED" ]; then
        LAT=$(echo "$PARSED" | cut -d' ' -f1)
        LON_PASTED=$(echo "$PARSED" | cut -d' ' -f2)
        break
    fi
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
if [ -n "$LON_PASTED" ]; then
    LON="$LON_PASTED"
    echo "  Longitude set from paste: $LON"
else
    while true; do
        prompt "  Longitude [${cur_lon:-none}]: "
        read -re INPUT
        LON="${INPUT:-$cur_lon}"
        if [[ "$LON" =~ ^-?[0-9]+(\.[0-9]+)?$ ]] && \
           python3 -c "exit(0 if -180 <= float('$LON') <= 180 else 1)" 2>/dev/null; then
            break
        else
            err "  Must be a decimal number between -180 and 180. Try again."
        fi
    done
fi

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
echo "  APRS-IS filter:         ${FILTER_EXPR:-(none)}"
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

# ── Backup ────────────────────────────────────────────────────────────────────
TS=$(date '+%Y%m%d-%H%M%S')
cp "$DIREWOLF_CONF" "${DIREWOLF_CONF}.bak-$TS"
sudo cp "$WEB_CONFIG" "${WEB_CONFIG}.bak-$TS"

# ── Apply to direwolf.conf ────────────────────────────────────────────────────
sed -i "s|^MYCALL .*|MYCALL $CALLSIGN|" "$DIREWOLF_CONF"
sed -i "s|^IGLOGIN .*|IGLOGIN $IGLOGIN_CALL $PASSCODE|" "$DIREWOLF_CONF"
sed -i "s|lat=[^ ]*|lat=$LAT|g" "$DIREWOLF_CONF"
sed -i "s|long=[^ ]*|long=$LON|g" "$DIREWOLF_CONF"
sed -i "s|comment=\"[^\"]*\"|comment=\"iGate 5.1 by $BASE_CALL, $LOCATION\"|" "$DIREWOLF_CONF"
if [ -n "$FILTER_EXPR" ]; then
    # Escape & and \ so sed doesn't treat them as metacharacters in replacement
    FILTER_SAFE="${FILTER_EXPR//\\/\\\\}"
    FILTER_SAFE="${FILTER_SAFE//&/\\&}"
    if grep -q "^FILTER " "$DIREWOLF_CONF"; then
        sed -i "s|^FILTER[[:space:]].*|FILTER  0  IG  $FILTER_SAFE|" "$DIREWOLF_CONF"
    else
        echo "FILTER  0  IG  $FILTER_EXPR" >> "$DIREWOLF_CONF"
    fi
else
    sed -i "/^FILTER /d" "$DIREWOLF_CONF"
fi

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
        sudo netbird up -k "$NETBIRD_KEY"
        echo ""
        ok "NetBird enrolled and connected"
        netbird status | head -5
    else
        warn "NetBird skipped — run manually: sudo netbird up -k <setup-key>"
    fi
fi

# ── Disable unnecessary audio stack (not needed on headless iGate) ───────────
systemctl --user stop wireplumber pipewire pipewire.socket 2>/dev/null
systemctl --user disable wireplumber pipewire pipewire.socket 2>/dev/null

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

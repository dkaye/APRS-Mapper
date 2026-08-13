#!/usr/bin/env bash
# Transcriber configuration wizard — v1.0
#
# Everything a Transcriber needs that is specific to this box: its hostname, its NetBird
# membership, its device token, and the USB serial of each dongle. install.sh builds the
# machine; this makes it a particular receiver.
#
# Safe to run repeatedly. Press Enter at any prompt to keep the value in [brackets].
#
# Usage:
#   /home/pi/configure.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

TOKEN_FILE="/home/pi/.transcriber-token"
MANAGER="https://marsaprs.org/transcriber/"

# Read prompts from the terminal even when this script arrived down a pipe.
exec < /dev/tty

# ── Colors ────────────────────────────────────────────────────────────────────
BOLD='\033[1m'
CYAN='\033[1;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
RESET='\033[0m'

header() { echo -e "\n${CYAN}══ $1 ══${RESET}"; }
ok()     { echo -e "${GREEN}✓ $1${RESET}"; }
warn()   { echo -e "${YELLOW}! $1${RESET}"; }
err()    { echo -e "${RED}✗ $1${RESET}"; }
prompt() { echo -e "${BOLD}$1${RESET}"; }

# ── Bootstrap: hostname must resolve ─────────────────────────────────────────
# Pi Imager writes /etc/hostname but not /etc/hosts, and every sudo then prints
# "unable to resolve host" — noise that hides real errors for the whole session.
_bh=$(hostname)
if ! grep -qE "^\S+\s+${_bh}(\s|$)" /etc/hosts 2>/dev/null; then
    echo "127.0.1.1 $_bh" | sudo tee -a /etc/hosts > /dev/null
fi
unset _bh

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════╗${RESET}"
echo -e "${CYAN}║      Transcriber v1.0 — Configuration    ║${RESET}"
echo -e "${CYAN}╚══════════════════════════════════════════╝${RESET}"
echo ""
echo "Press Enter at any prompt to keep the current value shown in [brackets]."

# ── Hostname ─────────────────────────────────────────────────────────────────
# Not cosmetic. The hostname IS this device's identity in the channel manager:
# auto-update.sh fetches with ?device=$(hostname), and the server matches it against the
# Host column. A mismatch is not an error anybody sees — the device asks for channels,
# is told it has none, and sits there healthy and deaf.
header "Hostname"
echo "  How this Pi identifies itself when it collects its settings. It must match"
echo "  the Host column for this device in the manager, exactly."
echo ""
echo "  Suggestion: name it for where it lives — transcriber-westmarin, rx-basecamp."
echo ""
cur_hostname=$(hostname)
while true; do
    prompt "  Hostname [${cur_hostname}]: "
    read -r INPUT
    HOSTNAME_NEW="${INPUT:-$cur_hostname}"
    if [[ "$HOSTNAME_NEW" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,62}$ ]]; then
        break
    else
        err "  Letters, digits and hyphens only, starting with a letter or digit."
    fi
done
ok "  Hostname: $HOSTNAME_NEW"

# ── Device token ─────────────────────────────────────────────────────────────
header "Device token"
echo "  Add this device in the manager, then use its \"New…\" button under Config"
echo "  token and paste the value here. It only ever fetches configuration — it"
echo "  cannot write to the log, which is what the separate channel tokens do."
echo ""
echo "  Manager: $MANAGER"
echo ""
cur_token=""
if [ -s "$TOKEN_FILE" ]; then
    # Never echo the token itself — this runs over SSH and scrolls into somebody's
    # terminal history. Its length is enough to tell "installed" from "empty file".
    _tok=$(cat "$TOKEN_FILE")
    cur_token="installed, ${#_tok} characters"
    unset _tok
    echo "  A token is already installed. Press Enter to keep it."
    echo ""
fi
prompt "  Device token [${cur_token:-none}]: "
read -r NEW_TOKEN
if [ -n "$NEW_TOKEN" ]; then
    # 0600 and owned by pi. It is a credential, and the registry it fetches from is
    # deliberately outside the web root for the same reason.
    printf '%s' "$NEW_TOKEN" > "$TOKEN_FILE"
    chmod 600 "$TOKEN_FILE"
    chown pi:pi "$TOKEN_FILE" 2>/dev/null || sudo chown pi:pi "$TOKEN_FILE"
    ok "  Token written to $TOKEN_FILE"
elif [ -s "$TOKEN_FILE" ]; then
    ok "  Keeping the existing token"
else
    warn "  No token — this device cannot collect its channels until one is set."
fi

# ── NetBird ──────────────────────────────────────────────────────────────────
# Unlike the iGates and displays, a Transcriber's NetBird stays up permanently. Those
# devices toggle it from the server (check-netbird.sh, every five minutes) because they
# go out to sites on metered or marginal links where a VPN is worth switching off. A
# Transcriber is remote-managed by definition — its whole configuration arrives over the
# network — so there is no toggle here, no netbird-up.sh, and no cron entry.
header "NetBird VPN"
if ! command -v netbird &>/dev/null; then
    echo "  NetBird is not installed. Installing now..."
    if curl -fsSL https://pkgs.netbird.io/install.sh | sh; then
        ok "  NetBird installed"
    else
        err "  NetBird install failed — carry on and run it later by hand."
    fi
fi

if command -v netbird &>/dev/null; then
    NB_STATUS=$(sudo netbird status 2>/dev/null | grep -i 'Management' | head -1)
    if echo "$NB_STATUS" | grep -qi 'connected'; then
        ok "  Already enrolled and connected"
    else
        echo ""
        echo "  Enter the NetBird setup key (ask Doug), or press Enter to skip:"
        prompt "  Setup key: "
        read -r NETBIRD_KEY
        if [ -n "$NETBIRD_KEY" ]; then
            # Same prerequisites the iGates enable. timesyncd is the one that matters:
            # enrollment is a TLS handshake, and a Pi with no RTC boots in 1970 until
            # something sets the clock, which fails in a way that reads as a bad key.
            sudo systemctl enable rpcbind.socket rpcbind.service avahi-daemon 2>/dev/null
            sudo systemctl start  rpcbind.socket rpcbind.service avahi-daemon 2>/dev/null
            sudo systemctl enable --now systemd-timesyncd 2>/dev/null
            sudo timedatectl set-ntp true 2>/dev/null
            if sudo netbird up -k "$NETBIRD_KEY"; then
                ok "  NetBird enrolled and connected"
            else
                err "  Enrollment failed — check the key and try: sudo netbird up -k <key>"
            fi
        else
            warn "  Skipped — run later: sudo netbird up -k <setup-key>"
        fi
    fi

    # Always-on, so it is back after a reboot without anything having to notice.
    sudo systemctl enable netbird &>/dev/null && ok "  NetBird set to start at boot"
fi

# ── Dongle serials ───────────────────────────────────────────────────────────
# Channels address dongles by SERIAL, never by index: index order is not stable across
# reboots or re-plugs, and two channels silently swapping frequencies is the kind of
# fault nobody notices until the log is already wrong. A new dongle ships with the same
# serial as every other one of its model, so with two fitted this is not optional.
header "SDR dongles"
if command -v rtl_test &>/dev/null; then
    echo "  Detected:"
    echo ""
    # rtl_test lists devices and then starts a tuner test that never ends; the list is
    # all we want, so take it and stop.
    DONGLES=$(timeout 3 rtl_test 2>&1 | sed -n '/Found .* device/,/^$/p' | grep -E '^\s+[0-9]+:' || true)
    if [ -n "$DONGLES" ]; then
        echo "$DONGLES" | sed 's/^/  /'
    else
        warn "  None found. Check they are on the USB 2.0 ports and re-plugged."
    fi
    echo ""
    echo "  Each channel in the manager names one of these serials. Two dongles"
    echo "  fresh from the box share a serial and must be told apart first."
    echo ""
    read -rp "  Set a dongle's serial now? [y/N]: " SET_SERIAL
    while [[ "${SET_SERIAL,,}" == "y" ]]; do
        prompt "  Device index (the number before the colon above): "
        read -r DEV_INDEX
        prompt "  New serial (e.g. 00000001): "
        read -r DEV_SERIAL
        if [[ "$DEV_INDEX" =~ ^[0-9]+$ ]] && [ -n "$DEV_SERIAL" ]; then
            # rtl_eeprom cannot open a dongle another process is holding.
            sudo systemctl stop 'transcriber@*' 2>/dev/null
            sudo rtl_eeprom -d "$DEV_INDEX" -s "$DEV_SERIAL"
            echo ""
            warn "  Unplug and re-plug that dongle now for the new serial to take effect."
            read -rp "  Press Enter once you have..." _
        else
            err "  Need a numeric index and a non-empty serial."
        fi
        echo ""
        read -rp "  Set another? [y/N]: " SET_SERIAL
    done
else
    warn "  rtl_test not found — run install.sh first."
fi

# ── Apply hostname ───────────────────────────────────────────────────────────
OLD_HOSTNAME=$(hostname)
if [[ "$HOSTNAME_NEW" != "$OLD_HOSTNAME" ]]; then
    echo "$HOSTNAME_NEW" | sudo tee /etc/hostname > /dev/null
    sudo sed -i "s/\b${OLD_HOSTNAME}\b/${HOSTNAME_NEW}/g" /etc/hosts
    sudo hostnamectl set-hostname "$HOSTNAME_NEW"
    # cloud-init puts the hostname back on every boot if user-data carries one.
    if grep -q "^hostname:" /boot/firmware/user-data 2>/dev/null; then
        sudo sed -i "s/^hostname:.*/hostname: ${HOSTNAME_NEW}/" /boot/firmware/user-data
    fi
    ok "Hostname changed: $OLD_HOSTNAME → $HOSTNAME_NEW"
fi

# ── Collect channels and start ───────────────────────────────────────────────
header "Channels"
if [ -s "$TOKEN_FILE" ]; then
    echo "  Collecting this device's channels from the manager..."
    echo ""
    sudo /home/pi/auto-update.sh 2>&1 | sed 's/^/  /'
else
    warn "  No device token, so there is nothing to collect yet."
    echo "  Add this device at $MANAGER, then re-run this script."
fi

# ── Done ─────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}Configuration applied.${RESET}"
echo ""
echo "  Channels:          systemctl status 'transcriber@*'"
echo "  Watch one live:    journalctl -u 'transcriber@*' -f"
echo "  Health:            vcgencmd get_throttled     (0x0 is healthy)"
echo "  Manager:           $MANAGER"
if command -v netbird &>/dev/null; then
    NB_IP=$(sudo netbird status 2>/dev/null | grep 'NetBird IP' | awk '{print $3}' | cut -d/ -f1)
    [ -n "$NB_IP" ] && echo "  NetBird address:   $NB_IP"
fi
echo ""
if [[ "$HOSTNAME_NEW" != "$OLD_HOSTNAME" ]]; then
    read -rp "The hostname changed and needs a reboot. Reboot now? [Y/n]: " REBOOT
    [[ "${REBOOT,,}" == "n" ]] || { echo "Rebooting..."; sudo reboot; }
fi

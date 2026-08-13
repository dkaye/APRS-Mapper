#!/usr/bin/env bash
# Transcriber one-time install — Raspberry Pi 4.
#
# Turns a fresh Pi OS Lite install into a Transcriber: SDR tools, whisper.cpp built
# for this CPU, the channel worker, and a nightly update. Idempotent — safe to re-run.
#
# Usage:
#   curl -fsSL https://marsaprs.org/transcriber/install.sh | sudo bash
#
# Afterwards:
#   1. Set each dongle's USB serial:  rtl_eeprom -d 0 -s 00000001   (then re-plug)
#   2. Put the device token in /home/pi/.transcriber-token
#   3. Add the channels in the manager at marsaprs.org/transcriber/
#   4. sudo /home/pi/auto-update.sh
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo." >&2; exit 1; }

BASE="https://marsaprs.org/transcriber"
MODEL_BASE="https://huggingface.co/ggerganov/whisper.cpp/resolve/main"
MODELS="/opt/transcriber/models"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "=== Transcriber install ==="

# ── packages ─────────────────────────────────────────────────────────────────
# rtl-sdr gives rtl_fm and rtl_eeprom; sox does the silence splitting; the build
# tools are for whisper.cpp, which has no usable ARM package.
apt-get update -qq
apt-get install -y --no-install-recommends \
    rtl-sdr sox libsox-fmt-all curl git build-essential cmake python3

# The DVB-T driver claims the dongle on plug-in and rtl_fm then cannot open it.
# Blacklisting is the standard fix and is what the iGates do.
cat > /etc/modprobe.d/blacklist-rtl.conf <<'EOF'
blacklist dvb_usb_rtl28xxu
blacklist rtl2832
blacklist rtl2830
EOF

# ── whisper.cpp ──────────────────────────────────────────────────────────────
# Built here rather than shipped: it wants the host's NEON support, and a binary
# compiled elsewhere is the sort of thing that runs at a third of the speed for
# reasons nobody thinks to check.
if [ ! -x /opt/transcriber/bin/whisper-cli ]; then
    echo "Building whisper.cpp (several minutes)..."
    git clone --depth 1 https://github.com/ggerganov/whisper.cpp "$TMP/whisper"
    cmake -S "$TMP/whisper" -B "$TMP/whisper/build" -DCMAKE_BUILD_TYPE=Release >/dev/null
    cmake --build "$TMP/whisper/build" --config Release -j"$(nproc)" >/dev/null
    mkdir -p /opt/transcriber/bin
    install -m 755 "$TMP/whisper/build/bin/whisper-cli" /opt/transcriber/bin/
else
    echo "whisper.cpp already built; leaving it alone"
fi

# ── models ───────────────────────────────────────────────────────────────────
# tiny.en by default: on a Pi 4 it keeps up with bursty traffic in real time, and
# base.en (also fetched) can be selected per channel where accuracy matters more
# than latency. auto-update.sh never touches these — they are large and static.
mkdir -p "$MODELS"
for m in tiny.en base.en; do
    if [ ! -s "$MODELS/ggml-$m.bin" ]; then
        echo "Fetching model $m..."
        curl -fsSL --retry 3 -o "$MODELS/ggml-$m.bin" "$MODEL_BASE/ggml-$m.bin"
    fi
done

# ── directories ──────────────────────────────────────────────────────────────
mkdir -p /opt/transcriber/bin /etc/transcriber /var/spool/transcriber /var/log/transcriber
chown -R pi:pi /var/spool/transcriber /var/log/transcriber

# ── files ────────────────────────────────────────────────────────────────────
echo "Installing worker and units..."
curl -fsSL --retry 3 -o "$TMP/files.tar.gz" "$BASE/files.tar.gz"
tar -xzf "$TMP/files.tar.gz" -C "$TMP"
rsync -a --ignore-times "$TMP/bin/"     /opt/transcriber/bin/
rsync -a --ignore-times "$TMP/systemd/" /etc/systemd/system/
[ -d "$TMP/udev" ] && rsync -a --ignore-times "$TMP/udev/" /etc/udev/rules.d/ || true
chmod +x /opt/transcriber/bin/*.py

# Seed an example config so the worker has something to explain itself with before
# the device is enrolled. auto-update.sh replaces it with the real list.
[ -f /etc/transcriber/channels.json ] || \
    install -m 640 -o root -g pi "$TMP/etc/transcriber/channels.json.example" \
                                 /etc/transcriber/channels.json

curl -fsSL --retry 3 -o /home/pi/auto-update.sh "$BASE/auto-update.sh"
chmod +x /home/pi/auto-update.sh
chown pi:pi /home/pi/auto-update.sh

# ── nightly update ───────────────────────────────────────────────────────────
# 4:11am — deliberately not 4:01, when every iGate hits the server at once.
cat > /etc/cron.d/transcriber <<'EOF'
11 4 * * * root /home/pi/auto-update.sh >/dev/null 2>&1
EOF

# ── log rotation ─────────────────────────────────────────────────────────────
cat > /etc/logrotate.d/transcriber <<'EOF'
/var/log/transcriber/*.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    copytruncate
}
EOF

systemctl daemon-reload
udevadm control --reload-rules 2>/dev/null || true

echo
echo "=== Installed ==="
echo
echo "Next:"
echo "  1. Set each dongle's serial:   rtl_eeprom -d 0 -s 00000001   (then re-plug)"
echo "     Serials, not indexes — index order is not stable across reboots."
echo "  2. echo <device-token> > /home/pi/.transcriber-token"
echo "  3. Add this device's channels at https://marsaprs.org/transcriber/"
echo "  4. sudo /home/pi/auto-update.sh"
echo
echo "Then:  systemctl status 'transcriber@*'"
echo "       journalctl -u 'transcriber@*' -f"

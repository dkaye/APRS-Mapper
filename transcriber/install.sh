#!/usr/bin/env bash
# Transcriber v1.0 one-time install — Raspberry Pi 4.
#
# Turns a fresh Pi OS Lite install into a Transcriber: SDR tools, whisper.cpp built
# for this CPU, the channel worker, and a nightly update. Idempotent — safe to re-run.
#
# Usage:
#   curl -fsSL https://marsaprs.org/transcriber/install.sh | sudo bash
#
# This script builds the machine and knows nothing about which receiver it is.
# Everything site-specific — hostname, NetBird, device token, dongle serials — is
# configure.sh, which this installs and which you run next.
#
# Afterwards:
#   sudo /home/pi/configure.sh
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

echo "=== Transcriber v1.0 install ==="

# ── packages ─────────────────────────────────────────────────────────────────
# rtl-sdr gives rtl_fm and rtl_eeprom; sox does the silence splitting; the build
# tools are for whisper.cpp, which has no usable ARM package.
apt-get update -qq
# ffmpeg encodes the clip a channel sends with its log entry. AAC rather than Opus, and
# not because Opus is worse: Apple does not decode Ogg Opus through AVFoundation, which
# is what the phone app's player uses on iOS, so on this fleet Opus is the codec that
# might not play at all. See AUDIO_BITRATE in transcriber.py.
apt-get install -y --no-install-recommends \
    rtl-sdr sox libsox-fmt-all curl git build-essential cmake python3 ffmpeg

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
# "Does it run" is not the same question as "was it built for this machine", and the
# difference is invisible until somebody measures it. An aarch64 binary compiled on a
# Pi 4 runs perfectly well on a Pi 5 — it just runs a Cortex-A72 build on a Cortex-A76,
# giving back much of what the new board was bought for, with nothing anywhere to say
# so. That happened on the first Pi 5 migration: install.sh reported "already installed
# and working" and skipped the rebuild.
#
# So the stamp records the CPU it was built for, and a mismatch is a rebuild. The model
# string is what changes across boards; a missing stamp means a build that predates this
# check, which is also worth redoing once.
WHISPER_STAMP=/usr/local/lib/whisper-built-for
THIS_CPU=$(tr -d '\0' < /proc/device-tree/model 2>/dev/null || uname -m)
if [ -f "$WHISPER_STAMP" ] && [ "$(cat "$WHISPER_STAMP")" != "$THIS_CPU" ]; then
    echo "whisper.cpp was built for '$(cat "$WHISPER_STAMP")', this is '$THIS_CPU' — rebuilding"
    rm -f /usr/local/bin/whisper-cli /usr/local/lib/libwhisper* /usr/local/lib/libggml*
    ldconfig
elif [ ! -f "$WHISPER_STAMP" ] && [ -x /usr/local/bin/whisper-cli ]; then
    echo "whisper.cpp has no build stamp — rebuilding once so it is known to match"
    rm -f /usr/local/bin/whisper-cli /usr/local/lib/libwhisper* /usr/local/lib/libggml*
    ldconfig
fi

if ! /usr/local/bin/whisper-cli -h >/dev/null 2>&1; then
    echo "Building whisper.cpp (several minutes)..."
    git clone --depth 1 https://github.com/ggerganov/whisper.cpp "$TMP/whisper"
    cmake -S "$TMP/whisper" -B "$TMP/whisper/build" -DCMAKE_BUILD_TYPE=Release >/dev/null
    cmake --build "$TMP/whisper/build" --config Release -j"$(nproc)" >/dev/null

    # `cmake --install`, not a copy of the binary. whisper-cli links against
    # libwhisper.so and libggml*.so, which the first version of this left behind in a
    # build tree that the EXIT trap then deleted. The result passed every check anyone
    # would think to run — the binary was present, executable and the right size — and
    # failed only at the moment it was asked to transcribe, where the worker treated
    # the empty output as silence. Installing to /usr/local puts the libraries on the
    # default loader path, so no LD_LIBRARY_PATH is needed in the unit file.
    cmake --install "$TMP/whisper/build" --prefix /usr/local >/dev/null
    ldconfig

    # The binary this replaces, from installs made before the fix.
    rm -f /opt/transcriber/bin/whisper-cli

    /usr/local/bin/whisper-cli -h >/dev/null 2>&1 \
        || { echo "whisper.cpp built but will not run — refusing to continue" >&2; exit 1; }
    # Written only after it is known to run, so a failed build cannot leave a stamp
    # claiming this machine is done.
    printf '%s' "$THIS_CPU" > "$WHISPER_STAMP"
    echo "  whisper.cpp installed and verified for $THIS_CPU"
else
    echo "whisper.cpp already installed and working; leaving it alone"
fi

# ── audio encoder ────────────────────────────────────────────────────────────
# Checked by encoding something rather than by looking for the binary. A packaged
# ffmpeg built without the AAC encoder would pass every test anyone thinks to run --
# it is installed, it is executable, it reports a version -- and fail only when a
# channel tries to send a clip, where the failure reads as "audio just doesn't work
# on this device". The same shape of mistake as the whisper-cli one above.
#
# Not fatal. A receiver with no encoder still hears, transcribes and logs; it just
# cannot send the audio, and the worker says so once and carries on.
if printf '' | ffmpeg -hide_banner -loglevel error -nostdin -y \
        -f lavfi -i "sine=frequency=440:duration=0.2" \
        -ac 1 -c:a aac -b:a 24k "$TMP/probe.m4a" >/dev/null 2>&1 \
        && [ -s "$TMP/probe.m4a" ]; then
    echo "  ffmpeg can encode AAC"
else
    echo "  WARNING: ffmpeg cannot encode AAC here. Channels will log text only;" >&2
    echo "           the mobile app will not be able to play back the audio." >&2
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
curl -fsSL --retry 3 -o "$TMP/files.tar.gz" "$BASE/files.tar.gz?t=$(date +%s)"
tar -xzf "$TMP/files.tar.gz" -C "$TMP"
rsync -a --ignore-times "$TMP/bin/"     /opt/transcriber/bin/
rsync -a --ignore-times "$TMP/systemd/" /etc/systemd/system/
[ -d "$TMP/udev" ] && rsync -a --ignore-times "$TMP/udev/" /etc/udev/rules.d/ || true
chmod +x /opt/transcriber/bin/*.py
chmod +x /opt/transcriber/bin/*.sh          # calibrate.sh, run by transcriber-calibrate@

# Seed an example config so the worker has something to explain itself with before
# the device is enrolled. auto-update.sh replaces it with the real list.
[ -f /etc/transcriber/channels.json ] || \
    install -m 640 -o root -g pi "$TMP/etc/transcriber/channels.json.example" \
                                 /etc/transcriber/channels.json

curl -fsSL --retry 3 -o /home/pi/auto-update.sh "$BASE/auto-update.sh?t=$(date +%s)"
[ -d "$TMP/home" ] && rsync -a --ignore-times "$TMP/home/" /home/pi/
chmod +x /home/pi/*.sh
chown pi:pi /home/pi/*.sh

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

# Answers the NetBird monitor's UDP poll, which is all a device has to do to appear at
# /netbird/admin.php. Nothing site-specific, so it starts now rather than waiting for
# configure.sh.
systemctl enable --now stats-listener.service >/dev/null 2>&1 || true

# Collects channel settings from the manager every 60 seconds, so a change made there
# reaches the receiver by itself instead of waiting for the nightly run.
systemctl enable --now transcriber-config.timer >/dev/null 2>&1 || true

echo
echo "=== Installed ==="
echo
echo "This built the machine. Now make it a particular receiver:"
echo
echo "  sudo /home/pi/configure.sh"
echo
echo "which asks for the hostname, a NetBird setup key, the device token from the"
echo "manager, and each dongle's serial — then collects this device's channels and"
echo "starts them."
echo
echo "Add the device at https://marsaprs.org/transcriber/ first, so there is a token"
echo "to paste in."
echo
echo "Afterwards:  systemctl status 'transcriber@*'"
echo "             journalctl -u 'transcriber@*' -f"

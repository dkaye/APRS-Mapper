#!/usr/bin/env bash
# make-master.sh — Create a cloneable iGate master image from an SD card.
#
# Run this on your Mac after:
#   1. Flashing Raspberry Pi OS Lite (64-bit) with Pi Imager
#   2. Booting the card in a Pi and running:
#        bash <(curl -fsSL https://marsaprs.org/igate/install.sh) --no-configure 2>&1 | tee install.log
#   3. Shutting the Pi down cleanly and returning the card to this Mac
#
# Output: igate-master-YYYYMMDD.img.gz  (readable by Balena Etcher / Apple Pi Baker)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
OUTPUT="$SCRIPT_DIR/igate-master-$(date +%Y%m%d).img.gz"

echo "=== iGate Master Image Builder ==="
echo ""
echo "External disks detected:"
echo ""
diskutil list external physical
echo ""
read -rp "Enter the disk identifier for the SD card (e.g. disk4): " DISK

DEVICE="/dev/$DISK"
RAW_DEVICE="/dev/r$DISK"   # raw device — much faster reads on macOS

if ! diskutil info "$DEVICE" &>/dev/null; then
    echo "Error: $DEVICE not found."
    exit 1
fi

echo ""
echo "Disk info:"
diskutil info "$DEVICE" | grep -E "Device Node|Media Name|Total Size|Removable"
echo ""

if [[ -f "$OUTPUT" ]]; then
    echo "Warning: $OUTPUT already exists and will be overwritten."
    echo ""
fi

read -rp "Image $DEVICE → $(basename "$OUTPUT")? This will take 10–20 minutes. [y/N]: " CONFIRM
[[ "${CONFIRM,,}" != "y" ]] && echo "Cancelled." && exit 0

echo ""
echo "Unmounting partitions (keeping disk attached for reading)..."
diskutil unmountDisk "$DEVICE"

echo "Imaging and compressing..."
echo "(No progress bar — grab a coffee. File appears when done.)"
echo ""

sudo dd if="$RAW_DEVICE" bs=4m 2>/dev/null | gzip > "$OUTPUT"

SIZE=$(du -sh "$OUTPUT" | cut -f1)
echo ""
echo "Done."
echo "  Output: $OUTPUT"
echo "  Size:   $SIZE"
echo ""
echo "Ejecting SD card..."
diskutil eject "$DEVICE"
echo "Safe to remove the card."
echo ""
echo "Flash onto new cards with Balena Etcher or Apple Pi Baker,"
echo "then boot the Pi and run: /home/pi/configure.sh"

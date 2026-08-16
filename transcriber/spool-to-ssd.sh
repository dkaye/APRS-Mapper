#!/usr/bin/env bash
# spool-to-ssd.sh — Move /var/spool/transcriber onto a USB SSD.
#
# The spool is where the write traffic is: the outbox (one small file per transmission,
# written at whatever rate the radio is busy), the retained recordings, and the AAC
# clips a channel sends with its log entries. That is what wears an SD card out. The
# system keeps booting from the card, which always works; only the churn moves.
#
# Deliberately NOT a USB-boot migration. See migrate-to-ssd.sh for that, and read its
# header first — some drives cannot be booted from at all, and on a Pi 4 the firmware
# probes USB mass storage at startup regardless of boot order, so a drive that hangs
# that probe will stop the machine booting even from its SD card. BOOT_ORDER=0xf1
# removes USB from the list entirely and is the setting that makes such a drive usable.
#
# Usage (on the Pi):
#   sudo /home/pi/spool-to-ssd.sh --target /dev/sda
#
# EVERYTHING ON THE TARGET IS DESTROYED.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

TARGET=""
SPOOL=/var/spool/transcriber
STAGE=/mnt/newspool

while [ $# -gt 0 ]; do
    case "$1" in
        --target) TARGET="${2:?--target needs a device}"; shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 1 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo." >&2; exit 1; }
[ -n "$TARGET" ] && [ -b "$TARGET" ] || { echo "Need --target <block device>" >&2; exit 1; }

# Never eat the disk we are running from.
ROOT_DISK=/dev/$(lsblk -no PKNAME "$(findmnt -n -o SOURCE /)")
BOOT_DISK=/dev/$(lsblk -no PKNAME "$(findmnt -n -o SOURCE /boot/firmware)")
for d in "$ROOT_DISK" "$BOOT_DISK"; do
    [ "$TARGET" = "$d" ] && { echo "$TARGET carries the running system. Refusing." >&2; exit 1; }
done

PART="${TARGET}1"
case "$TARGET" in *[0-9]) PART="${TARGET}p1" ;; esac

echo "=== Moving $SPOOL to $TARGET ==="
lsblk -o NAME,SIZE,FSTYPE,MOUNTPOINT,MODEL "$TARGET"
echo

# ── quiesce ──────────────────────────────────────────────────────────────────
# Real unit names, never a glob: `systemctl stop 'transcriber@*'` matches loaded units
# but `start` with the same pattern matches nothing and exits 0, which stops the
# receiver and reports that it restarted it.
STOPPED=$(systemctl list-units 'transcriber@*' --state=active --no-legend 2>/dev/null | awk '{print $1}')
systemctl is-active --quiet transcriber-config.timer 2>/dev/null \
    && STOPPED="$STOPPED transcriber-config.timer"
STOPPED=$(echo $STOPPED)
if [ -n "$STOPPED" ]; then
    echo "Stopping: $STOPPED"
    # shellcheck disable=SC2086
    systemctl stop $STOPPED || true
fi

restore_services() {
    [ -n "$STOPPED" ] || return 0
    echo "Restarting: $STOPPED"
    # shellcheck disable=SC2086
    systemctl start $STOPPED 2>/dev/null || true
    for u in $STOPPED; do
        case "$u" in *.timer) continue ;; esac
        systemctl is-active --quiet "$u" \
            || echo "  WARNING: $u did not come back — check 'journalctl -u $u'" >&2
    done
}
trap 'restore_services' EXIT

# ── partition ────────────────────────────────────────────────────────────────
echo "Partitioning $TARGET..."
umount -q "${TARGET}"* 2>/dev/null || true
wipefs -a "$TARGET" >/dev/null
parted -s "$TARGET" mklabel gpt
parted -s "$TARGET" mkpart primary ext4 1MiB 100%
partprobe "$TARGET"; sleep 2
# -m 0: the 5% ext4 reserve exists to stop a full ROOT filesystem wedging the system.
# On a 931 GB spool that is 46 GB set aside for nothing.
mkfs.ext4 -F -m 0 -L tspool "$PART" >/dev/null
echo "  formatted $PART"

# ── copy what is there ───────────────────────────────────────────────────────
mkdir -p "$STAGE"
mount "$PART" "$STAGE"
if [ -d "$SPOOL" ]; then
    echo "Copying the existing spool..."
    rsync -aHAX "$SPOOL/" "$STAGE/"
fi
# The worker runs as User=pi and creates per-channel directories itself.
chown pi:pi "$STAGE"
chmod 755 "$STAGE"
umount "$STAGE"
rmdir "$STAGE" 2>/dev/null || true

# ── mount it for good ────────────────────────────────────────────────────────
UUID=$(blkid -s UUID -o value "$PART")
echo "Adding $SPOOL to fstab (UUID=$UUID)"
# By UUID, not /dev/sda1: USB enumeration order is not a promise, and a second drive
# plugged in one day must not silently become the spool.
sed -i "\#[[:space:]]$SPOOL[[:space:]]#d" /etc/fstab
cat >> /etc/fstab <<EOF
# Transcriber spool on USB SSD. nofail is not optional: without it a drive that has
# died or been unplugged leaves systemd waiting on the mount and drops the machine to
# an emergency shell, which on a headless receiver is indistinguishable from bricked.
# With it, the Pi boots and the spool falls back to the SD card underneath.
UUID=$UUID  $SPOOL  ext4  defaults,noatime,nofail,x-systemd.device-timeout=15s  0  2
EOF

systemctl daemon-reload
mount "$SPOOL"

# ── verify ───────────────────────────────────────────────────────────────────
# The old contents are still on the SD card underneath this mountpoint, which is a free
# backup — but it also means a mount that silently failed looks exactly like success.
MOUNTED=$(findmnt -n -o SOURCE "$SPOOL" || true)
[ -n "$MOUNTED" ] || { echo "$SPOOL is NOT mounted — check /etc/fstab" >&2; exit 1; }
echo
echo "=== Done ==="
findmnt -o SOURCE,TARGET,FSTYPE,SIZE,USED,OPTIONS "$SPOOL"
echo
echo "The previous spool is still on the SD card underneath this mountpoint."
echo "Reachable by unmounting $SPOOL; delete it once you are satisfied."

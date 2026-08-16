#!/usr/bin/env bash
# migrate-to-ssd.sh — Move a Transcriber (or any Pi 4) from its SD card to a USB SSD.
#
# Clones the running system to the SSD, fixes the copy's own references to itself, and
# leaves the SD card alone. Nothing about how the Pi boots changes until you ask for it
# with --set-boot-order, which is a separate step on purpose: the clone is reversible
# by unplugging a drive, and the boot order is the part that can strand the device.
#
# The SD card stays bootable throughout, and stays in the slot as the fallback. Take it
# out once the Pi has come up on the SSD a few times, not before.
#
# Usage (on the Pi):
#   sudo /home/pi/migrate-to-ssd.sh --target /dev/sda
#   sudo /home/pi/migrate-to-ssd.sh --target /dev/sda --set-boot-order
#
#   --resync           copy again over an existing clone (fast; skips partitioning)
#   --set-boot-order   after cloning, tell the firmware to try USB before the SD
#
# Why this is not `dd`: a block copy of a 32 GB card onto a 1 TB SSD copies the free
# space too, reproduces the old partition sizes, and leaves a filesystem that has to be
# grown afterwards. A filesystem-level copy takes what is actually there.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

set -euo pipefail

TARGET=""
RESYNC=0
SET_BOOT=0
MNT=/mnt/ssd-migrate

while [ $# -gt 0 ]; do
    case "$1" in
        --target)         TARGET="${2:?--target needs a device}"; shift 2 ;;
        --resync)         RESYNC=1; shift ;;
        --set-boot-order) SET_BOOT=1; shift ;;
        *) echo "Unknown argument: $1" >&2; exit 1 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo." >&2; exit 1; }
[ -n "$TARGET" ]     || { echo "Need --target, e.g. --target /dev/sda" >&2; exit 1; }
[ -b "$TARGET" ]     || { echo "$TARGET is not a block device." >&2; exit 1; }

# ── refuse to eat the wrong disk ─────────────────────────────────────────────
# Every destructive step below is downstream of this check, and the failure it guards
# against is unrecoverable rather than inconvenient. Naming the running root here means
# a mistyped --target cannot be the device we are currently booted from.
ROOT_SRC=$(findmnt -n -o SOURCE /)
ROOT_DISK=/dev/$(lsblk -no PKNAME "$ROOT_SRC")
if [ "$TARGET" = "$ROOT_DISK" ]; then
    echo "$TARGET is the disk this system is running from. Refusing." >&2
    exit 1
fi

BOOT_PART="${TARGET}1"
ROOT_PART="${TARGET}2"
# nvme0n1 and mmcblk0 number their partitions p1/p2; sda does not.
case "$TARGET" in *[0-9]) BOOT_PART="${TARGET}p1"; ROOT_PART="${TARGET}p2" ;; esac

USED_KB=$(df -k --output=used / /boot/firmware | tail -n +2 | paste -sd+ | bc)
CAP_KB=$(( $(blockdev --getsize64 "$TARGET") / 1024 ))
if [ "$CAP_KB" -lt $(( USED_KB * 12 / 10 )) ]; then
    echo "$TARGET holds $(( CAP_KB / 1024 )) MB; the system needs $(( USED_KB / 1024 )) MB plus room to work." >&2
    exit 1
fi

echo "=== Migrating this system to $TARGET ==="
echo "    running root : $ROOT_SRC"
echo "    to copy      : $(( USED_KB / 1024 )) MB"
echo

# ── quiesce ──────────────────────────────────────────────────────────────────
# The outbox is the reason. It holds transmissions the server has not accepted yet, as
# one small file per entry, and a channel writes to it at whatever rate the radio is
# busy. Copying it while it is being written gives a clone with a half-written entry in
# it — which is survivable (flush() deletes what it cannot parse) but silently loses a
# transmission somebody said. Stopping for the length of a copy costs nothing by
# comparison: the radio is not recorded while stopped, and that is a known gap rather
# than a corrupted record.
#
# The unit names are resolved to real instances FIRST, and that is not a tidiness
# preference. `systemctl stop 'transcriber@*'` works, because a glob matches units that
# are already loaded — but `systemctl start 'transcriber@*'` matches nothing and exits
# 0. Stopping with a pattern and starting with the same pattern therefore stops the
# channels, reports that it restarted them, and leaves the receiver off the air. That
# is what this script did the first time it was run.
STOPPED=$(systemctl list-units 'transcriber@*' --state=active --no-legend 2>/dev/null \
          | awk '{print $1}')
systemctl is-active --quiet transcriber-config.timer 2>/dev/null \
    && STOPPED="$STOPPED transcriber-config.timer"
STOPPED=$(echo $STOPPED)          # collapse whitespace so the -n test means something

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
    # Say what actually came back, rather than that we asked. A channel can fail to
    # start for reasons that have nothing to do with this script — most often a dongle
    # that is not plugged in — and the one thing worse than that happening is a
    # migration that prints "Restarting" and exits 0 over the top of it.
    for u in $STOPPED; do
        case "$u" in *.timer) continue ;; esac
        systemctl is-active --quiet "$u" \
            || echo "  WARNING: $u did not come back — check 'journalctl -u $u'" >&2
    done
}
trap 'restore_services' EXIT

# ── partition ────────────────────────────────────────────────────────────────
umount -q "${TARGET}"* 2>/dev/null || true

if [ "$RESYNC" -eq 0 ]; then
    echo "Partitioning $TARGET (everything on it is destroyed)..."
    wipefs -a "$TARGET" >/dev/null
    # msdos rather than GPT: it is what Pi OS images use, so the PARTUUIDs come out in
    # the same short <disk-id>-<nn> form the firmware and fstab already speak, and
    # nothing downstream has to care which kind of table it is looking at.
    parted -s "$TARGET" mklabel msdos
    parted -s "$TARGET" mkpart primary fat32 1MiB 513MiB
    parted -s "$TARGET" set 1 boot on
    parted -s "$TARGET" mkpart primary ext4 513MiB 100%
    partprobe "$TARGET"; sleep 2

    echo "Formatting..."
    mkfs.vfat -F 32 -n bootfs "$BOOT_PART" >/dev/null
    mkfs.ext4 -F -L rootfs "$ROOT_PART" >/dev/null
fi

# ── copy ─────────────────────────────────────────────────────────────────────
mkdir -p "$MNT"
mount "$ROOT_PART" "$MNT"
mkdir -p "$MNT/boot/firmware"
mount "$BOOT_PART" "$MNT/boot/firmware"

echo "Copying root filesystem (several minutes)..."
# -x confines this to the root filesystem, so /proc, /sys, /dev and every automounted
# volume are excluded by being other filesystems rather than by being listed. The
# explicit excludes below are the ones that ARE on the root filesystem and must not be
# copied: runtime state, the mountpoint we are writing into, and the tmpfs clip
# directory's fallback if it ever ran without one.
rsync -aHAXx --numeric-ids --delete \
    --exclude="$MNT" \
    --exclude=/proc/ --exclude=/sys/ --exclude=/dev/ --exclude=/run/ \
    --exclude=/tmp/ --exclude=/media/ --exclude=/mnt/ \
    --exclude=/lost+found \
    --info=stats1 / "$MNT/"

echo "Copying boot partition..."
rsync -aHAX --delete /boot/firmware/ "$MNT/boot/firmware/"

# ── teach the copy about itself ──────────────────────────────────────────────
# The clone is byte-identical to a system that boots off the SD card, which means it
# still says so: cmdline.txt names the SD's root PARTUUID and fstab names both of the
# SD's partitions. Left alone, booting the SSD would mount the SD card as root and the
# whole exercise would appear to work while changing nothing. This is the step that
# actually moves the machine.
NEW_BOOT_UUID=$(blkid -s PARTUUID -o value "$BOOT_PART")
NEW_ROOT_UUID=$(blkid -s PARTUUID -o value "$ROOT_PART")
OLD_ROOT_UUID=$(blkid -s PARTUUID -o value "$ROOT_SRC")
echo "Repointing the clone: root PARTUUID $OLD_ROOT_UUID -> $NEW_ROOT_UUID"

sed -i "s/root=PARTUUID=[^ ]*/root=PARTUUID=$NEW_ROOT_UUID/" "$MNT/boot/firmware/cmdline.txt"
cat > "$MNT/etc/fstab" <<EOF
proc            /proc           proc    defaults          0       0
PARTUUID=$NEW_BOOT_UUID  /boot/firmware  vfat    defaults          0       2
PARTUUID=$NEW_ROOT_UUID  /               ext4    defaults,noatime  0       1
EOF

# Verify rather than assume. A sed that matched nothing exits 0 and leaves a clone that
# boots straight back onto the SD card, which looks exactly like success.
grep -q "root=PARTUUID=$NEW_ROOT_UUID" "$MNT/boot/firmware/cmdline.txt" \
    || { echo "cmdline.txt was not updated — refusing to call this done." >&2; exit 1; }

sync
umount "$MNT/boot/firmware"
umount "$MNT"
echo "Clone complete and verified."

# ── boot order ───────────────────────────────────────────────────────────────
if [ "$SET_BOOT" -eq 1 ]; then
    # 0xf14 reads right-to-left: try USB (4), then the SD card (1), then restart the
    # sequence (f). The SD stays as the fallback, so a drive that fails to enumerate
    # still leaves a machine that comes up.
    #
    # What this does NOT protect against: an SSD that enumerates and has a valid boot
    # partition but a root the kernel cannot mount. The firmware has already handed off
    # by then and there is nothing left to fall back to — the recovery is to unplug the
    # SSD and power-cycle, which is why the first boot after this wants somebody within
    # reach of the machine.
    # WARNING, and it is not obvious: this does not write the EEPROM. It writes
    # recovery.bin and pieeprom.upd onto /boot/firmware -- the SD CARD -- and the
    # firmware applies them on the next boot. So the change belongs to the CARD until
    # it has been consumed, not to the machine.
    #
    # Move that card to another Pi before rebooting, and the other Pi reflashes its own
    # EEPROM from it. That is how a boot-order change intended for a spare ended up on
    # the production receiver, whose EEPROM this script had never been pointed at.
    #
    # So: reboot this machine before moving its card anywhere. `rpi-eeprom-update -r`
    # cancels a pending update if you change your mind.
    echo "Setting BOOT_ORDER=0xf14 (USB first, SD second)..."
    echo "NOTE: this stages an EEPROM update ON THE SD CARD. Reboot THIS Pi before"
    echo "      moving the card to another machine, or that machine will apply it too."
    CFG=$(mktemp)
    rpi-eeprom-config > "$CFG"
    if grep -q '^BOOT_ORDER=' "$CFG"; then
        sed -i 's/^BOOT_ORDER=.*/BOOT_ORDER=0xf14/' "$CFG"
    else
        echo 'BOOT_ORDER=0xf14' >> "$CFG"
    fi
    rpi-eeprom-config --apply "$CFG"
    rm -f "$CFG"
    echo "Applied. It takes effect on the next reboot."
fi

echo
echo "=== Done ==="
if [ "$SET_BOOT" -eq 1 ]; then
    echo "Reboot when ready. Afterwards check with:  findmnt -n -o SOURCE /"
    echo "It should say ${ROOT_PART}. If the Pi does not come back, pull the SSD"
    echo "and power-cycle — the SD card is untouched and still boots."
else
    echo "Nothing about booting has changed yet. To switch:"
    echo "  sudo $0 --target $TARGET --resync --set-boot-order"
fi

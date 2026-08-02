#!/usr/bin/env bash
# Wrapper for direwolf.service.
# Suppresses direwolf on unconfigured units (exits 0 so systemd does not restart).
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

if grep -q "CONFIGURE_ME" /home/pi/direwolf.conf 2>/dev/null; then
    echo "iGate not configured — direwolf suppressed. Run /home/pi/configure.sh."
    exit 0
fi

# A missing dongle is a fault systemd should keep retrying, unlike the
# unconfigured case above — so this exits non-zero on purpose. Without the
# precheck, rtl_fm exits, direwolf sees EOF on stdin and exits 0, and the
# service sits dead until someone notices.
#
# Match the "no devices" message rather than rtl_test's exit status: it also
# exits non-zero when the dongle is present but still claimed by the previous
# rtl_fm ("usb_claim_interface error -6"), which happens on a fast restart and
# is most likely on slower hosts. Treating that as a missing dongle would report
# the wrong cause. Anything other than a genuine absence falls through to the
# pipeline below, where pipefail reports the real failure.
if timeout 20 rtl_test -t 2>&1 | grep -q "No supported devices found"; then
    echo "No RTL-SDR detected — check the dongle." >&2
    exit 1
fi

# pipefail so a mid-run rtl_fm death is reported as failure rather than being
# masked by direwolf's clean exit-0 on EOF. Needed inside this bash -c too, as
# it is a fresh shell and does not inherit the outer shell's options.
exec /bin/bash -c 'set -o pipefail; rtl_fm -f 144.39M -E dc - | nice -n 5 direwolf -c /home/pi/direwolf.conf -r 24000 -d i - > /var/log/direwolf/console.log 2>&1'

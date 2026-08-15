#!/usr/bin/env bash
# iGate health watchdog — run from cron every minute.
#
# SDR check:      every minute — restarts direwolf if SDR reappears
# Decode check:   every 10 minutes — restarts direwolf if nothing is being decoded
# IP check:       every minute — logs if no address
# Internet check: every 5 minutes, only when NetBird is connected
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
LOGFILE=/var/log/direwolf/watchdog.log

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOGFILE"; }

display=0
pinctrl get 23 | grep -q hi && display=1

MIN=$(date +%M)

# ── Suppress all checks during boot sequence or pending reboot ────────────────
pgrep -f dw-startup.py > /dev/null 2>&1 && exit 0
[ -f /tmp/aprs-rebooting ] && exit 0
[ -f /tmp/sdr-usb-test.pause ] && exit 0   # sdr-usb-test owns the SDR; don't restart direwolf

# ── Decode check settings ─────────────────────────────────────────────────────
# The SDR check below proves the dongle is on the bus and direwolf is running.
# Neither proves that anything is being *decoded*, and that is a real blind spot:
# an RTL-SDR's R820T tuner can stop locking while every other indicator stays
# healthy. The dongle stays enumerated, rtl_test exits 0 while printing
# "[R82XX] PLL not locked!", rtl_fm reports the frequency it tuned to and the
# sample rate it allocated — and then delivers zero bytes, forever. direwolf sits
# there reading nothing and stays "active". We hit this twice in one day on the
# Transcribers, which use the same dongles. From the outside the gate looks
# perfect while not one packet is gated. Only traffic tells us the truth.
#
# Six hours of complete silence is the trigger. 144.39 around here is never quiet
# for that long: even a poorly sited gate hears a beacon within minutes, and the
# quietest overnight stretch at a remote site is a couple of hours at worst. Six
# hours of *nothing* is far outside what a working receiver produces, while the
# cost of being wrong is a few seconds of direwolf downtime. A shorter threshold
# would trade that certainty for speed we do not need — a receiver that died at
# 2am is no worse for being found at 8am than at 4am.
RX_SILENT_RESTART=21600     # 6 hours with no decode → restart direwolf, once
RX_SILENT_DEAD=10800        # 3 more hours of silence after that → say so, then stop

RX_APRSLOGS=/home/pi/aprslogs           # direwolf's LOGDIR — one line per received packet
RX_CONSOLE=/var/log/direwolf/console.log
RX_STATE=/tmp/igate-rx.state            # "<console line count> <last increase> <last reset>"
RX_RESTARTED=/tmp/igate-rx-restarted    # epoch of the one restart we allow ourselves
RX_DEAD=/tmp/igate-rx-dead              # set once we have given up; blocks further restarts

# Called only when the SDR is present and direwolf is already running.
decode_check() {
    local now newest last_decode watch_from silence
    local cur prev_count prev_evidence prev_reset
    local mono up dw_start restart_at

    now=$(date +%s)
    last_decode=0
    prev_reset=0

    # Source of truth: direwolf's own APRS traffic log (LOGDIR in direwolf.conf).
    # One line per received packet, so the newest file's mtime is the moment of the
    # last decode. It is already maintained — it lives on the SD card, survives the
    # nightly reboot, and auto-update.sh prunes it at 14 days — and, unlike
    # console.log, a direwolf restart does not truncate it.
    #
    # It counts everything the modem decoded, not what was gated. That matters: the
    # IG FILTER in direwolf.conf drops most received traffic, so counting gated
    # packets instead would read as silence on a perfectly healthy gate.
    #
    # If the directory is not there at all, this gate's direwolf.conf has no LOGDIR
    # and we have nothing solid to measure. Do nothing rather than guess.
    [ -d "$RX_APRSLOGS" ] || return 0
    newest=$(ls -t "$RX_APRSLOGS"/*.log 2>/dev/null | head -1)
    if [ -n "$newest" ]; then
        last_decode=$(stat -c %Y "$newest" 2>/dev/null)
        case "$last_decode" in ''|*[!0-9]*) last_decode=0 ;; esac
    fi

    # Corroboration from the console log, because letting a single indicator decide
    # for the whole fleet is how you get a fleet-wide mistake. direwolf prints an
    # "audio level" line for every frame it demodulates, and direwatch tails this
    # file live, so we know it is written as frames arrive rather than sitting in a
    # buffer. The traffic log's mtime, by contrast, can lag on a quiet gate whose
    # stdio buffer has not filled. This can only push the last-decode time later —
    # it can never cause a restart, only prevent one, which is the direction to be
    # wrong in.
    cur=$(grep -c 'audio level' "$RX_CONSOLE" 2>/dev/null)
    case "$cur" in ''|*[!0-9]*) cur='' ;; esac
    if [ -n "$cur" ]; then
        prev_count=''; prev_evidence=0
        if [ -f "$RX_STATE" ]; then
            read -r prev_count prev_evidence prev_reset < "$RX_STATE"
            case "$prev_count"    in ''|*[!0-9]*) prev_count='' ;; esac
            case "$prev_evidence" in ''|*[!0-9]*) prev_evidence=0 ;; esac
            case "$prev_reset"    in ''|*[!0-9]*) prev_reset=0 ;; esac
        fi
        if [ -z "$prev_count" ] || [ "$cur" -lt "$prev_count" ]; then
            # First run, or the file shrank. A direwolf restart truncates it and
            # logrotate's copytruncate does the same once a day, so the count is no
            # longer comparable with what we stored. Drop the evidence and start
            # watching from now: that can delay a detection by one window, and can
            # never cause a restart.
            prev_evidence=0
            prev_reset=$now
        elif [ "$cur" -gt "$prev_count" ]; then
            prev_evidence=$now
        fi
        echo "$cur $prev_evidence $prev_reset" > "$RX_STATE"
        [ "$prev_evidence" -gt "$last_decode" ] && last_decode=$prev_evidence
    fi

    # A restart resets both of the things measured above, so the window is anchored
    # on the later of the last decode and direwolf's own start: the question is
    # whether *this* run of direwolf has heard anything, not whether the gate ever
    # has. Monotonic microseconds since boot compared against /proc/uptime, so
    # there is no timestamp string to parse and no locale to get wrong.
    dw_start=0
    mono=$(systemctl show -p ActiveEnterTimestampMonotonic --value direwolf.service 2>/dev/null)
    case "$mono" in ''|*[!0-9]*) mono=0 ;; esac
    if [ "$mono" -gt 0 ]; then
        up=$(awk '{printf "%d", $1}' /proc/uptime 2>/dev/null)
        case "$up" in ''|*[!0-9]*) up=0 ;; esac
        [ "$up" -gt 0 ] && dw_start=$(( now - up + mono / 1000000 ))
    fi
    # No usable start time means we cannot say how long direwolf has been
    # listening, so we are not entitled to an opinion about its silence.
    [ "$dw_start" -gt 0 ] || return 0

    watch_from=$last_decode
    [ "$dw_start"   -gt "$watch_from" ] && watch_from=$dw_start
    [ "$prev_reset" -gt "$watch_from" ] && watch_from=$prev_reset
    silence=$(( now - watch_from ))

    # Anchoring on direwolf's start is what would otherwise turn this into a loop:
    # restart, wait six hours, restart again, forever, on a gate that is simply in a
    # quiet spot. So the restart marker is the gate on all of it, and only a real
    # decode clears it. One restart, then one report, then nothing — escalating
    # forever is worse than saying so once. The markers live in /tmp, so the nightly
    # reboot clears them and the worst case is one restart per gate per day.
    if [ -f "$RX_RESTARTED" ]; then
        read -r restart_at < "$RX_RESTARTED"
        case "$restart_at" in ''|*[!0-9]*) restart_at=0 ;; esac
        if [ "$last_decode" -gt "$restart_at" ]; then
            log "Decoding again $(( (last_decode - restart_at) / 60 )) min after the restart — receiver recovered."
            rm -f "$RX_RESTARTED" "$RX_DEAD"
        elif [ ! -f "$RX_DEAD" ] && [ $(( now - restart_at )) -ge "$RX_SILENT_DEAD" ]; then
            log "RECEIVER DEAD: nothing decoded in the $(( RX_SILENT_DEAD / 3600 ))h since direwolf was restarted. The dongle is enumerated and direwolf is running, so this is the tuner, not the software — it needs a power cycle or a replug. No further automatic restarts until something is decoded."
            : > "$RX_DEAD"
        fi
        return 0
    fi

    if [ "$silence" -ge "$RX_SILENT_RESTART" ]; then
        log "Nothing decoded for $(( silence / 3600 ))h — restarting direwolf."
        echo "$now" > "$RX_RESTARTED"
        sudo systemctl restart direwolf.service
    fi
}

# ── SDR check ─────────────────────────────────────────────────────────────────
if lsusb | grep -qiE '0bda:2838|0bda:2832|RTL28'; then
    if ! systemctl is-active --quiet direwolf.service; then
        log "SDR found, direwolf not running — restarting."
        sudo systemctl restart direwolf.service
    elif [ $(( 10#$MIN % 10 )) -eq 0 ]; then
        # Every 10 minutes, not every minute: the thresholds are hours, and this
        # greps the whole console log, which there is no reason to do 1,440 times
        # a day on a Pi.
        decode_check
    fi
else
    log "No SDR found."
    [ "$display" -eq 1 ] && python3 /home/pi/direwatch/dw-nosdr.py &
fi

# ── IP address check ──────────────────────────────────────────────────────────
if [ -z "$(hostname -I | awk '{print $1}')" ]; then
    log "No IP address."
fi

# ── Internet check (every 5 minutes, only when NetBird is connected) ──────────
if [ $(( 10#$MIN % 5 )) -eq 0 ]; then
    if command -v netbird &>/dev/null && netbird status 2>/dev/null | grep -q "NetBird IP:"; then
        if ! ping -c1 -W3 8.8.8.8 &>/dev/null; then
            log "No internet."
            python3 /home/pi/direwatch/dw-nointernet.py &
        fi
    fi
fi

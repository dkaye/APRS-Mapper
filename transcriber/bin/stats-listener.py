#!/usr/bin/env python3
# Transcriber health responder — answers the NetBird monitor's UDP poll.
#
# server/bin/netbird-poller.py sends "long" (or "short") to UDP 1235 on every device in
# /var/www/html/netbird/addresses.yaml and prints whatever comes back. Answering it is
# the whole of what makes a device appear in the monitor at /netbird/admin.php — there is
# no registration step and nothing to add on the server.
#
# This is the iGate's stats-listener.php in Python rather than a copy of it. The wire
# format is identical, field for field, because the poller displays the string verbatim;
# the language differs because a Transcriber has no PHP on it, and installing php-cli
# plus ext-sockets to run one 150-line script on a Pi whose entire worker is stdlib
# Python is a poor trade. If the format ever changes, both must change together.
#
# Usage:  stats-listener.py [--port 1235]
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import argparse
import socket
import subprocess
import sys

PORT = 1235


def run(cmd):
    """A shell command's output, or "" — never an exception.

    Every field below is best-effort: a missing vcgencmd or a Pi on Ethernet must
    produce a blank column, not a listener that dies and takes the device off the
    monitor. A device that stops answering looks exactly like a device that is down.
    """
    try:
        return subprocess.check_output(cmd, shell=True, stderr=subprocess.DEVNULL,
                                       timeout=5).decode().strip()
    except Exception:                       # noqa: BLE001 - see docstring
        return ""


def stats(short=False):
    hostname = run("hostname").ljust(11)

    load = run("cat /proc/loadavg").split()
    load = (", ".join(load[:3]) if len(load) >= 3 else "").ljust(14)

    # /sys is world-readable; vcgencmd needs the video group, which the poller
    # already learned the hard way.
    try:
        with open("/sys/class/thermal/thermal_zone0/temp") as fh:
            temp = "%.1f'C" % (int(fh.read().strip()) / 1000)
    except OSError:
        temp = ""

    disk = run("df -h / | awk 'NR==2{print $4}'")

    # The one field worth reading first when a Transcriber misbehaves. Continuous
    # transcription on four cores plus two dongles is a sustained near-peak draw, which
    # is what browned out BigTV; bits 16-18 latch since boot, so 0x0 here is a real
    # all-clear rather than "not right now".
    throttled = run("vcgencmd get_throttled")
    throttled = throttled.split("=")[1] if "=" in throttled else "0x0"

    ssid = run("nmcli -t -f active,ssid dev wifi | grep yes")
    ssid = ssid[4:30] if ssid else "<Ethernet>"

    nb = run("netbird status | grep 'NetBird IP'")
    nb_ip = (nb[12:].rstrip("/16").strip() if nb else "").ljust(15)

    bits = int(throttled, 16) if throttled.startswith("0x") else 0
    flags = ("  Low Voltage" if bits & 1 else "") + ("  High Temp" if bits & 8 else "")

    if short:
        return f"{hostname} Load={load} {temp} Throttled={throttled} SSID={ssid} {flags}"
    return (f"{hostname}  Load={load}  Temp={temp}  Disk={disk}  "
            f"Throttled={throttled}  {nb_ip}  SSID={ssid}  {flags}")


def main():
    p = argparse.ArgumentParser(description="Answer the NetBird monitor's health poll.")
    p.add_argument("--port", type=int, default=PORT)
    args = p.parse_args()

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.bind(("0.0.0.0", args.port))

    while True:
        try:
            data, addr = sock.recvfrom(100)
        except OSError:
            continue
        if not addr or not addr[0]:
            continue        # unanswerable; the PHP version used to exit() here
        try:
            # Always back to 1235, not to the sender's port: the poller binds 1235 and
            # waits there for every device's reply at once.
            sock.sendto(stats(b"short" in data).encode(), (addr[0], args.port))
        except OSError:
            pass


if __name__ == "__main__":
    sys.exit(main())

# APRS Kiosk Display Pi

## Overview

A dedicated kiosk display for the MARS APRS Tracker Map. Runs Chromium fullscreen
pointing at marsaprs.org and provides several background services.

No directly connected keyboard. Manage via SSH or VNC from the Mac.

Current devices of this type:

| Hostname   | Local IP      | SSH                      | VNC                       |
|------------|---------------|--------------------------|---------------------------|
| NetControl | 192.168.0.36  | ssh pi@192.168.0.36      | vnc://192.168.0.36:5901   |
| BigTV      | 192.168.0.52  | ssh pi@192.168.0.52      | vnc://192.168.0.52:5901   |

VNC password: guacamole

---

## Kiosk Display

Chromium launches automatically at boot in fullscreen kiosk mode via LXDE autostart.

Config: `/home/pi/.config/lxsession/rpd-x/autostart`

```
@chromium --password-store=basic --kiosk --noerrdialogs --disable-infobars
          --disable-dev-shm-usage --incognito --disable-features=BlockInsecurePrivateNetworkRequests
          https://marsaprs.org/
```

Flags:
- `--kiosk` — fullscreen, no browser UI
- `--password-store=basic` — suppresses keyring unlock popup
- `--incognito` — no session persistence
- `--disable-dev-shm-usage` — required on Pi (limited /dev/shm)
- `--disable-features=BlockInsecurePrivateNetworkRequests` — allows the Exit button to call localhost:8080

### Exiting the kiosk

On the APRS map page:
1. Click **Exit** in the kiosk footer → drops to normal map view
2. Click **Exit** in the normal footer → kills Chromium, returns to desktop

The second Exit button calls `http://localhost:8080/exit`, handled by kill-server (see Services).

### Desktop icon (Start APRS)

`/home/pi/Desktop/start-aprs.desktop` — double-click to kill and relaunch Chromium in kiosk mode.

The file must have mode `644` (not executable). On Pi OS Trixie, any `.desktop` file with the execute bit set triggers an "Executable Script" dialog that blocks the launch.

Also required on Trixie to suppress the dialog system-wide:

```bash
mkdir -p ~/.config/libfm
printf '[config]\nquick_exec=1\n' > ~/.config/libfm/libfm.conf
```

Both settings are applied automatically by `auto-update.sh`.

---

## Services

All services are managed by systemd and start automatically at boot.

### x11vnc  (VNC remote desktop)
```
Service:  /etc/systemd/system/x11vnc.service
Port:     5901
Password: guacamole
```
Provides VNC access to the live X11 desktop (:0).

### kill-server  (Chromium exit helper)
```
Service:  /etc/systemd/system/kill-server.service
Script:   /home/pi/kill-server.py
Port:     8080 (localhost only)
```
Minimal Python HTTP server. `GET /exit` runs `pkill chromium` and responds `ok`.

### stats-listener  (UDP stats responder)
```
Service:  /etc/systemd/system/stats-listener.service
Script:   /home/pi/StatsRequestListener.php
Port:     1235 UDP
```
Responds to any UDP packet with a one-line status string:
hostname, CPU load, temp, disk usage, throttle flags, NetBird IP, WiFi SSID, warnings.

Send `short` in the request for a compact subset.

### aprs-monitor  (APRS server reachability monitor)
```
Service:  /etc/systemd/system/aprs-monitor.service
Script:   /home/pi/aprs-monitor.sh (installed to /usr/local/bin/)
```
Polls marsaprs.org every 30 seconds. If unreachable, kills Chromium and relaunches it
pointing to `localhost:8080`. When reachable again, the connecting page auto-redirects.

**It takes three consecutive failures**, not one, and allows 10 s per check. A single slow
fetch used to tear the browser down: over Starlink the fetch routinely takes 3–8 s while the
link is perfectly healthy. Of three "failures" measured on 2026-08-07, two returned HTTP 200
(in 8.0 s and 3.4 s) and the third coincided with successful pings to 1.1.1.1 — nothing was
ever down. Switching pages is disruptive and visible, so it must not hinge on one sample.

**It also starts the kiosk if none is running** (after two empty checks, delayed so it does
not race the LXDE autostart at boot). Previously the loop saw no browser, set a flag and
continued — it never started one, because that is normally the autostart's job at login. So
anything that killed the browser left the display black until somebody rebooted the Pi.

**The unit sets `KillMode=process`.** The monitor launches Chromium, so the browser lands in
this unit's control group; with systemd's default `KillMode=control-group`, restarting the
monitor killed the kiosk too — and nothing restarted it.

After `pkill`, it waits for the process to actually exit rather than sleeping a fixed 2 s.
That guess became harmful once `start-kiosk.sh` enforced a single instance: a slow-dying
browser would make the replacement correctly refuse to start, leaving no display at all.

### wifi-band-pin.sh  (2.4 GHz band pin, dual-band Pis only)
```
Script:   /home/pi/wifi-band-pin.sh   (cron, every 5 min)
Log:      /home/pi/wifi-band-pin.log
```
Keeps a Pi 4 / Pi 5 off a weak 5 GHz radio when the same AP's 2.4 GHz radio is better — and
off 2.4 GHz when it is not. A Pi Zero 2 W is 2.4 GHz-only and cannot be steered, so this is a
no-op on iGates.

**Does nothing when Ethernet carries the traffic.** Every measurement pings the *default*
gateway; on a wired display that is `eth0`, so the numbers describe the cable rather than the
radio. The script exits immediately unless `wlan0` holds the default route. WiFi remains
associated as a fallback — it just is not judged while something else is carrying traffic.

It also enforces `802-11-wireless.powersave 2` on the active profile, matching what
`update-wifi.php` sets on every profile it creates. NetworkManager's default leaves power save
on, which delays *inbound* packets — a device stays reachable outbound while inbound stalls.

Every decision is **measured**, because signal strength cannot see co-channel interference.
On 2026-08-07 a display's 2.4 GHz radio read *stronger* than its 5 GHz one but sat on a
channel shared with three APs at full signal: switching to it produced 16% loss to the gateway
and 100% loss to the internet. So a switch is a proposal — measure, switch, measure again,
revert unless it genuinely improved — and a pin is released only if some same-SSID 5 GHz radio
looks viable, because a degraded link still carries the kiosk while a dead one does not.

If wlan0 cannot associate at all, any pin is cleared, so a device moved to a 5 GHz-only
network can never be stranded offline.

### wifi-watchdog  (WiFi reconnect watchdog)
```
Service:  /etc/systemd/system/wifi-watchdog.service
Script:   /home/pi/wifi-watchdog.sh (installed to /usr/local/bin/)
Hook:     /usr/local/bin/wifi-restored.sh (device-specific)
```
Checks WiFi every 30 seconds. On restore, calls `wifi-restored.sh` which kills and
relaunches Chromium via localhost:8080 and updates the aprs-pi hosts entry.

### lightdm  (Display manager / X11 session)
Manages the graphical desktop session (rpd-x / LXDE). Required for Chromium and VNC.

---

## Crontab (pi user)

```
*/5 * * * *  /home/pi/check-netbird.sh >> /tmp/checknetbird.log 2>&1
@reboot      /home/pi/netbird-up.sh
1 4 * * *    /home/pi/auto-update.sh >> /home/pi/update.log 2>&1
10 4 * * *   sudo reboot
*/5 * * * *  /home/pi/wifi-band-pin.sh
```

`install.sh` writes this crontab wholesale, so a re-install replaces anything added by hand.
`auto-update.sh` adds the `wifi-band-pin.sh` line idempotently for devices that only ever run
the updater — targeting **pi's** crontab explicitly, since running `auto-update.sh` by hand
under `sudo` would otherwise edit root's.

View with: `crontab -l`   Edit with: `crontab -e`

---

## check-netbird.sh

Runs every 5 minutes via cron. Queries the NetBird enable/disable endpoint:

```
GET https://marsaprs.org/netbird/?hostname=$HOSTNAME
```

Response `1` enables NetBird (starts rpcbind, avahi-daemon, systemd-timesyncd, NTP,
then `netbird up`).
Response `0` disables NetBird (brings it down, stops and disables the above services).

Log: `/tmp/checknetbird.log`

---

## StatsRequestListener.php

UDP server on port 1235. Waits for a UDP packet, then replies with a one-line string:

```
Hostname | CPU load (1/5/15 min) | CPU temp | Home dir disk | Throttled | NetBird IP | SSID | warnings
```

Throttle flags (from `vcgencmd get_throttled` bitmask):
- bit 0 = currently under-voltage
- bit 3 = currently throttled due to high temperature

Args (all optional): `listenerPort=N`, `destinationPort=N`, `debug`

---

## Useful Commands

```bash
# Check all service status
sudo systemctl status x11vnc kill-server stats-listener aprs-monitor wifi-watchdog

# View Chromium startup log
cat /tmp/chromium.log

# View NetBird check log
cat /tmp/checknetbird.log

# Restart Chromium manually (kiosk mode)
# Use the wrapper, not chromium directly: it enforces a single instance and
# builds the ?autologin&operator= URL from ~/autologin.txt.
pkill chromium; sleep 3
sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority setsid \
  /home/pi/start-kiosk.sh >/tmp/chromium.log 2>&1 &

# How many kiosks are really running? (renderers, the zygote and the flock
# wrapper all carry --kiosk, so a plain pgrep over-counts)
for p in $(pgrep -f -- '--kiosk'); do
  [ "$(cat /proc/$p/comm)" = chromium ] &&
    { grep -qz -- '--type=' /proc/$p/cmdline || echo "$p"; }
done | wc -l

# Power health — check this FIRST on any reboot loop or flakiness
vcgencmd get_throttled          # 0x0 = healthy; bit 16 = undervoltage since boot
dmesg | grep -i undervoltage

# Is it the WiFi, the WAN, or the server? Ping the gateway FROM the device:
# a LAN hop must be <5 ms at 0% loss.
ping -c 20 "$(ip route | awk '/^default/{print $3; exit}')"

# WiFi link quality (signal alone is misleading — see tx failed / bitrate)
sudo iw dev wlan0 link
sudo iw dev wlan0 station dump | grep -E 'signal|tx bitrate|tx failed'

# Band-pin decisions
tail /home/pi/wifi-band-pin.log
```

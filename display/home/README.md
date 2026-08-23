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

**Exit means it stays exited.** Before killing Chromium, kill-server writes
`/tmp/aprs-kiosk-off`, and while that file exists `aprs-monitor` stands down completely —
it will not restart a missing kiosk, will not switch to the connecting page, and will not
tear the browser down when the server comes back. Without it the monitor noticed the
missing browser within two 30-second cycles and brought it straight back, so there was no
way to actually reach the desktop on a display that has one.

The flag is written *before* the kill, not after, so there is no window in which the
monitor can see the gap and act on it.

**A reboot always re-arms it.** The flag lives in `/tmp` (tmpfs) and an `@reboot` cron
line removes it as well, so the guarantee does not quietly depend on how `/tmp` happens to
be mounted. A display exists to show the map and reboots itself nightly at 4:10 — one left
dark for a week because somebody pressed Exit once is a worse failure than one that comes
back unasked.

### Desktop icon (Start APRS)

`/home/pi/Desktop/start-aprs.desktop` — double-click to kill and relaunch Chromium in kiosk mode.

**It also re-arms auto-restart.** `start-kiosk.sh` removes `/tmp/aprs-kiosk-off` as its
first action, so the kiosk runs *and* the monitor resumes watching it until somebody
presses Exit again. The flag is cleared in `start-kiosk.sh` rather than in the shortcut so
it holds for every route in — the desktop icon, the script run by hand, and the autostart
at login. `aprs-monitor` never calls that script while the flag is set, so it cannot
un-exit itself.

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

## Wired displays: the ARP flux guard

A wired display keeps its WiFi associated deliberately — route metrics put `eth0` at 100
and `wlan0` at 600, so the cable wins and the radio is a silent fallback if it is pulled.

That is fine while the two are on **different** networks. Plug the Ethernet into a router
whose WiFi the Pi already knows and both interfaces land on **one subnet with two
addresses**, and Linux will answer ARP for either address on either interface. The
gateway's ARP table then flaps between the Pi's two MACs, and **inbound** connections
start failing while everything the Pi initiates still works.

That asymmetry is the most misleading symptom in this system. The display pings its own
gateway at 0.76 ms with 0% loss, its Ethernet negotiates 1 Gbps full duplex, its power
grades GOOD — and it is unreachable from anywhere, with the kiosk cycling because it
cannot load the map. It looks exactly like a Pi rebooting.

BigTV, 2026-08-19, after moving to Starlink: `eth0 192.168.1.112` and
`wlan0 192.168.1.141`, both `default via 192.168.1.1`.

`install.sh` and `auto-update.sh` both write:

```
net.ipv4.conf.all.arp_ignore = 1     # answer only for addresses on the receiving iface
net.ipv4.conf.all.arp_announce = 2   # source ARP from the outgoing iface's address
```

A no-op when the interfaces are on different networks, so it is applied everywhere.

**To spot it:**

```bash
ip -br addr show                # two addresses on one subnet?
ip route | grep default         # two defaults via the same gateway?
```

If you want the radio off entirely on a permanently-wired display, `sudo nmcli radio wifi
off` also solves it — at the cost of the fallback.

## Turning WiFi on and off

```bash
/home/pi/wifi-off.sh      # wired displays only — refuses otherwise
/home/pi/wifi-on.sh
```

**`wifi-off.sh` refuses unless eth0 has carrier *and* holds the default route.** That
refusal is the point: `nmcli radio wifi off` on a display whose only link is WiFi does not
warn or ask — it disconnects the machine and leaves no way back but a keyboard and a
screen. NetControl is exactly that machine. `--force` overrides, for when you are standing
in front of it.

Carrier alone is not accepted, because a cable into a dead switch has carrier and no path
— which is precisely when losing the radio hurts most.

Why turn it off on a wired display:

- Two interfaces on **one subnet** make the Pi answer ARP for both addresses on both, so
  inbound connections fail while everything the Pi initiates still works. Off is the
  simplest cure; the ARP guard is the one that lets you leave it on.
- A Pi 4 shares one 2.4 GHz radio between WiFi and Bluetooth, so a busy link costs
  Bluetooth range — which matters when pairing a mouse.

The cost is the fallback: with the radio off, pulling the cable takes the display off the
network entirely. `nmcli radio wifi off` **persists across reboots**, so a display switched
off during maintenance stays off until somebody runs `wifi-on.sh` — which is why that
script exists and why it reports what it associated with rather than just exiting.

## Diagnostics

Shared with the iGates, the Transcribers and the server — they live in `common/` in the
repo and arrive in `/home/pi/` with everything else.

### power-check.sh

**Run this first for any reboot, freeze, or "random" fault.** Marginal power does not
announce itself; it presents as the symptom of whatever else was happening at the time —
a USB drive that "does not work", a receiver that "goes deaf", a display that "reboots at
random". BigTV has been brought down by it twice.

```bash
/home/pi/power-check.sh
```

Reports a grade, the throttle word, temperature, and the count of kernel under-voltage
lines. `throttled=0x0` is healthy — but note the bits are latched **since boot** and are
cleared by every reboot, so `0x0` on a machine that keeps rebooting means "not yet this
boot", not "the power is fine". It runs nightly from `auto-update.sh` as well, minutes
before the 4:10 reboot wipes the evidence.

### nettest.sh / netreport.py

Separates the WiFi link (Pi → access point) from the path beyond it, so a flaky uplink can
be told apart from a flaky wireless link. Both matter on a display that roams.

```bash
/home/pi/nettest.sh 600 kitchen-ap      # probe for 10 minutes, label the run
/home/pi/netreport.py <run-dir>         # loss, latency, jitter, located outages
```

### nethogs.sh

Per-process network bandwidth, for when something is using the link and it is not obvious
what.

```bash
sudo /home/pi/nethogs.sh
```

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

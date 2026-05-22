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
```

View with: `crontab -l`   Edit with: `crontab -e`

---

## check-netbird.sh

Runs every 5 minutes via cron. Queries the NetBird enable/disable endpoint:

```
GET https://marsaprs.org/netbird/?hostname=$HOSTNAME
```

Response `1` enables NetBird (starts rpcbind, avahi-daemon, systemd-timesyncd, NTP,
then `netbird up --enable-lazy-connection`).
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
sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority \
  chromium --password-store=basic --kiosk --noerrdialogs \
  --disable-infobars --disable-dev-shm-usage --incognito \
  --disable-features=BlockInsecurePrivateNetworkRequests \
  'https://marsaprs.org/' &

# Restart Chromium via connecting page (graceful)
sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority \
  chromium --password-store=basic --kiosk --noerrdialogs \
  --disable-infobars --disable-dev-shm-usage --incognito \
  --disable-features=BlockInsecurePrivateNetworkRequests \
  'http://localhost:8080/' &
```

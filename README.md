# MARS APRS System

**Author:** Doug Kaye (K6DRK) · **Copyright:** 2026 Doug Kaye. All Rights Reserved.

**Version:** Server & Displays (v1.22.1); Mobile App (v1.22.1); iGates (v5.2); Transcribers (v1.0) — see [Versioning](#versioning)

---

## Table of Contents

1. [Overview](#overview)
   - [Versioning](#versioning)
2. [System Architecture](#system-architecture)
3. [NetBird VPN](#netbird-vpn)
4. [iGates (v5.2)](#igates-v52)
   - [iGate Diagnostics](#igate-diagnostics)
5. [iGate Aggregation Relay](#igate-aggregation-relay)
   - [The problem it solves](#the-problem-it-solves) · [How it works](#how-it-works-the-data-path) · [Why it runs on a VPS](#why-it-runs-on-a-vps-not-at-home) · [Cloudflare DNS](#cloudflare-dns-for-the-relay) · [Unique per-gate logins](#unique-per-gate-logins-required) · [Turning it on/off](#turning-it-on-or-off-for-a-gate) · [Components](#components-and-where-they-live)
6. [APRS Server (v1.22.1)](#aprs-server-v1221)
   - [Cloudflare Tunnel](#cloudflare-tunnel)
7. [Display Pis (v1.22.1)](#display-pis-v1221)
   - [Running a display Pi on Starlink](#running-a-display-pi-on-starlink)
8. [Mobile Apps (v1.22.1)](#mobile-apps-v1221)
   - [Architecture](#app-architecture) · [Location Sharing Flow](#location-sharing-flow) · [Smart Track](#smart-track) · [Building & Distributing](#building-distributing) · [Background Location](#background-location)
9. [Transcribers](#transcribers)
10. [User Interfaces](#user-interfaces)
11. [Authentication](#authentication)
12. [Analyzer](#analyzer)
   - [Architecture](#analyzer-architecture) · [Authentication](#analyzer-authentication) · [Beacon Recording](#beacon-recording) · [Map & Controls](#map-controls) · [Key Files](#analyzer-key-files) · [Services](#analyzer-services) · [API Endpoints](#analyzer-api-endpoints)
13. [Backup, Recovery and Updates](#backup-recovery-and-updates)
   - [Server Pi](#server-pi) · [Display Pis](#display-pis) · [iGates](#igates)
14. [Log Rotation](#log-rotation)
15. [Building & Deploying Devices](#building-deploying-devices)
    - [NetBird Setup Keys](#netbird-setup-keys) · [APRS Server](#aprs-server) · [Display Pis & iGates](#display-pis-igates)
16. [Creating New Master Images](#creating-new-master-images)
    - [APRS Server](#aprs-server_1) · [Display Pis](#display-pis_1) · [iGates](#igates_1)
17. [Supporting Systems](#supporting-systems)
    - [NetBird Status Monitor](#netbird-status-monitor) · [WiFi Manager](#wifi-manager)
18. [Appendix](#appendix)
    - [File Formats](#file-formats) · [Server](#server) · [Display Pi](#display-pi) · [iGate](#igate) · [Pi-Tools](#pi-tools)
19. [Testing](#testing)

---

## Overview

This document is intended for a technical audience who want to understand the inner workings of our APRS system. Users are encouraged to view the User Guide at [https://marsaprs.org/userguide.html](https://marsaprs.org/userguide.html?back=/readme.html).

The MARS APRS System provides real-time position tracking for MARS (Marin Amateur Radio
Society) public-service events. Operators carry APRS trackers that
transmit their GPS position over radio. The system receives those transmissions, publishes
them to a live web map, and gives net control a browser-based view of all tracker positions
updated every five seconds.

Three Raspberry Pi device types work together. **iGates** are devices that
receive APRS transmissions on 144.39 MHz and forward decoded packets to the APRS-IS internet
network. The **APRS Server** pulls packets from APRS-IS, maintains tracker state, and serves
the web map and all supporting tools. **Display Pis** are screens at net control and
other locations that show the live map in fullscreen. Anyone is free to connect to [https://marsaprs.org](https://marsaprs.org) to view the current default event. We expect this will be used by aid station, rest stop and other personnel as well as anyone interested in the events.

All devices are connected over a NetBird WireGuard VPN, which provides remote SSH access,
device health monitoring, and nightly configuration distribution — without requiring port
forwarding or static public IP addresses.

### Versioning

Four version numbers, which look like drift and are not. The rule is that **things
sharing a number are things that ship together**, and everything else carries its own.

| What | Version | Cadence |
|------|---------|---------|
| Server, Display Pis, web map, mobile apps | `1.22.1+42` | One release. They are one API contract and one deploy. |
| iGates | `5.2` | Independent. Its own image, its own nightly update. |
| Transcribers | `1.0` | Independent, and new. |

**Server, web and mobile share a number** because they genuinely move together: a
release changes `WEB_VERSION` in `map/index.php`, `version` in `app/pubspec.yaml`, and
`map/app_version.php` in one go. The `+N` build number is required by App Store Connect
to increase on every upload, so it advances faster than the marketing version and is
bumped on every build rather than every release.

**iGates keep their own line, and should not be folded in.** The major version is a
migration boundary — `migrate-to-v5.sh` exists because v4→v5 broke the device's on-disk
layout — and renumbering to match the server would discard that meaning. More
practically, an iGate does not change when the web app does. Dragging it to 1.23, 1.24,
1.25 while no iGate file is touched would stop the number answering the only question
anyone asks of it: which build is on that Pi in the field. `IGATE_VERSION` in
`igate/auto-update.sh` is the source of truth, and each gate stamps it into its own
dashboard so it reports what it is actually running.

**Transcribers start at 1.0** for the same reason, and because starting a brand-new
device at 1.22.1 would assert twenty-two releases that never happened. `VERSION` in
`transcriber/bin/transcriber.py` is the source of truth; `auto-update.sh` writes it to
`/etc/transcriber/version` so a device can be identified without running anything.

**None of these govern compatibility.** That is `API_VERSION` / `API_MIN_CLIENT` in
`map/index.php:31`, which is the wire-format contract and is deliberately decoupled from
all of the above — a client is supported while the server's `min_client` is at or below
the client's built-in version, regardless of what any marketing number says. Bump
`API_VERSION` only on a breaking change to a response shape; additive fields do not
require it. Version numbers are labels for people; this pair is the one the software
reads.

---

## System Architecture

```
APRS Radio (144.39 MHz)
      │
      ▼
┌─────────────────────┐
│  iGate  (×N)        │  Pi Zero 2 W · v5.1
│  RTL-SDR dongle     │
│  direwolf TNC       │
│  direwatch.py       │
└──────────┬──────────┘
           │ TCP 14580
           ▼
┌──────────────────────────────────────┐     ┌──────────────────────────────┐
│           APRS-IS Network            │◀────│  Mobile App  (iOS/Android)   │
│         noam.aprs2.net:14580         │     │  Flutter v1.22.1               │
└────────────────┬─────────────────────┘     │  TCP 14580 (inject position) │
                 │ TCP 14580                 └──────────────┬───────────────┘
┌────────────────▼─────────────────────┐                    │ HTTPS (map + config + session)
│       APRS Server  (aprs-pi)         │  Pi 4 · v1.22.1      │
│  aprsDaemon.php → trackers.json      │◀───────────────────┘
│  Apache + PHP · netbird/ · wifi/     │
│  marsaprs.org  (Cloudflare Tunnel)   │
└──┬───────────────────────────────────┘
   │ HTTPS via Cloudflare
┌──▼─────────────────────┐
│  Display Pi  (×2)      │  Pi 4 · v1.22.1
│  Chromium fullscreen   │
│  marsaprs.org          │
└────────────────────────┘

```

| Component | Runs on | Purpose |
|-----------|---------|---------|
| `direwolf` | iGate | AX.25 TNC; decodes RF packets; forwards to APRS-IS |
| `direwatch.py` | iGate | Drives TFT status display |
| `aprsDaemon.php` | Server | Pulls from APRS-IS; writes `trackers.json`, `igates.json`, `aidstations.json` |
| `index.php` | Server | Serves the live map; JSON polling endpoint |
| `tiles.php` | Server | Map-tile proxy + cache; serves OpenStreetMap tiles from a pre-seeded permanent base plus an on-demand browse cache, so clients never fetch from OSM directly |
| `admin/index.php` | Server | Admin UI; event and tracker management |
| `netbird-poller.py` | Server | Polls all Pi devices over VPN; writes `stats.json` |
| `wifi/` | Server | Master WiFi credential store; distributes to all Pis |
| Chromium | Display Pi | Fullscreen kiosk pointing at `marsaprs.org` |
| Flutter app | iOS / Android | Native map + location sharing client; injects APRS positions directly to APRS-IS |

---

## NetBird VPN

[NetBird](https://netbird.io) is a WireGuard-based mesh VPN. Every MARS Pi device — iGates
at remote locations, the server, and display Pis — is enrolled in the same NetBird network
and gets a stable private IP address (100.x.x.x range) that never changes regardless of
which WiFi network the device is on.

**Why NetBird:**

By using NetBird addresses for all our devices, they can communicate with one another without regard to how they're connected to the internet. NetBird can find them wherever they are. It also allows us to reach them from a laptop or other NetBird-capable device for maintenance, diagnostics, etc. We specifically use NetBird addressing for gathering statistics and operational data from devices in the field.

- **Remote SSH** — connect to any Pi from anywhere with no port forwarding or public IP
- **Device monitoring** — the NetBird status page polls every device on its VPN IP
- **iGate enable/disable** — each iGate queries `marsaprs.org/netbird/?hostname=<call>`
  every 5 minutes to check whether NetBird should be enabled or not
- **WiFi credential distribution** — `auto-update.sh` downloads `wifi.yaml` from the server
  over the VPN each night and applies it via `nmcli`
  
**The Problem with NetBird:**
In order for NetBird to keep track of devices, those devices must frequently announce themselves to the NetBird servers. Unfortunately, this generates a great deal of internet traffic -- more than the traffic generated by our devices themselves. In some permanent iGate locations we use cellular hotspots to connect to the internet. Our hotspot provider gives us 1GB/month free of charge. The problem is that the NetBird traffic can easily surpass that limit.

We have therefore implemented a system that allows us to turn NetBird on and off remotely. Each device contacts the marsaprs.org server every five minutes to see if should turn its NetBird service on or off. This is controlled by the WiFi admin page on our server. A user with the admin password can use this admin page to enable or disable NetBird on any remote device. The device will act on any changes at its next five-minute interval. Note that we generally keep NetBird off for any permanently located iGate that uses a cellular hotspot. This isn't necessary for temporary or "guerrilla" iGates since they're usually on for less than 24 hours per event.

---

## iGates (v5.2)

An iGate receives APRS radio packets and forwards them to the APRS-IS network. Each iGate
is a Raspberry Pi Zero 2 W with a USB RTL-SDR dongle listening on 144.39 MHz. An optional
1.3-inch TFT display shows boot status and countdown timers for fault conditions.

**Architecture:**

```
144.39 MHz RF
      │
      ▼
RTL-SDR USB dongle
      │
      ▼
direwolf  (TNC + iGate daemon)
      │
      ├──▶ APRS-IS  (noam.aprs2.net:14580)
      │      (or, if the aggregation relay is enabled on this gate, direwolf
      │       points at the local isproxy on 127.0.0.1 instead — see
      │       "iGate Aggregation Relay" below)
      │
      └──▶ direwatch.service  (display manager)
                 │
                 ├──▶ TFT display (status screens)
                 └──▶ UDP port 1235 → server (health monitoring)

igate-watchdog.sh  (cron, every minute)
      ├── SDR presence check → dw-nosdr.py (every 2 min, then 2-min countdown + reboot)
      └── Internet check (every 5 min) → dw-nointernet.py (2-min countdown + reboot)
```

**Watchdog:**

`igate-watchdog.sh` runs every minute via cron and handles three health checks:

- **SDR** — runs `lsusb` looking for RTL-SDR USB IDs (`0bda:2838`, `0bda:2832`, `RTL28`). If the SDR is absent, logs the event and (if a TFT display is attached) launches `dw-nosdr.py`, which shows a 2-minute countdown and reboots. If the SDR reappears while direwolf is down, restarts direwolf immediately.
- **IP address** — logs a warning if the device has no IP address.
- **Internet** — every 5 minutes, if NetBird is connected, pings `8.8.8.8`. On failure, launches `dw-nointernet.py` (same 2-minute countdown + reboot pattern).

The watchdog suppresses all checks while `dw-startup.py` is running (boot sequence), while a reboot is already pending (`/tmp/aprs-rebooting`), or while `sdr-usb-test` is running (`/tmp/sdr-usb-test.pause` — see [iGate Diagnostics](#igate-diagnostics) below), so a diagnostic that owns the SDR is never fought by the watchdog restarting direwolf. TFT presence is detected by reading GPIO 23: `pinctrl get 23 | grep -q hi`.

Log: `/var/log/direwolf/watchdog.log`

### iGate Diagnostics

Two tools help keep the fleet's receivers healthy — one automatic, one on demand.

**SDR self-noise self-test (automatic).** `igate-selftest.sh` runs nightly (from
`auto-update.sh`, before the 4:10 am reboot) and can also be run by hand
(`bash ~/igate-selftest.sh`). It briefly stops direwolf, sweeps 144–148 MHz with
`rtl_power`, and measures the level of any internal birdie (self-generated spur) in the
APRS guard band (144.37–144.42 MHz) relative to the surrounding noise floor. It grades the
receiver **GOOD / MARGINAL / BAD**, writes the result to `~/selftest.json`, and uploads it
to the fleet dashboard at **`https://marsaprs.org/igate/selftest/`**. The dashboard lists
each gate by **callsign and name**, its grade, guard-band spur in dB, noise floor, and last
report time, and flags the quietest receiver as "Best." A MARGINAL/BAD grade usually means
RF self-noise coupling into the SDR (shielding/placement) — but a flaky dongle or bad USB
connection can fake it too, so reseat the dongle before assuming a shielding problem.

**Flaky-USB dongle test, `sdr-usb-test` (on demand).** Some RTL-SDR dongles have cracked
USB-A solder joints or worn connectors and drop off the USB bus intermittently. On an iGate
that shows up as "SDR Not Found" and reboot loops. `sdr-usb-test` isolates the dongle and
watches the kernel USB log for spontaneous disconnects, then for disconnects you induce by
wiggling the connector. It is standalone: it pauses the watchdog
(`/tmp/sdr-usb-test.pause`), stops direwolf, and streams from the dongle to load the USB
link like real operation, restoring everything on exit.

```bash
sdr-usb-test                 # 5-min passive test, then a 20-s wiggle test
sdr-usb-test --quick         # 60-s passive + 15-s wiggle (fast check)
sdr-usb-test --no-wiggle     # passive only (also auto-selected with no TTY)
sdr-usb-test 120 15          # custom passive / wiggle seconds
```

Verdicts: **PASS** — no disconnects in either phase (healthy); **FAIL** — drops on its own
→ replace the dongle; **SUSPECT** — stable at rest but drops when disturbed → reseat the
plug/adapter, else replace. The source lives at `igate/home/sdr-usb-test.sh` in this repo;
`install.sh` and `auto-update.sh` symlink it to `/usr/local/bin/sdr-usb-test` so it runs
from anywhere. Log: `~/sdr-usb-test.log`.

**Key files on the iGate Pi:**

| File | Location | Purpose |
|------|----------|---------|
| `direwolf.conf` | `/home/pi/` | TNC frequency, callsign, APRS-IS login, filter |
| `configure.sh` | `/home/pi/` | Interactive configuration wizard |
| `auto-update.sh` | `/home/pi/` | Nightly script + WiFi credential update |
| `igate-watchdog.sh` | `/home/pi/` | Cron watchdog (SDR + internet checks) |
| `direwatch.py` | `/home/pi/direwatch/` | APRS-IS connection + TFT display manager |
| `dw-startup.py` | `/home/pi/direwatch/` | Boot sequence display (stats + SDR check) |
| `dw-nosdr.py` | `/home/pi/direwatch/` | "No SDR found" countdown display |
| `dw-nointernet.py` | `/home/pi/direwatch/` | "No internet" countdown display |
| `StatsRequestListener.php` | `/home/pi/` | UDP responder for NetBird monitor |
| `igate-selftest.sh` | `/home/pi/` | Nightly SDR self-noise test → fleet dashboard |
| `sdr-usb-test.sh` | `/home/pi/` (→ `/usr/local/bin/sdr-usb-test`) | Flaky-USB dongle test (run over SSH) |
| `isproxy.py` / `isproxy.json` | `/home/pi/` | Local APRS-IS failover proxy for the aggregation relay (ships inert; active only when enabled — see [iGate Aggregation Relay](#igate-aggregation-relay)) |
| `.isproxy-enabled` | `/home/pi/` | Sentinel file that opts this gate into the aggregation relay |

**SSH:** `ssh pi@<ip>` · Password: `guacamole`

---

## iGate Aggregation Relay

This is an **optional, opt-in** subsystem that lets us see our own iGates' received
traffic *before* the public APRS-IS network throws most of it away. It is off by default;
a gate only participates when we explicitly enable it. Everything below was designed and
first deployed in August 2026.

### The problem it solves

The public APRS-IS network **de-duplicates** packets. When several iGates all hear the same
station and forward the same packet, APRS-IS keeps only the *first* copy and credits only
the *first* iGate (via the packet's "q-construct"). Every other iGate that heard that packet
gets no credit for it.

The practical consequence: an iGate that is **usually the second** to hear traffic — or a
receive-only gate in an area already well covered by others — looks *idle* on the public
feed even when it is fully online and doing its job. We could not tell "this gate is dead"
apart from "this gate is healthy but always beaten to the punch." For fleet monitoring, that
distinction matters.

The aggregation relay fixes this by capturing our controlled gates' radio→internet stream
**before** it reaches the public de-duplicator, so we see *every* packet *every* one of our
gates gates, undeduped, and can prove each gate is alive.

### How it works (the data path)

```
   RF 144.39 → direwolf → isproxy (127.0.0.1)  ── on each ENABLED iGate
                              │
                              │  primary: relay.marsaprs.org:14590   (our relay)
                              │  fallback: noam.aprs2.net:14580       (public APRS-IS)
                              ▼
              igate-isrelay  (DigitalOcean VPS, public IP)
                              │
                              ├──▶ APRS-IS (noam.aprs2.net:14580)   ← still really gates
                              │
                              ├──▶ igate_relay.json   {callsign: last_gated_unix_ts}
                              └──▶ capture.jsonl       every gated packet, undeduped
                                        │
              aprs-pi: igate-relay-sync.service  ── pulls igate_relay.json every 20 s
                                        │
                                        ▼
                          /var/www/html/igate_relay.json
                                        │
                          map/index.php merges it into ?json  →  web sidebar
```

In plain English:

1. On an **enabled** gate, direwolf no longer talks to APRS-IS directly. Instead it connects
   to a tiny local proxy, **`isproxy.py`**, listening on `127.0.0.1`. This is invisible to
   direwolf — it thinks it is talking to a normal APRS-IS server.
2. `isproxy` forwards that stream to **our relay** on the VPS (its *primary* upstream). If the
   relay is ever unreachable, `isproxy` automatically falls back to the real public APRS-IS,
   so **gating never depends on our server** — the worst case is we lose the undeduped
   capture for that gate, not its ability to gate.
3. The relay, **`igate-isrelay.py`**, does two things with each gate's stream: it **records**
   every gated packet (attributing it to the gating station via the q-construct), and it
   **forwards** the stream on to the real public APRS-IS so the gate keeps doing its normal
   job. Recording happens before that forward, so it is undeduped.
4. The relay writes `igate_relay.json` (a simple `{callsign: last-gated-timestamp}` map, the
   same shape as the server's `igates.json`) and appends every packet to `capture.jsonl`.
5. On the server Pi, **`igate-relay-sync.service`** copies `igate_relay.json` over every 20 s,
   and `map/index.php` merges it into the map feed so the sidebar shows the gate as active
   from *relay* data even when the public feed would show it idle.

> **Does the sidebar update for every beacon a gate hears? No.** The timestamp only advances
> when the gate actually *gates a packet to APRS-IS* — not for packets it merely receives and
> then drops (non-position, heard via a digipeater, filtered out, or a direwolf duplicate).
> The relay also throttles its file write to at most once every 3 s, and the sync runs every
> 20 s, so the sidebar is a **liveness indicator** ("this gate is gating"), not a per-packet
> feed. The full per-packet, undeduped record is in `capture.jsonl` on the VPS.

### Why it runs on a VPS (not at home)

The natural place for the relay would be the server Pi (`aprs-pi`) at home. **It can't live
there**, and understanding why is the key to this whole design:

- The home internet is **Comcast with an IPv6-only WAN (DS-Lite)**. There is no usable
  inbound IPv4 — IPv4 is carried over the carrier's network and NAT'd on *their* side, so we
  cannot port-forward IPv4 to anything at home. (This is also why `marsaprs.org` itself uses a
  Cloudflare Tunnel: an *outbound* connection, because inbound doesn't work.)
- IPv6 *does* work end-to-end and has no NAT, but the home router is an **eero**, and eero's
  app provides **no way to open an inbound IPv6 firewall pinhole**. So even over IPv6 we
  cannot let outside gates connect in to a service at home.
- The Cloudflare Tunnel only carries **HTTP**. It cannot expose the relay's raw TCP port
  (14590) to the gates.

So the gates — which live on cellular connections all over the county — have **no way to
reach a relay hosted at home**. The fix is to put the relay somewhere with a real, public,
inbound-reachable IP: a small cloud server.

**The VPS:** a **DigitalOcean** droplet named `aprs-relay`, region **SFO3**, Ubuntu 24.04,
the smallest tier (the relay is a trivial Python program). It has a public IPv4 and IPv6,
which gates can reach directly with no NAT in the way.

| | |
|--|--|
| Public IPv4 | `64.23.166.192` |
| Public IPv6 | `2604:a880:4:1d0:0:3:3d0f:6000` |
| Relay service | `igate-isrelay.service`, runs as user `isrelay` from `/opt/igate-isrelay/` |
| Listens | `:14590`, dual-stack (accepts gates over both IPv4 and IPv6) |
| Firewall | `ufw` allows `22` (SSH) and `14590` (relay) only |
| SSH | `ssh root@64.23.166.192` using the Mac's `~/.ssh/id_ed25519` key |

### Cloudflare DNS for the relay

Gates connect to the relay by the name **`relay.marsaprs.org`**, which points at the VPS:

- an **A** record → `64.23.166.192`
- an **AAAA** record → `2604:a880:4:1d0:0:3:3d0f:6000`

Both are **DNS-only (grey cloud), *not* proxied.** This is important: Cloudflare's orange-cloud
proxy only understands HTTP, so a proxied record would break the relay's raw TCP. The records
live in the `marsaprs.org` zone and are edited from the Cloudflare dashboard, or via the API
with a token scoped to *Zone → DNS → Edit* on that zone. Using a name (not the bare IP) means
if the VPS is ever rebuilt, only these two records change and no gate needs reconfiguring.

### Unique per-gate logins (required)

APRS-IS allows **only one connection per callsign-SSID** at a time; a second login with the
same identity kicks the first. Historically our gates logged in with the **bare base call**
(every MARS gate as `MARS`, each personal gate as its own base call). That was fine when each
gate held a single direct connection, but the relay opens *its own* upstream per gate — so
with a shared login those upstreams fight each other, flapping every second and even knocking
field gates off their servers.

The fix is to give every gate a **unique login = its full callsign with SSID** (`MARS-5`,
`MARS-13`, `KI6RGP-10`, …). The APRS-IS **passcode is derived from the base call only** and
ignores the SSID, so *the same passcode still works* — `MARS`, `MARS-5` and `MARS-13` all use
passcode `27888`. A bonus: the public q-construct now shows the real gate (`qAO,MARS-13`)
instead of a uniform `MARS`.

This is applied automatically:

- **`auto-update.sh`** contains an idempotent block that promotes `IGLOGIN` from the base call
  to the full `MYCALL`, keeping the passcode. It only acts when the two share a base call, so
  it can never create a passcode mismatch. It runs on every gate at the nightly update.
- **`configure.sh`** (the setup wizard) now defaults `IGLOGIN` to the full callsign for new
  installs, and its passcode calculator strips the SSID before hashing.

### The local proxy (`isproxy`) and failover

`isproxy.py` is a small stdlib-only asyncio program. direwolf connects to it on
`127.0.0.1:14580` (set by `IGSERVER 127.0.0.1` in `direwolf.conf`). It keeps **one** upstream
connection at a time and pipes bytes transparently in both directions:

- **Primary** = the relay (`relay.marsaprs.org:14590`); **fallback** = public APRS-IS
  (`noam.aprs2.net:14580`).
- It fails over to the fallback on connect failure or staleness (no data for 60 s), and
  switches back to the primary once it is healthy again, with hysteresis so it can't flap.
- It replays direwolf's login line on each new upstream, so switches are invisible to direwolf.
- **It speaks the APRS-IS server side of the handshake locally**, so direwolf stays
  connected regardless of upstream latency. Two pieces, both learned the hard way:
  (a) a synthetic `# igate-isproxy` **banner** the instant direwolf connects — a real
  server greets with a `# …` banner *before* the client logs in, and direwolf waits for
  it; and (b) a synthetic `# logresp <call> verified` **the instant direwolf sends its
  login** — otherwise direwolf disconnects immediately after logging in, before the real
  upstream can connect and relay the real logresp. Without (a), direwolf and the proxy
  deadlocked; without (b), only low-latency (LAN) gates won the race while cellular gates
  churned (connect → login → disconnect every ~15 s, never gating). Our logins are all
  verified, so the local `verified` is accurate; the real upstream banner/logresp still
  flow through afterward.

Configuration is `isproxy.json`; a live status file `isproxy.status.json` records which
upstream is currently in use (`primary`/`fallback`).

**Reconnect (fixed 2026-08-07, commit `e77e8cc`).** The shutdown flag for a direwolf session
was never cleared when that session ended, so every reconnect after the first was a no-op:
`handle_direwolf` still ran — banner, cached login, synthetic logresp, which is why the logs
looked half-alive — but the upstream supervisor's `while not self.stop` exited immediately and
no upstream was ever opened. The gate then churned (connect, login, drop) every ~15 s without
gating anything.

`auto-update.sh` restarts `igate-isproxy` **before** direwolf, so the proxy's first session is
the *outgoing* direwolf, killed seconds later by that restart — meaning **every nightly update
wedged every relay-enrolled gate** until someone restarted the service by hand. The fix clears
the flag per session, with a session counter so a session that is closing down cannot clear it
out from under a newer one that has already taken over.

`igate/tests/test_isproxy.py` drives two sequential direwolf sessions against a fake APRS-IS
and asserts both reach the upstream; it fails on the old code and passes on the new. This
matters most for the cellular gates, whose NetBird is disabled by policy most of the time — a
wedged proxy there stops gating *and* leaves no way in until NetBird is toggled on from the
server and the next 5-minute `check-netbird.sh` poll runs.

### Turning it on or off for a gate

Activation is **sentinel-guarded** so nothing changes on a gate unless we ask. The proxy
files ship to every gate but stay **inert** until the sentinel exists:

```bash
# Enable the relay on a gate:
ssh pi@<gate>  touch /home/pi/.isproxy-enabled
# then run the updater (or wait for the nightly run):
ssh pi@<gate>  sudo /home/pi/auto-update.sh

# Disable / roll back:
ssh pi@<gate>  rm /home/pi/.isproxy-enabled
ssh pi@<gate>  sudo /home/pi/auto-update.sh
```

When the sentinel is present, the `auto-update.sh` block: (1) promotes `IGLOGIN` to the unique
login (above); (2) backs up `direwolf.conf`, rewrites `IGSERVER` to `127.0.0.1`, saving the
original for rollback; (3) enables and starts `igate-isproxy`; (4) restarts direwolf. When the
sentinel is removed, the same block cleanly reverses all of that — restores the original
`IGSERVER`, disables the proxy, and direwolf goes back to talking to APRS-IS directly. Because
`isproxy` always falls back to public APRS-IS, **enabling a gate before DNS/relay are reachable
is harmless** — it just gates the normal way until the relay is up.

**Fleet-wide enrollment (central control).** SSHing to each gate doesn't scale (and most of
the fleet is only intermittently reachable over NetBird), so `auto-update.sh` also reads a
single server-side flag, **`https://marsaprs.org/igate/isproxy-enroll.txt`**, and creates or
removes the sentinel to match it — so the whole fleet enrolls or withdraws from one file, and
each gate applies it on its next nightly run. The file holds one token per line:

```
ALL          # enroll the entire fleet
# MARS-3     # …or list specific MYCALLs, one per line, to stage a rollout
# MARS-5
```

`ALL` enrolls every gate; a list enrolls just those `MYCALL`s; commenting out everything
(or an empty/unreachable file) is **fail-safe** — gates leave their current state untouched,
so a network blip never mass-disables the fleet. **To pause a rollout,** comment out `ALL`.
**To roll the whole feature back,** set the file to withdraw (no active tokens) and the gates
disable themselves on their next update. Only **v5** gates activate (the block is v5-guarded);
on a v4 gate the sentinel is harmless. Because of the self-updating two-run pattern, a gate
running an `auto-update.sh` that predates this flag picks the flag up the *following* night.

### Components and where they live

| Component | Host | Path / unit | Role |
|-----------|------|-------------|------|
| `igate-isrelay.py` | VPS `aprs-relay` | `/opt/igate-isrelay/` · `igate-isrelay.service` | Aggregation relay: forwards each gate to APRS-IS under its own login, records undeduped |
| `igate_relay.json` | VPS | `/opt/igate-isrelay/` | `{callsign: last-gated-ts}`, pulled to the server |
| `capture.jsonl` | VPS | `/opt/igate-isrelay/` | Every gated packet, undeduped (for the analyzer, future) |
| `isproxy.py` / `.json` | each iGate | `/home/pi/` · `igate-isproxy.service` | Local failover proxy; ships inert, active only when enabled |
| `.isproxy-enabled` | each iGate | `/home/pi/` | Sentinel opting the gate in |
| `igate-relay-sync.sh` | server `aprs-pi` | `/home/pi/` · `igate-relay-sync.service` | Pulls `igate_relay.json` from the VPS every 20 s |
| `relaypull` key | server → VPS | `~/.ssh/relaypull` → `relaypull@VPS` | Least-privilege SSH key; a forced command lets it *only* read `igate_relay.json` |
| `relay.marsaprs.org` | Cloudflare DNS | A + AAAA, DNS-only | Name the gates connect to |

Repo sources: relay + its unit and the sync service are under `server/bin/` and
`server/systemd/`; the proxy, its unit, and the activation logic are under `igate/home/`,
`igate/systemd/`, and `igate/auto-update.sh`.

**Rollback of the whole feature:** remove `~/.isproxy-enabled` from every enabled gate and run
their updater (returns them to direct gating); the relay and sync can then simply be stopped.
No gate's ability to gate ever depended on any of this.

---

## APRS Server (v1.22.1)

The server is a Raspberry Pi 4 running Apache and PHP. It receives APRS packets from
APRS-IS, maintains live tracker state, serves the web map and admin tools, and hosts the
NetBird monitor and WiFi credential manager. It is reachable at `marsaprs.org` via a
Cloudflare Tunnel — no inbound port forwarding or static IP required.

Note that anyone can always view the then-current event at https://marsaprs.org. No pasword is required.

**Architecture:**

```
APRS-IS (noam.aprs2.net:14580)
      │ TCP
      ▼
aprsDaemon.php  ──writes──▶  trackers.json
      │                            │
      └── reads ──▶ config.yaml ◀──┘
            │  (symlink → events/<Event>/event.yaml)
            ▼
       index.php  ◀── browser polls every 5 s
            │
       admin/index.php  (event + tracker management)

netbird/daemon.php  ──UDP──▶  all Pi devices (port 1235)
       └──writes──▶  netbird/stats.json

wifi/index.php  (credential editor)
       └──▶  wifi.yaml  (downloaded nightly by all Pis)
```

**Key files:**

| File | Role |
|------|------|
| `aprsDaemon.php` | Background daemon; APRS-IS connection; writes `trackers.json`, `igates.json`, `aidstations.json` |
| `config.yaml` | Symlink → active event's `event.yaml` |
| `trackers.json` | Live tracker state (daemon writes, browser reads) |
| `igates.json` | iGate last-beacon timestamps (daemon writes, browser reads) |
| `aidstations.json` | Aid station last-beacon timestamps (daemon writes, browser reads) |
| `index.php` | Map page + JSON/config/history endpoints |
| `admin/index.php` | Admin UI; all admin API endpoints |
| `events/<Name>/event.yaml` | Per-event configuration |
| `mobile_trackers.json` | Active mobile participant sessions (token, callsign, last update, `pending_msgs` queue, `aprs_lat`/`aprs_lon`/`aprs_ts` for APRS-IS dedup) |
| `/var/lib/marsaprs/messages.db` | Messaging store (SQLite, event-scoped); written by `?messaging=send` / `?mobile=message`. Photo attachments under `/var/lib/marsaprs/photos/<E>/`. Replaces the old per-event `messages.json`. |
| `/run/aprs/web_sessions.json` | Active web operator tokens (RAM disk; cleared on reboot) |

Each event's configuration lives in `events/<EventName>/event.yaml`. `config.yaml` is a
symlink to the active event's file.

### Cloudflare Tunnel

All traffic to `marsaprs.org` is routed through a Cloudflare Tunnel. The Pi establishes
an outbound HTTPS connection to Cloudflare's edge — no inbound firewall rules needed, and
the Pi works on any network including cellular.

```
Browser → Cloudflare edge (marsaprs.org)
                  │ tunnel
          cloudflared (aprs-pi) → Apache :80
```

Tunnel ID: `b11404f2-7497-4822-92b4-f75db418a1fe`

Config at `/etc/cloudflared/config.yml` (repo: `server/cloudflared/config.yml`):

```yaml
ingress:
  - hostname: marsaprs.org
    service: http://localhost:80
  - service: http_status:404
```

To reinstall with a new token:

```bash
sudo cloudflared service uninstall
sudo cloudflared service install <new-token>
sudo systemctl start cloudflared
```

The tunnel token is obtained from the **Cloudflare Zero Trust dashboard**:
*Networks → Tunnels → \<tunnel\> → Configure → Install and run connector*

---

## Display Pis (v1.22.1)

A display Pi is a Raspberry Pi 4 running Chromium in fullscreen mode, pointed at
`marsaprs.org`. It is a read-only display device — no long-term local configuration or data storage.
Two display Pis are in use: **NetControl** (operator screen) and **BigTV** (large audience
screen).

**Architecture:**

```
marsaprs.org  (via WiFi + Cloudflare)
      │ HTTPS
      ▼
Chromium  (fullscreen kiosk)
      │ localhost:8080
      ├──▶ kill-server.py   (handles /exit from kiosk mode)
      │
aprs-monitor.sh   (polls marsaprs.org every 30 s;
      │             relaunches Chromium via localhost:8080
      │             if server is unreachable)
wifi-watchdog.sh  (checks WiFi every 30 s;
                   calls wifi-restored.sh on reconnect)
```

**Autostart:** Chromium launches at boot via `~/start-kiosk.sh`, called from the LXDE
autostart file (`~/.config/lxsession/rpd-x/autostart`). The wrapper reads `~/autologin.txt`,
sets cursor size via `XCURSOR_SIZE=48`, and passes `--user-data-dir=/tmp/chromium` to keep
Chromium cache/state in RAM (tmpfs).

**Operator auto-login:** If `~/autologin.txt` exists, `start-kiosk.sh` appends `?autologin`
to the URL; if line 1 contains an operator name, it also appends `&operator=<name>` for
automatic messaging subscription. A **blank** first line means "use this machine's hostname",
so a fresh install is correct without anyone editing the file. The server handles
`?autologin` by setting a PHP session for the current event and redirecting to the clean
URL, with messaging credentials embedded as JS globals for auto-subscribe.

`configure.sh` prompts for the operator name alongside the hostname and writes this file.
That pairing exists because the two used to be set independently: a display renamed to
NetControl on 2026-08-07 carried on announcing itself to the app as `BigTV` for the rest of
the day, because nothing updated `autologin.txt` when the hostname changed. With two
displays in the fleet, both claiming one operator name breaks messaging auto-subscribe.

**Single kiosk instance:** several things launch the kiosk — the LXDE autostart at login,
`aprs-monitor` when it switches pages or finds no browser, and hands at the keyboard — and
nothing used to stop them stacking up. Two browsers loading the same map means double the
tile requests and double the CPU, which presents as a slow display on a perfectly healthy
network. `start-kiosk.sh` now refuses to start a second instance (a pre-flight check plus
`flock` to close the race). Chromium's own protection had been disabled by an `rm` of its
Singleton files, which was itself aimed at `~/.config/chromium` while the browser runs with
`--user-data-dir=/tmp/chromium` — so it had been guarding nothing.

> Counting kiosk instances is subtler than it looks. Matching `--kiosk` on the command line
> also matches Chromium's renderers and zygote, and the `flock` wrapper, since all of them
> carry the flag. A correct count requires the executable itself to be `chromium` **and** the
> process not to be a child (no `--type=`).

**Cursor size:** 48 px via `XCURSOR_SIZE=48` in `start-kiosk.sh` (applied before Chromium),
reinforced by `~/.Xresources` (`Xcursor.size: 48`) loaded via `@xrdb` in the LXDE autostart.

**SD card I/O:** Chromium profile to `/tmp/chromium` (tmpfs); journald `Storage=volatile`
keeps journal entries in RAM. Both configured via `files.tar.gz` and `install.sh`.

**SSH:** `ssh pi@<ip>` · Password: `guacamole` · VNC: `vnc://<ip>:5901`

### Running a display Pi on Starlink

Both display Pis share one Starlink terminal. A day of measurement (2026-08-07, ~300 samples
at 1/min plus 20 instrumented reboots) produced the following, and the reasoning matters more
than the settings because the symptoms are all misleading.

**Diagnose power before anything else.** A Pi 4 that reboots in a loop, reaching "Welcome to
Desktop" and then resetting silently — no panic, no log, the journal simply stops — is
browning out, not crashing. It fails at desktop start because that is peak draw: GPU,
compositor, Chromium and WiFi all at once. Confirm from the hardware, not the symptoms:

```bash
vcgencmd get_throttled      # 0x0 = healthy
                            # bits 0-2 = happening now; bits 16-18 = since boot (latched)
dmesg | grep -i undervoltage
```

Two supplies were inadequate before one worked, and the second failed *progressively*
(undervoltage events 3 → 6 → 12 in three minutes) without looping. **Use a 61 W USB-C supply**
with a short, thick cable directly into a wall socket. Moving the SD card to a different Pi 4
changed nothing — the board was never at fault.

**Strong signal does not mean a working link.** The most expensive wrong turn of the day was
trusting signal strength. A display measured `-49 dBm`, 72 Mbit/s negotiated and *zero* tx
failures while showing **2.7-second round trips to its own gateway** — every static indicator
healthy, the link unusable. The cause was airtime contention: it sat 24 inches from another
AP occupying the same 2.4 GHz channel. Relocating the display fixed it; no Pi-side setting
could have.

> **The single most useful check:** ping the device's *own gateway* **from the device**. A LAN
> hop must be under ~5 ms at 0% loss. That one number separates "the WiFi is broken" from
> "the WAN is slow" from "the server is slow", and nothing else does it as quickly.

**Prefer Ethernet where it exists.** A display wired to the router sidesteps this entire
section — band steering, co-channel contention, roaming to a stronger AP, and WiFi/Bluetooth
coexistence all stop applying. Route metrics handle it automatically (`eth0` at 100 beats
`wlan0` at 600), and WiFi stays associated as a silent fallback if the cable is pulled. The
WiFi logic below stands down on its own: `wifi-band-pin.sh` exits immediately unless `wlan0`
actually carries the default route, because every measurement it makes pings the default
gateway and would otherwise be judging the radio using the cable's numbers.

> Check the negotiated speed after wiring one up: `dmesg | grep 'eth0: Link is'`. A display
> here first came up at **10 Mbps half duplex** — the signature of a damaged cable or bad
> crimp — and would have carried map tiles well enough to look fine while being one knock
> from dropping. A replacement cable gave 1 Gbps full duplex.

**WiFi power save is disabled** on every profile `update-wifi.php` creates. NetworkManager
leaves it on by default, which parks the radio between beacons and delays *inbound* packets
specifically — so a device stays reachable outbound while inbound traffic stalls, which looks
like anything but a power-save setting. On a marginal signal it costs hundreds of milliseconds.

**Band selection is measured, not assumed** — see `wifi-band-pin.sh`. 5 GHz was unusable at
this site (`-73 dBm`, 100% packet loss) despite negotiating 351–390 Mbit/s, while 2.4 GHz on a
clear channel gave 0% loss at 3 ms. Starlink picks its own channels and moved between 1, 6, 11
and 153 during a single day, so neither band can be trusted in advance and a good choice does
not stay good.

**Warm boot is reliable; cold power-on is where faults appear.** Twenty instrumented reboots
produced a rendered map in **20–21 seconds every time** — zero failures, zero kiosk restarts,
zero roams. The startup failures that prompted the investigation were all cold power-on, which
draws far more current than `systemctl reboot`. If a display misbehaves at startup, test with
a cold power-cycle; a warm reboot will not reproduce it.

**A cloned SD card carries the source machine's identity.** It brings the NetBird enrolment
(two peers sharing one identity flap endlessly), `/var/lib/bluetooth/<adapter>/` (a Bluetooth
mouse paired to the *previous* Pi fails with `ConnectionAttemptFailed: Page Timeout`, and no
amount of resetting the mouse helps — the stale bond is on the Pi), plus hostname,
`autologin.txt`, SSH host keys and machine-id. Prefer a fresh `install.sh` per device.

For details on using the map, see [USERGUIDE.MD](https://marsaprs.org/userguide.html?back=/readme.html).

---

## Mobile Apps (v1.22.1)

Native iOS and Android apps are available as an alternative to the web map. The apps provide the same live tracker display as the web map, and support background location sharing — GPS position continues to be reported even when the screen is locked or the app is not in the foreground.

**Location:** the `app/` subdirectory of this repo (`app/lib`, `app/ios`, `app/android`, `app/pubspec.yaml`). It was merged in from the former standalone `aprs-map` repo, with history preserved.

**The Admin "Hide" toggle applies to both clients.** `index.php?json` has always carried a `hidden` flag per tracker; the app simply never read it, so a tracker hidden from the web map kept its marker on the phone. `TrackerData.showsOnMap` (position **and** not hidden — kept distinct from `hasPosition`, since a hidden tracker still has a position and the drawer still reports its age) now gates the marker, the drawer keeps the entry dimmed, and a tracker hidden while selected has its selection and breadcrumb trail dropped together — otherwise a trail is left drawn to nothing. Hiding is about map clutter, not reachability: a hidden tracker remains addressable in messaging on both clients.

### App Architecture

The app is a Flutter application with a hybrid architecture:

```
┌────────────────────────────────────────────────┐
│                 Flutter App                    │
│                                                │
│  ┌──────────────────────────────────────────┐  │
│  │  WebView  →  marsaprs.org/index.php      │  │  reads tracker data, config, courses
│  └──────────────────────────────────────────┘  │
│                                                │
│  ┌───────────────────┐  ┌────────────────────┐ │
│  │  Native Drawer    │  │  BackgroundLocation │ │
│  │  menu_drawer.dart │  │  Service           │ │
│  │  tracker_layer    │  │  background_       │ │
│  │  .dart            │  │  location.dart     │ │
│  └───────────────────┘  └────────┬───────────┘ │
└────────────────────────────────  │  ───────────┘
                                   │
              ┌────────────────────┼──────────────────────┐
              │ TCP 14580          │ HTTPS                 │
              ▼                    ▼                       │
    APRS-IS (noam)        marsaprs.org                    │
    inject position       ?mobile=join/update/leave       │
    packet                heartbeat + session mgmt        │
```

| File | Purpose |
|------|---------|
| `lib/map_screen.dart` | Root widget; hosts the WebView, JS bridge, native tracker/breadcrumb overlay |
| `lib/help_screen.dart` | Built-in Quick Start guide; displayed on first launch; accessible via Help → Quick Start in the drawer footer |
| `lib/menu_drawer.dart` | Native Flutter sidebar drawer; Help footer button shows modal (app info + Quick Start/User Guide/ticket buttons) |
| `lib/tracker_layer.dart` | Native map marker overlay; squares for mobile, circles for fixed; ID label next to each dot |
| `lib/arrow_painter.dart` | CustomPainter for breadcrumb directional arrows; isolated to avoid `Path<LatLng>` collision |
| `lib/course_layer.dart` | Course polyline overlay; fetches all courses in parallel |
| `lib/background_location.dart` | GPS stream; `_heartbeatTimer` (timed uploads) + `_maybeUploadFromStream()` (distance-triggered); calls `MobileSession` |
| `lib/background_task_handler.dart` | Android foreground service stub; no-op handler; keeps process alive |
| `lib/mobile_session.dart` | HTTP client for `?mobile=join/update/leave` API |
| `lib/aprs_client.dart` | Legacy — TCP socket to APRS-IS; no longer used (server-side injection replaced direct TCP) |
| `lib/remote_config.dart` | Polls `?config` endpoint; parses event configuration, including `offline_map.url` (the tile source) |
| `lib/map_config.dart` | Constants: server URL; default map-tile URL (the MARS tile proxy) |
| `android/app/src/main/AndroidManifest.xml` | Android permissions |
| `ios/Runner/Info.plist` | iOS background mode declaration |

### Offline Maps & Tile Proxy

Both the on-screen base map and the offline download pull map tiles from the **MARS tile proxy** (`marsaprs.org/tiles.php/{z}/{x}/{y}.png`) rather than from OpenStreetMap directly. This was introduced in 1.20.2 to fix first-run offline-map downloads: OpenStreetMap blocks bulk tile downloads per-IP, so the app's download of an event area would fail. Routing everything through our own server means each tile is fetched from OSM **at most once** (server-side, with a proper `User-Agent`), which both keeps the app working and is far gentler on OSM than every client fetching its own tiles.

**Server side (`map/tiles.php`)** serves each tile from, in order:

1. `tiles/base/` — a **permanent, pre-seeded** cache of event areas (e.g. Marin). Never auto-deleted; this is what offline event downloads pull from, so those downloads never touch OSM.
2. `tiles/cache/` — an **on-demand "browse" cache**, filled the first time anyone pans to an area outside the seeded regions.
3. **OpenStreetMap** — on a miss, fetched once, cached into `tiles/cache/`, and returned. If OSM is unreachable a transparent tile is returned with a short cache time so it retries soon.

**Seeding** (`map/tiles-seed.sh <minLat> <maxLat> <minLon> <maxLon> <minZoom> <maxZoom>`) politely fetches a lat/lon box into the permanent base cache (single-threaded, small delay, proper `User-Agent`). Marin z10–14 (`tiles-seed.sh 37.80 38.25 -123.05 -122.30 10 14`, ~1,300 tiles) is seeded.

**Cleanup** (`map/tiles-clean.sh`, nightly cron at 04:17) trims the browse cache — deletes tiles not re-fetched in 90 days — which bounds the cache size while keeping popular areas fresh; it **never** touches `tiles/base`.

**Config override:** the tile URL is the server config's `offline_map.url`, which `index.php?config` defaults to the proxy. An event can point both the display layer and the offline download at a different source by setting `offline_map.url` in its config. The app reads it via `remote_config.dart`; the compiled fallback lives in `map_config.dart`. Because the display and download URLs match, downloaded tiles render from the shared offline cache (FMTC keys tiles by URL).

### Location Sharing Flow

Both iOS and Android use the same upload path: position data is POSTed to the MARS server, which injects the APRS packet via its own persistent TCP connection to APRS-IS.

```
User taps Share Location
  → _ensureBackgroundPermissions()     (Android: notification + battery opt;
                                        iOS: verify Always location permission)
  → MobileSession.join()               POST ?mobile=join {name, pin, sharing_mode: "unknown"}
      ← {token, callsign, passcode}    e.g. callsign=K6DRK-01
  → BackgroundLocationService.startTracking()
      → Geolocator.getPositionStream() (single stream; AppleSettings on iOS with
                                        allowBackgroundLocationUpdates: true;
                                        AndroidSettings on Android)
  → Android only: FlutterForegroundTask.startService() — persistent notification;
                  background_task_handler.dart is a no-op stub
  → Upload triggered by _heartbeatTimer (timed) OR GPS event (distance ≥ threshold):
      Both platforms:
        _heartbeatTimer fires at configured interval
        _maybeUploadFromStream() on every GPS event (uploads if moved ≥ threshold
            OR configured interval elapsed)
        → MobileSession.update(lat, lon)    POST ?mobile=update {token, lat, lon}
              ← 200 ok  (or 404 → stops sharing)
              → server injectAprsPacket() via local TCP 14580
                    → "K6DRK-01>APRS,TCPIP*:!3751.72N/12232.64W>Mobile/Alice\r\n"
  → aproDaemon receives packet from APRS-IS
      → distance filter: skip if new position < 100 ft from last breadcrumb
      → writes K6DRK-01 to trackers.json with mobile=true
  → WebView polls ?json every 5 s → map updates

App restart while sharing was active:
  → resumeSharing() reads SharedPreferences (token, callsign, interval, activity mode)
  → Attempts token reuse via MobileSession.update(); if stale, re-joins silently
  → Sharing resumes with same callsign and activity mode; snackbar notifies user
```

### App Update Check

On launch the app fetches `https://marsaprs.org/app_version.php` (a small JSON manifest carrying the latest `build` per platform) and, if a newer build is available, shows a **dismissable** "Update available" prompt — never a forced upgrade. iOS opens the App Store listing (`store_url`; the check is a silent no-op while that URL is empty), Android opens the APK download (`apk_url`). A "Later" choice is remembered until an even newer build ships. Client logic lives in `lib/update_check.dart`; bump `build`/`latest` in `map/app_version.php` when a new app version is released (see the version-bump checklist).

### Smart Track

Smart Track is the automatic beacon-interval algorithm in the native app (iOS/Android) and the web map. It monitors GPS speed and adjusts the upload frequency without any input from the user. (The Apple Watch companion does messaging only — it never beacons position.)

**Unknown (?) mode — startup phase:**

Every session begins in **unknown** mode (shown as **?** in the web sidebar). The app joins the server with `sharing_mode: "unknown"` and stays in this phase until Smart Track collects enough GPS readings to make a confident initial determination — typically within 90 seconds. When the mode is first set, the app waits 15 seconds before sending the first real-mode beacon so the sidebar briefly shows **?** before the actual activity icon appears.

**Speed thresholds:**

| Mode | Speed | Interval |
|------|-------|----------|
| Stationary | ≤ 1.0 m/s (2.2 mph) | 120 s |
| Walk / Run | ≤ 4.5 m/s (10 mph) | 60 s |
| Cycle | ≤ 11.0 m/s (25 mph) | 30 s |
| Drive | > 11.0 m/s | 15 s |

**Debounce — preventing false mode changes:**

A mode switch only occurs after a run of consecutive GPS samples all agree on the new mode. The required run length:
- Walk/Run/Cycle/Drive: 15 consecutive samples
- Stationary: 20 consecutive samples, plus at least 5 minutes of wall-clock time since last movement

A timer also fires every 30 seconds to catch the case where the device stops moving but GPS events stall. If no movement has been detected for 5 minutes (or 90 seconds during startup) the mode switches directly to stationary without waiting for GPS events.

**Startup fast-window:**

During the first 10 GPS readings of a new session, only 3 consecutive matching samples are needed to set the initial mode (instead of 15–20). This lets Smart Track make a confident initial guess within 30–60 seconds of starting rather than waiting several minutes. Once those 10 readings have been processed, the full debounce requirements apply.

**GPS noise defense:**

Two mechanisms prevent GPS jitter from causing false mode transitions:

1. **Accuracy gate:** Any GPS reading with horizontal accuracy worse than 20 m is excluded when updating the last-movement timestamp. A brief poor-fix cannot reset the stationary timer.
2. **Movement confirmation guard:** Three consecutive readings must all exceed the stationary threshold (> 1.0 m/s with accuracy ≤ 20 m) before the last-movement timestamp is updated. A single speed spike from GPS noise cannot prevent the stationary timer from eventually firing.

**Implementation:**

The algorithm runs identically in `lib/map_screen.dart` (Flutter iOS/Android) and `index.php` (web JS). Key functions: `_processSpeedSample` / `processSpeedSample` / `_autoDetectFromSpeed` for sample-based detection; `_checkStationaryByTime` / `checkStationaryByTime` for the timer-based fallback.

### Building & Distributing

#### iOS

```bash
cd ~/marsaprs/app
flutter pub get
flutter build ios --release --no-codesign
```

Open `ios/Runner.xcworkspace` in Xcode. To distribute via TestFlight:

1. Bump `version` in `pubspec.yaml` (e.g. `1.14.0+2` — the build number after `+` must increase with each upload).
2. `flutter build ios --release --no-codesign`
3. Xcode → Product → Archive → Distribute App → TestFlight (internal).
4. Wait for the build status in App Store Connect to leave **Processing** before testers can install it. This typically takes 5–15 minutes.

Testers must have TestFlight installed (free, from the App Store). They install the app by opening the invitation email and tapping **View in TestFlight**. New testers may be prompted for a redeem code from the invitation. For updates, testers open TestFlight and tap **Update**.

#### Android

```bash
cd ~/marsaprs/app
flutter pub get
flutter build apk --release
```

The signed APK is at `build/app/outputs/flutter-apk/app-release.apk`. This is a
**universal** APK (arm64-v8a, armeabi-v7a, x86_64 in one file) — do not use
`--split-per-abi` for direct download, since the recipient would have to know their
device's architecture and the wrong pick fails to install.

**Publishing it** — copy to the Pi under a versioned name; the download page picks up
the newest automatically:

```bash
cp build/app/outputs/flutter-apk/app-release.apk ~/Downloads/aprs-map-<version>-<build>.apk
rsync -avz ~/Downloads/aprs-map-<version>-<build>.apk pi@192.168.0.180:/var/www/html/android/
```

| URL | Purpose |
|---|---|
| `https://marsaprs.org/android/` | Landing page — version, size, SHA-256, install steps |
| `https://marsaprs.org/android/download.php` | **Permanent** download link; 302s to the newest APK |

`map/android/` holds `index.php`, `download.php` and `_apk.php`; the APKs themselves are
gitignored and live only on the Pi. The filename must match
`aprs-map-<major>.<minor>.<patch>-<build>.apk` or it is ignored, and the highest build
number wins. The stable URL redirects rather than being a fixed filename that gets
overwritten, so Cloudflare and browser caches can't pin an old release to it — and old
versions stay downloadable at their own paths.

**Release signing** requires `android/key.properties` (gitignored):

```
storePassword=<password>
keyPassword=<password>
keyAlias=<alias>
storeFile=<absolute path to .jks or .p12>
```

`android/app/build.gradle.kts` reads this file automatically. If it is absent or incomplete, a **release build fails** rather than falling back to debug signing — an APK signed with the wrong key installs fine on a clean device and is rejected as an update for every existing user, who would have to uninstall (losing their tracker token and registration) to take it. Debug builds are unaffected and need no keystore.

The canonical copy predates the merge of the standalone `aprs-map` repo into this one and, being gitignored, did not travel with it:

```bash
cp ~/aprs-map/android/key.properties app/android/key.properties
```

Always verify a release before publishing it — the certificate is the only thing that distinguishes a correct APK from a broken one:

```bash
apksigner verify --print-certs <apk> | grep SHA-256
# expected: 0d30a9f258e1330cb22a092ca6df65af5d3bf085905fb9bc01d74e54c866da57
#           CN=Doug Kaye, OU=MARS, O=W6SG, L=Marin, ST=CA, C=US
```

**`compileSdk` note:** `objectbox_flutter_libs` in `~/.pub-cache` may hardcode `compileSdkVersion 31`. Patch it to `36` to match `build.gradle.kts`.

To distribute: share the APK via Google Drive or email. Testers tap the download link, open the file with **Package Installer**, and if Android warns about an unknown source, tap **More details** → **Install anyway**.

#### Submitting to Google Play Store

**One-time setup:**

1. Create a Google Play Developer account at [play.google.com/console](https://play.google.com/console) ($25 one-time fee).
2. Generate a signing keystore (only done once — never change it after first publish):
   ```bash
   keytool -genkey -v -keystore ~/aprs-map-release.jks \
     -alias aprs-map -keyalg RSA -keysize 2048 -validity 10000 \
     -dname "CN=Doug Kaye, OU=MARS, O=W6SG, L=Marin, ST=CA, C=US"
   ```
3. Create `android/key.properties` (gitignored — back up the passwords and `.jks` file securely):
   ```
   storePassword=<store-password>
   keyPassword=<key-password>
   keyAlias=aprs-map
   storeFile=/Users/doug/aprs-map-release.jks
   ```

**Each release:**

1. Bump `version` in `pubspec.yaml` (e.g. `1.21.0+12` → `1.21.1+13` — the build number after `+` must increase with each upload).
2. Build a signed App Bundle (AAB):
   ```bash
   flutter build appbundle --release
   ```
   Output: `build/app/outputs/bundle/release/app-release.aab`
3. In Play Console → **Your app** → **Testing → Internal testing** → **Create new release** → upload the `.aab`.
4. After internal testing, promote the release through **Closed testing → Open testing → Production** using the **Promote release** button.
5. Google typically reviews new production releases within 1–3 days.

**Store listing assets required (one-time, update as needed):**
- Short description (80 chars), full description (4000 chars)
- At least 2 phone screenshots (minimum 320px on shortest side)
- Feature graphic: 1024 × 500 px PNG or JPG
- App icon: 512 × 512 px PNG (must match the launcher icon)
- Content rating questionnaire (set category to **Utilities** or **Tools**)

#### Submitting to Apple App Store Connect

**One-time setup:**

1. Enroll in the **Apple Developer Program** at [developer.apple.com](https://developer.apple.com) ($99/year).
2. In App Store Connect ([appstoreconnect.apple.com](https://appstoreconnect.apple.com)) → **Apps** → **+** → **New App**:
   - Platform: iOS
   - Bundle ID: `org.w6sg.aprsmap` (must match `ios/Runner.xcodeproj`)
   - SKU: `aprsmap` (any unique string)
3. In Xcode → **Signing & Capabilities**: set Team to your Apple Developer account; let Xcode manage provisioning profiles automatically.

**Each release:**

1. Bump `version` in `pubspec.yaml` (build number after `+` must increase with each upload).
2. Build the iOS app:
   ```bash
   flutter build ios --release --no-codesign
   ```
3. Open `ios/Runner.xcworkspace` in Xcode.
4. **Product → Archive**. When complete, the Organizer opens automatically.
5. Click **Distribute App → App Store Connect → Upload**.
6. In App Store Connect → **Your app → TestFlight**: the build appears within minutes; full App Review takes 5–30 minutes before testers can install it.
7. To submit for **App Store production review**:
   - Go to **Your app → App Store → + Version**
   - Fill in "What's New", select the build, submit for review.
   - Apple review typically takes 1–3 days.

**Store listing assets required (one-time, update as needed):**
- App description, keywords, support URL, marketing URL
- Screenshots for iPhone 6.9" display (required) and iPad 13" (required if supporting iPad)
- App icon: 1024 × 1024 px PNG (no alpha channel)
- Privacy policy URL (required for apps that collect location data)

### Background Location

Background location is implemented differently on each platform because of how each OS handles suspended processes.

#### Android

Two foreground services run while sharing: `GeolocatorLocationService` (from the `geolocator` package) keeps the GPS stream and Dart isolate alive; `FlutterForegroundTask` (from `flutter_foreground_task`) provides a persistent notification and prevents Android from killing the process. The `FlutterForegroundTask` handler (`background_task_handler.dart`) is a no-op stub — all beaconing is done by the main isolate via `_heartbeatTimer` and `_maybeUploadFromStream()`. A persistent notification is required — without it Android 13+ treats the service as having no notification and kills it when the screen locks.

**Required permissions in `AndroidManifest.xml`:**

| Permission | Why |
|-----------|-----|
| `INTERNET` | Network access — not injected automatically in release builds |
| `ACCESS_FINE_LOCATION` | GPS |
| `ACCESS_BACKGROUND_LOCATION` | Location while screen is locked (Android 10+) |
| `FOREGROUND_SERVICE` | Run a foreground service |
| `FOREGROUND_SERVICE_LOCATION` | Foreground service of type `location` (Android 14+) |
| `WAKE_LOCK` | Keep CPU active between GPS fixes |
| `POST_NOTIFICATIONS` | Show the foreground service notification (Android 13+); without this the service is killed on screen lock |
| `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` | Prompt the user to exempt the app from battery killing |

At runtime, `_ensureBackgroundPermissions()` in `map_screen.dart` requests `POST_NOTIFICATIONS` and `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` before starting a sharing session.

Samsung One UI is particularly aggressive — users must also set the app to **Unrestricted** battery mode: Settings → Apps → APRS Map → Battery → Unrestricted.

#### iOS

`Info.plist` declares `UIBackgroundModes: location`. The `CLLocationManager` flag `allowsBackgroundLocationUpdates` is set to `true` via `AppleSettings(allowBackgroundLocationUpdates: true)` in the geolocator stream configuration. "Always" location permission is required — "While Using" is not sufficient. When active, a white location arrow appears in the iOS status bar.

**Single GPS stream — critical constraint:** `geolocator_apple` only supports one active event-channel listener at a time. If a second `Geolocator.getPositionStream()` call is made while one is already active, `PositionStreamHandler.onListenWithArguments` returns an error and the second call silently fails — `allowsBackgroundLocationUpdates` is never set to `true` on the underlying `CLLocationManager`, and the background location indicator never appears. The fix: `startTracking()` in `BackgroundLocationService` owns the single Geolocator stream (with `AppleSettings`), and the map's blue-dot layer subscribes to `_bgLocation.positionStream` (a Dart broadcast stream fed by that same stream) rather than opening its own.

**Upload mechanism:** Both iOS and Android use the same `_heartbeatTimer` for timed uploads and `_maybeUploadFromStream()` for distance-triggered uploads. On iOS, the GPS event stream keeps the Dart isolate continuously alive, so `Timer.periodic` fires reliably. `_maybeUploadFromStream()` provides an additional trigger: it uploads immediately when the device has moved ≥ the configured distance threshold since the last upload, resetting the timer to avoid a duplicate beacon shortly after.

**Why both platforms use server-side APRS injection:** Both iOS and Android POST position data to `?mobile=update` rather than sending raw TCP packets directly to APRS-IS. On iOS, `NSURLSession`-based HTTP is explicitly supported for background network tasks while raw `dart:io Socket` TCP connections are not reliable in background. Unifying Android to the same path keeps all beaconing logic in the main isolate, makes `background_task_handler.dart` a no-op, and simplifies the overall architecture.

### Apple Watch Companion ("Watch")

A watchOS companion that makes the wrist a nearly hands-free extension of the phone: a reply is a press-and-hold push-to-talk. It does **messaging only** — it never beacons position.

**The phone is the loudspeaker; the watch is the microphone.** That is the opposite of how this was first built, and the inversion came from field testing. watchOS lets an app play audio only while it is frontmost, so a watch on a lowered wrist cannot alert anybody — while the phone, in a pocket, can both chime and read a message aloud. So the phone announces everything unless the watch app is genuinely on screen, and the watch's job the rest of the time is to be ready to take a reply.

**Who announces an arriving message** is decided by the watch and obeyed by the phone. The watch publishes `canAnnounce` whenever its scene phase changes; the phone suppresses its own alert only while that is true. It is reported rather than inferred because the two devices had different notions of "awake": a dimmed watch is unreachable over WatchConnectivity yet still frontmost, so each assumed the other had it and messages were announced twice or not at all. Every relayed message also carries `phoneAnnounced`, which stops the watch re-reading a backlog when queued transfers flush on waking, and lets it raise a notification if it went quiet after promising to speak.

**Why it is native Swift.** Flutter does not target watchOS, so the watch app cannot be Dart. It is a native SwiftUI target (`WatchApp`) inside the same `app/ios/Runner.xcodeproj`, bridged to the Flutter app over WatchConnectivity plus a Flutter method/event channel.

| Path | Purpose |
|------|---------|
| `app/ios/WatchApp/Info.plist` | **Must** live at this exact path. `flutter_tools` detects a watch companion by reading `ios/<TargetName>/Info.plist` for `WKCompanionAppBundleIdentifier`, so the directory name and the target name have to match (`WatchApp`) |
| `app/ios/WatchApp/WatchApp.xcconfig` | `#include`s `Flutter/Generated.xcconfig` so the watch app's version comes from `pubspec.yaml`, exactly like the iPhone app. Project-level configurations carry no `baseConfigurationReference`, so without this the watch target would inherit no Flutter build settings |
| `app/ios/WatchApp/Sources/` | SwiftUI app, WatchConnectivity session, announcer (haptic/tone/TTS), dictation, direct messaging client |
| `app/ios/Runner/WatchBridge.swift` | Phone side of the bridge: `WCSessionDelegate` + the `org.marsaprs/watch` method and event channels |
| `app/lib/watch_bridge.dart` | Dart side of the bridge; reuses `MessagingClient` for all network traffic |

**Transport is hybrid.** The phone is the fast path — it already polls, so it relays inbound messages to the watch and sends the watch's replies. The phone also hands the watch its tracker token, so when the phone is unreachable the watch polls `?messaging=poll` directly over WiFi or LTE. Both paths converge on one `ingest()` on the watch that dedupes by message `id`, so a message can never be announced twice. The watch never polls while the phone is reachable.

**Two build-system consequences.** Once a watch companion exists, `flutter_tools` omits `-sdk` from the Xcode invocation and stops narrowing `ARCHS`/`ONLY_ACTIVE_ARCH`: simulator builds now need an explicit `-d <device-id>`, and debug builds are slower. Device builds are unaffected. `app/TESTFLIGHT.md` carries the release-time verification steps.

**Do not move the "Embed Watch Content" build phase.** It sits immediately after `Resources` in the Runner target, and it has to stay before `Thin Binary`. `Thin Binary` runs `xcode_backend.sh embed_and_thin` and declares `${TARGET_BUILD_DIR}/${INFOPLIST_PATH}` as an input, which makes Xcode take a directory-tree signature of the whole of `Runner.app`. Scheduling a copy *into* `Runner.app/Watch/` after that closes a dependency loop and the build dies with `Cycle inside Runner`. The watch target also sets `SKIP_INSTALL = YES` so the archive contains only `Runner.app`, with the watch app nested inside it.

**Reading aloud on the phone needs `UIBackgroundModes: audio`.** Without it iOS refuses to start an audio session once the app leaves the screen, which is the situation the feature exists for. One shared `Speaker` (`app/lib/speaker.dart`) owns the app's only text-to-speech engine — the chat screen used to own a second, and two contend for the same audio session and cut each other off mid-sentence. Note that `defaultToSpeaker` must not be passed with the `playback` category: it is valid only with `playAndRecord`, and including it fails the whole category call, leaving the app silent in the background with no error anywhere obvious.

**The reply follows the traffic, from whichever side heard it.** An arriving message re-aims the destination, so the announcement the operator just heard is also a statement of where their answer will go. The phone does this in `WatchBridge._aimAt` when it relays; the watch does it in `AppState.ingest` for anything it fetched by direct poll, which is the case the phone never sees. Broadcasts move the aim only when there is an addressable sender key — the phone has one, the watch does not, and aiming at the broadcast thread instead would send a spoken "copy that" to every tracker. A destination is also dropped when the conversation behind it is absent from the current event's list, which is what stops a thread from a finished event surviving into the next one.

**Unconfirmed sends are visible and actionable.** `Outbox` persists every reply until the server confirms it, across app launches and watch reboots — a message spoken into the wrist and silently lost is the worst failure this app has. A badge on the Talk page shows the count, and it is a link: tapping it lists what was said and offers **Retry** or **Discard**. Retry aims at the *current* destination, not the original, because an entry usually failed precisely because its target had gone; it also takes a fresh client id, since `?messaging=send` carries none and the server therefore cannot dedupe a retry against a delivered-but-unacknowledged original.

**The limitation accepted.** watchOS only lets an app play audio while frontmost, and will not run a timer for a backgrounded one. So with the phone off or out of range **and** the watch app backgrounded, nothing reaches the operator until they raise their wrist — at which point the watch polls and announces what it missed. The only mechanism that would change this is a `WKExtendedRuntimeSession`, which costs heavy battery and a background-mode declaration App Review may query; the deliberate decision is to live without it, since the phone covers every case in which it is alive.

---

## Transcribers

A Transcriber is a Raspberry Pi 4 with an RTL-SDR that listens on a voice frequency,
transcribes each transmission with `whisper.cpp`, and appends it to the event log by
itself. Net control hears everything on the radio and writes down almost none of it;
this is the part that writes it down.

```
146.520 MHz RF                       rtl_fm -d serial=… -f … -M fm -l <squelch>
      │                                    │  raw S16LE
      ▼                                    ▼
  RTL-SDR ──── Pi 4 ──── transcriber@rx1-146520.service
                              │  sox … silence … : newfile : restart
                              ▼  one wav per transmission
                         whisper.cpp (tiny.en / base.en)
                              ▼
                POST index.php?messaging=log   →   "146.520 → Log"
```

**Several channels per Pi**, not one Pi per frequency: `transcriber@.service` is a
systemd template, so a second frequency costs one unit instance rather than one more
device to power and maintain. Each channel is bound to its dongle **by USB serial**
(`rtl_eeprom -d 0 -s 00000001`), never by index — index order is not stable across
reboots or re-plugs, and two channels silently swapping frequencies is the kind of fault
nobody notices until the log is already wrong.

**Two dongles per Pi 4 is comfortable.** The Pi supplies 1.2 A across all USB ports and
an RTL-SDR draws roughly 300 mA, so two use about half of it. The real load is
continuous transcription on four cores, which is a sustained near-peak draw of the kind
that browned out BigTV — use the official 5.1 V/3 A supply, put the dongles on the
**USB 2.0** ports (USB 3.0 radiates broadband noise that desenses an RTL-SDR, and
presents as a deaf receiver rather than a power fault), and cool it actively.

**A channel is a participant.** It is registered as `kind = 'transcriber'` and identified
exactly the way a mobile is — a token matching no participant row is looked up in the
channel registry and registered in the current event (`_msg_resolve_sender`). `?messaging=log`
accepts an operator or a transcriber; nothing else changed, because a log entry was
already a message with no recipients. Channels write and never read: the log thread is
listed only for operators, and the recipient pickers list only operators and mobiles, so
a channel cannot be messaged and sees no traffic. `display_name` is the channel label,
which is what makes an entry read `146.520 → Log` with no client change anywhere.

**Most of the worker is code that refuses to log things.** whisper does not go quiet when
it hears nothing — fed squelch hiss it produces "Thank you." with complete confidence —
so clips under 1.2 s never reach it, a denylist catches what it invents anyway, and
anything over two minutes is treated as a stuck carrier and discarded rather than
occupying the channel for minutes. A log quietly filling with invented lines is worse
than one that misses a transmission, because nobody thinks to question it. Entries queue
on disk and flush in order, stopping at the first failure so a later one cannot overtake
an earlier.

**A gap in the byte stream is the boundary between transmissions, and that is the whole
of the segmentation.** `rtl_fm`'s squelch gates on RF power *before* demodulation, so
while it is closed the process emits nothing at all — measured on a real receiver at
exactly zero bytes over eight seconds of idle channel. Samples arriving means a carrier
is up; samples stopping for `GAP_SECONDS` means it dropped.

A software audio-level squelch ran on top of that for a while, meant to find the edges of
each over. It could not work, for a reason the measurement above makes obvious in
hindsight: because `rtl_fm` emits nothing while closed, the only audio it ever saw was
speech, so the "noise floor" it computed was a *speech* level — 98, in the field. It then
discarded anything quieter, which meant the opening syllables of every over, and cut the
over in two at the first pause. A station saying "monitoring channel, K6DRK" was logged
as "ring channel K6DRK." followed by a 1.2 s fragment whisper could make nothing of. It
is gone; the gap needs no help.

**Transcription runs on its own thread, and the audio never touches the card.** Two
separate reasons, both about what a Pi in a box somewhere can afford to lose:

- *The thread.* whisper is blocking, and while it ran inline nothing was draining
  `rtl_fm`'s pipe — 64 KB, about two seconds of audio, after which `rtl_fm` blocks on
  write, stops reading the SDR, and those samples are gone. Choosing the careful model
  would have lost transmissions rather than merely running behind. Separated, a slow
  model or a slow server costs latency and nothing else.
- *The ramdisk.* A clip is written once, read once, and deleted; at 16 kHz mono that is
  32 KB per second of audio written to a card with a finite number of erase cycles, and
  none of it is worth keeping. Clips live on tmpfs under `/run/transcriber/<channel>`,
  which systemd creates (`RuntimeDirectory=`) and empties when the unit stops — so they
  also stopped leaking on every kill. `rtl_fm`'s stderr goes there too, and is read back
  only when it dies: "No supported devices found." is the whole diagnosis when a dongle
  has fallen off the USB bus, and it used to go to `/dev/null`.

What must survive a reboot stays on the card under `/var/spool/transcriber/<channel>`:
the outbox, so a transmission heard during an outage is not lost, and the measured
squelch, so a restart is listening again in seconds instead of deaf for a minute while it
measures the site again. The backlog is bounded by **both** clip count and total bytes —
a count alone bounds nothing when one clip can be 4 MB and `/run` is smaller than a
hundred of them.

**Two kinds of token, deliberately.** A *device* token only fetches configuration
(`/transcriber/get.php?token=…&device=<hostname>`, and only that device's channels); a
*channel* token only writes log entries. Neither can do the other's job, so a Transcriber
left in a shed cannot be used to read the net's traffic. The registry lives at
`/var/lib/marsaprs/transcriber.json`, beside `messages.db` and **outside the web root** —
a token registry under `/var/www/html` is how `mobile_trackers.json` came to be
downloadable by anyone.

**Each channel measures its own receiver nightly.** `sdr-selftest.sh` frees the dongle,
runs five `rtl_power` sweeps around the channel's frequency, and grades the worst internal
birdie in the guard band beside it — the fault that quietly deafens a receiver without
ever looking like a fault. Results go to the same fleet dashboard as the iGates', at
`/igate/selftest/`, and a history line is appended locally so a slow degradation is
visible rather than inferred.

It is one report per **channel**, not per device: each channel has its own dongle on its
own frequency, so they are separate receivers that happen to share a Pi, and a spur that
deafens one says nothing about the other.

This is the iGates' `igate-selftest.sh`, renamed and generalized. Only four constants were
ever APRS-specific; the watched frequency is now a parameter, so an iGate asks about
144.390 and a Transcriber about whatever voice channel it is on. It lives in `sdr/` rather
than in either device's tree and is copied into both archives at deploy time — one source
file, two fleets, no drift. The old `aprs_guard_*` output keys are still emitted alongside
the new generic ones, because every gate's `selftest-history.csv` and the dashboard were
written against them, and a nightly update cycle means "every deployed device" for a day.

**Settings reach the receiver by themselves, within a minute.** `transcriber-config.timer`
runs `auto-update.sh --channels-only` every 60 seconds: fetch this device's channels, and
if they differ from what is installed, apply them and restart the affected channel. No
archive download, no self-replacement, no self-noise test — those belong to the nightly
run, and the last of them would take the receiver off the air for a minute every minute.

Two things that sound like details and are not. The poll **never restarts a channel that
has not changed**: doing so on a schedule would re-measure the squelch and lose whatever
was being said, sixty times an hour, forever. And the `update_requested` stamp the manager
sets is kept *out* of the file the device compares against, or pressing "Update devices"
would look like a changed channel list and restart every receiver for nothing.

The manager saves on an explicit **Save**, not as you type, and carries a fingerprint of
what the page was loaded from so the server refuses a write made against a stale copy
rather than silently reverting somebody else's change. **Update devices** is separate and
is about software: it asks every Transcriber to pull a new worker at its next check
instead of waiting for 4:11am. Neither can be instant — there is no way into a Pi behind NAT
and no wish to open one — so instead of promising a number, the page waits for the
devices to come and collect. Each Transcriber's fetch is recorded along with a fingerprint
of what it was given, and the page spins on "Update pending" until every device's
fingerprint matches what it should now hold, or gives up after 75 seconds and names the
ones that never answered. A device the edit did not affect is already current, so it does
not sit pending on somebody else's change.

**Two scripts, and the split between them matters.** `install.sh` builds the *machine* —
packages, `whisper.cpp` compiled for this CPU, the models, the nightly cron — and knows
nothing about which receiver it is. `configure.sh` makes it a *particular* receiver:
hostname, NetBird, device token, dongle serials. Everything in the second is site-specific
and everything in the first is not, so a Transcriber can be re-sited by re-running
`configure.sh` alone, and re-running either is safe.

The hostname is the part worth care. It *is* the device's identity: `auto-update.sh`
fetches with `?device=$(hostname)` and the server matches that against the Host column.
Get it wrong and nothing reports an error — the device asks for its channels, is told it
has none, and sits there healthy and deaf.

**Renaming a device takes three steps in the manager, not one**, and skipping the second
produces exactly that silent failure:

1. Change the device's **Host**. This issues a **new config token**, because tokens are
   keyed by host name — the one on the Pi stops working, so rotate and copy the new one.
2. **Re-pick the Receiver on every channel that device owns.** Channels store the device
   name as a string and do not follow a rename, so they are left pointing at a host that
   no longer exists. The channel ID is derived from device and frequency, so this also
   renames the unit (`transcriber@<host>-<kHz>`); `auto-update.sh` stops the old one.
3. Run `configure.sh` (or just `auto-update.sh`) on the Pi with the new hostname and token.

When a fetch succeeds but returns no channels, `auto-update.sh` now says so and names step
2 as the likely cause, because "update complete: no channels configured" is accurate and
tells you nothing about why a receiver reporting success is deaf.

**NetBird is installed by `configure.sh` and stays up permanently.** The iGates and
displays toggle theirs from the server every five minutes (`check-netbird.sh`), because
they go to sites on metered or marginal links where a VPN is worth switching off. A
Transcriber is remote-managed by definition — its entire configuration arrives over the
network — so there is no toggle, no `netbird-up.sh`, and no cron entry; just
`systemctl enable netbird` so it is back after a reboot without anything having to
notice.

Answering the monitor's health poll is all a device does to appear in `/netbird/admin.php`
— there is no registration step. `stats-listener.py` is the iGate's `stats-listener.php`
field for field, in Python because a Transcriber has no PHP on it and adding `php-cli`
plus `ext-sockets` to run one script on a Pi whose whole worker is stdlib Python is a
poor trade. The format is what the poller prints verbatim, so the two must change together.

| Path | Purpose |
|------|---------|
| `transcriber/bin/transcriber.py` | The per-channel worker (stdlib only, like `isproxy.py`) |
| `transcriber/bin/stats-listener.py` | Answers the NetBird monitor's UDP:1235 health poll |
| `transcriber/systemd/transcriber@.service` | Template unit — one instance per channel |
| `transcriber/install.sh` | One-time build: SDR tools, `whisper.cpp` compiled for this CPU, models |
| `transcriber/home/configure.sh` | Site setup: hostname, NetBird, device token, dongle serials |
| `transcriber/auto-update.sh` | Nightly: pulls the archive, fetches this device's channels, starts/stops units to match |
| `server/www/transcriber/` | The channel manager and the device download |
| `map/tests/php/TranscriberStoreTest.php` | Registry and token checks |
| `transcriber/tests/test_transcriber.py` | The worker, with no SDR and no whisper |
| `transcriber/tests/test_auto_update.sh` | The updater's self-replacement, against a fake server |
| `sdr/sdr-selftest.sh` | SDR self-noise test — shared with the iGates |
| `sdr/sdr-selftest.py` | The spur analyzer behind it |

`auto-update.sh` ships inside the archive as well as standing alone, so it can replace
itself. It could not before: `install.sh` fetched it once and the device ran that copy
forever, which meant no change to the updater could ever reach a deployed Transcriber.

**It hands over on the same run rather than the next one.** Immediately after extracting
the archive — before anything else is touched — it compares the published copy with
itself, and if they differ it installs the new one and `exec`s it, guarded by an
environment variable so exactly one hand-over can occur. Without that, a change to the
updater took effect only on the *following* run, which is invisible and reads as a deploy
that silently did nothing.

This is deliberately not the two-stage loader pattern — a thin stub that downloads and
runs its own logic every time. That buys the same immediacy, but it makes every nightly
run depend on the network for its *code* and not just its content: a device on a marginal
link must degrade to "keep running what is installed", and a stub that cannot fetch stage
two cannot do anything at all. It would also mean `cat /home/pi/auto-update.sh` no longer
tells you what runs tonight, which is exactly the question worth answering when
reconstructing what a device did last night. `transcriber/tests/test_auto_update.sh`
covers the hand-over, the loop guard, and the unreachable server, and `deploy.sh` will
not ship past it.

## User Interfaces

Six browser-based interfaces run on `marsaprs.org`. All are served by Apache on the server
Pi, accessible at `https://marsaprs.org/<path>`.

| Interface | URL | Access | Purpose |
|-----------|-----|--------|---------|
| **Map** | `/` | Public | Live tracker positions on an interactive Leaflet map |
| **Map Admin** | `/admin/` | User account | Event configuration, tracker list, course and aid station management |
| **Analyzer** | `/analyzer/` | User account | Beacon recording, playback, and analysis for the current event |
| **NetBird Monitor** | `/netbird/` | User account | Real-time health status of all Pi devices |
| **NetBird Admin** | `/netbird/admin.php` | User account | Add/remove devices, enable/disable, SSH terminal |
| **WiFi Manager** | `/wifi/` | User account | Edit the shared WiFi credential list distributed to all Pis |
| **Transcriber Channels** | `/transcriber/` | User account | Which Pi listens on which frequency, and with which dongle |
| **Tickets** | `/tickets/admin.php` | User account | Bug report and suggestion ticket management |

**Map** — Shows tracker positions updated every 5 seconds. Sidebar lists trackers (with
elapsed time and breadcrumb history), courses, aid stations, iGates, and map backgrounds.
Hovering a tracker or breadcrumb dot shows its APRS path (iGates/digipeaters the packet
traveled). Breadcrumbs are filtered: consecutive duplicate positions and positions within
100 feet of the previous breadcrumb are suppressed. The breadcrumb trail shows up to
`breadcrumb_count` positions as dots on a dashed line with directional arrows; the trail updates automatically
as the selected tracker moves. Aid station and iGate tooltips include an optional callsign.
A scale bar in the lower-right corner toggles between miles/feet and kilometers/meters when
clicked. Kiosk mode removes controls for unattended display use.

**Map Admin** — Requires `admin.view` or `admin.edit`. Manages all event configuration: tracker callsigns and
IDs, GPX/KML/GeoJSON course overlays, aid station and iGate locations (with optional APRS
callsign), map default view, and background tile layers. Supports multiple named events;
switching events is instant. Users with only `admin.view` see all data read-only.
See [Appendix — Server — Admin Interface](#admin-interface) for full details.

**Analyzer** — Requires `analyzer.view`. A Flask/gunicorn web app served at
`/analyzer/` via Apache mod_proxy. Runs `analyzer-daemon` to record APRS beacons from
APRS-IS into a local SQLite database for the current event. Displays recorded beacons on
an interactive Leaflet map with controls for filtering by tracker and iGate, toggling radio
vs. cellular beacons and course overlays, adjusting the auto-refresh rate (30 s–5 min per
client), and scrubbing a time-range slider. Daemon start/stop and data erasure require `analyzer.admin`.

**NetBird Monitor** — Requires `netbird.view`. Polls all registered Pi devices every 60 seconds (default; adjustable) over
the NetBird VPN and shows online/offline/enabling/disabled status. Includes an SSH terminal
for online devices. Poll/refresh sliders and the Admin button are shown only to users with `netbird.admin`.
See [Supporting Systems — NetBird Status Monitor](#netbird-status-monitor) for full details.

**NetBird Admin** — Requires `netbird.admin`. Manages the device list in `addresses.yaml`: add, edit, delete, enable,
and disable devices. Access to the SSH terminal for any online device.

**WiFi Manager** — Requires `wifi.admin` to edit; accessible read-only to users with `netbird.view`.
Edits `wifi.yaml`, the master list of WiFi networks distributed to all Pis nightly. Auto-saves on every change; no manual save step.

**Tickets** — Requires `tickets.manage`. View and manage bug reports and suggestions submitted via the in-app ticket form.

---

## Authentication

All protected browser interfaces use a shared named-account system. A single sign-in at
`/auth/login.php` establishes a session that is recognized by every tool — Admin, Analyzer,
NetBird, WiFi, and Tickets — without re-entering credentials.

### User Accounts

Accounts are stored in an SQLite database at `/var/lib/marsaprs/users.db` (outside the
web root, owned by `www-data`). Each account holds a username, display name, and bcrypt
password hash, plus an active flag. Inactive accounts cannot log in.

### Session Cookie

After a successful login the server inserts a row into the `sessions` table with a
64-character hex token and sends it to the browser as the `marsaprs_session` cookie
(`httponly`, `secure`, `SameSite=Lax`, 24-hour TTL). Both PHP pages and the Python
Flask analyzer validate the same cookie against the same database.

### Permissions

Access is controlled by flat permission strings. Each user is granted exactly the
permissions they need; there are no implicit roles or inheritance.

| Permission | Guards |
|---|---|
| `admin.view` | `/admin/` — read-only view of all event config, trackers, aid stations, iGates |
| `admin.edit` | `/admin/` — full edit and save |
| `admin.edit_trackers` | `/admin/` — edit **only** the Trackers section (add/remove/rename mobile trackers, set display ID and ham callsign, tracker mode, **Hide** toggle, beacon reset) without full `admin.edit`; requires `admin.view` |
| `admin.set_default` | **Save as Default Event** action |
| `admin.delete_event` | **Delete** event action |
| `analyzer.view` | `/analyzer/` — event map and beacon data |
| `analyzer.admin` | Daemon start/stop; Erase All Data |
| `netbird.view` | `/netbird/` — status page; read-only WiFi page |
| `netbird.admin` | `/netbird/admin.php`; poll/refresh sliders; full WiFi edit |
| `wifi.admin` | `/wifi/` — edit WiFi credentials |
| `tickets.manage` | `/tickets/admin.php` — ticket list and management |
| `messages.manage` | Messaging admin: **Delete All Messages**, and **Manage operators** (disconnect a stuck/idle operator to free their name). *(Renamed from `messages.delete_all`.)* |
| `users.manage` | `/auth/users.php` — create/edit/delete accounts and permissions |

The authoritative list is `KNOWN_PERMISSIONS` in `server/www/auth/users.php`; a permission
missing from it cannot be granted in the UI. Pages outside the auth tree guard themselves with
`require_once __DIR__.'/auth/auth.php'` then `has_permission(...)` — `map/index.php` does this
lazily (`msgHasAuthPermission()`) so the public map never opens `users.db` for anonymous
visitors.

### Login Flow

Every protected page passes unauthenticated requests through
`require_permission($perm)`, which redirects to `/auth/login.php?next=<original-url>`.
After a successful login the browser is sent back to the originally requested page.
Logging out (`/auth/logout.php`) deletes the session row and clears the cookie.

Users with `admin.view` but not `admin.edit` see the Admin page in read-only mode:
all data is displayed but edit controls, save buttons, and import/export actions are
hidden. Granting `admin.edit_trackers` alongside `admin.view` unlocks editing of just the **Trackers** section (the controls listed above) while the rest of the page stays read-only. The same permission-aware rendering applies to the NetBird status page (sliders
and Admin button hidden for `netbird.view`-only users) and the WiFi page (edit controls
and drag-to-reorder hidden).

### User Management

Users with `users.manage` access `/auth/users.php` (linked from the Admin page header
as **Users**). From there they can create accounts, reset passwords, toggle active status,
and assign or revoke individual permissions.

### Key Files

| File | Location | Purpose |
|---|---|---|
| `auth.php` | `/var/www/html/auth/` | Shared PHP library: `current_user()`, `has_permission()`, `require_permission()`, `create_session()`, `destroy_session()` |
| `login.php` | `/var/www/html/auth/` | Login form (GET) and credential validator (POST) |
| `logout.php` | `/var/www/html/auth/` | Destroys session, redirects to login |
| `users.php` | `/var/www/html/auth/` | User management UI (requires `users.manage`) |
| `init_db.php` | `/var/www/html/auth/` | Idempotent DB bootstrap; creates first admin account when no users exist |
| `auth_db.py` | `/home/pi/analyzer/src/` | Python mirror of `auth.php`; validates `marsaprs_session` cookie in Flask |
| `users.db` | `/var/lib/marsaprs/` | SQLite database: `users`, `permissions`, `sessions` tables |

### Event Lock Password

The event-level **lock** feature (🔒 in the Admin page) still uses `admin/password.txt`
as a separate credential. This is distinct from user-account authentication — it controls
whether a specific event's YAML can be overwritten, not who can log in. All other
admin actions are gated by the user-account permission system.

---

## Messaging System

Messaging lets web operators and mobile tracker participants exchange text messages in real time — one-to-one, in named **groups**, or broadcast to all mobiles. It is enabled by setting `messaging_password` in the event's `event.yaml` (via the Admin page). As of 1.21.0 the store is a single server-side **SQLite database** and both clients present a familiar **chat UI** (conversation list + thread + always-visible composer) with delivery/read receipts and optional photo attachments. This replaced the former per-event `messages.json` log and per-tracker `pending_msgs` queues.

### Data Storage — `messages.db` (SQLite)

All messaging state lives in one SQLite database, `/var/lib/marsaprs/messages.db` (outside the deploy tree, like `users.db` and the analyzer's `aprs.db`, so deployments never wipe it). It is event-scoped — every row carries an `event` column. Helper `map/messaging_db.php` owns the schema and all access; `map/messaging.php` is the JSON API layer.

| Table | Purpose |
|-------|---------|
| `participants` | One row per addressable party per event — mobiles (keyed by callsign) and operators (keyed by unique name). Holds `display_name`, `short_id`, `token`, `last_seen`, and last-known `lat`/`lon`/`pos_ts`. |
| `conversations` | A `direct`, `group`, `broadcast`, `entity`, `entity_multi`, or `log` thread, with a `member_hash` so a given set of participants maps to exactly one conversation. |
| `conversation_members` | Membership join between conversations and participants. **Empty for broadcast conversations** — see below. |
| `messages` | The messages: monotonic `id` (the wire id for `since_id` polling), `event`, `conversation_id`, `sender_id`, `ts`, `text`, sender `lat`/`lon`/`pos_ts`, `broadcast`, and photo columns (`attachment`, `attach_w`, `attach_h`). |
| `deliveries` | Per-recipient row for each message with `delivered_ts` / `read_ts` — this is the inbox, the unread count, and the delivery/read receipts. Replaces the old `pending_msgs` queue. |

**Message IDs are monotonic.** Each client polls with a `since_id` watermark, so ids must never go backwards; the DB assigns them from an always-increasing sequence and **Delete All Messages** does not reset it.

**One person, several phones: entities.** A volunteer may carry two devices (a phone and a spare, iPhone and Android). Each is a separate mobile session with its own callsign, so without help they appear as two recipients and two threads. Everyone sharing **both `display_id` and name** is treated as one **entity**:

| Case | Picker shows | Addressed as |
|---|---|---|
| CRD Stanton on two phones | one row, "2 devices" | `ent:CRD\|Stanton` → both |
| LKL Dirck + LKL Jerry | two rows **plus** "LKL (multiple)" | `mult:LKL` → everyone at LKL |

Both pickers sort alphabetically by `display_id` then name, with each `(multiple)` row sorted to the **head of its own ID group** so it sits directly above the people it covers rather than trailing the list. Each `(multiple)` row reports how many entities it addresses ("5 people at CM"), and a multi-device row reports its device count ("2 devices"). The web builds this in `_pickerOptions()`; the app sorts in `_RecipientPicker` using `MsgParticipant.groupId`/`isMultiple`, with the counts carried by the `devices` field from `?messaging=participants`.

`display_id` is the grouping key **by design** — it is operator-editable in the Admin UI (`?setdisplayid`) and is routinely edited to make this merging possible. The underlying callsign never changes and remains the APRS identity.

**Entity threads are keyed to the entity, not to the device set.** `resolveEntityConversation()` builds `member_hash` from `<operatorId>|ent:<display_id>\x1f<name>`, so a phone going offline, coming back, or a third being added never forks the conversation — which hashing the participant set would do. Delivery is re-resolved from the live tracker feed on every send, including replies, so a device whose `display_id` was edited away stops receiving even though it stays a historical member. Threads are per-operator: two operators messaging the same entity get their own conversations, exactly as with a direct thread.

Kinds are distinct: `entity` is one person (receipts collapse — any phone acknowledging counts as delivered/read), `entity_multi` is several people sharing a station (keeps "N of M").

**Merging folds prior history in, reversibly.** When an entity thread is resolved, `migrateThreadsIntoEntity()` moves the operator's earlier one-to-one messages with those devices into it — otherwise merging two phones leaves a second, stale "CRD Stanton" in the conversation list. Two safeguards:

- **Guarded to true 1:1 threads** whose only non-operator member is one of the entity's devices. Group, broadcast, and mobile-to-mobile threads are never touched, so a mistyped `display_id` cannot pull in an unrelated conversation. This is not theoretical — testing against live data, the guard correctly declined to migrate a "Stanton + Rich" thread between two mobiles.
- **Every moved row records `prev_conversation_id` and `merged_ts`**, so `undoMerge()` reverses a bad merge exactly. The log is not optional: the tempting alternative signal — one delivery row means pre-merge, several mean post-merge — collapses whenever a device was offline at send time, which is the normal case for the multi-device people this feature serves.

Emptied threads are **hidden, not deleted** (`conversationsFor()` skips conversations with no messages). Deleting them orphaned the history on undo — the messages returned to a `conversation_id` whose row no longer existed.

**The entity thread is chosen, not merged into afterwards.** Migration only ran when an operator sent via an `ent:` picker row, so any *other* path that addressed a device by callsign — notably right-clicking a tracker, which composes `recipients:[callsign]` — resolved a fresh `direct` thread beside the entity one and undid the tidying. Both render from the same `display_id` and name, so the operator saw the same person listed twice with the history split; they cannot converge on their own because the hashes differ (`111|ent:-LOGDoug` vs `77,111`). `entityConversationForDevice()` now looks the person's thread up at send time when an operator addresses a single device. Restricted to kind `entity`, never `entity_multi` — a `(multiple)` thread is a station's whole group, and routing one person's message into it would put a private reply in front of everyone there.

**The event log is a conversation with no recipients.** One `log` thread per event (`member_hash = 'log:*'`), holding entries that were written rather than sent — times, arrivals, decisions. `insertMessage()` with an empty recipient list writes no `deliveries`, so nothing is queued for anyone to poll, no client announces it, and no receipt can come back; everything else (storage, threading, `history`, CSV export) it gets for free by being an ordinary message. Memberless like the broadcast thread, because it belongs to the event rather than to whoever made the first entry — which also settles access without a rule of its own, since the `thread` handler already lets an operator open anything and refuses a non-member everything. The one place that could still leak it is the conversation list, so `conversationsFor()` takes an `$includeLog` flag the caller sets only for operators. `to_label` is `'Log'` in both `thread()` and `history()`, and entries are attributed to their author — the inverse of the rule for ordinary traffic, because the log is shared and is read later by someone reconstructing events.

The mobile app needs no change for any of this: `?messaging=participants` returns entity-grouped rows keyed `ent:`/`mult:`, and the app already renders `short_id + name` and sends `p.key` back. That endpoint also now filters to addressable participants only (mobiles with a live session in the last 24 h, operators seen in the last 24 h); it previously returned the entire never-pruned participants table, which is what filled the app's picker with one volunteer repeated across four old sessions.

**Broadcast access is decided by kind, not membership.** Direct and group conversations are keyed by `member_hash` — the member set *is* the identity, so whoever finds one is already in it. The broadcast conversation is different: its hash is the constant `'*'`, so a single row is shared by the whole event, and it therefore records **no members at all**. Access goes through `canAccessConversation()`, which admits any participant in the event; `isConversationMember()` remains a literal membership test for callers that need one (such as the mobile reply-routing path, which deliberately skips broadcasts). `conversationsFor()` likewise unions the broadcast thread in for every participant.
>
> This matters because membership and delivery must not disagree. `conversationRecipients()` fans a broadcast out to every participant, so everyone gets a `deliveries` row and their client rings the alert tone. If the thread were membership-gated, those recipients would be refused when they tried to open it — the message would arrive audibly and be unreadable. That was a real failure during Dirt Fondo 2026: `findOrCreateConversation()` wrote members only on the *creation* path, so the event's one broadcast conversation listed only the operator who happened to send the first announcement. Every later broadcast reused that row, mobiles got `403 Not a member of this conversation`, and on the sender's screen the thread rendered as a direct chat with that first operator instead of **All Trackers**.

**Photos.** An attached image is stored as a file under `/var/lib/marsaprs/photos/<event>/` (private, owned by `www-data`), with the message row referencing it and `attach_w`/`attach_h` recording its dimensions. Photos are served only through the auth-gated `?messaging=photo&id=&token=` endpoint (never a public path), validated with `getimagesize` and capped at 12 MB. The server has no image library, so the mobile client downscales before upload. Deleting an event's messages (or **Delete All Messages**) removes the event's photo directory too, and an event backup/export bundles both the messages and the photos.

### API (`?messaging=…` → `map/messaging.php`)

| Action | Purpose |
|--------|---------|
| `subscribe` / `identify` | Operator (name + password → token) / mobile (token → participant). Operators must choose a unique, non-empty name — there is no `Operator` default. |
| `participants` | Addressable list for the event (mobiles + operators, with `short_id`, `display_name`, online state) — powers the any-to-any recipient pickers. |
| `send` | `{token, recipients:[keys] | 'all', text, conversation_id?}`, optionally multipart with a photo. Resolves/creates the conversation and writes the message + `deliveries`. |
| `poll` | `{token, since_id}` — new messages addressed to me plus delivery/read updates; incremental. |
| `thread` | The running exchange for one conversation (members only). |
| `history` | The full event log (View All / admin) — operators only. |
| `log` | `{token, text}` — append an entry to the event log. Operators only; writes a message with **no recipients**, so no `deliveries` rows exist and nothing is queued, announced, or acknowledged. |
| `read` | Mark delivered messages read (read receipts). |
| `photo` | Stream an attachment (conversation members or operators only). |
| `flush` | Per-event wipe, gated by `messages.manage`. |

**Backward compatibility.** The legacy mobile endpoints (`?mobile=message`, mobile `poll`, `web_recipients`) are a thin shim over the same `messages.db`, so an older app keeps working unchanged alongside the new chat clients — old and new interoperate through one database.

### Web Operator UI (chat panel)

The **Messaging** button subscribes (name + password → token, remembered in `localStorage`). Messaging then lives in a persistent **chat panel docked right of the map**: a conversation list with unread badges, the selected thread, and an always-visible composer that incoming messages never cover. The 5-second poll drives live updates into the open thread, the list, and the unread counts without a reload. **New message** searches mobiles + operators (multi-select → a group; **All Trackers** → broadcast). Sent messages show **Delivered ✓** / **Read ✓✓** (or *N of M* in a group). A **Read aloud** toggle speaks arriving messages (Web Speech API); when off, a volume-controlled tone plays. A **microphone** dictates into the composer. **View all** opens the whole-event feed — searchable, and clicking a message opens its thread; operators holding `messages.manage` also get **Export CSV** and **Delete All Messages** (both re-checked server-side). The panel's settings menu also offers **Manage operators** (same permission) — a list of every operator with their connected/idle state and a **Disconnect** button that frees a stuck name and signs that session out. A name is only "in use" while an operator was seen within the last **90 s** with a live token; both `subscribe` and `rename` use that same test, so a departed operator's name auto-frees.

**Conversation list housekeeping.** **All Trackers** and the **Event Log** are pinned at the head of the list and always present — the two destinations that always exist should not have to be composed to, nor scroll away under ordinary traffic. Below them, threads whose other end has not been seen for 24 hours are hidden, keeping anything unread or currently open. Staleness is read from the **tracker feed's `lastUpdate`**, not `participants.last_seen`: `upsertParticipant()` writes `last_seen` on every write and it is written in bulk by paths that say nothing about activity (`_msg_ensure_all_mobiles()` touches every tracker on each broadcast), so a station gone for weeks looked freshly seen the moment somebody else broadcast. The recipient picker had always used the tracker feed, which is exactly why it was right about who was reachable while the conversation list was not — `_msg_mark_stale()` in `messaging.php` now puts both on the same clock. Each message also carries a **copy** button that yields the message text alone, with an `execCommand` fallback for the non-secure contexts (plain-HTTP LAN, NetBird address) where `navigator.clipboard` is unavailable.

**Two-screen operation (`?messages`).** The same document rendered as a messaging-only window for a second monitor — the map's chrome hidden and the panel promoted to fill the window. Not a separate page: the messaging JS is interleaved through `index.php` and everything it touches has to stay in scope. Opened from the panel menu via a **named** `window.open`, so repeat clicks focus the existing window.

The operator session already spans windows — it lives in `localStorage` and the restore at script load hands a second window the same token — so the second window **must never subscribe**: that would mint a fresh token, invalidate the map window's, and also collide with the 90-second name-in-use check. A `?messages` window opened directly, with no session to inherit, says how to open it properly rather than offering a modal that would do the damage.

Both windows poll, so exactly one announces. The messages window owns audio while open via a **speaker lease** in `localStorage`, renewed **on each poll rather than by a heartbeat timer**: background tabs have their timers throttled (to 1/s, and 1/min once hidden a while), so a merely covered messages window stops heartbeating while still running, and a timer-based liveness check reads that as death — measured against a real browser, the map window wrongly reclaimed audio and both would have announced. Renewing from the poll ties the lease to the activity that produces an announcement, so the two cannot disagree: a window that stopped polling stopped announcing, and reclaiming is then correct. The map window evaluates it lazily at announce time and needs no timer of its own. A `BroadcastChannel` carries the rest — a message's location pin drives `_showMsgLocation()` on the map window, and a tracker right-click on the map composes in the messages window.

**Auto-subscribe for Display Pi operators:** when `?autologin&operator=<name>` embeds the messaging password into the page (via PHP session → in-page script), the panel subscribes silently on load. The token is held only in memory, so removing line 1 of `~/autologin.txt` and rebooting cleanly unsubscribes.

### Mobile Participant UI (chat screen)

The app's **Message** (💬) button opens a **chat screen** mirroring the web panel: a **Conversations** list, threads, and a composer, with **New message → Start conversation / Start group** over the participant list (with Online/Offline presence). It sends text and **photos** (Take a photo / Choose from library), shows **Delivered ✓** / **Read ✓✓** receipts, and can **Read arriving messages aloud**. Background arrivals raise a notification with a distinct, insistent alert sound; tapping it opens the conversation. The app continues to satisfy the older `?mobile=` contract, so mixed-version fleets interoperate.

---

## Analyzer

The Analyzer is a separate Flask web application served at `/analyzer/` that records APRS beacons into a local SQLite database during an event and provides an interactive playback and analysis map. It is the only interface that preserves a historical record of all positions received — the main map only retains the most recent `breadcrumb_count` breadcrumbs per tracker (10–100). The Analyzer is intended for post-event analysis, coverage review, and real-time monitoring of beacon reception quality.

### Analyzer Architecture

```
APRS-IS (noam.aprs2.net:14580)
      │ TCP  (filtered to tracked callsigns)
      ▼
aprs_daemon.py  ──writes──▶  aprs.db  (SQLite)
                                   │
                             flask_app.py  ◀── browser polls on auto-refresh timer
                                   │
                             gunicorn  (127.0.0.1:5001, 2 workers)
                                   │
                          Apache mod_proxy  (/analyzer/ → 5001)
```

Two systemd services work together:

| Service | Unit file | Purpose |
|---------|-----------|---------|
| `analyzer` | `analyzer.service` | gunicorn serving `flask_app:app` |
| `analyzer-daemon` | `analyzer-daemon.service` | `aprs_daemon.py` recording beacons to SQLite |

`analyzer-daemon` is controlled from the Analyzer UI — it does not start automatically at boot. The `analyzer` (web app) service starts at boot and is always available even when no recording is taking place.

`flask_app.py` uses `ProxyFix(app.wsgi_app, x_prefix=1)` so that the `/analyzer/` path prefix is correctly stripped before routing.

### Analyzer Authentication

The Analyzer uses the shared user-account system described in [Authentication](#authentication).
Access is gated on two permissions:

| Permission | Required for |
|---|---|
| `analyzer.view` | All pages and API endpoints |
| `analyzer.admin` | `POST /api/daemon` (start/stop); `POST /api/flush` (erase data) |

The `marsaprs_session` cookie is validated by `auth_db.py` against `/var/lib/marsaprs/users.db`
on every request. Unauthenticated requests are redirected to `/auth/login.php`. The
**Collect Data** checkbox is disabled in the UI for users who lack `analyzer.admin`;
no password modal is shown.

### Beacon Recording

`aprs_daemon.py` connects to `noam.aprs2.net:14580` with an APRS-IS filter string built from all callsigns in the current event's configuration (trackers from `config.yaml`, mobile participants from `mobile_trackers.json`). It is managed by `analyzer-daemon.service` and controlled from the Analyzer UI by users with the admin password.

**Which event beacons are recorded against.** Always the current event — the one named by `event:` in `config.yaml` (the symlink to `events/<name>/event.yaml`). The event roster lives in the Admin UI, which does not write to `aprs.db`, so an event that has never been recorded has no row in the `events` table yet. Both recorders (and the Analyzer page itself) create that row on demand via `ensure_event()`, so **creating an event in the Admin UI is all the setup required** — there is no separate database step. `events.start_time` records when the event was first seen and is informational only; `end_time` is unused.

**There is no recording time window.** Recording runs for exactly as long as the services run, and the **Record** control in the Analyzer UI is the only start/stop. Earlier versions gated recording on `events.start_time`/`end_time`, but nothing in `event.yaml` ever supplied those values — an event with no database row silently fell back to a ten-minute window and wrote its beacons with a `NULL` `event_id`, where no query could see them. Both recorders now refuse to start rather than record against no event.

**What is recorded:** For each received packet that matches a tracked callsign: callsign, latitude, longitude, Unix timestamp, receiving station (iGate), and the full APRS path string. Stored in the `beacons` table of `aprs.db` (SQLite), keyed to the current event by `event_id`.

**Deduplication:** Consecutive beacons for the same callsign at the same position within a short interval are collapsed during the `get_ordered_deduplicated_beacons()` query so they don't clutter the playback trail. The query dedupes per callsign and then returns the list **sorted globally by time**, so the playback range maps its list-index sliders directly to a chronological window (see Map & Controls).

**Why `position_tolerance` is 0.0001 (~11 m).** When one of our own iGates is also the first gate to reach APRS-IS, both recorders log the same beacon, and the two feeds report its position differently. The public APRS-IS feed carries uncompressed `DDMM.mm` positions, quantized to 1/100 minute (`1.6667e-4°`), while `relay_daemon.py` reads full precision from the relay capture:

```
public APRS-IS:  37.97983333333333, -122.5775      (1/100-minute grid)
relay capture:   37.979808,         -122.577478    (full precision)
```

Rounding to nearest bounds the disagreement at half a quantization step — **`8.333e-5°`** — and measurement across 63 real pairs found a maximum of exactly `8.33e-5`, matching the bound to every digit. A tolerance of `0.0001` therefore provably collapses every such pair (the former `0.00001` caught 2% of them, leaving the map drawing two markers a few metres apart). Widening is safe because `receiver` is part of the comparison, so beacons heard by *different* gates are never merged — which is the distinction the per-iGate recording exists to capture. Measured against real event traffic, the wider tolerance removed only cross-feed duplicates and thinned no legitimate trail: the morning's public-only rows deduped to 49 at both settings, with per-tracker counts identical.

A timestamp-based dedup was considered and rejected. The two feeds do not agree on time either — `relay_daemon.py` stores `int(time.time())` from the **VPS** at gating time (truncated to whole seconds), while `aprs_daemon.py` stores `time.time()` on the **server Pi** at APRS-IS receipt (position packets carry no `timestamp` field, so the fallback always applies). The difference is always positive and small (median +0.59 s, max +1.12 s over 62 pairs — truncation loss plus propagation), but its safety margin rests on the minimum observed gap between consecutive beacons from one tracker (2.77 s), which is empirical. The position bound is structural, so it wins.

**Aid stations as iGates:** Aid stations and rest stops that have an APRS callsign configured in the Admin UI are treated as iGates on the Analyzer map — their coordinates are loaded from `config.yaml`, their received packets are displayed with red receiver lines, and they appear as map markers alongside regular iGates.

**Data persistence:** Beacon data is never deleted automatically. It persists across daemon restarts, page reloads, and server reboots until an operator explicitly uses **Erase All Data** (admin password + two-step confirmation). **Erase All Data is scoped to the current event** — it deletes only rows carrying that event's `event_id`, so recordings from other events are never touched. The SQLite database is excluded from the deploy rsync so a new deployment never wipes event data.

**Per-iGate recording (undeduped, from the aggregation relay).** `aprs_daemon.py`
above reads the *public* APRS-IS feed, which is **de-duplicated** — so each beacon
carries only one `receiver` (the first iGate to gate it), and you can't see which of
*our* gates actually heard a tracker or how their coverage overlaps. A second recorder,
**`relay_daemon.py`** (service `analyzer-relay-daemon`), fills that gap: it streams the
[aggregation relay's](#igate-aggregation-relay) undeduped `capture.jsonl` from the VPS
over SSH (a www-data-readable forced-command key that only `tail -F`s the file) and,
for the active event, inserts a beacon **per (tracker, iGate)** with `receiver` set to
the gating gate (`MARS-13`, `MARS-5`, …). It writes the same `beacons` table and reuses
the same event/watch-list logic; the Analyzer UI already draws a line from each beacon
to its receiving iGate, so it renders the full "who heard whom" picture with no display
change. Insertion is de-duped per **(callsign, receiver, position)** — a stationary
tracker heard 30× by one gate is a single tracker→gate link, while a *different* gate or
a *new* position is a new link. The Analyzer's **Record** control starts and stops both
recorders together (`flask_app.py` → `/api/daemon`), so the public-feed and per-iGate
records are always captured for the same window. This only sees gates that have the
relay enabled (see [iGate Aggregation Relay](#igate-aggregation-relay)); coverage grows
as more gates are opted in.

### Map & Controls

The Analyzer map is a Leaflet map using the same tile backgrounds configured in the event. All recorded beacons for the current event are loaded on page load and re-fetched on every auto-refresh cycle.

**Beacon rendering:**

| Beacon type | Color | Line |
|-------------|-------|------|
| Radio (via iGate) | Red dot | Red line from beacon → iGate |
| Cellular (mobile participant) | Green dot | Green line between consecutive positions |

**Show Full Path** extends radio receiver lines through all intermediate digipeaters in the APRS path, not just the final iGate. When a single iGate is selected, lines route to that iGate/digipeater specifically.

**Name labels** (Show Names checkbox, default on): Each tracker's name is displayed in a black-on-white label at its last known position. iGate and aid station names are shown as permanent Leaflet tooltips on their map markers.

**Controls modal** (gear icon, lower-left corner):

*Left column — Display*

| Control | Function |
|---------|----------|
| Show All Times | Toggle time tooltip visibility on all beacon dots |
| Show Full Path | Draw lines through all digipeaters, not just the final iGate |
| Show Radio Beacons 🔴 | Show/hide all radio (APRS) beacon dots and lines |
| Show Cellular Beacons 🟢 | Show/hide all cellular (mobile app) beacon dots and lines |
| Show Courses | Toggle GPX/KML/GeoJSON course overlays |
| Show Names | Toggle name labels for trackers, iGates, and aid stations |
| Auto-Refresh | Slider: poll interval from 30 s to 5 min (per-client; does not affect recording) |
| Beacon Time Range | Two sliders trim the displayed beacon window; each slider shows the actual date/time of the first/last beacon in range. Because beacons are returned in global time order, the window is chronological — the end slider tracks the newest beacon across all trackers (not whichever tracker happened to sort last), and each track is drawn from every tracker's own previous point so interleaved ordering doesn't break the trails |

*Right column — Filtering*

| Control | Function |
|---------|----------|
| Trackers | Multi-select list of all tracked callsigns. Hybrid trackers (mobile + ham radio) appear as a single entry; selecting one shows both callsign streams. ⌘/Ctrl+click for multiple. |
| IGates | Multi-select list of iGates and digipeaters. Filtering to one iGate shows only beacons received by that station. |
| Cellular Carrier | Multi-select: All · AT&T · Comcast · Space Exploration · T-Mobile · Verizon · Other. Filters cellular beacons by the carrier stored in `device_info.carrier` in `mobile_trackers.json`. Radio beacons are unaffected. |

*Footer buttons*

| Button | Function |
|--------|----------|
| Save Map Position | Saves the current map center and zoom to `localStorage` as the default view for this event |
| Erase All Data… | Deletes all beacon rows for the current event from SQLite (requires admin password + two-step confirmation) |

### Analyzer Key Files

All files live under `/home/pi/analyzer/` on the server Pi.

| File | Purpose |
|------|---------|
| `src/flask_app.py` | Flask application: routes, auth, config loading, beacon enrichment, template rendering |
| `src/aprs_daemon.py` | APRS-IS listener (public, deduped feed); inserts beacons into SQLite; reads tracker list from `config.yaml` and `mobile_trackers.json` |
| `src/relay_daemon.py` | Per-iGate recorder; streams the aggregation relay's undeduped `capture.jsonl` from the VPS and inserts a beacon per (tracker, iGate). Uses `relaycap_key` (www-data-readable, forced-command SSH) |
| `src/aprs_db.py` | SQLite wrapper: event management, beacon insert, deduplicated + globally time-sorted beacon fetch, recording time range queries |
| `src/aprs.db` | SQLite database; excluded from deploys |
| `src/templates/event_map.html` | Main live map page (Leaflet + controls modal); loads the shared player engine |
| `src/static/session_player.js` | Shared rendering + playback engine (filtering, time-range sliders, track drawing) used by both the live map and exported sessions |
| `src/auth_db.py` | Python auth library: validates `marsaprs_session` cookie against `users.db` |

Configuration is read live from `/var/www/html/admin/config.yaml` (the active event symlink) and `/var/www/html/mobile_trackers.json` on every page load — no restart needed when the event or tracker list changes.

### Analyzer Services

```bash
# Flask web app (always on)
sudo systemctl status analyzer
sudo systemctl restart analyzer
sudo journalctl -u analyzer -f

# Beacon recording daemons (both started/stopped together from the UI Record control)
sudo systemctl status analyzer-daemon        # public deduped APRS-IS feed
sudo systemctl status analyzer-relay-daemon  # per-iGate undeduped relay capture
sudo systemctl start analyzer-daemon analyzer-relay-daemon
sudo systemctl stop  analyzer-daemon analyzer-relay-daemon
sudo journalctl -u analyzer-daemon -u analyzer-relay-daemon -f
```

Logs: `/var/log/analyzer/analyzer.log`, `/var/log/analyzer/daemon.log`, and `/var/log/analyzer/relay-daemon.log`

The daemon restarts automatically on failure (30 s delay, 5 retries per 5 minutes). The web app restarts automatically on failure (10 s delay).

### Analyzer API Endpoints

All endpoints require a valid `marsaprs_session` cookie with at least `analyzer.view`.
Admin endpoints additionally require `analyzer.admin`.

| Endpoint | Method | Permission | Description |
|----------|--------|------------|-------------|
| `/` | GET | `analyzer.view` | Redirect to current event URL |
| `/event/<name>` | GET | `analyzer.view` | Main map page; always redirects to the current yaml event |
| `/api/event_beacons/<name>` | GET | `analyzer.view` | All deduplicated beacons for the named event as JSON |
| `/api/check_admin` | GET | `analyzer.view` | Returns `{"ok": true}` if user has `analyzer.admin` |
| `/api/daemon` | GET | `analyzer.view` | Returns `{"running": true/false}` — current daemon status |
| `/api/daemon` | POST | `analyzer.admin` | `{"action": "start"\|"stop"}` — starts or stops `analyzer-daemon` |
| `/api/flush` | POST | `analyzer.admin` | Deletes all beacon rows for the current event |

---

## Backup, Recovery and Updates

### Server Pi

The server is backed up nightly to an FTP server using `aprs-backup.sh` (requires `lftp`).
The backup includes event configs, tracker history, and the WiFi credential file.

To restore: run `aprs-recover.sh`, which downloads the latest backup from the FTP server
and restores files in place.

### Display Pis

Each display Pi runs `auto-update.sh` nightly at 4:00 am. It downloads a tar archive of
updated scripts from the server, applies them via rsync, updates WiFi credentials, and
reboots at 4:10 am to pick up any changes.

To force an immediate update:

```bash
ssh pi@<ip> /home/pi/auto-update.sh
```

There is no backup for the display Pis since their data are all temporary.

### iGates

Each iGate runs `auto-update.sh` nightly at 4:00 am. It downloads updated direwatch
scripts and the WiFi credential list from the server, applies them, and restarts affected
services. If `direwolf.conf` has changed it restarts direwolf.

To force an immediate update:

```bash
ssh pi@<ip> /home/pi/auto-update.sh
```

> **Run it twice when upgrading from an older build.** `auto-update.sh` downloads and
> overwrites *itself* partway through, so a device running an older copy applies only the
> blocks that existed in that older script. Anything newer — version stamping, IGLOGIN
> promotion, relay enrolment, added cron entries — does not run until the **second** pass.
>
> The symptom is a device that looks updated (`wc -c /home/pi/auto-update.sh` matches the
> current one) while `config.php` and `direwolf.conf` still report the old version and no
> relay sentinel appears. Just run it again. Seen across six gates on 2026-08-07 upgrading
> 5.1 → 5.2.

There is no backup for the iGates since their data are all temporary.

---

## Log Rotation

Log rotation is configured via `/etc/logrotate.d/aprs`, installed by `install.sh` on iGates and the server.

**iGates** — logs in `/var/log/direwolf/`:

| File | Written by |
|------|-----------|
| `console.log` | direwolf (RF decoding, APRS-IS forwarding) |
| `watchdog.log` | `igate-watchdog.sh` (SDR, IP, internet checks) |

**Display Pis** — logs in `/home/pi/`:

| File | Written by |
|------|-----------|
| `update.log` | `auto-update.sh` (nightly update) |

**Server** — multiple locations:

| File | Written by |
|------|-----------|
| `/var/log/aprs-daemon/daemon.log` | `aprsDaemon.php` (APRS-IS connection, packet processing) |
| `/var/log/netbird-poller.log` | `netbird-poller.py` (NetBird device polling) |
| `/var/log/aprs-backup.log` | `aprs-backup.sh` (nightly FTP backup) |
| `/var/log/boot.log` | System boot messages (kernel + service startup output) |

All three APRS-specific logs are covered by `/etc/logrotate.d/aprs` (installed by `install.sh`). `boot.log` is managed by the system's default logrotate config. Logs are rotated daily, compressed, and seven days of history are retained.

---

## Building & Deploying Devices

Master SD card images for each device type are stored on the FTP server: [ftp://ftp.w6sg.net/APRS-SD-Masters](ftp://ftp.w6sg.net/APRS-SD-Masters). They include
the results of running `install.sh` but not `configure.sh` — so packages, services, and
scripts are pre-installed, but site-specific settings (callsign, location, hostname) are
set when deploying each individual device.

### NetBird Setup Keys

As part of configuring any device, you will need a NetBird key. At the moment, the only way to get one is to email Doug Kaye at [doug@rds.com](mailto:doug@rds.com). 

### APRS Server

The server master image is a complete, fully-configured copy of the running server. Flashing
it to a replacement Pi produces a working server immediately — no further steps needed.

1. Download and flash the server master image from the FTP server using **Apple Pi Baker** or Raspberry Pi Imager.
2. Insert SD card and boot. The server is ready.
3. Configuration should only be necessary if something (such as a NetBird address) has changed from the standard setup.

### Display Pis & iGates

1. Download and flash the display Pi master image.
2. Insert SD card, connect to your local network, and boot.
3. Find the Pi's IP address (from your router or `arp -a <hostname>`).
4. SSH in: `ssh pi@<ip>` (password: `guacamole`)
5. Run the configuration wizard:
   ```bash
   /home/pi/configure.sh
   ```
   Expected hostnames: `NetControl` and `BigTV`.
6. Reboot when prompted.

---

## Creating New Master Images

### APRS Server

The server master is simply a snapshot of the current running server. No install script is
needed (and none is available — the script would be served by the very Pi being built).

1. Shut down aprs-pi cleanly: `sudo shutdown -h now`
2. Remove the SD card and copy it using **Apple Pi Baker** or `dd`.
3. Reinstall the original card and reboot.

### Display Pis

1. Flash a fresh **Raspberry Pi OS Desktop (64-bit, Trixie)** to an SD card using
   Raspberry Pi Imager. In the OS customization dialog set:
   - Model: **Pi 4**
   - Hostname: `displayClone`
   - Username / password: `pi` / `guacamole`
   - WiFi: your local network
   - SSH: enabled
   - Timezone: as appropriate
2. Insert the card into a Pi 4 and boot.
3. Find the IP and SSH in.
4. Download and run the install script:
   ```bash
   bash <(curl -fsSL https://marsaprs.org/display/install.sh) 2>&1 | tee install.log
   ```
5. **Do not run `configure.sh`** — that step is done when deploying each device.
6. Shut down: `sudo shutdown -h now`
7. Copy the SD card using **Apple Pi Baker** — this is the new display Pi master.
8. Upload the image file to **`ftp.w6sg.net/APRS-SD-Masters`** for later cloning.

> **Cloning a master is safe; cloning a deployed device is not.** Step 5 is what makes the
> difference — a master has never run `configure.sh`, so it carries no NetBird enrolment, no
> operator name and a placeholder hostname. A card taken from a *working* display brings all
> of that with it, plus `/var/lib/bluetooth/<adapter>/`: on 2026-08-07 a Bluetooth mouse
> paired to a previous Pi failed with `ConnectionAttemptFailed: Page Timeout` on the new one,
> and nothing done to the mouse could fix it, because the stale bond was on the Pi. Two peers
> sharing one NetBird identity flap endlessly. If you must clone a deployed card, scrub the
> NetBird enrolment, `/var/lib/bluetooth/*`, hostname, `autologin.txt`, SSH host keys and
> `machine-id` before first boot.
>
> Note the master will have **no fleet WiFi list**: `install.sh` skips that download unless
> `/home/pi/.wifi-token` already exists. Either add the token before step 4, or add it at
> deploy time and re-run `/home/pi/update-wifi.php`.

### iGates

1. Flash a fresh **Raspberry Pi OS Desktop (64-bit, Trixie)** to an SD card using
   Raspberry Pi Imager. In the OS customization dialog set:
   - Model: **Pi Zero 2 W**
   - Hostname: `trackerClone`
   - Username / password: `pi` / `guacamole`
   - WiFi: your local network
   - SSH: enabled
   - Timezone: as appropriate
2. Insert the card into a Pi Zero 2 W and boot.
3. Find the IP and SSH in.
4. Download and run the install script:
   ```bash
   bash <(curl -fsSL https://marsaprs.org/igate/install.sh) 2>&1 | tee install.log
   ```
5. **Do not run `configure.sh`** — that step is done when deploying each device.
6. Shut down: `sudo shutdown -h now`
7. Copy the SD card using **Apple Pi Baker** — this is the new iGate master.
8. Upload the image file to **`ftp.w6sg.net/APRS-SD-Masters`** for later cloning.

---

## Supporting Systems

### NetBird Status Monitor

A web application at `/netbird/` that monitors all registered Pi devices over the NetBird
VPN, showing real-time health status and providing a browser-based SSH terminal.

#### Architecture

```
addresses.yaml  (device list, credentials)
toggle_state.json  (per-device toggle timestamps)
      │
      ├──▶  netbird-poller.py  ──writes──▶  stats.json
      │        │  UDP poll every N seconds
      │        ▼
      │     each device's NetBird IP : 1235
      │
      ├──▶  api.php  ◀── browser polls every N seconds
      │        │  merges YAML + stats.json + toggle_state.json
      │        ▼
      │     index.php  (main status page)
      │
      └──▶  admin.php  (device management)
               │
               ├──▶  save.php  (CRUD + toggle)
               └──▶  ssh_term.php  ──SSE──▶  ssh_stream.php
                                                   │  proc_open
                                                   ▼
                                              ssh_relay.py  (paramiko)
                                                   │  NetBird VPN
                                                   ▼
                                              remote device
```

| File | Role |
|------|------|
| `netbird-poller.py` | UDP polling daemon (Python); sends requests to enabled devices, writes `stats.json` |
| `api.php` | JSON endpoint; merges `addresses.yaml`, `stats.json`, and `toggle_state.json`; computes `pending_until` server-side |
| `index.php` | Main status page; password-protected; polls `api.php` every N seconds |
| `admin.php` | Device list CRUD; toggle switches; SSH terminal launch |
| `save.php` | Handles all CRUD actions and toggle; computes and returns `pending_until` on toggle |
| `yaml_lib.php` | Custom YAML parser/writer (no `php-yaml` extension required) |
| `addresses.yaml` | Device configuration: name, host, ip, group, enabled, ssh\_user, ssh\_pass |
| `stats.json` | Live poller output; read by `api.php` |
| `toggle_state.json` | Per-device toggle timestamps (Unix epoch); written by `save.php` on every enable/disable |
| `ssh_term.php` | Browser SSH terminal popup (xterm.js) |
| `ssh_stream.php` | SSE relay; spawns `ssh_relay.py` via `proc_open` |
| `ssh_relay.py` | Python/paramiko SSH relay; reads input queue, writes output to stdout |
| `ssh_input.php` | Receives keystrokes from browser; appends to per-session queue file |
| `ssh_resize.php` | Receives terminal resize events; writes to per-session resize file |

#### Device Configuration (`addresses.yaml`)

```yaml
- name: Muir Woods
  host: MARS-2            # APRS callsign / hostname used for status lookups
  ip: 100.101.197.70      # NetBird IP
  group: "via internet (WiFi or Ethernet)"
  enabled: true
  ssh_user: pi            # stored when first SSH login succeeds
  ssh_pass: ""            # stored only if "Remember password" is checked
```

The `group` field becomes a section header in both the admin and main pages. The `host`
field is used for the hostname self-reporting endpoint. `ssh_user` and `ssh_pass` are
written by `save.php` when a user authenticates via the SSH terminal.

#### Status States

| Status | Condition | Color |
|--------|-----------|--------|
| **Online** | Device responded to the last UDP poll | Green |
| **Pending** | Device was recently enabled or disabled; waiting for the change to take effect | Blue |
| **Offline** | Enabled but no response after the pending period has fully elapsed | Red |
| **Disabled** | `enabled: false` in `addresses.yaml`, and not in a pending window | Gray |

**Pending** applies to both transitions:

- **Enable (off → on):** Devices check whether to start NetBird on 5-minute clock boundaries (0:00, 0:05, 0:10, …). After the server sets a device enabled, it won't respond via its NetBird address until ~30 seconds after the next 5-minute boundary. Once past that deadline, the system allows 3 additional polling intervals for the device to respond before declaring it Offline.
- **Disable (on → off):** Similarly, a disabled device will keep responding until ~30 seconds past the next 5-minute boundary. The status shows Pending until the deadline passes, rather than showing Disabled prematurely while the device is still reachable.

`pending_until` is computed entirely server-side by `api.php` and `save.php`:

```php
$deadline     = (int)(ceil($toggled_at / 300) * 300) + 30;
$pending_until = $enabled
    ? $deadline + ($repeat_seconds * 3)   // enable: extra time for 3 missed polls
    : $deadline;                           // disable: deadline only
```

The browser compares `Date.now() / 1000 < pending_until` and renders the Pending badge accordingly. No scheduling logic lives in the client.

#### Stats Response Format

Each Pi runs `stats-listener.php` (iGate: `stats-listener.php`; display Pi / server: `StatsRequestListener.php`) as a systemd service listening on UDP port 1235. When polled by `daemon.php`, it responds with a single line of plain text.

**Full response** (default):
```
<hostname>  Load=<load>  Temp=<temp>  Mem=<disk>  Throttled=<throttled>  <NetBird-IP>  SSID=<ssid>  [Low Voltage][High Temp]
```

**Short response** (when the request contains the word `short`):
```
<hostname> Load=<load> <temp> Throttled=<throttled> SSID=<ssid> [Low Voltage][High Temp]
```

| Field | Source | Notes |
|-------|--------|-------|
| `hostname` | `hostname` command | |
| `Load` | `uptime` | 1, 5, and 15-minute load averages |
| `Temp` | `vcgencmd measure_temp` | e.g. `temp=47.8'C` |
| `Mem` | `du -sh ~` | Home directory size; proxy for total disk use |
| `Throttled` | `vcgencmd get_throttled` | Hex bitmask; `0x00000` = normal |
| `<NetBird-IP>` | `netbird status` | Omitted in short form |
| `SSID` | `nmcli` | WiFi network name, or `<Ethernet>` |
| `Low Voltage` | Throttled bit 0 | Appended only when set |
| `High Temp` | Throttled bit 3 | Appended only when set |

Example response as shown on the NetBird Status page:
```
0.02,0.06,0.11  40.4'C  128M  TerraceLan2  Low Voltage
```

#### Immediate Status Propagation

`api.php` merges `addresses.yaml`, `stats.json`, and `toggle_state.json` on every request,
so toggling a device in the admin page is reflected on the status page on its very next poll
— no poller cycle needed. `save.php` also returns `pending_until` directly in the toggle
response so the admin page can render the correct Pending state immediately, before the next
poll completes. A `BroadcastChannel('aprs_netbird')` message carries `pending_until` to the
status page if it is open in the same browser.

#### Hostname Self-Reporting Endpoint

```
GET /netbird/?hostname=<callsign>
```

No authentication required. Returns a plain-text integer:

| Value | Meaning |
|-------|---------|
| `1` | Device is known and `enabled: true` |
| `0` | Device is known and `enabled: false` |
| `-1` | Device not found in `addresses.yaml` |

iGates use this to determine whether to transmit APRS beacons. They poll it on 5-minute
clock boundaries.

#### SSH Terminal

The SSH button (admin page, active when device is Online) opens a popup with a full
terminal powered by xterm.js.

**Security:** If both `ssh_user` and `ssh_pass` are stored, PHP mints a one-time session
token server-side — the password is never sent to the browser. If credentials are missing,
a login form is shown; on success the credentials are saved to `addresses.yaml`.

**Relay architecture:**
1. `ssh_stream.php` spawns `ssh_relay.py` via PHP `proc_open`.
2. `ssh_relay.py` opens a paramiko PTY shell to the device.
3. SSH output → base64 lines → PHP → SSE → browser → xterm.js.
4. Keystrokes → `ssh_input.php` → queue file → relay → SSH channel.
5. Terminal resize events → `ssh_resize.php` → `chan.resize_pty(cols, rows)`.

**Dependency:** `sudo apt-get install python3-paramiko`

#### Poller (`netbird-poller.py`)

Run by `netbird-daemon.service`. Polls enabled devices every `repeat_seconds` (default 60)
when there are active viewers. Writes responses to `stats.json` via atomic rename. Requires
3 consecutive missed polls before marking a device Offline.

```bash
sudo systemctl status netbird-daemon
sudo systemctl restart netbird-daemon
sudo journalctl -u netbird-daemon -f
```

Log: `/var/www/html/netbird/daemon.log`

#### API: `api.php`

No authentication required. Polled by the main page every 15 seconds.

`GET api.php` — returns full device status for all devices:

```json
{
  "last_send_ts": 1779080795,
  "repeat_seconds": 60,
  "daemon_running": true,
  "devices": [
    {
      "ip": "100.101.197.70",
      "hostname": "MARS-2",
      "name": "Muir Woods",
      "group": "via internet (WiFi or Ethernet)",
      "enabled": true,
      "online": true,
      "pending_until": null,
      "last_request": 1779080795,
      "last_response": 1779080795,
      "response_data": "MARS-2 Load=0.19 Temp=47.8°C ..."
    }
  ]
}
```

`pending_until` is a Unix timestamp (or `null`). The browser renders Pending while `Date.now() / 1000 < pending_until`.

#### API: `save.php`

| Action | Auth | Method | Key Parameters | Description |
|--------|------|--------|----------------|-------------|
| `add_device` | required | POST | name, host, ip, group, enabled | Append device |
| `update_device` | required | POST | orig\_ip, name, host, ip, group, enabled | Edit device |
| `delete_device` | required | POST | ip | Remove device |
| `toggle_device` | required | POST | ip | Flip `enabled`; returns `{ok, enabled, pending_until}` |
| `save_config` | open | POST | repeat\_seconds | Update poll interval |
| `save_ssh_creds` | required | POST | ip, ssh\_user, ssh\_pass | Update credentials |

---

### WiFi Manager

A web application at `/wifi/` for managing the shared list of WiFi credentials distributed
to all MARS Pi devices nightly.

#### Architecture

```
/var/www/html/wifi/wifi.yaml  (on aprs-pi — master copy)
     │
     ├──▶  /igate/wifi/get.php?token=<token>    ──▶  iGates
     ├──▶  /display/wifi/get.php?token=<token>  ──▶  Display Pis
     └──▶  update-wifi.php                      ──▶  Server Pi (nightly via cron)
```

| File | Role |
|------|------|
| `server/www/wifi/index.php` | Web UI for viewing and editing the credential list |
| `server/www/wifi/get.php` | Token-authenticated download endpoint for iGates |
| `display/www/wifi/get.php` | Token-authenticated download endpoint for display Pis |
| `/home/pi/update-wifi.php` | Applies `wifi.yaml` via `nmcli` on each Pi |
| `/home/pi/.wifi-token` | Shared authentication token (required on all Pi types) |

Every edit and drag-drop reorder immediately writes `wifi.yaml` via the `?save` endpoint.
There is no manual save step.

#### Update Schedule

| Device | When credentials update |
|--------|------------------------|
| iGates | Nightly at 4:00 am via `auto-update.sh` |
| Display Pis | Nightly at 4:01 am via `auto-update.sh` |
| Server Pi | Nightly at 4:00 am via cron |

Note that display Pis (Net Control and Big TV) are likely not running at 4am and therefore must be updated manually.

#### Token Authentication

Each Pi has `/home/pi/.wifi-token`. Download requests without a valid token return HTTP 403.
If the token is missing from the server:

```bash
tar -xzf /var/www/html/igate/files.tar.gz -C /tmp ./home/.wifi-token
cp /tmp/home/.wifi-token /home/pi/.wifi-token
```

#### Safe Download Pattern

`auto-update.sh` downloads to a temp file and validates content before replacing the live copy:

```bash
wget -qO /tmp/wifi.yaml.new "$BASE/wifi/get.php?token=$(cat /home/pi/.wifi-token)"
grep -q "^- name:" /tmp/wifi.yaml.new && mv /tmp/wifi.yaml.new /home/pi/wifi.yaml
```

`update-wifi.php` exits without making any `nmcli` changes if zero entries are parsed,
preventing accidental deletion of all configured networks.

---

## Appendix

---

### File Formats

#### `event.yaml`

Each event's configuration lives in `events/<EventName>/event.yaml`. `config.yaml` is a
symlink to the active event's file. `aprsDaemon.php` and `index.php` monitor it via
`filemtime`; changes take effect within ~5 seconds without restarting the daemon.

##### `event`

```yaml
event: Dipsea 2026
```

##### `legend`

Optional HTML displayed in the lower-left corner of the map in kiosk mode (ie, for the Big TV). The value is injected as `innerHTML`, so any HTML tags, inline styles, and attributes are supported. In the YAML file, use `\n` inside a quoted string to break across lines for readability.

```yaml
legend: "<b>Race Day — June 14</b><br>Start: 7:00 AM at Mill Valley"
```

##### `trackers`

```yaml
trackers:
  - callsign: W6SG-4    # APRS callsign
    id: S2              # short label on the map marker and sidebar
    name: Rob           # full name shown in the sidebar
```

##### `tracker_style`

```yaml
tracker_style:
  icon: circle          # circle, square, diamond, triangle, star, cross, person
  label_color: "#000000"
```

Marker size scales with zoom level: full at zoom ≥ 14, down to 30 % at zoom 7 and below.

##### `map`

```yaml
map:
  lat: 37.87255
  lon: -122.544079
  zoom: 13
```

##### `backgrounds`

```yaml
backgrounds:
  - name: OpenStreetMap
    url: https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png
    attribution: "&copy; <a href='https://www.openstreetmap.org/copyright'>OpenStreetMap</a> contributors"
    max_zoom: 19
```

`max_zoom` caps the Leaflet zoom level for this tile layer. Omit to default to 19.

##### `courses`

GPX, KML, GeoJSON, or JSON overlays. Files must be in the event directory.

```yaml
courses:
  - name: 13 Mile
    file: events/Dipsea 2026/13_Mile.geojson
    color: "#2196f3"
```

Supported extensions: `.gpx` `.kml` `.geojson` `.json`

##### `aidstations`

```yaml
aidstations:
  - name: Muir Woods Clubhouse
    lat: 37.89799
    lon: -122.56667
```

##### `igates`

```yaml
igates:
  - name: Pt. Reyes Station
    lat: 38.068051
    lon: -122.808013
```

##### `section_visibility`

Default visibility of each sidebar section on page load.

```yaml
section_visibility:
  trackers: true
  courses: true
  aidstations: true
  igates: true
  backgrounds: true
```

##### `mobile`

Controls mobile location sharing for participants.

```yaml
mobile:
  enabled: true              # show Share Location button in the mobile drawer
  pin: "1234"                # PIN participants must enter (omit to require no PIN)
  root: K6DRK                # base APRS callsign; participants get <root>-01, -02, etc.
  messaging_password: zippy  # enables Messages button on web map; omit to disable messaging
```

When `enabled` is true, any visitor on a mobile device sees a **Share Location** button in the drawer footer. Participants enter their name (pre-filled from localStorage on return visits) and the PIN (both shown as plain text), then tap **Share Location** to start sharing in **unknown** (?) mode. **Smart Track** automatically determines the activity and adjusts the beacon interval based on GPS speed within ~90 seconds (see [Smart Track](#smart-track)). The button changes to **Sharing** while active; tapping it opens a panel to stop sharing. The server assigns a unique callsign (`<root>-NN`) and a session token. Position updates are injected directly into the APRS-IS network, so mobile participants appear on this map and on external APRS sites (aprs.fi, CalTopo) exactly like any other tracker. Session state is stored in `mobile_trackers.json`.

---

### Server

#### Requirements

- PHP 8.x CLI (daemon) and web (Apache)
- PHP `sockets` extension enabled
- Web root (`/var/www/html/`) writable by `www-data` group
- Network access to `noam.aprs2.net:14580`
- `python3-paramiko` for the SSH terminal relay

#### Configuration (`config.yaml`)

`config.yaml` is a symlink to the active event's `event.yaml`. Changes take effect within
~5 seconds without restarting the daemon. See [File Formats](#file-formats) for the full
`event.yaml` field reference.

#### Event Directory Structure

Each event lives in `events/<EventName>/`. `config.yaml` is a symlink to the active event.

```
events/
├── Dipsea 2026/
│   ├── event.yaml              # event configuration
│   ├── Dipsea.json             # course overlay
│   └── tracker_history.yaml    # auto-generated; not committed to git
└── Marin Ultra Challenge 2026/
    ├── event.yaml
    ├── 13_Mile.geojson
    ├── 18_Mile.json
    └── 6_Mile.json
config.yaml → events/Dipsea 2026/event.yaml   (symlink)
```

`tracker_history.yaml` — auto-generated by `aprsDaemon.php`. Stores the most-recent beacon positions per tracker. Pruned when a tracker is removed from the config. Also pruned for a mobile participant's callsign when they start a new session (`?mobile=join`), so stale breadcrumbs from a prior session never appear.

**Retention follows `breadcrumb_count`.** The daemon keeps `$breadcrumbRetain` positions per tracker — read from `breadcrumb_count` in `config.yaml` on every config reload, clamped to 10–100. **The daemon is the binding limit:** both clients ask for `breadcrumb_count` points and can only draw what the daemon stored, so retention must be at least as large as the slider. This was formerly hard-coded to 10 in four places while the Admin slider ranged to 100, which silently clipped every trail to 10 dots on the web map and the iOS app alike no matter what the slider said. Raising the slider takes effect on the next config reload without a daemon restart, but only fills going forward — discarded positions cannot be recovered.

**Startup ordering note:** `loadMobileSessions()` must run before `readTrackerHistoryFile()` at daemon startup. `loadMobileSessions()` calls `unset($trackerHistory[$cs])` for any callsign not yet in `$trackers`; if history were loaded first, all mobile callsigns would be cleared (they are absent from `$trackers` until `loadMobileSessions()` adds them). Running sessions load first makes the `unset()` calls harmless (the array is still empty).

**Minimum-distance filter:** Before appending a new breadcrumb, the daemon computes the haversine distance from the previous breadcrumb. If the new position is within 30.48 m (100 feet), it is discarded — `lastUpdate` is still updated (so the tracker doesn't appear stale), but no new breadcrumb is added and `trackers.json` position fields are not changed. This filter applies to both regular and mobile trackers and covers both web and native-app clients, since both read the same server-side files.

**APRS-IS injection deduplication:** `?mobile=update` now gates each call to `injectAprsPacket()` with a server-side dedup check. The last injected position and timestamp (`aprs_lat`, `aprs_lon`, `aprs_ts`) are stored per tracker in `mobile_trackers.json`. A new packet is injected only when the tracker has moved ≥ 30 m from the last injected position **or** ≥ 5 minutes have elapsed since the last injection (heartbeat). This does not affect what is stored, displayed, or returned to clients — every `?mobile=update` is still processed fully; only the outbound TCP connection to `noam.aprs2.net` is skipped when the position hasn't changed meaningfully.

#### Running the Daemon

**Direct (foreground):**

```bash
php aprsDaemon.php
```

| Option | Default | Description |
|--------|---------|-------------|
| `server=<host>` | `noam.aprs2.net` | APRS-IS server hostname |
| `config=<file>` | `config.yaml` | Path to configuration file |
| `trackerstatus=<file>` | `trackers.json` | Path to tracker status output file |
| `debug` | off | Print every received APRS line to stdout |

**Via systemd:**

```bash
sudo systemctl status aprs-daemon
sudo journalctl -u aprs-daemon -f
sudo systemctl restart aprs-daemon
```

Log: `/var/log/aprs-daemon/daemon.log`

The wrapper script (`aprs-daemon.sh`) handles PID-file-based duplicate-start protection.
systemd restarts the daemon on failure (10 s delay, 5 retries per 5 minutes).

**Tracker colors:**

| Color | Condition |
|--------|-----------|
| Green | Heard within the last 2 minutes |
| Blue | Heard within the last 5 minutes |
| Red | Not heard for more than 5 minutes |

**APRS packet decoding:**

| Format | Description |
|--------|-------------|
| Uncompressed | `DDmm.mmN/DDDmm.mmW` — degrees and decimal minutes |
| Compressed Base91 | 4-byte Base91-encoded lat/lon in the payload |
| Mic-E | Latitude in the 6-character AX.25 destination field; longitude in payload |

The daemon maintains a 60-second socket receive timeout; if no data arrives (e.g., network
change) the socket is closed and a new connection established automatically.

A **watchdog** check runs at the top of every main loop iteration. If no data has been written in more than 3 minutes (i.e., the APRS-IS connection stays `ESTABLISHED` but delivers no packets), the daemon reconnects and resets the timer. This prevents a silent multi-hour freeze that occurs when the APRS-IS server goes quiet without closing the TCP connection.

```php
// At the top of the while(TRUE) loop:
if ((time() - $lastWriteTime) > 180) {
    connectToAprsServer();
    $lastWriteTime = time();
}
```

#### Web Interface

The map is at `https://marsaprs.org/`. Layout adapts automatically:

- **Desktop** — fixed sidebar on the left; map fills the rest.
- **Touch/mobile** — map fills the screen; slide-in drawer opened with the ⚙ icon.

**Mobile full-screen:** On iOS and iPadOS, `index.php` shows a one-time nudge at the bottom of the screen on first visit. Safari users see "tap Share ⬆ → Add to Home Screen"; Chrome (`CriOS`) users see "tap ⋯ → Add to Home Screen". Dismissing the nudge via ✕ sets `localStorage['a2hs-dismissed']` so it never reappears. Once installed as a home screen app (standalone mode), the page runs without address bar or tab bar, enabled by the `apple-mobile-web-app-capable` and `apple-mobile-web-app-status-bar-style` meta tags. On Android Chrome, the address bar auto-hides on scroll; no nudge is needed.

**Sidebar sections (desktop):** Each section header has a visibility checkbox (show/hide map
objects) and a click-to-collapse toggle. Sidebar width is adjustable by dragging the divider;
width is saved in `localStorage`.

**Desktop lower-right corner controls:** On non-touch screens, two Leaflet controls appear in the lower-right corner of the map (above the zoom controls):
- **↺ Reset Map** — resets the map view, clears breadcrumbs and the Origin marker (same as the sidebar Reset Map button).
- **⊕ My Location** — requests the browser's geolocation and pans to the result; blinks while locating; shows an error tooltip if geolocation fails or times out (8 s timeout).

**Mobile drawer:** Sections are collapsible accordions. Visibility checkboxes work the same
as desktop. Footer buttons: Save Map, Admin, User Guide, Kiosk Mode, Clients, and (when `mobile.enabled`) Share Location / Sharing. The **About** section of the drawer shows the user's assigned APRS callsign while sharing is active. The floating reset button (↺) pinned to the map also closes the drawer if it is open before recentering.

**Mobile tracker markers:** Mobile participants (sharing via Share Location) are rendered as **rounded squares** on both the map and in the sidebar, distinguishing them from regular APRS trackers (which use the configured icon shape). The shape is determined by `t.mobile` in the tracker JSON. In `index.php`, the icon is selected as `t.mobile ? 'square' : trackerStyle.icon`; the sidebar dot gets `border-radius: 3px` for mobile and `50%` for others. In the native Flutter app, each marker also shows the **tracker ID** as a text label directly next to the dot on the map.

**Tracker clicks (desktop — three-click cycle):**
1. Blink tracker + show beacon history (up to 10 positions as dots + dashed polyline with directional arrows; trail auto-refreshes as tracker moves)
2. Zoom to tracker's last position (zoom 15)
3. Reset map to default view

**Tracker touches (mobile):**
- Short tap — blink + show history; drawer stays open
- Long press (≥ 500 ms) — blink + history + close drawer + zoom to position

**Origin, distance, bearing:** Right-click sets a red Origin marker. Left-click anywhere
shows distance (miles) and bearing from Origin. Origin clears on Reset Map.

**Map layer z-order (top to bottom):**

| Layer | z-index |
|-------|---------|
| Trackers | 450 |
| Aid Stations | 430 |
| Courses | 410 |
| iGates | 390 |

**Kiosk mode:** `?kiosk=1` or click **Kiosk Mode**. Shows Trackers, Aid Stations, and
iGates only. Footer: Sidebar toggle, Reset Map, Exit (navigates to `localhost:8080/exit`).

**Live polling:** The browser polls `?json` every 5 seconds when viewing the default event
and when the tracker list has not been locally edited. Only tracker positions and timestamps
are updated; all other config sections are left unchanged by polls.

#### Admin Interface

Open `https://marsaprs.org/admin/`. Requires a user account with `admin.view` or
`admin.edit` (see [Authentication](#authentication)). Session lasts 24 hours.

**Editable sections:** Trackers (callsign, ID, name), Tracker Style (icon, color), Map
Default View, Default Section Visibility, Backgrounds, Courses, Aid Stations, iGates, Legend, Mobile Tracking (enabled, PIN, root callsign; messaging password; participant list with rename/block/remove), Beacon Settings (upload interval and distance threshold per activity mode).

**Messages modal:** The 💬 Messages button opens a modal showing the full message log for the current event (read from `messages.db`), with **Export** and **Delete All Messages** buttons. Delete All Messages wipes the event's messages and photos from the SQLite store after a two-step confirmation and is gated by the `messages.manage` permission.

**Tracker Δ column:** Each tracker row shows a read-only Δ field — the minimum interval
between consecutive beacons in the last 10 received, displayed as M:SS. Shows `—` until at
least 2 beacons have been received. The column refreshes automatically every 30 seconds;
the last refresh time is shown next to the Trackers section heading. Deleting and re-adding
a tracker resets its history — avoid this while trying to verify the beacon interval.

**Header/footer buttons:**

| Button | What it does |
|--------|-------------|
| **Update** | Saves local-only sections to `localStorage`; returns to map. Does not write to server. |
| **Save** | Writes config to the current event's `event.yaml`. Does not change the active event symlink. |
| **Save as Default Event** | Writes config + re-points `config.yaml` symlink. All users pick up changes within ~5 s. |
| **Exit** | Leaves without saving. |
| **Sign Out** | Destroys the session. |

**Local vs server sections:**

| Classification | Sections |
|----------------|---------|
| Local (Update saves) | Event Name, Legend, Tracker Style, Map Default View |
| Server-only | Trackers, Backgrounds, Courses, Aid Stations, iGates |

**Event management:** Save as Default Event creates or overwrites a named event directory and re-points
the symlink. Load shows all events newest-first; Load & Activate switches the active event.
Delete removes the event directory permanently. Events can be locked (`locked: true` in
`event.yaml`); locked events reject saves without the admin password.

**Import / Export:**

| Section | Export | Import |
|---------|--------|--------|
| Event | YAML | YAML |
| Trackers | YAML, CSV | YAML, CSV |
| Aid Stations | YAML, CSV, GPX | YAML, CSV, GPX, KML, GeoJSON, JSON |
| iGates | YAML, CSV, GPX | YAML, CSV, GPX, KML, GeoJSON, JSON |
| Courses | YAML, CSV | YAML, CSV, GPX, KML, GeoJSON, JSON |

**Smart lat/lon paste:** Pasting a combined coordinate string (e.g., `37.7749, -122.4194`
from Google Maps) into either lat or lon field auto-splits and populates both fields.

**Tile Provider Browser:** The ⊞ button opens a grid of all free Leaflet tile providers
with thumbnail previews. Clicking a provider inserts it into Backgrounds with all fields
pre-filled.

**Manage Location Files:** Sortable table of course files across all events. Upload
(drag-and-drop or file picker), rename, delete, or add to the current event.

#### API Endpoints — `index.php`

All endpoints return `Cache-Control: no-store` unless noted. ETag/304 caching is used
for `?json`, `?config`, and `?history`.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `?json` | GET | Current tracker state from `trackers.json`, plus iGate and aid station last-beacon timestamps |
| `?config` | GET | Parsed active configuration (all sections) |
| `?history` | GET | Beacon history — 5 most-recent positions per tracker |
| `?clientstatus` | GET | Apache worker stats and connected client IPs |
| `?mobile=join` | POST | Create a mobile participant session; assigns callsign and returns token. Clears any prior history for that callsign. |
| `?mobile=update` | POST | Heartbeat + inject APRS-IS position packet; refreshes session timestamp. Accepts `ack_ids` to clear delivered messages. Returns pending messages in response body. Returns 404 if the session was removed or blocked. |
| `?mobile=leave` | POST | End the participant session and remove from `mobile_trackers.json`. |
| `?mobile=message` | POST | Send a text message from a mobile participant to web operators. Body: `{token, text, to?}` — `to` is an operator name for per-operator addressing, omitted (legacy) → `web` (all operators). Stamps the sender's latest beacon onto the log entry as `lat`/`lon`/`pos_ts`. Returns `{ok, id}`. |
| `?mobile=poll` | POST | Lightweight message poll without updating position. Body: `{token, ack_ids?}`. Returns `{messages: [...]}` or 404. |
| `?mobile=msghistory` | POST | Fetch full message history for the session callsign. Body: `{token}`. Returns `{messages: [...]}` oldest-first. |
| `?mobile=auth` | POST | Validate the event password. Body: `{password}`. Returns 200 if accepted. |
| `?messaging=subscribe` | POST | Subscribe as a web operator. Body: `{name, password}`. Returns `{token, name}` or `{error}`. |
| `?messaging=send` | POST | Send a message from a web operator to a mobile tracker. Body: `{web_token, to, text}`. `to` is a callsign or `"*"` for broadcast, taken from the compose **To:** dropdown. |
| `?messaging=poll` | GET | Poll for new messages directed to web operators. Params: `web_token`, `since_id`. Returns `{messages, last_id}`. |
| `?messaging=rename` | POST | Update operator's display name. Body: `{web_token, name}`. Updates `web_sessions.json` and returns `{ok}`. |
| `?messaging=history` | GET/POST | Full message log for the current event from `messages.db` (unfiltered — includes other operators' traffic). Param/body: `web_token`. Returns `{messages, last_id, can_delete_all}`. |
| `?messaging=delete_all` | POST | Erase the message log. Body: `{web_token}`. Requires a valid subscription **and** a signed-in account with `messages.manage`; otherwise 403. Preserves the ID counter and clears all `pending_msgs`. Returns `{ok, deleted}`. |
| `?autologin` | GET | Set a PHP session for the current event (no password required); if `operator` param is set, also sets the operator name in session. Redirects to clean URL. |

#### API Endpoints — `admin/index.php`

All endpoints require an active session (HTTP 401 if not authenticated).

| Endpoint | Method | Description |
|----------|--------|-------------|
| `?logout` | GET | Destroy session and redirect to login |
| `?load` | GET | Active event config as JSON |
| `?save` | POST | Write full config to `config.yaml` |
| `?versions` | GET | All saved events, newest-first |
| `?saveonly` | POST | Write config to named event; do not change symlink |
| `?saveversion` | POST | Write config + re-point `config.yaml` symlink |
| `?loadversion&name=<N>` | GET | Named event's config as JSON |
| `?deleteversion` | POST | Delete event directory |
| `?locationfiles` | GET | Course files in the active event directory |
| `?alllocationfiles` | GET | Course files across all events |
| `?upload` | POST | Upload a course file to the active event directory |
| `?renamefile` | POST | Rename a course file |
| `?deletefile` | POST | Delete a course file |
| `?setactiveevent` | POST | Re-point `config.yaml` without saving content |
| `?bglib` | GET | Deduplicated background tile layers from all events |
| `?beacondeltas` | GET | Min inter-beacon gap per tracker callsign (from `tracker_history.yaml`) |
| `?togglelock` | POST | Lock or unlock an event (requires password) |
| `?messages` | GET | Return the full message log for the current event from `messages.db` as JSON. |
| `?delete_messages` | GET | Delete all messages (and photos) for the current event from `messages.db`. Gated by `messages.manage`; used by the **Delete All Messages** button in the admin Messages modal. |

#### File Permissions

Web root `/var/www/html/` is owned `pi:www-data` with mode `775`.
The daemon runs as `www-data` with `UMask=0002`; files it creates get mode `664`.

| File | Needs write access | Created by |
|------|-------------------|------------|
| `trackers.json` | www-data (daemon) | daemon on first run |
| `igates.json` | www-data (daemon) | daemon on first iGate activity |
| `aidstations.json` | www-data (daemon) | daemon on first aid station activity |
| `config.yaml` | www-data (admin page) | manual / admin Save as Default Event |
| `events/<E>/event.yaml` | www-data (admin page) | admin Save as Default Event |
| `events/<E>/tracker_history.yaml` | www-data (daemon) | daemon on first beacon |
| Course files (`*.gpx`, etc.) | www-data (upload) | admin upload or rsync |

To repair ownership after manual file operations:

```bash
sudo chown -R pi:www-data /var/www/html/events/
sudo chmod -R g+w /var/www/html/events/
```

---

### Display Pi

#### Services

All services start automatically at boot via systemd.

| Service | Port | Script | Purpose |
|---------|------|--------|---------|
| `x11vnc` | 5901 | — | VNC remote desktop (password: guacamole) |
| `kill-server` | 8080 (localhost) | `kill-server.py` | `GET /exit` kills Chromium |
| `stats-listener` | 1235 UDP | `StatsRequestListener.php` | Responds to health-check polls |
| `aprs-monitor` | — | `aprs-monitor.sh` | Polls `marsaprs.org` every 30 s; relaunches Chromium if unreachable |
| `wifi-watchdog` | — | `wifi-watchdog.sh` | Checks WiFi every 30 s; calls `wifi-restored.sh` on reconnect |
| `lightdm` | — | — | X11 display manager; required for Chromium and VNC |

#### Crontab

```
# MARS APRS Display Pi
# Check every 5 minutes whether to enable/disable NetBird
*/5 * * * * /home/pi/check-netbird.sh >> /tmp/checknetbird.log 2>&1
# Enable NetBird after any reboot
@reboot /home/pi/netbird-up.sh
# Nightly auto-update at 4:01am
1 4 * * * /home/pi/auto-update.sh >> /home/pi/update.log 2>&1
# Nightly reboot at 4:10am (after updates)
10 4 * * * sudo reboot
```

#### Kiosk Autostart

LXDE autostart (`/home/pi/.config/lxsession/rpd-x/autostart`) calls `~/start-kiosk.sh`
rather than launching Chromium directly:

```
@xrdb -merge ~/.Xresources
@/home/pi/start-kiosk.sh
```

`~/start-kiosk.sh` handles URL construction (auto-login), cursor size, and Chromium launch:

```bash
#!/bin/bash
URL="https://marsaprs.org/"
if [ -f ~/autologin.txt ]; then
    mapfile -t lines < ~/autologin.txt
    operator="${lines[0]:-}"
    URL="https://marsaprs.org/?autologin"
    if [ -n "$operator" ]; then
        enc_op=$(python3 -c "import sys,urllib.parse; print(urllib.parse.quote(sys.argv[1]))" "$operator")
        URL="${URL}&operator=${enc_op}"
    fi
fi
export XCURSOR_SIZE=48
rm -f ~/.config/chromium/Singleton*
exec chromium --password-store=basic --kiosk --noerrdialogs --disable-infobars \
    --disable-dev-shm-usage --incognito \
    --disable-features=BlockInsecurePrivateNetworkRequests \
    --user-data-dir=/tmp/chromium \
    "$URL"
```

#### Useful Commands

```bash
# Check all services
sudo systemctl status x11vnc kill-server stats-listener aprs-monitor wifi-watchdog

# Restart Chromium in kiosk mode
sudo -u pi DISPLAY=:0 XAUTHORITY=/home/pi/.Xauthority \
  chromium --password-store=basic --kiosk --noerrdialogs \
  --disable-infobars --disable-dev-shm-usage --incognito \
  --disable-features=BlockInsecurePrivateNetworkRequests \
  --user-data-dir=/tmp/chromium \
  'https://marsaprs.org/' &
```

**`add-wifi.php`** — Adds a WiFi network to `wifi.conf` on the local device. Accepts name, SSID, and password as arguments (or prompts interactively). Hashes the password via `wpa_passphrase` and appends the entry to `wifi.conf`, then calls `update-wifi.php` to apply it immediately. Available on all Pi types.

```bash
php /home/pi/add-wifi.php "Home Network" "MySSID" "MyPassword"
```

**Note:** The addition applies immediately but will be overwritten the next time `auto-update.sh` runs (nightly). Use the WiFi Manager web UI (`/wifi/`) to make permanent changes.

---

### iGate

#### Crontab

```
# Health watchdog: SDR + IP every minute, internet every 5 min
* * * * *    /home/pi/igate-watchdog.sh
# Nightly update at 4:01am
1 4 * * *    /home/pi/auto-update.sh
# Nightly reboot at 4:10am (after updates)
10 4 * * *   sudo reboot
# Check every 5 minutes whether to enable/disable NetBird
*/5 * * * *  /home/pi/check-netbird.sh
# Enable NetBird after any reboot
@reboot      /home/pi/netbird-up.sh
```

#### direwolf.conf Key Directives

| Directive | Example | Purpose |
|-----------|---------|---------|
| `MYCALL` | `MYCALL MARS-5` | Station callsign used on RF and APRS-IS |
| `IGLOGIN` | `IGLOGIN K6DRK 12345` | APRS-IS login callsign and passcode |
| `PBEACON` | `PBEACON lat=37.96 long=-122.54 comment="iGate 5.0 by K6DRK, Richmond CA"` | Position beacon |
| `FILTER` | `FILTER 0 IG t/p & ! d/*` | APRS-IS server-side packet filter |

Run `/home/pi/configure.sh` to set all of these interactively.

#### File Permissions

All iGate scripts live in `/home/pi/`, owned by `pi`. The direwolf log directory
(`/var/log/direwolf/`) is owned by `pi` for the watchdog to write to.

---

### Pi-Tools

These utility scripts live in their device source directories on the Mac
(`display/home/`, `display/systemd/`, `server/bin/`, `server/home/`,
`server/systemd/`) and are installed to `/home/pi/`, `/usr/local/bin/`, and
`/etc/systemd/system/` by `install.sh` as part of the standard device setup.

#### All Pis

**`add-wifi.php`** — Adds a WiFi network to the local `wifi.conf`. Takes name, SSID, and password as arguments or prompts interactively. Hashes the password with `wpa_passphrase`, appends the entry, and calls `update-wifi.php` to apply it. The change is immediate but will be overwritten by the next nightly `auto-update.sh` — use the WiFi Manager web UI for permanent additions.

**`wifi-watchdog.sh` / `wifi-watchdog.service`** — Generic WiFi watchdog. Checks
connectivity every 30 seconds; calls `/usr/local/bin/wifi-lost.sh` on loss and
`/usr/local/bin/wifi-restored.sh` on restore. `install.sh` installs and enables
both. To install manually after the script has been placed in `/home/pi/`:

```bash
sudo cp ~/wifi-watchdog.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/wifi-watchdog.sh
sudo systemctl daemon-reload && sudo systemctl enable --now wifi-watchdog
```

#### Display Pi

**`set-hostname.sh`** — Changes the hostname everywhere it needs to be set so the change
persists across reboots (updates `/boot/firmware/user-data`, `/etc/hostname`, `/etc/hosts`,
kernel UTS name, and `hostnamectl`).

```bash
sudo ~/set-hostname.sh <new-hostname>
exec $SHELL   # refresh the shell prompt
```

**`kill-server.py`** — Minimal Python HTTP server on `localhost:8080`. `GET /` returns
a "Connecting…" page that auto-redirects when `marsaprs.org` is reachable. `GET /exit`
kills Chromium. Used by the map's kiosk Exit button (via `window.location.href` to bypass
Chrome's Private Network Access preflight).

**`aprs-monitor.sh`** — Polls `marsaprs.org` every 30 seconds. If unreachable, kills
Chromium and relaunches it pointing to `localhost:8080`. When the site is reachable again,
the connecting page auto-redirects.

**`wifi-restored.sh`** (display Pi hook) — Called by `wifi-watchdog.sh` when WiFi is
restored. Kills Chromium and relaunches it via `localhost:8080`.

**`start-aprs.desktop`** — Desktop launcher; kills any running Chromium and relaunches
in kiosk mode. `install.sh` places it in `/home/pi/`; copy it to the Desktop manually:

```bash
cp ~/start-aprs.desktop ~/Desktop/start-aprs.desktop
chmod 644 ~/Desktop/start-aprs.desktop   # must NOT be executable on Trixie
```

On Pi OS Trixie, also suppress the "Executable Script" dialog system-wide:

```bash
mkdir -p ~/.config/libfm
printf '[config]\nquick_exec=1\n' > ~/.config/libfm/libfm.conf
```

#### Server Pi

**`wifi-restored.sh`** — Called by `wifi-watchdog.sh` when WiFi is restored on
aprs-pi. Restarts the APRS daemon to reconnect to APRS-IS on the new interface.

**`aprs-daemon.service` / `aprs-daemon.sh`** — Systemd service unit and wrapper script
for the APRS daemon. See [Running the Daemon](#running-the-daemon).

---

## Testing

A regression test suite covers the logic that is testable without live hardware: APRS parsers,
YAML config read/write, tracker history file I/O, and the pure JavaScript utility functions.
Hardware-dependent code (radio, SDR, GPIO, TFT display) is not tested here.

### Prerequisites

Install PHPUnit (PHP tests) and Jest (JavaScript tests) once:

```bash
cd ~/marsaprs/map
composer install                # installs PHPUnit into map/vendor/

cd tests/js
npm install                     # installs Jest into map/tests/js/node_modules/
```

Both tools are listed as dev dependencies (`composer.json` / `map/tests/js/package.json`) and
are excluded from the production server — `tests/` and `vendor/` are in the exclude list of
**both** `sync-to-pi.sh` and `server/deploy.sh`. Keep the two lists in step: `deploy.sh` lacked
these two exclusions until 2026-08-08, so a full deploy pushed ~49 MB of PHPUnit and test
fixtures into the live web root, where individual files were served (directory listings were
not — Apache returns 403). There are no production Composer dependencies at all; `composer.json`
is `require-dev` only, so nothing at runtime needs `vendor/`.

### Running the PHP tests

From `map/`:

```bash
./vendor/bin/phpunit -c tests/phpunit.xml
```

Expected output: `OK (90 tests, 159 assertions)`

| Test class | What it covers |
|------------|----------------|
| `AprsParserTest` | `parseAprsPosition()` — uncompressed, Base91 compressed, and Mic-E position formats; malformed packets |
| `ConfigParseTest` | `parseConfigYaml()` and `yamlScalar()` — all config sections, edge cases, missing files |
| `YamlLibTest` | `yaml_lib.php` — `yamlVal`, `yamlStr`, `loadDevices`/`saveDevices` roundtrip |
| `TrackerHistoryTest` | `readTrackerHistoryFile()` / `writeTrackerHistoryFile()` — roundtrip, the `breadcrumb_count`-driven retention cap (including counts above 10), missing file |
| `AdminConfigTest` | `buildConfigYaml()` → `parseConfigYaml()` roundtrip — all sections, special chars, booleans |

Test files live in `map/tests/php/`. Fixtures (sample YAML files) are in `map/tests/fixtures/`.

### Running the Python tests

Standalone scripts — no framework, run them directly, exit 0 is a pass:

```bash
python3 igate/tests/test_isproxy.py          # direwolf gets an upstream on reconnect
python3 transcriber/tests/test_transcriber.py
```

The Transcriber suite runs the whole channel pipeline without an SDR and without
whisper: `rtl_fm` and `sox` are skipped via `--spool-only`, and whisper is a stub script
whose output the test chooses, so the filters can be driven deliberately. The two that
earn their keep are the ones guarding what reaches the log — squelch noise transcribed
as "Thank you." must produce nothing, and a genuine transmission through the same path
must produce exactly one entry. Without that second test the first passes for free, and
it did: an early version discarded everything because the clip looked like one sox was
still writing, and every filter assertion passed without a filter ever running.

`transcriber/deploy.sh` runs this suite and refuses to deploy if it fails.

### Running the JavaScript tests

From `map/tests/js/`:

```bash
npx jest
```

Expected output: `Tests: 59 passed, 59 total`

| Function tested | Cases |
|-----------------|-------|
| `esc(s)` | All five HTML special characters; null/undefined coerced to `''` |
| `relativeTime(ts)` | "just now", seconds, minutes, hours; boundary conditions at 10 s, 60 s, 3600 s |
| `haversineDistance()` | Same point = 0; SF→LA; SF→NYC; pole-to-pole |
| `bearingTo()` | Due N/S/E/W; result always in [0°, 360°) |
| `compassDir()` | All 16 cardinal/intercardinal points; 359° wraps to N |
| `formatAprsPath()` | Q-code expansion; digipeated hops; unknown hops; HTML escaping; empty/null path |

The JS functions (`esc`, `relativeTime`, `haversineDistance`, `bearingTo`, `compassDir`,
`Q_LABELS`, `formatAprsPath`) live in `map/utils.js` and are loaded by `map/index.php`
via `<script src="utils.js">`. The `module.exports` shim at the bottom of `utils.js`
allows Jest to import them in Node without a browser.

### Scope

| In scope | Out of scope |
|----------|-------------|
| APRS position parsers | APRS-IS socket connection |
| YAML config read/write | iGate (RTL-SDR, direwolf, GPIO) |
| Tracker history file I/O | Display Pi (TFT screen) |
| Admin config serialiser/parser roundtrip | Leaflet map UI interactions |
| Pure JS utility functions | — |

# Troubleshooting

---

## Is my iGate working?

**Quick check — external:**
Go to [aprs.fi](https://aprs.fi) and search for your iGate callsign (e.g. `K6DRK-6`).
If it shows a packet received in the last few minutes, your iGate is reaching the APRS
network.

**Quick check — on the map:**
Open [marsaprs.org](https://marsaprs.org). If the iGates section is visible, your iGate
callsign should appear in the list. Its presence confirms the event configuration recognizes
your station; aprs.fi confirms it is actually passing packets.

**If your iGate is not appearing on aprs.fi:**

1. **Check the local status page.** From a computer on the same network as the iGate,
   open a browser to `http://<igate-ip>/system.php` (replace `<igate-ip>` with the Pi's
   local IP address). This page shows whether Direwolf is running and whether it is
   connected to the APRS-IS server (`noam.aprs2.net`).

2. **Check internet connectivity.** The iGate must have internet access to forward packets
   to APRS-IS. If the local status page doesn't load, or shows no internet, check the
   network connection and WiFi configuration.

3. **Verify the SDR is recognized.** The RTL-SDR dongle must be plugged in. If Direwolf
   is stopped and the SDR is missing, the watchdog will restart Direwolf when the dongle
   is re-inserted. Check `http://<igate-ip>/system.php` for SDR status.

4. **Check the APRS-IS connection.** The status page shows the connected server. If it
   reads "not connected," Direwolf may need to be restarted: `sudo systemctl restart
   direwolf.service`.

---

## Are you receiving my tracker?

**Quick check:**
Open [marsaprs.org](https://marsaprs.org) and find your callsign (or assigned ID/name) in
the **Trackers** list. The color of the dot tells you how recently a beacon was received:

| Color | Meaning |
|-------|---------|
| Green | Heard within the last 2 minutes |
| Blue  | Heard within the last 5 minutes |
| Red   | Not heard for more than 5 minutes |

The elapsed time next to your name updates every 5 seconds.

**See where your packets were received:**
Click your name in the tracker list (or tap it on mobile) to show your breadcrumb history —
the last 10 beacon positions as dots on the map. Hover (desktop) or tap (mobile) any dot to
see the timestamp and the **APRS path**: which iGates and digipeaters your packet traveled
through before reaching the internet.

**If your tracker is not appearing or is stuck on red:**

1. **Confirm your transmitter is on 144.390 MHz** (the standard APRS frequency in North
   America).
2. **Confirm your callsign matches** what is configured for you in the event. The
   callsign your radio transmits must match what is entered in the map's tracker list.
   Contact the event administrator if you are unsure.
3. **Check aprs.fi.** Go to [aprs.fi](https://aprs.fi) and search your callsign. If
   packets appear there but not on the event map, the callsign in the event configuration
   may not match. If they don't appear on aprs.fi either, the issue is RF or
   transmitter-side.
4. **Verify at least one iGate is operational.** If no iGates are receiving, no trackers
   will be heard. Check the iGate status (see above) or contact the net control operator.

---

## How do I add a new WiFi hotspot in the field?

The iGates and display Pis download a shared WiFi credential list from the server every
night at 4:00 AM. There are two ways to add a new hotspot:

### Permanent — via the web admin (preferred)

1. Log in to the **WiFi Manager** at [marsaprs.org/wifi/](https://marsaprs.org/wifi/)
   (requires the admin password).
2. Enter the network **Name** (a label for your records), **SSID**, and **password**.
3. Click **Add**. The new network is saved immediately and will be distributed to all
   field devices at the next nightly update (4:00 AM).

Networks at the top of the list have higher priority. Drag to reorder if needed.

### Temporary — directly on a field device

Use this when you are on-site and need a device to connect to a new hotspot right away,
before the next nightly sync.

1. Connect your laptop to the device via its **recovery hotspot** (the personal-hotspot
   entry at the top of the WiFi list that the device falls back to).
2. SSH to the device: `ssh pi@<device-ip>`
3. Run: `/home/pi/add-wifi.php`
4. Enter the network name, SSID, and password when prompted.
5. The device will connect immediately.
6. **Turn off the recovery hotspot** once the device has connected to the new network.

> **Important:** Hotspots added this way are **temporary**. At 4:00 AM the device will
> re-download the master WiFi list from the server and the locally added entry will be
> removed unless you also added it via the web admin (step above).

---

## How do I determine how frequently a tracker is beaconing?

There is no single "beacon interval" setting visible to the map — the interval is
determined by the tracker hardware/software itself. But you can infer it from packet
history.

### Quickest method — Admin page

Log in to the **Map Admin** page at [marsaprs.org/admin/](https://marsaprs.org/admin/)
and look at the **Trackers** table. The **Δ** column shows the shortest observed interval
between any two consecutive beacons in the last 10 received, in M:SS format. For example,
**4:05** means the tracker was heard twice within 4 minutes and 5 seconds — a reliable
indicator of the configured beacon rate. The table auto-refreshes every 30 seconds.

> **Δ shows `—`** if fewer than two beacons have been received yet.

### On the map — breadcrumb timestamps

Click the tracker name in the sidebar (or tap on mobile) to show breadcrumb history. Hover
(desktop) or tap (mobile) any dot to see the exact time that beacon was received. Compare
the times on two adjacent dots to estimate the interval.

### External reference — aprs.fi

Go to [aprs.fi](https://aprs.fi) and search the tracker's callsign. The packet log shows
timestamps for every packet that reached APRS-IS, giving a complete view of the beacon
cadence independent of the event map.

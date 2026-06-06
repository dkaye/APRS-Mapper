# Troubleshooting

## Is my iGate working?

**Quick check — external:**
Go to [aprs.fi](https://aprs.fi) and search for the iGate's callsign (e.g. `K6DRK-6`).
If it shows a packet received in the last few minutes, the iGate is reaching the APRS
network.

**If the iGate is not appearing on aprs.fi:**

1. **Check the NetBird Status page.** On the MARS APRS map [marsaprs.org](https://marsaprs.org)
   click on Admin→NetBird. If the iGate is listed, you should see *Online* next to the iGate's ID.
   If the iGate isn't on the list, you can add it via Admin→NetBird→Admin using the iGate's NetBird address.
   If the iGate is listed, but shows as *Disabled*, go to Admin→NetBird→Admin and turn it on. Nothing will happen until
   35 seconds after the next five-minute interval (i.e., 5, 10, 15, etc., minutes past the hour).
   If the iGate can't be reached, its status will change to *Offline*.
   
2. **Check internet connectivity.** The iGate must have internet access to forward packets
   to APRS-IS. If the Admin→NetBird page doesn't show *Online* after the above steps, have the
   operator check whether the cellular hotspot (if one is being used) is on and has a cellular connection.
   
3. **Reboot the iGate.** Regardless of whether the iGate is connected voa a cellular hotspot or a local WiFi access point,
   have the operator reboot the iGate and check for an IP address and SSID on the diagnostic display, which appears 1-2 minutes
   after a reboot and stays on for about 15 seconds. 
   If the iGate bypasses the diagnostic screen, the iGate has no internet connection.

4. **Verify the SDR is recognized.** If the SDR isn't connected or has malfunctioned, the iGate will display *No SDR Found*
   and will wait until one is found or reboot. Tell the opertor to check the USB connection to the SDR.


## Are you receiving my tracker?

**Quick check:**
Open [marsaprs.org](https://marsaprs.org) and find the callsign (or assigned ID/name) in
the *Trackers* list. 

**See where your packets were received:**
Click the tracker's name in the tracker list (or tap it on mobile) to show its breadcrumb history —
the last 10 beacon positions as dots on the map. Hover (desktop) or tap (mobile) any dot to
see the timestamp and the *APRS path*: which iGates and digipeaters the beacons traveled
through before reaching the internet.

**If the tracker is not appearing or is stuck on red** (>5 minutes since last beacon received):

1. **Is it in a good location?** Assuming the tracker has been properly configured, it's likely not close enough to an iGate or digipeater to be received.

2. **Check aprs.fi.** Go to [aprs.fi](https://aprs.fi) and search for the tracker's callsign. If
   packets appear there but not on the event map, the callsign in the event configuration
   may not match. If they don't appear on aprs.fi either, the issue is RF or
   transmitter-side.

3. **Check the tracker's configuration:** 

    * Confirm the transmitter is on 144.390 MHz.

    * Check that the tracker's GPS is on.

    * Check that the tracker is receiving latitude/longitude from the GPS.
   
    * Check that the tracker's APRS function is using GPS for location.
	
    * Check the tracker's beaconing period. Ideally, use ***smart beaconing*** which will adjust the beaconing period based on 
  travel speed, etc. If that's not available, use 30 seconds for a vehicle, 60 seconds for a bicycle or 90 seconds for a
  hiker/runner. 60 seconds is a good compromise for all uses.
  
    * Check the tracker's callsign such as MARS-3.


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

**N.B.**: Be careful when reordering the list. We purposely keep cellphone hotspots at or near the top
so they can be used as *recovery hotspots*, superseding other devices farther down in the list.

### Temporary — directly on a field device

Use this when you are on-site and need a device to connect to a new hotspot before the next nightly sync.

1. Connect your laptop to the device via a *recovery hotspot* (such as the personal-hotspot
   entry at the top of the WiFi list) or any other hotspot on the list.
2. Get the device's IP address. (The NetControl and BigTV devices show their IP address when they reboot.)
3. SSH to the device: `ssh pi@<device-ip>`
4. Run: `/home/pi/add-wifi.php`
5. Enter the network name, SSID, and password when prompted.
6. The device will connect immediately.
7. **Turn off the recovery hotspot** once the device has connected to the new network.

**N.B.**: Hotspots added this way are *temporary*. At 4:00 AM the device will
re-download the master WiFi list from the server and the locally added entry will be
removed unless you also added it via the web admin (step above).

## How do I determine how frequently a tracker is beaconing?

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

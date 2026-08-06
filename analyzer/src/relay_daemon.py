"""
relay_daemon.py — MARS APRS Analyzer

Records each tracked tracker's beacon AS HEARD BY EACH of our iGates, so the
analyzer can show which trackers and which iGates are actually working (and how
well their coverage overlaps).

It streams the aggregation relay's UNDEDUPED capture (`capture.jsonl` on the VPS
relay) over SSH and, for the active event, inserts a beacon per (tracker, iGate)
with `receiver` = the gating iGate (e.g. MARS-13). This complements
`aprs_daemon.py`, which records the public APRS-IS feed (deduped — only ever one
receiver per beacon). Both write to the same `aprs.db`; the display de-dups per
(callsign, receiver, position), so a stationary tracker heard repeatedly by one
gate collapses to a single tracker→gate link, while different gates and different
positions are kept.

Runs as the `analyzer-relay-daemon` service; started/stopped with the recorder
from the Analyzer UI. See README "iGate Aggregation Relay".
"""
import os
import sys
import json
import time
import subprocess

import yaml
import aprslib
from aprs_db import aprs_db_connection

CONFIG_YAML = '/var/www/html/admin/config.yaml'
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# SSH into the VPS relay under a forced-command key that only streams the capture
# (`tail -n0 -F capture.jsonl`). The key is www-data-readable and set up out of band.
RELAY_KEY    = os.environ.get('RELAY_CAP_KEY',  os.path.join(os.path.dirname(BASE_DIR), 'relaycap_key'))
RELAY_TARGET = os.environ.get('RELAY_CAP_HOST', 'relaypull@64.23.166.192')
SSH_CMD = [
    'ssh', '-i', RELAY_KEY, '-n',
    '-o', 'BatchMode=yes',
    '-o', 'UserKnownHostsFile=/dev/null',
    '-o', 'StrictHostKeyChecking=no',
    '-o', 'LogLevel=ERROR',
    '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3',
    '-o', 'ConnectTimeout=10',
    RELAY_TARGET,
]

POS_ROUND = 5            # ~1 m; matches the display's position_tolerance (1e-5)
WATCH_REFRESH_SECS = 60  # re-read the tracker list this often (picks up new trackers)

database = None
event_id = None
event_name = None
start_time = 0
end_time = 0
watch_list = {}
seen = set()             # (callsign, receiver, lat, lng) — insert-time de-dup
beacons_logged = 0


def read_event_data():
    """Load the active event's id, time window and tracker watch-list. Returns True if found."""
    global event_id, event_name, start_time, end_time, watch_list
    requested = sys.argv[1] if len(sys.argv) > 1 else None
    if not requested:
        try:
            with open(CONFIG_YAML) as f:
                requested = (yaml.safe_load(f) or {}).get('event', '')
        except Exception as e:
            print(f"Could not read event from config.yaml: {e}")
    if requested:
        event = database.get_event(requested)
        if event:
            event_id   = event["id"]
            event_name = event["name"]
            start_time = event["start_time"]
            end_time   = event["end_time"]
            watch_list = database.get_trackers_for_event(event_name)
            print(f"Found event {event_id}: {event_name}. Tracking {len(watch_list)} callsigns.")
            return True
    print("No matching event found — relay recorder idle.")
    return False


def handle_line(line):
    """Parse one capture.jsonl record and, if it is a tracked position in the event
    window heard by an iGate, record it (de-duped per tracker/gate/position)."""
    global beacons_logged
    try:
        rec = json.loads(line)
    except Exception:
        return
    t     = rec.get('t')
    igate = rec.get('igate')     # the gating iGate = q-construct entry station (our receiver)
    raw   = rec.get('raw')
    if not (t and igate and raw):
        return
    if t < start_time or t > end_time:
        return
    src = rec.get('src') or (raw.split('>', 1)[0] if '>' in raw else '')
    if src not in watch_list:
        return
    try:
        pkt = aprslib.parse(raw)
    except Exception:
        return                    # unparseable / non-position (telemetry, status, …)
    lat = pkt.get('latitude')
    lng = pkt.get('longitude')
    if lat is None or lng is None or (lat == 0.0 and lng == 0.0):
        return
    key = (src, igate, round(lat, POS_ROUND), round(lng, POS_ROUND))
    if key in seen:
        return                    # same tracker, same gate, same spot — one link is enough
    seen.add(key)
    path = pkt.get('path', []) or []
    path_string = ", ".join(path)
    database.add_beacon_to_event(event_id, src, lat, lng, t, igate, path_string)
    beacons_logged += 1
    print(f"{src} -> {igate}  {lat},{lng}  @ {time.ctime(t)}")


def stream_capture():
    """Stream the relay capture until the event ends, reconnecting if the SSH pipe drops."""
    global watch_list
    last_refresh = 0.0
    while time.time() < end_time:
        print(f"Connecting to relay capture ({RELAY_TARGET})…")
        proc = subprocess.Popen(SSH_CMD, stdout=subprocess.PIPE, text=True, bufsize=1)
        try:
            for line in proc.stdout:
                now = time.time()
                if now > end_time:
                    break
                if now - last_refresh > WATCH_REFRESH_SECS:
                    new_watch = database.get_trackers_for_event(event_name)
                    if new_watch:
                        added = set(new_watch) - set(watch_list)
                        if added:
                            print(f"New trackers: {', '.join(sorted(added))}")
                        watch_list = new_watch
                    last_refresh = now
                handle_line(line)
        except Exception as e:
            print(f"Capture stream error: {e}")
        finally:
            try:
                proc.terminate()
            except Exception:
                pass
        if time.time() < end_time:
            time.sleep(5)         # brief backoff before reconnecting
    print(f"Event finished. Logged {beacons_logged} relay beacons.")


if __name__ == '__main__':
    database = aprs_db_connection()
    if not read_event_data():
        sys.exit(0)
    if time.time() > end_time:
        print("Event has already finished.")
        sys.exit(0)
    if time.time() < start_time:
        print(f"Waiting {int(start_time - time.time())}s for event start…")
        time.sleep(start_time - time.time())
    stream_capture()

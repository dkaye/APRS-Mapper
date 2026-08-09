"""
aprs_daemon.py — MARS APRS Analyzer

APRS-IS listener that connects to noam.aprs2.net:14580 and records incoming
beacons for all tracked callsigns into the local SQLite database (aprs.db).
Run as the analyzer-daemon systemd service; started and stopped from the Analyzer UI.

Beacons are always recorded against the current event named in config.yaml, whose
row is created on demand if this is its first recording — creating an event in the
Admin UI is all the setup required. Recording runs for as long as the service does;
the Record control in the Analyzer UI is the only start/stop.
"""
import os
import time
import json
import yaml
import aprslib
import sqlite3
import sys
import socket
from aprs_db import aprs_db_connection

CONFIG_YAML = '/var/www/html/admin/config.yaml'

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

DATA_FILE     = os.path.join(BASE_DIR, 'latest_packets.json')
HEARTBEAT_FILE = os.path.join(BASE_DIR, 'heartbeat.txt')
IGATES_FILE   = os.path.join(BASE_DIR, 'igates.json')

latest_packets = {}
watch_list = {}   # loaded from the event's roster in read_event_data()

database = None
event_name = None
event_id = None
beacons_logged = 0

def process_incoming_packet(packet):
    """Callback for incoming APRS packets."""
    global latest_packets
    global beacons_logged

    sender = packet.get('from')

    if sender in watch_list and 'latitude' in packet:
        path = packet.get('path', [])
        received_by = "Unknown"
        path_string = ""

        if path:
            last_hop = path[-1]
            received_by = last_hop.split(',')[-1].strip() if ',' in last_hop else last_hop
            for token in path:
                path_string = path_string + token + ", "
            if len(path_string) > 0:
                path_string = path_string[:-2]  # remove the trailing ", "

        latitude = packet.get('latitude')
        longitude = packet.get('longitude')
        time_sent = packet.get('timestamp') if 'timestamp' in packet else time.time()
        print(f"{sender}: position {latitude}, {longitude}  receiver: {received_by} @ {time.ctime(time_sent)}")
        database.add_beacon_to_event(event_id,sender,latitude,longitude,time_sent,received_by,path_string)
        beacons_logged = beacons_logged + 1

def connect_to_database():
    global database
    database = aprs_db_connection()


def read_event_data():
    """Resolve the current event, creating its database row if this is its first
    recording. Returns True if we have an event to record into."""
    global event_name, event_id, watch_list
    requested = sys.argv[1] if len(sys.argv) > 1 else None
    if not requested:
        try:
            with open(CONFIG_YAML) as f:
                cfg = yaml.safe_load(f)
            requested = cfg.get('event', '')
        except Exception as e:
            print(f"Could not read event name from config.yaml: {e}")
    if not requested:
        print("No current event in config.yaml — nothing to record into.")
        return False
    existed = database.get_event(requested) is not None
    event = database.ensure_event(requested)
    if not event:
        print(f"Could not create event {requested}.")
        return False
    event_name = event["name"]
    event_id   = event["id"]
    print(f"{'Found' if existed else 'Created'} event {event_id}: {event_name}.")
    watch_list = database.get_trackers_for_event(event_name)
    for cs, name in watch_list.items():
        display_name = name.split('/')[1] if '/' in name else name
        if display_name:
            database.save_tracker_name(cs, display_name)
    return True


def main_loop():
    global watch_list
    while True:
        # Refresh tracker list every reconnect cycle to pick up newly added trackers
        new_watch = database.get_trackers_for_event(event_name)
        if set(new_watch.keys()) != set(watch_list.keys()):
            added = set(new_watch.keys()) - set(watch_list.keys())
            if added:
                print(f"New trackers: {', '.join(added)}")
            watch_list = new_watch
        for cs, name in watch_list.items():
            display_name = name.split('/')[1] if '/' in name else name
            if display_name:
                database.save_tracker_name(cs, display_name)
        if not watch_list:
            # A brand-new event with no trackers configured yet. An empty filter is
            # malformed, so wait for the roster rather than connecting with one.
            print("No trackers configured for this event yet. Waiting 30s...")
            time.sleep(30)
            continue
        calls = "/".join(watch_list.keys())
        print(f"Connecting to APRS-IS... filter: p/{calls} t/p")
        ais = aprslib.IS("KN6RST", passwd="-1", host="noam.aprs2.net", port=14580)
        try:
            ais.set_filter(f"p/{calls} t/p")
            ais.connect()
            ais.sock.settimeout(60)  # reconnect every 60s to pick up new trackers
            print("Connected.")
            ais.consumer(process_incoming_packet, blocking=True)
        except socket.timeout:
            pass  # normal 60s periodic reconnect for tracker-list refresh
        except KeyboardInterrupt:
            print(f"Stopped. Logged {beacons_logged} beacons.")
            return
        except Exception as e:
            print(f"APRS-IS error: {e}. Reconnecting in 30s...")
            time.sleep(30)
        finally:
            try:
                ais.close()
            except Exception:
                pass

if __name__ == '__main__':
    connect_to_database()
    if not read_event_data():
        sys.exit(0)
    print("Tracking: " + str(watch_list))
    main_loop()
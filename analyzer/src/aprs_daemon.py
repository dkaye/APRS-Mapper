"""
aprs_daemon.py — MARS APRS Analyzer

APRS-IS listener that connects to noam.aprs2.net:14580 and records incoming
beacons for all tracked callsigns into the local SQLite database (aprs.db).
Run as the analyzer-daemon systemd service; started and stopped from the Analyzer UI.
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

DEFAULT_WATCH_LIST = {
    "KN6RST-7": "Tracker 1",
    "NZ6J-2": "Rob",
    "KO6JDL-10": "Charles"
}

DATA_FILE     = os.path.join(BASE_DIR, 'latest_packets.json')
HEARTBEAT_FILE = os.path.join(BASE_DIR, 'heartbeat.txt')
IGATES_FILE   = os.path.join(BASE_DIR, 'igates.json')

latest_packets = {}
watch_list = DEFAULT_WATCH_LIST

database = None
start_time = time.time()  # default to starting now
end_time = start_time + 10 * (60) # default to four hours if no event given
event_name = None
event_id = None
beacons_logged = 0

def process_incoming_packet(packet):
    """Callback for incoming APRS packets."""
    global latest_packets
    global beacons_logged

    sender = packet.get('from')


    if time.time() > end_time:
        raise StopIteration
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
    global event_name, event_id, watch_list, start_time, end_time
    requested = sys.argv[1] if len(sys.argv) > 1 else None
    if not requested:
        try:
            with open(CONFIG_YAML) as f:
                cfg = yaml.safe_load(f)
            requested = cfg.get('event', '')
        except Exception as e:
            print(f"Could not read event name from config.yaml: {e}")
    if requested:
        event = database.get_event(requested)
        if event:
            start_time  = event["start_time"]
            end_time    = event["end_time"]
            event_name  = event["name"]
            event_id    = event["id"]
            print(f"Found event {event_id}: {event_name}.")
            watch_list = database.get_trackers_for_event(event_name)
            for cs, name in watch_list.items():
                display_name = name.split('/')[1] if '/' in name else name
                if display_name:
                    database.save_tracker_name(cs, display_name)
            return
    event_name = None
    print("No matching event found.  Will only print beacons")

def wait_for_event_start():
    current_time = time.time()
    if current_time < start_time:
        sleep_time = start_time - current_time
        if sleep_time < 60:
            print( f"Event will start in {sleep_time} seconds" )
        else:
            print( f"Event will start in {sleep_time/60} minutes" )
        time.sleep(start_time - current_time)


def main_loop():
    global watch_list
    if time.time() > end_time:
        print("Event has finished already.")
        return
    while time.time() < end_time:
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
        except StopIteration:
            print(f"Event ended. Logged {beacons_logged} beacons.")
            return
        except KeyboardInterrupt:
            return
        except Exception as e:
            print(f"APRS-IS error: {e}. Reconnecting in 30s...")
            time.sleep(30)
        finally:
            try:
                ais.close()
            except Exception:
                pass
    print(f"Event finished. Logged {beacons_logged} beacons.")

if __name__ == '__main__':
    connect_to_database()
    read_event_data()
    print("Tracking: " + str(watch_list))
    wait_for_event_start()
    main_loop()
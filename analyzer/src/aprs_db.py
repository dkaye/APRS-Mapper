"""
aprs_db.py — MARS APRS Analyzer

SQLite database wrapper for the Analyzer. Manages the events and beacons tables:
creates the schema on first use, inserts incoming beacons, returns ordered and
deduplicated beacon lists for display, and provides recording time-range queries.
"""
import sqlite3
import datetime
import sys
from zoneinfo import ZoneInfo
import time
from collections import defaultdict
import math
import os
import yaml

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_YAML    = '/var/www/html/admin/config.yaml'
TRACKERS_JSON  = '/var/www/html/trackers.json'


class aprs_db_connection:
    time_format = "%m-%d-%y %H:%M"
    timezone = ZoneInfo("America/Los_Angeles")

    def __init__(self, filename=os.path.join(BASE_DIR, 'aprs.db')):
        self.connection = sqlite3.connect(filename)
        self._ensure_tables()

    def _ensure_tables(self):
        with self.connection:
            cursor = self.connection.cursor()
            # create event table
            table_query = """
                CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY,
                    name TEXT UNIQUE,
                    start_time INTEGER,
                    end_time INTEGER
                );
                """
            cursor.execute(table_query)

            # create beacon table
            table_query = """
                CREATE TABLE IF NOT EXISTS beacons (
                id INTEGER PRIMARY KEY,
                callsign TEXT,
                latitude REAL,
                longitude REAL,
                time INTEGER,
                receiver TEXT,
                event_id INTEGER,
                path TEXT,
                FOREIGN KEY (event_id) REFERENCES events(id)
            );
            """
            cursor.execute(table_query)

            cursor.execute("""
                CREATE TABLE IF NOT EXISTS tracker_names (
                    callsign TEXT PRIMARY KEY,
                    name     TEXT NOT NULL
                );
            """)
            try:
                cursor.execute("ALTER TABLE tracker_names ADD COLUMN carrier TEXT")
            except sqlite3.OperationalError:
                pass  # column already exists
            cursor.close()

    def save_tracker_name(self, callsign, name):
        with self.connection:
            self.connection.execute(
                "INSERT INTO tracker_names (callsign, name) VALUES (?, ?)"
                " ON CONFLICT(callsign) DO UPDATE SET name=excluded.name",
                (callsign, name)
            )

    def save_tracker_carrier(self, callsign, carrier):
        with self.connection:
            self.connection.execute(
                "INSERT INTO tracker_names (callsign, name, carrier) VALUES (?, '', ?)"
                " ON CONFLICT(callsign) DO UPDATE SET carrier=excluded.carrier",
                (callsign, carrier)
            )

    def get_all_tracker_names(self):
        cursor = self.connection.cursor()
        cursor.execute("SELECT callsign, name FROM tracker_names")
        return {row[0]: row[1] for row in cursor.fetchall()}

    def get_all_tracker_carriers(self):
        cursor = self.connection.cursor()
        cursor.execute("SELECT callsign, carrier FROM tracker_names WHERE carrier IS NOT NULL AND carrier != ''")
        return {row[0]: row[1] for row in cursor.fetchall()}

    def get_event(self,event_name):
        with self.connection:
            cursor = self.connection.cursor()
            cursor.execute( "SELECT start_time, end_time, name, id FROM events WHERE name = ?", [event_name] )
            event_info = cursor.fetchone()
            if event_info:
                event = {
                    "start_time" : event_info[0],
                    "end_time" : event_info[1],
                    "name" : event_info[2],
                    "id" : event_info[3]
                }
                return event
            cursor.close()
        return None

    def ensure_event(self, event_name):
        """Return the named event, creating its row if it does not exist yet.

        The event roster lives in the Admin UI (events/<name>/event.yaml); nothing
        there writes to aprs.db, so an event that has never been recorded has no row
        here. Rather than leave the recorders with no event to log into — which used
        to write beacons with a NULL event_id, invisible to every query — provision
        the row on first use. start_time records when the event was first seen;
        end_time is unused (recording is controlled by the Record button, not a clock).
        """
        if not event_name:
            return None
        event = self.get_event(event_name)
        if event:
            return event
        with self.connection:
            cursor = self.connection.cursor()
            cursor.execute(
                "INSERT OR IGNORE INTO events (name, start_time, end_time) VALUES (?,?,NULL)",
                [event_name, int(time.time())]
            )
            cursor.close()
        return self.get_event(event_name)

    def get_all_event_names(self):
        event_name_list = []
        with self.connection:
            cursor = self.connection.cursor()
            cursor.execute( "SELECT name FROM events" )
            results = cursor.fetchall()
            for event in results:
                event_name_list.append(event[0])
            cursor.close()
        return event_name_list


    def dump_events(self):
        with self.connection:
            cursor = self.connection.cursor()
            cursor.execute( "SELECT * FROM events" )
            results = cursor.fetchall()
            print(f"Found {len(results)} events\n")
            for event in results:
                start = self.timestamp_to_readable(event[2]) if event[2] else "—"
                end = self.timestamp_to_readable(event[3]) if event[3] else "—"
                print(f"{event[0]} {event[1]} {start} {end}\n")
            cursor.close()


    def set_event_times(self, event_name, start, end ):
        with self.connection:
            cursor = self.connection.cursor()
            query = """
            INSERT INTO events (name, start_time, end_time)
            VALUES(?,?,?)
            ON CONFLICT(name) DO UPDATE SET
                start_time = excluded.start_time,
                end_time = excluded.end_time
            """
            cursor.execute(query,[event_name,start,end])
            cursor.close()

    # format is month-day-year hour:minute
    def set_event_time_strings(self, event_name, start, end):
        start_obj = datetime.datetime.strptime(start, aprs_db_connection.time_format).replace(tzinfo=aprs_db_connection.timezone)
        if not start_obj:
            print("Invalid start time. Format is month-day-year hour:minute")
            return
        end_obj = datetime.datetime.strptime(end, aprs_db_connection.time_format).replace(tzinfo=aprs_db_connection.timezone)
        if not end_obj:
            print("Invalid end time. Format is month-day-year hour:minute")
            return
        self.set_event_times(event_name, start_obj.timestamp(), end_obj.timestamp())

    def timestamp_to_readable(self, timestamp):
        time_obj = datetime.datetime.fromtimestamp(timestamp).astimezone(aprs_db_connection.timezone)
        return time_obj.strftime(aprs_db_connection.time_format)

    # Assumes times are in UTC, as beacons will
    def add_beacon_to_event(self, event_id, callsign, lat, long, time, receiver,path):
        with self.connection:
            cursor = self.connection.cursor()
            query = "INSERT INTO beacons (callsign, latitude, longitude, time, receiver, event_id, path) VALUES(?,?,?,?,?,?,?)"
            cursor.execute(query,[callsign,lat,long,time,receiver,event_id,path])
            cursor.close()

    def beacon_to_readable(self, beacon):
        time = self.timestamp_to_readable(beacon['time'])
        return f"{beacon['callsign']} {beacon['longitude']} {beacon['latitude']} {beacon['receiver']} {time} {beacon['event_id']}"

    def dump_beacons(self,beacon_list):
        print(f"Found {len(beacon_list)} beacons\n")
        for beacon in beacon_list:
            print(self.beacon_to_readable(beacon) + "\n")

    def get_beacons_for_event(self,event):
        event_id = None
        if isinstance(event, str):
            event_data = self.get_event(event)
            if event_data:
                event_id = event_data["id"]
            else:
                print(f"Could not find {event}\n")
        elif isinstance(event, int):
            event_id = event
        if event_id:
            self.connection.row_factory = sqlite3.Row
            cursor = self.connection.cursor()
            cursor.execute( "SELECT * FROM beacons WHERE event_id = ?", [event_id] )
            results = [dict(row) for row in cursor.fetchall()]
            cursor.close()
            self.connection.row_factory = None
            return results
        return None

    def create_sorted_beacon_dictionary(self, beacons):
        grouped_beacons = defaultdict(list)
        for beacon in beacons:
            grouped_beacons[beacon["callsign"]].append(beacon)
        return grouped_beacons


    def get_ordered_deduplicated_beacons(self, event):
        raw_list = self.get_beacons_for_event(event)
        if not raw_list:
            return []
        beacon_dictionary = self.create_sorted_beacon_dictionary(raw_list)
        filtered_data = []
        # ~11 m. Sized to bridge the two recorders' disagreement about the SAME
        # beacon: the public APRS-IS feed carries uncompressed DDMM.mm positions,
        # quantized to 1/100 minute (1.6667e-4 deg), while relay_daemon reads full
        # precision from the relay capture. Rounding to nearest bounds their offset
        # at half a step — 8.333e-5 deg — so this tolerance provably collapses every
        # such pair. Rows from DIFFERENT gates are never merged: `receiver` is part
        # of the comparison below, which is what makes widening this safe.
        position_tolerance = 0.0001
        for callsign, data in beacon_dictionary.items():
            unique_beacons = []

            for item in data:
                # Check if 'item' is a duplicate of anything we've already decided to keep
                is_duplicate = False
                for unique_item in unique_beacons:
                    if item["receiver"] == unique_item["receiver"] and \
                       math.isclose(item["latitude"], unique_item["latitude"], abs_tol=position_tolerance) and \
                       math.isclose(item["longitude"], unique_item["longitude"], abs_tol=position_tolerance):
                        is_duplicate = True
                        break # It's a duplicate, no need to check further

                # If it's not close to any kept item, keep it
                if not is_duplicate and item["latitude"] != 0.0 and item["longitude"] != 0.0:
                    unique_beacons.append(item)
            filtered_data.extend(unique_beacons)
        # Dedup groups beacons by callsign, so filtered_data comes out in
        # callsign blocks. Sort globally by time so the returned list is
        # chronological: the player maps its time-range slider to list
        # indices, so index order MUST equal time order or the range readout
        # reports the last callsign's last beacon (not the newest beacon) as
        # the end. Track drawing no longer relies on callsign adjacency.
        filtered_data.sort(key=lambda b: b["time"])
        return filtered_data

    def get_event_recording_times(self, event_name):
        """Return {first, last, count} Unix timestamps for beacons in this event, or None."""
        event_data = self.get_event(event_name)
        if not event_data:
            return None
        with self.connection:
            cursor = self.connection.cursor()
            cursor.execute(
                "SELECT MIN(time), MAX(time), COUNT(*) FROM beacons WHERE event_id = ?",
                [event_data["id"]]
            )
            row = cursor.fetchone()
            cursor.close()
        if row and row[2] > 0:
            return {'first': row[0], 'last': row[1], 'count': row[2]}
        return None

    def delete_event_beacons(self, event_name):
        """Erase All Data: removes every beacon recorded for this event, and only
        this event. Other events' recordings are untouched."""
        event_data = self.ensure_event(event_name)
        if not event_data:
            return 0
        with self.connection:
            cursor = self.connection.cursor()
            cursor.execute("DELETE FROM beacons WHERE event_id = ?", [event_data["id"]])
            deleted = cursor.rowcount
            cursor.close()
        return deleted

    def get_trackers_for_event(self, event_name):
        tracker_list = {}
        try:
            with open(CONFIG_YAML, 'r') as f:
                config = yaml.safe_load(f)
            for entry in (config.get('trackers') or []):
                cs = entry.get('callsign')
                if cs:
                    tracker_list[cs] = f"{entry.get('id', '')}/{entry.get('name', '')}"
        except Exception as e:
            print(f"Could not read trackers from config.yaml: {e}")
        try:
            with open(TRACKERS_JSON, 'r') as f:
                import json
                mobile = json.load(f)
            for entry in mobile:
                cs = entry.get('callsign')
                if cs and cs not in tracker_list:
                    tracker_list[cs] = entry.get('name', cs)
        except Exception as e:
            print(f"Could not read trackers from trackers.json: {e}")
        return tracker_list


def show_help():
    print("Valid commands are event_time and show_event")

def run_utility_command_args():
    if len(sys.argv) > 1:
        command = sys.argv[1]
        params = sys.argv[2:]
        num_params = len(params)
        match command:
            case 'event_time':
                if num_params == 5:
                    db = aprs_db_connection()
                    start_time_string = f"{params[1]} {params[2]}"
                    end_time_string = f"{params[3]} {params[4]}"
                    db.set_event_time_strings( params[0], start_time_string, end_time_string )
                else:
                    print( "event_time: <event name> <start day> <start time> <end day> <end time>\nUse month-day-year hour:minute format")
            case 'show_event':
                if num_params == 1:
                    db = aprs_db_connection()
                    data = db.get_event( params[0] )
                    if data:
                        start_string = db.timestamp_to_readable(data["start_time"])
                        end_string = db.timestamp_to_readable(data["end_time"])
                        event_name = data["name"]
                        print(f"{event_name} {start_string} to {end_string}")
                    else:
                        print(f"Could not locate event {params[0]}")
                else:
                    print("show_event <event name>")
            case 'dump_events':
                db = aprs_db_connection()
                db.dump_events()
            case 'test_add_beacon':
                db = aprs_db_connection()
                db.add_beacon_to_event(1,"KN6RST-7",100.4,-33.5,time.time(),"KN6RST-10")
            case 'dump_beacons':
                db = aprs_db_connection()
                beacon_id = params[0] if num_params > 0 else 2
                if num_params > 1 and params[1] == "-o":
                    print("Cleaning list...")
                    beacons = db.get_ordered_deduplicated_beacons(beacon_id)
                else:
                    beacons = db.get_beacons_for_event(beacon_id)
                print(str(beacons))
                # db.dump_beacons(beacons)
            case 'dump_trackers':
                if num_params == 1:
                    db = aprs_db_connection()
                    tracker_list = db.get_trackers_for_event( params[0] )
                    print( str(tracker_list) )
            case '_':
                show_help()
    else:
        show_help()


# assorted command-line utilities
if __name__ == '__main__':
    run_utility_command_args()
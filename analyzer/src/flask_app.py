"""
flask_app.py — MARS APRS Analyzer

Flask web application served at /analyzer/ via Apache mod_proxy → gunicorn.
Provides the event map UI, cookie-based authentication (view + admin levels),
daemon start/stop API, data flush API, and live beacon JSON endpoint.
"""
import time
import os
import json
import yaml
import hmac
import hashlib
import subprocess
import xml.etree.ElementTree as ET
from flask import Flask, jsonify, request, redirect, url_for, render_template, make_response
from werkzeug.middleware.proxy_fix import ProxyFix
from aprs_db import aprs_db_connection

BASE_DIR             = os.path.dirname(os.path.abspath(__file__))
DATA_FILE            = os.path.join(BASE_DIR, 'latest_packets.json')
CONFIG_YAML          = '/var/www/html/admin/config.yaml'
TRACKERS_JSON        = '/var/www/html/trackers.json'
MOBILE_TRACKERS_JSON = '/var/www/html/mobile_trackers.json'
WEB_ROOT             = '/var/www/html'
PASSWORD_FILE        = '/var/www/html/admin/password.txt'
COOKIE_KEY           = 'marsaprs_admin_k6drk'
VIEW_COOKIE_KEY      = 'marsaprs_analyzer_view_k6drk'
ADMIN_COOKIE_KEY     = 'marsaprs_analyzer_admin_k6drk'
GPX_NS               = 'http://www.topografix.com/GPX/1/1'


def get_event_password():
    try:
        with open(CONFIG_YAML) as f:
            return yaml.safe_load(f).get('event_password', '') or ''
    except Exception:
        return ''


def check_view_auth():
    ep = get_event_password()
    if not ep:
        return True  # no event password configured → open access
    expected = hmac.new(VIEW_COOKIE_KEY.encode(), ep.encode(), hashlib.sha256).hexdigest()
    return hmac.compare_digest(request.cookies.get('analyzer_view_auth', ''), expected)


def check_admin_auth():
    """Return True if the request carries a valid 24-hour admin auth cookie."""
    try:
        stored = open(PASSWORD_FILE).read().strip()
    except Exception:
        return True
    if not stored:
        return True
    expected = hmac.new(ADMIN_COOKIE_KEY.encode(), stored.encode(), hashlib.sha256).hexdigest()
    return hmac.compare_digest(request.cookies.get('analyzer_admin_auth', ''), expected)


def _stamp_admin_cookie(resp):
    """Attach a 24-hour admin auth cookie to resp."""
    try:
        stored = open(PASSWORD_FILE).read().strip()
    except Exception:
        stored = ''
    token = hmac.new(ADMIN_COOKIE_KEY.encode(), stored.encode(), hashlib.sha256).hexdigest()
    resp.set_cookie('analyzer_admin_auth', token, max_age=86400, httponly=True, samesite='Lax')
    return resp


def parse_gpx(path):
    try:
        tree = ET.parse(path)
        root = tree.getroot()
        ns = {'g': GPX_NS}
        return [[float(p.get('lat')), float(p.get('lon'))]
                for p in root.findall('.//g:trkpt', ns)]
    except Exception as e:
        print(f"GPX parse error {path}: {e}")
        return []


DASH_MAP = {'dashed': '8 6', 'dotted': '2 6', 'dash-dot': '8 6 2 6'}
DEFAULT_COURSE_COLOR = '#2196f3'


def load_courses():
    """Return list of {coords, color, dash} dicts matching the regular map's style."""
    try:
        with open(CONFIG_YAML) as f:
            cfg = yaml.safe_load(f)
        courses = []
        for entry in (cfg.get('courses') or []):
            path = os.path.join(WEB_ROOT, entry.get('file', ''))
            coords = parse_gpx(path)
            if coords:
                courses.append({
                    'coords': coords,
                    'color':  entry.get('color') or DEFAULT_COURSE_COLOR,
                    'dash':   DASH_MAP.get(entry.get('dash', '') or '', None),
                })
        return courses
    except Exception as e:
        print(f"Could not load courses: {e}")
        return []


def load_map_config():
    """Return background tile URL and default map center from config.yaml."""
    try:
        with open(CONFIG_YAML) as f:
            cfg = yaml.safe_load(f)
        bgs    = cfg.get('backgrounds') or []
        bg_url = (cfg.get('background_url')
                  or (bgs[0].get('url', '') if bgs else '')
                  or 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png')
        m      = cfg.get('map') or {}
        return {
            'bg_url': bg_url,
            'lat':    float(m.get('lat', 37.9757)),
            'lon':    float(m.get('lon', -122.612)),
            'zoom':   int(m.get('zoom', 12)),
        }
    except Exception:
        return {'bg_url': 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                'lat': 37.9757, 'lon': -122.612, 'zoom': 12}


def load_config():
    """Read config.yaml + trackers.json + mobile_trackers.json.

    Returns: (event_name, trackers_dict, igates, digipeaters, mobile_callsigns_set, tracker_options)
      tracker_options: list of {callsign, label, is_mobile, mobile_pair}

    Pulldown strategy:
      - trackers.json (ham: True)  → hybrid radio-side entry, paired with its MARSQ counterpart
      - config.yaml trackers       → radio-only entries (if not already in trackers.json as ham)
      - trackers.json (mobile: True, not paired) → standalone mobile entry
      - mobile_trackers.json       → fills in registered-but-not-yet-active mobile trackers
    """
    event_name       = ''
    trackers         = {}   # callsign -> display label (unknown-beacon fallback)
    igates           = {}   # callsign -> {name, lat, lng}
    digipeaters      = {}   # callsign -> {name, lat, lng}
    mobile_callsigns = set()
    tracker_options  = []

    # ── config.yaml: event name + igates ──────────────────────────────────────
    radio_tracker_entries = []  # [{callsign, name, id}] from config.yaml trackers section
    try:
        with open(CONFIG_YAML, 'r') as f:
            config = yaml.safe_load(f)
        event_name = config.get('event', '')
        for entry in (config.get('trackers') or []):
            cs = entry.get('callsign')
            if cs:
                radio_tracker_entries.append({'callsign': cs,
                                              'name': entry.get('name', cs),
                                              'id': entry.get('id', cs)})
        for entry in (config.get('igates') or []):
            cs = entry.get('callsign')
            if not cs:
                continue
            record = {'name': entry.get('name', cs),
                      'lat': float(entry['lat']), 'lng': float(entry['lon'])}
            if entry.get('digipeater'):
                digipeaters[cs] = record
            else:
                igates[cs] = record
        for entry in (config.get('aidstations') or []):
            cs = entry.get('callsign')
            if not cs:
                continue
            igates[cs] = {'name': entry.get('name', cs),
                          'lat': float(entry['lat']), 'lng': float(entry['lon'])}
    except Exception as e:
        print(f"Could not read config.yaml: {e}")

    # ── mobile_trackers.json: ham↔mobile pairing map + registered list ────────
    hybrid_map = {}          # ham_cs → mobile tracker entry
    mobile_reg = {}          # MARSQ-cs → mobile tracker entry (all registered)
    try:
        with open(MOBILE_TRACKERS_JSON, 'r') as f:
            for entry in (json.load(f).get('trackers') or []):
                cs = entry.get('callsign')
                if not cs:
                    continue
                mobile_reg[cs] = entry
                mobile_callsigns.add(cs)
                ham_cs = entry.get('ham_callsign')
                if ham_cs:
                    hybrid_map[ham_cs] = entry
    except Exception as e:
        print(f"Could not read mobile_trackers.json: {e}")

    # ── trackers.json: live tracker list written by aprsDaemon.php ────────────
    tj = {}
    try:
        with open(TRACKERS_JSON, 'r') as f:
            for entry in json.load(f):
                cs = entry.get('callsign')
                if cs:
                    tj[cs] = entry
                    if entry.get('mobile'):
                        mobile_callsigns.add(cs)
    except Exception as e:
        print(f"Could not read trackers.json: {e}")

    # ── Build tracker_options ──────────────────────────────────────────────────
    represented_mobile = set()  # MARSQ callsigns already covered by a hybrid entry

    # 1. trackers.json ham entries → hybrid pair (radio side shown, mobile linked)
    for cs, entry in tj.items():
        if not entry.get('ham'):
            continue
        mob_entry = hybrid_map.get(cs)
        if mob_entry:
            mob_cs = mob_entry.get('callsign')
            name   = entry.get('name', cs)
            mid    = entry.get('id', cs)
            trackers[cs]     = name
            trackers[mob_cs] = name
            tracker_options.append({'callsign': cs, 'label': f"{name} ({mid})",
                                    'name': name,
                                    'is_mobile': False, 'mobile_pair': mob_cs})
            represented_mobile.add(mob_cs)

    # 2. config.yaml radio trackers not already added via trackers.json
    existing_cs = {opt['callsign'] for opt in tracker_options}
    for rt in radio_tracker_entries:
        cs = rt['callsign']
        if cs in existing_cs:
            continue
        trackers[cs] = rt['name']
        if cs in hybrid_map:
            mob_entry = hybrid_map[cs]
            mob_cs    = mob_entry.get('callsign')
            tracker_options.append({'callsign': cs,
                                    'label': f"{rt['name']} ({mob_entry.get('id', mob_cs)})",
                                    'name': rt['name'],
                                    'is_mobile': False, 'mobile_pair': mob_cs})
            represented_mobile.add(mob_cs)
        else:
            label = f"{rt['name']} ({cs})" if rt['name'] != cs else cs
            tracker_options.append({'callsign': cs, 'label': label,
                                    'name': rt['name'],
                                    'is_mobile': False, 'mobile_pair': None})

    # 3. standalone mobile trackers from trackers.json (active, not yet paired)
    for cs, entry in tj.items():
        if not entry.get('mobile') or cs in represented_mobile:
            continue
        name = entry.get('name', cs)
        mid  = entry.get('id', cs)
        trackers[cs] = name
        tracker_options.append({'callsign': cs, 'label': f"{name} ({mid})",
                                'name': name,
                                'is_mobile': True, 'mobile_pair': None})
        represented_mobile.add(cs)

    # 4. registered-but-not-yet-active mobile trackers from mobile_trackers.json
    for cs, entry in mobile_reg.items():
        if cs in represented_mobile:
            continue
        name = entry.get('name', cs)
        mid  = entry.get('id', cs)
        trackers[cs] = name
        tracker_options.append({'callsign': cs, 'label': f"{name} ({mid})",
                                'name': name,
                                'is_mobile': True, 'mobile_pair': None})

    carriers_map = {cs: entry['device_info']['carrier']
                    for cs, entry in mobile_reg.items()
                    if entry.get('device_info', {}).get('carrier')}
    return event_name, trackers, igates, digipeaters, mobile_callsigns, tracker_options, carriers_map


db = aprs_db_connection(os.path.join(BASE_DIR, 'aprs.db'))
app = Flask(__name__)
app.config["TEMPLATES_AUTO_RELOAD"] = True
app.wsgi_app = ProxyFix(app.wsgi_app, x_prefix=1)


@app.before_request
def require_auth():
    if request.path.endswith('/login'):
        return None
    if not check_view_auth():
        return redirect(url_for('login'))


@app.route('/login', methods=['GET', 'POST'])
def login():
    error = None
    if request.method == 'POST':
        password = request.form.get('password', '')
        ep = get_event_password()
        if not ep or password == ep:
            token = hmac.new(VIEW_COOKIE_KEY.encode(), ep.encode(), hashlib.sha256).hexdigest() if ep else 'open'
            resp = make_response(redirect(url_for('hello_world')))
            resp.set_cookie('analyzer_view_auth', token, max_age=86400, httponly=True, samesite='Lax')
            return resp
        error = 'Incorrect password.'
    return render_template('login.html', error=error)


@app.route('/api/event_beacons/<event_name>')
def event_beacon_json(event_name):
    beacon_list = db.get_ordered_deduplicated_beacons(event_name)
    return jsonify(beacon_list)


@app.route('/api/check_admin')
def check_admin_api():
    return jsonify({'ok': check_admin_auth()})


@app.route('/api/daemon', methods=['GET', 'POST'])
def daemon_api():
    stamp_cookie = False
    if request.method == 'POST':
        if not check_admin_auth():
            data      = request.get_json(silent=True) or {}
            submitted = data.get('password', '')
            try:
                stored = open(PASSWORD_FILE).read().strip()
            except Exception:
                stored = ''
            if stored and submitted != stored:
                return jsonify({'error': 'Incorrect password'}), 403
        else:
            data = request.get_json(silent=True) or {}
        stamp_cookie = True
        action = data.get('action', '')
        if action in ('start', 'stop'):
            subprocess.run(['sudo', 'systemctl', action, 'analyzer-daemon'],
                           capture_output=True)
    result = subprocess.run(['sudo', 'systemctl', 'is-active', 'analyzer-daemon'],
                            capture_output=True, text=True)
    running = result.stdout.strip() == 'active'
    try:
        with open(CONFIG_YAML) as f:
            current_event = yaml.safe_load(f).get('event', '')
    except Exception:
        current_event = ''
    rec = db.get_event_recording_times(current_event) if current_event else None
    resp = make_response(jsonify({
        'running':         running,
        'recording_start': rec['first'] if rec else None,
        'recording_last':  rec['last']  if rec else None,
    }))
    if stamp_cookie:
        _stamp_admin_cookie(resp)
    return resp


@app.route('/api/flush', methods=['POST'])
def flush_api():
    if not check_admin_auth():
        data      = request.get_json(silent=True) or {}
        submitted = data.get('password', '')
        try:
            stored = open(PASSWORD_FILE).read().strip()
        except Exception:
            stored = ''
        if stored and submitted != stored:
            return jsonify({'error': 'Incorrect password'}), 403

    yaml_event, _, _, _, _, _, _ = load_config()
    if not yaml_event:
        return jsonify({'error': 'No current event'}), 400
    deleted = db.delete_event_beacons(yaml_event)
    resp = make_response(jsonify({'deleted': deleted}))
    _stamp_admin_cookie(resp)
    return resp


@app.route('/event/<event_name>')
def show_event_map(event_name):
    yaml_event, yaml_trackers, event_igates, event_digipeaters, mobile_callsigns, tracker_options, carriers_map = load_config()

    # Always redirect to current yaml event
    if yaml_event and event_name != yaml_event:
        return redirect(url_for('show_event_map', event_name=yaml_event))

    # Background map + map center
    map_cfg = load_map_config()

    # Courses from config.yaml GPX files
    course_data = json.dumps(load_courses())

    # Beacons
    beacon_list = db.get_ordered_deduplicated_beacons(event_name)
    if beacon_list:
        for beacon in beacon_list:
            cs = beacon['callsign']
            if cs not in yaml_trackers:
                yaml_trackers[cs] = cs
            if cs.startswith('MARSQ-'):
                mobile_callsigns.add(cs)
            if beacon['receiver'] in event_igates:
                igate = event_igates[beacon['receiver']]
                beacon['rx_lat'] = igate['lat']
                beacon['rx_lng'] = igate['lng']
            if not beacon.get('path'):
                beacon['path'] = ''
        beacons_json = json.dumps(beacon_list)
    else:
        beacons_json = '[]'

    # Event times
    start_time = time.time()
    end_time   = start_time + 3600
    event_data = db.get_event(event_name)
    if event_data:
        start_time = event_data['start_time']
        end_time   = event_data['end_time']

    # Recording start/end times from first and last stored beacon
    rec = db.get_event_recording_times(event_name)

    tracker_options.sort(key=lambda t: t['label'].lower())
    event_igates      = dict(sorted(event_igates.items(),      key=lambda x: x[1]['name'].lower()))
    event_digipeaters = dict(sorted(event_digipeaters.items(), key=lambda x: x[1]['name'].lower()))

    return render_template(
        'event_map.html',
        event_name=event_name,
        beacon_list=beacons_json,
        igates=event_igates,
        digipeaters=event_digipeaters,
        trackers=tracker_options,
        mobile_callsigns_json=json.dumps(list(mobile_callsigns)),
        carriers_json=json.dumps(carriers_map),
        start_time=start_time,
        end_time=end_time,
        course_data=course_data,
        bg_url=map_cfg['bg_url'],
        map_lat=map_cfg['lat'],
        map_lon=map_cfg['lon'],
        map_zoom=map_cfg['zoom'],
        recording_start=rec['first'] if rec else None,
        recording_last=rec['last']  if rec else None,
    )


@app.route('/')
def hello_world():
    yaml_event, _, _, _, _, _, _ = load_config()
    all_events = db.get_all_event_names()
    first = yaml_event or (all_events[0] if all_events else None)
    if first:
        return redirect(url_for('show_event_map', event_name=first))
    return '<h2>No events found.</h2><p>Use aprs_db.py to create an event.</p>', 200

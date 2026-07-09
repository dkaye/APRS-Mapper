#!/usr/bin/env python3
"""
APRS Map load tester.

Simulates N mobile trackers joining and beaconing at realistic intervals.
Each tracker runs in its own thread. Reports latency percentiles and
error rates every REPORT_INTERVAL seconds.

Usage:
    python3 loadtest.py [--url URL] [--pin PIN] [--clients N]
                        [--interval SECONDS] [--duration SECONDS]
                        [--mode walk_run|cycle|drive|stationary]

Examples:
    python3 loadtest.py --clients 20 --duration 120
    python3 loadtest.py --url https://marsaprs.org --pin 1234 --clients 50
"""

import argparse
import json
import math
import random
import signal
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request

# ── Config defaults ────────────────────────────────────────────────────────────

DEFAULT_URL      = 'https://marsaprs.org'
DEFAULT_PIN      = '1234'
DEFAULT_CLIENTS  = 20
DEFAULT_INTERVAL = 15       # seconds between beacons per client
DEFAULT_DURATION = 120      # seconds to run before stopping (0 = run until Ctrl-C)
DEFAULT_MODE     = 'drive'

# Lat/lon bounding box for random starting positions (Marin County, CA)
LAT_MIN, LAT_MAX = 37.80, 37.95
LON_MIN, LON_MAX = -122.65, -122.45

REPORT_INTERVAL = 10        # seconds between stats printouts
JOIN_STAGGER    = 0.3       # seconds between each client joining (avoids thundering herd)

# ── Shared state ──────────────────────────────────────────────────────────────

lock         = threading.Lock()
results      = []           # list of (elapsed_ms, endpoint, ok) tuples
errors       = []           # list of (timestamp, endpoint, message) tuples
active_count = 0
stop_event   = threading.Event()

# ── HTTP helpers ──────────────────────────────────────────────────────────────

UA = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Mobile Safari/537.36'

def post(url, payload, timeout=10):
    data = json.dumps(payload).encode()
    req  = urllib.request.Request(url, data=data,
                                  headers={'Content-Type': 'application/json',
                                           'User-Agent': UA})
    t0 = time.monotonic()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body   = resp.read()
            elapsed = (time.monotonic() - t0) * 1000
            return elapsed, json.loads(body), None
    except urllib.error.HTTPError as e:
        elapsed = (time.monotonic() - t0) * 1000
        try:
            body = json.loads(e.read())
        except Exception:
            body = {}
        return elapsed, body, f'HTTP {e.code}'
    except Exception as exc:
        elapsed = (time.monotonic() - t0) * 1000
        return elapsed, {}, str(exc)

def get(url, timeout=10):
    t0 = time.monotonic()
    req = urllib.request.Request(url, headers={'User-Agent': UA})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            resp.read()
            return (time.monotonic() - t0) * 1000, None
    except Exception as exc:
        return (time.monotonic() - t0) * 1000, str(exc)

# ── Tracker simulation ────────────────────────────────────────────────────────

def random_walk(lat, lon):
    """Nudge position slightly to simulate movement."""
    return (
        lat + random.uniform(-0.0005, 0.0005),
        lon + random.uniform(-0.0005, 0.0005),
    )

def tracker_thread(base_url, pin, name, interval, mode):
    global active_count

    join_url   = f'{base_url}/index.php?mobile=join'
    update_url = f'{base_url}/index.php?mobile=update'
    leave_url  = f'{base_url}/index.php?mobile=leave'

    lat = random.uniform(LAT_MIN, LAT_MAX)
    lon = random.uniform(LON_MIN, LON_MAX)

    # Join without device_id so the leave call removes the entry entirely
    # (entries with a device_id are only soft-deleted and linger in the admin UI)
    elapsed, body, err = post(join_url, {
        'name': name,
        'pin':  pin,
        'sharing_mode': mode,
    })

    with lock:
        results.append((elapsed, 'join', err is None))
        if err:
            errors.append((time.time(), 'join', f'{name}: {err} — {body}'))
            return
        active_count += 1

    token = body.get('token')
    if not token:
        with lock:
            errors.append((time.time(), 'join', f'{name}: no token in response'))
            active_count -= 1
        return

    # Beacon loop
    next_beacon = time.monotonic()
    while not stop_event.is_set():
        now = time.monotonic()
        if now < next_beacon:
            stop_event.wait(timeout=next_beacon - now)
            continue

        lat, lon = random_walk(lat, lon)
        elapsed, body, err = post(update_url, {
            'token': token,
            'lat':   round(lat, 6),
            'lon':   round(lon, 6),
        })

        with lock:
            results.append((elapsed, 'update', err is None))
            if err:
                errors.append((time.time(), 'update', f'{name}: {err} — {body}'))

        next_beacon = time.monotonic() + interval

    # Leave
    post(leave_url, {'token': token}, timeout=5)
    with lock:
        active_count -= 1

# ── Poll simulation (one extra thread mimics map watchers) ───────────────────

def poller_thread(base_url, n_pollers):
    """Simulates n_pollers web clients each polling ?json every 5 seconds."""
    json_url = f'{base_url}/index.php?json'
    while not stop_event.is_set():
        for _ in range(n_pollers):
            elapsed, err = get(json_url)
            with lock:
                results.append((elapsed, 'json', err is None))
                if err:
                    errors.append((time.time(), 'json', err))
        stop_event.wait(timeout=5)

# ── Stats reporter ────────────────────────────────────────────────────────────

def percentile(data, p):
    if not data:
        return 0
    s = sorted(data)
    k = (len(s) - 1) * p / 100
    f, c = math.floor(k), math.ceil(k)
    return s[f] if f == c else s[f] * (c - k) + s[c] * (k - f)

def report(start_time, final=False):
    with lock:
        snap    = list(results)
        err_snap = list(errors)

    elapsed_sec = time.monotonic() - start_time
    by_ep = {}
    for ms, ep, ok in snap:
        by_ep.setdefault(ep, {'ok': 0, 'err': 0, 'ms': []})
        if ok:
            by_ep[ep]['ok'] += 1
            by_ep[ep]['ms'].append(ms)
        else:
            by_ep[ep]['err'] += 1

    print(f'\n── {elapsed_sec:.0f}s elapsed  |  active trackers: {active_count} ──')
    print(f'{"Endpoint":<10} {"Reqs":>6} {"Errors":>7} {"p50ms":>8} {"p95ms":>8} {"p99ms":>8}')
    print('-' * 55)
    for ep in ('join', 'update', 'json'):
        d = by_ep.get(ep)
        if not d:
            continue
        total = d['ok'] + d['err']
        ms    = d['ms']
        print(f'{ep:<10} {total:>6} {d["err"]:>7} '
              f'{percentile(ms, 50):>7.0f}  {percentile(ms, 95):>7.0f}  {percentile(ms, 99):>7.0f}')

    shown = err_snap if final else [e for e in err_snap if time.time() - e[0] < REPORT_INTERVAL * 2]
    if shown:
        label = 'All errors' if final else 'Recent errors'
        print(f'\n{label} ({len(shown)} total):')
        for _, ep, msg in shown[-10:]:
            print(f'  [{ep}] {msg}')

# ── Plain-English summary ─────────────────────────────────────────────────────

def summarize(args, start_time):
    with lock:
        snap     = list(results)
        err_snap = list(errors)

    by_ep = {}
    for ms, ep, ok in snap:
        by_ep.setdefault(ep, {'ok': 0, 'err': 0, 'ms': []})
        if ok:
            by_ep[ep]['ok'] += 1
            by_ep[ep]['ms'].append(ms)
        else:
            by_ep[ep]['err'] += 1

    total_reqs  = sum(d['ok'] + d['err'] for d in by_ep.values())
    total_errs  = sum(d['err']            for d in by_ep.values())
    error_rate  = total_errs / total_reqs * 100 if total_reqs else 0

    upd         = by_ep.get('update', {})
    upd_ms      = upd.get('ms', [])
    upd_errs    = upd.get('err', 0)
    upd_p50     = percentile(upd_ms, 50)
    upd_p99     = percentile(upd_ms, 99)

    json_d      = by_ep.get('json', {})
    json_ms     = json_d.get('ms', [])
    json_p50    = percentile(json_ms, 50)
    json_p99    = percentile(json_ms, 99)

    join_d      = by_ep.get('join', {})
    join_errs   = join_d.get('err', 0)

    duration    = time.monotonic() - start_time
    beacons_per_sec = len(upd_ms) / duration if duration else 0

    print('── Summary ──────────────────────────────────────────────────────────')
    print()

    # Overall health
    if error_rate == 0:
        print(f'✓  No errors. All {total_reqs} requests succeeded.')
    elif error_rate < 1:
        print(f'△  {total_errs} errors out of {total_reqs} requests ({error_rate:.1f}%) — mostly healthy.')
    elif error_rate < 5:
        print(f'⚠  {total_errs} errors out of {total_reqs} requests ({error_rate:.1f}%) — starting to struggle.')
    else:
        print(f'✗  {total_errs} errors out of {total_reqs} requests ({error_rate:.1f}%) — server is overwhelmed.')

    if join_errs:
        print(f'   {join_errs} tracker(s) failed to join — those clients sent no beacons.')

    print()

    # Beacon (update) performance
    if upd_ms:
        if upd_p50 < 200:
            beacon_feel = 'fast'
        elif upd_p50 < 500:
            beacon_feel = 'acceptable'
        elif upd_p50 < 1000:
            beacon_feel = 'slow'
        else:
            beacon_feel = 'very slow'

        print(f'Beacon uploads ({args.clients} trackers every {args.interval}s):')
        print(f'  Typical response: {upd_p50:.0f}ms — {beacon_feel}.')
        if upd_p99 > upd_p50 * 2:
            print(f'  Worst 1%: {upd_p99:.0f}ms — significant spikes, likely file-lock contention.')
        else:
            print(f'  Worst 1%: {upd_p99:.0f}ms — consistent, no significant spikes.')
        print(f'  Throughput: {beacons_per_sec:.1f} beacons/second sustained.')
        if upd_errs:
            print(f'  {upd_errs} beacon(s) failed — trackers\' positions were not recorded.')

    print()

    # Map poll performance
    if json_ms:
        if json_p50 < 200:
            poll_feel = 'fast — map updates feel live'
        elif json_p50 < 500:
            poll_feel = 'acceptable — map updates may feel slightly delayed'
        elif json_p50 < 1000:
            poll_feel = 'slow — map watchers will notice lag'
        else:
            poll_feel = 'very slow — map will feel unresponsive'

        print(f'Map polling ({args.pollers} watchers every 5s):')
        print(f'  Typical response: {json_p50:.0f}ms — {poll_feel}.')
        if json_p99 > json_p50 * 2:
            print(f'  Worst 1%: {json_p99:.0f}ms — occasional long pauses for watchers.')
        else:
            print(f'  Worst 1%: {json_p99:.0f}ms — consistent.')

    print()

    # Overall verdict
    print('Verdict:')
    if error_rate == 0 and upd_p99 < 500 and json_p99 < 500:
        print(f'  The server handled {args.clients} simultaneous trackers + {args.pollers} map')
        print(f'  watchers comfortably. Try increasing --clients to find the limit.')
    elif error_rate == 0 and upd_p99 < 2000:
        print(f'  The server handled {args.clients} trackers but response times are elevated.')
        print(f'  This is likely near the practical limit for smooth operation.')
    else:
        print(f'  The server struggled at {args.clients} trackers. Reduce --clients until')
        print(f'  errors drop to zero to find the stable operating limit.')
    print()


# ── Main ──────────────────────────────────────────────────────────────────────

class _Parser(argparse.ArgumentParser):
    """Print full help (not just usage) on any argument error."""
    def error(self, message):
        self.print_help(sys.stderr)
        sys.stderr.write(f'\nerror: {message}\n')
        sys.exit(2)


def main():
    parser = _Parser(
        description='APRS Map load tester — simulates N mobile trackers joining '
                    'and beaconing at realistic intervals, plus M web clients '
                    'polling the map. Reports latency percentiles and error rates '
                    'every 10 seconds and prints a plain-English verdict at the end.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog='''\
arguments:
  --url URL         Base URL of the APRS Map server.
                    Default: %(url)s

  --pin PIN         Event PIN required to join as a mobile tracker.
                    Default: %(pin)s

  --clients N       Number of simulated mobile trackers to run concurrently.
                    Each tracker joins, then beacons its position every
                    --interval seconds until the test ends.
                    Default: %(clients)s

  --interval SECS   How often each tracker sends a position update (beacon).
                    Lower values create more server load.
                    Default: %(interval)ss

  --pollers N       Number of simulated web clients polling ?json every 5 seconds.
                    Defaults to the same value as --clients if not specified.

  --duration SECS   How long to run the test. Use 0 to run until Ctrl-C.
                    Default: %(duration)ss

  --mode MODE       Sharing mode reported by each tracker. Affects the
                    sharing_mode field sent on join. One of:
                      walk_run    pedestrian pace
                      cycle       cycling pace
                      drive       vehicle pace  (default)
                      stationary  not moving

examples:
  python3 loadtest.py
      Run with all defaults: %(clients)s trackers, %(interval)ss interval, %(duration)ss duration.

  python3 loadtest.py --clients 50 --duration 300
      Simulate 50 trackers for 5 minutes against the default server.

  python3 loadtest.py --url https://marsaprs.org --pin 9999 --clients 100 --interval 10
      Hammer the production server with 100 trackers beaconing every 10 seconds.

  python3 loadtest.py --clients 30 --pollers 10 --duration 0
      30 trackers + 10 map watchers; run until Ctrl-C.
''' % {
            'url':      DEFAULT_URL,
            'pin':      DEFAULT_PIN,
            'clients':  DEFAULT_CLIENTS,
            'interval': DEFAULT_INTERVAL,
            'duration': DEFAULT_DURATION,
        },
    )
    parser.add_argument('--url',      default=DEFAULT_URL,      help=argparse.SUPPRESS)
    parser.add_argument('--pin',      default=DEFAULT_PIN,      help=argparse.SUPPRESS)
    parser.add_argument('--clients',  type=int,   default=DEFAULT_CLIENTS,  help=argparse.SUPPRESS)
    parser.add_argument('--interval', type=float, default=DEFAULT_INTERVAL, help=argparse.SUPPRESS)
    parser.add_argument('--pollers',  type=int,   default=None,             help=argparse.SUPPRESS)
    parser.add_argument('--duration', type=float, default=DEFAULT_DURATION, help=argparse.SUPPRESS)
    parser.add_argument('--mode',     default=DEFAULT_MODE,     help=argparse.SUPPRESS,
                        choices=['walk_run', 'cycle', 'drive', 'stationary'])
    args = parser.parse_args()
    if args.pollers is None:
        args.pollers = args.clients

    print(f'APRS Map load test')
    print(f'  URL:      {args.url}')
    print(f'  Clients:  {args.clients} trackers + {args.pollers} map pollers')
    print(f'  Interval: {args.interval}s  |  Mode: {args.mode}')
    print(f'  Duration: {args.duration}s' if args.duration else '  Duration: until Ctrl-C')
    print()

    signal.signal(signal.SIGINT, lambda *_: (stop_event.set(), print('\nStopping...')))

    start = time.monotonic()

    # Launch poller thread
    t = threading.Thread(target=poller_thread,
                         args=(args.url, args.pollers), daemon=True)
    t.start()

    # Stagger-launch tracker threads
    threads = []
    for i in range(args.clients):
        name = f'LT{i+1:02d}'
        t = threading.Thread(target=tracker_thread,
                             args=(args.url, args.pin, name,
                                   args.interval, args.mode),
                             daemon=True)
        t.start()
        threads.append(t)
        if i < args.clients - 1:
            time.sleep(JOIN_STAGGER)

    print(f'All {args.clients} clients joined. Running...')

    # Periodic stats
    next_report = start + REPORT_INTERVAL
    while not stop_event.is_set():
        now = time.monotonic()
        if args.duration and now - start >= args.duration:
            stop_event.set()
            break
        if now >= next_report:
            report(start)
            next_report += REPORT_INTERVAL
        stop_event.wait(timeout=1)

    for t in threads:
        t.join(timeout=15)

    print('\n── Final results ──')
    report(start, final=True)
    print()
    summarize(args, start)

if __name__ == '__main__':
    main()

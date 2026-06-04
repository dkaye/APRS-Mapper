#!/usr/bin/env python3
"""
netbird-poller.py — MARS APRS Server

Polls each enabled NetBird device via UDP (StatsRequestListener port 1235)
and writes live status to /var/www/html/netbird/stats.json for api.php.

Control files (in the netbird web dir):
  poll_force   — trigger an immediate full poll on next tick
  poll_single  — contains one IP to poll immediately, then delete
  config.json  — contains repeat_seconds (polling interval)

Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
©2025 Doug Kaye, K6DRK <doug@rds.com>
"""

import socket
import json
import time
import os
import re
import sys

NETBIRD_DIR = '/var/www/html/netbird'
STATS_FILE  = os.path.join(NETBIRD_DIR, 'stats.json')
YAML_FILE   = os.path.join(NETBIRD_DIR, 'addresses.yaml')
CONFIG_FILE = os.path.join(NETBIRD_DIR, 'config.json')
POLL_FORCE  = os.path.join(NETBIRD_DIR, 'poll_force')
POLL_SINGLE = os.path.join(NETBIRD_DIR, 'poll_single')

UDP_PORT     = 1235
UDP_TIMEOUT  = 3.0
DEFAULT_SECS = 60


def log(msg):
    print(f'{time.strftime("%Y-%m-%d %H:%M:%S")} {msg}', flush=True)


def load_yaml():
    devices = []
    if not os.path.exists(YAML_FILE):
        return devices
    with open(YAML_FILE) as f:
        raw = f.read()
    for block in re.split(r'(?=^- )', raw, flags=re.MULTILINE):
        if not block.strip():
            continue
        d = {'name': '', 'host': '', 'ip': '', 'group': '', 'enabled': True}
        for line in block.splitlines():
            m = re.match(r'[\s\-]*(\w+):\s*(.*)', line)
            if m:
                k = m.group(1)
                v = m.group(2).strip().strip('"\'')
                if k == 'enabled':
                    d['enabled'] = v.lower() in ('true', '1')
                elif k in d:
                    d[k] = v
        if d['ip']:
            devices.append(d)
    return devices


def repeat_seconds():
    try:
        with open(CONFIG_FILE) as f:
            return max(10, int(json.load(f).get('repeat_seconds', DEFAULT_SECS)))
    except Exception:
        return DEFAULT_SECS


def read_stats():
    try:
        with open(STATS_FILE) as f:
            return json.load(f)
    except Exception:
        return {'last_send_ts': None, 'devices': []}


def write_stats(stats):
    tmp = STATS_FILE + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(stats, f)
    os.replace(tmp, STATS_FILE)
    try:
        os.chmod(STATS_FILE, 0o664)
    except Exception:
        pass


def broadcast_poll(ips):
    """Send to all IPs at once from port 1235, collect responses until timeout.
    iGate stats-listener always replies to port 1235 on the caller."""
    responses = {}
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        sock.bind(('', UDP_PORT))
        sock.settimeout(0.1)
        for ip in ips:
            try:
                sock.sendto(b'long', (ip, UDP_PORT))
            except Exception:
                pass
        deadline = time.time() + UDP_TIMEOUT
        while time.time() < deadline:
            try:
                data, (src_ip, _) = sock.recvfrom(1024)
                if src_ip in ips:
                    responses[src_ip] = data.decode('utf-8', errors='replace').strip()
            except socket.timeout:
                pass
            except BlockingIOError:
                pass
    except Exception as e:
        log(f'poll socket error: {e}')
    finally:
        sock.close()
    return responses


def poll_all(devices, stats):
    now     = int(time.time())
    enabled = [d for d in devices if d['enabled']]
    existing = {sd['ip']: sd for sd in stats.get('devices', [])}

    ips       = [d['ip'] for d in enabled]
    responses = broadcast_poll(ips)

    out = []
    for d in enabled:
        ip    = d['ip']
        entry = existing.get(ip, {'ip': ip})
        entry['ip']           = ip
        entry['last_request'] = now
        if ip in responses:
            entry['online']        = True
            entry['miss_count']    = 0
            entry['last_response'] = now
            entry['response_data'] = responses[ip]
        else:
            misses = entry.get('miss_count', 0) + 1
            entry['miss_count'] = misses
            if misses >= 3:
                entry['online'] = False
        out.append(entry)

    stats['last_send_ts'] = now
    stats['devices']      = out
    return stats


def poll_one(ip, stats):
    now      = int(time.time())
    existing = {sd['ip']: sd for sd in stats.get('devices', [])}
    responses = broadcast_poll([ip])
    entry    = existing.get(ip, {'ip': ip})
    entry['ip']           = ip
    entry['last_request'] = now
    if ip in responses:
        entry['online']        = True
        entry['miss_count']    = 0
        entry['last_response'] = now
        entry['response_data'] = responses[ip]
    else:
        misses = entry.get('miss_count', 0) + 1
        entry['miss_count'] = misses
        if misses >= 3:
            entry['online'] = False
    existing[ip]     = entry
    stats['devices'] = list(existing.values())
    return stats


def main():
    log('netbird-poller starting')
    devices = load_yaml()
    stats   = poll_all(devices, read_stats())
    write_stats(stats)
    online = sum(1 for d in stats['devices'] if d.get('online'))
    log(f'Initial poll: {online}/{len(stats["devices"])} online')
    last_poll = time.time()

    while True:
        time.sleep(1)
        now = time.time()

        if os.path.exists(POLL_SINGLE):
            try:
                ip = open(POLL_SINGLE).read().strip()
                os.remove(POLL_SINGLE)
                if ip:
                    stats = poll_one(ip, read_stats())
                    write_stats(stats)
                    log(f'Single poll: {ip} → {"online" if any(d["ip"]==ip and d.get("online") for d in stats["devices"]) else "offline"}')
            except Exception as e:
                log(f'poll_single error: {e}')

        forced = os.path.exists(POLL_FORCE)
        if forced:
            try:
                os.remove(POLL_FORCE)
            except Exception:
                pass

        if forced or (now - last_poll) >= repeat_seconds():
            devices = load_yaml()
            stats   = poll_all(devices, read_stats())
            write_stats(stats)
            last_poll = now
            online = sum(1 for d in stats['devices'] if d.get('online'))
            log(f'Poll: {online}/{len(stats["devices"])} online')


if __name__ == '__main__':
    main()

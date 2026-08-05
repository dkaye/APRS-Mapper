#!/usr/bin/env python3
# igate-isrelay — APRS-IS aggregation relay for the K6DRK iGate fleet.
#
# Our controlled iGates point their local isproxy at this relay instead of the
# public APRS-IS. For each connected iGate the relay:
#   1. RECORDS every packet the iGate gates — the raw RF->IS stream, captured
#      *before* APRS-IS dedup, so we see every iGate's receptions even when it
#      was not first to gate a packet. This is the whole point: the public feed
#      only ever shows the first gater of each packet, so a busy iGate that is
#      usually second (dense overlap) looks idle there but is fully visible here.
#   2. FORWARDS that stream to the public APRS-IS unchanged (preserving each
#      iGate's own login and q-construct) and pipes the downlink back, so the
#      iGate keeps working exactly as if connected directly.
#
# Attribution is by the q-construct ENTRY STATION in each packet (",qAO,MARS-5"
# -> MARS-5), NOT the login callsign: direwolf logs in with IGLOGIN (often the
# base call, e.g. "MARS") but stamps packets with MYCALL ("MARS-5"), and the map
# / analyzer key on MYCALL.
#
# Outputs:
#   SIGNAL_FILE  {callsign: last_unix_ts} — same shape as the map's igates.json;
#                the connectivity sidebar consumes it (a real "online + hearing
#                RF" signal, undeduped).
#   CAPTURE_FILE JSON-lines of every gated packet, for the analyzer's coverage.
#
# Config via environment (set in the systemd unit):
#   ISRELAY_HOST/PORT       listen socket (default 0.0.0.0:14590)
#   ISRELAY_UP_HOST/PORT    upstream public APRS-IS (default noam.aprs2.net:14580)
#   ISRELAY_SIGNAL          signal file (default /var/www/html/igate_relay.json)
#   ISRELAY_CAPTURE         capture log (default /home/pi/igate-isrelay/capture.jsonl)
#   ISRELAY_ALLOW           comma-list of allowed entry callsigns ("" = allow all)
#
# Prototype: stdlib asyncio only, no dependencies.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# (c)2026 Doug Kaye, K6DRK <doug@rds.com>

import asyncio
import json
import os
import re
import time

LISTEN_HOST  = os.environ.get("ISRELAY_HOST", "0.0.0.0")
LISTEN_PORT  = int(os.environ.get("ISRELAY_PORT", "14590"))
UPSTREAM     = (os.environ.get("ISRELAY_UP_HOST", "noam.aprs2.net"),
                int(os.environ.get("ISRELAY_UP_PORT", "14580")))
SIGNAL_FILE  = os.environ.get("ISRELAY_SIGNAL",  "/var/www/html/igate_relay.json")
CAPTURE_FILE = os.environ.get("ISRELAY_CAPTURE", "/home/pi/igate-isrelay/capture.jsonl")
ALLOW        = set(c.strip() for c in os.environ.get("ISRELAY_ALLOW", "").split(",") if c.strip())
SIGNAL_MIN_INTERVAL = 3   # seconds; throttle signal-file writes

QRE = re.compile(r",q[A-Z]+,([^,:]+)")   # entry station after the q-construct

# callsign -> last unix ts we heard a packet gated by it
_last_heard = {}
_last_signal_write = 0.0


def log(msg):
    print(f"{time.strftime('%Y-%m-%d %H:%M:%S')} {msg}", flush=True)


def _write_signal(force=False):
    global _last_signal_write
    now = time.time()
    if not force and now - _last_signal_write < SIGNAL_MIN_INTERVAL:
        return
    _last_signal_write = now
    try:
        tmp = SIGNAL_FILE + ".tmp"
        with open(tmp, "w") as f:
            json.dump(_last_heard, f)
        os.replace(tmp, SIGNAL_FILE)
    except Exception as e:
        log(f"WARN signal write failed: {e}")


def _capture(rec):
    try:
        with open(CAPTURE_FILE, "a") as f:
            f.write(json.dumps(rec) + "\n")
    except Exception as e:
        log(f"WARN capture write failed: {e}")


def _note_packet(login_call, raw):
    m = QRE.search(raw)
    entry = m.group(1).rstrip("*") if m else None
    src = raw.split(">", 1)[0] if ">" in raw else ""
    now = int(time.time())
    if entry:
        _last_heard[entry] = now
        _write_signal()
    _capture({"t": now, "igate": entry, "login": login_call, "src": src, "raw": raw})


async def handle_igate(reader, writer):
    peer = writer.get_extra_info("peername")
    try:
        login = await reader.readline()
    except Exception as e:
        log(f"login read failed from {peer}: {e}")
        writer.close(); return
    login_call = "?"
    parts = login.decode("ascii", "replace").split()
    if len(parts) >= 2 and parts[0].lower() == "user":
        login_call = parts[1]
    if not login.strip():
        # Bare connect with no login — e.g. isproxy's reachability probe. Ignore.
        writer.close(); return
    log(f"iGate {login_call} connected from {peer}")

    try:
        up_reader, up_writer = await asyncio.wait_for(
            asyncio.open_connection(*UPSTREAM), timeout=10)
        up_writer.write(login)
        await up_writer.drain()
    except Exception as e:
        log(f"iGate {login_call} upstream connect failed: {e}")
        writer.close(); return

    async def igate_to_upstream():
        buf = b""
        try:
            while True:
                data = await reader.read(4096)
                if not data:
                    break
                up_writer.write(data)
                await up_writer.drain()
                buf += data
                while b"\n" in buf:
                    ln, buf = buf.split(b"\n", 1)
                    s = ln.decode("utf-8", "replace").rstrip("\r")
                    if not s or s.startswith("#"):
                        continue
                    entry = QRE.search(s)
                    ecall = entry.group(1).rstrip("*") if entry else None
                    if ALLOW and ecall and ecall not in ALLOW:
                        continue   # not one of ours; still forwarded above
                    _note_packet(login_call, s)
        except Exception as e:
            log(f"iGate {login_call} uplink error: {e}")

    async def upstream_to_igate():
        try:
            while True:
                data = await up_reader.read(4096)
                if not data:
                    break
                writer.write(data)
                await writer.drain()
        except Exception as e:
            log(f"iGate {login_call} downlink error: {e}")

    t1 = asyncio.ensure_future(igate_to_upstream())
    t2 = asyncio.ensure_future(upstream_to_igate())
    await asyncio.wait([t1, t2], return_when=asyncio.FIRST_COMPLETED)
    for t in (t1, t2):
        t.cancel()
    for w in (writer, up_writer):
        try: w.close()
        except Exception: pass
    log(f"iGate {login_call} disconnected")


async def main():
    os.makedirs(os.path.dirname(CAPTURE_FILE), exist_ok=True)
    _write_signal(force=True)
    server = await asyncio.start_server(handle_igate, LISTEN_HOST, LISTEN_PORT)
    log(f"isrelay listening on {LISTEN_HOST}:{LISTEN_PORT} upstream={UPSTREAM} "
        f"signal={SIGNAL_FILE} allow={sorted(ALLOW) or 'ALL'}")
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass

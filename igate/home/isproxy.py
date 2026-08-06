#!/usr/bin/env python3
# igate-isproxy — local APRS-IS failover proxy for the K6DRK iGate software.
#
# direwolf connects to this proxy (set "IGSERVER 127.0.0.1" in direwolf.conf).
# The proxy keeps ONE upstream APRS-IS connection at a time and pipes bytes
# transparently in both directions, so direwolf never sees a disconnect when the
# upstream changes underneath it.
#
# Upstreams, in priority order:
#   1. PRIMARY  — our aggregation relay, which records every iGate's traffic
#                 *before* APRS-IS dedup and then forwards it upstream. Routing
#                 through it costs the iGate no extra cellular data (same single
#                 uplink, just a different destination).
#   2. FALLBACK — the public APRS-IS network (exactly today's direct path).
#
# If PRIMARY can't be reached, or its connection goes stale (no data for
# STALE_SECS — APRS-IS sends "# ..." keepalives about every 20s), the proxy fails
# over to FALLBACK so gating never stops. It keeps probing PRIMARY and switches
# back once PRIMARY is healthy again, with hysteresis so it can't flap.
#
# The proxy caches direwolf's login line and replays it on every new upstream
# connection (APRS-IS requires the login first).
#
# A status file (STATUS_FILE) records which upstream is live — this doubles as a
# real "iGate is online and streaming to us" health signal for the fleet.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import asyncio
import json
import os
import sys
import time

CONF_FILE   = os.environ.get("ISPROXY_CONF",   "/home/pi/isproxy.json")
STATUS_FILE = os.environ.get("ISPROXY_STATUS", "/home/pi/isproxy.status.json")

DEFAULTS = {
    "listen_host":       "127.0.0.1",
    "listen_port":       14580,
    "primary":           ["noam.aprs2.net", 14580],   # override to the relay
    "fallback":          ["rotate.aprs2.net", 14580],  # public APRS-IS
    "connect_timeout":   10,   # seconds to establish an upstream TCP connection
    "stale_secs":        60,   # no upstream data for this long => treat as dead
    "fallback_min_secs": 30,   # min time on fallback before retrying primary
    "probe_secs":        30,   # how often to probe primary while on fallback
    "buffer_max":        65536, # cap on uplink bytes buffered during a gap
}


def load_conf():
    conf = dict(DEFAULTS)
    try:
        with open(CONF_FILE) as f:
            conf.update(json.load(f))
    except FileNotFoundError:
        pass
    except Exception as e:
        log(f"WARN bad config {CONF_FILE}: {e}; using defaults")
    conf["primary"]  = tuple(conf["primary"])
    conf["fallback"] = tuple(conf["fallback"])
    return conf


def log(msg):
    line = f"{time.strftime('%Y-%m-%d %H:%M:%S')} {msg}"
    print(line, flush=True)


def write_status(mode, target, since):
    try:
        tmp = STATUS_FILE + ".tmp"
        with open(tmp, "w") as f:
            json.dump({"mode": mode, "target": list(target), "since": int(since),
                       "updated": int(time.time())}, f)
        os.replace(tmp, STATUS_FILE)
    except Exception as e:
        log(f"WARN can't write status: {e}")


class Proxy:
    def __init__(self, conf):
        self.c = conf
        self.login = b""              # direwolf's login line, replayed upstream
        self.dw_writer = None         # StreamWriter to direwolf
        self.up_writer = None         # StreamWriter to current upstream
        self.up_buf = bytearray()     # uplink bytes buffered while upstream down
        self.last_up_data = 0.0       # monotonic time of last upstream byte
        self.mode = "init"            # "primary" | "fallback"
        self.retry_primary_at = 0.0   # wall-clock; before this, prefer fallback
        self.switch_back = asyncio.Event()  # set by prober to end a fallback run
        self.stop = False

    # ---- direwolf side -------------------------------------------------------
    async def handle_direwolf(self, reader, writer):
        peer = writer.get_extra_info("peername")
        log(f"direwolf connected from {peer}")
        self.dw_writer = writer
        # An APRS-IS server greets the client with a "# ..." banner line BEFORE the
        # client sends its login, and direwolf waits for that greeting first. Send a
        # synthetic banner immediately — otherwise direwolf blocks waiting for it
        # while we block reading its login (deadlock), so it times out, sends the
        # login late, gets no timely logresp and disconnects, churning forever. The
        # real upstream banner/logresp still flow through afterward (extra "#"
        # comment lines are harmless to direwolf).
        try:
            writer.write(b"# igate-isproxy 5.2\r\n")
            await writer.drain()
        except Exception as e:
            log(f"direwolf banner write failed: {e}")
            writer.close()
            return
        # First line from direwolf is its APRS-IS login; cache it for replay.
        try:
            self.login = await reader.readline()
        except Exception as e:
            log(f"direwolf login read failed: {e}")
            writer.close()
            return
        log(f"cached login: {self.login.decode('ascii','replace').strip()}")
        # Acknowledge the login LOCALLY with a synthetic "# logresp … verified" so
        # direwolf considers itself connected and stays, independent of how long the
        # real upstream takes to come up. Without this, a higher-latency gate (cellular)
        # disconnects the instant after it sends its login — before isproxy can connect
        # the upstream and relay the real logresp — and churns forever; only low-latency
        # (LAN) gates won the race. Our logins are all verified, so asserting "verified"
        # is accurate; the real upstream logresp still flows through afterward (a second
        # "#" line is harmless to direwolf).
        try:
            _p = self.login.decode('ascii', 'replace').split()
            _call = _p[1] if len(_p) >= 2 and _p[0].lower() == 'user' else 'UNKNOWN'
            writer.write(f"# logresp {_call} verified, server IGATE-PROXY\r\n".encode())
            await writer.drain()
        except Exception as e:
            log(f"synthetic logresp failed: {e}")
        # Supervise the upstream for the life of this direwolf session.
        sup = asyncio.ensure_future(self.upstream_supervisor())
        try:
            while not self.stop:
                data = await reader.read(4096)
                if not data:
                    break
                if self.up_writer is not None:
                    self.up_writer.write(data)
                    try:
                        await self.up_writer.drain()
                    except Exception:
                        self.up_writer = None
                        self._buffer(data)
                else:
                    self._buffer(data)
        except Exception as e:
            log(f"direwolf read error: {e}")
        finally:
            log("direwolf disconnected")
            self.stop = True
            sup.cancel()
            self._close_upstream()
            writer.close()

    def _buffer(self, data):
        self.up_buf.extend(data)
        if len(self.up_buf) > self.c["buffer_max"]:
            # Gating is best-effort; drop the oldest to bound memory.
            del self.up_buf[:len(self.up_buf) - self.c["buffer_max"]]

    def _close_upstream(self):
        if self.up_writer is not None:
            try:
                self.up_writer.close()
            except Exception:
                pass
            self.up_writer = None

    # ---- upstream side -------------------------------------------------------
    def _choose_target(self):
        # Prefer primary unless we're in a post-failure cooldown window.
        if time.time() >= self.retry_primary_at:
            return "primary", self.c["primary"]
        return "fallback", self.c["fallback"]

    async def upstream_supervisor(self):
        while not self.stop:
            mode, target = self._choose_target()
            try:
                reader, writer = await asyncio.wait_for(
                    asyncio.open_connection(target[0], target[1]),
                    timeout=self.c["connect_timeout"])
            except Exception as e:
                log(f"upstream {mode} {target[0]}:{target[1]} connect failed: {e}")
                if mode == "primary":
                    # Primary down: fall back and hold there before retrying.
                    self.retry_primary_at = time.time() + self.c["fallback_min_secs"]
                else:
                    await asyncio.sleep(2)  # fallback also down; brief backoff
                continue

            self.up_writer = writer
            self.mode = mode
            self.last_up_data = time.monotonic()
            since = time.time()
            write_status(mode, target, since)
            log(f"upstream UP: {mode} {target[0]}:{target[1]}")

            # Replay direwolf's login, then flush anything buffered during the gap.
            try:
                writer.write(self.login)
                if self.up_buf:
                    writer.write(bytes(self.up_buf))
                    self.up_buf.clear()
                await writer.drain()
            except Exception as e:
                log(f"upstream {mode} login/flush failed: {e}")
                self._close_upstream()
                continue

            self.switch_back.clear()
            pump = asyncio.ensure_future(self._pump_up_to_dw(reader))
            watch = asyncio.ensure_future(self._staleness_watch())
            prober = None
            if mode == "fallback":
                prober = asyncio.ensure_future(self._probe_primary())

            done, pending = await asyncio.wait(
                [pump, watch] + ([prober] if prober else []),
                return_when=asyncio.FIRST_COMPLETED)
            for t in pending:
                t.cancel()
            self._close_upstream()

            if self.stop:
                break
            if mode == "primary":
                # If a primary session died quickly, cool down before retrying so
                # a flapping primary doesn't thrash; otherwise retry it at once.
                if time.time() - since < 5:
                    self.retry_primary_at = time.time() + self.c["fallback_min_secs"]
            else:
                # Fallback run ended — either it died (retry chooses primary if
                # cooldown expired) or the prober found primary healthy.
                if self.switch_back.is_set():
                    self.retry_primary_at = 0.0
            await asyncio.sleep(0.2)

    async def _pump_up_to_dw(self, reader):
        try:
            while not self.stop:
                data = await reader.read(4096)
                if not data:
                    log(f"upstream {self.mode} closed by peer")
                    return
                self.last_up_data = time.monotonic()
                if self.dw_writer is not None:
                    self.dw_writer.write(data)
                    await self.dw_writer.drain()
        except Exception as e:
            log(f"upstream {self.mode} read error: {e}")
            return

    async def _staleness_watch(self):
        while not self.stop:
            await asyncio.sleep(5)
            if time.monotonic() - self.last_up_data > self.c["stale_secs"]:
                log(f"upstream {self.mode} STALE ({self.c['stale_secs']}s no data)")
                return

    async def _probe_primary(self):
        # Active only while on fallback: after a hold-down, periodically test the
        # primary; when it accepts a connection, end the fallback run so the
        # supervisor reconnects to primary.
        await asyncio.sleep(self.c["fallback_min_secs"])
        while not self.stop:
            try:
                r, w = await asyncio.wait_for(
                    asyncio.open_connection(*self.c["primary"]),
                    timeout=self.c["connect_timeout"])
                w.close()
                log("primary reachable again — switching back")
                self.switch_back.set()
                return
            except Exception:
                await asyncio.sleep(self.c["probe_secs"])


async def main():
    conf = load_conf()
    proxy = Proxy(conf)
    server = await asyncio.start_server(
        proxy.handle_direwolf, conf["listen_host"], conf["listen_port"])
    log(f"isproxy listening on {conf['listen_host']}:{conf['listen_port']} "
        f"primary={conf['primary']} fallback={conf['fallback']}")
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass

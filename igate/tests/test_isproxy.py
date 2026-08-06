#!/usr/bin/env python3
# Regression test for isproxy.py — direwolf must get an upstream on RECONNECT.
#
# isproxy sets a shutdown flag when a direwolf session ends. If that flag is not
# cleared for the next session, the upstream supervisor exits immediately, no
# upstream is ever opened, and direwolf churns (connect, login, drop) every ~15s
# forever — the gate stops gating. auto-update.sh restarts direwolf on every run,
# so this fires nightly on every relay-enrolled gate.
#
# The test runs a fake APRS-IS upstream and two SEQUENTIAL fake direwolf sessions
# against the real Proxy class. Both sessions must reach the upstream.
#
# Usage: python3 igate/tests/test_isproxy.py   (exit 0 = pass)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import asyncio
import os
import sys
import tempfile

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "home"))
os.environ["ISPROXY_STATUS"] = tempfile.mktemp(suffix=".json")
import isproxy

upstream_logins = []          # one entry per upstream connection isproxy opens


async def fake_aprsis(reader, writer):
    """Minimal APRS-IS server: banner, read login, logresp, then drain."""
    writer.write(b"# fake-aprsis 1.0\r\n")
    await writer.drain()
    upstream_logins.append((await reader.readline()).decode().strip())
    writer.write(b"# logresp TEST verified, server FAKE\r\n")
    await writer.drain()
    try:
        while await reader.read(4096):
            pass
    except Exception:
        pass


async def direwolf_session(port, label):
    """One direwolf lifecycle: connect, log in, send a frame, disconnect."""
    reader, writer = await asyncio.open_connection("127.0.0.1", port)
    await asyncio.wait_for(reader.readline(), timeout=5)      # banner
    writer.write(b"user TEST-1 pass 12345 vers Fake 1.0\r\n")
    await writer.drain()
    try:
        for _ in range(3):
            await asyncio.wait_for(reader.read(4096), timeout=3)
    except asyncio.TimeoutError:
        pass
    writer.write(b"TEST-1>APRS:test packet\r\n")
    await writer.drain()
    await asyncio.sleep(1)
    writer.close()
    print(f"  {label}: sent login, disconnected")


async def main():
    upstream = await asyncio.start_server(fake_aprsis, "127.0.0.1", 0)
    up_port = upstream.sockets[0].getsockname()[1]

    conf = dict(isproxy.DEFAULTS)
    conf.update({"listen_port": 0,
                 "primary":  ("127.0.0.1", up_port),
                 "fallback": ("127.0.0.1", up_port)})
    proxy = isproxy.Proxy(conf)
    server = await asyncio.start_server(proxy.handle_direwolf, "127.0.0.1", 0)
    port = server.sockets[0].getsockname()[1]

    await direwolf_session(port, "session 1")
    await asyncio.sleep(1)                 # direwolf gone; shutdown flag was set
    await direwolf_session(port, "session 2")
    await asyncio.sleep(1)

    server.close()
    upstream.close()
    ok = len(upstream_logins) == 2
    print(f"upstream connections: {len(upstream_logins)} (expected 2)")
    print("PASS" if ok else "FAIL — reconnect opened no upstream; the gate would churn")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))

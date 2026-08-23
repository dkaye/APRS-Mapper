#!/usr/bin/env python3
"""Summarize a nettest.sh run: loss, latency, jitter, and located outages.

Shared by every device type; see common/power-check.sh.

Usage: netreport.py <run-dir>
"""
import re, sys, os, statistics as st
from datetime import datetime

LINE = re.compile(r"\[(\d+\.\d+)\].*icmp_seq=(\d+).*time=([\d.]+) ms")


def parse(path):
    seqs = {}
    with open(path, errors="replace") as fh:
        for ln in fh:
            m = LINE.search(ln)
            if m:
                seqs[int(m.group(2))] = (float(m.group(1)), float(m.group(3)))
    return seqs


def outages(seqs):
    """Runs of consecutive missing sequence numbers, with wall-clock anchor."""
    if not seqs:
        return []
    lo, hi = min(seqs), max(seqs)
    gaps, cur = [], None
    for s in range(lo, hi + 1):
        if s not in seqs:
            cur = s if cur is None else cur
        elif cur is not None:
            gaps.append((cur, s - 1))
            cur = None
    if cur is not None:
        gaps.append((cur, hi))
    out = []
    for a, b in gaps:
        prev = max((s for s in seqs if s < a), default=None)
        when = datetime.fromtimestamp(seqs[prev][0]).strftime("%H:%M:%S") if prev else "?"
        out.append((when, b - a + 1))
    return out


def report(name, path):
    if not os.path.exists(path):
        print(f"\n## {name}: (no data)")
        return
    seqs = parse(path)
    if not seqs:
        print(f"\n## {name}: NO REPLIES AT ALL — target unreachable for the whole run")
        return
    lo, hi = min(seqs), max(seqs)
    sent, got = hi - lo + 1, len(seqs)
    lost = sent - got
    rtt = sorted(v[1] for v in seqs.values())
    order = [seqs[s][1] for s in sorted(seqs)]
    jitter = st.mean(abs(b - a) for a, b in zip(order, order[1:])) if len(order) > 1 else 0.0

    def pct(p):
        return rtt[min(len(rtt) - 1, int(len(rtt) * p / 100))]

    print(f"\n## {name}")
    print(f"  sent={sent}  recv={got}  LOSS={lost} ({100.0*lost/sent:.2f}%)")
    print(f"  rtt  min={rtt[0]:.1f}  p50={pct(50):.1f}  p95={pct(95):.1f}  "
          f"p99={pct(99):.1f}  max={rtt[-1]:.1f} ms")
    print(f"  jitter(mean consecutive delta)={jitter:.1f} ms")
    gaps = outages(seqs)
    if gaps:
        tot = sum(g[1] for g in gaps)
        print(f"  OUTAGES: {len(gaps)} gap(s), {tot}s total unreachable")
        for when, dur in sorted(gaps, key=lambda g: -g[1])[:12]:
            print(f"    at {when}  lasting ~{dur}s")
    else:
        print("  OUTAGES: none — no dropped packets")


d = sys.argv[1].rstrip("/")
print("=" * 62)
print(open(os.path.join(d, "context.txt"), errors="replace").read().strip())
print("=" * 62)

report("WiFi hop  (Pi -> hotspot)", os.path.join(d, "ping-gw.txt"))
report("WAN via cellular (1.1.1.1)", os.path.join(d, "ping-cf.txt"))
report("WAN via cellular (8.8.8.8)", os.path.join(d, "ping-goog.txt"))

sp = os.path.join(d, "samples.txt")
if os.path.exists(sp):
    rows = [r.split() for r in open(sp, errors="replace") if "curl=" in r]
    bad = [r for r in rows if r[-1] != "200"]
    tots, sigs = [], []
    for r in rows:
        try:
            tots.append(float(r[-2]))
        except ValueError:
            pass
        for f in r:
            if f.startswith("signal="):
                try:
                    sigs.append(float(r[r.index(f) + 1].replace("dBm", "")))
                except (ValueError, IndexError):
                    pass
    print("\n## HTTPS transactions (DNS+TCP+TLS+fetch, every 10s)")
    print(f"  attempts={len(rows)}  failed/timed-out={len(bad)}")
    if tots:
        s = sorted(tots)
        print(f"  total time  p50={s[len(s)//2]:.2f}s  "
              f"p95={s[min(len(s)-1,int(len(s)*.95))]:.2f}s  max={s[-1]:.2f}s")
    if sigs:
        print(f"  wifi signal min={min(sigs):.0f}dBm max={max(sigs):.0f}dBm "
              f"(closer to 0 is stronger)")
    for r in bad[:8]:
        print(f"    FAILED at {r[0]}  -> {' '.join(r[-5:])}")

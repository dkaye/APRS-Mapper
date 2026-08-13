#!/usr/bin/env python3
"""SDR self-noise analyzer (stdlib only — runs on a bare Pi).

Reads several rtl_power sweeps and reports the internal-birdie / spur level near the
frequency the receiver actually cares about — the thing that quietly desensitizes it.
Shared by the iGates (144.390 APRS) and the Transcribers (whatever voice channel each
one is on); the maths never cared which, only the analyzer's hardcoded constants did.

Combiner: MAX-HOLD across the sweeps (so an intermittent internal spur, which cycles on
and off, is caught when it's on), gated by an OCCURRENCE filter (a spur must appear in at
least 2 sweeps). That rejects a one-off over-the-air transmission — present in a single
sweep — while keeping a real internal spur that recurs. So the test is valid with the
antenna connected: no need to unplug anything in the field.

The headline number is the worst qualifying spur in the guard band around the watched
frequency, excluding the channel itself, in dB over the noise floor. Calibration from a
Pi Zero 2 W: ~+18 dB with the dongle in the case (BAD), ~+1-3 dB with it moved 12 cm out
(GOOD).

Usage: sdr-selftest.py --watch 144390000 --meta '<json>' sweep1.csv sweep2.csv ...
Emits one JSON object on stdout.

Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
©2026 Doug Kaye, K6DRK <doug@rds.com>
"""
import argparse
import csv
import json
import os
import statistics
import sys

# Offsets from the watched frequency, in Hz. These are the iGate's original absolute
# numbers re-expressed relative to 144.390: guard 144.37-144.42, channel 144.383-144.397.
# The asymmetry is inherited rather than principled, and is kept so that a gate's grade
# means the same thing before and after this file was generalized — a self-noise history
# is only useful if the bar has not moved under it.
GUARD_LO_OFFSET = -20_000
GUARD_HI_OFFSET = +30_000
CHANNEL_OFFSET = 7_000

SPUR_MIN_DB = 6.0     # a bin this far over floor is "elevated"
COUNT_MIN_DB = 10.0   # spurs this strong are tallied
GOOD_MAX, MARGINAL_MAX = 6.0, 15.0   # grade thresholds on the guard spur


def load(path):
    spec = {}
    try:
        rows = list(csv.reader(open(path)))
    except OSError:
        return spec
    for row in rows:
        if len(row) < 7:
            continue
        try:
            lo, step = float(row[2]), float(row[4])
        except ValueError:
            continue
        for i, v in enumerate(row[6:]):
            v = v.strip()
            if v in ('', 'nan', '-nan', 'inf', '-inf'):
                continue
            try:
                spec[round(lo + i * step)] = float(v)
            except ValueError:
                pass
    return spec


def analyse(specs, watch, guard_lo, guard_hi, chan):
    """The measurement, separated from argument handling so it can be tested."""
    common = set.intersection(*[set(s) for s in specs])
    if not common:
        return None
    freqs = sorted(common)
    n = len(specs)

    # Floor from the per-bin median (stable); spurs measured against max-hold.
    med_spec = {f: statistics.median([s[f] for s in specs]) for f in common}
    max_spec = {f: max(s[f] for s in specs) for f in common}
    floor = statistics.median(med_spec.values())
    min_occ = 2 if n >= 3 else 1
    occ = {f: sum(1 for s in specs if s[f] > floor + SPUR_MIN_DB) for f in common}

    # Spurs: local maxima in max-hold, elevated, recurring in >= min_occ sweeps.
    spurs = []
    for i, f in enumerate(freqs):
        if max_spec[f] < floor + SPUR_MIN_DB or occ[f] < min_occ:
            continue
        if any(0 <= j < len(freqs) and max_spec[freqs[j]] > max_spec[f] for j in (i-2, i-1, i+1, i+2)):
            continue
        spurs.append((f, max_spec[f] - floor, occ[f]))
    # Collapse peaks within 20 kHz to the strongest.
    spurs.sort(key=lambda x: -x[1])
    ded = []
    for f, d, o in spurs:
        if all(abs(f - g) > 20000 for g, _, _ in ded):
            ded.append((f, d, o))

    def strongest(cands):
        return max(cands, key=lambda x: x[1]) if cands else (None, 0.0, 0)

    gf, gd, go = strongest([t for t in ded
                            if guard_lo <= t[0] <= guard_hi
                            and not (watch - chan <= t[0] <= watch + chan)])
    wf, wd, wo = strongest(ded)
    spur_count = sum(1 for _, d, _ in ded if d >= COUNT_MIN_DB)
    grade = 'GOOD' if gd < GOOD_MAX else ('MARGINAL' if gd < MARGINAL_MAX else 'BAD')

    # Comb detection: >=5 spurs at a consistent spacing => self-noise, not a legitimate
    # signal (nothing on the air makes a regular comb across the band).
    comb = False
    fl = sorted(f for f, _, _ in ded)
    if len(fl) >= 5:
        gaps = sorted(fl[i+1] - fl[i] for i in range(len(fl)-1))
        med_gap = statistics.median(gaps)
        if med_gap > 0:
            regular = sum(1 for g in gaps if abs(g - med_gap) < 0.15 * med_gap)
            comb = regular >= 4

    return {
        'floor_db': round(floor, 1),
        'sweeps': n,
        'watch_mhz': round(watch / 1e6, 4),
        'guard_spur_db': round(gd, 1),
        'guard_spur_mhz': round(gf / 1e6, 4) if gf else None,
        'guard_offset_khz': round((gf - watch) / 1e3, 1) if gf else None,
        'guard_duty': round(go / n, 2) if gf else None,
        'worst_band_spur_db': round(wd, 1),
        'worst_band_spur_mhz': round(wf / 1e6, 4) if wf else None,
        'spur_count': spur_count,
        'comb_detected': comb,
        'grade': grade,
        'top_spurs': [{'mhz': round(f / 1e6, 4), 'db': round(d, 1), 'duty': round(o / n, 2)}
                      for f, d, o in sorted(ded, key=lambda x: -x[1])[:8]],
    }


def with_legacy_keys(out):
    """Also emit the aprs_guard_* names this used to use.

    The fleet dashboard and every gate's selftest-history.csv were written against them.
    Renaming without aliases would blank the dashboard for any device that had not yet
    picked up the new analyzer, which on a nightly update cycle is all of them for a day
    — and would silently break the history that makes a slow degradation visible at all.
    """
    out['aprs_guard_spur_db'] = out['guard_spur_db']
    out['aprs_guard_spur_mhz'] = out['guard_spur_mhz']
    out['aprs_guard_offset_khz'] = out['guard_offset_khz']
    out['aprs_guard_duty'] = out['guard_duty']
    return out


def main(argv=None):
    p = argparse.ArgumentParser(description="Analyse rtl_power sweeps for internal spurs.")
    p.add_argument("--watch", type=float, required=True,
                   help="the frequency this receiver cares about, in Hz")
    p.add_argument("--meta", default="{}", help="JSON merged into the output")
    p.add_argument("--guard-lo", type=float, default=GUARD_LO_OFFSET)
    p.add_argument("--guard-hi", type=float, default=GUARD_HI_OFFSET)
    p.add_argument("--channel", type=float, default=CHANNEL_OFFSET,
                   help="half-width of the channel itself, excluded from the guard band")
    p.add_argument("sweeps", nargs="*")
    args = p.parse_args(argv)

    try:
        meta = json.loads(args.meta)
    except ValueError:
        meta = {}

    files = [a for a in args.sweeps
             if a.endswith('.csv') and os.path.exists(a) and os.path.getsize(a) > 0]
    specs = [s for s in (load(f) for f in files) if s]
    if not specs:
        print(json.dumps({**meta, 'error': 'no sweep data'}))
        return 1

    out = analyse(specs, args.watch,
                  args.watch + args.guard_lo, args.watch + args.guard_hi, args.channel)
    if out is None:
        print(json.dumps({**meta, 'error': 'no common bins'}))
        return 1

    print(json.dumps({**meta, **with_legacy_keys(out)}))
    return 0


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""iGate SDR self-noise analyzer (stdlib only — runs on a bare Pi).

Reads several rtl_power sweeps of the 2 m band and reports the internal-birdie /
spur level near the APRS channel — the thing that quietly desensitizes a gate.

Combiner: MAX-HOLD across the sweeps (so an intermittent internal spur, which
cycles on and off, is caught when it's on), gated by an OCCURRENCE filter (a
spur must appear in at least 2 sweeps). That rejects a one-off over-the-air
transmission — present in a single sweep — while keeping a real internal spur
that recurs. So the test is valid with the antenna connected: no need to unplug
anything in the field.

The headline number is the worst qualifying spur in the APRS guard band
(144.37-144.42, excluding the channel itself), in dB over the noise floor.
Calibration from a Pi Zero 2 W: ~+18 dB with the dongle in the case (BAD),
~+1-3 dB with it moved 12 cm out (GOOD).

Usage: igate-selftest.py '<metadata-json>' sweep1.csv sweep2.csv ...
Emits one JSON object on stdout.
"""
import sys, csv, json, statistics, os

APRS = 144.390e6
GUARD_LO, GUARD_HI = 144.37e6, 144.42e6
CHAN_LO, CHAN_HI   = 144.383e6, 144.397e6
SPUR_MIN_DB  = 6.0    # a bin this far over floor is "elevated"
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


def main():
    meta = json.loads(sys.argv[1]) if len(sys.argv) > 1 else {}
    files = [a for a in sys.argv[2:] if a.endswith('.csv') and os.path.exists(a) and os.path.getsize(a) > 0]
    specs = [s for s in (load(f) for f in files) if s]
    if not specs:
        print(json.dumps({**meta, 'error': 'no sweep data'}))
        return 1

    common = set.intersection(*[set(s) for s in specs])
    if not common:
        print(json.dumps({**meta, 'error': 'no common bins'}))
        return 1
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
                            if GUARD_LO <= t[0] <= GUARD_HI and not (CHAN_LO <= t[0] <= CHAN_HI)])
    wf, wd, wo = strongest(ded)
    spur_count = sum(1 for _, d, _ in ded if d >= COUNT_MIN_DB)
    grade = 'GOOD' if gd < GOOD_MAX else ('MARGINAL' if gd < MARGINAL_MAX else 'BAD')

    # Comb detection: >=5 spurs at a consistent spacing => self-noise, not a
    # legitimate signal (nothing on the air makes a regular comb across the band).
    comb = False
    fl = sorted(f for f, _, _ in ded)
    if len(fl) >= 5:
        gaps = sorted(fl[i+1] - fl[i] for i in range(len(fl)-1))
        med_gap = statistics.median(gaps)
        if med_gap > 0:
            regular = sum(1 for g in gaps if abs(g - med_gap) < 0.15 * med_gap)
            comb = regular >= 4

    out = {
        **meta,
        'floor_db': round(floor, 1),
        'sweeps': n,
        'aprs_guard_spur_db': round(gd, 1),
        'aprs_guard_spur_mhz': round(gf / 1e6, 4) if gf else None,
        'aprs_guard_offset_khz': round((gf - APRS) / 1e3, 1) if gf else None,
        'aprs_guard_duty': round(go / n, 2) if gf else None,
        'worst_band_spur_db': round(wd, 1),
        'worst_band_spur_mhz': round(wf / 1e6, 4) if wf else None,
        'spur_count': spur_count,
        'comb_detected': comb,
        'grade': grade,
        'top_spurs': [{'mhz': round(f / 1e6, 4), 'db': round(d, 1), 'duty': round(o / n, 2)}
                      for f, d, o in sorted(ded, key=lambda x: -x[1])[:8]],
    }
    print(json.dumps(out))
    return 0


if __name__ == '__main__':
    sys.exit(main())

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
import math
import os
import statistics
import subprocess
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


# ── is anything actually reaching this receiver? ─────────────────────────────
# Everything above grades INTERNAL noise, and for a long time it was quietly read as
# answering a question it cannot: whether the receiver hears anything at all. It does not.
# A dongle with nothing on its antenna port has LESS internal noise than a working one, so
# a stone-deaf receiver scores GOOD — which is what it did, minutes before a calibration
# on the same dongle reported a disconnected antenna, on a receiver whose antenna was
# connected and working the whole time.
#
# What is recorded here is the noise floor against tuner gain. Flat means the converter's
# own quantisation noise is all that reaches the ADC; rising roughly 1:1 means the band
# does, amplified along with everything else.
#
# NO GRADE IS PUT ON IT, on purpose. The connected case is measured — a quiet 2 m site
# rises about 15 dB across this ladder, from 0.44 counts RMS to about 3.6. The
# disconnected case is NOT measured, because that needs somebody to walk over and unscrew
# an antenna. Setting a threshold from one half of the evidence is precisely the mistake
# that put the calibration ceiling below a real site's knee and then blamed the hardware
# for the silence. So the numbers go into the history, and the threshold waits for data.
CURVE_GAINS = [8.7, 16.6, 25.4, 32.8, 38.6, 44.5, 49.6]
IQ_RATE = 250_000
IQ_WARM_SECONDS = 1.5     # discarded off the front: the tuner is still settling
IQ_SECONDS = 0.6


def floor_db(iq):
    """Power in a block of raw unsigned 8-bit I/Q, in dB relative to one ADC count.

    The same measurement the transcriber's own calibration sweep makes, deliberately: a
    number here that could not be compared against one from there would be worth much
    less. A copy rather than an import because this file also runs on iGates, which have
    no transcriber.py, and it is a dozen lines.

    Each half keeps its own DC offset — the tuner's I and Q offsets differ by a count or
    two, and down at the converter floor that error would be most of the answer.

    Read the result in COUNTS, not dBFS: -4 dB is 0.4 counts RMS, which is nothing
    arriving at all. Misreading it as "nearly full scale" costs an afternoon.
    """
    if not iq:
        return None
    power = 0.0
    for half in (iq[0::2], iq[1::2]):
        if not half:
            return None
        hist = [half.count(v) for v in range(256)]
        mean = sum(v * n for v, n in enumerate(hist)) / len(half)
        power += sum((v - mean) ** 2 * n for v, n in enumerate(hist)) / len(half)
    return round(10 * math.log10(power), 1) if power > 0 else None


def gain_curve(watch, serial="", gains=CURVE_GAINS):
    """The noise floor at each tuner gain, as [[gain, floor_db], ...].

    The hardware half, kept apart from curve_summary so the reading of the curve can be
    tested without a radio. A gain that will not measure is dropped rather than guessed
    at, so a partial curve is still a usable one.
    """
    out = []
    for g in gains:
        cmd = ["rtl_sdr"]
        if serial:
            cmd += ["-d", str(serial)]
        cmd += ["-f", str(int(watch)), "-s", str(IQ_RATE), "-g", "%g" % g,
                "-n", str(int(IQ_RATE * (IQ_WARM_SECONDS + IQ_SECONDS))), "-"]
        try:
            p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                               timeout=IQ_WARM_SECONDS + IQ_SECONDS + 20)
        except (OSError, subprocess.SubprocessError):
            continue
        settled = p.stdout[int(IQ_RATE * IQ_WARM_SECONDS) * 2:]
        if len(settled) < IQ_RATE:      # less than half the wanted samples: not a reading
            continue
        f = floor_db(settled)
        if f is not None:
            out.append([g, f])
    return out


def curve_summary(curve):
    """What the curve says, and nothing it does not.

    floor_rise_db is top gain minus bottom gain, not max minus min: a transmission caught
    mid-curve lifts one point and would make max-minus-min look like sensitivity that is
    not there. The spread is reported separately so that case stays visible.
    """
    if len(curve) < 2:
        return {}
    floors = [f for _, f in curve]
    return {
        'gain_floors': curve,
        'floor_bottom_db': curve[0][1],
        'floor_top_db': curve[-1][1],
        'floor_rise_db': round(curve[-1][1] - curve[0][1], 1),
        'floor_spread_db': round(max(floors) - min(floors), 1),
        'curve_gains': len(curve),
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
    p.add_argument("--antenna", action="store_true",
                   help="also record a noise-floor-against-gain curve on this receiver")
    p.add_argument("--serial", default="",
                   help="dongle serial for --antenna; empty means the default device")
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

    # Non-fatal by construction: a receiver with no rtl_sdr, or one that will not open,
    # still gets its spur grade. The curve is extra evidence, never a precondition.
    if args.antenna:
        try:
            out.update(curve_summary(gain_curve(args.watch, args.serial)))
        except Exception:
            pass

    print(json.dumps({**meta, **with_legacy_keys(out)}))
    return 0


if __name__ == '__main__':
    sys.exit(main())

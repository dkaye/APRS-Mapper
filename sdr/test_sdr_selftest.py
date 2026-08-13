#!/usr/bin/env python3
# Tests for the shared SDR self-noise analyzer.
#
# Two fleets grade their hardware on this, and the failure mode is quiet: a spur that is
# not reported is a receiver that is deaf for reasons nobody looks for. The generalization
# from "the APRS channel" to "whichever frequency this receiver watches" is what these
# mostly guard — an iGate's answer must not move because a Transcriber needed the same
# code.
#
# Usage: python3 sdr/test_sdr_selftest.py   (exit 0 = pass)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import importlib.util
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("sdr_selftest", os.path.join(HERE, "sdr-selftest.py"))
sdr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sdr)

FAILURES = []


def check(label, got, want):
    if got != want:
        FAILURES.append(f"{label}: got {got!r}, want {want!r}")
    else:
        print(f"  ok  {label}")


def sweep(watch, spurs, floor=-60.0, span=2_000_000, step=1000):
    """One synthetic sweep: a flat floor with `spurs` = {hz: dB over floor} on top."""
    s = {}
    for f in range(int(watch - span), int(watch + span) + 1, step):
        s[f] = floor
    for hz, over in spurs.items():
        s[round(hz)] = floor + over
    return s


def test_a_spur_beside_the_watched_channel_is_the_headline():
    """The whole measurement: how bad is the nearest internal birdie to what we listen
    for. 12 dB over the floor at +15 kHz should be found, named, and graded MARGINAL."""
    print("guard-band spur")
    watch = 144_390_000
    specs = [sweep(watch, {watch + 15_000: 12.0}) for _ in range(5)]
    out = sdr.analyse(specs, watch, watch - 20_000, watch + 30_000, 7_000)
    check("found", out["guard_spur_mhz"], 144.405)
    check("measured", out["guard_spur_db"], 12.0)
    check("offset reported in kHz", out["guard_offset_khz"], 15.0)
    check("graded", out["grade"], "MARGINAL")


def test_the_channel_itself_is_not_a_spur():
    """A signal ON the frequency is what the receiver is for. Counting it would grade a
    busy channel as broken hardware."""
    print("the channel is excluded")
    watch = 144_390_000
    specs = [sweep(watch, {watch: 30.0}) for _ in range(5)]
    out = sdr.analyse(specs, watch, watch - 20_000, watch + 30_000, 7_000)
    check("ignored in the guard band", out["guard_spur_mhz"], None)
    check("so the grade is clean", out["grade"], "GOOD")
    check("but it is still the worst in band", out["worst_band_spur_mhz"], 144.39)


def test_a_one_off_transmission_does_not_count():
    """Why the test is valid with the antenna connected. Somebody talking appears in one
    sweep; an internal spur is there every time. Without the occurrence filter every busy
    afternoon would look like failing hardware."""
    print("occurrence filter")
    watch = 144_390_000
    specs = [sweep(watch, {watch + 15_000: 20.0})] + [sweep(watch, {}) for _ in range(4)]
    out = sdr.analyse(specs, watch, watch - 20_000, watch + 30_000, 7_000)
    check("a single-sweep signal is rejected", out["guard_spur_mhz"], None)
    check("graded on what recurs", out["grade"], "GOOD")


def test_the_watched_frequency_is_what_moves():
    """The generalization. The same spectrum graded against two different receivers must
    give two different answers — that is the entire reason this file stopped being
    igate-selftest.py."""
    print("watch frequency drives the answer")
    spurs = {147_480_000: 12.0}                     # 15 kHz above a Transcriber channel
    voice = 147_465_000
    specs = [sweep(voice, spurs, span=3_000_000) for _ in range(5)]

    out = sdr.analyse(specs, voice, voice - 20_000, voice + 30_000, 7_000)
    check("a Transcriber on 147.465 sees it", out["guard_spur_db"], 12.0)

    # The same sweeps, asked about the APRS channel instead: far outside its guard band.
    aprs = 144_390_000
    out = sdr.analyse(specs, aprs, aprs - 20_000, aprs + 30_000, 7_000)
    check("an iGate on 144.390 does not", out["guard_spur_mhz"], None)


def test_grades():
    print("grade thresholds")
    watch = 144_390_000
    # 6.1 rather than 6.0 for the first boundary. A spur at exactly SPUR_MIN_DB over the
    # floor is dropped before grading, because the occurrence filter counts bins strictly
    # greater than floor + SPUR_MIN_DB while the elevation test admits ones equal to it.
    # Inherited, harmless at 0.1 dB, and left alone deliberately: tightening it would move
    # every gate's numbers a little and make the stored history incomparable with itself.
    for db, want in [(2.0, "GOOD"), (5.9, "GOOD"), (6.1, "MARGINAL"),
                     (14.9, "MARGINAL"), (15.1, "BAD"), (25.0, "BAD")]:
        specs = [sweep(watch, {watch + 15_000: db}) for _ in range(5)]
        out = sdr.analyse(specs, watch, watch - 20_000, watch + 30_000, 7_000)
        check(f"{db} dB is {want}", out["grade"], want)


def test_legacy_keys_are_still_emitted():
    """Every deployed gate's selftest-history.csv and the fleet dashboard were written
    against aprs_guard_*. Dropping them would blank the dashboard for a day and break the
    history that makes a slow degradation visible at all."""
    print("legacy key aliases")
    watch = 144_390_000
    specs = [sweep(watch, {watch + 15_000: 12.0}) for _ in range(5)]
    out = sdr.with_legacy_keys(sdr.analyse(specs, watch, watch - 20_000, watch + 30_000, 7_000))
    check("aprs_guard_spur_db", out["aprs_guard_spur_db"], out["guard_spur_db"])
    check("aprs_guard_spur_mhz", out["aprs_guard_spur_mhz"], out["guard_spur_mhz"])
    check("aprs_guard_offset_khz", out["aprs_guard_offset_khz"], out["guard_offset_khz"])


def test_a_comb_is_recognised_as_self_noise():
    """Nothing on the air makes a regular comb across the band, so one is proof the noise
    is coming from inside the box."""
    print("comb detection")
    watch = 144_390_000
    spurs = {watch - 1_500_000 + i * 400_000: 14.0 for i in range(8)}
    specs = [sweep(watch, spurs) for _ in range(5)]
    out = sdr.analyse(specs, watch, watch - 20_000, watch + 30_000, 7_000)
    check("detected", out["comb_detected"], True)

    specs = [sweep(watch, {watch + 900_000: 14.0}) for _ in range(5)]
    out = sdr.analyse(specs, watch, watch - 20_000, watch + 30_000, 7_000)
    check("a lone spur is not a comb", out["comb_detected"], False)


if __name__ == "__main__":
    for fn in [test_a_spur_beside_the_watched_channel_is_the_headline,
               test_the_channel_itself_is_not_a_spur,
               test_a_one_off_transmission_does_not_count,
               test_the_watched_frequency_is_what_moves,
               test_grades,
               test_legacy_keys_are_still_emitted,
               test_a_comb_is_recognised_as_self_noise]:
        fn()
    print()
    if FAILURES:
        print("FAILED:")
        for f in FAILURES:
            print("  " + f)
        sys.exit(1)
    print("all passed")

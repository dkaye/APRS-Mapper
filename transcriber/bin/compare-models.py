#!/usr/bin/env python3
# Compare two transcription configurations over the same audio — a bench tool, not part
# of the service.
#
# Two channels on two dongles would be the obvious way to run a comparison, and it would
# be the wrong one: they hear slightly different things, so any difference in the text is
# confounded with a difference in what arrived. This captures each transmission ONCE and
# runs both arms over that same file, which is the only comparison that answers the
# question actually being asked.
#
# Two questions can be asked, and they differ in what the arms differ by:
#
#   --compare models   Fast against Careful, no prompt either side. Is the slower model
#                      worth the wait, on this frequency, at this site, for these voices.
#
#   --compare prompt   one model, run without and then with the event's own vocabulary
#                      handed to whisper as an initial prompt. Does priming the decoder
#                      with thirty-five callsigns buy anything correct_callsigns cannot
#                      fix afterwards — and what does it do to static.
#
# That second question is why the static pass exists. An initial prompt makes the model
# likelier to emit the exact words it was given, which is the point and also the danger:
# a log entry reading "K6DRK at Cardiac", invented from hiss, is far worse than a mangled
# callsign, because it is plausible, it names real people and real places, and nobody
# would question it. The receiver no longer records silence — the gain is pinned and the
# squelch gates properly, so a quiet frequency yields no clips at all — which is correct
# behavior and removes the very thing that has to be measured. So the static pass takes
# noise on purpose, unsquelched, and asks of each arm how many clips of nothing produced
# something the log would have accepted. Zero on both arms is what clears a prompt to
# ship; one entry naming the roster is the veto.
#
# It borrows the worker's own code throughout: the same capture path, the same squelch,
# the same gap-based segmentation, the same filters, and the prompt built by the same
# Vocabulary the device holds — a lookalike would be measuring something else. What it
# does not do is post anything. Nothing here reaches the event log.
#
# The channel is stopped while this runs, because there is one dongle, and started again
# afterwards however this exits.
#
# Usage:
#   sudo /opt/transcriber/bin/compare-models.py --channel Transcriber-146700
#   sudo /opt/transcriber/bin/compare-models.py --channel <id> --clips 10 --minutes 20
#   sudo /opt/transcriber/bin/compare-models.py --channel <id> --compare prompt
#   sudo /opt/transcriber/bin/compare-models.py --channel <id> --clips 0   (static only)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import argparse
import collections
import os
import re
import select
import shutil
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import transcriber as tr  # noqa: E402

MODELS = [("Fast", "ggml-tiny.en.bin"), ("Careful", "ggml-base.en.bin")]

# While this exists, auto-update leaves the channels alone. Without it the 60-second
# config poll would start the channel again within a minute of this stopping it, and the
# two would then fight over the one dongle for the rest of the run. Same idea as the
# iGate's /tmp/sdr-usb-test.pause, and the same reason.
PAUSE = "/tmp/transcriber-bench.pause"

# How long a clip of deliberate noise should be.
#
# Long enough to be a transmission, because that is what is being imitated: an over on
# these frequencies runs ten to twenty seconds, and whisper's willingness to invent
# depends on how much nothing it is given at once. Not so long that a run takes an
# afternoon — twelve clips is two minutes of capture and, on the careful model, about two
# minutes of transcription per arm.
STATIC_SECONDS = 10.0
STATIC_CLIPS = 12

# Squelch off. Not a low threshold — the absence of one: with -l 0 rtl_fm gates nothing
# and emits at the full sample rate whatever is on the air, which on an idle frequency is
# the noise floor and nothing else. This is the one place that value is wanted, and
# SQUELCH_CANDIDATES starts at 10 precisely so calibration can never return it.
UNSQUELCHED = 0

# One arm of the comparison: a model, and whatever it is primed with.
Arm = collections.namedtuple("Arm", "name model prompt")

# What one arm made of one clip. `text` is what the log would have filed — the filters
# have already had their say — and `written` is that same text after the worker corrects
# callsigns in it, which is the distinction the prompt decision turns on.
Result = collections.namedtuple("Result", "text written took names")


def unit_for(channel_id):
    return f"transcriber@{channel_id}.service"


def systemctl(*args):
    subprocess.run(["systemctl", *args], capture_output=True)


def arms_for(mode, channel, where, model=None):
    """The two configurations to compare, which must differ in exactly one thing.

    Raises rather than returning a pair that would measure nothing: a prompt comparison
    against an event with no vocabulary is the same run twice, and it would report a
    tidy "identical on 6 of 6" that means only that nothing was tested.
    """
    if mode == "models":
        return [Arm(name, fname, None) for name, fname in MODELS]

    # Built by the worker's own Vocabulary, from the file the worker reads, so what is
    # measured is the prompt the device would actually use — down to the token budget and
    # the deliberate exclusion of the corrections' heard-forms.
    prompt = channel.vocabulary.prompt()
    if not prompt:
        raise SystemExit(
            f"there is no vocabulary in {where}, so there is nothing to prime whisper "
            f"with and both arms would be the same run twice.\n"
            f"The vocabulary arrives beside the channels, read off the event's "
            f"assignment sheet by the channel manager — see the README. Until there is "
            f"one, --compare models is the comparison this channel can make.")
    model = model or channel.model
    return [Arm("Plain", model, None), Arm("Primed", model, prompt)]


def names_from(text, vocabulary):
    """Which of the event's own words this text contains.

    The worker's correction pass runs first, because that is what decides whether a
    mangled callsign IS a callsign: "kilo six delta romeo kilo" off a hiss burst is
    K6DRK named in the log, and a check that only looked for the literal string would
    miss the entry that matters most.

    Whole words only. "Cardiac" must not be found inside a longer word, and a roster of
    short tactical calls would otherwise match half the band.
    """
    written = tr.correct_callsigns(text, vocabulary)
    haystack = " ".join(re.sub(r"[^0-9A-Za-z]+", " ", written).lower().split())
    found = []
    for term in vocabulary.callsigns + vocabulary.tactical + vocabulary.terms:
        needle = " ".join(re.sub(r"[^0-9A-Za-z]+", " ", term).lower().split())
        if not needle:
            continue
        if re.search(rf"(?<![0-9a-z]){re.escape(needle)}(?![0-9a-z])", haystack):
            found.append(term)
    return found


def run_arm(arm, whisper, models_dir, path, seconds, vocabulary):
    """One arm over one clip, judged exactly as the channel would judge it."""
    t0 = time.time()
    # clean() then loggable(): what the channel would actually file, not what whisper
    # said. The filters reject a transcription that has looped and trim a loop off the
    # end of one that has not, and a comparison that ignored that would credit an arm
    # for text the log would never have shown.
    text = tr.loggable(tr.clean(tr.transcribe(
        whisper, os.path.join(models_dir, arm.model), path, seconds, prompt=arm.prompt)))
    took = time.time() - t0
    # Correction runs after the filters here for the same reason it does in the worker:
    # they are calibrated on what whisper emits, and it has no vote in what is real.
    written = tr.correct_callsigns(text, vocabulary) if text else ""
    return Result(text, written, took, names_from(text, vocabulary) if text else [])


# ── capture ──────────────────────────────────────────────────────────────────

def capture(channel, clips_dir, spool, want, deadline):
    """Yield finished clips, segmented exactly as the worker segments them."""
    rtl = tr.start_capture(channel, clips_dir, spool)
    audio, seq, last_data = bytearray(), 0, None
    max_bytes = int(tr.MAX_CLIP_SECONDS * tr.SAMPLE_RATE) * 2
    try:
        while seq < want and time.time() < deadline:
            ready, _, _ = select.select([rtl.stdout], [], [], 0.2)
            now = time.time()
            if ready:
                chunk = os.read(rtl.stdout.fileno(), 65536)
                if chunk:
                    audio += chunk
                    last_data = now
            if audio and last_data is not None and now - last_data >= tr.GAP_SECONDS:
                seq += 1
                yield tr.write_clip(clips_dir, bytes(audio), seq)
                audio.clear()
                last_data = None
            if len(audio) >= max_bytes:
                # Exactly at the cap, keeping the remainder, as the worker does. The
                # reads overshoot, and clips measuring 120.1s rather than 120.0s is how
                # the worker came to be deleting every capped clip it made.
                seq += 1
                segment = bytes(audio[:max_bytes])
                del audio[:max_bytes]
                yield tr.write_clip(clips_dir, segment, seq, capped=True)
            if rtl.poll() is not None:
                print(f"  rtl_fm exited: {tr.rtl_complaint(clips_dir)}")
                return
    finally:
        if rtl.poll() is None:
            rtl.terminate()


def capture_noise(channel, clips_dir, seconds, want, deadline):
    """Yield clips of whatever is on the frequency with the gate wide open.

    The channel itself would never record any of these, and that is the point. There is
    no waiting for a carrier and no segmenting on gaps: with the squelch off there are no
    gaps, so the stream is simply cut into clips the length of an over.
    """
    stderr = open(os.path.join(clips_dir, tr.RTL_LOG), "wb")
    rtl = subprocess.Popen(tr.rtl_argv(channel, UNSQUELCHED),
                           stdout=subprocess.PIPE, stderr=stderr)
    want_bytes = int(seconds * tr.SAMPLE_RATE) * 2
    audio, seq = bytearray(), 0
    try:
        # Opening the device and settling the tuner produces a burst that is not this
        # site's noise floor. _sample_rtl discards the same second and a half before it
        # counts anything, for the same reason.
        warmup = time.time() + 1.5
        while time.time() < warmup:
            if select.select([rtl.stdout], [], [], 0.2)[0]:
                os.read(rtl.stdout.fileno(), 65536)
        while seq < want and time.time() < deadline:
            if select.select([rtl.stdout], [], [], 0.5)[0]:
                audio += os.read(rtl.stdout.fileno(), 65536)
            if len(audio) >= want_bytes:
                seq += 1
                segment = bytes(audio[:want_bytes])
                del audio[:want_bytes]
                yield tr.write_clip(clips_dir, segment, seq)
            if rtl.poll() is not None:
                print(f"  rtl_fm exited: {tr.rtl_complaint(clips_dir)}")
                return
    finally:
        if rtl.poll() is None:
            rtl.terminate()
        stderr.close()


# ── reporting ────────────────────────────────────────────────────────────────

def counted(n, thing, plural=None):
    """"1 callsign", "35 callsigns". Nobody trusts a report that says "1 callsigns"."""
    return f"{n} {thing if n == 1 else plural or thing + 's'}"


def traffic_lines(arms, rows):
    """The summary for the traffic pass: did the second arm change anything, and where."""
    if not rows:
        return ["Nothing was heard. That is a quiet frequency, not a verdict."]

    audio_secs = sum(r["seconds"] for r in rows) or 1.0
    first, second = arms[0].name, arms[1].name
    out = [f"{counted(len(rows), 'transmission')}, {audio_secs:.0f}s of audio", ""]
    out.append(f"   {'':<8} {'time':>7}  {'x real-time':>11}  {'logged':>6}")
    for arm in arms:
        took = sum(r[arm.name].took for r in rows)
        logged = sum(1 for r in rows if r[arm.name].text)
        out.append(f"   {arm.name:<8} {took:6.1f}s  {took / audio_secs:10.2f}x  "
                   f"{logged:3d}/{len(rows)}")

    same_raw = sum(1 for r in rows if r[first].text == r[second].text)
    same_written = sum(1 for r in rows if r[first].written == r[second].written)
    out += ["",
            f"   text identical on {same_raw} of {len(rows)}, "
            f"different on {len(rows) - same_raw}",
            f"   after the worker corrects callsigns: identical on {same_written}, "
            f"different on {len(rows) - same_written}"]
    # The crux of the prompt decision, and the reason both texts are carried. A
    # difference the correction pass closes by itself was bought for nothing: the log
    # would have read the same either way, and the prompt's risk went unpaid for.
    changed = len(rows) - same_written
    if same_raw == len(rows):
        out.append(f"   → {second} said exactly what {first} said, every time.")
    elif changed == 0:
        out.append(f"   → every difference {second} made is one correct_callsigns "
                   f"closes afterwards; the log would read the same either way.")
    else:
        out += [f"   → {counted(changed, 'entry', 'entries')} still different "
                f"after correction. That, and only that, is what",
                f"     {second} bought — read them above and decide whether it is "
                f"better or merely different."]

    # Above 1.0x an arm cannot keep up with a busy net: the log falls further behind the
    # radio with every over, which is latency rather than loss, but it compounds.
    for arm in arms:
        if sum(r[arm.name].took for r in rows) / audio_secs > 1.0:
            out.append(f"   {arm.name} is slower than real time here — on a busy net "
                       f"the log will fall progressively further behind.")
    return out


def static_lines(arms, rows, seconds, vocabulary):
    """The static pass, which answers a different question and is reported separately.

    Not "which arm is more accurate" — there is nothing on this tape to be accurate
    about. Only: how much of it reached the log, and did any of it name somebody.
    """
    if not rows:
        return ["No noise was captured, so nothing was measured. The receiver produced "
                "no samples even with the squelch off — see Transcriber Diagnostics."]

    out = [f"Static — {counted(len(rows), 'clip')} of {seconds:g}s with the squelch off, "
           f"none of which the channel itself would have recorded", ""]
    vetoed = []
    for arm in arms:
        logged = [r[arm.name] for r in rows if r[arm.name].text]
        out.append(f"   {arm.name:<8} {len(logged):2d} of {len(rows)} clips of noise "
                   f"would have been logged")
        for result in logged:
            named = f"   ← names {', '.join(result.names)}" if result.names else ""
            out.append(f"      {result.written!r}{named}")
            if result.names:
                vetoed.append((arm.name, result))
    out.append("")

    if vetoed:
        who = sorted({name for name, _ in vetoed})
        terms = sorted({t for _, r in vetoed for t in r.names})
        out += [f"   {' and '.join(who)} invented traffic from noise: "
                f"{counted(len(vetoed), 'entry', 'entries')} naming "
                f"{', '.join(terms)}.",
                "   That is the veto. An entry like that reads as authoritative, names "
                "real people and real",
                "   places, and nobody downstream has any reason to doubt it."]
    elif any(r[arm.name].text for r in rows for arm in arms):
        out += ["   Nothing named the vocabulary, but noise still reached the log. "
                "Those lines are junk rather",
                "   than fiction, and worth reading before deciding — the filters are "
                "meant to catch them."]
    elif not vocabulary:
        out += ["   Neither arm made a loggable line out of the noise. There is no "
                "vocabulary on this device,",
                "   though, so nothing could be checked against one — this run cannot "
                "clear a prompt."]
    else:
        out += ["   Neither arm made a loggable line out of the noise, and neither "
                "named anything from the",
                "   vocabulary. That is the outcome that clears the prompt to ship."]
    return out


# ── the run ──────────────────────────────────────────────────────────────────

def parser():
    p = argparse.ArgumentParser(
        description="Run two transcription configurations over the same audio.")
    p.add_argument("--channel", required=True)
    p.add_argument("--config", default=tr.CONFIG)
    p.add_argument("--compare", choices=["models", "prompt"], default="models",
                   help="what the two arms differ by: the model (default), or whether "
                        "the event vocabulary is handed to whisper as an initial prompt")
    p.add_argument("--models", default="/opt/transcriber/models")
    p.add_argument("--model", default=None,
                   help="model for both arms of --compare prompt; defaults to the one "
                        "this channel is configured to use")
    p.add_argument("--whisper", default="whisper-cli")
    p.add_argument("--clips", type=int, default=6, help="stop after this many transmissions")
    p.add_argument("--replay", nargs="?", const="", default=None,
                   help="compare over clips retention already kept, instead of "
                        "listening. Defaults to this channel's recordings directory. "
                        "Does not touch the dongle and does not delete the clips.")
    p.add_argument("--since", default=None,
                   help="with --replay, only clips whose filename sorts at or after "
                        "this (the names are timestamps, so a prefix works)")
    p.add_argument("--minutes", type=float, default=15, help="give up after this long")
    p.add_argument("--static-clips", type=int, default=STATIC_CLIPS,
                   help="clips of deliberate noise to capture afterwards; 0 to skip")
    p.add_argument("--static-seconds", type=float, default=STATIC_SECONDS)
    return p


def main(argv=None):
    args = parser().parse_args(argv)

    try:
        channel = tr.load_channel(args.config, args.channel)
    except OSError as e:
        # Absent or unreadable, which on a workstation is the ordinary case. Say which
        # file, because the answer is nearly always that this is not the device.
        raise SystemExit(f"cannot read {args.config}: {e}")
    vocabulary = channel.vocabulary
    arms = arms_for(args.compare, channel, args.config, args.model)

    whisper = shutil.which(args.whisper) or args.whisper
    for arm in arms:
        if not os.path.exists(os.path.join(args.models, arm.model)):
            raise SystemExit(f"model not found: {os.path.join(args.models, arm.model)}")

    spool = os.path.join(tr.SPOOL, channel.id)
    os.makedirs(spool, exist_ok=True)
    unit = unit_for(channel.id)

    # The gain this channel actually opens with, settled before anything is captured.
    # start_capture does this for itself, but the static pass builds its own rtl_fm
    # command — and a comparison run at a different gain from the channel it is about is a
    # comparison of a different receiver.
    channel.gain, _, _ = tr.calibration_for(channel, spool)

    print(f"Comparing {arms[0].name} and {arms[1].name} on {channel.label} "
          f"({int(channel.frequency)/1e6:.4f} MHz)")
    for arm in arms:
        print(f"   {arm.name:<8} {arm.model}"
              f"{'  + initial prompt' if arm.prompt else ''}")
    if arms[1].prompt:
        # Print it. It is the thing under test, it is built from a document somebody
        # edits, and a prompt naming the wrong event would otherwise look like a result.
        print(f"\n   {counted(len(vocabulary.callsigns), 'callsign')}, "
              f"{counted(len(vocabulary.tactical), 'tactical call')}, "
              f"{counted(len(vocabulary.terms), 'term')} from {args.config}:")
        print(f"   {arms[1].prompt}")
    elif not vocabulary:
        print(f"\n   No vocabulary in {args.config}. The static pass can still say "
              f"whether noise reached the log,\n   but not whether it named anybody.")

    # Replay needs no radio, so it takes none of the dongle machinery below: the channel
    # keeps running and keeps logging while this reads files off the card. That also
    # means no PAUSE flag and no restart-on-exit, because nothing was stopped.
    if args.replay is not None:
        rows = []
        try:
            replay_pass(args, channel, arms, whisper, vocabulary, rows)
        except KeyboardInterrupt:
            print("\nstopped — reporting what was compared so far")
        print()
        for line in traffic_lines(arms, rows):
            print(line)
        return 0

    print(f"\nStopping {unit} — there is one dongle, so the channel cannot listen "
          f"while this does.")
    with open(PAUSE, "w") as fh:
        fh.write(f"{unit}\n")
    systemctl("stop", unit)
    time.sleep(2)

    if not tr.receiver_alive(channel):
        systemctl("start", unit)
        try:
            os.unlink(PAUSE)
        except OSError:
            pass
        raise SystemExit("the receiver is not producing samples — see Transcriber "
                         "Diagnostics in the README")

    # Both passes fill a list handed to them rather than returning one, so that a run
    # stopped with Ctrl-C still reports what it heard. A six-hour run that summarized
    # nothing because somebody ended it a transmission early would be the whole cost of
    # the afternoon.
    rows, static = [], []
    try:
        try:
            traffic_pass(args, channel, arms, whisper, spool, vocabulary, rows)
        except KeyboardInterrupt:
            print("\nstopped listening for traffic — going on to the static pass "
                  "(Ctrl-C again to stop altogether)")
        try:
            static_pass(args, channel, arms, whisper, vocabulary, static)
        except KeyboardInterrupt:
            print("\nstopped")
    finally:
        try:
            os.unlink(PAUSE)
        except OSError:
            pass
        systemctl("start", unit)
        print(f"{unit} restarted.")

    if args.clips > 0:
        print()
        for line in traffic_lines(arms, rows):
            print(line)
    if args.static_clips > 0:
        print()
        for line in static_lines(arms, static, args.static_seconds, vocabulary):
            print(line)
    return 0


def traffic_pass(args, channel, arms, whisper, spool, vocabulary, rows):
    """Real transmissions, both arms, one capture each. Appends to `rows`."""
    if args.clips <= 0:
        return
    deadline = time.time() + args.minutes * 60
    with tempfile.TemporaryDirectory() as clips_dir:
        print(f"\nListening for up to {args.clips} transmissions "
              f"({args.minutes:.0f} minutes max). Ctrl-C to stop early.\n")
        for path in capture(channel, clips_dir, spool, args.clips, deadline):
            secs = tr.clip_seconds(path)
            if secs < tr.MIN_CLIP_SECONDS:
                os.unlink(path)
                continue
            print(f"── {secs:.1f}s transmission " + "─" * 40)
            row = {"seconds": secs}
            for arm in arms:
                result = run_arm(arm, whisper, args.models, path, secs, vocabulary)
                row[arm.name] = result
                mark = " " if result.text else "✗"   # ✗ = the filters would drop this
                print(f"   {arm.name:<8}{result.took:5.1f}s {mark} "
                      f"{result.text or '(nothing)'}")
                # Both texts, because the decision turns on the difference between them:
                # a prompt earns its risk only where it rescues something the correction
                # pass cannot fix after the fact.
                if result.written != result.text:
                    print(f"   {'':<8}      → {result.written}")
            if row[arms[0].name].text == row[arms[1].name].text:
                print("   → identical")
            elif row[arms[0].name].written == row[arms[1].name].written:
                print("   → identical once callsigns are corrected")
            print()
            rows.append(row)
            os.unlink(path)


def replay_pass(args, channel, arms, whisper, vocabulary, rows):
    """Both arms over clips already on the card. Appends to `rows`.

    The comparison this was built for is the live one — capture each transmission once,
    run both arms over that same file — and replay answers the same question from
    audio a channel kept earlier. It is strictly better where the audio exists: the
    arms see byte-identical input, it costs no airtime, it can be re-run after a
    filter changes, and it does not take the receiver off the air to do it.
    Retention (`record_until`) is what makes it possible.

    Two things this must not do, both of which the live path does and is right to:
    it must not touch the dongle, and it must NOT unlink the clips. They are the
    archive; deleting them would consume the evidence in the act of examining it.
    """
    d = args.replay or os.path.join(tr.SPOOL, channel.id, tr.RECORDINGS)
    if not os.path.isdir(d):
        raise SystemExit(f"no recordings directory at {d} — was record_until set?")
    clips = sorted(f for f in os.listdir(d) if f.endswith(".wav"))
    if args.since:
        clips = [f for f in clips if f >= args.since]
    if args.clips > 0:
        clips = clips[-args.clips:]          # the most recent, not the first
    if not clips:
        raise SystemExit(f"no clips to replay in {d}")

    print(f"\nReplaying {counted(len(clips), 'kept clip')} from {d}\n")
    for name in clips:
        path = os.path.join(d, name)
        secs = tr.clip_seconds(path)
        if secs < tr.MIN_CLIP_SECONDS:
            # Kept by retention on purpose, but the channel never transcribed it, so
            # neither arm has anything to be judged on.
            continue
        print(f"── {name}  {secs:.1f}s " + "─" * 30)
        row = {"seconds": secs, "file": name}
        for arm in arms:
            result = run_arm(arm, whisper, args.models, path, secs, vocabulary)
            row[arm.name] = result
            mark = " " if result.text else "✗"
            print(f"   {arm.name:<8}{result.took:5.1f}s {mark} {result.text or '(nothing)'}")
            if result.written != result.text:
                print(f"   {'':<8}      → {result.written}")
        if row[arms[0].name].text == row[arms[1].name].text:
            print("   → identical")
        elif row[arms[0].name].written == row[arms[1].name].written:
            print("   → identical once callsigns are corrected")
        print()
        rows.append(row)


def static_pass(args, channel, arms, whisper, vocabulary, rows):
    """Noise, captured on purpose, both arms. Appends to `rows`.

    Its own temporary directory, because it is its own recording: nothing from the
    traffic pass may end up counted here, and rtl_fm writes its complaints by a fixed
    name into whichever directory it is given.
    """
    if args.static_clips <= 0:
        return
    # Generous, and only a safety net — this captures a known amount of audio, so the
    # deadline exists for the case where the dongle stops delivering it.
    deadline = time.time() + args.static_clips * args.static_seconds * 3 + 60
    with tempfile.TemporaryDirectory() as clips_dir:
        print(f"\nNow capturing {args.static_clips} × {args.static_seconds:g}s of "
              f"noise with the squelch off.\nThis is not a recording of anything; the "
              f"question is what each arm makes of it.\n")
        for path in capture_noise(channel, clips_dir, args.static_seconds,
                                  args.static_clips, deadline):
            row = {}
            for arm in arms:
                result = run_arm(arm, whisper, args.models, path, args.static_seconds,
                                 vocabulary)
                row[arm.name] = result
                if result.text:
                    named = f"   ← names {', '.join(result.names)}" if result.names \
                        else ""
                    print(f"   {arm.name:<8}{result.written!r}{named}")
            if not any(row[arm.name].text for arm in arms):
                print(f"   {'':<8}(nothing from either)")
            rows.append(row)
            os.unlink(path)


if __name__ == "__main__":
    sys.exit(main())

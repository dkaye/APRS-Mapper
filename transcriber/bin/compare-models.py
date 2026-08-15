#!/usr/bin/env python3
# Compare Fast and Careful on the same audio — a bench tool, not part of the service.
#
# Two channels on two dongles would be the obvious way to compare, and it would be the
# wrong one: they hear slightly different things, so any difference in the text is
# confounded with a difference in what arrived. This captures each transmission ONCE and
# runs both models over that same file, which is the only comparison that answers the
# question actually being asked — is Careful worth the wait, on this frequency, at this
# site, for these voices.
#
# It borrows the worker's own capture path: same squelch, same gap-based segmentation,
# same filters. What it does not do is post anything. Nothing here reaches the event log.
#
# The channel is stopped while this runs, because there is one dongle, and started again
# afterwards however this exits.
#
# Usage:
#   sudo /opt/transcriber/bin/compare-models.py --channel Transcriber-146700
#   sudo /opt/transcriber/bin/compare-models.py --channel <id> --clips 10 --minutes 20
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import argparse
import os
import select
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


def unit_for(channel_id):
    return f"transcriber@{channel_id}.service"


def systemctl(*args):
    subprocess.run(["systemctl", *args], capture_output=True)


def capture(channel, clips_dir, spool, want, deadline):
    """Yield finished clips, segmented exactly as the worker segments them."""
    rtl = tr.start_capture(channel, clips_dir, spool)
    audio, seq, last_data = bytearray(), 0, None
    max_bytes = tr.MAX_CLIP_SECONDS * tr.SAMPLE_RATE * 2
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
                seq += 1
                yield tr.write_clip(clips_dir, bytes(audio), seq)
                audio.clear()
            if rtl.poll() is not None:
                print(f"  rtl_fm exited: {tr.rtl_complaint(clips_dir)}")
                return
    finally:
        if rtl.poll() is None:
            rtl.terminate()


def main():
    p = argparse.ArgumentParser(description="Run both models over the same transmissions.")
    p.add_argument("--channel", required=True)
    p.add_argument("--config", default=tr.CONFIG)
    p.add_argument("--models", default="/opt/transcriber/models")
    p.add_argument("--whisper", default="whisper-cli")
    p.add_argument("--clips", type=int, default=6, help="stop after this many transmissions")
    p.add_argument("--minutes", type=float, default=15, help="give up after this long")
    args = p.parse_args()

    channel = tr.load_channel(args.config, args.channel)
    whisper = __import__("shutil").which(args.whisper) or args.whisper
    for _, f in MODELS:
        if not os.path.exists(os.path.join(args.models, f)):
            raise SystemExit(f"model not found: {os.path.join(args.models, f)}")

    spool = os.path.join(tr.SPOOL, channel.id)
    os.makedirs(spool, exist_ok=True)
    unit = unit_for(channel.id)

    print(f"Comparing Fast and Careful on {channel.label} "
          f"({int(channel.frequency)/1e6:.4f} MHz)")
    print(f"Stopping {unit} — there is one dongle, so the channel cannot listen "
          f"while this does.")
    with open(PAUSE, "w") as fh:
        fh.write(f"{unit}\n")
    systemctl("stop", unit)
    time.sleep(2)

    if not tr.receiver_alive(channel):
        systemctl("start", unit)
        raise SystemExit("the receiver is not producing samples — see Transcriber "
                         "Diagnostics in the README")

    rows, totals = [], {name: 0.0 for name, _ in MODELS}
    deadline = time.time() + args.minutes * 60
    try:
        with tempfile.TemporaryDirectory() as clips_dir:
            print(f"Listening for up to {args.clips} transmissions "
                  f"({args.minutes:.0f} minutes max). Ctrl-C to stop early.\n")
            for path in capture(channel, clips_dir, spool, args.clips, deadline):
                secs = tr.clip_seconds(path)
                if secs < tr.MIN_CLIP_SECONDS:
                    os.unlink(path)
                    continue
                print(f"── {secs:.1f}s transmission " + "─" * 40)
                row = {"seconds": secs}
                for name, fname in MODELS:
                    t0 = time.time()
                    text = tr.clean(tr.transcribe(whisper, os.path.join(args.models, fname), path))
                    took = time.time() - t0
                    totals[name] += took
                    # What the channel would actually file, not what whisper said: the
                    # filters reject a transcription that has looped and trim a loop off
                    # the end of one that has not, and a comparison that ignored that
                    # would credit a model for text the log would never have shown.
                    kept = tr.loggable(text)
                    row[name] = (kept, took, bool(kept))
                    mark = " " if kept else "✗"   # ✗ = the filters would drop this
                    print(f"   {name:<8}{took:5.1f}s {mark} {kept or '(nothing)'}")
                # Slower is only worth it if it says something different.
                if row["Fast"][0] == row["Careful"][0]:
                    print("   → identical")
                print()
                rows.append(row)
                os.unlink(path)
    except KeyboardInterrupt:
        print("\nstopped")
    finally:
        try:
            os.unlink(PAUSE)
        except OSError:
            pass
        systemctl("start", unit)
        print(f"{unit} restarted.")

    if not rows:
        print("\nNothing was heard. That is a quiet frequency, not a verdict.")
        return 0

    audio_secs = sum(r["seconds"] for r in rows)
    print(f"\n{len(rows)} transmissions, {audio_secs:.0f}s of audio\n")
    print(f"   {'':<8} {'time':>7}  {'x real-time':>11}  {'kept':>5}")
    for name, _ in MODELS:
        kept = sum(1 for r in rows if r[name][2])
        print(f"   {name:<8} {totals[name]:6.1f}s  {totals[name]/audio_secs:10.2f}x  "
              f"{kept:3d}/{len(rows)}")
    same = sum(1 for r in rows if r["Fast"][0] == r["Careful"][0])
    print(f"\n   identical text on {same} of {len(rows)}")
    # Above 1.0x the model cannot keep up with a busy net: the log falls further behind
    # the radio with every over, which is latency rather than loss, but it compounds.
    if totals["Careful"] / audio_secs > 1.0:
        print("   Careful is slower than real time here — on a busy net the log will "
              "fall progressively further behind.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

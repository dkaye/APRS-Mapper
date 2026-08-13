#!/usr/bin/env python3
# Tests for the Transcriber channel worker.
#
# The two that matter most are the ones guarding what reaches the log. whisper does not
# stay quiet when it hears nothing — fed squelch hiss it produces "Thank you." with
# complete confidence — and a log slowly filling with invented lines is worse than one
# that misses a transmission, because nobody thinks to question it.
#
# Runs without an SDR and without whisper: rtl_fm and sox are skipped via --spool-only,
# and whisper is a stub script whose output the test chooses.
#
# Usage: python3 transcriber/tests/test_transcriber.py   (exit 0 = pass)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import json
import os
import struct
import sys
import tempfile
import threading
import wave
from http.server import BaseHTTPRequestHandler, HTTPServer

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "bin"))
import transcriber  # noqa: E402

FAILURES = []


def check(label, got, want):
    if got != want:
        FAILURES.append(f"{label}: got {got!r}, want {want!r}")
    else:
        print(f"  ok  {label}")


# ── the guard against invented speech ────────────────────────────────────────

def test_worth_logging():
    print("worth_logging")
    for junk in ["", "  ", "you", "Thank you.", "THANK YOU", "thanks for watching!",
                 "[BLANK_AUDIO]", "Bye.", "...", "uh", "you you you you",
                 # whisper describing a sound rather than reporting speech. An open
                 # squelch on a quiet frequency produces these steadily — "(water
                 # splashing)" is a real one, off a real repeater.
                 "(water splashing)", "[MUSIC]", "(engine noise)", "( silence )"]:
        check(f"discards {junk!r}", transcriber.worth_logging(junk), False)
    for real in ["aid three we have a rider down", "copy that sending medical",
                 "net control this is whiskey six sierra golf"]:
        check(f"keeps {real[:24]!r}", transcriber.worth_logging(real), True)


# ── capture must not wait for transcription ──────────────────────────────────

def test_transcription_runs_off_the_capture_thread():
    """A slow model must cost latency, not transmissions.

    While whisper ran inline, nothing drained rtl_fm's pipe — 64 KB, about two seconds
    of audio — after which rtl_fm blocks on write, stops reading the SDR, and the
    samples are gone. The careful model runs slower than real time, so it would have
    lost overs outright rather than merely lagging.

    Here the "transcription" sleeps far longer than the clips take to arrive. What
    matters is that enqueueing never blocks and everything is eventually processed.
    """
    print("capture vs transcription")
    import time as _time
    work, stopping, done = transcriber.ClipQueue(), threading.Event(), []

    def slow(channel, path, whisper, model, outbox):
        _time.sleep(0.15)                   # far slower than clips arrive
        done.append(path)

    real, transcriber.handle_clip = transcriber.handle_clip, slow
    try:
        worker = threading.Thread(target=transcriber.transcribe_loop,
                                  args=(work, None, None, None, _FakeOutbox(), stopping),
                                  daemon=True)
        worker.start()
        t0 = _time.time()
        for i in range(10):
            work.put(f"clip_{i}.wav")       # capture keeps going regardless
        enqueue_time = _time.time() - t0
        check("enqueueing 10 clips is instant", enqueue_time < 0.05, True)

        deadline = _time.time() + 10
        while len(done) < 10 and _time.time() < deadline:
            _time.sleep(0.02)
        check("all ten are transcribed", len(done), 10)
        check("and in order", done, [f"clip_{i}.wav" for i in range(10)])
        stopping.set(); worker.join(timeout=5)
    finally:
        transcriber.handle_clip = real


class _FakeOutbox:
    def flush(self, post):
        return True


# ── the backlog is in RAM now, so it has to be bounded ───────────────────────

def test_backlog_is_bounded_by_size_not_just_count():
    """Clips moved to tmpfs, which is RAM. A clip count bounds nothing on its own: at
    MAX_CLIP_SECONDS one clip is nearly 4 MB, so a hundred would be more than /run holds
    and the channel would die of ENOSPC instead of merely running behind."""
    print("clip backlog")
    with tempfile.TemporaryDirectory() as d:
        def clip(i, kb):
            path = os.path.join(d, f"clip_{i:05d}.wav")
            with open(path, "wb") as fh:
                fh.write(b"\0" * (kb * 1024))
            return path

        q = transcriber.ClipQueue(max_clips=100, max_bytes=300 * 1024)
        paths = [clip(i, 100) for i in range(5)]         # 500 KB against a 300 KB budget
        for p in paths:
            q.put(p)
        check("keeps only what fits", len(q), 3)
        check("and deletes what it dropped", os.path.exists(paths[0]), False)
        check("keeping the newest", q.get(0.1), paths[2])

        # The count limit still applies on its own, for many small clips.
        q = transcriber.ClipQueue(max_clips=3, max_bytes=1 << 30)
        small = [clip(100 + i, 1) for i in range(6)]
        for p in small:
            q.put(p)
        check("count limit still bites", len(q), 3)
        check("oldest gone", [os.path.basename(p) for p in small if os.path.exists(p)],
              ["clip_00103.wav", "clip_00104.wav", "clip_00105.wav"])

        # And a backlog that drains must free its budget again, or the queue would
        # slowly convince itself it was full and start dropping everything.
        q = transcriber.ClipQueue(max_clips=100, max_bytes=300 * 1024)
        for i in range(20):
            p = clip(200 + i, 100)
            q.put(p)
            check_quiet(q.get(0.1) == p)
        check("draining frees the budget", len(q), 0)


def check_quiet(ok):
    if not ok:
        FAILURES.append("a drained clip was dropped instead of returned")


def test_start_capture_builds_a_command_and_keeps_the_two_directories_straight():
    """start_capture was the one function no test ever called, because it needs an SDR.
    It did not need one to catch what actually shipped: a renamed parameter left a stale
    reference behind, and the channel died with a NameError on the Pi. Stubbing Popen
    exercises the whole body for the cost of six lines.

    It also pins the split that is easy to get backwards — clips and rtl_fm's chatter in
    RAM, the measured squelch on the card, where re-measuring costs a minute of deafness.
    """
    print("start_capture")
    with tempfile.TemporaryDirectory() as d:
        clips, spool = os.path.join(d, "run"), os.path.join(d, "spool")
        os.makedirs(clips), os.makedirs(spool)
        with open(os.path.join(spool, "squelch.json"), "w") as fh:
            json.dump({"squelch": 40, "when": __import__("time").time(),
                       "frequency": "147465000"}, fh)

        seen = {}

        class FakePopen:
            def __init__(self, argv, stdout=None, stderr=None):
                seen["argv"], seen["stderr"] = argv, stderr

        real, transcriber.subprocess.Popen = transcriber.subprocess.Popen, FakePopen
        try:
            channel = transcriber.Channel({"id": "rx1-147465", "frequency": "147465000",
                                           "serial": "56052444", "squelch": 0})
            transcriber.start_capture(channel, clips, spool)
        finally:
            transcriber.subprocess.Popen = real

        argv = seen["argv"]
        check("addresses the dongle by bare serial", argv[argv.index("-d") + 1], "56052444")
        check("tunes where it was told", argv[argv.index("-f") + 1], "147465000")
        # -s 200000 -r 16000, never -s 16000: the RTL2832U cannot sample that low and the
        # audio comes back mangled but plausible-looking, which whisper answers by
        # inventing something.
        check("oversamples", argv[argv.index("-s") + 1], "200000")
        check("uses the squelch measured for this site", argv[argv.index("-l") + 1], "40")
        check("rtl_fm's stderr lands in RAM, not on the card",
              os.path.dirname(seen["stderr"].name), clips)
        seen["stderr"].close()


def test_clips_go_to_ram_but_never_at_the_cost_of_listening():
    """Clips belong on tmpfs, but not so badly that a channel refuses to run without it.

    Wearing the card is a slow problem; a receiver that will not start is an immediate
    one, and the device is unattended somewhere nobody wants to drive to."""
    print("clip directory")
    with tempfile.TemporaryDirectory() as spool:
        os.environ["RUNTIME_DIRECTORY"] = os.path.join(spool, "runtime")
        try:
            check("uses systemd's tmpfs directory when given one",
                  transcriber.clip_dir("rx1-146520", fallback=spool),
                  os.path.join(spool, "runtime"))
        finally:
            del os.environ["RUNTIME_DIRECTORY"]

        # /run is root-owned, so a channel run by hand as pi lands here.
        saved, transcriber.CLIPS = transcriber.CLIPS, "/proc/definitely/not/writable"
        try:
            check("falls back to the spool rather than refusing to start",
                  transcriber.clip_dir("rx1-146520", fallback=spool), spool)
        finally:
            transcriber.CLIPS = saved

        # And a run that was killed must not leave its clips behind to be transcribed
        # again, hours later, as if they had just been heard.
        keep = os.path.join(spool, "outbox")
        os.makedirs(keep, exist_ok=True)
        open(os.path.join(spool, "clip_00001.wav"), "wb").close()
        open(os.path.join(spool, "squelch.json"), "w").close()
        transcriber.sweep_clips(spool)
        check("sweeps stale clips", os.path.exists(os.path.join(spool, "clip_00001.wav")), False)
        check("but leaves the squelch cache", os.path.exists(os.path.join(spool, "squelch.json")), True)
        check("and the outbox", os.path.isdir(keep), True)


# ── squelch calibration ──────────────────────────────────────────────────────

def site(floor, busy_at=None):
    """A fake receiver whose noise stops getting through above `floor`.

    busy_at: a level during whose FIRST sample a transmission arrives, so the
    confirmation pass is what has to catch it.
    """
    full = transcriber.SAMPLE_RATE * 2
    state = {"seen": set()}

    def sample(level, seconds):
        first = level not in state["seen"]
        state["seen"].add(level)
        if busy_at is not None and level == busy_at and first:
            return int(full * seconds)          # somebody transmitting
        return int(full * seconds) if level < floor else 0
    return sample


def test_calibration_finds_the_lowest_level_that_gates():
    """Lowest, not safest. Picking a high level would gate reliably and leave the
    receiver deaf to anything quiet — the failure nobody notices."""
    print("calibration")
    check("quiet site", transcriber.choose_squelch(site(floor=15)), 20)
    check("noisier site", transcriber.choose_squelch(site(floor=95)), 100)
    check("very quiet site", transcriber.choose_squelch(site(floor=1)), 10)


def test_calibration_is_not_fooled_by_a_transmission():
    """A transmission during the measurement looks exactly like a level that is too
    low. Without the confirmation pass it would push the answer up and quietly cost
    sensitivity for as long as the cache lasts."""
    print("calibration — someone transmits mid-measurement")
    chosen = transcriber.choose_squelch(site(floor=15, busy_at=20))
    check("still picks the right level", chosen, 20)


def test_calibration_gives_up_rather_than_guessing():
    """If nothing shuts it up, say so — the caller falls back to the default instead of
    returning a made-up number."""
    print("calibration — nothing works")
    check("returns None", transcriber.choose_squelch(site(floor=10_000)), None)


# ── where one transmission ends and the next begins ──────────────────────────

def emitter(script):
    """A fake rtl_fm. `script` is [(seconds, emit?), ...] played in real time.

    "emit?" is the whole point. rtl_fm's RF squelch gates before demodulation, so while
    it is closed the process writes NOTHING — measured on a real receiver at exactly zero
    bytes over eight seconds of idle channel. A gap in the byte stream is therefore not a
    quiet passage; it is the carrier dropping.
    """
    body = (
        "import sys, time\n"
        "w = sys.stdout.buffer\n"
        "chunk = (%d).to_bytes(2, 'little', signed=True) * %d\n"
        "for seconds, emit, level in %r:\n"
        "    end = time.time() + seconds\n"
        "    while time.time() < end:\n"
        "        if emit:\n"
        "            w.write((level).to_bytes(2, 'little', signed=True) * %d); w.flush()\n"
        "        time.sleep(0.05)\n"
    ) % (3000, transcriber.BLOCK_SAMPLES, script, transcriber.BLOCK_SAMPLES)
    return [sys.executable, "-c", body]


def capture_clips(script, seconds):
    """Run the capture loop against a fake radio; return the clips it wrote, in order."""
    import subprocess as sp, time as _time
    with tempfile.TemporaryDirectory() as tmp:
        models = os.path.join(tmp, "models"); os.makedirs(models)
        open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()
        clips = os.path.join(tmp, "clips"); os.makedirs(clips)
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1-146520", "token": "t",
                                     "frequency": "146520000", "serial": "1"}]}, fh)

        written = []
        real_put, real_capture = transcriber.ClipQueue.put, transcriber.start_capture
        transcriber.ClipQueue.put = lambda self, p: written.append(p)
        transcriber.start_capture = lambda ch, c, sp_: sp.Popen(
            emitter(script), stdout=sp.PIPE, stderr=sp.DEVNULL)
        died = []
        try:
            def run():
                try:
                    transcriber.main(["--channel", "rx1-146520", "--config", config,
                                      "--spool", tmp, "--clips", clips,
                                      "--whisper", stub_whisper(tmp, "x"),
                                      "--models", models])
                except BaseException as e:                   # noqa: BLE001
                    died.append(repr(e))
            t = threading.Thread(target=run, daemon=True); t.start()
            _time.sleep(seconds)
            if died:
                FAILURES.append("capture loop died: %s" % died[0])
        finally:
            transcriber.ClipQueue.put = real_put
            transcriber.start_capture = real_capture
        return [transcriber.clip_seconds(p) for p in written if os.path.exists(p)]


def test_a_pause_in_speech_does_not_end_the_transmission():
    """The regression that reached the air.

    An audio-level squelch ran on top of rtl_fm's, and since rtl_fm emits nothing while
    closed, the only audio it ever saw was speech — so the "noise floor" it computed was
    a speech level. It then dropped anything quieter, which meant the opening syllables
    of every over, and cut the over in two at the first pause. A station saying
    "monitoring channel, K6DRK" was logged as "ring channel K6DRK." followed by a 1.2s
    fragment whisper could make nothing of.

    So: quiet audio is still audio, and only a gap in the BYTES ends a transmission.
    """
    print("segmentation — a quiet passage mid-over")
    #        (seconds, emitting?, level)
    got = capture_clips([(1.0, True, 3000),     # speech
                         (0.6, True, 40),       # a pause — carrier still up, barely audible
                         (1.0, True, 3000),     # more speech
                         (1.5, False, 0),       # carrier drops: THIS ends it
                         (1.0, True, 3000),     # a second over
                         (1.5, False, 0)],
                        seconds=7.0)
    check("two transmissions, not four", len(got), 2)
    if len(got) == 2:
        # ~2.6s means the quiet middle survived and nothing was trimmed off the front.
        check("the first keeps its quiet middle", 2.2 <= got[0] <= 3.0, True)
        check("and the second stands alone", 0.7 <= got[1] <= 1.5, True)


def test_a_long_gap_separates_two_overs():
    print("segmentation — two overs")
    got = capture_clips([(1.0, True, 3000), (1.5, False, 0),
                         (1.0, True, 3000), (1.5, False, 0)],
                        seconds=6.0)
    check("two clips", len(got), 2)


def test_a_single_over_is_one_clip():
    print("segmentation — one over")
    got = capture_clips([(2.0, True, 3000), (1.5, False, 0)], seconds=4.5)
    check("one clip", len(got), 1)
    if got:
        check("of about the right length", 1.7 <= got[0] <= 2.4, True)


def test_clean_strips_sound_effects():
    print("clean")
    real = "(water splashing) (water splashing) K-6 DRK testing on West Marin K-6 DRK (water splashing)"
    check("keeps the speech, drops the hiss",
          transcriber.clean(real), "K-6 DRK testing on West Marin K-6 DRK")
    check("pure narration becomes nothing", transcriber.clean("(water splashing)"), "")
    check("plain speech is untouched",
          transcriber.clean("aid three we have a rider down"), "aid three we have a rider down")
    check("and nothing left is not worth logging",
          transcriber.worth_logging(transcriber.clean("[MUSIC]")), False)


# ── clip length ──────────────────────────────────────────────────────────────

def write_wav(path, seconds, rate=16000):
    with wave.open(path, "w") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(struct.pack("<h", 0) * int(rate * seconds))


def test_clip_seconds():
    print("clip_seconds")
    with tempfile.TemporaryDirectory() as d:
        p = os.path.join(d, "a.wav")
        write_wav(p, 2.0)
        check("measures duration", round(transcriber.clip_seconds(p), 1), 2.0)
        check("unreadable file is 0", transcriber.clip_seconds(os.path.join(d, "nope.wav")), 0.0)


# ── the outbox ───────────────────────────────────────────────────────────────

def clear_backoff(box):
    """Pretend the backoff window has elapsed, so a test need not sleep through it."""
    for path in box.pending():
        with open(path) as fh:
            entry = json.load(fh)
        entry["next_try"] = 0
        with open(path, "w") as fh:
            json.dump(entry, fh)


def test_outbox_order_and_retry():
    print("Outbox")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(d)
        box.add("first", 1000.0)
        box.add("second", 1001.0)
        box.add("third", 1002.0)

        sent = []

        # Second send fails: everything after it must stay put, or a later entry would
        # overtake an earlier one and the log would be out of order.
        def flaky(text):
            sent.append(text)
            return transcriber.POST_OK if text != "second" else transcriber.POST_RETRY

        check("stops at the failure", box.flush(flaky), False)
        check("sent up to the failure", sent, ["first", "second"])
        check("two still waiting", len(box.pending()), 2)

        # A failure schedules a retry in the future, so an immediate second pass must
        # not hammer the server — it should decline to send anything at all.
        sent.clear()
        check("backs off rather than retrying at once", box.flush(flaky), False)
        check("and sent nothing while backing off", sent, [])

        clear_backoff(box)
        sent.clear()
        ok = lambda t: (sent.append(t), transcriber.POST_OK)[1]
        check("drains once the server recovers", box.flush(ok), True)
        check("in order", sent, ["second", "third"])
        check("nothing left", box.pending(), [])


def test_outbox_drops_corrupt_entries():
    print("Outbox — corrupt entry")
    with tempfile.TemporaryDirectory() as d:
        box = transcriber.Outbox(d)
        with open(os.path.join(d, "1000.000000.json"), "w") as fh:
            fh.write("{not json")
        box.add("good", 1001.0)
        sent = []
        check("flushes past it", box.flush(lambda t: (sent.append(t), transcriber.POST_OK)[1]), True)
        check("kept the good one", sent, ["good"])
        check("removed the bad one", box.pending(), [])


# ── posting ──────────────────────────────────────────────────────────────────

class Handler(BaseHTTPRequestHandler):
    status = 200
    body = b'{"ok":true}'
    seen = []

    def do_POST(self):
        n = int(self.headers.get("Content-Length", 0))
        entry = json.loads(self.rfile.read(n) or b"{}")
        entry["_ua"] = self.headers.get("User-Agent", "")
        Handler.seen.append(entry)
        self.send_response(Handler.status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(Handler.body)))
        self.end_headers()
        self.wfile.write(Handler.body)

    def log_message(self, *_a):
        pass


def serve():
    srv = HTTPServer(("127.0.0.1", 0), Handler)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


def channel_for(port, token="tok-rx"):
    return transcriber.Channel({
        "id": "rx1-146520", "label": "146.520", "token": token,
        "frequency": "146520000", "serial": "00000001",
        "server": f"http://127.0.0.1:{port}",
    })


def test_posting():
    print("post_log_entry")
    srv = serve()
    port = srv.server_address[1]
    ch = channel_for(port)

    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true}'
    check("accepted", transcriber.post_log_entry(ch, "aid three clear"), transcriber.POST_OK)
    check("sent the token", Handler.seen[-1]["token"], "tok-rx")
    check("sent the text", Handler.seen[-1]["text"], "aid three clear")
    # Cloudflare blocks urllib's default agent with a 403 that looks exactly like a
    # rejected token. An explicit one is required, not cosmetic.
    check("identifies itself", Handler.seen[-1]["_ua"].startswith("MARS-Transcriber/"), True)
    check("is not the urllib default", "Python-urllib" in Handler.seen[-1]["_ua"], False)

    # 5xx is the server's problem and may pass; keep the entry and retry.
    Handler.status, Handler.body = 503, b"busy"
    check("retries a 5xx", transcriber.post_log_entry(ch, "x"), transcriber.POST_RETRY)

    # 403 is kept, not dropped. An auth failure is nearly always a token change still
    # propagating, and discarding on it lost four real transmissions the first time a
    # channel was renamed under a running device.
    Handler.status, Handler.body = 403, b"Forbidden"
    check("KEEPS a 403 to retry", transcriber.post_log_entry(ch, "x"), transcriber.POST_RETRY)

    # A malformed body stays malformed however many times it is sent.
    Handler.status, Handler.body = 400, b"Bad Request"
    check("drops a 400", transcriber.post_log_entry(ch, "x"), transcriber.POST_DROP)

    Handler.status, Handler.body = 200, b'{"error":"text required"}'
    check("drops an application error", transcriber.post_log_entry(ch, "x"), transcriber.POST_DROP)
    srv.shutdown()


def test_unreachable_server_is_retried():
    print("post_log_entry — server down")
    ch = channel_for(1)          # nothing listening on port 1
    check("retries", transcriber.post_log_entry(ch, "x", timeout=2), transcriber.POST_RETRY)


# ── the whole pipeline, no radio ─────────────────────────────────────────────

def stub_whisper(directory, says):
    """A stand-in for whisper.cpp that prints whatever the test wants it to hear."""
    path = os.path.join(directory, "whisper-stub")
    with open(path, "w") as fh:
        fh.write("#!/bin/sh\ncat <<'EOF'\n" + says + "\nEOF\n")
    os.chmod(path, 0o755)
    return path


def run_pipeline(tmp, says, clip_seconds=3.0):
    """One --spool-only pass over a single clip. Returns the entries the server got."""
    spool = os.path.join(tmp, "spool")
    os.makedirs(spool, exist_ok=True)
    clip = os.path.join(spool, "clip_001.wav")
    write_wav(clip, clip_seconds)
    # settled_clips skips anything sox might still be appending to, so backdate the
    # mtime past that window. Without this the clip is simply not picked up and every
    # assertion about the filters passes for the wrong reason.
    old = os.path.getmtime(clip) - 5
    os.utime(clip, (old, old))

    models = os.path.join(tmp, "models")
    os.makedirs(models, exist_ok=True)
    open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()

    srv = serve()
    port = srv.server_address[1]
    Handler.seen.clear()
    Handler.status, Handler.body = 200, b'{"ok":true}'

    config = os.path.join(tmp, "channels.json")
    with open(config, "w") as fh:
        json.dump({"channels": [{
            "id": "rx1-146520", "label": "146.520", "token": "tok-rx",
            "frequency": "146520000", "serial": "00000001",
            "server": f"http://127.0.0.1:{port}",
        }]}, fh)

    rc = transcriber.main([
        "--channel", "rx1-146520", "--config", config, "--spool", spool,
        "--whisper", stub_whisper(tmp, says), "--models", models,
        "--spool-only", "--once",
    ])
    srv.shutdown()
    return rc, [e["text"] for e in Handler.seen]


def test_a_clip_is_queued_once_not_once_per_loop():
    """With a radio attached, the directory scanner must never run.

    The capture loop writes each clip and queues it itself. Scanning the same directory
    queues a SECOND reference to a file that is already waiting — then a third, and a
    fourth, several times a second for as long as it sits there.

    That was invisible while transcription ran inline: the clip was unlinked before the
    next scan. With a worker thread the file waits, the duplicates pile up, and the
    backlog cap starts dropping the oldest entry — which deletes real clips nobody has
    transcribed yet. On the air it looked like the second transmission of the day simply
    never arriving, with the journal filling with FileNotFoundError from duplicates
    chasing a file the worker had already finished with.
    """
    print("one clip, one queue entry")
    with tempfile.TemporaryDirectory() as tmp:
        models = os.path.join(tmp, "models"); os.makedirs(models)
        open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()
        clips = os.path.join(tmp, "clips"); os.makedirs(clips)
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1-146520", "token": "t",
                                     "frequency": "146520000", "serial": "1"}]}, fh)

        # A radio that produces nothing. Enough to make rtl non-None, which is the whole
        # condition under test; what it emits does not matter.
        import subprocess as sp
        fake = [sys.executable, "-c", "import time; time.sleep(30)"]
        scans, queued = [], []

        def no_capture(channel, clips_dir, spool):
            return sp.Popen(fake, stdout=sp.PIPE, stderr=sp.DEVNULL)

        real_settled = transcriber.settled_clips
        real_put = transcriber.ClipQueue.put
        # Calls through to the real scanner rather than returning nothing, so the
        # "nothing is queued" check below would actually see the duplicate.
        transcriber.settled_clips = lambda d, **kw: (scans.append(d),
                                                     real_settled(d, **kw))[1]
        transcriber.ClipQueue.put = lambda self, p: queued.append(p)
        real_capture, transcriber.start_capture = transcriber.start_capture, no_capture
        died = []
        try:
            def run():
                try:
                    transcriber.main([
                        "--channel", "rx1-146520", "--config", config, "--spool", tmp,
                        "--clips", clips, "--whisper", stub_whisper(tmp, "hello"),
                        "--models", models])
                except BaseException as e:                       # noqa: BLE001
                    died.append(repr(e))

            worker = threading.Thread(target=run, daemon=True)
            worker.start()
            __import__("time").sleep(0.4)      # let it sweep and enter the loop

            # Now put a clip where the capture loop would have written one. It has to go
            # in after startup, because sweep_clips clears the directory first — placing
            # it earlier is how the previous version of this check came to be testing
            # nothing at all. Backdate it past the settle window so the scanner, if it
            # ran, would take it immediately.
            stray = os.path.join(clips, "clip_00001.wav")
            write_wav(stray, 3.0)
            old = os.path.getmtime(stray) - 5
            os.utime(stray, (old, old))
            __import__("time").sleep(1.2)      # many passes through the main loop
            # The assertions below are about something NOT happening, so they pass for
            # free if the loop never ran. The first version of this test did exactly
            # that — main() died installing signal handlers off the main thread — and
            # reported a pass against the very bug it was written for.
            check("the capture loop is actually running", (worker.is_alive(), died),
                  (True, []))
        finally:
            transcriber.settled_clips = real_settled
            transcriber.ClipQueue.put = real_put
            transcriber.start_capture = real_capture

        check("the directory is never scanned with a radio attached", scans, [])
        check("and nothing is queued from it", queued, [])


def test_pipeline_logs_speech():
    print("pipeline — a real transmission")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "aid three we have a rider down")
        check("exit 0", rc, 0)
        check("one entry", sent, ["aid three we have a rider down"])


def test_pipeline_discards_hallucination():
    print("pipeline — squelch noise")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "Thank you.")
        check("exit 0", rc, 0)
        check("nothing logged", sent, [])


def test_pipeline_discards_open_carrier():
    print("pipeline — stuck carrier")
    with tempfile.TemporaryDirectory() as tmp:
        rc, sent = run_pipeline(tmp, "aid three we have a rider down",
                                clip_seconds=transcriber.MAX_CLIP_SECONDS + 5)
        check("exit 0", rc, 0)
        check("nothing logged", sent, [])


def test_pipeline_discards_short_clip():
    print("pipeline — key-up")
    with tempfile.TemporaryDirectory() as tmp:
        # Under MIN_CLIP_SECONDS, so whisper is never even asked.
        rc, sent = run_pipeline(tmp, "aid three we have a rider down", clip_seconds=0.5)
        check("exit 0", rc, 0)
        check("nothing logged", sent, [])


def test_an_idle_frequency_is_not_fatal():
    """sox creates its output file the instant it opens one and then waits, so on a
    quiet frequency there is always a 44-byte header sitting in the spool. Reading it
    raises EOFError, not wave.Error, and an uncaught one took the channel down within
    seconds of pointing it at an idle repeater — which is what a repeater is, most of
    the time. The empty file must be left alone: sox is about to write the next
    transmission into it."""
    print("idle frequency")
    with tempfile.TemporaryDirectory() as spool:
        header_only = os.path.join(spool, "clip_001.wav")
        with wave.open(header_only, "w") as w:      # opened, no frames written
            w.setnchannels(1); w.setsampwidth(2); w.setframerate(16000)
        old = os.path.getmtime(header_only) - 5
        os.utime(header_only, (old, old))

        check("header-only file measures 0s", transcriber.clip_seconds(header_only), 0.0)
        check("and is not harvested", transcriber.settled_clips(spool), [])
        check("and is left for sox to fill", os.path.exists(header_only), True)

        # A real clip beside it still gets picked up.
        real = os.path.join(spool, "clip_002.wav")
        write_wav(real, 3.0)
        os.utime(real, (old, old))
        check("a real clip is still harvested", transcriber.settled_clips(spool), [real])


def test_a_broken_whisper_is_fatal_not_silent():
    """The failure this guards against actually happened on the first real install:
    whisper-cli was present and executable but missing libwhisper.so.1, so every
    transcription returned nothing, the filters discarded it exactly as designed, and
    the channel was indistinguishable from a quiet frequency. Refusing to start makes
    systemd mark the unit failed, which somebody notices."""
    print("broken whisper")
    with tempfile.TemporaryDirectory() as tmp:
        broken = os.path.join(tmp, "whisper-broken")
        with open(broken, "w") as fh:
            fh.write("#!/bin/sh\necho 'error while loading shared libraries' >&2\nexit 127\n")
        os.chmod(broken, 0o755)

        models = os.path.join(tmp, "models")
        os.makedirs(models, exist_ok=True)
        open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1-146520", "label": "146.520",
                                     "token": "t", "frequency": "1", "serial": "1"}]}, fh)
        try:
            transcriber.main(["--channel", "rx1-146520", "--config", config,
                              "--spool", tmp, "--whisper", broken, "--models", models,
                              "--spool-only", "--once"])
            FAILURES.append("broken whisper: expected SystemExit, got a clean start")
        except SystemExit:
            print("  ok  refuses to start rather than logging nothing forever")

    # And a missing binary entirely.
    with tempfile.TemporaryDirectory() as tmp:
        models = os.path.join(tmp, "models")
        os.makedirs(models, exist_ok=True)
        open(os.path.join(models, "ggml-tiny.en.bin"), "w").close()
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "rx1-146520", "label": "146.520",
                                     "token": "t", "frequency": "1", "serial": "1"}]}, fh)
        try:
            transcriber.main(["--channel", "rx1-146520", "--config", config,
                              "--spool", tmp, "--whisper", "/nonexistent/whisper",
                              "--models", models, "--spool-only", "--once"])
            FAILURES.append("missing whisper: expected SystemExit")
        except SystemExit:
            print("  ok  a missing binary is fatal too")


def test_disabled_channel_does_nothing():
    print("disabled channel")
    with tempfile.TemporaryDirectory() as tmp:
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": [{"id": "x@rx1", "label": "x", "enabled": False}]}, fh)
        check("exits cleanly", transcriber.main(["--channel", "x@rx1", "--config", config]), 0)


def test_unknown_channel_is_fatal():
    print("unknown channel")
    with tempfile.TemporaryDirectory() as tmp:
        config = os.path.join(tmp, "channels.json")
        with open(config, "w") as fh:
            json.dump({"channels": []}, fh)
        try:
            transcriber.main(["--channel", "nope@rx1", "--config", config])
            FAILURES.append("unknown channel: expected SystemExit")
        except SystemExit:
            print("  ok  refuses to start")


if __name__ == "__main__":
    for fn in [
        test_worth_logging, test_clean_strips_sound_effects, test_clip_seconds,
        test_transcription_runs_off_the_capture_thread,
        test_backlog_is_bounded_by_size_not_just_count,
        test_start_capture_builds_a_command_and_keeps_the_two_directories_straight,
        test_clips_go_to_ram_but_never_at_the_cost_of_listening,
        test_calibration_finds_the_lowest_level_that_gates,
        test_calibration_is_not_fooled_by_a_transmission,
        test_calibration_gives_up_rather_than_guessing,
        test_a_pause_in_speech_does_not_end_the_transmission,
        test_a_long_gap_separates_two_overs,
        test_a_single_over_is_one_clip,
        test_outbox_order_and_retry, test_outbox_drops_corrupt_entries,
        test_posting, test_unreachable_server_is_retried,
        test_a_clip_is_queued_once_not_once_per_loop,
        test_pipeline_logs_speech, test_pipeline_discards_hallucination,
        test_pipeline_discards_short_clip, test_pipeline_discards_open_carrier,
        test_an_idle_frequency_is_not_fatal,
        test_a_broken_whisper_is_fatal_not_silent,
        test_disabled_channel_does_nothing, test_unknown_channel_is_fatal,
    ]:
        fn()
    if FAILURES:
        print("\nFAILED:")
        for f in FAILURES:
            print("  " + f)
        sys.exit(1)
    print("\nall passed")

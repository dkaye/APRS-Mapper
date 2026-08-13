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
        Handler.seen.append(json.loads(self.rfile.read(n) or b"{}"))
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
        test_worth_logging, test_clip_seconds,
        test_outbox_order_and_retry, test_outbox_drops_corrupt_entries,
        test_posting, test_unreachable_server_is_retried,
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

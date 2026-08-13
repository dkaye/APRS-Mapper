#!/usr/bin/env python3
# Transcriber channel worker — listens on one frequency and writes what it hears
# into the event log.
#
# One process per channel, started by systemd as transcriber@<channel-id>.service.
# Several run side by side on one Pi, each bound to its own SDR dongle by USB serial.
#
#   rtl_fm -d <serial> -f <freq> -M fm -l <squelch>   → samples only while the
#                                                        squelch is open
#      → gaps in that stream are the transmission boundaries
#      → whisper.cpp                                   → text
#      → POST index.php?messaging=log                  → "146.520 → Log"
#
# Stdlib only, like isproxy.py — nothing to install and nothing to break on a
# distribution upgrade.
#
# Usage:
#   transcriber.py --channel rx1-146520
#   transcriber.py --channel rx1-146520 --spool-only DIR   (no radio; see below)
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2026 Doug Kaye, K6DRK <doug@rds.com>

import argparse
import json
import logging
import os
import re
import select
import shutil
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request
import wave

# The Transcriber's own line, independent of the server/app version and of the iGates'
# 5.x — it is a separate device on its own cadence, and it is new. See Versioning in the
# README. auto-update.sh copies this to /etc/transcriber/version so a device can be
# identified without running anything.
VERSION = "1.0"

CONFIG = "/etc/transcriber/channels.json"
SPOOL = "/var/spool/transcriber"
SERVER = "https://marsaprs.org"

# A transmission shorter than this is a squelch tail, a key-up, or someone knocking
# their PTT — never words worth logging, and exactly what whisper invents speech from.
MIN_CLIP_SECONDS = 1.2
# Longer than this and it is almost certainly an open carrier rather than a
# transmission; transcribe what we have rather than growing a file forever.
MAX_CLIP_SECONDS = 120

# whisper does not return nothing when it hears nothing. Fed static or silence it
# produces these with complete confidence, and a log quietly filling with "Thank you."
# is a worse failure than one that misses a transmission, because nobody questions it.
HALLUCINATIONS = {
    "", "you", "thank you", "thank you.", "thanks for watching",
    "thanks for watching!", "bye", "bye.", "[blank_audio]", "(silence)",
    "[ silence ]", "so", "so.", "uh", "um", ".", "...",
}

log = logging.getLogger("transcriber")


# ── configuration ────────────────────────────────────────────────────────────

class Channel:
    """One frequency on one device, as the channel manager defined it."""

    def __init__(self, d):
        self.id = d["id"]
        self.label = d.get("label") or d["id"]
        self.token = d.get("token") or ""
        self.frequency = str(d.get("frequency") or "")
        self.serial = str(d.get("serial") or "")
        self.squelch = int(d.get("squelch") or 0)
        self.model = d.get("model") or "ggml-tiny.en.bin"
        self.enabled = bool(d.get("enabled", True))
        self.server = (d.get("server") or SERVER).rstrip("/")


def load_channel(path, channel_id):
    with open(path, encoding="utf-8") as fh:
        raw = json.load(fh)
    for entry in raw.get("channels", []):
        if entry.get("id") == channel_id:
            return Channel(entry)
    raise SystemExit(f"channel {channel_id!r} is not in {path}")


# ── the outbox ───────────────────────────────────────────────────────────────

class Outbox:
    """Entries the server has not accepted yet.

    A transmission heard and then dropped because WiFi blinked is indistinguishable,
    afterwards, from one that never happened — so entries wait on disk and are retried
    in order. The same reasoning as the watch's Outbox, and the same conclusion.
    """

    def __init__(self, directory):
        self.dir = directory
        os.makedirs(self.dir, exist_ok=True)

    # Retrying forever must not fill the disk. At roughly a transmission every few
    # seconds this is hours of backlog, far longer than any outage worth surviving.
    cap = 500

    def add(self, text, ts):
        # Timestamp-named so the flush order is the order things were said.
        path = os.path.join(self.dir, f"{ts:.6f}.json")
        with open(path, "w", encoding="utf-8") as fh:
            json.dump({"text": text, "ts": ts, "attempts": 0, "next_try": 0}, fh)
        waiting = self.pending()
        for stale in waiting[:max(0, len(waiting) - self.cap)]:
            log.error("outbox full; dropping the oldest entry")
            os.unlink(stale)

    def pending(self):
        return sorted(
            os.path.join(self.dir, f) for f in os.listdir(self.dir) if f.endswith(".json")
        )

    def flush(self, post):
        """Send what is waiting, oldest first. Stops at the first entry that could not
        go, so ordering survives an outage — a later entry must not overtake an earlier
        one, and a log out of order is worse than a log that arrives late."""
        now = time.time()
        for path in self.pending():
            try:
                with open(path, encoding="utf-8") as fh:
                    entry = json.load(fh)
            except (OSError, ValueError):
                os.unlink(path)          # unreadable: nothing to retry forever over
                continue

            if entry.get("next_try", 0) > now:
                return False             # backing off; later entries wait their turn

            result = post(entry["text"])
            if result == POST_OK or result == POST_DROP:
                os.unlink(path)
                continue

            # Back off so a wrong token or a dead server is not hammered every half
            # second for however long it takes somebody to notice, while a brief blip
            # still clears on the next pass.
            entry["attempts"] = entry.get("attempts", 0) + 1
            entry["next_try"] = now + min(60, 2 ** entry["attempts"])
            try:
                with open(path, "w", encoding="utf-8") as fh:
                    json.dump(entry, fh)
            except OSError:
                pass
            return False
        return True


# ── posting ──────────────────────────────────────────────────────────────────

# What to do with an entry after an attempt to post it.
POST_OK, POST_RETRY, POST_DROP = "ok", "retry", "drop"


def post_log_entry(channel, text, timeout=15):
    """One log entry. POST_OK, POST_RETRY or POST_DROP."""
    body = json.dumps({"token": channel.token, "text": text}).encode()
    req = urllib.request.Request(
        f"{channel.server}/index.php?messaging=log",
        data=body,
        # Cloudflare blocks urllib's default User-Agent outright — "error code: 1010",
        # its bot fingerprint rule — and the 403 that produces is indistinguishable
        # from a rejected token. That cost hours: the same token posted fine from curl
        # and was refused from the device, which read as an intermittent auth fault
        # rather than the client being banned for what it was.
        #
        # Any explicit agent satisfies it, so this one says what it actually is rather
        # than impersonating a browser.
        headers={"Content-Type": "application/json",
                 "User-Agent": f"MARS-Transcriber/{VERSION} (+https://marsaprs.org)"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            answer = json.loads(resp.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        # An auth failure is nearly always a token change still propagating — a channel
        # renamed or a token reissued in the manager, with this device a config poll
        # behind. That resolves itself, so the entry is kept and retried with backoff.
        # Discarding on 403 lost four real transmissions the first time this happened.
        if e.code in (401, 403):
            log.warning("server rejected our token (%s); keeping the entry to retry", e.code)
            return POST_RETRY
        # Other 4xx really are our fault and will not fix themselves — a malformed or
        # over-long body stays malformed however many times it is sent.
        if 400 <= e.code < 500:
            log.error("server refused the entry (%s); discarding: %s", e.code, text[:60])
            return POST_DROP
        log.warning("server error %s; will retry", e.code)
        return POST_RETRY
    except (urllib.error.URLError, OSError, ValueError) as e:
        log.warning("post failed (%s); will retry", e)
        return POST_RETRY
    if answer.get("error"):
        log.error("server refused the entry (%s); discarding", answer["error"])
        return POST_DROP
    return POST_OK


# ── transcription ────────────────────────────────────────────────────────────

def clip_seconds(path):
    # EOFError as well as wave.Error: a file containing only a WAV header — which is
    # what sox leaves while it waits for audio — raises EOFError rather than wave.Error,
    # and an uncaught one took the whole channel down on the first idle frequency.
    try:
        with wave.open(path) as w:
            return w.getnframes() / float(w.getframerate() or 1)
    except (wave.Error, OSError, EOFError):
        return 0.0


def transcribe(binary, model, path):
    """Text for one clip, or '' if there is nothing worth saying."""
    out = subprocess.run(
        [binary, "-m", model, "-f", path, "--no-timestamps", "--no-prints",
         "--language", "en", "--threads", str(max(1, (os.cpu_count() or 2) - 1))],
        capture_output=True, text=True, timeout=300,
    )
    if out.returncode != 0:
        log.error("whisper failed: %s", (out.stderr or "").strip()[:200])
        return ""
    return " ".join(out.stdout.split()).strip()


def clean(text):
    """Drop whisper's bracketed sound descriptions, keeping the speech around them.

    A transmission usually arrives with hiss either side of it, and whisper narrates
    that hiss: a real recording came back as "(water splashing) (water splashing) K-6
    DRK testing on West Marin K-6 DRK (water splashing)". The callsign and the message
    are in there and worth keeping; the rest is the squelch tail described in words.
    """
    return " ".join(re.sub(r"[\(\[\{][^\)\]\}]*[\)\]\}]", " ", text).split()).strip()


def worth_logging(text):
    """Whether this is speech rather than whisper's imagination.

    Deliberately conservative. Losing a genuine transmission costs the log one line;
    admitting invented ones costs it credibility, and an operator who stops trusting
    the log stops reading it.
    """
    if not text:
        return False
    # Anything wholly inside brackets is whisper describing a sound rather than
    # reporting speech — "(water splashing)", "[MUSIC]", "(engine noise)". There is no
    # useful list of these to keep; the shape is the signal. An open squelch on a quiet
    # frequency produces them steadily.
    if re.fullmatch(r"[\(\[\{].*[\)\]\}]", text.strip(), re.S):
        return False
    bare = re.sub(r"[^\w\s]", "", text).strip().lower()
    if bare in HALLUCINATIONS or text.strip().lower() in HALLUCINATIONS:
        return False
    if len(bare) < 3:
        return False
    # "you you you you" and friends — whisper looping on noise.
    words = bare.split()
    if len(words) >= 4 and len(set(words)) == 1:
        return False
    return True


# ── capture ──────────────────────────────────────────────────────────────────

SAMPLE_RATE = 16000          # what whisper wants; rtl_fm can produce it directly
GAP_SECONDS = 0.8            # squelch closed this long ends a transmission


def write_clip(spool, audio, seq):
    """One transmission, as a wav whisper can read."""
    path = os.path.join(spool, f"clip_{seq:05d}.wav")
    with wave.open(path, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SAMPLE_RATE)
        w.writeframes(audio)
    return path


def start_capture(channel, spool):
    """rtl_fm, squelched, writing raw 16 kHz samples for the main loop to segment.

    The dongle is addressed by USB SERIAL, never by index: index order is not stable
    across reboots or re-plugs, and two channels silently swapping frequencies is the
    kind of fault nobody notices until the log is wrong.
    """
    # "-d <serial>", not "-d serial=<serial>". rtl_fm's verbose_device_search tries the
    # argument as an index, then as an exact serial, then as a prefix — the SoapySDR
    # "serial=" form is not one of them, and it fails in the worst possible way: the
    # device is listed and then not selected, so rtl_fm exits without ever tuning and
    # the channel looks like a dead frequency.
    # Oversample and resample: "-s 200000 -r 24000", never "-s 24000" directly.
    #
    # The RTL2832U cannot sample below about 225 kHz, so asking for 24 kHz makes rtl_fm
    # decimate internally and the audio comes out mangled. It is not obviously broken to
    # look at — the recording had a healthy 0.10 RMS and a clean waveform — but it is
    # unintelligible, and whisper answers unintelligible audio by inventing something.
    # On a 30-second recording of a station reading out temperatures it produced
    # "(I'm not a fan)" and nothing else. The same 30 seconds captured this way
    # transcribed every place name and number correctly.
    #
    # -E deemp applies FM de-emphasis, which voice needs and without which the high end
    # is harsh enough to cost accuracy.
    # Gate on SIGNAL STRENGTH, not audio level, and cut clips on gaps in the data
    # rather than on quiet passages in the audio.
    #
    # The first version piped rtl_fm into sox and split on silence. That cannot work on
    # an un-squelched FM receiver: idle hiss and speech sit at similar audio levels
    # (measured, on a real repeater: hiss at 1.2% of full scale), so an amplitude gate
    # either treats hiss as sound or never opens at all. Sweeping the threshold showed
    # a usable window only between 0.5% and 1%, and even inside it the transmission was
    # not cleanly separated — 90 seconds containing a clear callsign split into an 80s
    # clip of hiss and a 3.8s clip of hiss.
    #
    # Squelch is the mechanism radios use for exactly this, and it works on received
    # power. With -l set, rtl_fm emits NOTHING while closed rather than emitting silence
    # — which is why sox could never cut on it either — so the gaps in the byte stream
    # are the transmission boundaries, and reading the stream directly is both simpler
    # and correct. -r 16000 gives whisper its rate with no resampling, so sox leaves the
    # capture path entirely.
    return subprocess.Popen(
        ["rtl_fm", "-d", channel.serial, "-f", channel.frequency,
         "-M", "fm", "-s", "200000", "-r", str(SAMPLE_RATE), "-E", "deemp",
         "-l", str(channel.squelch or 25), "-g", "40"],
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
    )


def settled_clips(spool, quiet_for=1.0):
    """Wavs already on disk, for --spool-only.

    Only the test and bench paths use this now — with a radio attached the main loop
    segments the stream itself and writes finished clips directly. A clip counts as
    complete once it has stopped growing, and empty ones are left alone.
    """
    now = time.time()
    out = []
    for name in sorted(os.listdir(spool)):
        if not name.endswith(".wav"):
            continue
        path = os.path.join(spool, name)
        try:
            # A bare WAV header and nothing else. sox creates its next output file the
            # moment it opens one and then waits, so on an idle frequency — which is
            # most frequencies most of the time — there is always exactly one of these
            # sitting in the spool. It is not a finished clip; it is the one sox is
            # about to write into, and taking it would mean deleting the recording of
            # the next transmission before it happened.
            if os.path.getsize(path) <= 44:
                continue
            if now - os.path.getmtime(path) >= quiet_for:
                out.append(path)
        except OSError:
            pass
    return out


# ── main loop ────────────────────────────────────────────────────────────────

def handle_clip(channel, path, whisper, model, outbox):
    seconds = clip_seconds(path)
    if seconds < MIN_CLIP_SECONDS:
        log.debug("ignoring %.1fs clip", seconds)
        os.unlink(path)
        return
    if seconds > MAX_CLIP_SECONDS:
        # A stuck or open carrier, not an over. Transcribing it would occupy the
        # channel for minutes and queue every real transmission behind it, to
        # produce a paragraph of noise nobody wants in the log.
        log.warning("discarding %.0fs clip — open carrier?", seconds)
        os.unlink(path)
        return
    text = clean(transcribe(whisper, model, path))
    os.unlink(path)
    if not worth_logging(text):
        log.info("discarded (%.1fs): %r", seconds, text[:60])
        return
    log.info("logging (%.1fs): %s", seconds, text[:80])
    outbox.add(text, time.time())


def main(argv=None):
    p = argparse.ArgumentParser(description="Transcribe one radio channel into the event log.")
    p.add_argument("--channel", required=True, help="channel id, e.g. rx1-146520")
    p.add_argument("--config", default=CONFIG)
    p.add_argument("--spool", default=None, help="defaults to %s/<channel>" % SPOOL)
    p.add_argument("--whisper", default="whisper-cli", help="whisper.cpp binary")
    p.add_argument("--models", default="/opt/transcriber/models")
    p.add_argument("--spool-only", action="store_true",
                   help="do not open the radio; transcribe wavs already in the spool. "
                        "This is how the pipeline is tested without an SDR.")
    p.add_argument("--once", action="store_true", help="process what is waiting, then exit")
    p.add_argument("-v", "--verbose", action="store_true")
    p.add_argument("--version", action="version", version=f"transcriber {VERSION}")
    args = p.parse_args(argv)

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
    )

    channel = load_channel(args.config, args.channel)
    if not channel.enabled:
        log.info("channel %s is disabled; nothing to do", channel.id)
        return 0

    spool = args.spool or os.path.join(SPOOL, re.sub(r"[^\w.-]", "_", channel.id))
    os.makedirs(spool, exist_ok=True)
    outbox = Outbox(os.path.join(spool, "outbox"))

    whisper = shutil.which(args.whisper) or args.whisper
    model = os.path.join(args.models, channel.model)
    if not os.path.exists(model):
        raise SystemExit(f"model not found: {model}")

    # Prove whisper actually runs before listening to anything.
    #
    # Without this a broken whisper is invisible: transcribe() returns "", the filters
    # discard it as they should, and the channel looks like a quiet frequency for as
    # long as nobody checks. That is precisely what a missing libwhisper.so.1 did on the
    # first real install — the binary was there and executable, and every transmission
    # went into the void. Failing at startup makes systemd mark the unit failed, which
    # is a state somebody notices.
    try:
        probe = subprocess.run([whisper, "-h"], capture_output=True, text=True, timeout=30)
    except (OSError, subprocess.SubprocessError) as e:
        raise SystemExit(f"cannot run {whisper}: {e}")
    if probe.returncode != 0 and "usage" not in (probe.stdout + probe.stderr).lower():
        raise SystemExit(f"{whisper} is not usable: "
                         f"{(probe.stderr or probe.stdout).strip()[:200]}")

    rtl = None
    if not args.spool_only:
        rtl = start_capture(channel, spool)
        # Version first, so `journalctl -u transcriber@… ` answers "what is this running"
        # without anyone having to go and look.
        log.info("transcriber %s — listening on %s (%s), dongle %s, model %s",
                 VERSION, channel.frequency, channel.label, channel.serial, channel.model)

    running = True

    def stop(_signum, _frame):
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    audio = bytearray()          # the transmission currently being received
    last_data = time.time()
    seq = 0
    max_bytes = MAX_CLIP_SECONDS * SAMPLE_RATE * 2

    try:
        while running:
            if rtl is not None:
                # Squelch closed means no bytes at all, so the read simply times out.
                # That timeout IS the end-of-transmission signal.
                ready, _, _ = select.select([rtl.stdout], [], [], 0.2)
                if ready:
                    chunk = os.read(rtl.stdout.fileno(), 65536)
                    if chunk:
                        audio += chunk
                        last_data = time.time()

                gap = time.time() - last_data
                if audio and (gap >= GAP_SECONDS or len(audio) >= max_bytes):
                    seq += 1
                    path = write_clip(spool, bytes(audio), seq)
                    audio.clear()
                    handle_clip(channel, path, whisper, model, outbox)

            # --spool-only: wavs are already on disk, put there by a test or by hand.
            for path in settled_clips(spool):
                handle_clip(channel, path, whisper, model, outbox)

            outbox.flush(lambda t: post_log_entry(channel, t))
            if args.once:
                break
            # A dead radio must not look like a quiet frequency. systemd restarts us,
            # and a failed unit is a state somebody notices.
            if rtl is not None and rtl.poll() is not None:
                log.error("rtl_fm exited (%s)", rtl.returncode)
                return 1
            if rtl is None:
                time.sleep(0.5)
    finally:
        if rtl is not None and rtl.poll() is None:
            rtl.terminate()
    return 0


if __name__ == "__main__":
    sys.exit(main())

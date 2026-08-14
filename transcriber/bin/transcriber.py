#!/usr/bin/env python3
# Transcriber channel worker — listens on one frequency and writes what it hears
# into the event log.
#
# One process per channel, started by systemd as transcriber@<channel-id>.service.
# Several run side by side on one Pi, each bound to its own SDR dongle by USB serial.
#
#   rtl_fm -d <serial> -f <freq> -M fm -l <squelch>
#      → RF squelch decides whether anything is transmitting: it gates on received
#        power, before demodulation, which is the only reliable question to ask —
#        FM noise is loudest precisely when there is no carrier
#      → gaps in the byte stream are the boundaries between overs: rtl_fm emits
#        nothing at all while squelched, so samples stopping IS the carrier dropping
#      → whisper.cpp                        → text
#      → POST index.php?messaging=log       → "146.520 → Log"
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
import collections
import json
import logging
import os
import re
import select
import shutil
import signal
import subprocess
import sys
import threading
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
# What must survive a reboot lives on the card: the outbox, so a transmission heard
# during an outage is not lost, and the measured squelch, so a restart is listening
# again in seconds rather than deaf for a minute while it re-measures.
SPOOL = "/var/spool/transcriber"
# What must not touch the card lives in RAM. See clip_dir().
CLIPS = "/run/transcriber"
RTL_LOG = "rtl_fm.err"
SERVER = "https://marsaprs.org"

# rtl_fm's RF squelch — the gate that decides whether anything is transmitting at all.
# Measured working on a real repeater. A site that needs a different value can set one
# per channel in the manager.
DEFAULT_SQUELCH = 25

# A transmission shorter than this is a squelch tail, a key-up, or someone knocking
# their PTT — never words worth logging, and exactly what whisper invents speech from.
MIN_CLIP_SECONDS = 1.2
# Longer than this and it is almost certainly an open carrier rather than a
# transmission; transcribe what we have rather than growing a file forever.
MAX_CLIP_SECONDS = 120
# How far transcription may fall behind before clips start being dropped. At a
# transmission every few seconds this is minutes of backlog — far more than the careful
# model needs to catch up between overs.
#
# Two limits, because the backlog is held in RAM. A count alone does not bound anything:
# a clip runs to MAX_CLIP_SECONDS, so a hundred of them is nearly 400 MB, and /run is
# smaller than that. Whichever limit is reached first, the oldest clips go — they are the
# least worth keeping, and by then the log is minutes behind the radio anyway.
CLIP_BACKLOG_CAP = 100
CLIP_BACKLOG_BYTES = 128 * 1024 * 1024

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
        # Tuner gain in dB, or None for rtl_fm's automatic. Automatic by default: a
        # fixed 40 was hardcoded here, which is near this tuner's maximum and overloads
        # the front end anywhere with a strong signal nearby.
        self.gain = d.get("gain")
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

    Asterisks and music notes as well as brackets. whisper has more than one house style
    for this and only the bracketed ones were being caught, so a squelch crash arrived in
    the event log as "*BANG*" and a burst of static as "*gunshot*" — which is a worse
    entry than a wrong one, because it reads like something happened.
    """
    stripped = re.sub(r"[\(\[\{][^\)\]\}]*[\)\]\}]", " ", text)
    stripped = re.sub(r"\*[^*]*\*", " ", stripped)
    stripped = re.sub(r"♪[^♪]*♪", " ", stripped)
    return " ".join(stripped.split()).strip()


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
    if re.fullmatch(r"[\(\[\{].*[\)\]\}]|\*.*\*|♪.*♪", text.strip(), re.S):
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

# How long the data has to stop before a transmission counts as over.
#
# The gap is the only signal used, and it is a reliable one: rtl_fm's RF squelch gates
# before demodulation, so while it is closed the process emits nothing at all — measured,
# on a real receiver, at exactly zero bytes over eight seconds of idle channel. Samples
# arriving means a carrier is up; samples stopping means it dropped.
#
# Long enough to ride out a squelch flicker on a fading signal, short enough that two
# overs a second apart do not merge into one entry.
GAP_SECONDS = 0.8

# How often to say whether anything has been heard.
REPORT_SECONDS = 1800

# How long total silence may last before the receiver itself is re-checked.
#
# A closed squelch and a wedged tuner produce exactly the same thing — nothing — so
# silence alone proves neither. After this long with no samples at all, the channel
# restarts, which re-runs the liveness probe below and turns the question into an answer.
# An hour, because on a quiet frequency the cost is a three-second gap once an hour, and
# on a dead receiver it is the difference between finding out today and finding out when
# somebody asks why the log is empty.
DEAF_CHECK_SECONDS = 3600

BLOCK_MS = 50                                        # granularity of the read loop
BLOCK_SAMPLES = SAMPLE_RATE * BLOCK_MS // 1000
BLOCK_BYTES = BLOCK_SAMPLES * 2


def clip_dir(channel_id, override=None, fallback=None):
    """Where transmissions are held between capture and transcription — RAM, not the card.

    A clip is written once, read once by whisper, and deleted. At 16 kHz mono that is
    32 KB per second of audio, so a busy net writes gigabytes a day to a card with a
    finite number of erase cycles, sitting in a box somewhere nobody wants to drive to.
    None of it is worth keeping: the text goes to the server and the audio is discarded
    either way. It is also the one file here whose speed matters, since whisper reads the
    whole thing back the moment it is written.

    So clips live on tmpfs. Under systemd that is RuntimeDirectory — /run/transcriber/<id>,
    created with the right ownership and, more usefully, emptied when the unit stops.
    Clips used to leak on every kill; now they cannot outlive the process that made them.

    Run by hand we make our own, and if /run is not writable we fall back to the spool
    with a warning rather than refusing to listen. Wearing the card is worth more than
    silence.
    """
    if override:
        return override
    runtime = os.environ.get("RUNTIME_DIRECTORY", "").split(":")[0]
    target = runtime or os.path.join(CLIPS, re.sub(r"[^\w.-]", "_", channel_id))
    try:
        os.makedirs(target, exist_ok=True)
        return target
    except OSError as e:
        if not fallback:
            raise
        log.warning("cannot use %s for clips (%s); falling back to %s", target, e, fallback)
        return fallback


def rtl_complaint(clips, limit=300):
    """The last thing rtl_fm said before it died, for the journal.

    It is verbose while running and the useful line is always the last one, so only the
    tail is worth reporting — "No supported devices found." is the whole diagnosis when a
    dongle has fallen off the bus, and it is what tells a deaf channel apart from a
    missing one.
    """
    try:
        with open(os.path.join(clips, RTL_LOG), "rb") as fh:
            fh.seek(0, os.SEEK_END)
            fh.seek(max(0, fh.tell() - limit * 4))
            tail = fh.read().decode("utf-8", "replace")
    except OSError:
        return "no output"
    lines = [ln.strip() for ln in tail.splitlines() if ln.strip()]
    return " / ".join(lines[-3:])[-limit:] or "no output"


def sweep_clips(directory):
    """Clear anything left from a previous run.

    RuntimeDirectory already does this for us under systemd, which is most of the point
    of using it. This covers the rest: a run by hand, a --clips override, and the empty
    44-byte headers that used to accumulate one per killed process.
    """
    for name in os.listdir(directory):
        if name.endswith(".wav"):
            try:
                os.unlink(os.path.join(directory, name))
            except OSError:
                pass


def write_clip(directory, audio, seq):
    """One transmission, as a wav whisper can read."""
    path = os.path.join(directory, f"clip_{seq:05d}.wav")
    with wave.open(path, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SAMPLE_RATE)
        w.writeframes(audio)
    return path


# Calibration. Ascending, so the winner is the LOWEST level that shuts out this site's
# noise — the most sensitive setting that still gates, rather than a safe-but-deaf one.
# The step is the margin: landing on 30 means 20 let noise through, so the true edge is
# between them and there is up to a step of headroom against drift.
# From 10, not 0. Zero is not a squelch level — it is the absence of one, and rtl_fm
# with -l 0 gates nothing at all. It was in this list, and a measurement returned it.
SQUELCH_CANDIDATES = list(range(10, 201, 10))
QUIET_FRACTION = 0.05        # under 5% of the full sample rate counts as "shut"
CALIBRATION_MAX_AGE = 86400  # re-measure daily; a site does not change hour to hour


def choose_squelch(sample, candidates=SQUELCH_CANDIDATES, quiet=QUIET_FRACTION):
    """Lowest squelch level at which an idle channel goes quiet.

    `sample(level, seconds) -> bytes observed`, injected so this can be tested without
    a radio.

    Two passes at each candidate. rtl_fm only emits samples while its squelch is open,
    so "no bytes" means "nothing is getting through" — but a transmission arriving
    mid-measurement looks identical to a level that is too low, and would push the
    answer upwards, leaving the receiver deaf to anything quieter. Confirming with a
    longer second look costs a few seconds and makes that need two coincidences rather
    than one.
    """
    expected = SAMPLE_RATE * 2

    def shut(level):
        # Twice, because a level that looks quiet for two seconds and is not would be
        # cached for a day.
        return (sample(level, 2.0) < expected * 2.0 * quiet
                and sample(level, 3.0) < expected * 3.0 * quiet)

    # Is the receiver producing anything at all? With the squelch off, rtl_fm cannot gate
    # and must emit at the full rate; if it does not, it is not running — the dongle is
    # busy, or has fallen off the USB bus, and every sample below will read as silence.
    #
    # Without this check that silence is indistinguishable from a beautifully quiet site,
    # and the scan walks the answer down to the lowest candidate. It happened: a restart
    # raced rtl_fm's release of the dongle, every sample came back empty, and the measured
    # answer was 0 — no gating whatsoever, so the channel then recorded continuous hiss
    # and filed a steady stream of clips that whisper had nothing to say about.
    if shut(0):
        log.warning("receiver produced no audio even with the squelch off — "
                    "not measuring against a dead input")
        return None

    for i, level in enumerate(candidates):
        if not shut(level):
            continue
        # Walk back down. A transmission during an earlier sample is indistinguishable
        # from a level that was too low, so it pushes the scan past the right answer —
        # and the result is a receiver that gates reliably and hears less than it could,
        # which nobody would notice. Stepping down while the level below also proves
        # quiet undoes that, and costs nothing when the scan was clean.
        while i > 0 and shut(candidates[i - 1]):
            i -= 1
        return candidates[i]
    return None


def _sample_rtl(channel, level, seconds):
    """Bytes rtl_fm emits at this squelch level over `seconds`."""
    p = subprocess.Popen(
        ["rtl_fm", "-d", channel.serial, "-f", channel.frequency,
         "-M", "fm", "-s", "200000", "-r", str(SAMPLE_RATE), "-E", "deemp", "-l", str(level)]
        + ([] if channel.gain is None else ["-g", str(channel.gain)]),
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        # Opening the device and settling the tuner produces a burst that says nothing
        # about the noise floor. Discard it before counting.
        warmup = time.time() + 1.5
        while time.time() < warmup:
            if select.select([p.stdout], [], [], 0.2)[0]:
                os.read(p.stdout.fileno(), 65536)
        total = 0
        deadline = time.time() + seconds
        while time.time() < deadline:
            if select.select([p.stdout], [], [], 0.2)[0]:
                total += len(os.read(p.stdout.fileno(), 65536))
        return total
    finally:
        p.terminate()
        try:
            p.wait(timeout=3)
        except subprocess.TimeoutExpired:
            p.kill()


def calibrated_squelch(channel, spool):
    """The squelch level to use, measured if need be and remembered afterwards.

    Measuring takes the better part of a minute and holds the dongle, so the answer is
    cached: a site's noise floor is a property of where the receiver is, not of when it
    was last restarted, and a channel that restarts should be listening again in seconds.
    """
    cache = os.path.join(spool, "squelch.json")
    try:
        with open(cache, encoding="utf-8") as fh:
            saved = json.load(fh)
        if time.time() - saved["when"] < CALIBRATION_MAX_AGE:
            log.info("squelch %s (measured %.1f hours ago)",
                     saved["squelch"], (time.time() - saved["when"]) / 3600)
            return saved["squelch"]
    except (OSError, ValueError, KeyError):
        pass

    log.info("calibrating squelch — listening for this site's noise floor")
    level = choose_squelch(lambda lv, secs: _sample_rtl(channel, lv, secs))
    if level is None:
        # Either nothing shut it up — a genuinely busy band — or the receiver gave us
        # nothing to measure. Carry on at the default rather than refusing to listen, and
        # do not cache it: a guess must be re-examined on the next restart, where a
        # measurement is trusted for a day.
        log.warning("could not measure a squelch level; using %s for now", DEFAULT_SQUELCH)
        return DEFAULT_SQUELCH

    log.info("squelch %s — measured", level)
    try:
        with open(cache, "w", encoding="utf-8") as fh:
            json.dump({"squelch": level, "when": time.time(), "frequency": channel.frequency}, fh)
    except OSError:
        pass
    return level


def receiver_alive(channel):
    """Does the dongle actually produce samples?

    With the squelch off rtl_fm cannot gate anything, so a working receiver must emit at
    close to the full rate. Nothing means the tuner is not delivering — and it does fail
    this way, silently, while every command still reports success: rtl_fm prints "Tuned
    to 146700000 Hz", allocates its buffers, announces its sample rate and then produces
    not one byte. rtl_test says "[R82XX] PLL not locked!" and exits 0.

    Worth two seconds at every start, because the alternative is a channel that sits there
    logging "no transmissions in the last 30 minutes" on a frequency somebody is listening
    to on a handheld. That has now happened twice, and both times the receiver looked
    perfectly healthy from every angle except this one.
    """
    got = _sample_rtl(channel, 0, 2.0)
    want = SAMPLE_RATE * 2 * 2.0 * 0.25       # a quarter of full rate is generous
    return got >= want


def start_capture(channel, clips, spool):
    """rtl_fm, squelched, writing raw 16 kHz samples for the main loop to segment.

    Both directories: clips and rtl_fm's own output belong in RAM, while the measured
    squelch belongs on the card in `spool`, because re-measuring it costs a minute of
    deafness on every restart.

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
    # Hardware squelch, and nothing on top of it.
    #
    # rtl_fm's -l gates on RF POWER before demodulation. That is a different and much
    # better question than "is the audio loud", because FM noise is loud precisely when
    # there is no carrier — an audio-level gate sees hiss peaking at 0.295 against a
    # floor of 0.036 and opens on it. Measured on a real repeater, hardware squelch at
    # 25 produced clean captures and a correct transcription, while audio-level gating
    # alone filled the spool with ten-second recordings of static.
    #
    # A software squelch ran on top of this for a while, to find the edges of each over.
    # It could not work, for a reason the measurement above should have made obvious:
    # rtl_fm emits nothing while closed, so the only audio it ever saw was speech, and
    # the "noise floor" it computed was a speech level. It then discarded anything
    # quieter — which is to say the start of every over — and cut overs in half at the
    # first pause. Gone. The gap in the byte stream is the boundary, and it needs no
    # help deciding that.
    # A value set in the manager is an override and is obeyed; otherwise it is measured
    # for this site and cached.
    #
    # Say which, either way. The measured path logs its own answer, so an override used
    # to be the one case where the journal never mentioned the squelch at all — and it is
    # now the only number that decides what gets recorded, since nothing downstream
    # second-guesses the RF gate any more. Too low and the Pi spends its afternoon
    # transcribing static; too high and it is quietly deaf.
    if channel.squelch:
        log.info("squelch %s — set in the manager for this channel", channel.squelch)
    level = channel.squelch or calibrated_squelch(channel, spool)
    argv = ["rtl_fm", "-d", channel.serial, "-f", channel.frequency,
            "-M", "fm", "-s", "200000", "-r", str(SAMPLE_RATE), "-E", "deemp",
            "-l", str(level)]
    if channel.gain is not None:
        argv += ["-g", str(channel.gain)]     # otherwise rtl_fm uses automatic gain
    # rtl_fm's stderr went to /dev/null, which threw away the only thing it ever says
    # that matters. A dongle that has dropped off the USB bus produces "No supported
    # devices found." and exit 1; all the journal showed was "rtl_fm exited (1)", and
    # working out which of the several things that could mean took a session. It cannot
    # be a pipe — rtl_fm chatters about signal level and nothing would be draining it,
    # which is the same deadlock transcription used to cause on stdout. A file on tmpfs
    # costs the card nothing and is read back only when rtl_fm dies.
    return subprocess.Popen(argv, stdout=subprocess.PIPE,
                            stderr=open(os.path.join(clips, RTL_LOG), "wb"))


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

class ClipQueue:
    """The backlog between the radio and whisper, bounded in RAM.

    Bounded in two ways at once, because a clip count says nothing about size: at
    MAX_CLIP_SECONDS a single clip is nearly 4 MB, so a hundred of them would be more
    than /run holds. Whichever limit is hit first, the oldest clip is deleted and its
    space reclaimed — by then the log is minutes behind the radio, and the newest
    transmission is the one somebody is waiting to read.

    Both threads touch the byte count, so it lives here behind a lock rather than in a
    closure the capture loop owns and the worker cannot correct.
    """

    def __init__(self, max_clips=CLIP_BACKLOG_CAP, max_bytes=CLIP_BACKLOG_BYTES):
        self.max_clips = max_clips
        self.max_bytes = max_bytes
        self._items = collections.deque()
        self._bytes = 0
        self._cv = threading.Condition()

    def put(self, path):
        try:
            size = os.path.getsize(path)
        except OSError:
            size = 0
        with self._cv:
            self._items.append((path, size))
            self._bytes += size
            # Never drop the clip just added, even if it alone is over the limit —
            # keeping the newest is the whole point of dropping the oldest.
            while len(self._items) > 1 and (
                    len(self._items) > self.max_clips or self._bytes > self.max_bytes):
                old, old_size = self._items.popleft()
                self._bytes -= old_size
                log.error("transcription is %s clips (%.0f MB) behind; dropped %s",
                          len(self._items), self._bytes / 1048576, os.path.basename(old))
                try:
                    os.unlink(old)
                except OSError:
                    pass
            self._cv.notify()

    def get(self, timeout):
        """The next clip, or None if none arrived in time."""
        with self._cv:
            if not self._items:
                self._cv.wait(timeout)
            if not self._items:
                return None
            path, size = self._items.popleft()
            self._bytes -= size
            return path

    def __len__(self):
        with self._cv:
            return len(self._items)


def transcribe_loop(work, channel, whisper, model, outbox, stopping):
    """Transcribe and post, off the capture thread.

    This has to be its own thread. whisper is blocking and posting has a fifteen-second
    timeout, and while either ran inline nothing was draining rtl_fm's pipe — which
    holds 64 KB, about two seconds of audio, after which rtl_fm blocks on write, stops
    reading the SDR, and the samples are gone. A ten-second over takes four seconds to
    transcribe on the fast model, so a second over arriving behind the first was already
    being clipped; on the careful model, which runs slower than real time, it would be
    lost outright.

    Separated, a slow model or a slow server only makes the log arrive later. The
    backlog waits in RAM, bounded by ClipQueue.
    """
    while True:
        path = work.get(timeout=0.5)
        if path is None:
            if stopping.is_set():
                return
            continue
        try:
            handle_clip(channel, path, whisper, model, outbox)
            outbox.flush(lambda t: post_log_entry(channel, t))
        except Exception:                       # noqa: BLE001 - one bad clip must not
            log.exception("transcription failed")   # stop the channel transcribing


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
    p.add_argument("--clips", default=None,
                   help="where clips are held; defaults to tmpfs at %s/<channel>" % CLIPS)
    p.add_argument("--whisper", default="whisper-cli", help="whisper.cpp binary")
    p.add_argument("--models", default="/opt/transcriber/models")
    p.add_argument("--spool-only", action="store_true",
                   help="do not open the radio; transcribe wavs already in the spool. "
                        "This is how the pipeline is tested without an SDR.")
    p.add_argument("--once", action="store_true", help="process what is waiting, then exit")
    p.add_argument("-v", "--verbose", action="store_true")
    p.add_argument("--version", action="version", version=f"transcriber {VERSION}")
    p.add_argument("--calibrate", action="store_true",
                   help="re-measure the squelch for this site now, ignoring the cache")
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
    if args.calibrate:
        try:
            os.unlink(os.path.join(spool, "squelch.json"))
        except OSError:
            pass
    outbox = Outbox(os.path.join(spool, "outbox"))
    # --spool-only means clips were put somewhere by hand or by a test; that is where to
    # read them from, and inventing a second directory would just mean finding nothing.
    if args.spool_only:
        clips = spool          # and never swept: those clips are the input
    else:
        clips = clip_dir(channel.id, args.clips, fallback=spool)
        sweep_clips(clips)
        if clips != spool:
            sweep_clips(spool)   # wavs from before clips moved off the card

    whisper = shutil.which(args.whisper) or args.whisper
    model = os.path.join(args.models, channel.model)
    if not os.path.exists(model):
        raise SystemExit(f"model not found: {model}")

    # An RTL-SDR covers roughly 24 MHz to 1.766 GHz. Anything outside that is a typo,
    # and rtl_fm will happily accept it and tune nowhere useful — a channel that looks
    # healthy and hears nothing, which is the failure this project keeps producing.
    # A real one: 147.465 typed into the manager reached the device as 1474650 Hz.
    try:
        hz = int(channel.frequency)
    except (TypeError, ValueError):
        raise SystemExit(f"frequency is not a number: {channel.frequency!r}")
    if not 24_000_000 <= hz <= 1_766_000_000:
        raise SystemExit(
            f"frequency {hz} Hz ({hz / 1e6:.4f} MHz) is outside what this receiver "
            f"covers — 147.465 MHz is 147465000, not 1474650")

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
        # Before anything else, prove the dongle is producing samples. A tuner that has
        # wedged is indistinguishable from a quiet frequency once capture is running, so
        # this is the only cheap moment to ask.
        if not receiver_alive(channel):
            log.error("the receiver is not producing samples — the tuner has not locked. "
                      "Power-cycle the dongle (unplug it, or re-bind its USB port) and "
                      "check it is not overheating or on a long/thin extension lead.")
            return 1
        rtl = start_capture(channel, clips, spool)
        # Version first, so `journalctl -u transcriber@… ` answers "what is this running"
        # without anyone having to go and look.
        log.info("transcriber %s — listening on %s (%s), dongle %s, model %s",
                 VERSION, channel.frequency, channel.label, channel.serial, channel.model)

    running = True

    def stop(_signum, _frame):
        nonlocal running
        running = False

    # Only the main thread may install these, and in production this is always the main
    # thread. Tolerating the failure is what lets a test drive main() on a thread of its
    # own — which is how the capture loop gets exercised at all, since it otherwise needs
    # a radio. Without this the thread died here, before the loop, and a test written to
    # watch the loop passed by watching nothing.
    try:
        signal.signal(signal.SIGTERM, stop)
        signal.signal(signal.SIGINT, stop)
    except ValueError:
        log.debug("not the main thread; leaving signal handling alone")

    # Transcription and posting run on their own thread; this one does nothing but read
    # the radio. See transcribe_loop for why that separation is not optional.
    work = ClipQueue()
    stopping = threading.Event()
    worker = threading.Thread(
        target=transcribe_loop, args=(work, channel, whisper, model, outbox, stopping),
        daemon=True, name="transcribe")
    worker.start()

    enqueue = work.put

    audio = bytearray()          # the transmission currently being received
    seq = 0
    last_data = None             # when samples last arrived; None between overs
    max_bytes = MAX_CLIP_SECONDS * SAMPLE_RATE * 2
    last_report, heard = time.time(), 0
    last_any_data = time.time()   # for the deaf-receiver check, not per-transmission

    try:
        while running:
            if rtl is not None:
                ready, _, _ = select.select([rtl.stdout], [], [], 0.2)
                now = time.time()
                if ready:
                    chunk = os.read(rtl.stdout.fileno(), 65536)
                    if chunk:
                        # Everything that arrives is part of a transmission. rtl_fm is
                        # already gating on RF power, so there is nothing here to second-
                        # guess and no audio level worth measuring.
                        #
                        # An audio-level squelch used to run on top of this, and it was
                        # actively destructive for a reason that is obvious in hindsight:
                        # since rtl_fm emits nothing while squelched, the only audio it
                        # ever saw was speech. Its "noise floor" was therefore a speech
                        # level — 98, measured — and it demanded 1.6x that to open and
                        # dropped out below 1.2x. So the quiet opening syllables of an
                        # over never cleared the bar and were discarded, and a pause in
                        # the middle fell through the floor and cut the over in two. On
                        # the air: an entry beginning "ring channel K6DRK" where the
                        # station had said "monitoring channel", followed by a 1.2s
                        # fragment whisper could make nothing of.
                        audio += chunk
                        last_data = now
                        last_any_data = now

                # The gap IS the end of the transmission.
                if audio and last_data is not None and now - last_data >= GAP_SECONDS:
                    seq += 1
                    heard += 1
                    enqueue(write_clip(clips, bytes(audio), seq))
                    audio.clear()
                    last_data = None

                # A carrier that never drops would otherwise grow one clip forever.
                if len(audio) >= max_bytes:
                    seq += 1
                    enqueue(write_clip(clips, bytes(audio), seq))
                    audio.clear()

                # Total silence for long enough is not proof of a quiet frequency — see
                # DEAF_CHECK_SECONDS. Exit cleanly and let systemd start us again; the
                # liveness probe at startup is what actually decides.
                if now - last_any_data > DEAF_CHECK_SECONDS:
                    log.info("no audio at all for %d minutes — restarting to re-check "
                             "the receiver", DEAF_CHECK_SECONDS // 60)
                    return 0

                # A periodic sign of life. With no audio level left to report, the useful
                # question is whether anything has been heard at all — a channel that has
                # captured nothing for hours is either on a quiet frequency or deaf, and
                # only the log's history tells them apart.
                if now - last_report >= REPORT_SECONDS:
                    log.info("%s transmissions in the last %d minutes",
                             heard or "no", REPORT_SECONDS // 60)
                    last_report, heard = now, 0

            # --spool-only ONLY: wavs already on disk, put there by a test or by hand.
            #
            # Never with a radio attached. The capture loop above writes each clip and
            # enqueues it itself, so scanning the same directory hands the worker a second
            # reference to a file that is already queued — and then a third, and a fourth,
            # once per pass through this loop.
            #
            # That was survivable while transcription ran inline, because the clip was
            # unlinked before this scan next ran, which is why it went unnoticed for so
            # long. With a worker thread the file waits, the duplicates pile up at several
            # a second, and the backlog cap starts dropping the OLDEST entry — deleting
            # real clips that had not been transcribed yet. On the air that looked like a
            # transmission simply never arriving: the first one logged, the second
            # vanished, and the journal filled with FileNotFoundError from the duplicates
            # chasing a file the worker had already finished with.
            if rtl is None:
                for path in settled_clips(clips):
                    if args.once:
                        handle_clip(channel, path, whisper, model, outbox)
                        outbox.flush(lambda t: post_log_entry(channel, t))
                    else:
                        enqueue(path)
            if args.once:
                break
            # A dead radio must not look like a quiet frequency. systemd restarts us,
            # and a failed unit is a state somebody notices.
            if rtl is not None and rtl.poll() is not None:
                log.error("rtl_fm exited (%s): %s", rtl.returncode, rtl_complaint(clips))
                return 1
            if rtl is None:
                time.sleep(0.5)
    finally:
        # Whatever was mid-transmission when we were told to stop is still a
        # transmission. Without this it was dropped on the floor — the clip is only
        # written when the gap arrives, and a shutdown, or rtl_fm dying, arrives first.
        if audio:
            seq += 1
            enqueue(write_clip(clips, bytes(audio), seq))
            audio.clear()
        # Stop listening first, then let the backlog finish: a transmission already
        # recorded should still reach the log, and systemd allows time for it.
        if rtl is not None and rtl.poll() is None:
            rtl.terminate()
        stopping.set()
        worker.join(timeout=30)
        if len(work):
            log.warning("%s clips left untranscribed", len(work))
    return 0


if __name__ == "__main__":
    sys.exit(main())

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
import copy
import difflib
import json
import logging
import math
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
VERSION = "1.1"

CONFIG = "/etc/transcriber/channels.json"
# What must survive a reboot lives on the card: the outbox, so a transmission heard
# during an outage is not lost, and the measured gain and squelch, which are the only
# record of what this site was measured at and are not measured again unless somebody
# asks for it.
SPOOL = "/var/spool/transcriber"
# What must not touch the card lives in RAM. See clip_dir().
CLIPS = "/run/transcriber"
RTL_LOG = "rtl_fm.err"
SERVER = "https://marsaprs.org"

# rtl_fm's RF squelch — the gate that decides whether anything is transmitting at all.
# Measured working on a real repeater. A site that needs a different value can set one
# per channel in the manager.
DEFAULT_SQUELCH = 25

# Tuner gain in dB, fixed rather than automatic.
#
# Automatic gain and an RF squelch cannot both work. rtl_fm's -l compares received power
# against a threshold, and AGC changes what that power means: on a quiet band it winds the
# gain up until the noise crosses whatever level you set. Measured on an idle frequency,
# with nothing whatsoever on the air — squelch 40 open 92% of the time, 50 open 25%, 60
# open 22%, and the same level of 50 reading 0% ten minutes earlier. Not a threshold that
# was slightly wrong: a threshold that meant something different every few minutes.
#
# The channel had been recording that noise all day. Six hours of it produced 154 minutes
# of "audio" on a frequency whose real duty cycle is nearer 1%, and whisper was run twice
# over every second of it.
#
# 30 dB, not the 40 that was hardcoded here once: 40 is near this tuner's 49.6 dB maximum
# and overloads the front end anywhere with a strong signal nearby, which is why it was
# removed in favour of automatic in the first place. At a fixed 30 the same idle frequency
# is silent at every squelch level from 10 to 40.
#
# It is a starting value and not an answer, because 30 was measured at ONE site. These
# receivers go up hills and into sheds with different antennas and different neighbours,
# and the gain a site wants is decided by how much noise its antenna hears — see
# choose_gain(). The squelch is already measured per site, and a squelch measured at the
# wrong gain means nothing, so the two are measured and cached together when somebody
# presses Recalibrate in the channel manager. Until they are, a channel runs on this pair.
DEFAULT_GAIN = 30

# A transmission shorter than this is a squelch tail, a key-up, or someone knocking
# their PTT — never words worth logging, and exactly what whisper invents speech from.
MIN_CLIP_SECONDS = 1.2

# The length at which a transmission stops being a transmission.
#
# This is not a limit on how long somebody may talk. On these frequencies an over runs
# ten to twenty seconds and a conversation is four to six of them, with minutes of
# nothing in between; two unbroken minutes is not a talkative operator. It is one of
# two faults, and both have happened here: a transmitter stuck down, or a squelch that
# has stopped gating and is handing us the noise floor as one endless carrier — which
# is exactly what automatic gain did before DEFAULT_GAIN pinned it.
#
# So the cap is a sampling boundary rather than a chapter break: no sentence is being
# cut in half and there is no pause worth hunting for. What matters is that the audio
# is not thrown away in silence, which is what used to happen — the clip overshot the
# cap by a tenth of a second, failed a `> MAX_CLIP_SECONDS` test, and was deleted
# without ever reaching whisper, so a fault erased the only evidence of itself — and
# that a carrier which never drops is not transcribed forever. See OpenCarrier.
MAX_CLIP_SECONDS = 120

# How many capped segments in a row may come back with nothing worth logging before we
# stop transcribing this carrier, and how many are skipped between looks after that.
#
# Two segments is four minutes of unbroken carrier that whisper made nothing of, which
# is enough to conclude nobody is talking. One further look every five segments — ten
# minutes — is what stops a squelch that has failed open from making the channel deaf:
# in that fault real traffic is still arriving inside the endless carrier, so a sample
# now and then finds it and switches transcription back on.
BARREN_SEGMENTS = 2
RECHECK_SEGMENTS = 5
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
    # A repeater sounds a courtesy tone after every over. When it lands in a clip of its
    # own — the carrier drops, the tone re-keys it, and the hang time makes the clip long
    # enough to survive MIN_CLIP_SECONDS — whisper reports it as the word. Three entries
    # reading exactly "Beep" reached a real event log this way, and on a roll call with an
    # over every few seconds there would have been dozens.
    "beep", "beeps", "beeping", "bleep", "bloop", "tone", "chirp", "ding", "buzz",
}

# whisper's other failure on marginal audio: not one invented sentence but the same one
# over and over, in phrases rather than single words. Two off this repeater, both of
# which the old "every word identical" test let through and both of which would have
# been filed as real traffic:
#
#   "I love it. I love it. I love it. It's good. I love it. … I love you. I love you.
#    I love you. I love you. I love you. I love you. I love you. I love you."
#
#   "I don't know if they have a piano, I don't think it's a place to get out of the
#    way. I think it's a place to get out of the way. I think that's a place to get out
#    of the way. I think that's a place to get out of the way."
#
# The numbers below were measured against 124 transcriptions from a six-hour bench run,
# not chosen: genuine traffic on that tape sits at a distinct-trigram ratio of 0.85 and
# above, and everything under 0.45 was invented. 0.45 leaves the margin on the safe
# side, which is where it belongs — losing a real transmission costs the log one line,
# and admitting an invented one costs it credibility.
LOOP_RATIO = 0.45
# Nothing shorter than this is judged by that ratio at all. Radio traffic repeats:
# "roger roger", "break break break", a callsign said three times, a number read back
# for clarity. All of it is short, and a short text can be almost entirely repetition
# and still be exactly what somebody said. Twelve words is about five seconds of speech.
LOOP_MIN_WORDS = 12

# What counts as a degenerate run when trimming one out of an otherwise real entry:
# the same phrase at least three times over, filling at least twelve words. Both
# conditions, because either alone catches real speech — "that's right, that's right,
# that's right, that's right" is off this repeater and is eight words, and a phrase
# said twice is a person making a point.
REPEAT_MIN_TIMES = 3
REPEAT_MIN_WORDS = 12
# The longest phrase looked at. Longer than a sentence somebody might repeat, short
# enough that the scan stays cheap.
REPEAT_MAX_PHRASE = 12

log = logging.getLogger("transcriber")


# ── configuration ────────────────────────────────────────────────────────────

class Channel:
    """One frequency on one device, as the channel manager defined it."""

    def __init__(self, d, vocabulary=None):
        self.id = d["id"]
        self.label = d.get("label") or d["id"]
        self.token = d.get("token") or ""
        self.frequency = str(d.get("frequency") or "")
        self.serial = str(d.get("serial") or "")
        self.squelch = int(d.get("squelch") or 0)
        # Tuner gain in dB, and squelch above it: both are OVERRIDES here, None and 0
        # meaning "nobody has said", not "use the default". What a channel actually opens
        # with is settled by calibration_for(), which needs the spool to know what was
        # measured for this site — and the difference between "set" and "unset" is the
        # whole of how a measurement gets a chance to be used.
        #
        # Fixed, never automatic: see DEFAULT_GAIN for why the squelch cannot work
        # without it. The manager does not offer a gain box and should not — the whole
        # point is that it is measured rather than guessed at — so this is the escape
        # hatch: a "gain" written into this device's channels.json by hand, for the site
        # with something so strong nearby that the knee is the wrong answer.
        self.gain = float(d["gain"]) if d.get("gain") not in (None, "", 0) else None
        self.model = d.get("model") or "ggml-tiny.en.bin"
        self.enabled = bool(d.get("enabled", True))
        self.server = (d.get("server") or SERVER).rstrip("/")
        # What this event expects to hear. A property of the event, so it arrives beside
        # the channels rather than inside one, and it is routinely absent.
        self.vocabulary = vocabulary or Vocabulary()
        # Whether to prime whisper with that vocabulary before it listens. Off unless the
        # channel says otherwise, and PROMPT_FLAG explains at length why the default is
        # the one that matters here.
        self.initial_prompt = bool(d.get("initial_prompt", False))


def load_channel(path, channel_id):
    with open(path, encoding="utf-8") as fh:
        raw = json.load(fh)
    vocabulary = Vocabulary(raw.get("vocabulary"))
    for entry in raw.get("channels", []):
        if entry.get("id") == channel_id:
            return Channel(entry, vocabulary)
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


# Non-speech tokens are whisper's own vocabulary for sounds rather than words:
# "(buzzing)", "(machine whirring)", "[BLANK_AUDIO]", "*BANG*", "*gunshot*". All of
# those are real, off this receiver, and clean() and worth_logging() delete them after
# the fact — but suppressing them at the decoder is a better thing to do than tidying
# up afterwards. A non-speech token does not simply sit at the end of an entry; it takes
# part in the decode and pulls the words around it out of shape, and deleting it later
# leaves that damage behind.
#
# The filters stay regardless, and that is deliberate. There is no way to prove the flag
# catches everything, a device built later against an older whisper may not have it at
# all, and the cost of being wrong is an invented line in an event log.
SUPPRESS_NON_SPEECH = "--suppress-nst"

# Priming the decoder with the event's own vocabulary. OFF unless a channel asks for it,
# and the reason is the same one the rest of this file is built around.
#
# An initial prompt makes the model more likely to emit the exact words it was given —
# which is the point, and also the danger. Our worst failure is not a mangled callsign;
# it is confident text invented from static, because that reads as authoritative and
# nobody questions it. Handing whisper thirty-five callsigns before it listens to a
# squelch tail is handing it a plausible net log to hallucinate, and the filters
# downstream cannot help: "KM6AOW mobile" off a hiss burst passes worth_logging()
# perfectly, because it is a short, unrepetitive, entirely reasonable sentence.
#
# So it ships implemented and switched off, and there is a measurement that decides it:
# compare-models.py --compare prompt runs one model twice over the same audio, without
# this prompt and with it, over the channel's real traffic AND over static it captures
# unsquelched on purpose. The accuracy it buys on speech is worth nothing if it also
# invents a check-in from noise. Until somebody has run that on this site, the default
# stands.
#
# --carry-initial-prompt matters because a clip here can run past one 30-second window
# (MAX_CLIP_SECONDS is 120), and without it the prompt applies only to the first.
PROMPT_FLAG = "--prompt"
CARRY_PROMPT = "--carry-initial-prompt"

# whisper.cpp caps the initial prompt at n_text_ctx/2 — 224 tokens on every model we run
# — and silently drops the rest. Trimmed here instead, so what goes is a whole callsign
# off the end rather than half of one, and the words the decoder is primed with are
# always words somebody could actually say.
PROMPT_MAX_TOKENS = 224

_flag_support = {}


def supports_flag(binary, flag, timeout=30):
    """Whether this whisper build knows a flag. Asked once per binary, then remembered.

    Worth asking, because whisper.cpp treats an unknown option as fatal — it prints its
    usage and exits non-zero. Passing one blind would turn an improvement in the text
    into a channel that transcribes nothing at all, on exactly the older device least
    likely to be watched.
    """
    if (binary, flag) not in _flag_support:
        try:
            probe = subprocess.run([binary, "-h"], capture_output=True, text=True,
                                   timeout=timeout)
            _flag_support[(binary, flag)] = flag in (probe.stdout + probe.stderr)
        except (OSError, subprocess.SubprocessError):
            _flag_support[(binary, flag)] = False
    return _flag_support[(binary, flag)]


def transcribe(binary, model, path, seconds=0, prompt=None):
    """Text for one clip, or '' if there is nothing worth saying.

    The timeout follows the recording rather than sitting at a fixed number. The
    careful model runs at about 0.8x real time on this Pi, so five minutes is generous
    for anything the capture loop produces and far too little for a long file handed
    over by the bench tool or by hand — and a timeout here raises, which loses the clip
    and leaves it on disk to be found again.

    `prompt` is only ever set when a channel has explicitly asked for it. See
    PROMPT_FLAG for why that is not a default.
    """
    argv = [binary, "-m", model, "-f", path, "--no-timestamps", "--no-prints",
            "--language", "en", "--threads", str(max(1, (os.cpu_count() or 2) - 1))]
    if supports_flag(binary, SUPPRESS_NON_SPEECH):
        argv.append(SUPPRESS_NON_SPEECH)
    if prompt and supports_flag(binary, PROMPT_FLAG):
        argv += [PROMPT_FLAG, prompt]
        # A clip can run past one 30-second window, and by default the prompt only
        # reaches the first. Asked for separately because it is the newer flag of the
        # two: a build that has --prompt may not have this one, and an unknown option
        # is fatal to whisper.cpp.
        if supports_flag(binary, CARRY_PROMPT):
            argv.append(CARRY_PROMPT)
    out = subprocess.run(
        argv, capture_output=True, text=True, timeout=max(300, seconds * 5),
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
    # The same thing over and over in phrases rather than in single words, which is
    # what the fast model actually produces on marginal audio. Judged by how much of
    # the text is a copy of the rest of it, because there is no list of these to keep —
    # the loop invents a different sentence every time and then sticks on it.
    if len(words) >= LOOP_MIN_WORDS and loop_ratio(words) < LOOP_RATIO:
        return False
    return True


def loop_ratio(words, n=3):
    """How much of this text is different from the rest of it: distinct n-word runs
    over total n-word runs, 1.0 when nothing repeats.

    A transcription that has fallen into a loop says the same handful of things
    repeatedly, so most of its trigrams are copies of earlier ones. Speech does not do
    that, even when somebody is repeating themselves — the words either side of the
    repeat are different, so the trigrams spanning it are too. Measured over a six-hour
    tape off a real repeater: genuine traffic 0.85 and above, invented text 0.43 and
    below, and nothing at all in between.
    """
    if len(words) < n + 1:
        return 1.0
    runs = [tuple(words[i:i + n]) for i in range(len(words) - n + 1)]
    return len(set(runs)) / len(runs)


def collapse_loops(text):
    """The same text with any degenerate repeated run cut back to a single copy.

    For the entry that is real up to a point and then sticks — "K6DRK monitoring, 73.
    I love you. I love you. I love you. I love you." Rejecting that whole would throw
    away a transmission that happened; keeping it whole files an invented sentence four
    times. Trimming keeps what was said and drops the groove, and it is the only one of
    the three that is right.

    A run must repeat REPEAT_MIN_TIMES over and cover REPEAT_MIN_WORDS before it counts.
    Both, because either on its own takes real speech: "that's right, that's right,
    that's right, that's right" came off this repeater and is four repeats of eight
    words, and "get out of the way, get out of the way" is a person meaning it. Thirty
    words of one sentence three times over is not.

    The shortest repeating phrase wins, so "I love it" six times collapses to "I love
    it" rather than to "I love it. I love it." Punctuation and case are ignored when
    matching but kept in what is returned, since whisper spells the loop differently
    each time round and the log should read as it was heard.
    """
    words = text.split()
    keys = [re.sub(r"[^\w]", "", w).lower() for w in words]
    out, i = [], 0
    while i < len(words):
        run = None
        for n in range(1, REPEAT_MAX_PHRASE + 1):
            if i + 2 * n > len(keys):
                break
            times = 1
            while keys[i + times * n:i + (times + 1) * n] == keys[i:i + n]:
                times += 1
            if times >= REPEAT_MIN_TIMES and times * n >= REPEAT_MIN_WORDS:
                run = (n, times)
                break
        if run:
            n, times = run
            out += words[i:i + n]
            i += n * times
        else:
            out.append(words[i])
            i += 1
    return " ".join(out)


def loggable(text):
    """What of this transcription belongs in the log, or "" if none of it does.

    Judge the whole text first and trim afterwards, never the other way round.
    Collapsing the loop first destroys the evidence: "I love it" ten times over reads
    as an unremarkable short entry once it has been reduced to one copy, and the
    repetition was the only thing that showed it was invented.

    That ordering is also the answer to the harder question — whether an entry that is
    plausible up to a point and then loops should be trimmed or thrown out. Both,
    depending on which part is the exception. A mostly-real entry with a groove stuck
    on the end is a transcription that worked and then failed, and the part that worked
    is a transmission somebody made: trim it and keep it. An entry that is mostly loop
    is a transcription that failed, and its opening words are no more trustworthy than
    its last ones — "I don't know if they have a piano" is not a real sentence rescued
    from a bad recording, it is the same failure a few words earlier. LOOP_RATIO is
    where that line sits, and it sits well clear of anything real: on a six-hour tape
    nothing genuine came below 0.85 and nothing invented came above 0.43.
    """
    if not worth_logging(text):
        return ""
    trimmed = collapse_loops(text)
    return trimmed if worth_logging(trimmed) else ""


# ── callsigns ────────────────────────────────────────────────────────────────
#
# The words a net log most needs right are the ones whisper is worst at. Real output
# from this receiver, all of it one station:
#
#   "K-60RK"   "K-6 DRK"   "6 delta rho mu"   "K-60 Arcade"
#
# All four are K6DRK. The model has no idea callsigns exist; it is spelling out sounds
# and reaching for English words, and a log full of K-60RK is a log nobody can search.
#
# Four things happen here, in descending order of confidence, and the first that fits
# wins:
#
#   0. somebody wrote down this exact mishearing and what it should say      → obey it
#   1. the span is exactly a callsign or phrase this event knows             → use it
#   2. it is close enough to one of them, and to nothing else                → use it
#   3. it spells out something with the SHAPE of a US callsign               → collapse
#   4. none of the above                                                     → untouched
#
# Rule 0 is the only one that is told rather than worked out, which is why it outranks the
# rest — and why it is the only one that never matches loosely.
#
# Knowing the event's own list is what makes this safe, and it is the whole reason the
# manager collects one: "did I hear a callsign" is a guess, while "which of these
# thirty-five did I hear" is a choice between known answers. Where there is no list,
# only rule 3 applies, and it can do no more than join up what was already said.
#
# Rule 4 is not a fallback, it is the point. A wrong callsign in a log is worse than a
# mangled one — it reads as authoritative and it points at the wrong person, and unlike
# "K-60RK" nobody reading it later has any reason to doubt it. Nothing here guesses on a
# partial match.

# The NATO alphabet, which is what hams actually say. Fixed, not configurable: it has
# been the same 26 words since 1956 and a site that changed it would be talking to
# itself. Both spellings of the two that have them, because whisper writes what it hears
# and people say both.
NATO = {
    "alfa": "A", "alpha": "A", "bravo": "B", "charlie": "C", "delta": "D", "echo": "E",
    "foxtrot": "F", "golf": "G", "hotel": "H", "india": "I", "juliet": "J",
    "juliett": "J", "kilo": "K", "lima": "L", "mike": "M", "november": "N",
    "oscar": "O", "papa": "P", "quebec": "Q", "romeo": "R", "sierra": "S",
    "tango": "T", "uniform": "U", "victor": "V", "whiskey": "W", "whisky": "W",
    "xray": "X", "yankee": "Y", "zulu": "Z",
}

# Spoken digits. "niner" because that is how it is said on the air, and whisper writes it
# down as it hears it. Not "oh" for zero, however common that is in speech — "oh" is a
# word people use constantly and admitting it would put a digit in the middle of half the
# sentences on the band.
SPOKEN_DIGITS = {
    "zero": "0", "one": "1", "two": "2", "three": "3", "four": "4", "five": "5",
    "six": "6", "seven": "7", "eight": "8", "nine": "9", "niner": "9",
}

# A US amateur callsign: one or two letters, a digit, one to three letters.
#
# Three characters is therefore a legal callsign, and TWO spoken words with a digit
# between them is a legitimate one — W6P is "whiskey six papa" and belongs to somebody.
# So this tests the shape and never the number of words; requiring three phonetic words
# would silently drop every 1x1 holder on the band.
CALLSIGN_RE = re.compile(r"[A-Z]{1,2}[0-9][A-Z]{1,3}")

# The longest run of spoken words that can be one callsign: "kilo mike six alpha oscar
# whiskey" is KM6AOW, and that is the maximum a 2x3 can take.
MAX_SPAN = 6

# How close two strings must be to count as the same thing, on difflib's ratio.
#
# Measured against the table above rather than picked: the closest any two NATO words
# come to each other is alpha/papa at 0.67, and every other pair that stands for a
# different letter is below that. 0.80 therefore sits clear of the whole alphabet, so no
# amount of mangling turns one phonetic word into a different letter — it either matches
# what was said or matches nothing.
#
# On the callsigns themselves 0.80 is one wrong character in five, which is exactly the
# failure being corrected: "K60RK" against "K6DRK" scores 0.80, and "K60 Arcade" against
# it scores 0.43 and is left alone, as it should be. Lower would start accepting the
# second sort; higher would reject the first.
SIMILARITY = 0.80

# And it must be a clear winner. Two known callsigns can easily sit the same distance
# from the same mangled text — with K6DRK and K6DRJ both on the roster, "K6DR0" is 0.80
# from each — and picking either one is a coin toss recorded as a fact. If nothing stands
# out, the words stay exactly as they were heard.
AMBIGUITY_MARGIN = 0.05


class Vocabulary:
    """Everything this event has told the receiver to expect: the callsigns and tactical
    calls found on the assignment sheet, the phrases it states outright, and the
    corrections somebody wrote down after hearing one go wrong.

    Four keys, in two kinds. `callsigns`, `tactical` and `terms` are all things the event
    says — a phrase to be matched, loosely, against what whisper produced. Tactical calls
    and terms are the same kind of thing and are held together in `by_phrase`; they arrive
    separately only because one is found by pattern and the other is stated, which is a
    fact about the sheet and not about the words.

    `corrections` is the other kind: a rule, not a candidate. It maps a normalized
    heard-form to what should be written instead, and it is applied on an exact match and
    in no other way. See resolve() for why it is applied first.

    Delivered in channels.json beside the channels, because it is a property of the event
    rather than of any one receiver. Every key is optional and all of them are routinely
    empty — an event with no roster is the normal case, not an error, and everything here
    degrades to "leave the words alone".
    """

    def __init__(self, d=None):
        d = d if isinstance(d, dict) else {}
        self.callsigns = _strings(d.get("callsigns"))
        self.tactical = _strings(d.get("tactical"))
        self.terms = _strings(d.get("terms"))
        # Keyed by what a mishearing would have to be compared against: callsigns with
        # punctuation and case removed, phrases as lower-case words with spoken numbers
        # written as digits, so "sweet one" and "Sweep 1" are the same shape of thing
        # before they are ever compared.
        self.by_call = {}
        for call in self.callsigns:
            key = re.sub(r"[^0-9A-Za-z]", "", call).upper()
            if key:
                self.by_call.setdefault(key, call)
        self.by_phrase = {}
        for term in self.tactical + self.terms:
            key = phrase_key(term.split())
            if key:
                self.by_phrase.setdefault(key, term)
        self.phrase_span = max([len(k.split()) for k in self.by_phrase] or [0])
        # The same phrases again, split by how many words they are, for the fuzzy layer to
        # compare like with like. See resolve() for what goes wrong without it — briefly, a
        # one-word phrase scores 0.82 against the two words before it and eats the first
        # one, which is a word deleted from the log.
        self.by_phrase_n = {}
        for key, term in self.by_phrase.items():
            self.by_phrase_n.setdefault(len(key.split()), {})[key] = term
        # And once more with the spaces taken out, for the one thing whisper reliably does
        # to a place name: it splits it. "Bootjack" comes back as "boot jack" and "Pantoll"
        # as "Pan Toll", which is the same letters in the same order and no guess at all.
        self.by_phrase_joined = {}
        for key, term in self.by_phrase.items():
            self.by_phrase_joined.setdefault(key.replace(" ", ""), term)

        # Corrections, keyed the way the server keyed them. The server writes these keys
        # and this looks them up, so correction_key() here and transcriber_correction_key()
        # in store.php have to agree exactly — a difference between them is not a mismatch
        # anybody would see, it is a rule that silently never fires.
        self.by_correction = {}
        raw = d.get("corrections")
        for heard, written in (raw.items() if isinstance(raw, dict) else []):
            key = correction_key(str(heard))
            written = str(written).strip()
            if key and written:
                self.by_correction[key] = written
        self.correction_span = max([len(k.split()) for k in self.by_correction] or [0])

    def __bool__(self):
        return bool(self.by_call or self.by_phrase or self.by_correction)

    def prompt(self):
        """The initial prompt for whisper, or "" if there is nothing to say.

        Only reached when a channel has explicitly turned it on — see PROMPT_FLAG for
        why that is off by default and what has to be measured before it is not.

        Callsigns first, because they are what the model gets wrong and what the log
        most needs right; tactical calls and stated terms fill whatever budget is left.
        Terms are dropped whole rather than truncated, since half a callsign is a word
        nobody says.

        Corrections are deliberately absent. Their left-hand side is what went wrong, and
        priming the model with it would make it likelier to produce the very text being
        corrected.
        """
        if not self:
            return ""
        lead = "Amateur radio net traffic. Stations and tactical calls on this net:"
        # A rough token count, deliberately pessimistic. whisper's tokenizer breaks an
        # upper-case alphanumeric run like K6DRK into several tokens, so counting one per
        # two characters over-estimates — which is the right direction to be wrong in,
        # because the cost of over-estimating is one callsign left out and the cost of
        # under-estimating is the tail being cut off silently inside whisper.
        budget = PROMPT_MAX_TOKENS - (len(lead) // 4 + 1)
        terms = []
        for term in self.callsigns + self.tactical + self.terms:
            cost = max(1, (len(term) + 1) // 2) + 1        # +1 for the separator
            if cost > budget:
                continue
            budget -= cost
            terms.append(term)
        return f"{lead} {', '.join(terms)}." if terms else ""


def _strings(value):
    """The non-empty strings in what should have been a list of them.

    Anything else is nothing. channels.json is written by a script from a server response
    and can be hand-edited on the device, and a string where a list was expected iterates
    into single characters — a vocabulary of "W", "i", "n", "d", "y" would match half the
    band. This runs on a receiver in a shed: it may do nothing, but it may not raise."""
    if not isinstance(value, list):
        return []
    return [str(v).strip() for v in value if str(v).strip()]


def phrase_key(tokens):
    """A tactical call reduced to what it sounds like: lower case, no punctuation,
    spoken numbers as digits. "sweet one" and "Sweep 1" both come out comparable."""
    words = []
    for token in tokens:
        bare = re.sub(r"[^0-9A-Za-z]", "", token).lower()
        if not bare:
            return ""
        words.append(SPOKEN_DIGITS.get(bare, bare))
    return " ".join(words)


def correction_key(text):
    """The heard-form of a correction, reduced to the one shape both ends compare on:
    lower case, and anything that is not a letter or a digit becomes a single space.

    transcriber_correction_key() in store.php does exactly this and must go on doing
    exactly this. Deliberately not phrase_key(): that maps spoken numbers to digits, and
    the server has no table to do the same with. Half a shared normalization is worse than
    none, because the halves disagree only on the rules nobody thought to test.
    """
    return re.sub(r"[^0-9A-Za-z]+", " ", text).strip().lower()


def closest(key, table):
    """What `key` clearly matches in `table` — a dict of what-it-sounds-like → what-it-is
    — or None if nothing does.

    Clearly: at or above SIMILARITY, and AMBIGUITY_MARGIN ahead of anything that would
    mean something different. Two equally good answers is not a near miss to be settled
    by ordering; it is the case where guessing puts somebody else's callsign in the log.

    Runners-up that mean the SAME thing do not count as competition, which is why the
    table maps to answers rather than being a list of keys — "alfa" and "alpha" are both
    A, and a mishearing sitting between them is not ambiguous about anything.
    """
    scored = sorted(
        ((difflib.SequenceMatcher(None, key, k).ratio(), k) for k in table), reverse=True)
    if not scored or scored[0][0] < SIMILARITY:
        return None
    best = table[scored[0][1]]
    for score, other in scored[1:]:
        if table[other] != best:
            return None if scored[0][0] - score < AMBIGUITY_MARGIN else best
    return best


def spell(tokens):
    """The letters and digits a run of spoken words stands for, or "" if it is not the
    sort of thing a callsign is made of.

    Three kinds of token qualify, and nothing else does:

      "kilo", "delta"   a NATO word, matched loosely because whisper will not hand us a
                        clean one. A wrong letter here cannot invent a callsign out of
                        ordinary speech, because whatever comes out still has to have the
                        shape of one — and CALLSIGN_RE needs a digit with letters either
                        side of it, which conversation does not produce.
      "six", "9"        a spoken or written digit
      "DRK", "K-60RK"   text already in capitals, which is how whisper writes the letters
                        it does recognize as letters

    The capitals are belt and braces rather than the thing holding this up — what
    actually stops "K6DRK on West Marin" collapsing into "K6DRK" and eating the "on" is
    the layer order in resolve(), which finds the exact callsign in one word before any
    two-word span is scored. But a run assembled out of ordinary lower-case words is not
    a callsign whatever it scores, and it should never be offered to the matcher at all.
    """
    out = []
    for token in tokens:
        bare = re.sub(r"[^0-9A-Za-z]", "", token)
        if not bare:
            return ""
        low = bare.lower()
        if low in SPOKEN_DIGITS:
            out.append(SPOKEN_DIGITS[low])
        elif low in NATO:
            out.append(NATO[low])
        elif bare == bare.upper() and len(bare) <= 6:
            out.append(bare)
        else:
            letter = closest(low, NATO)
            if letter is None:
                return ""
            out.append(letter)
    return "".join(out)


def tail(token):
    """Trailing punctuation, kept so replacing "K-6 DRK." does not lose the full stop."""
    m = re.search(r"[^0-9A-Za-z]+$", token)
    return m.group(0) if m else ""


def resolve(tokens, i, vocab):
    """What the words at `tokens[i]` turn out to be: (how many words, what to write), or
    (0, None) to leave this word exactly as it was heard.

    Strictly by confidence, stopping at the first layer that fits — and the layers are
    tried in order across every span length, not span by span. That distinction is the
    difference between "K6DRK I" finding the exact callsign in one word and finding a
    fuzzy match across two, which scores 0.91 and swallows the "I".
    """
    spans = []
    for n in range(min(MAX_SPAN, len(tokens) - i), 0, -1):
        spelled = spell(tokens[i:i + n])
        if spelled:
            spans.append((n, spelled))

    # 0. A correction somebody wrote down, on an exact match of the normalized form and on
    # nothing else. Never fuzzy: everything below can only ever write a callsign or a
    # phrase this event actually uses, while a correction says "replace this with that",
    # and a fuzzy one would let a single typo'd entry rewrite unrelated traffic into
    # whatever its author had in mind. One character out and it does nothing.
    #
    # Before the layers below, not after, and that is the point of it. A correction is an
    # instruction from somebody watching the log get this exact phrase wrong; the layers
    # below are a guess, a good one but a guess, and they run over the same words. Let them
    # go first and they consume the text the rule names — the rule then silently never
    # fires, which is precisely the failure somebody opened the box to fix. Running it
    # first also cannot make anything below worse: what it writes is what the event calls
    # the thing, so layer 1 would only have confirmed it.
    for n in range(min(vocab.correction_span, len(tokens) - i), 0, -1):
        key = correction_key(" ".join(tokens[i:i + n]))
        if key in vocab.by_correction:
            return n, vocab.by_correction[key]

    # 1. Exactly something this event knows. Nothing to decide.
    for n, spelled in spans:
        if spelled in vocab.by_call:
            return n, vocab.by_call[spelled]
    for n in range(min(vocab.phrase_span, len(tokens) - i), 0, -1):
        key = phrase_key(tokens[i:i + n])
        if key in vocab.by_phrase:
            return n, vocab.by_phrase[key]
    # The same letters in the same order, differently spaced — "boot jack" for Bootjack,
    # "Pan Toll" for Pantoll. Exact, not fuzzy: every character still has to be one that
    # was said, so this cannot invent anything, and it belongs up here with the other
    # certainties rather than down among the guesses. One token more than the phrase has
    # words, because one extra token is one split word; two is not a spelling of it, it is
    # a different sentence.
    for n in range(min(vocab.phrase_span + 1, len(tokens) - i), 0, -1):
        key = phrase_key(tokens[i:i + n]).replace(" ", "")
        if key and key in vocab.by_phrase_joined:
            return n, vocab.by_phrase_joined[key]

    # 2. Close enough to something this event knows, and to nothing else.
    #
    # Never over text that already reads as a callsign, which is the guard that keeps
    # this honest. K6DRJ scores 0.80 against K6DRK, so a visiting station whose call is
    # one letter away from a roster entry would be rewritten into that entry and logged
    # as somebody else — and the roster is never the whole band. A well-formed callsign
    # is not evidence of mangling; it is a callsign. It goes in as heard.
    for n, spelled in spans:
        if CALLSIGN_RE.fullmatch(spelled):
            continue
        match = closest(spelled, vocab.by_call)
        if match:
            return n, match
    #
    # A span is only ever compared with phrases of the same number of words, which is the
    # phrase-shaped version of the guard above. difflib scores "at cardiac" against
    # "cardiac" at 0.82 — one short extra word barely moves the ratio — so a one-word
    # place name on the list will happily eat the word in front of it and the log loses a
    # word with no sign that anything happened. A mishearing of an n-word phrase is n
    # words; the one case where it is not — a word whisper split in two — is already
    # settled exactly, above, so nothing is given up by declining it here.
    for n in range(min(vocab.phrase_span, len(tokens) - i), 0, -1):
        key = phrase_key(tokens[i:i + n])
        if not key:
            continue
        match = closest(key, vocab.by_phrase_n.get(n, {}))
        if match:
            return n, match

    # 3. No list to check against, or nothing on it fits — but the words have the shape
    # of a callsign, so join them up. This is all that is available on an event with no
    # roster, and it cannot invent anything: every character it writes was spoken.
    for n, spelled in spans:
        if CALLSIGN_RE.fullmatch(spelled):
            return n, spelled

    # 4. Leave it alone.
    return 0, None


def correct_callsigns(text, vocab=None):
    """The same text with callsigns and tactical calls written the way they are written.

    Runs on the transcription AFTER loggable() has passed it, never before. Both of the
    guards against invented speech are calibrated on what whisper actually emits —
    HALLUCINATIONS on its exact wording, loop_ratio on its repetition — and rewriting
    words underneath them would be quietly changing what they measure. Correcting text
    that is about to be discarded would also be work for nothing. This tidies up an
    entry that has already been judged real; it has no vote in that judgement, and it
    must never be able to turn something into an entry that would not have been one.
    """
    vocab = vocab or Vocabulary()
    tokens = text.split()
    out, i = [], 0
    while i < len(tokens):
        n, replacement = resolve(tokens, i, vocab)
        if replacement is None:
            out.append(tokens[i])
            i += 1
        else:
            out.append(replacement + tail(tokens[i + n - 1]))
            i += n
    return " ".join(out)


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


# What marks a clip that was cut at MAX_CLIP_SECONDS rather than at the end of a
# transmission. It rides along in the file name because that is the one thing that
# survives the queue between the capture thread and the transcribing one — and it makes
# the anomaly obvious in a directory listing, where a "-open" clip is the only one that
# means something is wrong.
CAPPED_MARK = "-open"


def is_capped(path):
    """Was this clip cut by the cap rather than by the carrier dropping?"""
    return CAPPED_MARK in os.path.basename(path)


def write_clip(directory, audio, seq, capped=False):
    """One transmission, as a wav whisper can read."""
    path = os.path.join(directory, f"clip_{seq:05d}{CAPPED_MARK if capped else ''}.wav")
    with wave.open(path, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SAMPLE_RATE)
        w.writeframes(audio)
    return path


# ── calibration ──────────────────────────────────────────────────────────────
#
# Two numbers, measured in this order and cached together: the tuner gain, and then the
# squelch level AT that gain. Together because a squelch level means nothing on its own —
# rtl_fm's -l compares received power against a threshold and the gain decides what that
# power is, so a squelch cached beside no gain is a number measured against something
# nobody wrote down. That is the bug this pairing exists to prevent, and we have had it.
#
# On demand only. There is no expiry and nothing measures at startup: calibration takes
# the channel off the air for a couple of minutes, and a channel going deaf at an hour
# nobody chose — during a net, say — is a worse outcome than one running slightly stale
# numbers. It happens when somebody presses Recalibrate in the channel manager, and the
# consequence is that a freshly deployed receiver runs on the compiled-in defaults until
# they do. The manager says "never calibrated" in so many words for exactly that reason.

# Ascending, so the winner is the LOWEST level that shuts out this site's
# noise — the most sensitive setting that still gates, rather than a safe-but-deaf one.
# The step is the margin: landing on 30 means 20 let noise through, so the true edge is
# between them and there is up to a step of headroom against drift.
# From 10, not 0. Zero is not a squelch level — it is the absence of one, and rtl_fm
# with -l 0 gates nothing at all. It was in this list, and a measurement returned it.
SQUELCH_CANDIDATES = list(range(10, 201, 10))
QUIET_FRACTION = 0.05        # under 5% of the full sample rate counts as "shut"

# Tuner gains to try, in dB, all of them steps the R820T actually offers. A subset of its
# 29, about 4 dB apart: the knee is found from the SLOPE between neighbours, and steps
# closer together than a floor measurement is repeatable would be reading noise.
#
# The list stops at 32.8 and not at the tuner's 49.6 dB maximum, and that cap is doing
# real work. The knee is measured on an IDLE channel, and the failure that got a hardcoded
# 40 removed is not visible from there: front-end overload from a strong transmitter
# somewhere else in the band, which is not on this frequency and need not be transmitting
# while the sweep runs. Nothing the sweep can measure would object to 40 dB. So the sweep
# is allowed to find the knee and is not allowed to chase it up to where this tuner stops
# being linear.
GAIN_CANDIDATES = [8.7, 12.5, 16.6, 20.7, 25.4, 29.7, 32.8]

# How much of each dB of gain has to reach the noise floor before the receiver counts as
# hearing the band rather than its own converter.
#
# At the knee itself the two contribute equally and the floor rises 0.5 dB per dB, which
# is 3 dB of sensitivity given away to the receiver's own noise. At 0.7 the converter adds
# about 1 dB and everything above that buys hundredths of a dB in exchange for headroom
# the sweep cannot measure the cost of. So: past the knee, and not far past it.
KNEE_SLOPE = 0.7

# How far a repeated floor measurement may differ, in dB, before the sweep is thrown away.
# An RMS over a quarter of a million samples repeats to a small fraction of a dB, so this
# is not measurement scatter — it is somebody transmitting.
FLOOR_TOLERANCE = 1.0

# How long each floor measurement listens, and how long opening the dongle and letting the
# tuner settle costs before it can. The second is not a guess: _sample_rtl has discarded
# exactly this much as warm-up since the first idle-frequency measurement, and both the
# floor sweep and the countdown the manager shows are built on it.
FLOOR_SECONDS = 1.0
RTL_OPEN_SECONDS = 1.5

# What the raw I/Q is sampled at. Not the 200000 rtl_argv asks for: rtl_fm oversamples and
# decimates internally, while rtl_sdr hands the rate straight to the tuner, and the
# RTL2832U will not deliver below about 225 kHz.
IQ_RATE = 250000


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


def gain_text(gain):
    """A tuner gain written the way a person would write it: 30, not 30.0, and 16.6 as it
    is. It reaches rtl_fm's command line and the journal, and both are read by people."""
    return f"{float(gain):g}"


def choose_gain(measure, candidates=GAIN_CANDIDATES, slope=KNEE_SLOPE,
                tolerance=FLOOR_TOLERANCE):
    """The lowest gain at which this receiver hears the band rather than itself.
    Returns (gain, "") or (None, why).

    `measure(gain) -> the noise floor in dB, or None if the receiver produced nothing`,
    injected the way choose_squelch's sampler is, so the knee-finding can be tested
    against synthetic curves without a radio.

    NOT "the most sensitive gain whose noise the squelch still gates", which is the
    obvious thing to measure and is wrong. Gating and sensitivity pull in opposite
    directions, and on a dead band only gating can be measured at all — so optimizing for
    it alone walks the gain down until the receiver gates beautifully and hears nothing.
    That is this project's recurring failure and it must not come back.

    The knee is a question a dead band can answer. Raise the gain a step at a time and
    watch the noise floor. While the receiver is limited by its own converter the floor
    rises LESS than each gain increment — the ADC's noise does not care how much gain
    precedes it. Once it is limited by thermal noise arriving from the antenna, the floor
    rises 1:1, because that noise is being amplified along with everything else. Where the
    slope crosses KNEE_SLOPE is where the receiver stops hearing itself and starts hearing
    the band, and no signal has to be present for any of it.

    Three ways it refuses to answer, and each names itself:

      - the receiver hands back nothing            the tuner has not locked
      - the floor never follows the gain           there is no antenna on it
      - the floor moves while being measured       somebody is transmitting

    The last is the one worth being careful about, because what traffic produces is not a
    wild answer but a plausible one: a floor that jumps at one gain looks exactly like a
    knee. Three checks catch it, and none of them depends on hearing what was said. A
    floor that FALLS as the gain rises cannot happen, so it is proof something was on the
    air and has stopped. The bottom of the sweep is measured again at the end, which
    catches the harder case — a carrier that comes up mid-sweep and is still up at the
    end, agreeing with itself everywhere it is asked. And the knee itself is confirmed by
    measuring its two points a second time, the way choose_squelch confirms a quiet level.
    """
    floors = []
    for gain in candidates:
        floor = measure(gain)
        if floor is None:
            return None, "the receiver produced no samples — the tuner has not locked"
        floors.append(floor)

    # The band has to have been idle throughout, not merely idle when we started.
    again = measure(candidates[0])
    if again is None:
        return None, "the receiver stopped producing samples part way through"
    if abs(again - floors[0]) > tolerance:
        return None, ("the noise floor moved while it was being measured — something was "
                      "transmitting")
    for i in range(1, len(floors)):
        if floors[i] < floors[i - 1] - tolerance:
            return None, ("the noise floor fell as the gain rose, which cannot happen — "
                          "something was transmitting")

    for i in range(1, len(candidates)):
        rise = (floors[i] - floors[i - 1]) / (candidates[i] - candidates[i - 1])
        if rise < slope:
            continue
        # The first candidate can never be the answer: it has nothing below it to measure
        # a slope against. A site noisy enough to be past the knee at the bottom of the
        # sweep therefore gets the second step, which is the lowest gain we can actually
        # show is hearing the band — a lower one might do as well, and might not.
        #
        # Confirm before caching it, exactly as choose_squelch confirms a quiet level:
        # measure the same two points again and require the same 1:1 rise. A single
        # measurement that happened to catch a key-up is the whole failure mode here.
        below, at = measure(candidates[i - 1]), measure(candidates[i])
        if below is None or at is None:
            return None, "the receiver stopped producing samples part way through"
        if (at - below) / (candidates[i] - candidates[i - 1]) < slope:
            return None, ("the noise floor did not rise the same way twice — something "
                          "was transmitting")
        return candidates[i], ""

    return None, ("the noise floor did not rise with the gain at all, so the receiver is "
                  "hearing itself rather than the antenna — check the antenna and its "
                  "connector")


def calibration_seconds():
    """About how long a calibration takes, for the countdown the manager shows.

    An estimate, and the manager says "about". The gain sweep is exact — a fixed number of
    measurements of a known length. The squelch scan is not: it stops at the first level
    that gates, which on a normal site is the second or third of twenty, and it is worth
    being wrong here rather than making the page invent a number of its own.
    """
    floor = RTL_OPEN_SECONDS + FLOOR_SECONDS
    # The sweep, the re-measured bottom, and the two-point confirmation.
    sweep = (len(GAIN_CANDIDATES) + 3) * floor
    # One full look at one squelch level is two passes, each with its own warm-up. Four
    # levels: the dead-input check, and the two or three the scan gets through.
    look = 2 * RTL_OPEN_SECONDS + 5.0
    return int(sweep + 4 * look)


def rtl_iq_argv(channel, gain, seconds):
    """How the raw I/Q is captured for a noise-floor measurement.

    rtl_sdr rather than rtl_fm, and that is not an inconsistency with rtl_argv. The noise
    floor is a question about RF power, and FM demodulation throws power away: on a dead
    band rtl_fm's output is full-scale hiss whatever the tuner gain is, so a floor
    measured through it would barely move across the sweep and the knee would never
    appear. The raw I/Q is the only place the question can be asked.

    By bare serial, for the reason rtl_argv gives at length — the same verbose_device_search
    is behind both, and the same wrong forms fail the same silent way.

    -n rather than a timer: rtl_sdr exits after that many samples, which is exact, and the
    warm-up is taken off the front of what comes back instead of being waited out.
    """
    return ["rtl_sdr", "-d", channel.serial, "-f", str(channel.frequency),
            "-s", str(IQ_RATE), "-g", gain_text(gain),
            "-n", str(int(IQ_RATE * (RTL_OPEN_SECONDS + seconds))), "-"]


def floor_db(iq):
    """The power in a block of raw unsigned-8-bit I/Q, in dB, or None if there is none.

    Each half keeps its own DC offset, which is why they are counted separately: the
    tuner's I and Q offsets differ by a count or two, and at the bottom of the sweep —
    where the entire point is that the floor is barely above the converter's own noise —
    a DC error of one count would be most of the answer.

    Counted through a 256-entry histogram rather than sample by sample. This runs on a Pi
    over half a megabyte per measurement, and bytes.count() does each scan in C.
    """
    power = 0.0
    for half in (iq[0::2], iq[1::2]):
        if not half:
            return None
        hist = [half.count(v) for v in range(256)]
        mean = sum(v * n for v, n in enumerate(hist)) / len(half)
        power += sum((v - mean) ** 2 * n for v, n in enumerate(hist)) / len(half)
    if power <= 0:
        return None                      # a constant stream is not a noise floor
    return 10 * math.log10(power)


def measure_floor(channel, gain, seconds=FLOOR_SECONDS):
    """This site's noise floor at one tuner gain, in dB, or None if nothing came back."""
    try:
        out = subprocess.run(rtl_iq_argv(channel, gain, seconds),
                             stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                             timeout=RTL_OPEN_SECONDS + seconds + 15)
    except (OSError, subprocess.SubprocessError) as e:
        log.warning("could not measure the noise floor at %s dB (%s)", gain, e)
        return None
    # The burst produced while the device opens and the tuner settles says nothing about
    # the noise floor, so it is cut off the front rather than waited out.
    settled = out.stdout[int(IQ_RATE * RTL_OPEN_SECONDS) * 2:]
    if len(settled) < IQ_RATE:           # less than half the wanted samples: not a reading
        return None
    return floor_db(settled)


def calibration_path(spool):
    return os.path.join(spool, "calibration.json")


def calibration_load(spool, frequency=None):
    """What was measured for this channel, or None if nothing usable was.

    Both numbers or neither. A cache carrying a squelch and no gain is what the previous
    version of this file wrote, and honouring half of it would put a level measured at an
    unknown gain back on the air — which is the failure the pairing exists to prevent, so
    it reads as "never calibrated" and the channel falls back to the compiled-in pair.

    No age check. A measurement describes where the receiver is, not when it was taken,
    and re-measuring it on a timer takes a channel off the air at an hour nobody chose.
    """
    try:
        with open(calibration_path(spool), encoding="utf-8") as fh:
            saved = json.load(fh)
        gain = float(saved["gain"])
        squelch = int(saved["squelch"])
    except (OSError, ValueError, KeyError, TypeError):
        return None
    if gain <= 0 or squelch <= 0:
        return None
    # Belt and braces: the channel id already carries the frequency, so a retune makes a
    # new spool directory. If one ever does not, a measurement made on another frequency
    # is exactly the kind of borrowed number this whole change is about.
    if frequency and str(saved.get("frequency") or "") not in ("", str(frequency)):
        log.warning("the cached calibration was measured on %s, not %s; ignoring it",
                    saved.get("frequency"), frequency)
        return None
    return {"gain": gain, "squelch": squelch, "when": float(saved.get("when") or 0)}


def calibration_save(spool, result):
    try:
        with open(calibration_path(spool), "w", encoding="utf-8") as fh:
            json.dump(result, fh)
    except OSError as e:
        log.error("could not write the calibration down (%s); it will have to be "
                  "measured again", e)


def calibration_for(channel, spool):
    """The gain and squelch this channel should open with, and what was measured here.

    Precedence is the same for both and in this order: a value set in the manager wins,
    because somebody typed it on purpose; then whatever was measured for this site; then
    the compiled-in default. The third case is a receiver nobody has calibrated yet, and
    it is a normal state to be in rather than an error — it is just one the manager has to
    show, or a new Pi quietly runs numbers measured somewhere else.
    """
    measured = calibration_load(spool, channel.frequency)
    gain = channel.gain or (measured or {}).get("gain") or DEFAULT_GAIN
    squelch = channel.squelch or (measured or {}).get("squelch") or DEFAULT_SQUELCH
    return gain, squelch, measured


def calibrate(channel, spool, measure=None, sample=None):
    """Measure this channel's gain and then its squelch AT that gain, and cache the two
    together. Returns (what was written, "") or (None, why).

    In that order and never the other way round: the squelch is a threshold on received
    power, and changing the gain afterwards would change what it is a threshold on. Both
    samplers are injectable for the tests, which is the only way any of this can be
    exercised without a radio.

    The order also covers the one thing the sweep cannot see for itself. A carrier that
    was already up before the sweep began and is still up at the end is consistent
    everywhere the sweep looks, and it tracks the gain 1:1 from the bottom — so it reads
    as a receiver that was past the knee all along. The squelch scan that follows cannot
    miss it: nothing gates a channel somebody is transmitting on, so it returns None and
    the whole calibration is abandoned rather than cached.
    """
    measure = measure or (lambda gain: measure_floor(channel, gain))
    gain, why = choose_gain(measure)
    if gain is None:
        return None, why
    log.info("gain %s dB — the knee, measured for this site", gain_text(gain))

    # The squelch is measured through a receiver opened at the gain just chosen. A copy,
    # so nothing here changes what the caller holds.
    at_gain = copy.copy(channel)
    at_gain.gain = gain
    sample = sample or (lambda level, secs: _sample_rtl(at_gain, level, secs))
    level = choose_squelch(sample)
    if level is None:
        return None, (f"nothing shut the receiver up at {gain_text(gain)} dB — either "
                      f"the channel was busy throughout, or the receiver stopped "
                      f"producing samples")

    result = {"gain": gain, "squelch": level, "when": time.time(),
              "frequency": str(channel.frequency)}
    calibration_save(spool, result)
    return result, ""


def emit(**fields):
    """One line of JSON on stdout, flushed as it is written.

    calibrate.sh reads these as they appear and forwards each to the server. That is what
    makes the manager's countdown honest: the device says when the radio work actually
    started, which can be up to a minute after the button was pressed, rather than the
    page counting down from a moment that meant nothing.

    stdout is these lines and nothing else — everything else the worker says goes to the
    log, which is stderr.
    """
    print(json.dumps(fields), flush=True)


def run_calibration(channel, spool):
    """Measure this channel now, reporting on stdout as it goes. 0 if it worked.

    Everything around it — stopping the channel, telling the server, starting it again —
    belongs to calibrate.sh, which runs as root and holds the DEVICE token. This holds the
    channel token and the radio, and does neither of those jobs.
    """
    if not receiver_alive(channel):
        emit(state="failed",
             error="the receiver is not producing samples — the tuner has not locked")
        return 1
    emit(state="started", expected=calibration_seconds())
    result, why = calibrate(channel, spool)
    if result is None:
        log.error("calibration failed: %s", why)
        emit(state="failed", error=why)
        return 1
    log.info("gain %s dB, squelch %s — measured and cached",
             gain_text(result["gain"]), result["squelch"])
    emit(state="done", gain=result["gain"], squelch=result["squelch"])
    return 0


def rtl_argv(channel, level):
    """How this receiver is opened, at a given squelch level.

    One definition, because three things open the dongle and every one of them has to do
    it identically or it is measuring a different receiver: the capture loop, the
    calibration sampler, and compare-models.py's deliberate noise pass. Only the squelch
    level differs between them, and that is the argument.

    The dongle is addressed by USB SERIAL, never by index: index order is not stable
    across reboots or re-plugs, and two channels silently swapping frequencies is the
    kind of fault nobody notices until the log is wrong. "-d <serial>", not
    "-d serial=<serial>" — rtl_fm's verbose_device_search tries the argument as an index,
    then as an exact serial, then as a prefix, and the SoapySDR "serial=" form is none of
    them. It fails in the worst possible way: the device is listed and then not selected,
    so rtl_fm exits without ever tuning and the channel looks like a dead frequency.

    Oversample and resample — "-s 200000 -r 16000", never "-s 16000" directly. The
    RTL2832U cannot sample below about 225 kHz, so asking for the low rate makes rtl_fm
    decimate internally and the audio comes out mangled. It is not obviously broken to
    look at — the recording had a healthy 0.10 RMS and a clean waveform — but it is
    unintelligible, and whisper answers unintelligible audio by inventing something. On a
    30-second recording of a station reading out temperatures it produced "(I'm not a
    fan)" and nothing else; the same 30 seconds captured this way transcribed every place
    name and number correctly. 16000 is whisper's own rate, so nothing resamples it
    afterwards.

    -E deemp applies FM de-emphasis, which voice needs and without which the high end is
    harsh enough to cost accuracy.
    """
    # Never without -g. rtl_fm's default is automatic gain, and automatic gain and an RF
    # squelch cannot both work — see DEFAULT_GAIN. A channel with nothing measured and
    # nothing set falls back to the compiled-in value rather than to AGC, so there is no
    # path through this file that opens the receiver with the gain floating.
    return ["rtl_fm", "-d", channel.serial, "-f", str(channel.frequency),
            "-M", "fm", "-s", "200000", "-r", str(SAMPLE_RATE), "-E", "deemp",
            "-l", str(level), "-g", gain_text(channel.gain or DEFAULT_GAIN)]


def _sample_rtl(channel, level, seconds):
    """Bytes rtl_fm emits at this squelch level over `seconds`."""
    p = subprocess.Popen(rtl_argv(channel, level),
                         stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        # Opening the device and settling the tuner produces a burst that says nothing
        # about the noise floor. Discard it before counting.
        warmup = time.time() + RTL_OPEN_SECONDS
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

    How the dongle itself is opened is rtl_argv's business — the serial, the oversampling
    and the de-emphasis are the same for everything that opens it, and the squelch level
    is the only thing this decides.
    """
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
    # The gain and the squelch, settled together — see calibration_for(). Nothing is
    # measured here: calibration runs when somebody asks for it, and a channel starting up
    # must be listening again in seconds rather than deaf for minutes.
    #
    # Say where both numbers came from, every time. They are the only two that decide what
    # gets recorded — too low a squelch and the Pi spends its afternoon transcribing
    # static, too high and it is quietly deaf, and the gain decides what "low" means — and
    # the first line after a restart is where anybody looks. The never-calibrated case
    # says so at length, because the alternative is a receiver running numbers measured on
    # a different hill and looking perfectly healthy while it does.
    gain, level, measured = calibration_for(channel, spool)
    channel.gain = gain
    if channel.squelch:
        log.info("squelch %s — set in the manager for this channel", channel.squelch)
    if measured:
        log.info("gain %s dB, squelch %s — measured for this site %.1f hours ago",
                 gain_text(gain), measured["squelch"],
                 (time.time() - measured["when"]) / 3600)
    else:
        log.info("gain %s dB, squelch %s — the built-in defaults. This channel has never "
                 "been calibrated; press Recalibrate in the channel manager to measure "
                 "the site it is actually on.", gain_text(gain), level)
    argv = rtl_argv(channel, level)
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


class OpenCarrier:
    """Tells a transmitter that has stuck down from a channel that is genuinely in use.

    Both look identical from the capture loop: samples arrive and never stop. The
    difference is not in the audio — an RF squelch has already decided something is
    transmitting, and a stuck microphone in a car is as loud as a conversation — it is
    in whether there are words in it. So the answer comes back from the transcribing
    thread, one capped segment at a time: BARREN_SEGMENTS in a row that whisper had
    nothing to say about, and we stop spending on this carrier.

    That has to be paid for, because it is expensive to be wrong. The careful model
    takes about 100 seconds per 120-second segment, so transcribing a stuck carrier
    keeps the worker permanently busy, puts every real transmission behind it, and the
    log falls further behind the radio for as long as the fault lasts — which for a
    stuck PTT in somebody's car can be hours.

    Stopping outright would be wrong too, and this is the part worth remembering. One
    of the two faults that produces an endless carrier is a squelch that has stopped
    gating, and in that one real traffic is still arriving — buried in a recording that
    never ends, but arriving. Going deaf to it would turn a degraded channel into a
    dead one. So after giving up we still take one segment in RECHECK_SEGMENTS, and any
    segment with words in it puts the channel straight back to normal.

    Both threads touch this, so it is behind a lock.
    """

    def __init__(self, tolerate=BARREN_SEGMENTS, recheck=RECHECK_SEGMENTS):
        self.tolerate = tolerate
        self.recheck = recheck
        self._lock = threading.Lock()
        self._segments = 0        # capped segments cut from the transmission in progress
        self._barren = 0          # consecutive ones whisper made nothing of
        self._skipped = 0         # segments dropped since we stopped transcribing

    @property
    def skipping(self):
        """Whether this carrier is currently being treated as stuck."""
        with self._lock:
            return self._barren >= self.tolerate

    def segment(self):
        """A transmission has just been cut at the cap. Whether to transcribe this one.

        Called on the capture thread, so it must not block and must not care that the
        verdict on the previous segment may not have arrived yet. It usually has —
        even the careful model finishes a segment inside the two minutes the next one
        takes to record — and when the worker is behind, the effect is only that one
        more segment is transcribed before the channel gives up.
        """
        with self._lock:
            self._segments += 1
            if self._barren < self.tolerate:
                return True
            self._skipped += 1
            if self._skipped >= self.recheck:
                self._skipped = 0
                return True             # a look, in case somebody is talking now
            return False

    def verdict(self, logged):
        """What a capped segment turned out to contain. Called on the worker thread."""
        with self._lock:
            self._barren = 0 if logged else self._barren + 1
            if self._barren == self.tolerate:
                log.warning(
                    "%d minutes of unbroken carrier with nothing worth logging in it — "
                    "a stuck transmitter, or a squelch that is no longer gating. Not "
                    "transcribing any more of it, apart from one segment in %d to see "
                    "whether anybody is talking.",
                    self.tolerate * MAX_CLIP_SECONDS // 60, self.recheck)

    def dropped(self):
        """The carrier dropped, whatever it was. Called on the capture thread."""
        with self._lock:
            if self._segments:
                log.info("the carrier finally dropped, after about %d minutes",
                         self._segments * MAX_CLIP_SECONDS // 60)
            self._segments = self._barren = self._skipped = 0


def transcribe_loop(work, channel, whisper, model, outbox, stopping, carrier=None):
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
            logged = handle_clip(channel, path, whisper, model, outbox)
            # Only a capped segment answers the question OpenCarrier is asking. An
            # ordinary over that came back empty is a squelch tail, and there are
            # hundreds of those a day.
            if carrier is not None and is_capped(path):
                carrier.verdict(bool(logged))
            outbox.flush(lambda t: post_log_entry(channel, t))
        except Exception:                       # noqa: BLE001 - one bad clip must not
            log.exception("transcription failed")   # stop the channel transcribing


def handle_clip(channel, path, whisper, model, outbox):
    """Transcribe one clip and queue whatever it said. True if anything was logged."""
    seconds = clip_seconds(path)
    if seconds < MIN_CLIP_SECONDS:
        log.debug("ignoring %.1fs clip", seconds)
        os.unlink(path)
        return False
    if seconds >= MAX_CLIP_SECONDS:
        # Not an over — see MAX_CLIP_SECONDS. Transcribe it all the same: it was
        # recorded, whether there are voices in it is the one question that separates
        # the two faults it can be, and OpenCarrier needs that answer to decide whether
        # to keep listening. What this must never do again is delete it. The previous
        # version tested `> MAX_CLIP_SECONDS` against a clip the capture loop had
        # overshot to 120.1 seconds, so every capped clip ever made was unlinked
        # without reaching whisper, and the journal said "discarding 120s clip — open
        # carrier?" as if that were a considered decision.
        log.warning("%.0fs of carrier without a break — a stuck transmitter, or a "
                    "squelch that is no longer gating. Transcribing it anyway; check "
                    "the squelch level for this site if it keeps happening.", seconds)
    vocabulary = getattr(channel, "vocabulary", None) or Vocabulary()
    prompt = vocabulary.prompt() if getattr(channel, "initial_prompt", False) else None
    text = clean(transcribe(whisper, model, path, seconds, prompt=prompt))
    os.unlink(path)
    keep = loggable(text)
    if not keep:
        log.info("discarded (%.1fs): %r", seconds, text[:60])
        return False
    if keep != text:
        log.info("trimmed a repeated phrase out of (%.1fs): %r", seconds, text[:60])
    # After the guards, never before: they are calibrated on what whisper emits, and this
    # has no vote in whether the entry is real. See correct_callsigns.
    written = correct_callsigns(keep, vocabulary)
    if written != keep:
        log.info("callsigns (%.1fs): %r → %r", seconds, keep[:60], written[:60])
    log.info("logging (%.1fs): %s", seconds, written[:80])
    outbox.add(written, time.time())
    return True


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
                   help="measure this site's tuner gain and squelch now, cache them, and "
                        "exit. The dongle must be free: stop the channel first, or let "
                        "calibrate.sh do it.")
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

    # Before whisper is looked for and before anything is swept: calibration is a radio
    # job and nothing else, and a device whose model is missing should still be able to
    # measure its site.
    if args.calibrate:
        return run_calibration(channel, spool)

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
    carrier = OpenCarrier()
    worker = threading.Thread(
        target=transcribe_loop,
        args=(work, channel, whisper, model, outbox, stopping, carrier),
        daemon=True, name="transcribe")
    worker.start()

    enqueue = work.put

    audio = bytearray()          # the transmission currently being received
    seq = 0
    last_data = None             # when samples last arrived; None between overs
    # A whole number of samples, and therefore an even number of bytes: the buffer is cut
    # at this offset, and half a 16-bit sample would shift every sample after it.
    max_bytes = int(MAX_CLIP_SECONDS * SAMPLE_RATE) * 2
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
                    # The tail of a carrier already judged stuck is more of the same.
                    if not carrier.skipping:
                        enqueue(write_clip(clips, bytes(audio), seq))
                    audio.clear()
                    last_data = None
                    carrier.dropped()

                # A carrier that never drops would otherwise grow one clip forever.
                #
                # Cut at exactly the cap and keep the remainder, rather than writing the
                # whole buffer and clearing it. The reads overshoot — a clip written
                # this way measured 120.1 seconds — and downstream that tenth of a
                # second was the difference between a clip being transcribed and being
                # deleted unheard.
                #
                # No search for a pause to cut at, and no overlap between segments.
                # Two minutes into an unbroken carrier there is no sentence being
                # chopped in half; there is a fault being sampled. Overlap would only
                # put the same words in the log twice, which is the exact shape of the
                # invented text the filters downstream exist to keep out.
                if len(audio) >= max_bytes:
                    seq += 1
                    segment = bytes(audio[:max_bytes])
                    del audio[:max_bytes]
                    if carrier.segment():
                        enqueue(write_clip(clips, segment, seq, capped=True))

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
        if audio and not carrier.skipping:
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

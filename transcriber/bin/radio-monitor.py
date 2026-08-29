#!/usr/bin/env python3
"""
radio-monitor.py — MARS APRS Transcriber (hardware-receiver trial)

Logs every transmission heard on the USB audio input, so the hardware squelch can
be characterised against the SDR corpus. One JSON line per over in
/home/pi/radio-monitor.jsonl, with the audio beside it in /home/pi/radio-clips/.

The thresholds are not guesses: measured 2026-08-28 on 146.700 with AGC off, the
squelch-closed floor is -79 dBFS across a flat 2 dB, and speech peaks near -13.
Opening at -55 sits in the middle of a 60 dB no-man's-land, which is the whole
point of moving the squelch into hardware.

Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
©2025 Doug Kaye, K6DRK <doug@rds.com>
"""
import json, math, os, subprocess, time, urllib.request, wave
import numpy as np

DEV, RATE, FRAME = "plughw:2,0", 16000, 1600          # 100 ms frames
OPEN_DB, CLOSE_DB = -55.0, -60.0                      # hysteresis, so a pause is not an end
# 2.0 s of hang, not 0.6.
#
# A Morse ID keys the tone on and off, and at the ~11 wpm this repeater sends, a
# word gap is about 770 ms. With 0.6 s of hang the gate closed inside the ID's own
# gaps and reopened on the next character, so a single ID arrived as two or three
# fragments -- each with too few marks to decode, and with misleading durations
# (2.4 s and 4.3 s looked like two different IDs; they may be pieces of one).
#
# 2.0 s spans a word gap comfortably while still being far shorter than the pause
# between two separate transmissions on a net.
HANG, MINSEC, MAXSEC = 2.0, 0.6, 180.0
OUT, LOG = "/home/pi/radio-clips", "/home/pi/radio-monitor.jsonl"
os.makedirs(OUT, exist_ok=True)

# Only 200-3500 Hz counts towards the gate.
#
# A squelch thump is not a transmission, but it is loud: the clip at 21:43 on
# 2026-08-28 had 97.6% of its energy below 100 Hz, peaks at 4-12 Hz, and 0.1% in
# the 1200-1800 Hz band where the repeater's Morse ID actually lives. Measured
# broadband it looks exactly like signal; band-limited it disappears. Nothing below
# 100 Hz is ever real here -- voice and the ID tones are 300-3000 Hz.
#
# Done on the spectrum rather than with an IIR filter because there is no scipy on
# this Pi, and Parseval makes the band-limited rms exact without filter state:
# sum(x^2)/N == sum(|X_k|^2)/N^2 over the full spectrum, and the interior bins of
# an rfft each stand for two.
BAND_LO, BAND_HI = 200.0, 3500.0
_bin = RATE / float(FRAME)
_lo, _hi = int(BAND_LO / _bin), int(BAND_HI / _bin)

def db(x):
    return 20.0 * np.log10(max(float(x), 1e-9) / 32768.0)

def band_db(frame):
    """rms of `frame` restricted to BAND_LO..BAND_HI, in dBFS."""
    X = np.fft.rfft(frame)
    ms = 2.0 * (np.abs(X[_lo:_hi]) ** 2).sum() / (len(frame) ** 2)
    return db(math.sqrt(ms))

p = subprocess.Popen(
    ["arecord", "-D", DEV, "-f", "S16_LE", "-r", str(RATE), "-c", "1", "-t", "raw", "-q"],
    stdout=subprocess.PIPE)

# A minute-by-minute level log, written whether or not anything crosses the
# threshold. Without it, silence in the transmission log is ambiguous: it cannot
# distinguish "nobody transmitted" from "somebody did and it arrived below OPEN_DB".
LEVELS = "/home/pi/radio-levels.jsonl"
win, wide_win, win_start = [], [], time.time()

# The live level, republished every frame for any number of viewers.
#
# arecord holds the capture device exclusively, so a meter cannot open its own
# stream while this is running -- and stopping the monitor to look at a meter is
# exactly backwards. One reader, many viewers. /dev/shm is tmpfs, so this costs no
# SSD writes at 10 Hz.
LIVE = "/dev/shm/radio-level"

# ── reporting the level to the channel manager ───────────────────────────────
#
# So marsaprs.org/transcriber/ can draw a calibration meter for somebody standing at the
# radio rather than at a terminal. Same band-limited number the local meter uses.
#
# Not sent every second forever: that is 86,000 requests a day for a value nobody is
# watching. Sent while there is something to see -- the level is above SEND_FLOOR, which
# is true exactly when the squelch is open (which is the calibration procedure) or a
# transmission is happening -- plus a keepalive, so the page can tell "quiet" from
# "this device died".
SERVER      = "https://marsaprs.org/transcriber/report.php"
TOKEN_FILE  = "/home/pi/.transcriber-token"
CHANNELS    = "/etc/transcriber/channels.json"
SEND_FLOOR  = -60.0        # above this, something is worth metering
SEND_EVERY  = 1.0          # seconds, while there is something to send
KEEPALIVE   = 20.0         # seconds, so silence is distinguishable from death

def _report_identity():
    """(device, token, channel) or None. Missing pieces are not an error worth stopping
    for -- the monitor's job is capturing audio, and the meter is a convenience."""
    try:
        token = open(TOKEN_FILE).read().strip()
        d = json.load(open(CHANNELS))
        chans = d.get("channels", d)
        chans = chans if isinstance(chans, list) else []
        ch = next((c for c in chans if c.get("enabled")), chans[0] if chans else None)
        if not token or not ch or not ch.get("id"):
            return None
        return os.uname().nodename, token, ch["id"]
    except Exception:
        return None

IDENT = _report_identity()

def report_level(db, wide):
    if not IDENT:
        return
    device, token, channel = IDENT
    body = json.dumps({"device": device, "token": token, "channel": channel,
                       "state": "level", "level_db": round(db, 1),
                       "wide_db": round(wide, 1)}).encode()
    # The User-Agent is required, not decoration. marsaprs.org is behind Cloudflare, whose
    # browser-integrity check answers "Python-urllib/3.x" with 403 and error code 1010 --
    # which looks exactly like a bad token until you read the body. Every other caller on
    # this Pi is curl, which passes because curl sends its own.
    req = urllib.request.Request(SERVER, data=body,
                                 headers={"Content-Type": "application/json",
                                          "User-Agent": "marsaprs-transcriber/1.0"})
    try:
        urllib.request.urlopen(req, timeout=3).read()
    except Exception:
        pass       # the meter is not worth interrupting the capture for

buf, in_tx, quiet, started = [], False, 0.0, None
last_sent = 0.0
while True:
    raw = p.stdout.read(FRAME * 2)
    if not raw or len(raw) < FRAME * 2:
        break
    f = np.frombuffer(raw, dtype="<i2").astype(float)
    f -= f.mean()
    wide = db(np.sqrt((f ** 2).mean()))     # everything, for the level log
    lvl = band_db(f)                        # 200-3500 Hz, what the gate judges

    try:
        with open(LIVE, "w") as fh:
            fh.write("%.1f %.1f\n" % (lvl, time.time()))
    except OSError:
        pass

    now_t = time.time()
    if (lvl > SEND_FLOOR and now_t - last_sent >= SEND_EVERY) or now_t - last_sent >= KEEPALIVE:
        last_sent = now_t
        report_level(lvl, wide)

    win.append(lvl)
    wide_win.append(wide)
    if time.time() - win_start >= 60.0:
        w = np.array(win)
        with open(LEVELS, "a") as fh:
            fh.write(json.dumps({
                "when": time.strftime("%Y-%m-%dT%H:%M:%S", time.localtime(win_start)),
                "frames": len(w),
                "max_db": round(float(w.max()), 1),
                "max_wide_db": round(float(max(wide_win)), 1) if wide_win else None,
                "p95_db": round(float(np.percentile(w, 95)), 1),
                "median_db": round(float(np.median(w)), 1),
                "min_db": round(float(w.min()), 1),
                "over_open": int((w > OPEN_DB).sum()),
            }) + "\n")
        win, wide_win, win_start = [], [], time.time()

    if not in_tx:
        if lvl > OPEN_DB:
            in_tx, started, buf, quiet = True, time.time(), [f], 0.0
        continue

    buf.append(f)
    quiet = quiet + 0.1 if lvl < CLOSE_DB else 0.0
    if quiet < HANG and len(buf) * 0.1 < MAXSEC:
        continue

    a = np.concatenate(buf)
    dur = len(a) / float(RATE)
    if dur >= MINSEC:
        ts = time.strftime("%Y%m%dT%H%M%S", time.localtime(started))
        with wave.open(os.path.join(OUT, ts + ".wav"), "w") as w:
            w.setnchannels(1); w.setsampwidth(2); w.setframerate(RATE)
            w.writeframes(a.astype("<i2").tobytes())
        e = np.sqrt((a[:len(a) // FRAME * FRAME].reshape(-1, FRAME) ** 2).mean(axis=1))
        rec = {"file": ts + ".wav",
               "when": time.strftime("%Y-%m-%dT%H:%M:%S", time.localtime(started)),
               "seconds": round(dur, 2),
               "peak_db": round(db(abs(a).max()), 1),
               "rms_db": round(db(np.sqrt((a ** 2).mean())), 1),
               "floor_db": round(db(e.min()), 1)}
        with open(LOG, "a") as fh:
            fh.write(json.dumps(rec) + "\n")
    in_tx, buf = False, []

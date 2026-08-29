#!/usr/bin/env python3
"""
vu.py — MARS APRS Transcriber (hardware-receiver trial)

Standalone level meter: opens the capture device itself, so radio-monitor.py does
NOT need to be running (and must not be -- only one process can hold the device).

Open the squelch, run this, turn the radio's volume until the bar reaches ^, then
close the squelch again.

Level is judged on the AUDIO BAND (200-4000 Hz), never the raw peak. On 2026-08-28
the raw peak belonged to a squelch thump generated after the volume control, which
no knob could change -- judging by it led to the volume being turned to zero.

Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
©2025 Doug Kaye, K6DRK <doug@rds.com>
"""
import math, subprocess, sys
import numpy as np

DEV, RATE, N = "plughw:2,0", 16000, 1600
MIN_OK, IDEAL, MAX_OK = -38.0, -27.0, -20.0
LO, HI, WIDTH = -70.0, 0.0, 56
_f = np.arange(N//2 + 1) * (RATE/float(N))
_band = (_f >= 200) & (_f < 4000)
_win = np.hanning(N)

def pos(db):
    return max(0, min(WIDTH-1, int((db-LO)/(HI-LO)*WIDTH)))

p = subprocess.Popen(["arecord","-D",DEV,"-f","S16_LE","-r",str(RATE),
                      "-c","1","-t","raw","-q"], stdout=subprocess.PIPE)
scale = [" "]*WIDTH
for d, ch in ((MIN_OK,"|"), (IDEAL,"^"), (MAX_OK,"|")):
    scale[pos(d)] = ch
print("  radio VU (standalone) — open the squelch, turn the knob until the bar reaches ^")
print("  %s" % "".join(scale))
peak, hold = LO, 0
print("\033[?25l", end="")
try:
    while True:
        raw = p.stdout.read(N*2)
        if not raw or len(raw) < N*2:
            break
        a = np.frombuffer(raw, dtype="<i2").astype(float)
        a -= a.mean()
        X = np.fft.rfft(a*_win)
        ms = 2*(np.abs(X[_band])**2).sum()/(N**2)/0.375
        db = 20*math.log10(max(math.sqrt(ms), 1e-9)/32768.0)
        hold += 1
        if db > peak or hold > 20:
            peak, hold = db, 0
        n = pos(db)
        bar = ["="]*n + [" "]*(WIDTH-n)
        bar[pos(peak)] = "|"
        for d, ch in ((MIN_OK,":"), (IDEAL,"^"), (MAX_OK,":")):
            if pos(d) >= n:
                bar[pos(d)] = ch
        if   db > MAX_OK:                      tag, c = "TOO HOT", 91
        elif db >= MIN_OK and abs(db-IDEAL)<=3: tag, c = "IDEAL  ", 92
        elif db >= MIN_OK:                     tag, c = "ok     ", 93
        else:                                  tag, c = "TOO LOW", 94
        print("\r  [\033[%dm%s\033[0m] %6.1f dB  \033[%dm%s\033[0m" % (c,"".join(bar),db,c,tag),
              end="", flush=True)
except KeyboardInterrupt:
    pass
finally:
    print("\033[?25h")
    p.kill()

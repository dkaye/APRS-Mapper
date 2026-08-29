# Hardware Receiver Trial

Replacing the RTL-SDR with a conventional receiver whose own squelch gates the audio,
fed into the Pi through a USB sound card.

**Status: this IS the production path**, as of 2026-08-29. `transcriber.py` reads a sound
card; `rtl_fm`, the software squelch, the gain calibration, `calibrate.sh` and the
calibrate service are gone. The SDR version is preserved at git tag
`pre-hardware-receiver`, and **the iGates are still SDR-based** — none of this touched
them.

---

## Why

On a single channel the SDR buys frequency agility that is not being used, and charges
for it in the one place that has been expensive: deciding what is a transmission and what
is noise.

Everything the SDR needed to answer that question — `level_db`, `zcr`, keying counts,
`carrier_gaps` — exists because a software squelch cannot close hard. Three filters were
built on top of it and **none reached production**:

| Filter | Fate |
|---|---|
| static / level | never completed; `level_db` and `zcr` were never persisted, so it could not be re-measured from the corpus |
| `unbroken` (long-clip keying) | **harmful.** Over the final 345-clip run it fired 6 times, all 6 on real speech |
| `quiet` (empty-clip) | 26 fires, 25 correct, 1 real transmission lost. Shippable, but only just |

A hardware squelch answers the question in hardware and makes all three unnecessary.

**Confirmed in production:** a complete two-station QSO captured, segmented and
transcribed automatically on 2026-08-29, including a 31-second conversational over, plus
the repeater's Morse IDs at 1094, 1500 and 2000 Hz.

**What it does not solve: Morse identifiers.** A repeater keys a full carrier to send its
ID, so any squelch opens. That detector is the one piece of SDR work that carries over —
and it is the piece that was already finished, with 120 IDs caught and zero false
positives on real speech.

---

## Hardware

| | |
|---|---|
| Sound card | C-Media `0d8c:0012`, ALSA card 2, device `plughw:2,0` |
| Sample format | S16_LE, 16 kHz, mono |
| Receiver | any with an audio output that follows its volume control |

The SDR it replaces: Nooelec NESDR SMArt v5, RTL2838 (`0bda:2838`).

---

## Configuration

Both of these must hold, and both are stored in `/var/lib/alsa/asound.state`:

```bash
amixer -c 2 sset "Auto Gain Control" off     # AGC destroys the silence the squelch provides
amixer -c 2 sset Mic 15                      # +3 dB, NOT 35 (+23 dB)
echo <pi-password> | sudo -S alsactl store   # sudo on the Transcriber is not NOPASSWD
```

**AGC off** is not optional. AGC raises gain during silence, pulling the noise floor up
into the gap that the whole approach depends on.

**Capture gain 15, not 35.** At maximum the radio's output overwhelms the card: half the
volume knob's travel does nothing and all useful adjustment is crammed into the bottom
20%. Measured response is **~1 dB per step** across 0–35, so the right value is
computable, never something to hunt for.

Not persisting these is a real risk — a power failure on 2026-08-26 rebooted every device
in the fleet, and an unstored mixer setting would have silently reverted.

---

## Calibration

Open-squelch noise is the reference signal. It is **stationary** (~1 dB spread over a
minute) and **available on demand**, where speech varies 20 dB within a syllable and only
arrives when somebody talks.

1. Stop `radio-monitor.py` — only one process can hold the capture device
2. **Open the radio's squelch**
3. `python3 /home/pi/vu.py`
4. Turn the radio's volume until the bar reaches `^` at **−27 dBFS**
5. Close the squelch, restart the monitor

### The trap that cost a day

**Judge the level on the AUDIO BAND (200–4000 Hz). Never on the raw peak.**

The raw peak belongs to a **squelch thump — a switching artifact generated *after* the
volume control**, so no knob can change it. Measured across one recalibration:

| | before | after |
|---|---|---|
| thump (<150 Hz) | −24.5 dBFS | −24.8 dBFS — **unmoved** |
| tone (1–2 kHz) | −21.5 dBFS | −47.2 dBFS — followed the knob |

Acting on the raw peak, the target was set to −35 dBFS. **The radio's leakage floor with
the volume fully OFF is −30.8 dBFS**, so −35 was unreachable except by turning the audio
off entirely — which is what happened. Ten seconds of test speech left no trace in the
recording, and hours went into hunting a cable fault that did not exist.

`vu.py` measures the audio band and carries this reasoning in its own comments.

---

## Measured performance — 146.700 MHz

| | |
|---|---|
| Squelch closed, floor | **−91 dBFS** band-limited (−79 broadband at the old gain) |
| Open-squelch noise, calibrated | −27 dBFS |
| Voice peak | −7 dBFS, 0% clipped |
| Morse ID | 2000 Hz tone at −25 dBFS, keying clearly visible |
| Courtesy beep | 1.3–2.0 s |
| **Speech-to-silence separation** | **60+ dB** |

That last figure is the whole point. The SDR needed statistics to guess whether anyone was
talking; here a threshold dropped anywhere in a 60 dB gap is unambiguous.

**A flat trace within ~1 dB means a dead path. Real audio swings 20 dB or more.** That one
test distinguishes working from broken faster than anything else tried.

---

## Validation

A complete QSO on 2026-08-29, captured and transcribed automatically, both stations, with
courtesy beeps correctly separated:

```
10:50:58  K6DRK    This is K6DRK, looking for a radio check, K6DRK, radio check, anyone?
10:51:12  WA6PXV   Hey Doug, you are Circuit Merit 5, Circuit Merit 5, W-A-6-P-X-V.
10:51:21  K6DRK    I'm running a test right now. I'm working on an app that transcribes
                   the audio to text ... How are you today?
10:51:34  WA6PXV   Well, pretty good ... audio sounds good, sounds like you're up about
                   four or five KC deviation, which is pretty normal for this repeater.
10:52:08  K6DRK    ... I'll see how well this app transcribes your voice in addition to
                   just mine. Well, let's go for now. Jerry, this is K6DRK. Thanks again.
10:52:23  WA6PXV   My pleasure. W-A-6-P-X be clear.
```

Six overs, 72 seconds, two different radios. A 31-second conversational over transcribed
accurately including technical detail. The remaining errors are callsigns — `K60RK` for
K6DRK, a dropped final V — which is exactly what the vocabulary list in `transcriber.py`
already exists to correct.

---

## Tooling on the Pi

| File | Purpose |
|---|---|
| `radio-monitor.py` | logs each over to `radio-monitor.jsonl` + `radio-clips/`, per-minute levels to `radio-levels.jsonl`, live level to `/dev/shm/radio-level`. `@reboot` in pi's crontab |
| `vu.py`, `vumeter.py` | standalone level meter (identical; two names so either works) |

The gate is **band-limited to 200–3500 Hz**, so sub-150 Hz thumps cannot open it. Clips are
saved unfiltered; only the *decision* is band-limited. Done on the spectrum rather than
with an IIR filter because there is no scipy on this Pi, and Parseval gives the
band-limited rms exactly without filter state.

### Two traps in the tooling

**Only one process can hold the capture device.** If `radio-monitor.py` is running,
`arecord` gets nothing — silently. This cost one failed keyup test.

**Never `pkill -f` a pattern that appears in your own ssh command line.** It matches the
remote shell and kills the connection (exit 255). Fetch the PID in one call, kill it in
another.

---

## Open questions

- **Squelch thumps on Morse keying.** Morse clips still carry ~87% of their energy below
  150 Hz, where voice clips sit at −80 dBFS. Probably the squelch reacting to each dit and
  dah. It does not matter operationally — the band-limited gate ignores it — but it is
  not understood.
- **Spectral flatness is not a reliable speech/tone discriminator.** It was calibrated at
  one FFT size and applied at another, and speech harmonics at fine resolution read as
  "peaky" like a tone. It called a clip "not speech-like" that whisper then transcribed
  perfectly. **Dynamic range and dominant-frequency spread are trustworthy; flatness is
  not.**
- **Frequency agility is lost.** Acceptable while the fleet runs one channel (146.700).
  Multiple channels would need multiple receivers.
- **No RSSI.** Signal-level telemetry that the SDR selftest used is unavailable.
- **A receiver with a COS/COR output** would give exact key-up/key-down timing from a GPIO
  pin and replace `carrier_gaps` entirely. Worth looking for in any permanent receiver.

# SPDX-FileCopyrightText: 2021 ladyada for Adafruit Industries
# SPDX-License-Identifier: MIT
# dw-nointernet.py — MARS APRS iGate (direwatch)
#
# Shown on the TFT display when internet access is lost. Counts down 2 minutes,
# checking every 2 seconds. Reboots if still offline; restarts direwatch and
# exits cleanly if connectivity is restored.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

import os
import signal
import subprocess
import sys
import time

REBOOT_SECS = 120
PIDFILE     = '/tmp/aprs-nointernet.pid'

# ── Single-instance guard ──────────────────────────────────────────────────────
_mypid = str(os.getpid())
if os.path.exists(PIDFILE):
    try:
        _old = open(PIDFILE).read().strip()
        if _old and os.path.exists(f'/proc/{_old}'):
            sys.exit(0)
    except Exception:
        pass
with open(PIDFILE, 'w') as _f:
    _f.write(_mypid)


def _cleanup(*_):
    try:
        os.remove(PIDFILE)
    except FileNotFoundError:
        pass


signal.signal(signal.SIGTERM, _cleanup)

# ── Display detection ──────────────────────────────────────────────────────────
_has_display = "hi" in subprocess.run(
    ["pinctrl", "get", "23"], capture_output=True, text=True
).stdout

if _has_display:
    import board
    import digitalio
    from adafruit_rgb_display import st7789
    from PIL import Image, ImageDraw, ImageFont

    subprocess.run(['sudo', 'systemctl', 'stop', 'direwatch.service'], capture_output=True)

    _disp = st7789.ST7789(
        board.SPI(),
        cs=digitalio.DigitalInOut(board.D4),
        dc=digitalio.DigitalInOut(board.D25),
        baudrate=64000000,
        width=240, height=240,
        x_offset=0, y_offset=80,
    )
    _W, _H   = 240, 240
    _rot     = 180
    _fbase   = "/usr/share/fonts/truetype/dejavu/"
    _fbold   = _fbase + "DejaVuSans-Bold.ttf"
    _freg    = _fbase + "DejaVuSans.ttf"
    font_msg   = ImageFont.truetype(_fbold, 24)
    font_timer = ImageFont.truetype(_fbold, 56)
    font_boot  = ImageFont.truetype(_fbold, 28)
    font_sub   = ImageFont.truetype(_freg,  22)


def render(remaining):
    if not _has_display:
        return
    mins, secs = remaining // 60, remaining % 60
    image = Image.new("RGB", (_W, _H))
    draw  = ImageDraw.Draw(image)
    draw.rectangle((0, 0, _W, _H), fill=0)

    lines = [
        ("No internet.",           font_msg),
        ("Rebooting in",           font_msg),
        (f"{mins}:{secs:02d}",    font_timer),
    ]
    gap     = 8
    heights = [draw.textbbox((0, 0), t, font=f)[3] - draw.textbbox((0, 0), t, font=f)[1]
               for t, f in lines]
    widths  = [draw.textbbox((0, 0), t, font=f)[2] - draw.textbbox((0, 0), t, font=f)[0]
               for t, f in lines]
    total_h = sum(heights) + gap * (len(lines) - 1)

    y = (_H - total_h) // 2
    for (text, font), h, w in zip(lines, heights, widths):
        draw.text(((_W - w) // 2, y), text, font=font, fill="#FFFFFF")
        y += h + gap
    _disp.image(image, _rot)


def do_reboot():
    open('/tmp/aprs-rebooting', 'w').close()
    if _has_display:
        image = Image.new("RGB", (_W, _H))
        draw  = ImageDraw.Draw(image)
        draw.rectangle((0, 0, _W, _H), fill=0)
        bh  = font_boot.getbbox("A")[3]
        sh_ = font_sub.getbbox("A")[3]
        draw.text((0, 20),                       "Booting...",      font=font_boot, fill="#FFFFFF")
        draw.text((0, 20 + bh + 14),             "This could take", font=font_sub,  fill="#AAAAAA")
        draw.text((0, 20 + bh + 14 + sh_ + 6),   "a few minutes.", font=font_sub,  fill="#AAAAAA")
        _disp.image(image, _rot)
    time.sleep(1)
    _cleanup()
    subprocess.run(['sudo', 'reboot'])
    sys.exit(0)


# ── Countdown ─────────────────────────────────────────────────────────────────
try:
    start = time.monotonic()
    _ping = None
    while True:
        elapsed   = int(time.monotonic() - start)
        remaining = max(0, REBOOT_SECS - elapsed)
        render(remaining)

        # Launch a background ping every 2 seconds (non-blocking)
        if elapsed % 2 == 0 and _ping is None:
            _ping = subprocess.Popen(
                ['ping', '-c1', '-W2', '8.8.8.8'],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            )

        # Check whether the last ping finished
        if _ping is not None and _ping.poll() is not None:
            if _ping.returncode == 0:
                subprocess.run(['sudo', 'systemctl', 'start', 'direwatch.service'],
                               capture_output=True)
                _cleanup()
                sys.exit(0)
            _ping = None

        if remaining == 0:
            break

        time.sleep(1)

    do_reboot()
finally:
    _cleanup()

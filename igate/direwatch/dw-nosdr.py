# SPDX-FileCopyrightText: 2021 ladyada for Adafruit Industries
# SPDX-License-Identifier: MIT
# dw-nosdr.py — MARS APRS iGate (direwatch)
#
# Shown on the TFT display when no SDR is detected. Counts down 2 minutes then
# reboots. Exits early (without rebooting) if the SDR reconnects. Uses a pidfile
# so only one instance runs at a time — safe to call from a cron-fired watchdog.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

# -*- coding: utf-8 -*-

import argparse
import time
import subprocess
import digitalio
import board
from PIL import Image, ImageDraw, ImageFont
import re
import adafruit_rgb_display.st7789 as st7789
import adafruit_rgb_display.ili9341 as ili9341
#import ILI9486_gpiod as ili9486
import ILI9486 as ili9486
import pyinotify
import gpiod
from gpiod.line import Direction, Value
import threading
import signal
import sys
import os
import aprslib
import math
import numpy

REBOOT_SECS = 120
PIDFILE = '/tmp/aprs-nosdr.pid'

# ── Single-instance guard ──────────────────────────────────────────────────────
_mypid = str(os.getpid())
if os.path.exists(PIDFILE):
    try:
        _old = open(PIDFILE).read().strip()
        if _old and os.path.exists(f'/proc/{_old}'):
            sys.exit(0)   # countdown already running
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

# ── Stop services that compete for the SPI display ────────────────────────────
subprocess.run(['sudo', 'systemctl', 'stop', 'direwatch.service'], capture_output=True)
subprocess.run(['sudo', 'systemctl', 'stop', 'direwolf.service'],  capture_output=True)

# Config for display baudrate (default max is 24mhz):
BAUDRATE = 64000000

# square adafruit screen 1.3" (240x240), two buttons
displaytype = st7789
dc_pin = digitalio.DigitalInOut(board.D25)
cs_pin = digitalio.DigitalInOut(board.D4)
spi = board.SPI()
disp = st7789.ST7789(
    spi,
    cs=cs_pin,
    dc=dc_pin,
    baudrate=BAUDRATE,
    height=240,
    width=240,
    y_offset=80,
    rotation=180
)
width  = 240
height = 240

chipdev = '/dev/gpiochip0'

fontname_bold = "DejaVuSans-Bold.ttf"
fontname_reg  = "DejaVuSans.ttf"
_font_base = "/usr/share/fonts/truetype/dejavu/"
if os.path.exists(_font_base + fontname_bold):
    fontpath_bold = _font_base + fontname_bold
elif os.path.exists("./" + fontname_bold):
    fontpath_bold = "./" + fontname_bold
else:
    print("Couldn't find font " + fontname_bold)
    _cleanup()
    sys.exit(1)
fontpath_reg = (_font_base + fontname_reg) if os.path.exists(_font_base + fontname_reg) else fontpath_bold

font_msg   = ImageFont.truetype(fontpath_bold, 24)
font_timer = ImageFont.truetype(fontpath_bold, 56)
font_boot  = ImageFont.truetype(fontpath_bold, 28)
font_sub   = ImageFont.truetype(fontpath_reg,  22)


def sdr_present():
    r = subprocess.run(['lsusb'], capture_output=True, text=True)
    return bool(re.search(r'0bda:2838|0bda:2832|RTL28', r.stdout, re.IGNORECASE))


def do_reboot():
    open('/tmp/aprs-rebooting', 'w').close()
    img = Image.new("RGB", (width, height))
    d   = ImageDraw.Draw(img)
    d.rectangle((0, 0, width, height), fill=0)
    bh  = font_boot.getbbox("A")[3]
    sh_ = font_sub.getbbox("A")[3]
    d.text((0, 20),                       "Booting...",      font=font_boot, fill="#FFFFFF")
    d.text((0, 20 + bh + 14),             "This could take", font=font_sub,  fill="#AAAAAA")
    d.text((0, 20 + bh + 14 + sh_ + 6),   "a few minutes.", font=font_sub,  fill="#AAAAAA")
    disp.image(img, disp.rotation)
    time.sleep(1)
    _cleanup()
    subprocess.run(['sudo', 'reboot'])
    sys.exit(0)   # reboot returns before shutdown completes; don't re-enter the loop


def render(remaining):
    mins = remaining // 60
    secs = remaining % 60
    image = Image.new("RGB", (width, height))
    draw  = ImageDraw.Draw(image)
    draw.rectangle((0, 0, width, height), fill=0)

    lines = [
        ("No SDR found.",     font_msg),
        ("Rebooting in",      font_msg),
        (f"{mins}:{secs:02d}", font_timer),
    ]

    gap = 8
    heights = [draw.textbbox((0, 0), t, font=f)[3] - draw.textbbox((0, 0), t, font=f)[1]
               for t, f in lines]
    total_h = sum(heights) + gap * (len(lines) - 1)

    y = (height - total_h) // 2
    for (text, font), h in zip(lines, heights):
        bb = draw.textbbox((0, 0), text, font=font)
        tw = bb[2] - bb[0]
        draw.text(((width - tw) // 2, y), text, font=font, fill="#FFFFFF")
        y += h + gap

    disp.image(image, disp.rotation)


# ── Countdown ─────────────────────────────────────────────────────────────────
try:
    start = time.monotonic()
    while True:
        elapsed   = int(time.monotonic() - start)
        remaining = max(0, REBOOT_SECS - elapsed)
        render(remaining)

        if elapsed % 2 == 0 and sdr_present():
            do_reboot()

        if remaining == 0:
            break

        time.sleep(1)

    do_reboot()
finally:
    _cleanup()

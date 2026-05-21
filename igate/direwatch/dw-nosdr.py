# SPDX-FileCopyrightText: 2021 ladyada for Adafruit Industries
# SPDX-License-Identifier: MIT

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
import os
import aprslib
import math
import numpy

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
fontsize = 30
width=240
height=240

# don't write to display concurrently with thread
display_lock = threading.Lock()

# Draw some shapes.
# First define some constants to allow easy resizing of shapes.
padding = -2
top = padding
bottom = height - padding

#image = Image.new("RGBA", (width, height))
image = Image.new("RGB", (width, height))
draw = ImageDraw.Draw(image)

chipdev = '/dev/gpiochip0'

# Move left to right keeping track of the current x position for drawing shapes.
x = 0

# Alternatively load a TTF font.  Make sure the .ttf font file is in the
# same directory as the python script!
# Some other nice fonts to try: http://www.dafont.com/bitmap.php
font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 24)
Hostfont = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 22)

# Load a TTF font.
fontname = "DejaVuSans.ttf"
fontname_bold = "DejaVuSans-Bold.ttf"
if os.path.exists("/usr/share/fonts/truetype/dejavu/" + fontname):
   fontpath = "/usr/share/fonts/truetype/dejavu/" + fontname
elif os.path.exists("./" + fontname):
   fontpath = "./" + fontname
else:
   print("Couldn't find font " +  fontname + " in working dir or /usr/share/fonts/truetype/dejavu/")
   exit(1)
if os.path.exists("/usr/share/fonts/truetype/dejavu/" + fontname_bold):
   fontpath_bold = "/usr/share/fonts/truetype/dejavu/" + fontname_bold
elif os.path.exists("./" + fontname_bold):
   fontpath_bold = "./" + fontname_bold
else:
   print("Couldn't find font " +  fontname_bold + " in working dir or /usr/share/fonts/truetype/dejavu/")
   exit(1)

bump = 10
#font(no suffix) is defined on command line, used in list mode
font = ImageFont.truetype(fontpath, fontsize)
font_small = ImageFont.truetype(fontpath_bold, 18 + bump)
font_big = ImageFont.truetype(fontpath_bold, 24)            # title bar font
font_huge = ImageFont.truetype(fontpath_bold, 34 + bump)
font_epic = ImageFont.truetype(fontpath_bold, 38 + bump)

# Draw a black filled box to clear the image.
draw.rectangle((0, 0, width, height), outline=0, fill=0)

# Shell scripts for system monitoring from here:
# https://unix.stackexchange.com/questions/119126/command-to-display-memory-usage-disk-usage-and-cpu-load
Line1 = "No SDR found."
Line2 = "Waiting..."

# Write two lines of text.
y = top
draw.text((x, y), Line1, font=font, fill="#FFFFFF")
y += font.getbbox(Line1)[3]
y += font.getbbox(Line1)[3]
draw.text((x, y), Line2, font=font, fill="#FFFFFF")
y += font.getbbox(Line2)[3]

# Display image.
disp.image(image, disp.rotation)
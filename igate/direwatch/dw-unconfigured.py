"""
dw-unconfigured.py — K6DRK iGate v5.0

Displayed on the TFT screen when the iGate has not yet been configured.
Loops forever, refreshing the IP address every 5 seconds.
Replaced by the normal direwatch display after configure.sh is run and the
unit reboots.
"""
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

import time
import subprocess
import digitalio
import board
from PIL import Image, ImageDraw, ImageFont
from adafruit_rgb_display import st7789

dc_pin    = digitalio.DigitalInOut(board.D25)
cs_pin    = digitalio.DigitalInOut(board.D4)
reset_pin = None
BAUDRATE  = 64000000

spi  = board.SPI()
disp = st7789.ST7789(
    spi,
    cs=cs_pin,
    dc=dc_pin,
    rst=reset_pin,
    baudrate=BAUDRATE,
    width=240,
    height=240,
    x_offset=0,
    y_offset=80,
)

height   = disp.width
width    = disp.height
rotation = 180
image    = Image.new("RGB", (width, height))
draw     = ImageDraw.Draw(image)

font_lg = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 20)
font_sm = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 20)

backlight = digitalio.DigitalInOut(board.D22)
backlight.switch_to_output()
backlight.value = True


def get_ip():
    try:
        return subprocess.check_output(
            "hostname -I | awk '{print $1}'", shell=True
        ).decode().strip()
    except Exception:
        return "..."


def line_height(font, text):
    _, top, _, bottom = font.getbbox(text)
    return bottom - top


GAP = 6

while True:
    ip = get_ip()

    draw.rectangle((0, 0, width, height), fill=0)

    y = 10
    draw.text((0, y), "! NOT CONFIGURED !",  font=font_lg, fill="#FFFFFF")
    y += line_height(font_lg, "!") + GAP + 6

    draw.text((0, y), "Run configure.sh",    font=font_sm, fill="#FFFFFF")
    y += line_height(font_sm, "R") + GAP

    draw.text((0, y), "via SSH:",            font=font_sm, fill="#FFFFFF")
    y += line_height(font_sm, "v") + GAP

    draw.text((0, y), f"pi@{ip}",            font=font_sm, fill="#FFFFFF")
    y += line_height(font_sm, "p") + GAP * 3

    draw.text((0, y), "direwolf: suppressed", font=font_sm, fill="#FFFFFF")

    disp.image(image, rotation)
    time.sleep(5)

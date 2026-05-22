"""
dw-startup.py — K6DRK iGate v5.0

Boot sequence on the TFT display:
  1. "Booting..."      — shown immediately at power-on
  2. Diagnostic screen — 10-second countdown with system stats

Exits cleanly; direwolf and direwatch start after this service completes.
SDR, IP, and internet monitoring is handled by igate-watchdog.sh via cron.

Exits immediately when:
  - No TFT detected (GPIO23 low)
  - iGate is unconfigured (CONFIGURE_ME in direwolf.conf)
"""
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

import re
import subprocess
import sys
import time

import board
import digitalio
from adafruit_rgb_display import st7789
from PIL import Image, ImageDraw, ImageFont

# ── TFT presence ──────────────────────────────────────────────────────────────
if "hi" not in subprocess.run(
    ["pinctrl", "get", "23"], capture_output=True, text=True
).stdout:
    sys.exit(0)

# ── Display setup ─────────────────────────────────────────────────────────────
disp = st7789.ST7789(
    board.SPI(),
    cs=digitalio.DigitalInOut(board.D4),
    dc=digitalio.DigitalInOut(board.D25),
    rst=None,
    baudrate=64000000,
    width=240, height=240,
    x_offset=0, y_offset=80,
)

W, H     = disp.height, disp.width
rotation = 180
image    = Image.new("RGB", (W, H))
draw     = ImageDraw.Draw(image)

backlight = digitalio.DigitalInOut(board.D22)
backlight.switch_to_output()
backlight.value = True

BOLD = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 28)
DIAG = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 22)


def lh(font):
    return font.getbbox("A")[3]


def sh(cmd):
    try:
        return subprocess.check_output(cmd, shell=True, stderr=subprocess.DEVNULL).decode().strip()
    except subprocess.CalledProcessError:
        return ""


# ── Step 1: Boot splash ───────────────────────────────────────────────────────
draw.rectangle((0, 0, W, H), fill=0)
draw.text((0, 20), "Booting...", font=BOLD, fill="#FFFFFF")
draw.text((0, 20 + lh(BOLD) + 14), "This could take",  font=DIAG, fill="#AAAAAA")
draw.text((0, 20 + lh(BOLD) + 14 + lh(DIAG) + 6), "a few minutes.", font=DIAG, fill="#AAAAAA")
disp.image(image, rotation)

# Exit early if unconfigured — direwatch will show the configure screen
try:
    with open("/home/pi/direwolf.conf") as f:
        if "CONFIGURE_ME" in f.read():
            sys.exit(0)
except OSError:
    pass

# ── Step 2: Diagnostic screen (10-second countdown) ──────────────────────────
DH = lh(DIAG) + 5

for countdown in range(10, -1, -1):
    host = sh("hostname")
    ip   = sh("hostname -I | awk '{print $1}'")
    nbip = sh("netbird status 2>/dev/null | grep 'NetBird IP' | awk '{print $3}' | cut -d/ -f1")
    cpu  = sh("awk '{printf \"CPU: %.2f\", $1}' /proc/loadavg")
    mem  = sh("free -m | awk 'NR==2{printf \"Mem: %s/%s MB\", $3,$2}'")
    disk = sh("df -h / | awk 'NR==2{printf \"Disk: %s/%s %s\", $3,$2,$5}'")
    temp = sh("awk '{printf \"Temp: %.1f C\", $1/1000}' /sys/class/thermal/thermal_zone0/temp")
    raw  = sh("iw dev 2>/dev/null | grep ssid")
    m    = re.search(r"ssid\s+(.+)", raw, re.IGNORECASE)
    ssid = m.group(1) if m else ""

    lines = [
        (f"Host: {host}",             "#00FFFF"),
        (f"IP: {ip}",                 "#FFFFFF"),
    ]
    if nbip:
        lines.append((f"NB: {nbip}", "#FFFFFF"))
    if ssid:
        lines.append((ssid,           "#00FFFF"))
    lines += [
        (cpu,                         "#FFFF00"),
        (mem,                         "#00FF00"),
        (disk,                        "#FF4444"),
        (temp,                        "#FF00FF"),
        ("APRS iGate",                "#FFFF00"),
        (f"by K6DRK v5.0  {countdown}", "#FFFF00"),
    ]

    draw.rectangle((0, 0, W, H), fill=0)
    y = 0
    for text, color in lines:
        draw.text((0, y), text, font=DIAG, fill=color)
        y += DH
    disp.image(image, rotation)
    time.sleep(1)

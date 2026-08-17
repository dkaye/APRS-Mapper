#!/usr/bin/env python3
"""
make-icon.py — MARS APRS Wear OS companion

Draws the watch app's launcher icon and writes every density Android needs.
Run it after changing anything below; nothing here is edited by hand.

    python3 app/android/wear/icon/make-icon.py

Writes ic_launcher.svg beside this file (for inspection) and
ic_launcher_background.png into the five mipmap folders under ../src/main/res.
Requires rsvg-convert (`brew install librsvg`).

The design is a recomposition of app/android/play-store-icon-512.png, whose colours
are sampled below rather than guessed. The square original fills its canvas edge to
edge; an adaptive icon shows only the middle 72 of 108 dp, masked to a circle, so the
scene is pulled in and the sky and mountain extended outward to fill the margin the
mask eats. Everything that has to survive — the arcs, the mast, the summit — lives
inside the 66 dp safe circle.

Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
©2025 Doug Kaye, K6DRK <doug@rds.com>
"""

import math
import shutil
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
RES = HERE.parent / "src" / "main" / "res"

S = 1024.0            # design canvas = 108 dp
SAFE_R = 313.0        # 66 dp — everything that matters lives inside this
MASK_R = 341.5        # 72 dp — the circular crop
CX = CY = S / 2

# One PNG per density bucket. 216 (xhdpi) is the one that actually ships: a Pixel
# Watch is 384x384 at 320 dpi, as are the Galaxy Watch 4-6. The rest are insurance.
DENSITIES = {
    "mdpi": 108,
    "hdpi": 162,
    "xhdpi": 216,
    "xxhdpi": 324,
    "xxxhdpi": 432,
}

# ── palette, sampled from play-store-icon-512.png ────────────────────────────
SKY_TOP = "#071540"
SKY_MID = "#101e4c"
SKY_LOW = "#16285b"
MTN_TOP = "#7c9a5c"
MTN_MID = "#4a6130"
MTN_LOW = "#26380f"
MTN_BACK = "#3c5330"   # the ridge behind, cooler and flatter, for depth
MAST = "#efe9e6"

# The arc centre sits below the canvas centre. The arcs open upward, so pushing their
# origin down is what centres the bundle in the circular crop rather than the square.
AX, AY = CX, 540.0

# radius, stroke, colour — inner is hot, outer cools to gold, as in the original
ARCS = [
    (78, 13.0, "#ff4a00"),
    (128, 12.0, "#ff6800"),
    (180, 11.0, "#fb8709"),
    (233, 10.0, "#efa016"),
    (285, 9.0, "#deb62c"),
]

MAST_LEN = 62.0
PEAK = (CX, AY + MAST_LEN)     # the antenna stands on the main summit


def arc_path(r):
    """A semicircle, open at the bottom, like a radiating wavefront."""
    return f"M {AX - r:.1f} {AY:.1f} A {r} {r} 0 0 1 {AX + r:.1f} {AY:.1f}"


def stars():
    """Deterministic scatter — the same sky every run, so a rebuild produces byte-identical
    PNGs and a diff means somebody actually changed the design."""
    out = []
    seed = 20260817

    def rnd():
        nonlocal seed
        seed = (1103515245 * seed + 12345) % (2 ** 31)
        return seed / (2 ** 31)

    placed = tries = 0
    while placed < 30 and tries < 6000:
        tries += 1
        x = rnd() * S
        y = rnd() * 560                                # sky only
        if math.hypot(x - AX, y - AY) < ARCS[-1][0] + 26:      # off the arc bundle
            continue
        if math.hypot(x - CX, y - CY) > MASK_R + 30:           # inside the crop
            continue
        r = 1.6 + rnd() * 2.4
        # Fainter low in the sky, where the warm glow washes them out.
        o = (0.30 + rnd() * 0.55) * (1.0 - min(1.0, max(0.0, (y - 120) / 700)))
        out.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r:.2f}" '
                   f'fill="#ffffff" opacity="{o:.2f}"/>')
        placed += 1
    return "\n    ".join(out)


def mountain():
    """Mt Tam's two summits, sized so the massif fills the lower third of the circular
    crop the way it fills the lower half of the square original.

    The surface heights below were chosen against the crop geometry, not the canvas.
    At x=250 the circle spans y 294-730, so a ridge at y=760 — which looks perfectly
    good on the square — is entirely outside the visible area, and the mountain reads
    on the wrist as a distant bump with a lot of empty sky above it.
    """
    px, py = PEAK
    return (
        f"M -20 {S + 20} "
        f"L -20 815 "
        f"C 40 800 84 782 120 762 "
        f"C 172 733 200 722 250 706 "
        f"C 306 688 342 676 380 656 "
        f"C 418 636 446 626 470 615 "
        f"C 492 610 {px - 16:.0f} {py + 12:.0f} {px:.0f} {py:.0f} "
        f"C {px + 12:.0f} {py + 10:.0f} {px + 28:.0f} {py + 26:.0f} 562 640 "
        f"C 580 662 590 676 602 682 "
        f"C 618 656 640 632 660 632 "
        f"C 690 632 726 664 760 690 "
        f"C 800 720 840 742 880 762 "
        f"C 950 796 1000 816 {S + 20:.0f} 830 "
        f"L {S + 20:.0f} {S + 20} Z"
    )


def back_ridge():
    """A lower, cooler ridge behind the main one. Without it the flanks of the crop are
    an unbroken wash of one green, which at launcher size looks like a fill rather than
    terrain."""
    return (
        "M -20 1044 L -20 792 "
        "C 60 776 120 748 190 726 "
        "C 250 707 290 700 330 690 "
        "C 372 679 404 676 430 672 "
        "C 452 669 470 668 486 670 "
        "L 540 690 "
        "C 600 706 640 700 690 692 "
        "C 750 683 800 690 860 706 "
        "C 940 728 990 742 1044 750 "
        "L 1044 1044 Z"
    )


SVG = f"""<svg xmlns="http://www.w3.org/2000/svg" width="{S:.0f}" height="{S:.0f}"
     viewBox="0 0 {S:.0f} {S:.0f}">
  <!-- Generated by make-icon.py. Do not edit. -->
  <defs>
    <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0"    stop-color="{SKY_TOP}"/>
      <stop offset="0.52" stop-color="{SKY_MID}"/>
      <stop offset="1"    stop-color="{SKY_LOW}"/>
    </linearGradient>

    <radialGradient id="glow" cx="{AX / S:.4f}" cy="{AY / S:.4f}" r="0.46">
      <stop offset="0"    stop-color="#ff8a1e" stop-opacity="0.30"/>
      <stop offset="0.45" stop-color="#ff7a10" stop-opacity="0.14"/>
      <stop offset="1"    stop-color="#ff6a00" stop-opacity="0"/>
    </radialGradient>

    <!-- The tonal ramp runs across y 599-880, not across the canvas. The visible
         mountain occupies only y 600-854 inside the crop, so a gradient spanning the
         full height would show the crop just its top third and the massif would read
         as one flat olive shape at 64 px. -->
    <linearGradient id="mtn" x1="0" y1="0.585" x2="0" y2="0.86">
      <stop offset="0"    stop-color="{MTN_TOP}"/>
      <stop offset="0.45" stop-color="{MTN_MID}"/>
      <stop offset="1"    stop-color="{MTN_LOW}"/>
    </linearGradient>

    <filter id="soft" x="-60%" y="-60%" width="220%" height="220%">
      <feGaussianBlur stdDeviation="11"/>
    </filter>
    <filter id="tight" x="-60%" y="-60%" width="220%" height="220%">
      <feGaussianBlur stdDeviation="4"/>
    </filter>
  </defs>

  <rect width="{S:.0f}" height="{S:.0f}" fill="url(#sky)"/>
  <rect width="{S:.0f}" height="{S:.0f}" fill="url(#glow)"/>

  <g id="stars">
    {stars()}
  </g>

  <!-- Three passes per arc: a wide blurred wash, a soft body, then a crisp core.
       That is what makes them read as light rather than as drawn lines, which is the
       whole character of the original. -->
  <g fill="none" stroke-linecap="round">
    {chr(10).join(
        f'    <path d="{arc_path(r)}" stroke="{c}" stroke-width="{w * 2.6:.1f}"'
        f' opacity="0.30" filter="url(#soft)"/>'
        for r, w, c in ARCS)}
    {chr(10).join(
        f'    <path d="{arc_path(r)}" stroke="{c}" stroke-width="{w:.1f}"'
        f' opacity="0.95" filter="url(#tight)"/>'
        for r, w, c in ARCS)}
    {chr(10).join(
        f'    <path d="{arc_path(r)}" stroke="{c}" stroke-width="{w * 0.55:.1f}"/>'
        for r, w, c in ARCS)}
  </g>

  <!-- Mast, drawn before the mountain so its foot is buried in the summit rather than
       ending in a visible stub. -->
  <rect x="{CX - 2.6:.1f}" y="{AY:.1f}" width="5.2" height="{MAST_LEN + 16:.1f}"
        fill="{MAST}"/>
  <circle cx="{CX:.1f}" cy="{AY:.1f}" r="9.5" fill="#ffffff"/>
  <circle cx="{CX:.1f}" cy="{AY:.1f}" r="17" fill="#ffd9a8" opacity="0.5"
          filter="url(#tight)"/>

  <path d="{back_ridge()}" fill="{MTN_BACK}"/>
  <path d="{mountain()}" fill="url(#mtn)"/>
</svg>
"""


def main():
    if shutil.which("rsvg-convert") is None:
        sys.exit("rsvg-convert not found — `brew install librsvg`")

    svg = HERE / "ic_launcher.svg"
    svg.write_text(SVG)
    print(f"wrote {svg.relative_to(HERE.parents[3])}")

    for bucket, px in DENSITIES.items():
        out = RES / f"mipmap-{bucket}" / "ic_launcher_background.png"
        out.parent.mkdir(parents=True, exist_ok=True)
        # Rendered from the vector at each size rather than downsampled from one
        # master: the arcs are thin and the stars are two pixels across, and both
        # survive a native render where they turn to mush in a resample.
        subprocess.run(
            ["rsvg-convert", "-w", str(px), "-h", str(px), str(svg), "-o", str(out)],
            check=True,
        )
        print(f"wrote {out.relative_to(HERE.parents[3])} ({px}px)")


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""Regenerate map/device_models.php from the published Apple identifier list.

Run this after Apple ships hardware — realistically September for iPhones, and whenever
an iPad or SE turns up in spring. Until it is run, a new handset shows its raw identifier
("iPhone19,1") instead of a name: honest, readable enough to act on, and obviously the
thing this script fixes.

Deliberately NOT on a cron, unlike the ASN table. That one is data, refreshed monthly, and
a stale row there is a wrong carrier. This one generates CODE into the repository, from a
third-party gist, for hardware that appears about twice a year — automating it would buy
nothing and would put an unreviewed download on the path to a live server.

    python3 map/update-device-models.py           # rewrite, print what changed
    python3 map/update-device-models.py --check    # report only, touch nothing

The report is the point: it names every identifier gained or lost, so a source that
changes format or goes missing shows up as an implausible diff rather than as a table
that quietly lost half its entries.

Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
©2026 Doug Kaye, K6DRK <doug@rds.com>
"""
import datetime
import os
import re
import sys
import urllib.request

SOURCE = "https://gist.githubusercontent.com/adamawolf/3048717/raw/Apple_mobile_device_types.txt"
TARGET = os.path.join(os.path.dirname(os.path.abspath(__file__)), "device_models.php")
# Watches and TVs never report as trackers, so they are not carried.
WANTED = re.compile(r"^(iPhone|iPad|iPod)\d")


def fetch():
    req = urllib.request.Request(SOURCE, headers={"User-Agent": "MARS-APRS/1.0"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read().decode("utf-8", "replace")


def parse(text):
    out = {}
    for line in text.splitlines():
        m = re.match(r"^(\S+)\s*:\s*(.+?)\s*$", line)
        if m and WANTED.match(m.group(1)):
            out[m.group(1)] = m.group(2)
    return out


def existing():
    """What the current file holds, read back rather than remembered."""
    if not os.path.exists(TARGET):
        return {}
    src = open(TARGET, encoding="utf-8").read()
    return {m.group(1): m.group(2)
            for m in re.finditer(r"^\s*'([^']+)'\s*=>\s*'(.*?)',\s*$", src, re.M)}


HEADER = '''<?php
/**
 * APRS Tracker Map — Apple hardware identifiers, in words.
 *
 * iOS reports `utsname.machine`, and there is no API that gives anything friendlier:
 * UIDevice.model returns the bare string "iPhone", and UIDevice.name has been the app's
 * own name rather than the device's since iOS 16 unless you hold an entitlement. So the
 * app sends the identifier, which is the only thing it can send, and the translation
 * happens here.
 *
 * The numbers do not track the marketing names and are not meant to: the iPhone 15 Pro
 * Max is iPhone16,2, while the plain 15 is iPhone15,4. Reading a generation off the
 * identifier gets you the wrong phone.
 *
 * SERVER-SIDE deliberately. A handset released next spring is then named by editing this
 * file, where a table compiled into the app would leave every phone in the field showing
 * an identifier until each one updated.
 *
 * GENERATED — do not edit by hand. Run map/update-device-models.py, which is where the
 * source and the reasoning live. Last generated %s, %d entries, from
 * https://gist.github.com/adamawolf/3048717 — not written from memory, because a
 * confidently wrong device name is worse than the identifier it replaced.
 *
 * Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
 * @author    Doug Kaye
 * @copyright 2026 Doug Kaye. All Rights Reserved.
 */

const APPLE_DEVICE_NAMES = [
%s
];

/**
 * The marketing name for an Apple hardware identifier, or null if it is not one we know.
 *
 * Null rather than a guess. An unknown identifier is shown as it stands, which is honest
 * and still diagnostic; inventing "iPhone 19" for an identifier nobody has mapped would
 * not be.
 */
function apple_device_name(?string $identifier): ?string
{
    $id = trim((string)$identifier);
    return $id !== '' && isset(APPLE_DEVICE_NAMES[$id]) ? APPLE_DEVICE_NAMES[$id] : null;
}
'''


def main():
    check = "--check" in sys.argv
    try:
        fresh = parse(fetch())
    except Exception as e:
        print("could not fetch the identifier list: %s" % e, file=sys.stderr)
        return 1
    if len(fresh) < 100:
        # The source went missing or changed shape. Refuse rather than write a table that
        # would silently stop naming most of the fleet.
        print("only %d identifiers parsed — refusing to write. Check %s"
              % (len(fresh), SOURCE), file=sys.stderr)
        return 1

    have = existing()
    added = sorted(k for k in fresh if k not in have)
    gone = sorted(k for k in have if k not in fresh)
    changed = sorted(k for k in fresh if k in have and fresh[k] != have[k])

    print("source: %d identifiers   current file: %d" % (len(fresh), len(have)))
    for label, keys in (("added", added), ("no longer listed", gone), ("renamed", changed)):
        if keys:
            print("  %s (%d):" % (label, len(keys)))
            for k in keys[:20]:
                print("      %-14s %s" % (k, fresh.get(k, have.get(k))))
            if len(keys) > 20:
                print("      ... and %d more" % (len(keys) - 20))
    if not (added or gone or changed):
        print("  no change")

    if check:
        return 0
    body = "\n".join("    '%s' => '%s'," % (k, fresh[k].replace("'", "\\'"))
                     for k in sorted(fresh))
    with open(TARGET, "w", encoding="utf-8") as fh:
        fh.write(HEADER % (datetime.date.today().isoformat(), len(fresh), body))
    print("wrote %s" % TARGET)
    return 0


if __name__ == "__main__":
    sys.exit(main())

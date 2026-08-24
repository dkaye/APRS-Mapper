#!/usr/bin/env python3
"""Build the local IP → network-operator table used to name a tracker's carrier.

Replaces a per-join call to ip-api.com. That call cost 50-200 ms of blocked round trip
inside the join handler, capped the whole server at 45 lookups a minute across every
device, and sent each volunteer's IP address to a third party over plain HTTP. None of
those are things a lookup that can be answered from a local file should cost.

The source is iptoasn.com's public-domain combined table: every routed IP range with the
autonomous system that announces it. About 8.9 MB gzipped, 43 MB as TSV, 716k rows, of
which 577k are actually routed.

WHAT IS WRITTEN, AND WHY IT IS SHAPED THIS WAY

Three files, in a directory that is swapped in atomically:

    v4.idx    454k records x 16 bytes   start(4)  end(4)  asn(4) name_offset(4)
    v6.idx    123k records x 40 bytes   start(16) end(16) asn(4) name_offset(4)
    names.bin one length-prefixed UTF-8 string per distinct operator
    meta.json when it was built, and from what

The AS NUMBER is carried as well as the description because the description is not fit to
show anybody: the table calls T-Mobile "T-MOBILE-AS21928", Verizon Wireless "CELLCO-PART",
Starlink "SPACEX-STARLINK", and there is a "COMCAST INDIA ENGINEERING CENTER" that has
nothing to do with the Comcast a phone connects through. The number is stable and
unambiguous, so the reader maps well-known ones to names people recognise and keeps the
description only as a fallback. Doing that at read time rather than here means correcting
a name costs an edit, not a 9 MB download.

FIXED-WIDTH records are the whole point: the reader seeks straight to record N instead of
scanning lines, so a lookup is ~19 seeks of 12 bytes rather than a walk through 43 MB.
Addresses are stored as inet_pton gives them — network byte order — because a bytewise
comparison of big-endian addresses IS a numeric comparison, so v4 and v6 need no separate
comparison logic and PHP needs no integer conversion that could overflow.

Ranges with AS 0 / "Not routed" are dropped. They cannot be a carrier and they are a
quarter of the file.

Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
©2026 Doug Kaye, K6DRK <doug@rds.com>
"""
import gzip
import json
import os
import shutil
import socket
import struct
import sys
import time
import urllib.request

SOURCE = "https://iptoasn.com/data/ip2asn-combined.tsv.gz"
ROOT = os.environ.get("MARSAPRS_ASN_DIR", "/var/lib/marsaprs/asn")
CURRENT = os.path.join(ROOT, "current")
MAX_NAME = 120          # AS descriptions are short; the length prefix is one byte
KEEP_BUILDS = 2         # the live one and the one before it, for a quick rollback


def fetch(url, dest):
    req = urllib.request.Request(url, headers={"User-Agent": "MARS-APRS/1.0"})
    with urllib.request.urlopen(req, timeout=120) as r, open(dest, "wb") as fh:
        shutil.copyfileobj(r, fh)
    return os.path.getsize(dest)


def build(tsv_gz, outdir):
    """Read the table, sort it, and write the three files. Returns a count summary."""
    names, blob = {}, bytearray()

    def name_offset(text):
        """Offset of this operator's name in names.bin, adding it if new.

        Deduplicated because 577k ranges share only 84k operators, and because a range
        pointing at a shared string is 4 bytes where an inline copy would be twenty.
        """
        if text in names:
            return names[text]
        raw = text.encode("utf-8", "replace")[:MAX_NAME]
        off = len(blob)
        blob.append(len(raw))
        blob.extend(raw)
        names[text] = off
        return off

    v4, v6 = [], []
    with gzip.open(tsv_gz, "rt", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            parts = line.rstrip("\n").split("\t")
            if len(parts) < 5:
                continue
            start, end, asn, _country, desc = parts[0], parts[1], parts[2], parts[3], parts[4]
            if asn == "0" or desc == "Not routed" or not desc:
                continue
            fam = socket.AF_INET6 if ":" in start else socket.AF_INET
            try:
                lo = socket.inet_pton(fam, start)
                hi = socket.inet_pton(fam, end)
            except OSError:
                continue
            try:
                as_number = int(asn)
            except ValueError:
                continue
            (v6 if fam == socket.AF_INET6 else v4).append(
                (lo, hi, as_number, name_offset(desc)))

    # Sorted by start address so the reader can binary-search. Sorting the raw bytes is
    # sorting the addresses, for the reason the header gives.
    v4.sort(key=lambda r: r[0])
    v6.sort(key=lambda r: r[0])

    os.makedirs(outdir, exist_ok=True)
    for fname, rows, width in (("v4.idx", v4, 4), ("v6.idx", v6, 16)):
        with open(os.path.join(outdir, fname), "wb") as fh:
            for lo, hi, as_number, off in rows:
                fh.write(lo.ljust(width, b"\0"))
                fh.write(hi.ljust(width, b"\0"))
                fh.write(struct.pack(">II", as_number, off))
    with open(os.path.join(outdir, "names.bin"), "wb") as fh:
        fh.write(blob)
    return {"v4": len(v4), "v6": len(v6), "names": len(names), "names_bytes": len(blob)}


def publish(build_dir, counts, source_bytes):
    """Swap the new table in atomically.

    Via a symlink rather than by moving three files into place: a reader that opened
    v4.idx from one build and names.bin from the next would resolve an offset into the
    wrong string table and name the wrong carrier — silently, and only for whoever asked
    during the half-second the files disagreed. Replacing one symlink has no such window.
    """
    with open(os.path.join(build_dir, "meta.json"), "w") as fh:
        json.dump({"built": int(time.time()), "source": SOURCE,
                   "source_bytes": source_bytes, **counts}, fh)
    link = CURRENT + ".new"
    if os.path.islink(link) or os.path.exists(link):
        os.unlink(link)
    os.symlink(build_dir, link)
    os.replace(link, CURRENT)          # atomic; readers never see a half-swapped table

    builds = sorted(d for d in os.listdir(ROOT) if d.startswith("build-"))
    live = os.path.basename(os.path.realpath(CURRENT))
    for old in builds[:-KEEP_BUILDS]:
        if old != live:
            shutil.rmtree(os.path.join(ROOT, old), ignore_errors=True)


def main():
    os.makedirs(ROOT, exist_ok=True)
    stamp = time.strftime("%Y%m%dT%H%M%S")
    build_dir = os.path.join(ROOT, "build-" + stamp)
    tsv_gz = os.path.join(ROOT, "source-%s.tsv.gz" % stamp)
    try:
        size = fetch(SOURCE, tsv_gz)
        print("fetched %s (%.1f MB)" % (SOURCE, size / 1e6))
        counts = build(tsv_gz, build_dir)
        print("built %(v4)d IPv4 + %(v6)d IPv6 ranges, %(names)d operators" % counts)
        publish(build_dir, counts, size)
        total = sum(os.path.getsize(os.path.join(build_dir, f))
                    for f in os.listdir(build_dir))
        print("published %s (%.1f MB)" % (CURRENT, total / 1e6))
    except Exception as e:
        # A failed rebuild leaves the previous table in place and serving, which is the
        # right outcome: an operator name a month out of date is worth far more than none.
        print("asn table build FAILED: %s" % e, file=sys.stderr)
        shutil.rmtree(build_dir, ignore_errors=True)
        return 1
    finally:
        if os.path.exists(tsv_gz):
            os.unlink(tsv_gz)
    return 0


if __name__ == "__main__":
    sys.exit(main())

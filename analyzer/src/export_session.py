"""
export_session.py — MARS APRS Analyzer

CLI twin of the /api/export route: writes a self-contained, gzipped playback
bundle (.marsplay.json.gz) for one event, capturing beacons plus their full
rendering context so the session can be replayed later by player.html without
any live config, DB, or APRS-IS access.

Usage:
    python export_session.py "<event name>" [output_path]

If output_path is omitted, writes "<event>_<YYYYMMDD>.marsplay.json.gz" to the
current directory. Run while the event is current so the surrounding context
(courses, igates, tracker roster) is still available in the web root.
"""
import sys
import gzip
import json
import time
import re

from flask_app import build_session_bundle


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    event_name = sys.argv[1]
    if len(sys.argv) > 2:
        out_path = sys.argv[2]
    else:
        safe = re.sub(r'[^A-Za-z0-9._-]+', '_', event_name).strip('_') or 'session'
        out_path = f"{safe}_{time.strftime('%Y%m%d')}.marsplay.json.gz"

    bundle = build_session_bundle(event_name, exported_by='cli')
    if not bundle['beacons']:
        print(f"Warning: no beacons found for event '{event_name}'.", file=sys.stderr)

    data = json.dumps(bundle, separators=(',', ':')).encode('utf-8')
    with gzip.open(out_path, 'wb', compresslevel=9) as f:
        f.write(data)

    print(f"Wrote {out_path}  "
          f"({bundle['event']['beacon_count']} beacons, "
          f"{len(bundle['trackers'])} trackers, "
          f"{len(data)} bytes uncompressed)")


if __name__ == '__main__':
    main()

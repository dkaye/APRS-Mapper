# MARS APRS — map/

This is the **web map** component (server backend + browser UI, `index.php`) of the
MARS APRS system. It deploys to the Pi at `/var/www/html/` via `sync-to-pi.sh`.

**The system README has moved to the repository root:** [`../README.md`](../README.md).

Other docs:
- **API contract** (endpoints consumed by the mobile app): [`../docs/API.md`](../docs/API.md)
- **User guide** (web-served, generated here): `USERGUIDE.MD` → `userguide.html`

`make-docs-html.py` renders `../README.md` → `readme.html` and `USERGUIDE.MD` →
`userguide.html` into this directory for serving.

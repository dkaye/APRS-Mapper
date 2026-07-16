# MARS APRS — Client/Server API Contract

Authoritative reference for the HTTP API exposed by the web map (`map/index.php`)
and consumed by the Flutter mobile app (`aprs-map`, a separate repo) and the
browser UI. When you change an endpoint's response shape, update this file **and**
follow the versioning rules below so mobile clients don't silently break.

- **Server implementation:** `map/index.php` (deployed to `/var/www/html/` on the Pi).
- **Base URL:** `https://marsaprs.org` (`MapConfig.serverBaseUrl` in the app).
- **All endpoints are query-string dispatched** on `index.php` (e.g. `index.php?json`).

---

## API version handshake

The wire-format contract is versioned **independently of the release/marketing
version** (`WEB_VERSION`, `pubspec.yaml`). It lets a client detect a server it can
no longer talk to — while a newer server serving older clients stays silent.

**Server** (`map/index.php`) advertises, in the `?json` and `?config` responses:

```json
"api": { "version": 1, "min_client": 1 }
```

- `version` — current contract version. **Bump only on a breaking change** to a
  response shape (removing/renaming a field a client reads, or changing its meaning).
  Additive changes (new fields) do **not** bump it — clients ignore unknown fields.
- `min_client` — oldest client contract the server still supports. **Raise only when
  deliberately dropping backward compatibility.**

Defined at the top of `index.php`: `API_VERSION`, `API_MIN_CLIENT`.

**Client** (`aprs-map`) knows one built-in constant: `MapConfig.clientApiVersion`.
On each `?json` poll it compares:

| Situation | Condition | Behavior |
|-----------|-----------|----------|
| Server newer, still supports this app | `clientApiVersion >= min_client` | **silent (normal case)** |
| Server dropped this app's contract | `clientApiVersion < min_client` | dismissible "Please update" banner |
| App newer than server (pre-release) | `clientApiVersion > version` | app self-limits new features |
| Old server without the `api` field | `api` absent | assumed compatible; silent |

Implementation: `APRSData.fromJson` parses `api.min_client`; `map_screen.dart`
sets `_updateRequired` and shows `widgets/update_banner.dart`.

**Rule of thumb:** most changes are additive and need no bump. Only a removed/renamed/
semantically-changed field bumps `API_VERSION`; only dropping old-client support
raises `API_MIN_CLIENT`.

---

## Endpoints

### `GET index.php?json`  — live map state (polled)
The app polls this (`OnlinePoller`, ETag/304 aware). Returns:

| Field | Type | Notes |
|-------|------|-------|
| `api` | object | `{version, min_client}` (see above) |
| `default_event` | string | current event name |
| `password_required` | bool | event is password-gated |
| `blink_duration` | int | seconds a selected marker blinks |
| `breadcrumb_count` | int | 0 = off, 1–100; caps the **combined** trail |
| `mobile_beacons` | object | Smart Track beacon intervals/distances |
| `trackers` | array | see **Tracker object** below |
| `igate_beacons` | object | iGate last-heard status |

**Tracker object:** `id`, `callsign`, `name`, `lat`, `lon`, `color`
(`green`/`blue`/`red` = fresh/recent/stale), `time` (human "ago"), `lastUpdate`
(unix), `mobile` (bool), `sharing_mode`, `ham_callsign` (nullable — a hybrid
tracker's paired radio callsign).

### `GET index.php?config` — static event config (fetched once at startup)
`ConfigService` → `RemoteConfig`. Returns `api`, `event`, `legend`,
`tracker_style`, `map`, `trackers`, `backgrounds`, `courses`, `aidstations`,
`igates`, plus mobile beacon config.

### `GET index.php?history` — breadcrumb trails
Returns beacons **bucketed per callsign**: `{ "<callsign>": [ {lat, lon, ts, path}, … ] }`,
newest-first, capped per callsign server-side. A hybrid tracker's cellular and
radio trails are keyed separately (own callsign vs `ham_callsign`); the client
merges + caps them to `breadcrumb_count` combined. Reads the analyzer SQLite DB
(`aprs.db`), falling back to `tracker_history.yaml`.

### `POST index.php?mobile=<action>` — mobile location sharing
Used by `mobile_session.dart` / `background_location.dart`. JSON body; most
actions require the session token from `auth`/`join`.

| Action | Purpose |
|--------|---------|
| `auth` | validate event password → session token |
| `join` | register/resume a mobile tracker (name, optional ham callsign+SSID) |
| `update` | push a GPS fix (throttled server-side; ≥30 m or ≥300 s) |
| `poll` | fetch inbound messages + session state |
| `leave` | stop sharing |
| `message` | send a message from this tracker |
| `msghistory` | fetch this tracker's message thread |

### `POST index.php?messaging=<action>` — web messaging (browser client)
`send`, `subscribe`, `rename` — used by the browser UI, not the app.

### `GET index.php?clientstatus` — connected-client list (admin/Clients modal)

---

## Changing the API safely

1. **Additive change** (new field): just add it; document it here. No version bump.
2. **Breaking change** (remove/rename/re-mean a field clients read): bump `API_VERSION`,
   and if you're dropping support for old clients, raise `API_MIN_CLIENT`. Rebuild the
   app against the new contract and bump `MapConfig.clientApiVersion` to match.
3. Keep the server backward-compatible whenever feasible so `API_MIN_CLIENT` rarely
   moves — that keeps the update banner quiet for field users on older app builds.

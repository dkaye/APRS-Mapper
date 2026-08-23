# MARS APRS — Admin Guide

How to use the administrative web interfaces that run on `marsaprs.org`. This is the
operator's manual; [README.md](https://marsaprs.org/readme.html) is the system
documentation that explains how the machinery underneath works, and the
[User Guide](https://marsaprs.org/userguide.html) is what you hand to event participants.

Everything described here is served by the APRS Server Pi and reached over the public web,
not over the NetBird VPN. You do not need to be on the VPN to administer the system — the
VPN is how the *server* reaches the field devices, not how you reach the server.

**Where this document lives.** On the server it is at
[`/admin.html`](https://marsaprs.org/admin.html), and at
[`/adminguide.php`](https://marsaprs.org/adminguide.php) when you want a back link — the
**Guide** button in the Map Admin header uses the latter. The source is `ADMIN.MD` at the
repo root; running `map/make-docs-html.py` regenerates both.

---

## Contents

1. [Signing in](#signing-in)
2. [Permissions](#permissions)
3. [User Management — `/auth/users.php`](#user-management-authusersphp)
4. [Map Admin — `/admin/`](#map-admin-admin)
5. [Tickets — `/tickets/admin.php`](#tickets-ticketsadminphp)
6. [NetBird Status — `/netbird/`](#netbird-status-netbird)
7. [NetBird Admin — `/netbird/admin.php`](#netbird-admin-netbirdadminphp)
8. [WiFi Manager — `/wifi/`](#wifi-manager-wifi)
9. [Transcriber Channels — `/transcriber/`](#transcriber-channels-transcriber)
10. [SDR Self-Test — `/igate/selftest/`](#sdr-self-test-igateselftest)
11. [Analyzer — `/analyzer/`](#analyzer-analyzer)
12. [App downloads](#app-downloads)

---

## Signing in

All pages share one sign-in. You do not sign in to each page
separately. A session lasts **24 hours**.

## Permissions

There are thirteen permissions. They are flat — there are no roles or groups — so an
account is exactly the set of boxes ticked on it.

| Permission | Grants |
|---|---|
| `users.manage` | Manage users and permissions |
| `admin.view` | Map Admin, read only |
| `admin.edit` | Map Admin, full editing |
| `admin.edit_trackers` | Map Admin, edit **only** the Trackers section |
| `admin.set_default` | "Save as Default Event" — make an event the live one |
| `admin.delete_event` | Delete events |
| `analyzer.view` | View the Analyzer |
| `analyzer.admin` | Analyzer admin: erase data, control the daemon |
| `netbird.view` | View NetBird device status |
| `netbird.admin` | Manage NetBird devices; edit Transcriber channels |
| `wifi.admin` | WiFi management |
| `tickets.manage` | Manage tickets |
| `messages.manage` | Delete all messages, disconnect operators |

## User Management — `/auth/users.php`

Requires `users.manage`. This is the only page that can hand out permissions.

The page lists every account with its username, name, email, active flag, creation date,
last login, and current permissions. Four actions are available:

**Create** — username (2–32 characters: letters, digits, `_`, `-`, `.`), optional name and
email, a password typed twice, and the permission tick-boxes. The account is active
immediately.

**Edit** — change name, email, the active flag, and permissions. Saving replaces the whole
permission set with whatever is ticked, so a box you clear is a permission removed.

**Reset password** — set a new password directly, without the email round trip. This also
**signs that user out everywhere** except your own current session, which is what you want
when you are resetting because an account may be compromised. Use this for anyone whose
account has no email address.

**Delete** — removes the account. Permissions and sessions go with it, by foreign key
cascade. There is no undo and no archive.

### Guards on your own account

The page stops you from locking yourself out, in three specific ways:

- The `users.manage` tick-box is **disabled** when you are editing yourself.
- If you somehow submit an edit of yourself without `users.manage`, the server puts it back.
- The **active** flag is forced to on when you are editing yourself, and **Delete** refuses
  on your own account.

These guards only protect *you* from *yourself*. Nothing stops two administrators from
removing each other's `users.manage`, and nothing stops you deleting the only other
administrator. If every account with `users.manage` is gone, no web page can fix it:
recovery means shell access to the server and
`sudo -u www-data php /var/www/html/auth/init_db.php`, which creates a new administrator
when run without arguments.

---

## Map Admin — `/admin/`

Requires `admin.view`. This is the largest page in the system and the one you will spend the
most time in. It edits an event — the whole description of what the map shows.

Without `admin.edit` every field renders read-only and the editing buttons are absent. The
server also refuses mutating requests from a view-only account, so the read-only mode is
real and not merely a hidden button.

### Header

The header names the current event file and event name, and carries the action buttons:

| Button | Needs | Does |
|---|---|---|
| **New** | `admin.edit` | Clears the form to an empty event. Warns first if you have unsaved changes |
| **Update** | `admin.edit` | Validates and applies your edits to the event that is live now, then returns to the map |
| **Load…** | `admin.edit` | Opens the list of saved events and loads one into the form |
| **Save** | `admin.edit` | Writes the form to a named event — **without** making it the live event |
| **Save as Default Event** | `admin.set_default` | Writes it **and** makes it the live event that `marsaprs.org` shows |
| **Sign out** / **Exit** | — | End the session / return to the map |
| **NetBird**, **Analyzer**, **Tickets**, **Users** | the matching permission | Jump to that page |

The distinction that matters: **Save** stores an event, **Save as Default Event** switches
the public map over to it. Saving a half-built event for next month cannot disturb today's
event. Only the second button is gated by `admin.set_default`, which is precisely the
permission you withhold from someone who may build events but must not go live with one.

**Update** is the event-day button. It applies what you changed to the running event and
takes you back to the map. If the event has been changed on the server since you loaded it —
someone else editing at the same time — you get a warning before your version overwrites
theirs.

### Sections

**Event** — the event name, the **Event Password** that participants type into the app or
web map, the **Messaging Password** that operators use to subscribe to the message net, and
two live-adjusting sliders:

- **Blink Duration** (0–10 s): how long a tracker's marker blinks when it moves.
- **Breadcrumb Count** (0–100, where 0 is off): how many past positions trail behind a
  tracker.

Those two sliders save as you drag them, without a separate Save. The **💬 Messages** button
beside the messaging password opens the current message thread, with an Export .txt button
and — for `messages.manage` only — **Delete All Messages**, which asks for a confirmation
click and then clears the whole thread.

**Backup** and **Restore** sit beside the event name. Backup downloads the entire event as a
`.zip` named for the event and today's date; Restore takes one back. This is the only
complete export of an event including its course files, and it is worth doing before any
event you would hate to rebuild.

**Legend** — raw HTML shown in the lower-left of the map in kiosk mode. Whatever you put
here renders as markup, so it is a place to be careful.

**Trackers** — everyone being tracked, in one list. This section is editable by
`admin.edit_trackers` as well as `admin.edit`, and has its own **Update Tracker Data**
button which saves the tracker list alone. That button works even when the event is locked,
which is the point of it: the roster changes on event morning after the rest of the event
has been frozen.

There are two kinds of row, and they look similar because they are the same thing seen two
ways — someone on the course, and how their position reaches you.

**Radio trackers** are people carrying an APRS radio. You type these in ahead of the event:

| Field | What to put in it |
|---|---|
| **ID** | The short label drawn on the map beside their marker. Keep it short — two or three characters is ideal, because it has to be readable at a glance on a screen across the room |
| **Callsign** | Their APRS callsign with SSID, e.g. `K6DRK-9`. Uppercased for you as you type |
| **Name** | The person or role, e.g. *Sweep 2* or *Jane*. This is what appears in the sidebar |
| **Δ** | Not editable — the shortest gap between beacons over the last ten received. It tells you how often this radio is actually reporting, which is usually more useful than when it last did |
| **Heard** | Not editable — how long ago this radio was last heard. Green through red as it ages |
| **Info** | Opens what is known about the radio: its last beacon, path, and any device details |
| **Remove** | Deletes the row |

**Mobile trackers** are phones running the app. You do not create these — they appear on
their own when someone joins with the event password, which is why the list has a refresh
(↻) button rather than an add button. Their columns:

| Column | Meaning |
|---|---|
| **ID** | The map label, as above. Editable — a phone joins with something generic, and renaming it to *SAG 3* is usually the first thing you want to do |
| **Callsign** | Assigned automatically when they join (`MARSQQ-NN` form), not editable. This is the callsign their positions are injected to APRS-IS under |
| **Name** | Who it is. Editable, and worth setting for the same reason as the ID |
| **Δ** | Interval between beacons, as above. Click it to reset the beacon history for that phone |
| **Client** | The device, and an icon for the Smart Track mode it is currently in — stationary, walking, cycling, driving |
| **Joined** | How long they have been in this session |
| **Heard** | How long since their last beacon |
| **Info** | The phone's reported device details |
| **Hide** / **Unhide** | Takes them off the map without disconnecting them. For a phone that is on but parked, or a duplicate |
| **Remove** | Drops the session. They come back if the app is still running and sharing |

When someone carries **both** a radio and a phone, the two rows are linked and shown
together — the radio row above, the phone row beneath it — so you can see one person's two
position sources side by side rather than hunting for them separately in the list.

The tick-boxes down the left are for bulk work: select several phones and use **Remove (n)**
in the section header to drop them all at once. This is the end-of-event tidy-up.

Above the list are the mobile settings: whether phones may join at all, the **PIN code** they
must enter to share location, and the **Root callsign** their assigned callsigns are built
from.

**Default Section Visibility** — which layers (trackers, courses, aid stations, iGates,
backgrounds) are switched on when someone opens the map fresh.

**Map Default View** — the latitude, longitude, and zoom the map opens at.

**Map Backgrounds** — the tile layers offered. The ⊞ button browses available tile
providers.

**Aid/Rest Stops**, **iGates**, **Courses** — the geographic content. Each has export (↓)
and import (↑) buttons in its section header. Aid stations and iGates import from YAML, GPX,
KML, or GeoJSON; courses from YAML, CSV, or a location file; and the Event section as a
whole exports and imports from YAML or CSV.

### Event locking

An event can be **locked** so that it cannot be overwritten by accident once it is set up.
A locked event shows a red 🔒 in the event list.

Locking is the one thing on this page that does **not** use your account permissions. It is
protected by a separate shared password in `admin/password.txt` on the server, and saving
over a locked event requires typing it. To change that password, edit the file directly on
the Pi — there is no web interface for it.

The reason for the separate password is that locking is a guard against mistakes by people
who legitimately hold `admin.edit`, and a permission they already have cannot guard against
them. Note the deliberate exception above: **Update Tracker Data** still works on a locked
event.

### Deleting events

Requires `admin.delete_event`, from the event list in the Load dialog. The event directory
and its course files go with it.

### Activity log

Admin actions are appended to `/var/log/aprs-admin/aprs-admin.log` on the server with a
timestamp and client IP. There is no web view of it; read it over SSH.

---

## Tickets — `/tickets/admin.php`

Requires `tickets.manage`.

Participants submit support requests at **`/tickets/`**, which is public and needs no
account — that is the whole point, since someone whose app is not working cannot be asked to
create an account first. Each ticket gets an ID of the form `TKT-0001` and a status URL the
submitter can check later.

The admin page lists all tickets, sortable, with a filter for active ones. Opening a ticket
shows the submitter's details, their summary and description, and copy buttons beside each
field for pasting into an email. You can set:

- **Status** — one of *Open*, *Testing*, *Resolved*, *Won't Fix*, *Duplicate*.
- **Resolved in version** — appears only when the status is *Resolved*, and records which
  app or site build fixed it.
- **Admin response** — free text that the submitter sees when they check their ticket's
  status URL. Write it as something a participant will read.

New tickets and new comments email the `manager_email` address from
`tickets/config.json`, which is the same address that account registrations notify.

---

## NetBird Status — `/netbird/`

Requires `netbird.view`. The live health board for the whole fleet.

Five counters across the top — **Total**, **Online**, **Pending**, **Offline**, **Disabled**
— over a table of every device. A **Daemon** badge in the header shows whether the APRS
tracker daemon is running on the server. Devices are grouped under the section headings set
in NetBird Admin, so related gates sit together.

Each row is one device:

| Column | What it tells you |
|---|---|
| **Name** | The site, as named in NetBird Admin — *Muir Woods*, *Bolinas Ridge* |
| **Host** | Its APRS callsign, e.g. `K6DRK-10`. A dash means the device has none, which is normal for a Display Pi or the server |
| **Status** | **Online** (reachable now), **Offline** (not), **Disabled** (switched off in Admin, so it is not expected), or **Pending** (just changed state — give it a moment before believing it) |
| **IP Address** | Its NetBird address, with a button to copy it for an SSH session |
| **Last** | How long ago the device answered. This is the number that tells you a gate has *just* gone quiet versus has been down all morning |
| **iGate** | Time since this gate last forwarded an APRS packet — green under 5 minutes, blue under 15, red beyond that, and *stale* after an hour. A gate can be **Online** here and still red: the Pi is up and the radio side is not, which is exactly the failure worth catching early |

Further columns appear on the right when devices report extra detail — most usefully **CPU**
temperature and **Throttled**. Throttled is the one to read: *Low voltage* means the power
supply is not keeping up, which is the most common cause of a Pi that reboots on its own or
goes deaf without explaining itself.

**Reading the board quickly.** Offline and red-iGate rows sort to your attention first.
Online with a stale iGate means the network is fine and the radio is not. Everything offline
in one group usually means the site's power or internet went, not six devices failing at
once.

Holders of `netbird.admin` also get two sliders:

- **Poll** — how often the server asks NetBird for device status.
- **Refresh** — how often this page redraws from what the server has.

These are different questions and it is worth keeping them apart: turning Refresh up makes
the page feel livelier without generating any more load on NetBird, while turning Poll up is
what actually gets you fresher data.

The page **stops polling when left idle** and shows a "Polling Paused" panel with a Resume
button. That is intentional — a status board left open on a spare monitor for a week should
not keep hammering the API.

---

## NetBird Admin — `/netbird/admin.php`

Requires `netbird.admin`. Where the device list itself is edited.

**+ Add Device** and **Edit** open a form with four fields:

- **Name** — what shows on the status board, e.g. *Muir Woods*.
- **Host** — the device's APRS hostname, e.g. `K6DRK-10`. Leave blank if it has none. This
  is matched case-insensitively, and the save path refuses a second entry for the same host.
- **IP** — the NetBird address, `100.101.x.y`.
- **Group** — a section heading. Devices with the same group are listed together.

Each row has a toggle for **enabled**, and four buttons:

| Button | Does |
|---|---|
| **ssh** | Opens a browser SSH terminal to the device |
| **Web** | Opens the device's own web interface |
| **Edit** | Reopens the form |
| **Del** | Removes the entry |

**ssh** and **Web** are disabled unless the device is both online and enabled.

### The browser SSH terminal

The **ssh** button opens a full xterm.js terminal in a popup, connected through the server
over the NetBird VPN to the device. It is a real interactive shell: you can run
`sudo systemctl restart …`, tail a log, or reboot a gate without leaving the browser.

The header also links to the [SDR Self-Test](#sdr-self-test-igateselftest) page
(**iGate Test**), the [WiFi Manager](#wifi-manager-wifi), and the User Guide.

---

## WiFi Manager — `/wifi/`

Requires `wifi.admin` to edit; `netbird.view` gets a read-only view, labeled as such in the
page heading.

This manages the single fleet-wide list of WiFi networks that **every** field device tries —
iGates, Display Pis, Transcribers, and the server. Add the venue's network here the week
before an event and the whole fleet picks it up on its nightly check, rather than being
configured one machine at a time in a parking lot.

Each row is one network:

| Column | Meaning |
|---|---|
| **Name** | A label for you. It has no effect on anything — call it *Community Center* so you know what it is next year |
| **SSID** | The network name exactly as it is broadcast. Case matters |
| **Password** | The plain password. Enter it here and the PSK is generated for you |
| **PSK Hash** | The hashed form, which is what actually goes out to the fleet |

**The order of the list is the priority order, and the top is the most preferred.** Drag a
row up to prefer it. A device connects to the highest network on the list that it can
actually see: if it can see numbers 2 and 5, it takes 2 and never looks at 5. If it can see
none of them it keeps trying, so a network that is out of range costs nothing but a line.

That is why the running order matters more than it looks. Put the event venue's network
above your home network before an event, and the gates that travel will prefer the venue
when they arrive and fall back to whatever else they know when they leave — without anyone
touching them. Leave it below and a gate sitting in your garage the night before may never
move off the wrong network.

Because a device only re-reads this list on its nightly check, **add the venue network the
day before, not the morning of.** A gate that has already left for the site will not see an
entry added after it lost internet.

\---

## Transcriber Channels — `/transcriber/`

Requires `netbird.admin` to edit; `netbird.view` gets a read-only view.

Manages the fleet of Transcribers: which Pi listens on which frequency, with which SDR
dongle, and whether it is on. Devices fetch their own slice of this configuration on a
nightly schedule.

### Devices

A table of receiver hosts, each with a **config token** that authenticates that Pi when it
fetches its settings. Tokens can be **rotated**, which shows the new value **once** — copy it
then, because it is not displayed again.

### Channels

One row per frequency being monitored:

| Column | Meaning |
|---|---|
| **Receiver** | Which Pi does the listening |
| **Frequency** | In MHz, as you would read it off a radio |
| **Heard as** | The name written on every log entry from this channel |
| **Dongle serial** | The serial programmed into the SDR stick — not a USB slot number |
| **Squelch** | How strong a signal must be before the receiver records it |
| **Calibration** | Tuner gain and squelch measured at the site; there is a button to ask a channel to measure itself now |
| **Accuracy** | **Fast** keeps up with a busy net in real time; the slower setting is more accurate |
| **On** | Off stops it logging immediately without losing the setup |
| **Audio** | Send the recording along with the transcription |
| **Log token** | Authenticates entries this channel posts |

**Update devices** in the header tells the Pis to re-fetch now instead of waiting for their
next scheduled check.

### Tracker ID names

A tracker's **ID** is short because it has to fit on a map marker and stay readable across
a room — `CAR`, `H1`, `INS`. That is exactly what makes it wrong everywhere else: *CAR
Stanton* in the Messages panel tells a reader nothing, and a speech engine reads `H1` as two
characters rather than as a station.

This list says what each ID is called when it is written out or spoken. One per line:

```
H1 = Hiker One
INS = Insult
CAR = Cardiac
```

Same shape as a correction below, so there is one syntax rather than two. Lines starting
with `#` are ignored, so you can group the list under headings.

With those set, a message from **H1 Germain** is shown in the Messages panel as *Hiker One
Germain* and announced as *"From Hiker One, Germain."* The comma is deliberate — run
together, the station and the person blur into one unfamiliar name.

An ID with no line here is left exactly as it is, so the list only needs the ones worth
expanding. **Map markers are deliberately not changed**; they keep the short ID, which is
the reason the field is short.

**It also applies inside transcribed radio traffic.** A log entry heard as *"H1 to net
control"* is shown as *"Hiker One to net control"*. Only whole words are replaced, so an ID
of `CAR` does not rewrite the middle of *CARDIAC*, *SCARED* or *CARRY*, and only radio
traffic is touched — text an operator typed is never altered.

> **One caveat, for IDs that are also ordinary words.** Matching ignores case, because
> transcription does not reliably produce a callsign in the case it was written in and
> requiring an exact match would make this miss most of the time. The cost is that an ID
> like `CAR` will also fire on the ordinary word: *"the car is parked"* becomes *"the
> Cardiac is parked"*. IDs that are unambiguous tokens — `H1`, `INS`, `M141` — have no such
> problem. If a collision becomes annoying, the cure is to drop that one line; the label
> still expands in the Messages panel either way.

The list is shared by every event, because an aid station keeps its name from one year to
the next. Unlike the vocabulary below it is not sent to the receivers — the server applies
it when it hands out a message, so a change is in force for the next message rather than at
the next device check, and it applies to entries already recorded as well as new ones.

### Beeps and Morse identifiers

A repeater sends a courtesy tone after every over and a Morse identifier every few
minutes. Both used to reach the log — the tone as an entry reading "Beep", the Morse as
whatever the transcription made of ten seconds of keyed carrier.

Each channel now measures the audio before transcribing it, and can tell both apart from
speech on one property: a beep and a CW identifier sit on **one frequency** for their whole
length, and speech never does — its pitch moves constantly. There is nothing to tune per
site and nothing to type; it is a property of the sound.

Every channel starts in **observe** mode, which changes nothing about what is logged. It
records what it *would* have dropped, in the channel's journal and in the recording
manifest if that channel is keeping audio. Read an event back, satisfy yourself that the
only things it flagged were tones, and then switch it to **drop**.

| Mode | Behaviour |
|---|---|
| `observe` | Measures and reports; drops nothing. **The default** |
| `drop` | A clip judged a tone is not transcribed, not logged, **and its audio is not sent** |
| `off` | Does not measure at all |

**It removes the audio as well as the line.** Normally a channel sending audio posts the
recording even when the transcription is rejected, so that a garbled human transmission is
still audible to anyone listening. A courtesy tone is the case where that is wrong — a
listener scrubbing an event wants the overs, not the repeater clearing its throat after
each one — so a clip judged a tone becomes neither a row of text nor a second of audio.

**Only when the tone is the whole transmission.** The measurement is of the whole clip, so
a beep arriving in its own transmission is dropped, while a beep in front of somebody
talking leaves most of the clip broadband and the whole transmission is kept — audio and
text together. Nothing is ever trimmed out of the middle of a recording; this keeps or
discards transmissions rather than rewriting them.

Dropping is also the cheaper setting: a clip judged a tone never reaches transcription,
which on a roll call with a tone after every over is most of the clips.

Anything it cannot judge — a clip too short, too quiet, or unreadable — is *no opinion*,
and is transcribed exactly as before. The detector can never be the reason a transmission
is missing from the log.

### Vocabulary

Transcription gets callsigns and local place names wrong more than anything else — `K6DRK`
comes back as *K6 dark* — so the page gives the receivers a list of words to expect. There
are three sources, and they add together:

1. **Event vocabulary** — the event's radio assignment sheet in Google Docs. Paste the
   ordinary `/edit` link; the document must be shared as *Anyone with the link can view*.
   Callsigns and tactical calls are pulled out by their shape, plus anything under a heading
   containing the word **Vocabulary**. Names, shift times, and phone numbers are not read
   and are never stored. The sheet is re-read every fifteen minutes; **Read sheet now**
   forces it.

2. **Place names and corrections** — a box on the page for terms that cannot be found on the
   sheet by shape, because they are ordinary words in ordinary order: *Windy Gap*,
   *Cardiac*, *Bootjack*, *Pantoll*, *Stinson Beach*. The proper home for these is the
   shared sheet, under a *Vocabulary* heading, one term per line. The box on the page is for
   mid-event, when *Cardiac* is coming out as *Cardiff* in the log and the document is not
   yours to edit right then. A line of the form `Cardiff = Cardiac` is a **correction**:
   what came out on the left, what it should have said on the right. Use one only for a
   mishearing somebody has actually heard, because a correction is obeyed exactly.

3. **Standing vocabulary** — one list shared by every event, behind **Edit standing list…**.
   The procedural words, the amateur-radio terms, and the regional place names that do not
   change from event to event belong here so nobody retypes them into each new sheet. An
   event's own sheet and the box above win on the same term, so a standing entry never
   overrides what today's sheet says.

---

## SDR Self-Test — `/igate/selftest/`

**No sign-in required.**

Every iGate and Transcriber runs an SDR self-noise test nightly and posts the result here.
The dashboard ranks the fleet worst-first by the single number that predicts a deaf gate:
the worst internal spur in the guard band around the monitored frequency, in dB over the
noise floor. **Low is good.**

For calibration, from a Pi Zero 2 W: roughly **+18 dB** with the dongle inside the case,
which is a deaf gate, against **0–3 dB** with the dongle moved out of the case, which is
healthy. Rows are graded GOOD / MARGINAL / BAD and sorted so problems are at the top.

Two further columns answer a different question, and it is the one that actually bites. The
grade above measures the receiver's *internal* noise. It says nothing about whether an
antenna is attached — a receiver with nothing on its antenna port scores GOOD, which is
exactly what happened while a Transcriber channel sat deaf.

**Rise** is how far the noise floor climbs from the bottom of the tuner's gain range to the
top. A receiver that hears the band climbs with the gain, around **11–15 dB** at a quiet
2 m site. One that hears only its own converter stays flat, within a dB or two. A flat
reading means either nothing is reaching the tuner or the site is unusually quiet, and the
page does not guess which — it shows you the number and leaves the judgement to you.

**Calibration** applies to Transcriber channels:

| Shown | What it means |
|---|---|
| <span>measured</span> | The gain was found from this site's own noise floor. This is the one you want. |
| <span>unmeasured</span> | The measurement ran but never found the point where the receiver starts hearing the band, so the channel is running the top of its sweep. Usable, but nothing confirmed it. |
| <span>NEVER</span> | Never calibrated. The channel is running a gain compiled into the software, measured at somebody else's site. |

A **NEVER** row is worth clearing before an event — use **Calibrate** on the
[Transcriber](#transcriber-transcriber) page, with the radio quiet.

Each row has a **Delete** button that clears that host's stored report. It is for tidying
stale or renamed entries — the row reappears on that device's next nightly upload, so it is
not a way to hide a bad gate for long.

The URL stays under `/igate/` even though the page now covers Transcribers too, because
every deployed gate posts to that path and the link is in people's bookmarks.

---

## Analyzer — `/analyzer/`

Requires `analyzer.view`; destructive operations require `analyzer.admin`.

Where the Map Admin shows you where everyone is *now*, the Analyzer shows you where they
have been. It records every beacon of an event and lets you replay the whole day afterward —
for a debrief, for working out why a runner was reported missing, or for showing a landowner
that riders stayed on the course.

Signing in is the same account; there is nothing separate to set up.

Opening `/analyzer/` takes you straight to the current event's map — there is no event list
to pick from first. Everything is driven from the **controls panel**, which opens from the
button on the map.

### Recording

At the top of the panel is the recording state and the **daemon toggle**. The dot beside it
is green while beacons are actively being collected and gray when they are not, and the panel
tells you when the current recording started and last received anything.

**This is the switch that matters, and the mistake to avoid is finding it off after the
event.** Turn it on before the first rider leaves. Only `analyzer.admin` can change it.

### Choosing what you are looking at

The middle of the panel filters the map. Nothing here changes the recording — you are
choosing what to draw from what was already collected:

| Control | Effect |
|---|---|
| **Show Tracks** | The line each tracker traveled, rather than just where they are |
| **Show Radio Beacons** / **Show Cellular Beacons** | Positions that arrived by radio (red) and by phone (green). Turning one off is how you see what coverage the *other* actually gave you |
| **Show iGates/Digipeaters** | The receiving stations themselves |
| **Show Radio Links** | Lines from a tracker to the iGate that heard it — this is the view that shows which gate was doing the work in each part of the course |
| **Show Courses** | The course overlay |
| **Show Names** | Labels on markers. Turn off when the map is crowded |
| **Auto-Refresh** | How often the view updates during a live event, 30 s to 5 min |

Below those, three multi-select lists narrow the map to specific **trackers**, **iGates**, or
**carriers**. Selecting one tracker and turning on Tracks and Radio Links answers "what
happened to this person" faster than anything else in the system.

### Choosing a time range

The two **beacon range** sliders set the start and end of what is displayed. Drag the start
slider forward to skip the hour of everyone milling around at registration, or pull the end
back to look at the finish. The labels above each slider show the time you have landed on.

### Getting things out

At the bottom of the panel:

| Button | Does |
|---|---|
| **Save Map** | Saves the current view as an image |
| **Export…** | Downloads the event's beacon data |
| **Playback** | Opens the replay — the event animated from start to finish. This is the one to use in a debrief |
| **Erase All…** | Deletes recorded data. `analyzer.admin` only, and it asks twice |

**Export before you erase.** Erase All is not recoverable from the Analyzer, and the beacon
history is not part of the event backup taken from Map Admin.

---

## App downloads

**No sign-in required** — these are the public pages you send participants to. Both are
permanent URLs, so the link in an email, a QR code, or the user guide never has to change
when a new version ships.

### iPhone and iPad

| | |
|---|---|
| **Send people to** | `https://marsaprs.org/ios/download.php` (or just `/ios/`) |
| **Where it goes** | The App Store listing for **APRS Map** |
| **Requires** | iOS 15 or later |

There is no page of install steps because none are needed — it is an ordinary App Store
install, and updates arrive by themselves.

The redirect exists so that written material can name one URL alongside the Android one.
Apple's own listing URL changes when the app is renamed — it has moved once already, from
`/marin-aprs-map/` to `/aprs-map/` — while the numeric id stays put, so the redirect links
on the id and anything printed keeps working.

Some testers may still be on **TestFlight** from before the App Store release. Move them
across when you can: TestFlight builds stop working 90 days after upload, and the resulting
"the app won't open" is the single most common iOS report.

### Android

| | |
|---|---|
| **Send people to** | `https://marsaprs.org/android/` |
| **Phone app** | `https://marsaprs.org/android/download.php` |
| **Watch app** | `https://marsaprs.org/android/watch.php` |

The landing page shows each build's version, size, date, and SHA-256, and carries the
install steps — Android sideloading trips an "unknown source" warning, so the steps are on
the page rather than somewhere people have to be told about separately.

The **watch app** is for Wear OS 3.0 and later and does messaging only; it does not share
location. Installing it is genuinely more involved than the phone app — a watch has no
browser, so the file has to be pushed across from the phone with the watch in developer
mode. The steps are on the same page.

### Publishing a new build

Copy the APK to the Pi under a versioned name and the download page picks up the newest
automatically. The filename prefix is what keeps the two Android apps apart — `aprs-map-`
for the phone, `aprs-wear-` for the watch — and they share an applicationId, so a watch APK
served as the phone download would be offered as an update to the phone app. See *Building
& Distributing* in the README for the commands.

When a release goes out, also update `map/app_version.php`, which is what makes the apps
show their "update available" prompt.

> **Currently out of step:** the iOS entry in `app_version.php` still has an empty
> `store_url` and a build number from 1.22.1, left over from before the App Store release.
> While `store_url` is empty the iOS update check does nothing at all, so iPhone users are
> never told a new version exists. Filling in the App Store URL and the current build number
> turns the prompt on.

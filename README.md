# Gallery Pulse — Piwigo plugin

Engagement analytics for albums shared through **UnlistedAlbum** `/v/` links. It adds
what Piwigo's History can't show: which photos guests open in the lightbox, how long
they stay on each, how far they zoom, when they try to save one, and what each guest
did, grouped by share link.

Admin: **Plugins → Gallery Pulse**, six tabs (Live is the one it opens on):

| Tab | Shows |
|---|---|
| Real-time | (since 1.7) A board of floating photo thumbnails for watching guests live: a photo appears when it's viewed, grows with activity (view or photo page +1, dwell +1 per 10 s, zoom +2, save intent +3, download +4), pulses on each new event, wears a blue ring that thickens with its downloads and a bar near its bottom that lengthens with its zooms, a pink &hearts; chip counting save attempts and a dark chip counting distinct guests from two up (since 1.8; all over the last 30 minutes; ring and bar level off so a busy photo stays readable), and shrinks away as its score decays (half-life 5 minutes; nothing older than 30 minutes counts). At most 60 photos at once. Under the board, a timeline of distinct guests per minute over the last 30 minutes (since 1.8, replacing the status line). Unfiltered, and nothing is kept: it opens from the newest events and starts over when the browser tab is shown again. Polls like Live. Hover a photo for its numbers in a panel at the top right (since 1.8); click it for its detail page |
| Live | Newest 500 events, then **Show older** pages back 500 at a time. Polls every 1 s while events arrive, 5 s after three empty polls, not at all while the tab is hidden. Keeps at most 2,000 rows on screen. The Album column shows the album each event was viewed in (full path, linked to Photos filtered to it); the share link is on the visitor timeline. The Event cell carries the value (`dwell 1.2s`, `zoom 2.4×`); the IP column shows the visitor's address with its city and country beneath |
| Photos | Per photo: views, guests, dwell, zooms, max zoom, save intent, downloads, avg dwell (mean of the dwell records). Every column sorts either way on the server (the top 200 of that sort are shown). Click a photo for its detail page: totals, guests, events, and Piwigo's own History counts for it, shown separately. Hovering a thumbnail anywhere shows a larger preview |
| Visitors | One row per browser, by username when a registered user was logged in, with the albums they viewed (latest first; three shown, the rest on hover over *+N more*) and the IP they were last seen from, with its location. The 200 most recently active are shown. Click a row for the visitor's timeline, which also shows the share link of each event |
| Albums | Totals per album (full path), with the routes guests came by: each share link, or *direct* for public pages and logged-in users |
| Settings | Retention, display time zone, GeoIP database path, and the event table's size |

**Filters** (since 1.6) on Live, Photos, Visitors and Albums: period or a date range
(whole days in the display time zone), album (with its sub-albums), events (any
number; none ticked = all) and guest (a browser id as shown, 4+ characters, a
username, or `no beacon`; an exact username wins over a browser id). There is no Apply button (since 1.7): a list applies when changed, Events when its list closes, Guest on Enter, on leaving the box or on picking a suggestion, and a date range once both dates are in (or on Enter, or on leaving the range). Each tab keeps its own filter, and the Photos sort with it,
in the admin's Piwigo user preferences, so a bare tab link brings it back; **Reset
filters** clears it. A link carrying filter values (e.g. an Albums row's Photos link,
which passes on the rest of the Albums filter) replaces the target tab's filter. Live
applies its filter to every poll and Show older page. A photo's detail page takes only
the period.

**Sorting** (since 1.6): every other table sorts in the page by clicking a heading,
again to reverse; numbers and times start largest or latest first, blanks stay at the
bottom, and each table's sort is remembered in the browser. Live keeps its sort as
polled rows arrive.

## What is recorded

| Event | Source | Notes |
|---|---|---|
| `page_view` | beacon | Album page (`album`) or photo page (`photo`) |
| `lightbox_view` | beacon | Each PhotoSwipe slide shown. Slides advanced by the slideshow get detail `autoplay` and aren't counted as views |
| `dwell` | beacon | Seconds on a slide, not counting time the tab was hidden or the window out of focus, nor anything past 60 s without mouse, touch or key input. Capped at 1800. Not logged for slideshow slides |
| `zoom` | beacon | Highest zoom reached on a slide, as a multiple of fit-to-screen. Only logged above 1.05× |
| `fullscreen` | beacon | Fullscreen entered while a slide is open |
| `save_intent` | beacon | `contextmenu` (right-click), `longpress` (touch held ≥ 600 ms) or `drag` on the lightbox image or the photo page's main image. This is intent, not a confirmed save |
| `download` | server | Logged in `action.php` (`loc_action_before_http_headers`) when `download` is in the URL. HEAD requests, cache revalidations, missing files and repeats of the same photo by the same browser within 60 s are skipped. On phones the theme fetches the file before showing the share sheet, so a download means the file was delivered, even if the guest then cancels |

**Screenshots can't be detected.** The OS captures the screen without the page knowing.

**Guest identity** is a first-party cookie, `pulse_sid` (random 128-bit, 30 days,
renewed on each visit).

**IP address** (since 1.5) is stored with every event: `REMOTE_ADDR`, which is the
visitor because the gallery isn't behind a proxy or CDN. Events from before 1.5 have
none. Live, the visitor timeline and the photo detail's Events table show it.

**Location** ("City, CC", or the country alone when the database has no city) is read
from a local MaxMind GeoLite2 City database when an admin page is shown, using
MaxMind's pure-PHP reader (vendored in `include/vendor/`, Apache-2.0; no PHP extension
needed). No address leaves the server and nothing is cached in the database. Private
and reserved addresses get no location. The path is on the Settings tab, default
`/usr/share/GeoIP/GeoLite2-City.mmdb` as the PHP container sees it (mount the host's
GeoIP directory read-only; don't put the file under the web root, where it could be
downloaded). Blank turns locations off; a missing or invalid file is reported on the
Settings tab and pages show addresses without a location. GeoLite2 places an address
at ISP level, sometimes a neighbouring town, and is only as current as the file.

**Views** in the admin are lightbox views only. Photo-page opens are a separate
column, so a guest who opens the photo page and then its lightbox isn't counted
twice.

**Album:** each photo event is filed under the album the guest was looking at.
The beacon sends the page's album with every event (the URL's `c-` token under a
share link, the numeric id otherwise). The server uses it only if the album is
within the share link's albums (or visible to the visitor, without a link) **and**
the photo is filed in it; otherwise the event goes to the lowest-id album the photo
is filed in. Events from before 1.2, and downloads, always use that fallback.

**Device** is parsed from the User-Agent: `iphone`, `ipad`, `android`, `windows`,
`mac`, `linux` or `other`. iPadOS reports itself as a Mac, so the beacon sends a
touch-screen hint that turns a Mac into an iPad; downloads don't carry the hint, so
an iPad download shows as `mac`. Events from before 1.2 say `phone`, `tablet` or
`desktop`.

**Times** are stored in UTC (MySQL `NOW()`, on a database server that runs in UTC)
and shown in the time zone set on the Settings tab, default `America/New_York`,
with daylight saving applied. Table headers name the zone.

**Share link** is the `/v/<key>` in the page URL. The server checks it against
UnlistedAlbum's table, and every image or category in an event must belong to that
link's album tree. If either check fails, the event is dropped, or for an unknown
key, stored without a link. Downloads take the key from UnlistedAlbum's session
context. On `/v/` pages the beacon never prints a numeric album id, which
UnlistedAlbum hides: album page views send the URL's `c-` token and the server
resolves it within the link's albums. Core `history` download rows carry no category, so they can't be attributed
to a link.

**Abuse limits:** at most 50 events per request, 600 per browser and 20,000 in
total per 11 minutes. Without a share link, only photos and albums the visitor
can see are accepted.

**Batch Downloader zips** are logged as `download` with detail `zip`, one row per
photo, from Batch Downloader's `batchdownload_end_zip` trigger. It fires once per zip
as the zip is built, with that zip's photos only, so a multi-zip set logs each photo
once and fetching a built zip again logs nothing. Browser, user and share link come
from the request, like a single download (later zips of a big set are built on
`/download/init_zip`, which carries the link through UnlistedAlbum's session grant).
An album zip credits its album for every photo filed in it; other sets (tags, search,
selections) use the photo's filed album. Batch Downloader only lets guests zip when
`$conf['batch_download_guest_allowed']` is set, so on a gallery without it, share-link
guests can't zip and zips come from logged-in users.

**Not tracked:** admins (no beacon, ingest ignores them), clients without JS.

**Retention:** 730 days by default, set on the Settings tab (30–3650). The prune runs
once a day, triggered by beacon traffic (Piwigo has no scheduler). A row is about
100 bytes plus indexes, so even 50,000 events a year stays around 20 MB for two years.

**Piwigo History** is only read, never written: the photo detail page shows core
photo-page views and downloads for the period and the all-time hit counter, labeled
as a separate count because it includes admins and visits from before the plugin.

## Requirements

Piwigo 16.4. Theme: Bootstrap Darkroom with PhotoSwipe 4.1.3. The beacon wraps the
global `PhotoSwipe` constructor, so the theme needs no edits. If a theme update
removes or renames it, the lightbox events stop and page views and photo-page save
intent keep working. Works without UnlistedAlbum, but then nothing is grouped by link.

## Install

Copy the repo to `plugins/gallery_pulse/` (the folder name must be exactly that), then
activate it in **Plugins**. Deactivating stops collection and keeps the data.
Uninstalling drops `piwigo_pulse_event`.

## Development

```bash
dev/setup.sh                                  # local Piwigo 16.4.0 on :8089, seeded share link
python tests/e2e_test.py <token-from-setup>   # desktop Chromium + emulated iPhone
```

`setup.sh` copies the theme and UnlistedAlbum from the live server over ssh, the
first time only. The plugin directory is bind-mounted, so edits apply immediately.
Locations in the dev stack come from `dev/GeoIP2-City-Test.mmdb`, MaxMind's test
database from github.com/maxmind/MaxMind-DB (`test-data/`), e.g. `81.2.69.160` =
London, GB.

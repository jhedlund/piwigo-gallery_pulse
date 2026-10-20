# Gallery Pulse — Piwigo plugin

Engagement analytics for albums shared through **UnlistedAlbum** `/v/` links. It adds
what Piwigo's History can't show: which photos guests open in the lightbox, how long
they stay on each, how far they zoom, when they try to save one, and what each guest
did, grouped by share link.

Admin: **Plugins → Gallery Pulse**, five tabs:

| Tab | Shows |
|---|---|
| Live | Newest 500 events, then **Show older** pages back 500 at a time. Polls every 1 s while events arrive, 5 s after three empty polls, not at all while the tab is hidden. Keeps at most 2,000 rows on screen |
| Photos | Per photo: views, guests, dwell, zooms, max zoom, save intent, downloads. Sortable; filterable by period and by album (an album includes its sub-albums). Click a photo for its detail page: totals, guests, events, and Piwigo's own History counts for it, shown separately. Hovering a thumbnail anywhere shows a larger preview |
| Visitors | One row per browser, by username when a registered user was logged in, with the albums they visited; filterable by album. Click a row for the visitor's timeline, which also shows the share link of each event |
| Albums | Totals per album (full path), with the routes guests came by: each share link, or *direct* for public pages and logged-in users |
| Settings | Retention, display time zone, and the event table's size |

## What is recorded

| Event | Source | Notes |
|---|---|---|
| `page_view` | beacon | Album page (`album`) or photo page (`photo`) |
| `lightbox_view` | beacon | Each PhotoSwipe slide shown. Slides advanced by the slideshow get detail `autoplay` and aren't counted as views |
| `dwell` | beacon | Seconds on a slide, not counting time the tab was hidden. Capped at 1800. Not logged for slideshow slides |
| `zoom` | beacon | Highest zoom reached on a slide, as a multiple of fit-to-screen. Only logged above 1.05× |
| `fullscreen` | beacon | Fullscreen entered while a slide is open |
| `save_intent` | beacon | `contextmenu` (right-click), `longpress` (touch held ≥ 600 ms) or `drag` on the lightbox image or the photo page's main image. This is intent, not a confirmed save |
| `download` | server | Logged in `action.php` (`loc_action_before_http_headers`) when `download` is in the URL. HEAD requests, cache revalidations, missing files and repeats of the same photo by the same browser within 60 s are skipped. On phones the theme fetches the file before showing the share sheet, so a download means the file was delivered, even if the guest then cancels |

**Screenshots can't be detected.** The OS captures the screen without the page knowing.

**Guest identity** is a first-party cookie, `pulse_sid` (random 128-bit, 30 days,
renewed on each visit). No IP addresses are stored.

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

**Not tracked:** admins (no beacon, ingest ignores them), clients without JS, and
Batch Downloader zip downloads.

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

# Gallery Pulse — Piwigo plugin

Engagement analytics for albums shared through **UnlistedAlbum** `/v/` links. It adds
what Piwigo's History can't show: which photos guests open in the lightbox, how long
they stay on each, how far they zoom, when they try to save one, and what each guest
did, grouped by share link.

Admin: **Plugins → Gallery Pulse**, four tabs:

| Tab | Shows |
|---|---|
| Live | Newest events, polled every 5 s |
| Photos | Per photo: views, guests, dwell, zooms, max zoom, save intent, downloads. Sortable and filterable by period and by share link |
| Visitors | One row per browser; click a row for that guest's timeline |
| Links | Totals per share link |

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

**Retention:** 180 days. The prune runs once a day, triggered by beacon traffic
(Piwigo has no scheduler).

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

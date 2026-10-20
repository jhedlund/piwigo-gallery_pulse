"""
End-to-end check of Gallery Pulse against the local stack in dev/.

    python -I tests/e2e_test.py <share-token>

Drives a real share link in desktop Chromium and an emulated iPhone (WebKit),
then asserts on the rows that landed in piwigo_pulse_event.
"""
import json
import subprocess
import sys
import time
import urllib.request

from playwright.sync_api import sync_playwright

BASE = "http://localhost:8089/photos/"
DB = ["docker", "exec", "gallery-pulse-test-db-1", "mariadb", "-upiwigo", "-ppiwigo", "piwigo", "-N", "-B", "-e"]


def sql(query):
    out = subprocess.run(DB + [query], capture_output=True, text=True, check=True).stdout
    return [line.split("\t") for line in out.strip().splitlines() if line]


def max_id():
    return int(sql("SELECT COALESCE(MAX(id),0) FROM piwigo_pulse_event")[0][0])


def events_since(since):
    return sql(
        "SELECT sid, share_token, IFNULL(image_id,''), IFNULL(category_id,''), event, IFNULL(value,''), "
        "IFNULL(detail,''), IFNULL(device,'') FROM piwigo_pulse_event WHERE id > %d ORDER BY id" % since
    )


def wait_rows(since, predicate, timeout=20):
    deadline = time.time() + timeout
    rows = []
    while time.time() < deadline:
        rows = events_since(since)
        if predicate(rows):
            return rows
        time.sleep(0.5)
    return rows


failures = []
DESKTOP_OS = {"win32": "windows", "darwin": "mac"}.get(sys.platform, "linux")


def check(cond, msg):
    print(("PASS " if cond else "FAIL ") + msg)
    if not cond:
        failures.append(msg)


def desktop(p, link):
    since = max_id()
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1400, "height": 900}, accept_downloads=True)
    page = ctx.new_page()
    page.goto(link)
    page.locator("#thumbnails a[data-index]").first.click()
    page.wait_for_selector(".pswp--open")
    page.wait_for_timeout(1500)
    # Desktop zoom: PhotoSwipe toggles zoom on image click.
    page.locator(".pswp__item:nth-child(2) .pswp__img").last.click()
    page.wait_for_timeout(1200)
    page.locator(".pswp__img").last.click(button="right")
    page.wait_for_timeout(300)
    page.keyboard.press("ArrowRight")
    page.wait_for_timeout(1500)
    with page.expect_download() as dl:
        page.locator(".pswp__button--download").click()
    dl.value
    # Unzoomed swipe from a landscape slide to the portrait one: the fit levels
    # differ, which is where a stale zoom reading would invent a zoom.
    page.keyboard.press("ArrowRight")
    page.wait_for_timeout(1200)
    page.keyboard.press("ArrowLeft")
    page.wait_for_timeout(1200)
    page.keyboard.press("Escape")
    page.wait_for_timeout(500)
    sid = ctx.cookies()[0:]
    pulse_sid = next((c["value"] for c in sid if c["name"] == "pulse_sid"), None)
    page.close()  # pagehide flush
    rows = wait_rows(since, lambda r: any(x[4] == "download" for x in r) and any(x[4] == "dwell" for x in r))
    browser.close()

    kinds = [r[4] for r in rows]
    print("desktop events:", kinds)
    check(pulse_sid is not None and len(pulse_sid) == 32, "desktop: pulse_sid cookie set")
    check(all(r[0] == pulse_sid for r in rows), "desktop: every row carries the browser's sid")
    check(all(r[1] == link.rsplit("/", 1)[-1] for r in rows), "desktop: every row carries the share token")
    check("page_view" in kinds, "desktop: page_view logged")
    check(kinds.count("lightbox_view") >= 2, "desktop: lightbox_view per slide")
    check("dwell" in kinds, "desktop: dwell logged")
    zooms = [float(r[5]) for r in rows if r[4] == "zoom"]
    check(bool(zooms) and max(zooms) > 1.05, "desktop: zoom logged with scale > 1 (%s)" % zooms)
    first_img = next(r[2] for r in rows if r[4] == "lightbox_view")
    check(all(r[2] == first_img for r in rows if r[4] == "zoom"), "desktop: no zoom row for the slide that was never zoomed")
    check(any(r[4] == "save_intent" and r[6] == "contextmenu" for r in rows), "desktop: right-click = save_intent")
    downloads = [r for r in rows if r[4] == "download"]
    check(len(downloads) == 1, "desktop: exactly one download row (%d)" % len(downloads))
    check(all(r[2] != "" for r in rows if r[4] != "page_view"), "desktop: photo events have image_id")
    check(all(r[7] == DESKTOP_OS for r in rows), "desktop: device = OS from the User-Agent (%s)" % rows[0][7])
    return pulse_sid


def iphone(p, link):
    # Chromium with the iPhone descriptor: Playwright's WebKit can't synthesize
    # a held touch, and CDP touch events give a real touchstart/touchend pair.
    since = max_id()
    browser = p.chromium.launch()
    ctx = browser.new_context(**p.devices["iPhone 13"])
    page = ctx.new_page()
    page.goto(link)
    page.locator("#thumbnails a[data-index]").first.tap()
    page.wait_for_selector(".pswp--open")
    page.wait_for_timeout(1500)
    # Viewport centre is the current slide; .pswp__img.last is a preloaded neighbour off-screen.
    vp = page.viewport_size
    x, y = vp["width"] / 2, vp["height"] / 2
    cdp = ctx.new_cdp_session(page)
    cdp.send("Input.dispatchTouchEvent", {"type": "touchStart", "touchPoints": [{"x": x, "y": y}]})
    page.wait_for_timeout(800)
    cdp.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})
    page.wait_for_timeout(1500)
    # Closing the lightbox flushes from the live page: Chromium sends a beacon
    # fired during unload with its own User-Agent, not the emulated iPhone's.
    page.locator(".pswp__button--close").tap()
    page.wait_for_timeout(500)
    page.goto("about:blank")
    rows = wait_rows(since, lambda r: any(x[4] == "dwell" for x in r))
    pulse_sid = next((c["value"] for c in ctx.cookies() if c["name"] == "pulse_sid"), None)
    browser.close()

    kinds = [r[4] for r in rows]
    print("iphone events:", kinds)
    check("lightbox_view" in kinds and "dwell" in kinds, "iphone: lightbox_view + dwell")
    check(any(r[4] == "save_intent" and r[6] == "longpress" for r in rows), "iphone: long-press = save_intent")
    check(all(r[7] == "iphone" for r in rows), "iphone: device = iphone")
    return pulse_sid


def autoplay(p, link):
    """Slides advanced by the slideshow are flagged and get no dwell."""
    since = max_id()
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1400, "height": 900})
    page.goto(link)
    page.locator("#thumbnails a[data-index]").first.click()
    page.wait_for_selector(".pswp--open")
    page.wait_for_timeout(1000)
    page.locator(".pswp__button--autoplay").click()
    page.wait_for_timeout(9500)  # live photoswipe_interval is 8000 ms
    page.keyboard.press("Escape")
    page.goto("about:blank")
    rows = wait_rows(since, lambda r: any(x[6] == "autoplay" for x in r), timeout=10)
    browser.close()
    auto_imgs = {r[2] for r in rows if r[4] == "lightbox_view" and r[6] == "autoplay"}
    check(bool(auto_imgs), "autoplay: slideshow slides flagged autoplay")
    check(not any(r[4] == "dwell" and r[2] in auto_imgs for r in rows), "autoplay: no dwell for slideshow slides")


def page_context(p, link, token):
    since = max_id()
    html = urllib.request.urlopen(link).read().decode()
    start = html[html.index("GalleryPulse.start("):]
    check('"cat":null' in start[:300], "/v/ page does not print the category id")

    sub_id = sql("SELECT id FROM piwigo_categories WHERE name = 'Second Half'")[0][0]
    browser = p.chromium.launch()
    page = browser.new_page()
    page.goto(link)
    sub_href = page.eval_on_selector("a[href*='/c-']", "a => a.href")
    page.goto(sub_href)
    page.wait_for_timeout(500)
    page.goto("about:blank")
    rows = wait_rows(since, lambda r: len([x for x in r if x[4] == "page_view"]) >= 2)
    browser.close()
    cats = [r[3] for r in rows if r[4] == "page_view"]
    check(cats[-1:] == [sub_id], "sub-album page_view resolves its c-token to the sub-album (%s)" % cats)


def browse_lightbox(page, url, slides):
    page.goto(url)
    page.locator("#thumbnails a[data-index]").first.click()
    page.wait_for_selector(".pswp--open")
    page.wait_for_timeout(800)
    for _ in range(slides):
        page.keyboard.press("ArrowRight")
        page.wait_for_timeout(700)
    page.keyboard.press("Escape")
    page.wait_for_timeout(300)
    page.goto("about:blank")


def viewed_album(p, link, token):
    """A photo filed in both a parent and its sub-album is credited to the album it was viewed in."""
    top_id = sql("SELECT category_id FROM piwigo_unlisted_albums WHERE random_key = '%s'" % token)[0][0]
    sub_id = sql("SELECT id FROM piwigo_categories WHERE name = 'Second Half'")[0][0]
    both = sql("SELECT MIN(image_id) FROM piwigo_image_category WHERE category_id = %s" % sub_id)[0][0]
    sql("INSERT INTO piwigo_image_category (image_id, category_id) VALUES (%s, %s)" % (both, top_id))
    sql("UPDATE piwigo_user_cache SET need_update = 'true'")
    try:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={"width": 1400, "height": 900})

        since = max_id()
        page.goto(link)
        sub_href = page.eval_on_selector("a[href*='/c-']", "a => a.href")
        browse_lightbox(page, sub_href, 2)
        rows = wait_rows(since, lambda r: any(x[4] == "lightbox_view" and x[2] == both for x in r))
        cats = {r[3] for r in rows if r[2] == both}
        check(cats == {sub_id}, "viewed album: photo seen inside the sub-album is credited to it, not the parent (%s)" % cats)

        since = max_id()
        page.goto(sub_href)  # renews the share-link session
        only_top = sql("SELECT MIN(image_id) FROM piwigo_image_category WHERE category_id = %s AND image_id <> %s"
                       % (top_id, both))[0][0]
        for img in (both, only_top):
            page.request.get(BASE + "action.php?id=%s&part=e&download" % img, headers={"Referer": sub_href})
        got = [(r[2], r[3]) for r in events_since(since) if r[4] == "download"]
        check(got == [(both, sub_id), (only_top, top_id)],
              "viewed album: download credited to the album in the Referer only when the photo is in it (%s)" % got)

        since = max_id()
        n = int(sql("SELECT COUNT(*) FROM piwigo_image_category WHERE category_id = %s" % top_id)[0][0])
        browse_lightbox(page, link, n)
        rows = wait_rows(since, lambda r: any(x[4] == "lightbox_view" and x[2] == both for x in r))
        cats = {r[3] for r in rows if r[2] == both}
        check(cats == {top_id}, "viewed album: same photo seen in the parent is credited to the parent (%s)" % cats)
        browser.close()

        # Forged context: a sub-album token for a photo that isn't in it falls back to where it is filed.
        import hashlib
        sub_tok = "c-" + hashlib.md5((token + "cat" + sub_id).encode()).hexdigest()[:8]
        only_top = sql("SELECT MIN(image_id) FROM piwigo_image_category WHERE category_id = %s AND image_id <> %s"
                       % (top_id, both))[0][0]
        since = max_id()
        events = [{"e": "fullscreen", "img": int(only_top), "ctok": sub_tok},
                  {"e": "fullscreen", "img": int(both), "ctok": sub_tok},
                  {"e": "fullscreen", "img": int(both), "ctok": "c-00000000"}]
        data = ("method=galleryPulse.track&sid=" + "9" * 32 + "&token=" + token + "&events=" +
                urllib.request.quote(json.dumps(events))).encode()
        urllib.request.urlopen(BASE + "ws.php?format=json", data).read()
        rows = events_since(since)
        got = [(r[2], r[3]) for r in rows]
        check(got == [(only_top, top_id), (both, sub_id), (both, top_id)],
              "viewed album: forged or unknown album context rejected, valid one kept (%s)" % got)
        sql("DELETE FROM piwigo_pulse_event WHERE id > %d" % since)
    finally:
        sql("DELETE FROM piwigo_image_category WHERE image_id = %s AND category_id = %s" % (both, top_id))
        sql("UPDATE piwigo_user_cache SET need_update = 'true'")


def device_os(token):
    """Device family from the User-Agent; the touch hint only turns a Mac into an iPad."""
    img = sql("SELECT MIN(image_id) FROM piwigo_image_category")[0][0]
    mac = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15"
    android = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36"
    cases = [(mac, "1", "ipad"), (mac, "0", "mac"), (mac, "yes", "mac"), (android, "0", "android")]
    for ua, touch, want in cases:
        since = max_id()
        data = ("method=galleryPulse.track&sid=" + "8" * 32 + "&token=" + token + "&touch=" + touch + "&events=" +
                urllib.request.quote(json.dumps([{"e": "fullscreen", "img": int(img)}]))).encode()
        urllib.request.urlopen(urllib.request.Request(BASE + "ws.php?format=json", data, {"User-Agent": ua})).read()
        got = [r[7] for r in events_since(since)]
        check(got == [want], "device: %s UA, touch=%s -> %s (%s)" % (ua.split("(")[1].split(";")[0], touch, want, got))
        sql("DELETE FROM piwigo_pulse_event WHERE id > %d" % since)

    # A download has no touch hint: an iPad's Mac User-Agent takes the beacon's answer.
    import http.cookiejar
    jar = http.cookiejar.CookieJar()
    urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)).open(BASE + "index.php?/v/" + token).read()
    cookies = "; ".join("%s=%s" % (c.name, c.value) for c in jar)
    since = max_id()
    for sid, touch in (("8" * 32, "1"), ("6" * 32, "0")):
        data = ("method=galleryPulse.track&sid=" + sid + "&token=" + token + "&touch=" + touch + "&events=" +
                urllib.request.quote(json.dumps([{"e": "fullscreen", "img": int(img)}]))).encode()
        urllib.request.urlopen(urllib.request.Request(BASE + "ws.php?format=json", data, {"User-Agent": mac})).read()
        urllib.request.urlopen(urllib.request.Request(BASE + "action.php?id=%s&part=e&download" % img, headers={
            "User-Agent": mac, "Cookie": cookies + "; pulse_sid=" + sid})).read()
    got = [(r[0][:1], r[7]) for r in events_since(since) if r[4] == "download"]
    check(got == [("8", "ipad"), ("6", "mac")], "device: an iPad's download shows as ipad, a Mac's as mac (%s)" % got)
    sql("DELETE FROM piwigo_pulse_event WHERE id > %d" % since)


def downloads(link):
    """Repeat fetches within 60 s, HEAD and 404s don't add rows."""
    import http.cookiejar
    since = max_id()
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    opener.open(link).read()  # UnlistedAlbum session grant
    # An explicit Cookie header replaces the jar's, so carry the session cookie too.
    cookies = "; ".join("%s=%s" % (c.name, c.value) for c in jar)
    opener = urllib.request.build_opener()
    opener.addheaders = [("Cookie", cookies + "; pulse_sid=" + "e" * 32)]
    img = sql("SELECT MIN(image_id) FROM piwigo_image_category")[0][0]
    url = BASE + "action.php?id=%s&part=e&download" % img
    opener.open(url).read()
    opener.open(url).read()
    opener.open(urllib.request.Request(url, method="HEAD")).read()
    rows = [r for r in events_since(since) if r[4] == "download"]
    check(len(rows) == 1, "download: two fetches + HEAD in 60 s = one row (%d)" % len(rows))
    check(rows and rows[0][1] == link.rsplit("/", 1)[-1], "download: attributed to the share link via session")
    sql("DELETE FROM piwigo_pulse_event WHERE id > %d" % since)


def no_js(link):
    since = max_id()
    urllib.request.urlopen(link).read()
    time.sleep(1)
    check(events_since(since) == [], "curl: no JS = no pulse rows")


def bad_input(link):
    """Junk from a forged beacon must not land."""
    since = max_id()
    token = link.rsplit("/", 1)[-1]
    other_img = sql("SELECT MAX(id)+1000 FROM piwigo_images")[0][0]
    events = [
        {"e": "download", "img": 1},                 # server-only event
        {"e": "lightbox_view", "img": int(other_img)},  # image outside the link
        {"e": "save_intent", "img": 1, "d": "evil"},  # bad detail
        {"e": "zoom", "img": 1, "v": 0.5},            # zoom must be > 1
        {"e": "dwell", "img": 1, "v": 99999},         # clamped to 1800
    ]
    data = ("method=galleryPulse.track&sid=" + "a" * 32 + "&token=" + token + "&events=" +
            urllib.request.quote(json.dumps(events))).encode()
    resp = json.loads(urllib.request.urlopen(BASE + "ws.php?format=json", data).read())
    rows = events_since(since)
    check(resp.get("result", {}).get("stored") == 1 and len(rows) == 1, "forged batch: only the valid dwell stored (%s)" % resp)
    check(rows and float(rows[0][5]) == 1800.0, "forged batch: dwell clamped to 1800")

    resp = json.loads(urllib.request.urlopen(BASE + "ws.php?format=json",
                                             b"method=galleryPulse.track&sid=nothex&events=[]").read())
    check(resp.get("stat") == "fail", "bad sid rejected")

    # Without a token: an out-of-range album id and a photo the guest can't see
    # (unlisted albums are private) are both dropped, without a SQL error.
    events = [{"e": "page_view", "img": None, "d": "album", "cat": 70000},
              {"e": "lightbox_view", "img": 1}]
    data = ("method=galleryPulse.track&sid=" + "f" * 32 + "&events=" + urllib.request.quote(json.dumps(events))).encode()
    resp = json.loads(urllib.request.urlopen(BASE + "ws.php?format=json", data).read())
    check(resp.get("result", {}).get("stored") == 0, "no token: unknown album and private photo dropped (%s)" % resp)
    sql("DELETE FROM piwigo_pulse_event WHERE id > %d" % since)


PHP_ERRORS = ("Warning:", "Notice:", "Deprecated:", "Fatal error", "Uncaught")
ADMIN = BASE + "admin.php?page=plugin-gallery_pulse"


def admin_page(page, url):
    page.goto(url)
    html = page.content()
    return html, not any(e in html for e in PHP_ERRORS)


LIVE_PROBE = """
window.__polls = []; window.__inflight = 0; window.__maxInflight = 0; window.__delay = 0;
window.__hidden = false;
Object.defineProperty(document, 'hidden', { get: () => window.__hidden });
const realFetch = window.fetch;
window.fetch = function (u, o) {
  if (String(u).indexOf('since_id') < 0) { return realFetch(u, o); }
  window.__polls.push(performance.now());
  window.__inflight++;
  window.__maxInflight = Math.max(window.__maxInflight, window.__inflight);
  return new Promise(r => setTimeout(r, window.__delay))
    .then(() => realFetch(u, o))
    .finally(() => { window.__inflight--; });
};
window.__setHidden = h => { window.__hidden = h; document.dispatchEvent(new Event('visibilitychange')); };
"""


def live_poll(ctx, sid, token, img, cat):
    """Adaptive Live poll: 1 s while active, 5 s when quiet, paused while hidden, never overlapping."""
    page = ctx.new_page()
    page.add_init_script(LIVE_PROBE)
    page.goto(ADMIN + "-live")
    page.wait_for_timeout(500)
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, share_token, image_id, category_id, event) "
        "VALUES (NOW(), '%s', '%s', %s, %s, 'fullscreen')" % (sid, token, img, cat))
    new_id = sql("SELECT MAX(id) FROM piwigo_pulse_event")[0][0]
    t0 = time.time()
    page.wait_for_selector("#gp-live tr[data-id='%s']" % new_id, timeout=5000)
    check(time.time() - t0 < 2.5, "live poll: new event shown within ~1 s (%.1fs)" % (time.time() - t0))

    page.wait_for_timeout(10000)
    polls = page.evaluate("window.__polls")
    gaps = [b - a for a, b in zip(polls, polls[1:])]
    check(gaps[0] < 1500 and gaps[-1] > 4500, "live poll: 1 s cadence backs off to 5 s when quiet (%s)"
          % [round(g / 1000, 1) for g in gaps])

    page.evaluate("window.__setHidden(true)")
    n = page.evaluate("window.__polls.length")
    page.wait_for_timeout(7000)
    check(page.evaluate("window.__polls.length") == n, "live poll: stops while the tab is hidden")
    page.evaluate("window.__setHidden(false)")
    page.wait_for_timeout(300)
    check(page.evaluate("window.__polls.length") == n + 1, "live poll: polls immediately when shown again")
    page.wait_for_timeout(1500)
    check(page.evaluate("window.__polls.length") >= n + 2, "live poll: resumes at 1 s after becoming visible")

    page.evaluate("window.__delay = 2500")
    page.wait_for_timeout(1200)
    page.evaluate("window.__setHidden(false); window.__setHidden(false)")
    page.wait_for_timeout(4000)
    check(page.evaluate("window.__maxInflight") == 1, "live poll: never two requests in flight")
    page.close()


def live_trim(ctx, sid, token, img, cat):
    """Past the 2,000-row cap, a poll never drops rows Show older loaded, and every event stays reachable."""
    # Seeded out of id order by time, so trims and paging can't rely on the two agreeing.
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, share_token, image_id, category_id, event) "
        "SELECT NOW() - INTERVAL 3000 + (seq %% 7) * 50 - seq SECOND, '%s', '%s', %s, %s, 'dwell' FROM seq_0_to_1999"
        % (sid, token, img, cat))
    total = int(sql("SELECT COUNT(*) FROM piwigo_pulse_event")[0][0])
    page = ctx.new_page()
    page.goto(ADMIN + "-live")
    for _ in range(10):
        if not page.is_visible("#gp-older") or page.locator("#gp-live tbody tr").count() >= 2400:
            break
        page.click("#gp-older")
        page.wait_for_function("!document.getElementById('gp-older').disabled")
    loaded = page.locator("#gp-live tbody tr").count()
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, share_token, image_id, category_id, event) "
        "VALUES (NOW(), '%s', '%s', %s, %s, 'fullscreen')" % (sid, token, img, cat))
    new_id = sql("SELECT MAX(id) FROM piwigo_pulse_event")[0][0]
    page.wait_for_selector("#gp-live tr[data-id='%s']" % new_id, timeout=5000)
    page.wait_for_timeout(1500)
    after = page.locator("#gp-live tbody tr").count()
    check(loaded > 2000 and after == loaded, "live trim: poll keeps rows Show older loaded (%d -> %d)" % (loaded, after))
    for _ in range(20):
        if not page.is_visible("#gp-older"):
            break
        page.click("#gp-older")
        page.wait_for_function("!document.getElementById('gp-older').disabled")
    ids = page.eval_on_selector_all("#gp-live tbody tr", "trs => trs.map(t => t.dataset.id)")
    check(len(ids) == len(set(ids)) == total + 1, "live trim: every event reachable once by paging (%d unique of %d)"
          % (len(set(ids)), total + 1))
    page.close()


def albums_tab(page, sid, token, top_id, sub_id, top_img):
    """Albums tab: routes per album, direct traffic, Unknown; Visitors: username and album filter."""
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, user_id, share_token, image_id, category_id, event) VALUES "
        "(NOW(), '%s', 1, NULL, %s, %s, 'lightbox_view'), (NOW(), '%s', NULL, NULL, NULL, NULL, 'page_view')"
        % ("e" * 32, top_img, top_id, "f" * 32))
    html, _ = admin_page(page, ADMIN + "-albums&days=1")
    rows = page.eval_on_selector_all("table.gp-table tbody tr", "trs => trs.map(t => [...t.cells].map(c => c.innerText))")
    by_name = {r[0]: r for r in rows}
    check("Test Match" in by_name and "direct" in by_name["Test Match"][1] and "link: Test Match" in by_name["Test Match"][1],
          "albums: an album lists both a share link and direct as routes (%s)" % by_name.get("Test Match", [None, None])[1])
    check("Test Match / Second Half" in by_name, "albums: sub-album row labelled by full path")
    check("Unknown" in by_name, "albums: events with no album group as Unknown")
    check(page.locator("a[href*='-photos'][href*='cat=%s']" % sub_id).count() == 1, "albums: album name links to Photos filtered to it")

    html, _ = admin_page(page, ADMIN + "-visitors&days=1")
    check(">admin<" in html, "visitors: a registered user is shown by username")
    check(page.locator("select[name=link]").count() == 0 and page.locator("select[name=cat]").count() == 1,
          "visitors: album filter replaces the share-link filter")
    admin_page(page, ADMIN + "-visitors&days=1&cat=%s" % sub_id)
    shown = page.eval_on_selector_all("table.gp-table tbody tr td:first-child", "tds => tds.map(t => t.innerText)")
    check(sid[:8] in shown and "admin" not in shown, "visitors: sub-album filter keeps only its visitors (%s)" % shown)
    html, _ = admin_page(page, ADMIN + "-visitors&sid=" + "e" * 32)
    check("<h3>admin" in html, "visitors: timeline names the user")


def time_zone(page):
    """Times shown in the configured zone; the Live poll still sorts on raw UTC."""
    admin_page(page, ADMIN + "-settings")
    check(page.input_value("select[name=timezone]") == "America/New_York", "tz: defaults to America/New_York")
    page.select_option("select[name=timezone]", "Asia/Tokyo")
    page.click("input[name=save]")
    check(sql("SELECT value FROM piwigo_config WHERE param = 'gallery_pulse_timezone'") == [["Asia/Tokyo"]], "tz: setting saved")
    page.evaluate("""() => { const o = document.createElement('option'); o.value = 'Mars/Olympus'; o.selected = true;
                             document.querySelector('select[name=timezone]').appendChild(o); }""")
    page.click("input[name=save]")
    check("Unknown time zone" in page.content(), "tz: unknown zone rejected")

    admin_page(page, ADMIN + "-live")
    first_id = page.get_attribute("#gp-live tbody tr", "data-id")
    utc = sql("SELECT occurred_at, occurred_at + INTERVAL 9 HOUR FROM piwigo_pulse_event WHERE id = %s" % first_id)[0]
    shown = page.inner_text("#gp-live tbody tr td")
    check(shown == utc[1] and "(Tokyo)" in page.inner_text("#gp-live thead"),
          "tz: Live shows UTC %s as %s Tokyo time (%s)" % (utc[0], utc[1], shown))
    data = page.evaluate("fetch('ws.php?format=json&method=galleryPulse.live&since_id=%d').then(r => r.json())" % (int(first_id) - 1))
    ev = data["result"]["events"][0]
    check(ev["occurred_at"] == utc[0] and ev["occurred_at_display"] == utc[1], "tz: poll returns raw UTC and display time")
    sql("DELETE FROM piwigo_config WHERE param = 'gallery_pulse_timezone'")


def admin(p, token):
    """Admin tabs: album filter, full paths, photo detail, hover, Live paging, retention setting."""
    since = max_id()
    top_id = sql("SELECT category_id FROM piwigo_unlisted_albums WHERE random_key = '%s'" % token)[0][0]
    sub_id = sql("SELECT id FROM piwigo_categories WHERE name = 'Second Half'")[0][0]
    top_img = sql("SELECT MIN(image_id) FROM piwigo_image_category WHERE category_id = %s" % top_id)[0][0]
    sub_img = sql("SELECT MIN(image_id) FROM piwigo_image_category WHERE category_id = %s" % sub_id)[0][0]
    sid = "c" * 32
    # 600 seeded events so Live has more than one page; one zoom per album.
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, share_token, image_id, category_id, event) "
        "SELECT NOW() - INTERVAL seq + 10 SECOND, '%s', '%s', %s, %s, 'lightbox_view' FROM seq_0_to_597"
        % (sid, token, top_img, top_id))
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, share_token, image_id, category_id, event, value) VALUES "
        "(NOW(), '%s', '%s', %s, %s, 'zoom', 2.5), (NOW(), '%s', '%s', %s, %s, 'zoom', 3.0)"
        % (sid, token, top_img, top_id, sid, token, sub_img, sub_id))

    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1400, "height": 900})
    ctx.request.post(BASE + "ws.php?format=json", form={"method": "pwg.session.login", "username": "admin", "password": "adminpass"})
    page = ctx.new_page()

    for tab in ("live", "photos", "visitors", "albums", "links", "settings"):
        html, clean = admin_page(page, ADMIN + "-" + tab)
        check(clean and "gp-wrap" in html, "admin: %s tab renders without PHP errors" % tab)
    pad = page.eval_on_selector(".gp-wrap", "e => parseFloat(getComputedStyle(e).paddingLeft)")
    check(pad >= 15, "admin: content padded off the left edge (%spx)" % pad)

    # Live: first page is 500, Show older pages back to everything.
    admin_page(page, ADMIN + "-live")
    check(page.locator("#gp-live tbody tr").count() == 500, "live: opens with 500 rows")
    page.click("#gp-older")
    page.wait_for_function("document.querySelectorAll('#gp-live tbody tr').length > 500")
    total = int(sql("SELECT COUNT(*) FROM piwigo_pulse_event")[0][0])
    rows = page.locator("#gp-live tbody tr").count()
    check(rows == min(total, 1000), "live: Show older adds the next page (%d of %d)" % (rows, total))
    ids = page.eval_on_selector_all("#gp-live tbody tr", "trs => trs.map(t => t.dataset.id)")
    check(len(ids) == len(set(ids)), "live: no duplicate rows after paging")

    live_poll(ctx, sid, token, top_img, top_id)
    live_trim(ctx, sid, token, top_img, top_id)

    # Album filter: full-path labels; a parent includes its sub-albums.
    html, _ = admin_page(page, ADMIN + "-photos&days=1")
    check("Test Match / Second Half" in html, "photos: album filter shows full paths")
    check(page.locator("select[name=link]").count() == 0, "photos: share-link filter replaced by album filter")
    _, clean = admin_page(page, ADMIN + "-photos&days=1&cat=%s" % sub_id)
    photo_ids = page.eval_on_selector_all("td.gp-photo a", "as => as.map(a => new URL(a.href).searchParams.get('image_id'))")
    sub_imgs = [r[0] for r in sql("SELECT image_id FROM piwigo_image_category WHERE category_id = %s" % sub_id)]
    check(clean and sub_img in photo_ids and set(photo_ids) <= set(sub_imgs),
          "photos: sub-album filter shows only its photos (%s)" % photo_ids)
    admin_page(page, ADMIN + "-photos&days=1&cat=%s" % top_id)
    photo_ids = page.eval_on_selector_all("td.gp-photo a", "as => as.map(a => new URL(a.href).searchParams.get('image_id'))")
    check(top_img in photo_ids and sub_img in photo_ids, "photos: parent album includes sub-album photos (%s)" % photo_ids)
    _, clean = admin_page(page, ADMIN + "-photos&days=1&cat=99999")
    check(clean and page.locator("select[name=cat] option[selected]").count() == 0, "photos: unknown album id ignored")

    # Hover popup: a sharper derivative, fetched only on hover, beside the thumbnail.
    fetched = []
    page.on("request", lambda r: fetched.append(r.url) if "-sm." in r.url else None)
    admin_page(page, ADMIN + "-photos&days=1")
    page.wait_for_timeout(500)
    check(fetched == [], "hover: no preview images fetched before hovering (%d)" % len(fetched))
    thumb = page.locator("td.gp-photo img").first
    thumb.hover()
    page.wait_for_selector(".gp-preview.gp-shown")
    box = page.locator(".gp-preview").bounding_box()
    tb = thumb.bounding_box()
    check(len(fetched) == 1 and box["x"] >= tb["x"] + tb["width"], "hover: popup shows the preview to the right of the thumbnail")
    check(box["y"] >= 0 and box["y"] + box["height"] <= 900, "hover: popup inside the viewport")
    page.mouse.move(5, 5)
    page.wait_for_timeout(200)
    check(not page.is_visible(".gp-preview"), "hover: popup hides on mouse out")

    # Detail view, and the round trip to Piwigo's own photo page.
    page.locator("td.gp-photo a[href*='image_id=%s']" % sub_img).first.click()
    page.wait_for_load_state()
    html = page.content()
    check(not any(e in html for e in PHP_ERRORS) and "Piwigo History" in html and "Guests" in html,
          "detail: renders pulse totals, core History block and guests")
    check(sid[:8] in html and ("3&times;" in html or "3×" in html), "detail: shows this photo's guest and max zoom")
    page.click("text=Edit in Piwigo")
    page.wait_for_load_state()
    check(("page=photo-%s" % sub_img) in page.url, "detail: links through to the core photo page (%s)" % page.url)
    _, clean = admin_page(page, ADMIN + "-photo&image_id=999999")
    check(clean and "Photo not found" in page.content(), "detail: unknown photo handled")

    albums_tab(page, sid, token, top_id, sub_id, top_img)
    time_zone(page)

    # Retention: default 730, validated, and the prune honours it.
    admin_page(page, ADMIN + "-settings")
    check(page.input_value("input[name=retention_days]") == "730", "settings: retention defaults to 730 days")
    page.fill("input[name=retention_days]", "5")
    page.evaluate("document.querySelector('input[name=retention_days]').removeAttribute('min')")
    page.click("input[name=save]")
    check("between 30 and 3650" in page.content(), "settings: out-of-range retention rejected")
    page.fill("input[name=retention_days]", "400")
    page.click("input[name=save]")
    check(sql("SELECT value FROM piwigo_config WHERE param = 'gallery_pulse_retention_days'") == [["400"]],
          "settings: retention saved")
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, image_id, event) VALUES "
        "(NOW() - INTERVAL 401 DAY, '%s', %s, 'zoom'), (NOW() - INTERVAL 399 DAY, '%s', %s, 'fullscreen')"
        % ("d" * 32, top_img, "d" * 32, top_img))
    sql("DELETE FROM piwigo_config WHERE param = 'gallery_pulse_last_prune'")
    data = ("method=galleryPulse.track&sid=" + "b" * 32 + "&token=" + token + "&events=" +
            urllib.request.quote(json.dumps([{"e": "fullscreen", "img": int(top_img)}]))).encode()
    urllib.request.urlopen(BASE + "ws.php?format=json", data).read()
    kept = [r[0] for r in sql("SELECT event FROM piwigo_pulse_event WHERE sid = '%s'" % ("d" * 32))]
    check(kept == ["fullscreen"], "prune: 401-day row deleted, 399-day row kept at 400-day retention (%s)" % kept)

    browser.close()
    sql("DELETE FROM piwigo_config WHERE param IN ('gallery_pulse_retention_days', 'gallery_pulse_last_prune')")
    sql("DELETE FROM piwigo_pulse_event WHERE id > %d OR sid = '%s'" % (since, "d" * 32))


def main():
    token = sys.argv[1]
    link = BASE + "index.php?/v/" + token
    with sync_playwright() as p:
        a = desktop(p, link)
        b = iphone(p, link)
        check(a and b and a != b, "two devices = two guests under one link")
        autoplay(p, link)
        page_context(p, link, token)
        admin(p, token)
        viewed_album(p, link, token)
    device_os(token)
    downloads(link)
    no_js(link)
    bad_input(link)
    print("\n%d failure(s)" % len(failures))
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()

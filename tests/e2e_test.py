"""
End-to-end check of Gallery Pulse against the local stack in dev/.

    python -I tests/e2e_test.py <share-token>

Drives a real share link in desktop Chromium and an emulated iPhone (WebKit),
then asserts on the rows that landed in piwigo_pulse_event.
"""
import ipaddress
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


def ips_since(since, where="1"):
    return [r[0] for r in sql("SELECT IFNULL(ip,'') FROM piwigo_pulse_event WHERE id > %d AND %s ORDER BY id" % (since, where))]


def private_ip(ip):
    try:
        return ipaddress.ip_address(ip).is_private
    except ValueError:
        return False


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
    ips = ips_since(since)
    check(len(set(ips)) == 1 and private_ip(ips[0]),
          "desktop: beacon and download rows all carry the request IP (%s)" % sorted(set(ips)))
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


def zip_rows(since):
    return [r for r in events_since(since) if r[4] == "download" and r[6] == "zip"]


def harness(calls, token=""):
    subprocess.run(["docker", "exec", "-w", "/var/www/html/photos", "gallery-pulse-test-web-1", "php",
                    "plugins/gallery_pulse/tests/zip_harness.php", json.dumps(calls), token],
                   capture_output=True, text=True, check=True)


def zips(p, link, token):
    """Batch Downloader: one download per photo in each zip as it is built."""
    import re
    top_id = sql("SELECT category_id FROM piwigo_unlisted_albums WHERE random_key = '%s'" % token)[0][0]
    sub_id = sql("SELECT id FROM piwigo_categories WHERE name = 'Second Half'")[0][0]
    top_imgs = sorted(r[0] for r in sql("SELECT image_id FROM piwigo_image_category WHERE category_id = %s" % top_id))
    sub_imgs = sorted(r[0] for r in sql("SELECT image_id FROM piwigo_image_category WHERE category_id = %s" % sub_id))
    conf = sql("SELECT value FROM piwigo_config WHERE param = 'batch_download'")[0][0]
    # Share-link visitors are guests; prod doesn't let guests zip, but the hook must handle it if enabled.
    sql("REPLACE INTO piwigo_config (param, value) VALUES ('batch_download_guest_allowed', 'true')")
    browser = p.chromium.launch()
    try:
        ctx = browser.new_context()
        ctx.add_cookies([{"name": "pulse_sid", "value": "5" * 32, "url": BASE}])
        req = ctx.request

        since = max_id()
        req.get(link)
        req.get(link + "&action=advdown_set")
        rows = zip_rows(since)
        check(sorted(r[2] for r in rows) == top_imgs and all(r[1] == token and r[3] == top_id and r[0] == "5" * 32 for r in rows),
              "zip: album zip under a share link = one row per photo, with the link, album and browser (%s)"
              % [(r[2], r[3]) for r in rows])
        ips = ips_since(since, "detail = 'zip'")
        check(len(ips) == len(top_imgs) and len(set(ips)) == 1 and private_ip(ips[0]),
              "zip: every zip row carries the request IP (%s)" % sorted(set(ips)))
        set_id = sql("SELECT MAX(id) FROM piwigo_download_sets")[0][0]
        since = max_id()
        body = req.get(BASE + "plugins/BatchDownloader/download.php?set_id=%s&zip=1" % set_id).body()
        check(body[:2] == b"PK" and zip_rows(since) == [], "zip: fetching the built zip again adds no rows")

        # A set too big for one zip is built one zip per request, on /download/init_zip (no /v/ URL).
        # Batch Downloader compares filesize (KB) with max_size*1024, so inflate the recorded sizes.
        sql("UPDATE piwigo_config SET value = '%s' WHERE param = 'batch_download'"
            % re.sub(r's:8:"max_size";i:\d+;', 's:8:"max_size";i:1;', conf).replace("'", "''"))
        sql("UPDATE piwigo_images SET filesize = filesize + 100000")
        since = max_id()
        req.get(link)
        req.get(link + "&action=advdown_set")  # redirects to init_zip, which readies the set
        set_id = sql("SELECT MAX(id) FROM piwigo_download_sets")[0][0]
        check(zip_rows(since) == [], "zip: preparing a multi-zip set logs nothing yet")
        req.get(BASE + "index.php?/download/init_zip&set_id=%s&zip=1" % set_id)
        first = zip_rows(since)
        req.get(BASE + "index.php?/download/init_zip&set_id=%s&zip=2" % set_id)
        both = zip_rows(since)
        second = both[len(first):]
        req.get(BASE + "index.php?/download/init_zip&set_id=%s&zip=1" % set_id)
        check(len(first) >= 1 and len(second) >= 1 and not {r[2] for r in first} & {r[2] for r in second},
              "zip: each zip of a multi-zip set logs only its own photos (%s / %s)" % ([r[2] for r in first], [r[2] for r in second]))
        check(all(r[1] == token for r in both), "zip: later zips keep the share link via its session grant")
        check(len(zip_rows(since)) == len(both), "zip: revisiting a built zip of the set adds no rows")
        sql("UPDATE piwigo_config SET value = '%s' WHERE param = 'batch_download'" % conf.replace("'", "''"))
        sql("UPDATE piwigo_images SET filesize = filesize - 100000 WHERE filesize > 100000")
        ctx.close()

        # A logged-in, non-admin user with access to the album (no share link).
        admin = browser.new_context()
        admin.request.post(BASE + "ws.php?format=json", form={"method": "pwg.session.login", "username": "admin", "password": "adminpass"})
        tok = admin.request.post(BASE + "ws.php?format=json", form={"method": "pwg.session.getStatus"}).json()["result"]["pwg_token"]
        made = admin.request.post(BASE + "ws.php?format=json", form={
            "method": "pwg.users.add", "username": "gp_zipper", "password": "zipperpass", "pwg_token": tok}).json()
        uid = made["result"]["users"][0]["id"]
        members = str(admin.request.post(BASE + "ws.php?format=json", form={
            "method": "pwg.categories.add", "name": "Members", "status": "private"}).json()["result"]["id"])
        member_imgs = top_imgs[:2]
        for img in member_imgs:
            sql("INSERT INTO piwigo_image_category (image_id, category_id) VALUES (%s, %s)" % (img, members))
        sql("UPDATE piwigo_user_cache SET need_update = 'true'")
        admin.request.post(BASE + "ws.php?format=json", form={
            "method": "pwg.permissions.add", "cat_id": members, "user_id": uid, "pwg_token": tok})
        user = browser.new_context()
        user.request.post(BASE + "ws.php?format=json", form={"method": "pwg.session.login", "username": "gp_zipper", "password": "zipperpass"})
        since = max_id()
        user.request.get(BASE + "index.php?/category/%s&action=advdown_set" % members)
        rows = zip_rows(since)
        check(sorted(r[2] for r in rows) == member_imgs and all(r[1] == "NULL" and r[3] == members for r in rows),
              "zip: a logged-in user's album zip is logged, without a share link (%s)" % [(r[1], r[3]) for r in rows])
        check(sql("SELECT DISTINCT user_id FROM piwigo_pulse_event WHERE id > %d" % since) == [[str(uid)]],
              "zip: rows carry the user")

        since = max_id()
        admin.request.get(BASE + "index.php?/category/%s&action=advdown_set" % members)
        made_set = sql("SELECT user_id FROM piwigo_download_sets ORDER BY id DESC LIMIT 1")[0][0]
        check(made_set == "1" and zip_rows(since) == [], "zip: an admin's zip logs nothing")
        admin.request.post(BASE + "ws.php?format=json", form={"method": "pwg.users.delete", "user_id": uid, "pwg_token": tok})
        admin.request.post(BASE + "ws.php?format=json", form={
            "method": "pwg.categories.delete", "category_id": members, "photo_deletion_mode": "no_delete", "pwg_token": tok})
    finally:
        browser.close()
        sql("UPDATE piwigo_images SET filesize = filesize - 100000 WHERE filesize > 100000")
        sql("UPDATE piwigo_config SET value = '%s' WHERE param = 'batch_download'" % conf.replace("'", "''"))
        sql("DELETE FROM piwigo_config WHERE param = 'batch_download_guest_allowed'")

    # Album attribution, through the trigger itself: a photo filed in both albums.
    both_img = sub_imgs[0]
    only_sub = sub_imgs[-1]
    sql("INSERT INTO piwigo_image_category (image_id, category_id) VALUES (%s, %s)" % (both_img, top_id))
    try:
        since = max_id()
        harness([[{"type": "category", "type_id": int(sub_id)}, [int(both_img)]],
                 [{"type": "tags", "type_id": int(sub_id)}, [int(both_img)]],
                 [{"type": "category", "type_id": int(top_id)}, [int(only_sub)]]], token)
        got = [(r[2], r[3]) for r in zip_rows(since)]
        check(got == [(both_img, sub_id), (both_img, top_id), (only_sub, sub_id)],
              "zip: album zips credit their album when the photo is in it; other sets and other photos use the filed album (%s)" % got)
        check(all(r[1] == token for r in zip_rows(since)), "zip: zips of the link's albums carry the link")
        check(set(ips_since(since, "detail = 'zip'")) == {"127.0.0.1"}, "zip: trigger rows carry REMOTE_ADDR")

        # An album outside the link, zipped while the session still holds the link.
        outside = int(sql("SELECT MAX(id) FROM piwigo_categories")[0][0]) + 1000
        sql("INSERT INTO piwigo_image_category (image_id, category_id) VALUES (%s, %d)" % (only_sub, outside))
        since = max_id()
        harness([[{"type": "category", "type_id": outside}, [int(only_sub)]]], token)
        got = [(r[1], r[2], r[3]) for r in zip_rows(since)]
        sql("DELETE FROM piwigo_image_category WHERE category_id = %d" % outside)
        check(got == [("NULL", only_sub, str(outside))],
              "zip: a zip of an album outside the link doesn't carry the link (%s)" % got)
        sql("DELETE FROM piwigo_pulse_event WHERE id > %d" % since)
    finally:
        sql("DELETE FROM piwigo_image_category WHERE image_id = %s AND category_id = %s" % (both_img, top_id))
        sql("UPDATE piwigo_user_cache SET need_update = 'true'")


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
    ips = ips_since(since, "event = 'download'")
    check(len(ips) == 1 and private_ip(ips[0]), "download: row carries the request IP (%s)" % ips)
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


ALBUM_CELLS = """ids => ids.map(id => {
  const td = document.querySelector('#gp-live tr[data-id="' + id + '"] td.gp-album');
  const a = td && td.querySelector('a');
  return td ? [td.textContent.trim(), a ? a.href : null] : null;
})"""


def live_album(ctx, sid, token, sub_id, sub_img):
    """Live's Album column: the viewed album's full path, the same whether polled or server-rendered."""
    page = ctx.new_page()
    page.goto(ADMIN + "-live")
    check("Album" in page.inner_text("#gp-live thead") and "Link" not in page.inner_text("#gp-live thead"),
          "live album: Link column replaced by Album")
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, share_token, image_id, category_id, event, detail) VALUES "
        "(NOW(), '%s', '%s', NULL, %s, 'page_view', 'album'), (NOW(), '%s', '%s', %s, %s, 'lightbox_view', NULL), "
        "(NOW(), '%s', '%s', %s, NULL, 'fullscreen', NULL)"
        % (sid, token, sub_id, sid, token, sub_img, sub_id, sid, token, sub_img))
    ids = [r[0] for r in sql("SELECT id FROM piwigo_pulse_event ORDER BY id DESC LIMIT 3")][::-1]
    for i in ids:
        page.wait_for_selector("#gp-live tr[data-id='%s']" % i, timeout=5000)
    polled = page.evaluate(ALBUM_CELLS, ids)
    page.reload()
    rendered = page.evaluate(ALBUM_CELLS, ids)
    want = "Test Match / Second Half"
    check(polled[0][0] == want and polled[1][0] == want and ("cat=%s" % sub_id) in (polled[0][1] or ""),
          "live album: sub-album page view and lightbox show its full path, linked to Photos (%s)" % polled[:2])
    check(polled[2] == ["", None], "live album: no album = empty cell, no link (%s)" % polled[2])
    check(polled == rendered, "live album: polled rows match server-rendered rows (%s vs %s)" % (polled, rendered))
    page.close()


# Per row: Event cell text, the address as shown (location excluded), the
# location line, and how many break points the address has.
IP_CELLS = """([sel, ev]) => [...document.querySelectorAll(sel)].map(tr => {
  const ip = tr.querySelector('td.gp-ip');
  const loc = ip.querySelector('.gp-detail');
  return {row: tr.textContent, event: tr.cells[ev].textContent.trim(),
          ip: [...ip.childNodes].filter(n => n !== loc).map(n => n.textContent).join(''),
          loc: loc ? loc.textContent : null, wbr: ip.querySelectorAll('wbr').length};
})"""

IP_WANT = [("dwell 1.2s", "81.2.69.160", "London, GB", 0), ("zoom 2.4×", "2001:218::1", "JP", 3),
           ("fullscreen", "172.18.0.1", None, 0), ("fullscreen", "", None, 0)]


def ip_rows(sid, token, img, cat, delta=0):
    """Rows exercising the Event value suffix and the IP / location cell, oldest first."""
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, share_token, image_id, category_id, event, value, ip) VALUES "
        "(NOW() + INTERVAL %d SECOND, '%s', '%s', %s, %s, 'dwell', 1.2, '81.2.69.160'), "
        "(NOW() + INTERVAL %d SECOND, '%s', '%s', %s, %s, 'zoom', 2.4, '2001:218::1'), "
        "(NOW() + INTERVAL %d SECOND, '%s', '%s', %s, %s, 'fullscreen', NULL, '172.18.0.1'), "
        "(NOW() + INTERVAL %d SECOND, '%s', '%s', %s, %s, 'fullscreen', NULL, NULL)"
        % tuple(x for i in range(4) for x in (delta + i, sid, token, img, cat)))
    return [r[0] for r in sql("SELECT id FROM piwigo_pulse_event ORDER BY id DESC LIMIT 4")][::-1]


def ip_check(label, cells):
    got = [(c["event"], c["ip"], c["loc"], c["wbr"]) for c in cells]
    check(got == IP_WANT, "%s: value inline in Event, IP with location beneath (%s)" % (label, got))


def table_head(page, table):
    return [t.strip() for t in page.eval_on_selector_all(table + " thead th", "ths => ths.map(t => t.textContent)")]


def live_ip(ctx, sid, token, img, cat):
    """Live: Value column gone, IP column with location; polled rows match server-rendered ones."""
    page = ctx.new_page()
    page.goto(ADMIN + "-live")
    head = table_head(page, "#gp-live")
    check("Value" not in head and "IP" in head, "live ip: Value column replaced by IP (%s)" % head)
    ids = ip_rows(sid, token, img, cat, 100)
    for i in ids:
        page.wait_for_selector("#gp-live tr[data-id='%s']" % i, timeout=5000)
    sel = ", ".join("#gp-live tr[data-id='%s']" % i for i in ids)
    # Newest first on screen.
    polled = page.evaluate(IP_CELLS, [sel, head.index("Event")])[::-1]
    ip_check("live ip (polled)", polled)
    page.reload()
    rendered = page.evaluate(IP_CELLS, [sel, head.index("Event")])[::-1]
    strip = lambda cells: [{k: v for k, v in c.items() if k != "row"} for c in cells]
    check(strip(polled) == strip(rendered), "live ip: polled rows match server-rendered rows (%s vs %s)"
          % (strip(polled), strip(rendered)))
    page.close()
    sql("DELETE FROM piwigo_pulse_event WHERE id >= %s" % ids[0])


def ip_tables(page, token, img, cat):
    """Visitor timeline and photo detail: same Value -> IP swap, same cells as Live."""
    sid = "7" * 32
    ip_rows(sid, token, img, cat)
    try:
        for label, url, table, newest_first in (
                ("timeline", ADMIN + "-visitors&sid=" + sid, ".gp-wrap > table:last-of-type", False),
                ("photo detail", ADMIN + "-photo&image_id=%s" % img, ".gp-wrap > table:last-of-type", True)):
            html, clean = admin_page(page, url)
            head = table_head(page, table)
            check(clean and "Value" not in head and "IP" in head, "%s: Value column replaced by IP (%s)" % (label, head))
            cells = page.evaluate(IP_CELLS, [table + " tbody tr", head.index("Event")])
            if newest_first:  # the detail lists every guest; the timeline only this one
                cells = [c for c in cells if sid[:8] in c["row"]]
            ip_check(label, cells[::-1] if newest_first else cells)
    finally:
        sql("DELETE FROM piwigo_pulse_event WHERE sid = '%s'" % sid)


def geoip_setting(page, token, img, cat):
    """A missing GeoIP file: pages render, no location, Settings says why; blank turns locations off."""
    sid = "7" * 32
    ip_rows(sid, token, img, cat)
    conf = sql("SELECT value FROM piwigo_config WHERE param = 'gallery_pulse_geoip_path'")[0][0]
    try:
        admin_page(page, ADMIN + "-settings")
        check(page.input_value("input[name=geoip_path]") == conf and "gp-warning" not in page.content(),
              "geoip: working database path shown, no note")
        for bad in ("relative.mmdb", "/tmp/it's.mmdb"):
            page.fill("input[name=geoip_path]", bad)
            save_settings(page)
            check("must be an absolute path" in page.content(), "geoip: path %r rejected" % bad)
        for path, note in (("/nonexistent/GeoLite2-City.mmdb", "can't be read"), ("/etc/hostname", "isn't a MaxMind database"), ("", None)):
            admin_page(page, ADMIN + "-settings")
            page.fill("input[name=geoip_path]", path)
            save_settings(page)
            html = page.content()
            check(sql("SELECT CONCAT('[', value, ']') FROM piwigo_config WHERE param = 'gallery_pulse_geoip_path'") == [["[%s]" % path]]
                  and page.input_value("input[name=geoip_path]") == path, "geoip: path %r saved" % path)
            check((note in html.replace("&#39;", "'")) if note else ("gp-warning" not in html), "geoip: Settings note for %r" % path)
            html, clean = admin_page(page, ADMIN + "-visitors&sid=" + sid)
            locs = page.eval_on_selector_all("td.gp-ip .gp-detail", "ds => ds.map(d => d.textContent)")
            check(clean and "81.2.69.160" in html and locs == [],
                  "geoip: %r -> page renders, addresses without location (%s)" % (path, locs))
    finally:
        sql("REPLACE INTO piwigo_config (param, value) VALUES ('gallery_pulse_geoip_path', '%s')" % conf)
        sql("DELETE FROM piwigo_pulse_event WHERE sid = '%s'" % sid)


def upgrade():
    """Activating 1.5 over a 1.4 table adds the ip column; old rows stay intact, with no IP."""
    snap = ("SELECT COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS('|', id, occurred_at, sid, event, IFNULL(value,''), "
            "IFNULL(detail,''), IFNULL(device,'')) ORDER BY id)) FROM piwigo_pulse_event")
    sql("ALTER TABLE piwigo_pulse_event DROP COLUMN ip")
    sql("UPDATE piwigo_plugins SET version = '1.4.0' WHERE id = 'gallery_pulse'")
    before = sql(snap)
    urllib.request.urlopen(BASE + "index.php").read()
    cols = [r[0] for r in sql("SHOW COLUMNS FROM piwigo_pulse_event")]
    check(sql("SELECT version FROM piwigo_plugins WHERE id = 'gallery_pulse'") == [["1.5.0"]], "upgrade: plugin updated to 1.5.0")
    check(cols[-2:] == ["device", "ip"], "upgrade: ip column added after device (%s)" % cols)
    check(sql(snap) == before and sql("SELECT COUNT(*) FROM piwigo_pulse_event WHERE ip IS NOT NULL") == [["0"]],
          "upgrade: old rows intact, IP blank (%s)" % before)


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
    page.wait_for_selector("#gp-live tr[data-id='%s']" % new_id, timeout=9000)
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


def save_settings(page):
    # Wait for the response page: reading content straight after the click can
    # catch the old page.
    with page.expect_navigation():
        page.click("input[name=save]")


def time_zone(page):
    """Times shown in the configured zone; the Live poll still sorts on raw UTC."""
    admin_page(page, ADMIN + "-settings")
    check(page.input_value("select[name=timezone]") == "America/New_York", "tz: defaults to America/New_York")
    page.select_option("select[name=timezone]", "Asia/Tokyo")
    save_settings(page)
    check(sql("SELECT value FROM piwigo_config WHERE param = 'gallery_pulse_timezone'") == [["Asia/Tokyo"]], "tz: setting saved")
    page.evaluate("""() => { const o = document.createElement('option'); o.value = 'Mars/Olympus'; o.selected = true;
                             document.querySelector('select[name=timezone]').appendChild(o); }""")
    save_settings(page)
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


VISITOR_ROWS = "trs => trs.map(t => [...t.cells].map(c => c.innerText.trim()))"


def visitor_row(page, label):
    rows = page.eval_on_selector_all("#gp-visitors tbody tr", VISITOR_ROWS)
    return next((r for r in rows if r[0] == label), None)


def filters(ctx, token, top_id, sub_id, top_img, sub_img):
    """1.6: per-tab sticky filters (period/range, album, events, guest), Visitors last IP and recent albums, sorting."""
    since = max_id()
    sid = "9" * 32
    extra = [901, 902, 903]
    for i, cid in enumerate(extra):
        sql("INSERT INTO piwigo_categories (id, name, uppercats, global_rank, status) VALUES (%d, 'GP Extra %d', '%d', '%d', 'public')"
            % (cid, i + 1, cid, cid))
    # Most events in the sub-album, but the top album was viewed last; then three
    # older extra albums. The newest event has no IP, so "last seen" is the one before.
    rows = ["(NOW() - INTERVAL %d MINUTE, '%s', %s, %s, 'lightbox_view', '2001:218::1')" % (40 + n, sid, sub_img, sub_id) for n in range(5)]
    rows += ["(NOW() - INTERVAL %d MINUTE, '%s', %s, %d, 'lightbox_view', '172.18.0.1')" % (60 + i, sid, top_img, cid) for i, cid in enumerate(extra)]
    rows.append("(NOW() - INTERVAL 10 MINUTE, '%s', %s, %s, 'zoom', '81.2.69.160')" % (sid, top_img, top_id))
    rows.append("(NOW() - INTERVAL 5 MINUTE, '%s', NULL, NULL, 'page_view', NULL)" % sid)
    # 23:30 New York on 14 Jan = 04:30 UTC on the 15th: a date range is in display time.
    rows.append("('2026-01-15 04:30:00', '%s', %s, %s, 'fullscreen', NULL)" % (sid, top_img, top_id))
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, image_id, category_id, event, ip) VALUES " + ", ".join(rows))
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, user_id, image_id, category_id, event) VALUES "
        "(NOW() - INTERVAL 3 MINUTE, '%s', 1, %s, %s, 'save_intent')" % ("8" * 32, top_img, top_id))

    page = ctx.new_page()
    for tab in ("live", "photos", "visitors", "albums"):
        admin_page(page, ADMIN + "-" + tab + "&reset=1")

    # Visitors: latest album first, three inline, the rest behind "+N more"; last non-blank IP.
    _, clean = admin_page(page, ADMIN + "-visitors&days=1")
    r = visitor_row(page, sid[:8])
    albums = r[1].split("\n") if r else []
    check(clean and albums[:2] == ["Test Match", "Test Match / Second Half"] and albums[2] == "GP Extra 1" and albums[3] == "+2 more",
          "visitors: albums most recently viewed first, three shown, then +N more (%s)" % albums)
    more = page.locator("#gp-visitors td.gp-albums .gp-detail").first.get_attribute("title")
    check(more == "GP Extra 2\nGP Extra 3", "visitors: +N more lists the rest on hover (%r)" % more)
    check(r and r[2] == "81.2.69.160\nLondon, GB", "visitors: IP column = last IP seen, with location (%s)" % (r[2] if r else None))

    # Guest filter by browser id prefix; it sticks on Visitors only.
    admin_page(page, ADMIN + "-visitors&f=1&days=1&guest=" + sid[:8])
    names = [x[0] for x in page.eval_on_selector_all("#gp-visitors tbody tr", VISITOR_ROWS)]
    check(names == [sid[:8]], "guest filter: id prefix shows only that visitor (%s)" % names)
    admin_page(page, ADMIN + "-photos")
    check(page.input_value("input[name=guest]") == "", "sticky: another tab keeps its own filter")
    admin_page(page, ADMIN + "-visitors")
    names = [x[0] for x in page.eval_on_selector_all("#gp-visitors tbody tr", VISITOR_ROWS)]
    check(page.input_value("input[name=guest]") == sid[:8] and names == [sid[:8]] and page.input_value("select[name=days]") == "1",
          "sticky: a bare tab link brings the tab's filter back (%s)" % names)
    page.click("a.gp-reset")
    page.wait_for_load_state()
    names = [x[0] for x in page.eval_on_selector_all("#gp-visitors tbody tr", VISITOR_ROWS)]
    check(page.input_value("input[name=guest]") == "" and len(names) > 1 and page.input_value("select[name=days]") == "30",
          "sticky: Reset filters goes back to the defaults (%d rows)" % len(names))

    # Guest by username; an unknown name matches nobody.
    admin_page(page, ADMIN + "-visitors&f=1&days=1&guest=admin")
    names = [x[0] for x in page.eval_on_selector_all("#gp-visitors tbody tr", VISITOR_ROWS)]
    check(set(names) == {"admin"}, "guest filter: username (%s)" % names)
    _, clean = admin_page(page, ADMIN + "-visitors&f=1&days=1&guest=nobody%27s")
    check(clean and page.locator("#gp-visitors").count() == 0 and page.input_value("input[name=guest]") == "nobody's",
          "guest filter: unknown guest = no rows, quote kept intact")

    # Date range, in display time.
    admin_page(page, ADMIN + "-visitors&f=1&days=custom&from=2026-01-14&to=2026-01-14&guest=" + sid[:8])
    r = visitor_row(page, sid[:8])
    check(r is not None and page.is_visible("input[name=from]") and page.input_value("select[name=days]") == "custom",
          "date range: 23:30 New York is on the 14th")
    admin_page(page, ADMIN + "-visitors&f=1&days=custom&from=2026-01-15&to=2026-01-15&guest=" + sid[:8])
    check(visitor_row(page, sid[:8]) is None, "date range: ...and not on the 15th (its UTC date)")
    _, clean = admin_page(page, ADMIN + "-visitors&f=1&days=custom&from=2026-02-30")
    check(clean and page.input_value("select[name=days]") == "30", "date range: an impossible date falls back to the default period")
    admin_page(page, ADMIN + "-visitors&reset=1")

    # Event filter (multi) on Live, including what the poll brings in.
    admin_page(page, ADMIN + "-live&f=1&ev[]=zoom&ev[]=save_intent")
    events = set(page.eval_on_selector_all("#gp-live tbody tr", "trs => trs.map(t => t.cells[3].innerText.split(' ')[0])"))
    check(events == {"zoom", "save_intent"}, "event filter: Live shows only the ticked events (%s)" % sorted(events))
    check("zoom, save_intent" in page.inner_text("details.gp-multi summary"), "event filter: summary names the ticked events")
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, image_id, category_id, event) VALUES "
        "(NOW(), '%s', %s, %s, 'fullscreen')" % (sid, top_img, top_id))
    skip_id = sql("SELECT MAX(id) FROM piwigo_pulse_event")[0][0]
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, image_id, category_id, event, value) VALUES "
        "(NOW(), '%s', %s, %s, 'zoom', 2)" % (sid, top_img, top_id))
    zoom_id = sql("SELECT MAX(id) FROM piwigo_pulse_event")[0][0]
    page.wait_for_selector("#gp-live tr[data-id='%s']" % zoom_id, timeout=9000)
    check(page.locator("#gp-live tr[data-id='%s']" % skip_id).count() == 0, "event filter: the poll leaves out other events")
    admin_page(page, ADMIN + "-live")
    ticked = page.eval_on_selector_all("input[name='ev[]']:checked", "es => es.map(e => e.value)")
    check(ticked == ["zoom", "save_intent"], "sticky: Live keeps its event filter (%s)" % ticked)
    admin_page(page, ADMIN + "-live&reset=1")

    # Album filter on Live and Albums (parent includes sub-albums).
    admin_page(page, ADMIN + "-albums&f=1&days=1&cat=%s" % top_id)
    names = page.eval_on_selector_all("#gp-albums tbody tr", "trs => trs.map(t => t.cells[0].innerText)")
    check(set(names) == {"Test Match", "Test Match / Second Half"}, "album filter: Albums tab, sub-albums included (%s)" % names)
    admin_page(page, ADMIN + "-albums&f=1&days=1&guest=" + sid[:8])
    href = page.locator("#gp-albums a[href*='-photos']").first.get_attribute("href")
    check("guest=" + sid[:8] in href and "days=1" in href, "albums: Photos link carries the rest of the filter (%s)" % href)
    admin_page(page, ADMIN + "-albums&reset=1")
    admin_page(page, ADMIN + "-live&f=1&cat=%s" % sub_id)
    albums = set(page.eval_on_selector_all("#gp-live tbody tr", "trs => trs.map(t => t.cells[2].innerText)"))
    check(albums == {"Test Match / Second Half"}, "album filter: Live (%s)" % albums)
    admin_page(page, ADMIN + "-live&reset=1")

    # Client-side sort: numbers start biggest-first, a second click reverses, the sort is remembered.
    admin_page(page, ADMIN + "-visitors&f=1&days=1")
    col = table_head(page, "#gp-visitors").index("Photos")
    page.locator("#gp-visitors thead th").nth(col).click()
    vals = [int(x[col]) for x in page.eval_on_selector_all("#gp-visitors tbody tr", VISITOR_ROWS)]
    check(len(vals) > 1 and vals == sorted(vals, reverse=True), "sort: first click on a number column sorts biggest first (%s)" % vals)
    page.locator("#gp-visitors thead th").nth(col).click()
    vals = [int(x[col]) for x in page.eval_on_selector_all("#gp-visitors tbody tr", VISITOR_ROWS)]
    check(vals == sorted(vals), "sort: second click reverses (%s)" % vals)
    admin_page(page, ADMIN + "-visitors")
    vals = [int(x[col]) for x in page.eval_on_selector_all("#gp-visitors tbody tr", VISITOR_ROWS)]
    cls = page.locator("#gp-visitors thead th").nth(col).get_attribute("class") or ""
    check(vals == sorted(vals) and "gp-sorted-asc" in cls, "sort: remembered after reload (%s)" % vals)
    page.evaluate("localStorage.clear()")
    admin_page(page, ADMIN + "-visitors&reset=1")

    # Live stays sorted as polled rows arrive.
    admin_page(page, ADMIN + "-live&f=1&days=1")
    page.locator("#gp-live thead th").nth(3).click()
    sql("INSERT INTO piwigo_pulse_event (occurred_at, sid, image_id, category_id, event) VALUES "
        "(NOW(), '%s', %s, %s, 'download')" % (sid, top_img, top_id))
    new_id = sql("SELECT MAX(id) FROM piwigo_pulse_event")[0][0]
    page.wait_for_selector("#gp-live tr[data-id='%s']" % new_id, timeout=9000)
    events = page.eval_on_selector_all("#gp-live tbody tr", "trs => trs.map(t => t.cells[3].innerText.split(' ')[0])")
    # Sorted by Event: each event name forms one block, the new download included.
    blocks = [e for i, e in enumerate(events) if i == 0 or events[i - 1] != e]
    check(len(blocks) == len(set(events)) > 1 and "download" in events,
          "sort: Live re-sorts when the poll adds rows (%s)" % blocks)
    page.evaluate("localStorage.clear()")
    admin_page(page, ADMIN + "-live&reset=1")

    # Photos sorts on the server: by name, either way, and the sort sticks.
    admin_page(page, ADMIN + "-photos&f=1&days=1&sort=name&dir=asc")
    names = page.eval_on_selector_all("#gp-photos tbody td.gp-photo", "tds => tds.map(t => t.innerText.trim())")
    th = page.locator("#gp-photos thead th").first
    check(len(names) > 1 and names == sorted(names) and "gp-sorted-asc" in (th.get_attribute("class") or "")
          and "dir=desc" in th.locator("a").get_attribute("href"), "photos sort: by name A-Z, heading links to Z-A (%s)" % names)
    admin_page(page, ADMIN + "-photos&f=1&days=1&sort=dwell_avg&dir=asc")
    _, clean = admin_page(page, ADMIN + "-photos")
    check(clean and page.input_value("input[name=sort]") == "dwell_avg" and page.input_value("input[name=dir]") == "asc",
          "photos sort: sticks with the filter")
    admin_page(page, ADMIN + "-photos&reset=1")

    # The Live poll validates its filter too.
    data = page.evaluate("fetch('ws.php?format=json&method=galleryPulse.live&since_id=%d&filter=' + "
                         "encodeURIComponent('{\"ev\":[\"zoom\",\"x\\'1\"],\"guest\":\"%s\",\"days\":\"9999\"}')).then(r => r.json())"
                         % (since, sid[:8]))
    evs = {e["event"] for e in data["result"]["events"]} if data.get("stat") == "ok" else None
    check(evs == {"zoom"}, "live poll: filter applied, unknown event names dropped (%s)" % evs)
    page.close()

    sql("DELETE FROM piwigo_pulse_event WHERE id > %d" % since)
    sql("DELETE FROM piwigo_categories WHERE id IN (%s)" % ",".join(map(str, extra)))


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
    # Filters stick per tab across runs; start clean.
    for tab in ("live", "photos", "visitors", "albums"):
        admin_page(page, ADMIN + "-" + tab + "&reset=1")

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
    live_album(ctx, sid, token, sub_id, sub_img)
    live_ip(ctx, sid, token, top_img, top_id)
    ip_tables(page, token, top_img, top_id)
    geoip_setting(page, token, top_img, top_id)

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
    filters(ctx, token, top_id, sub_id, top_img, sub_img)

    # Retention: default 730, validated, and the prune honours it.
    admin_page(page, ADMIN + "-settings")
    check(page.input_value("input[name=retention_days]") == "730", "settings: retention defaults to 730 days")
    page.fill("input[name=retention_days]", "5")
    page.evaluate("document.querySelector('input[name=retention_days]').removeAttribute('min')")
    save_settings(page)
    check("between 30 and 3650" in page.content(), "settings: out-of-range retention rejected")
    page.fill("input[name=retention_days]", "400")
    save_settings(page)
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
    upgrade()
    with sync_playwright() as p:
        a = desktop(p, link)
        b = iphone(p, link)
        check(a and b and a != b, "two devices = two guests under one link")
        autoplay(p, link)
        page_context(p, link, token)
        admin(p, token)
        viewed_album(p, link, token)
        zips(p, link, token)
    device_os(token)
    downloads(link)
    no_js(link)
    bad_input(link)
    print("\n%d failure(s)" % len(failures))
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()

/*
 * Gallery Pulse beacon.
 *
 * Hooks PhotoSwipe v4 (bundled with Bootstrap Darkroom) by wrapping the global
 * constructor, so it needs no theme edits. If PhotoSwipe is missing or renamed
 * the wrap is a no-op and only page views and save intent are recorded.
 */
(function (window, document) {
  'use strict';

  var FLUSH_MS = 10000;
  var ZOOM_SAMPLE_MS = 300;
  var LONG_PRESS_MS = 600;
  var COOKIE_DAYS = 30;

  var config = null;
  var queue = [];
  var sid = null;
  var token = (function () {
    var m = /[?\/]v\/([A-Za-z0-9]{8,64})/.exec(window.location.search + window.location.pathname);
    return m ? m[1] : null;
  })();
  // UnlistedAlbum's hashed sub-album token; the server maps it back to an album.
  var catToken = (function () {
    var m = /\/(c-[a-f0-9]{8})(?:\/|$)/.exec(window.location.search + window.location.pathname);
    return m ? m[1] : null;
  })();

  function now() { return Date.now(); }

  function push(e, img, extra) {
    var ev = { e: e, img: img === undefined ? null : img, t: now() };
    if (extra) {
      for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { ev[k] = extra[k]; } }
    }
    queue.push(ev);
  }

  function readSid() {
    var m = /(?:^|;\s*)pulse_sid=([a-f0-9]{32})(?:;|$)/.exec(document.cookie);
    if (m) { return m[1]; }
    var bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    var hex = '';
    for (var i = 0; i < bytes.length; i++) { hex += ('0' + bytes[i].toString(16)).slice(-2); }
    return hex;
  }

  function writeSid(value) {
    // Rewritten on every page so the 30 days run from the last visit.
    var expires = new Date(now() + COOKIE_DAYS * 864e5).toUTCString();
    document.cookie = 'pulse_sid=' + value + '; expires=' + expires + '; path=' + config.cookiePath +
      '; SameSite=Lax' + (config.secure ? '; Secure' : '');
  }

  function flush() {
    if (!config || !queue.length) { return; }
    var batch = queue.splice(0, 50);
    var t = now();
    var events = batch.map(function (ev) {
      var out = { e: ev.e, img: ev.img, ago: t - ev.t };
      if (ev.v !== undefined) { out.v = ev.v; }
      if (ev.d !== undefined) { out.d = ev.d; }
      if (ev.cat !== undefined) { out.cat = ev.cat; }
      if (ev.ctok !== undefined) { out.ctok = ev.ctok; }
      return out;
    });
    var body = new URLSearchParams();
    body.set('method', 'galleryPulse.track');
    body.set('sid', sid);
    if (token) { body.set('token', token); }
    body.set('events', JSON.stringify(events));

    var sent = false;
    if (navigator.sendBeacon) {
      try { sent = navigator.sendBeacon(config.ws, body); } catch (err) { sent = false; }
    }
    if (!sent && window.fetch) {
      fetch(config.ws, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
    }
    if (queue.length) { flush(); }
  }

  /* ---- Lightbox ---- */

  var current = null; // slide being looked at: { item, img, autoplay, shownAt, hiddenFor, hiddenAt, maxZoom }
  var lightboxOpen = false;

  function zoomRatio(pswp) {
    var item = pswp.currItem;
    if (!item || !item.initialZoomLevel || typeof pswp.getZoomLevel !== 'function') { return 1; }
    return pswp.getZoomLevel() / item.initialZoomLevel;
  }

  // The theme's slideshow button carries .stop while autoplay runs.
  function autoplaying() {
    return !!document.querySelector('.pswp__button--autoplay.stop');
  }

  function startSlide(pswp) {
    var item = pswp.currItem;
    var img = item && item.imageid ? parseInt(item.imageid, 10) : null;
    if (!img) { current = null; return; }
    var auto = autoplaying();
    current = { item: item, img: img, autoplay: auto, shownAt: now(), hiddenFor: 0, hiddenAt: document.hidden ? now() : null, maxZoom: 1 };
    push('lightbox_view', img, auto ? { d: 'autoplay' } : null);
  }

  function endSlide() {
    if (!current) { return; }
    var end = now();
    var hidden = current.hiddenFor + (current.hiddenAt ? end - current.hiddenAt : 0);
    var seconds = Math.max(0, (end - current.shownAt - hidden) / 1000);
    if (!current.autoplay) {
      push('dwell', current.img, { v: Math.round(seconds * 10) / 10 });
    }
    if (current.maxZoom > 1.05) {
      push('zoom', current.img, { v: Math.round(current.maxZoom * 100) / 100 });
    }
    current = null;
  }

  function sampleZoom(pswp) {
    // PhotoSwipe switches currItem before beforeChange while getZoomLevel()
    // still reports the old slide; only sample while they agree.
    if (!current || pswp.currItem !== current.item) { return; }
    var r = zoomRatio(pswp);
    if (r > current.maxZoom) { current.maxZoom = r; }
  }

  function attach(pswp) {
    if (!pswp || typeof pswp.listen !== 'function') { return; }
    var timer = null;

    pswp.listen('afterChange', function () {
      // afterChange fires on open and on every swipe; close the previous slide first.
      endSlide();
      startSlide(pswp);
    });
    pswp.listen('initialZoomInEnd', function () {
      lightboxOpen = true;
      if (!timer) { timer = setInterval(function () { sampleZoom(pswp); }, ZOOM_SAMPLE_MS); }
    });
    pswp.listen('zoomGestureEnded', function () { sampleZoom(pswp); });
    pswp.listen('close', function () {
      sampleZoom(pswp);
      endSlide();
      lightboxOpen = false;
      if (timer) { clearInterval(timer); timer = null; }
      flush();
    });
    pswp.listen('destroy', function () {
      if (timer) { clearInterval(timer); timer = null; }
    });
    attach.last = pswp;
  }

  function wrap(Original) {
    if (typeof Original !== 'function' || Original.__galleryPulse) { return Original; }
    var Wrapped = function (template, UiClass, items, options) {
      var instance = new Original(template, UiClass, items, options);
      try { attach(instance); } catch (err) { /* never break the gallery */ }
      return instance;
    };
    Wrapped.prototype = Original.prototype;
    Wrapped.__galleryPulse = true;
    return Wrapped;
  }

  function hookPhotoSwipe() {
    if (window.PhotoSwipe) {
      window.PhotoSwipe = wrap(window.PhotoSwipe);
      return;
    }
    // Loaded before photoswipe.min.js: wrap the constructor when it gets assigned.
    var stored;
    try {
      Object.defineProperty(window, 'PhotoSwipe', {
        configurable: true,
        enumerable: true,
        get: function () { return stored; },
        set: function (v) { stored = wrap(v); }
      });
    } catch (err) { /* page-level events still work */ }
  }

  /* ---- Save intent ---- */

  // Only the lightbox image and the photo page's main image count. Grid
  // thumbnails are left out: they carry no image id, and mapping them by
  // position breaks on paginated albums.
  function imageIdFor(el) {
    if (!el || el.tagName !== 'IMG' || !el.closest) { return null; }
    if (el.closest('.pswp')) {
      var pswp = attach.last;
      return lightboxOpen && pswp && pswp.currItem && pswp.currItem.imageid ? parseInt(pswp.currItem.imageid, 10) : null;
    }
    if (el.id === 'theMainImage' && config && config.img) { return config.img; }
    return null;
  }

  function saveIntent(target, kind) {
    var img = imageIdFor(target);
    if (img) { push('save_intent', img, { d: kind }); }
  }

  function bindSaveIntent() {
    document.addEventListener('contextmenu', function (e) { saveIntent(e.target, 'contextmenu'); }, true);
    document.addEventListener('dragstart', function (e) { saveIntent(e.target, 'drag'); }, true);

    var pressTimer = null;
    var cancel = function () { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } };
    document.addEventListener('touchstart', function (e) {
      cancel();
      if (e.touches.length !== 1) { return; }
      var target = e.target;
      pressTimer = setTimeout(function () {
        pressTimer = null;
        // A long press while zoomed in is panning, not saving.
        if (current && current.maxZoom > 1.05 && attach.last && zoomRatio(attach.last) > 1.05) { return; }
        saveIntent(target, 'longpress');
      }, LONG_PRESS_MS);
    }, { capture: true, passive: true });
    document.addEventListener('touchmove', cancel, { capture: true, passive: true });
    document.addEventListener('touchend', cancel, { capture: true, passive: true });
    document.addEventListener('touchcancel', cancel, { capture: true, passive: true });
  }

  /* ---- Page lifecycle ---- */

  function bindLifecycle() {
    document.addEventListener('visibilitychange', function () {
      if (current) {
        if (document.hidden) {
          current.hiddenAt = now();
        } else if (current.hiddenAt) {
          current.hiddenFor += now() - current.hiddenAt;
          current.hiddenAt = null;
        }
      }
      if (document.hidden) { flush(); }
    });
    window.addEventListener('pagehide', function () {
      if (current && attach.last) { sampleZoom(attach.last); }
      endSlide();
      flush();
    });
    var onFullscreen = function () {
      var fs = document.fullscreenElement || document.webkitFullscreenElement;
      if (fs && current) { push('fullscreen', current.img); }
    };
    document.addEventListener('fullscreenchange', onFullscreen);
    document.addEventListener('webkitfullscreenchange', onFullscreen);
    setInterval(flush, FLUSH_MS);
  }

  hookPhotoSwipe();

  window.GalleryPulse = {
    start: function (cfg) {
      if (config) { return; }
      config = cfg;
      sid = readSid();
      writeSid(sid);
      // Lightbox events may already be queued (opened from #pid on load); they
      // flush now that the endpoint is known.
      var view = { d: config.page };
      if (config.cat) { view.cat = config.cat; }
      if (catToken) { view.ctok = catToken; }
      push('page_view', config.page === 'photo' ? config.img : null, view);
      bindSaveIntent();
      bindLifecycle();
    }
  };
})(window, document);

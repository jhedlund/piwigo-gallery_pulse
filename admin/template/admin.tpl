{combine_css path=$GP_PATH|cat:"admin/template/admin.css"}

<div class="titrePage">
  <h2>Gallery Pulse</h2>
</div>

<div class="gp-wrap">

{if $GP_FILTERED || $GP_TAB == 'photo'}
<form method="get" action="admin.php" class="gp-filters" id="gp-filters">
  <input type="hidden" name="page" value="plugin-gallery_pulse-{$GP_TAB}">
  <input type="hidden" name="f" value="1">
  {if $GP_TAB == 'photos'}<input type="hidden" name="sort" value="{$GP_F.sort}"><input type="hidden" name="dir" value="{$GP_F.dir}">{/if}
  {if isset($GP_IMAGE_ID)}<input type="hidden" name="image_id" value="{$GP_IMAGE_ID}">{/if}
  <label>Period
    <select name="days" data-submit>
      {foreach from=$GP_PERIODS key=d item=label}
      <option value="{$d}"{if !$GP_CUSTOM && $d == $GP_F.days} selected{/if}>{$label}</option>
      {/foreach}
      <option value="custom"{if $GP_CUSTOM} selected{/if}>Date range…</option>
    </select>
  </label>
  <span class="gp-range"{if !$GP_CUSTOM} hidden{/if}>
    <label>From <input type="date" name="from" value="{$GP_F.from}"></label>
    <label>To <input type="date" name="to" value="{$GP_F.to}"></label>
  </span>
  {if $GP_FILTERED}
  <label>Album
    <select name="cat" data-submit>
      <option value="">All</option>
      {foreach from=$GP_ALBUMS key=id item=path}
      <option value="{$id}"{if $id == $GP_F.cat} selected{/if}>{$path|escape}</option>
      {/foreach}
    </select>
  </label>
  <details class="gp-multi">
    <summary>Events: {if empty($GP_F.ev)}all{else}{$GP_EV_LABEL|escape}{/if}</summary>
    <div>
      {foreach from=$GP_EVENT_OPTIONS key=name item=on}
      <label><input type="checkbox" name="ev[]" value="{$name}"{if $on} checked{/if}> {$name}</label>
      {/foreach}
      <p class="gp-detail">None ticked = all events.</p>
    </div>
  </details>
  <label>Guest
    <input type="search" name="guest" value="{$GP_F.guest|escape}" list="gp-guests" placeholder="anyone" size="16" title="A visitor's id as shown (4+ characters), a username, or &quot;no beacon&quot;">
  </label>
  <datalist id="gp-guests">
    {foreach from=$GP_GUESTS item=g}<option value="{$g|escape}">{/foreach}
  </datalist>
  {/if}
  {if $GP_FILTERED && $GP_RESETTABLE}<a href="{$GP_BASE}&amp;reset=1" class="gp-reset">Reset filters</a>{/if}
</form>
{* No Apply button: each control applies itself once its value is settled. *}
{footer_script}
(function () {
  var form = document.getElementById('gp-filters');
  var range = form.querySelector('.gp-range');
  function state() { return new URLSearchParams(new FormData(form)).toString(); }
  var initial = state(), pending = null;
  function apply() {
    clearTimeout(pending);
    if (state() !== initial) { form.submit(); }
  }
  form.addEventListener('submit', function (ev) { ev.preventDefault(); apply(); });
  form.querySelectorAll('select[data-submit]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      if (sel.name === 'days') {
        if (sel.value === 'custom') {
          range.hidden = false;
          form.querySelector('input[name=from]').focus();
          return;
        }
        range.hidden = true;
      }
      apply();
    });
  });
  // Events: tick any number, applied when the list closes (or on a click
  // outside it, which closes it).
  var multi = form.querySelector('details.gp-multi');
  if (multi) {
    multi.addEventListener('toggle', function () { if (!multi.open) { apply(); } });
    document.addEventListener('click', function (ev) {
      if (multi.open && !multi.contains(ev.target)) { multi.open = false; }
    });
  }
  // Guest: Enter, leaving the box, or a suggestion picked from the list.
  // Dates: Enter, leaving the range, or both filled in. A typed date fires
  // change on every keystroke that makes a valid date (year 0002, 0020...),
  // so a full range waits for typing to pause.
  form.querySelectorAll('input[type=search], input[type=date]').forEach(function (input) {
    input.addEventListener('keydown', function (ev) {
      clearTimeout(pending);
      if (ev.key === 'Enter') { ev.preventDefault(); apply(); }
    });
  });
  var guest = form.querySelector('input[name=guest]');
  if (guest) {
    guest.addEventListener('blur', apply);
    guest.addEventListener('input', function (ev) {
      if (!ev.inputType || ev.inputType === 'insertReplacementText') { apply(); }
    });
  }
  range.addEventListener('focusout', function (ev) {
    if (!range.contains(ev.relatedTarget)) { apply(); }
  });
  range.querySelectorAll('input').forEach(function (input) {
    input.addEventListener('change', function () {
      clearTimeout(pending);
      var full = Array.prototype.every.call(range.querySelectorAll('input'), function (i) {
        return /^[2-9]\d\d\d-/.test(i.value);
      });
      if (full) { pending = setTimeout(apply, 800); }
    });
  });
})();
{/footer_script}
{/if}

{* Hover preview: one shared popup to the right of the thumbnail. *}
{footer_script}
(function () {
  var pop = document.createElement('div');
  pop.className = 'gp-preview';
  var big = document.createElement('img');
  big.alt = '';
  pop.appendChild(big);
  document.body.appendChild(pop);
  var current = null;

  function place() {
    if (!current) { return; }
    var r = current.getBoundingClientRect();
    var h = pop.offsetHeight, w = pop.offsetWidth;
    var top = Math.max(8, Math.min(r.top + r.height / 2 - h / 2, window.innerHeight - h - 8));
    var left = r.right + 12;
    if (left + w > window.innerWidth - 8) { left = Math.max(8, r.left - w - 12); }
    pop.style.top = top + 'px';
    pop.style.left = left + 'px';
  }

  document.addEventListener('mouseover', function (ev) {
    var t = ev.target;
    if (!t.matches || !t.matches('.gp-photo img[data-preview]') || t === current) { return; }
    current = t;
    big.onload = function () { if (current === t) { pop.classList.add('gp-shown'); place(); } };
    big.src = t.getAttribute('data-preview');
    if (big.complete && big.naturalWidth) { big.onload(); }
  });
  document.addEventListener('mouseout', function (ev) {
    if (ev.target === current) {
      current = null;
      pop.classList.remove('gp-shown');
    }
  });
  window.addEventListener('scroll', place, true);
})();

{* Click a column heading to sort; again to reverse. A cell's data-sort, when
   present, is what it sorts by. Blank cells stay at the bottom. Each table's
   sort is remembered in this browser. *}
(function () {
  function value(tr, col) {
    var td = tr.cells[col];
    if (!td) { return ''; }
    return td.hasAttribute('data-sort') ? td.getAttribute('data-sort') : td.textContent.trim();
  }
  function numeric(v) { return v !== '' && !isNaN(Number(v)); }
  function compare(a, b) {
    if (numeric(a) && numeric(b)) { return Number(a) - Number(b); }
    return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
  }
  function apply(table, col, dir) {
    var tbody = table.tBodies[0];
    var rows = Array.prototype.map.call(tbody.rows, function (tr, i) { return { tr: tr, v: value(tr, col), i: i }; });
    rows.sort(function (x, y) {
      if (x.v === '' || y.v === '') { return x.v === y.v ? x.i - y.i : x.v === '' ? 1 : -1; }
      return (dir === 'asc' ? compare(x.v, y.v) : compare(y.v, x.v)) || x.i - y.i;
    });
    rows.forEach(function (r) { tbody.appendChild(r.tr); });
    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, i) {
      th.classList.toggle('gp-sorted-asc', i === col && dir === 'asc');
      th.classList.toggle('gp-sorted-desc', i === col && dir === 'desc');
    });
  }
  function saved(table) {
    try {
      var m = /^(\d+):(asc|desc)$/.exec(localStorage.getItem('gp-sort:' + table.id) || '');
      return m ? { col: parseInt(m[1], 10), dir: m[2] } : null;
    } catch (e) { return null; }
  }
  // Re-sort after rows are added (Live); false when the table is unsorted.
  window.gpResort = function (table) {
    var s = saved(table);
    if (!s) { return false; }
    apply(table, s.col, s.dir);
    return true;
  };
  document.querySelectorAll('table.gp-sortable').forEach(function (table) {
    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, col) {
      th.addEventListener('click', function () {
        var s = saved(table), dir;
        if (s && s.col === col) {
          dir = s.dir === 'asc' ? 'desc' : 'asc';
        } else {
          // Numbers and times start with the biggest or latest, text from A.
          var first = '';
          Array.prototype.some.call(table.tBodies[0].rows, function (tr) { first = value(tr, col); return first !== ''; });
          dir = numeric(first) || th.hasAttribute('data-desc') ? 'desc' : 'asc';
        }
        try { localStorage.setItem('gp-sort:' + table.id, col + ':' + dir); } catch (e) {}
        apply(table, col, dir);
      });
    });
    window.gpResort(table);
  });
})();
{/footer_script}

{* ------------------------------------------------------------- Real-time *}
{if $GP_TAB == 'realtime'}
<p class="gp-note">Photos float up as guests view them, grow with views, zooms, saves and downloads, and fade after a quiet spell (a photo's activity halves every {$GP_RT_HALF_LIFE} minutes; nothing older than {$GP_RT_WINDOW} minutes counts). Click a photo for its details. Nothing here is kept — it starts fresh each time it's opened.</p>
<p class="gp-rt-status" id="gp-rt-status">Loading…</p>
<div class="gp-board" id="gp-board"><p class="gp-rt-empty">Waiting for guests — photos appear here as they're viewed.</p></div>
{footer_script}
(function () {
  var board = document.getElementById('gp-board');
  var status = document.getElementById('gp-rt-status');
  var POLL = {$GP_LIVE_POLL}, MAX = {$GP_RT_MAX};
  var WINDOW = {$GP_RT_WINDOW} * 60000, HALF = {$GP_RT_HALF_LIFE} * 60000;
  var MIN_R = 24, MAX_R = 90, FLOOR = 0.15, ACTIVE = 5 * 60000;
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // image_id => bubble; sid => last event time. Times are the database's
  // clock in ms: resynced from every response, advanced by tick() between.
  var bubbles = new Map(), guests = new Map();
  var clock = null, lastEvent = null, sinceId = 0;

  function utc(s) { return Date.parse(s.replace(' ', 'T') + 'Z'); }

  function weight(e) {
    switch (e.event) {
      case 'lightbox_view': return e.detail === 'autoplay' ? 0.5 : 1;
      case 'page_view': return 1;
      case 'dwell': return (e.value || 0) / 10;
      case 'zoom': return 2;
      case 'save_intent': return 3;
      case 'download': return 4;
      default: return 1;
    }
  }

  function score(b) { return b.score * Math.pow(0.5, (clock - b.scoreAt) / HALF); }

  function bubble(e) {
    var a = document.createElement('a');
    a.className = 'gp-bubble';
    a.setAttribute('data-id', e.image_id);
    a.href = e.detail_url;
    a.title = e.image_name || '';
    var img = document.createElement('img');
    img.src = e.thumb;
    img.alt = '';
    a.appendChild(img);
    a.appendChild(document.createElement('span')).className = 'gp-ring';
    board.appendChild(a);
    var w = board.clientWidth, h = board.clientHeight;
    return {
      id: e.image_id, el: a, img: img, preview: e.preview, score: 0, scoreAt: clock, lastAt: 0,
      x: w / 2 + (Math.random() - 0.5) * w * 0.5, y: h / 2 + (Math.random() - 0.5) * h * 0.5,
      vx: 0, vy: 0, r: 0, heading: Math.random() * 2 * Math.PI
    };
  }

  function add(e, live) {
    var t = utc(e.occurred_at);
    if (clock - t > WINDOW) { return; }
    guests.set(e.sid, Math.max(guests.get(e.sid) || 0, t));
    lastEvent = Math.max(lastEvent || 0, t);
    // Album-only events just feed the status line.
    if (!e.image_id || !e.thumb) { return; }
    var b = bubbles.get(e.image_id);
    if (!b) {
      b = bubble(e);
      bubbles.set(e.image_id, b);
    }
    b.score = score(b) + weight(e) * Math.pow(0.5, Math.max(0, clock - t) / HALF);
    b.scoreAt = clock;
    b.lastAt = Math.max(b.lastAt, t);
    if (live && !still) {
      var kick = 60 + Math.random() * 60, dir = Math.random() * 2 * Math.PI;
      b.vx += Math.cos(dir) * kick;
      b.vy += Math.sin(dir) * kick;
      var ring = b.el.lastChild;
      ring.classList.remove('gp-pulse');
      void ring.offsetWidth; // restart the animation
      ring.classList.add('gp-pulse');
    }
  }

  function remove(b) {
    bubbles.delete(b.id);
    board.removeChild(b.el);
  }

  // Busy zips can flood the board: keep the highest scores.
  function cap() {
    if (bubbles.size <= MAX) { return; }
    Array.from(bubbles.values()).sort(function (a, b) { return score(a) - score(b); })
      .slice(0, bubbles.size - MAX).forEach(remove);
  }

  function ago(ms) {
    var s = Math.max(0, Math.round(ms / 1000));
    return s < 60 ? s + ' s' : s < 3600 ? Math.floor(s / 60) + ' m' : Math.floor(s / 3600) + ' h';
  }

  function showStatus() {
    var n = 0;
    guests.forEach(function (t, sid) {
      if (clock - t > WINDOW) { guests.delete(sid); } else if (clock - t <= ACTIVE) { n++; }
    });
    status.textContent = (n === 1 ? '1 guest' : n + ' guests') + ' active'
      + (lastEvent === null ? ' · no activity in the last ' + WINDOW / 60000 + ' minutes'
        : clock - lastEvent < 10000 ? ' · active now' : ' · quiet for ' + ago(clock - lastEvent));
  }

  // One step: decay, sizes, motion, then draw. Decay uses the whole dt, so a
  // test can jump minutes ahead; motion is clamped to a short step.
  var statusAt = 0;
  function tick(dtMs) {
    if (clock === null) { return; }
    clock += dtMs;
    var dt = Math.min(dtMs, 50) / 1000;
    var w = board.clientWidth, h = board.clientHeight;
    var list = Array.from(bubbles.values()), top = 4;
    list.forEach(function (b) { b.s = score(b); top = Math.max(top, b.s); });
    list.forEach(function (b) {
      var gone = b.s < FLOOR || clock - b.lastAt > WINDOW;
      var target = gone ? 0 : MIN_R + (MAX_R - MIN_R) * Math.sqrt(b.s / top);
      b.r += (target - b.r) * Math.min(1, dt * 4 + (dtMs > 1000 ? 1 : 0));
      if (gone && b.r < 2) { remove(b); b.dead = true; }
    });
    list = list.filter(function (b) { return !b.dead; });
    list.forEach(function (b) {
      var ax = (w / 2 - b.x) * 0.4, ay = (h / 2 - b.y) * 0.4;
      if (!still) {
        b.heading += (Math.random() - 0.5) * dt * 3;
        ax += Math.cos(b.heading) * 15;
        ay += Math.sin(b.heading) * 15;
      }
      b.vx = (b.vx + ax * dt) * Math.exp(-2 * dt);
      b.vy = (b.vy + ay * dt) * Math.exp(-2 * dt);
      b.x += b.vx * dt;
      b.y += b.vy * dt;
    });
    // Push overlapping pairs apart, a few passes so a crowd settles.
    for (var pass = 0; pass < 3; pass++) {
      for (var i = 0; i < list.length; i++) {
        for (var j = i + 1; j < list.length; j++) {
          var p = list[i], q = list[j];
          var dx = q.x - p.x, dy = q.y - p.y, d = Math.sqrt(dx * dx + dy * dy) || 0.01;
          var overlap = p.r + q.r + 6 - d;
          if (overlap > 0) {
            var mx = dx / d * overlap / 2, my = dy / d * overlap / 2;
            p.x -= mx; p.y -= my; q.x += mx; q.y += my;
          }
        }
      }
    }
    list.forEach(function (b) {
      b.x = Math.min(Math.max(b.x, b.r), w - b.r);
      b.y = Math.min(Math.max(b.y, b.r), h - b.r);
      var size = 2 * b.r;
      b.el.style.width = b.el.style.height = size + 'px';
      b.el.style.transform = 'translate(' + (b.x - b.r) + 'px,' + (b.y - b.r) + 'px)';
      // The thumbnail blurs past ~140 px: switch to the larger derivative.
      if (size > 140 && b.preview && !b.big) { b.big = true; b.img.src = b.preview; }
    });
    board.classList.toggle('gp-board-empty', list.length === 0);
    if (clock - statusAt >= 1000 || dtMs > 1000) { statusAt = clock; showStatus(); }
  }

  var frame = null, prev = 0;
  function loop(ts) {
    tick(prev ? Math.min(ts - prev, 1000) : 16);
    prev = ts;
    frame = requestAnimationFrame(loop);
  }

  function fetchEvents(query) {
    return fetch('{$GP_WS}&' + query, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.stat !== 'ok') { throw new Error(data.message); }
        clock = utc(data.result.now);
        return data.result.events;
      });
  }

  // The newest page, newest first: Show older from the top.
  function seed() {
    bubbles.forEach(remove);
    guests.clear();
    lastEvent = null;
    return fetchEvents('before_id=' + Number.MAX_SAFE_INTEGER).then(function (events) {
      events.forEach(function (e) { sinceId = Math.max(sinceId, e.id); });
      events.slice().reverse().forEach(function (e) { add(e, false); });
      cap();
      tick(0);
      showStatus();
    });
  }

  var busy = false, timer = null, empties = 0;
  function poll() {
    if (busy) { return; }
    busy = true;
    fetchEvents('since_id=' + sinceId)
      .then(function (events) {
        events.forEach(function (e) {
          sinceId = Math.max(sinceId, e.id);
          add(e, true);
        });
        cap();
        return events.length;
      })
      .catch(function () { return 0; })
      .then(function (count) {
        busy = false;
        if (count >= POLL) { poll(); return; }
        empties = count > 0 ? 0 : empties + 1;
        schedule(empties >= 3 ? 5000 : 1000);
      });
  }
  function schedule(ms) {
    clearTimeout(timer);
    timer = document.hidden ? null : setTimeout(poll, ms);
  }
  function start() {
    empties = 0;
    seed().catch(function () { status.textContent = 'Could not load activity.'; })
      .then(function () { schedule(1000); });
  }
  // Hidden: stop. Shown again: start over from the newest rather than replay the gap.
  document.addEventListener('visibilitychange', function () {
    clearTimeout(timer);
    if (!document.hidden) { start(); }
  });
  start();
  frame = requestAnimationFrame(loop);

  window.gpRealtime = {
    tick: tick,
    bubbles: function () {
      return Array.from(bubbles.values()).map(function (b) {
        return { id: b.id, r: b.r, score: score(b), x: b.x, y: b.y, src: b.img.src };
      });
    },
    stop: function () { cancelAnimationFrame(frame); clearTimeout(timer); frame = null; }
  };
})();
{/footer_script}
{/if}

{* ------------------------------------------------------------------ Live *}
{if $GP_TAB == 'live'}
<p class="gp-note">Newest first, updated within a second while guests are active (every 5 seconds when quiet); opens with the latest {$GP_LIVE_PAGE} events, and Show older pages further back. New events that don't match the filters aren't shown. Admin sessions are not tracked — open a share link in a private window to see yourself here.</p>
<table class="table2 gp-table gp-sortable" id="gp-live">
  <thead><tr><th data-desc>Time ({$GP_TZ})</th><th>Guest</th><th>Album</th><th>Event</th><th>Photo</th><th>IP</th><th>Device</th></tr></thead>
  <tbody>
  {foreach from=$GP_EVENTS item=e}
    <tr data-id="{$e.id}">
      <td data-sort="{$e.occurred_at}">{$e.occurred_at_display}</td>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$e.sid}">{$e.guest}</a></td>
      <td class="gp-album">{if $e.album_url}<a href="{$e.album_url|escape}">{$e.album_path|escape}</a>{/if}</td>
      <td>{$e.event}{if $e.detail} <span class="gp-detail">{$e.detail}</span>{/if}{if $e.value_label} <span class="gp-detail">{$e.value_label|escape}</span>{/if}</td>
      <td class="gp-photo">{if $e.thumb}<a href="{$e.detail_url|escape}"><img src="{$e.thumb}" data-preview="{$e.preview}" alt=""> {$e.image_name|escape}</a>{elseif $e.category_name}{$e.category_name|escape}{/if}</td>
      <td class="gp-ip">{$e.ip|escape|replace:':':':<wbr>'}{if $e.ip_location}<div class="gp-detail">{$e.ip_location|escape}</div>{/if}</td>
      <td>{$e.device}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
<p><button type="button" class="buttonLike" id="gp-older"{if !$GP_LIVE_MORE} hidden{/if}>Show older</button></p>
{footer_script}
(function () {
  var table = document.getElementById('gp-live');
  var tbody = table.tBodies[0];
  var olderBtn = document.getElementById('gp-older');
  var PAGE = {$GP_LIVE_PAGE}, POLL = {$GP_LIVE_POLL}, MAX_ROWS = {$GP_LIVE_MAX_ROWS};
  // Rows are shown by occurrence time, so the first and last rows aren't
  // necessarily the highest and lowest ids.
  var sinceId = 0, minId = 0;
  // Rows loaded with Show older raise the cap, so a poll never trims them away.
  var cap = MAX_ROWS;
  function rescanMinId() {
    minId = 0;
    tbody.querySelectorAll('tr[data-id]').forEach(function (tr) {
      var id = parseInt(tr.getAttribute('data-id'), 10);
      minId = minId ? Math.min(minId, id) : id;
    });
  }
  tbody.querySelectorAll('tr[data-id]').forEach(function (tr) {
    sinceId = Math.max(sinceId, parseInt(tr.getAttribute('data-id'), 10));
  });
  rescanMinId();
  var visitorsUrl = '{$GP_ADMIN}-visitors&sid=';

  function cell(tr, text) {
    var td = document.createElement('td');
    if (text !== null && text !== undefined) { td.textContent = text; }
    tr.appendChild(td);
    return td;
  }

  function row(e) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-id', e.id);
    tr.className = 'gp-new';
    cell(tr, e.occurred_at_display).setAttribute('data-sort', e.occurred_at);
    var a = document.createElement('a');
    a.href = visitorsUrl + e.sid;
    a.textContent = e.guest;
    cell(tr, null).appendChild(a);
    var album = cell(tr, null);
    album.className = 'gp-album';
    if (e.album_url) {
      var al = document.createElement('a');
      al.href = e.album_url;
      al.textContent = e.album_path;
      album.appendChild(al);
    }
    var ev = cell(tr, e.event);
    [e.detail, e.value_label].forEach(function (text) {
      if (!text) { return; }
      ev.appendChild(document.createTextNode(' '));
      var s = document.createElement('span');
      s.className = 'gp-detail';
      s.textContent = text;
      ev.appendChild(s);
    });
    var photo = cell(tr, null);
    photo.className = 'gp-photo';
    if (e.thumb) {
      var link = document.createElement('a');
      link.href = e.detail_url;
      var img = document.createElement('img');
      img.src = e.thumb;
      img.setAttribute('data-preview', e.preview);
      img.alt = '';
      link.appendChild(img);
      link.appendChild(document.createTextNode(' ' + (e.image_name || '')));
      photo.appendChild(link);
    } else if (e.category_name) {
      photo.textContent = e.category_name;
    }
    var ip = cell(tr, null);
    ip.className = 'gp-ip';
    // IPv6 may break after a colon; nothing else breaks an address.
    (e.ip || '').split(':').forEach(function (part, i) {
      if (i > 0) {
        ip.appendChild(document.createTextNode(':'));
        ip.appendChild(document.createElement('wbr'));
      }
      if (part) { ip.appendChild(document.createTextNode(part)); }
    });
    if (e.ip_location) {
      var loc = document.createElement('div');
      loc.className = 'gp-detail';
      loc.textContent = e.ip_location;
      ip.appendChild(loc);
    }
    cell(tr, e.device);
    return tr;
  }

  function newestFirst(a, b) {
    var ta = a.cells[0].getAttribute('data-sort'), tb = b.cells[0].getAttribute('data-sort');
    return ta < tb ? 1 : ta > tb ? -1 : b.getAttribute('data-id') - a.getAttribute('data-id');
  }

  var busy = false;
  function poll() {
    if (busy) { return; }
    busy = true;
    fetch('{$GP_WS}&since_id=' + sinceId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.stat !== 'ok') { return 0; }
        // Polls after the first return events oldest first, sorted by time.
        var events = data.result.events.slice().sort(function (a, b) {
          return a.occurred_at < b.occurred_at ? -1 : a.occurred_at > b.occurred_at ? 1 : a.id - b.id;
        });
        events.forEach(function (e) {
          tbody.insertBefore(row(e), tbody.firstChild);
          sinceId = Math.max(sinceId, e.id);
        });
        // A tab left open for days stays responsive; trimmed rows can be paged
        // back. The oldest rows go, whatever the table is sorted by. A trimmed
        // row can have a higher id than one kept: page back from above every
        // trimmed id, and Show older skips the rows still on screen.
        if (tbody.children.length > cap) {
          var maxTrimmed = 0;
          Array.prototype.slice.call(tbody.children).sort(newestFirst).slice(cap).forEach(function (tr) {
            maxTrimmed = Math.max(maxTrimmed, parseInt(tr.getAttribute('data-id'), 10));
            tbody.removeChild(tr);
          });
          minId = Math.max(minId, maxTrimmed + 1);
          olderBtn.hidden = false;
        }
        if (events.length) { window.gpResort(table); }
        return data.result.events.length;
      })
      .catch(function () { return 0; })
      .then(function (count) {
        busy = false;
        if (count >= POLL) { poll(); return; } // a full page: more are waiting
        empties = count > 0 ? 0 : empties + 1;
        schedule(empties >= 3 ? 5000 : 1000);
      });
  }

  // Adaptive: 1 s while events are arriving, 5 s after three empty polls,
  // nothing while the tab is hidden. Each poll schedules the next, so they
  // never overlap; an extra poll() while one is in flight is just skipped.
  var timer = null, empties = 0;
  function schedule(ms) {
    clearTimeout(timer);
    timer = document.hidden ? null : setTimeout(poll, ms);
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      clearTimeout(timer);
    } else {
      empties = 0;
      poll();
    }
  });
  schedule(1000);

  olderBtn.addEventListener('click', function () {
    olderBtn.disabled = true;
    fetch('{$GP_WS}&before_id=' + minId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.stat !== 'ok') { return; }
        // Already newest first by time.
        data.result.events.forEach(function (e) {
          minId = minId ? Math.min(minId, e.id) : e.id;
          if (tbody.querySelector('tr[data-id="' + e.id + '"]')) { return; }
          var tr = row(e);
          tr.className = '';
          tbody.appendChild(tr);
        });
        cap = Math.max(cap, tbody.children.length);
        window.gpResort(table);
        olderBtn.hidden = data.result.events.length < PAGE;
      })
      .catch(function () {})
      .then(function () { olderBtn.disabled = false; });
  });
})();
{/footer_script}
{/if}

{* ---------------------------------------------------------------- Photos *}
{if $GP_TAB == 'photos'}
<p class="gp-note">
  <b>Views</b> = lightbox slides a guest opened (slideshow autoplay excluded); <b>Photo page</b> = opens of the photo's own page, counted separately. <b>Save intent</b> = right-click, long-press or drag on the photo — what people do right before saving; it is intent, not a confirmed save. Screenshots cannot be detected.
  <b>Downloads</b> are logged by this plugin when the download button is used; Piwigo's own History counts them too, including yours — the two are different counts, don't add them.
  Downloads also include Batch Downloader zips: one per photo in the zip, counted when the zip is built (so one 200-photo zip adds 200).
  An album includes its sub-albums. Click a photo for its details.
</p>
{if empty($GP_PHOTOS)}
<p>No photo activity in this period.</p>
{else}
{if count($GP_PHOTOS) >= $GP_ROWS}<p class="gp-note">Showing the top {$GP_ROWS} by the sorted column.</p>{/if}
<table class="table2 gp-table" id="gp-photos">
  <thead><tr>
    {foreach from=$GP_SORTS key=k item=label}
    {if $k == $GP_F.sort}{assign var=next value=($GP_F.dir == 'asc') ? 'desc' : 'asc'}{else}{assign var=next value=($k == 'name') ? 'asc' : 'desc'}{/if}
    <th class="{if $k == $GP_F.sort}gp-sorted-{$GP_F.dir}{/if}"><a href="{$GP_BASE}&amp;{$GP_QUERY|escape}&amp;sort={$k}&amp;dir={$next}">{$label}</a></th>
    {/foreach}
  </tr></thead>
  <tbody>
  {foreach from=$GP_PHOTOS item=p}
    <tr>
      <td class="gp-photo">{if $p.thumb}<a href="{$p.detail_url|escape}&amp;{$GP_PERIOD_QUERY|escape}"><img src="{$p.thumb}" data-preview="{$p.preview}" alt=""> {$p.image_name|escape}</a>{else}#{$p.image_id}{/if}</td>
      <td>{$p.views}</td>
      <td>{$p.page_views}</td>
      <td>{$p.guests}</td>
      <td>{$p.dwell_total}</td>
      <td>{$p.zooms}</td>
      <td>{if $p.max_zoom}{$p.max_zoom}&times;{/if}</td>
      <td>{$p.saves}</td>
      <td>{$p.downloads}</td>
      <td>{$p.dwell_avg}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}
{/if}

{* ----------------------------------------------------------- Photo detail *}
{if $GP_TAB == 'photo'}
<p><a href="{$GP_ADMIN}-photos">&larr; All photos</a></p>
{if empty($GP_PHOTO)}
<p>Photo not found.</p>
{else}
<div class="gp-photo-head">
  <a href="{$GP_PHOTO.admin_url}" title="Open in Piwigo's photo editor"><img src="{$GP_PHOTO.src}" alt=""></a>
  <div>
    <h3>{$GP_PHOTO.name|escape}</h3>
    <p><a href="{$GP_PHOTO.admin_url}">Edit in Piwigo &rarr;</a></p>
  </div>
</div>

<h4>Gallery Pulse — share-link guests</h4>
{if empty($GP_PHOTO.totals)}
<p>No activity on this photo in this period.</p>
{else}
{assign var=t value=$GP_PHOTO.totals}
<table class="table2 gp-table gp-totals">
  <thead><tr><th>Views</th><th>Photo page</th><th>Guests</th><th>Dwell (total s)</th><th>Avg dwell (s)</th><th>Zooms</th><th>Max zoom</th><th>Save intent</th><th>Downloads</th></tr></thead>
  <tbody><tr>
    <td>{$t.views}</td><td>{$t.page_views}</td><td>{$t.guests}</td><td>{$t.dwell_total}</td><td>{$t.dwell_avg}</td>
    <td>{$t.zooms}</td><td>{if $t.max_zoom}{$t.max_zoom}&times;{/if}</td><td>{$t.saves}</td><td>{$t.downloads}</td>
  </tr></tbody>
</table>
{/if}

<h4>Piwigo History — everyone, including you</h4>
<p class="gp-note">Read from Piwigo's own History, which also counts admins and visits from before this plugin. A separate count — don't add it to the numbers above.</p>
<table class="table2 gp-table gp-totals">
  <thead><tr><th>Photo page views (period)</th><th>Downloads (period)</th><th>Hits (all time)</th></tr></thead>
  <tbody><tr><td>{$GP_PHOTO.core.views}</td><td>{$GP_PHOTO.core.downloads}</td><td>{$GP_PHOTO.core.hits}</td></tr></tbody>
</table>

{if !empty($GP_PHOTO.guests)}
<h4>Guests</h4>
<table class="table2 gp-table gp-sortable" id="gp-photo-guests">
  <thead><tr><th>Guest</th><th>Link</th><th>Device</th><th data-desc>Last seen ({$GP_TZ})</th><th>Views</th><th>Dwell (s)</th><th>Max zoom</th><th>Save intent</th><th>Downloads</th></tr></thead>
  <tbody>
  {foreach from=$GP_PHOTO.guests item=g}
    <tr>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$g.sid}">{$g.guest}</a></td>
      <td>{$g.link_name|escape}</td>
      <td>{$g.device}</td>
      <td data-sort="{$g.last_seen}">{$g.last_seen_display}</td>
      <td>{$g.views}</td>
      <td>{$g.dwell_total}</td>
      <td data-sort="{$g.max_zoom}">{if $g.max_zoom}{$g.max_zoom}&times;{/if}</td>
      <td>{$g.saves}</td>
      <td>{$g.downloads}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}

{if !empty($GP_PHOTO.timeline)}
<h4>Events</h4>
<table class="table2 gp-table gp-sortable" id="gp-photo-events">
  <thead><tr><th data-desc>Time ({$GP_TZ})</th><th>Guest</th><th>Link</th><th>Event</th><th>IP</th><th>Device</th></tr></thead>
  <tbody>
  {foreach from=$GP_PHOTO.timeline item=e}
    <tr>
      <td data-sort="{$e.occurred_at}">{$e.occurred_at_display}</td>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$e.sid}">{$e.guest}</a></td>
      <td>{$e.link_name|escape}</td>
      <td>{$e.event}{if $e.detail} <span class="gp-detail">{$e.detail}</span>{/if}{if $e.value_label} <span class="gp-detail">{$e.value_label|escape}</span>{/if}</td>
      <td class="gp-ip">{$e.ip|escape|replace:':':':<wbr>'}{if $e.ip_location}<div class="gp-detail">{$e.ip_location|escape}</div>{/if}</td>
      <td>{$e.device}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}
{/if}
{/if}

{* -------------------------------------------------------------- Visitors *}
{if $GP_TAB == 'visitors'}
{if isset($GP_SID)}
<p><a href="{$GP_ADMIN}-visitors">&larr; All visitors</a></p>
<h3>{if $GP_USERNAME}{$GP_USERNAME|escape} <span class="gp-detail">browser {$GP_SID|truncate:8:''}</span>{else}Guest {$GP_SID|truncate:8:''}{/if}</h3>
{if empty($GP_TIMELINE)}
<p>No events for this guest.</p>
{else}
<table class="table2 gp-table gp-sortable" id="gp-timeline">
  <thead><tr><th data-desc>Time ({$GP_TZ})</th><th>Link</th><th>Event</th><th>Photo / album</th><th>IP</th><th>Device</th></tr></thead>
  <tbody>
  {foreach from=$GP_TIMELINE item=e}
    <tr>
      <td data-sort="{$e.occurred_at}">{$e.occurred_at_display}</td>
      <td>{$e.link_name|escape}</td>
      <td>{$e.event}{if $e.detail} <span class="gp-detail">{$e.detail}</span>{/if}{if $e.value_label} <span class="gp-detail">{$e.value_label|escape}</span>{/if}</td>
      <td class="gp-photo">{if $e.thumb}<a href="{$e.detail_url|escape}"><img src="{$e.thumb}" data-preview="{$e.preview}" alt=""> {$e.image_name|escape}</a>{elseif $e.category_name}{$e.category_name|escape}{/if}</td>
      <td class="gp-ip">{$e.ip|escape|replace:':':':<wbr>'}{if $e.ip_location}<div class="gp-detail">{$e.ip_location|escape}</div>{/if}</td>
      <td>{$e.device}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}
{else}
<p class="gp-note">One row per browser (a first-party cookie kept 30 days), named by username when a registered user was logged in. "no beacon" collects downloads from browsers that never ran the tracking script. Albums are listed most recently viewed first; IP is the address the visitor was last seen from. The share link each event came through is on the visitor's timeline.</p>
{if empty($GP_VISITORS)}
<p>No visitors in this period.</p>
{else}
{if count($GP_VISITORS) >= $GP_ROWS}<p class="gp-note">Showing the {$GP_ROWS} most recently active.</p>{/if}
<table class="table2 gp-table gp-sortable" id="gp-visitors">
  <thead><tr><th>Visitor</th><th>Albums (latest first)</th><th>IP (last seen)</th><th>Device</th><th data-desc>First seen ({$GP_TZ})</th><th data-desc>Last seen ({$GP_TZ})</th><th>Photos</th><th>Dwell (s)</th><th>Zooms</th><th>Save intent</th><th>Downloads</th></tr></thead>
  <tbody>
  {foreach from=$GP_VISITORS item=v}
    <tr>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$v.sid}">{if $v.username}{$v.username|escape}{else}{$v.guest}{/if}</a></td>
      <td class="gp-albums">{foreach from=$v.albums_shown item=a}<div>{$a|escape}</div>{/foreach}{if $v.albums_more}<div class="gp-detail" title="{$v.albums_rest|escape}">+{$v.albums_more} more</div>{/if}</td>
      <td class="gp-ip">{$v.last_ip|escape|replace:':':':<wbr>'}{if $v.ip_location}<div class="gp-detail">{$v.ip_location|escape}</div>{/if}</td>
      <td>{$v.device}</td>
      <td data-sort="{$v.first_seen}">{$v.first_seen_display}</td>
      <td data-sort="{$v.last_seen}">{$v.last_seen_display}</td>
      <td>{$v.photos}</td>
      <td>{$v.dwell_total}</td>
      <td>{$v.zooms}</td>
      <td>{$v.saves}</td>
      <td>{$v.downloads}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}
{/if}
{/if}

{* ---------------------------------------------------------------- Albums *}
{if $GP_TAB == 'albums'}
<p class="gp-note">One row per album, counting what guests did while looking at it. Downloads include Batch Downloader zips, one per photo, credited to the zipped album. Since 1.2 a photo's events go to the album it was viewed in; older events go to the first album the photo is filed in. <b>Via</b> lists how guests got there, busiest first: a share link, or <i>direct</i> for public pages and logged-in users. Counts here are for the album itself; its Photos link includes sub-albums.</p>
{if empty($GP_ALBUM_STATS)}
<p>No activity in this period.</p>
{else}
<table class="table2 gp-table gp-sortable" id="gp-albums">
  <thead><tr><th>Album</th><th>Via</th><th>Guests</th><th>Album views</th><th>Views</th><th>Photo page</th><th>Zooms</th><th>Save intent</th><th>Downloads</th><th data-desc>Last activity ({$GP_TZ})</th><th></th></tr></thead>
  <tbody>
  {foreach from=$GP_ALBUM_STATS item=a}
    <tr>
      <td>{if $a.album_name}<a href="{$GP_ADMIN}-photos&amp;{$GP_QUERY_NOCAT|escape}&amp;cat={$a.category_id}">{$a.album_name|escape}</a>{elseif $a.category_id}#{$a.category_id} <span class="gp-detail">deleted</span>{else}<i>Unknown</i>{/if}</td>
      <td class="gp-detail">{$a.via|escape}</td>
      <td>{$a.guests}</td>
      <td>{$a.album_views}</td>
      <td>{$a.views}</td>
      <td>{$a.page_views}</td>
      <td>{$a.zooms}</td>
      <td>{$a.saves}</td>
      <td>{$a.downloads}</td>
      <td data-sort="{$a.last_seen}">{$a.last_seen_display}</td>
      <td>{if $a.album_name}<a href="{$GP_ADMIN}-visitors&amp;{$GP_QUERY_NOCAT|escape}&amp;cat={$a.category_id}">Visitors</a>{/if}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}
{/if}

{* -------------------------------------------------------------- Settings *}
{if $GP_TAB == 'settings'}
<form method="post" action="{$GP_ADMIN}-settings" class="gp-settings">
  <input type="hidden" name="pwg_token" value="{$GP_TOKEN}">
  <p>
    <label>Keep events for
      <input type="number" name="retention_days" value="{$GP_RETENTION}" min="{$GP_RETENTION_MIN}" max="{$GP_RETENTION_MAX}" required> days
    </label>
  </p>
  <p class="gp-note">Older events are deleted once a day. Lowering this deletes data on the next run; it can't be brought back.</p>
  <p>
    <label>Show times in
      <select name="timezone">
        {foreach from=$GP_TIMEZONES item=z}
        <option value="{$z}"{if $z == $GP_TIMEZONE} selected{/if}>{$z}</option>
        {/foreach}
      </select>
    </label>
  </p>
  <p class="gp-note">Events are stored in UTC; only what you see is converted, with daylight saving applied.</p>
  <p>
    <label>GeoIP database
      <input type="text" name="geoip_path" value="{$GP_GEOIP_PATH|escape}" size="60" placeholder="blank = no locations">
    </label>
  </p>
  {if $GP_GEOIP_PROBLEM}<p class="gp-note gp-warning">{$GP_GEOIP_PROBLEM|escape}</p>{/if}
  <p class="gp-note">A MaxMind GeoLite2 City (<code>.mmdb</code>) file as the server sees it, used to show each IP's city and country. Lookups are local; no address leaves the server. Leave blank to show addresses without a location.</p>
  <p><input type="submit" name="save" value="Save" class="buttonLike"></p>
</form>
<p class="gp-note">The event table holds {$GP_TABLE.rows} events{if $GP_TABLE.oldest}, the oldest from {$GP_TABLE.oldest} {$GP_TZ}{/if}, using {$GP_TABLE.size_mb} MB.</p>
{/if}

</div>

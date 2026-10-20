{combine_css path=$GP_PATH|cat:"admin/template/admin.css"}

<div class="titrePage">
  <h2>Gallery Pulse</h2>
</div>

<div class="gp-wrap">

{if $GP_TAB != 'live' && $GP_TAB != 'settings' && !isset($GP_SID)}
<form method="get" action="admin.php" class="gp-filters">
  <input type="hidden" name="page" value="plugin-gallery_pulse-{$GP_TAB}">
  {if isset($GP_SORT)}<input type="hidden" name="sort" value="{$GP_SORT}">{/if}
  {if isset($GP_IMAGE_ID)}<input type="hidden" name="image_id" value="{$GP_IMAGE_ID}">{/if}
  <label>Period
    <select name="days" onchange="this.form.submit()">
      {foreach from=$GP_PERIODS key=d item=label}
      <option value="{$d}"{if $d == $GP_DAYS} selected{/if}>{$label}</option>
      {/foreach}
    </select>
  </label>
  {if $GP_TAB == 'photos' || $GP_TAB == 'visitors'}
  <label>Album
    <select name="cat" onchange="this.form.submit()">
      <option value="">All</option>
      {foreach from=$GP_ALBUMS key=id item=path}
      <option value="{$id}"{if $id == $GP_CAT} selected{/if}>{$path|escape}</option>
      {/foreach}
    </select>
  </label>
  {/if}
</form>
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
{/footer_script}

{* ------------------------------------------------------------------ Live *}
{if $GP_TAB == 'live'}
<p class="gp-note">Newest first, updated within a second while guests are active (every 5 seconds when quiet); opens with the latest {$GP_LIVE_PAGE} events, and Show older pages further back. Admin sessions are not tracked — open a share link in a private window to see yourself here.</p>
<table class="table2 gp-table" id="gp-live">
  <thead><tr><th>Time ({$GP_TZ})</th><th>Guest</th><th>Album</th><th>Event</th><th>Photo</th><th>Value</th><th>Device</th></tr></thead>
  <tbody>
  {foreach from=$GP_EVENTS item=e}
    <tr data-id="{$e.id}">
      <td>{$e.occurred_at_display}</td>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$e.sid}">{$e.guest}</a></td>
      <td class="gp-album">{if $e.album_url}<a href="{$e.album_url|escape}">{$e.album_path|escape}</a>{/if}</td>
      <td>{$e.event}{if $e.detail} <span class="gp-detail">{$e.detail}</span>{/if}</td>
      <td class="gp-photo">{if $e.thumb}<a href="{$e.detail_url|escape}"><img src="{$e.thumb}" data-preview="{$e.preview}" alt=""> {$e.image_name|escape}</a>{elseif $e.category_name}{$e.category_name|escape}{/if}</td>
      <td>{$e.value}</td>
      <td>{$e.device}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
<p><button type="button" class="buttonLike" id="gp-older"{if !$GP_LIVE_MORE} hidden{/if}>Show older</button></p>
{footer_script}
(function () {
  var tbody = document.querySelector('#gp-live tbody');
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
    cell(tr, e.occurred_at_display);
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
    if (e.detail) {
      var s = document.createElement('span');
      s.className = 'gp-detail';
      s.textContent = ' ' + e.detail;
      ev.appendChild(s);
    }
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
    cell(tr, e.value);
    cell(tr, e.device);
    return tr;
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
        // back. Rows are in time order, so a trimmed row can have a higher id
        // than one kept: page back from above every trimmed id, and Show older
        // skips the rows still on screen.
        if (tbody.children.length > cap) {
          var maxTrimmed = 0;
          while (tbody.children.length > cap) {
            maxTrimmed = Math.max(maxTrimmed, parseInt(tbody.lastChild.getAttribute('data-id'), 10));
            tbody.removeChild(tbody.lastChild);
          }
          minId = Math.max(minId, maxTrimmed + 1);
          olderBtn.hidden = false;
        }
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
  An album includes its sub-albums. Click a photo for its details.
</p>
{if empty($GP_PHOTOS)}
<p>No photo activity in this period.</p>
{else}
<table class="table2 gp-table">
  <thead><tr>
    <th>Photo</th>
    {foreach from=['views'=>'Views','page_views'=>'Photo page','guests'=>'Guests','dwell'=>'Dwell (total s)','zooms'=>'Zooms','max_zoom'=>'Max zoom','saves'=>'Save intent','downloads'=>'Downloads'] key=k item=label}
    <th>{if $k == $GP_SORT}<b>{$label} &darr;</b>{else}<a href="{$GP_BASE}&amp;days={$GP_DAYS}{if $GP_CAT}&amp;cat={$GP_CAT}{/if}&amp;sort={$k}">{$label}</a>{/if}</th>
    {/foreach}
    <th>Avg dwell (s)</th>
  </tr></thead>
  <tbody>
  {foreach from=$GP_PHOTOS item=p}
    <tr>
      <td class="gp-photo">{if $p.thumb}<a href="{$p.detail_url|escape}&amp;days={$GP_DAYS}"><img src="{$p.thumb}" data-preview="{$p.preview}" alt=""> {$p.image_name|escape}</a>{else}#{$p.image_id}{/if}</td>
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
<p><a href="{$GP_ADMIN}-photos&amp;days={$GP_DAYS}">&larr; All photos</a></p>
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
<table class="table2 gp-table">
  <thead><tr><th>Guest</th><th>Link</th><th>Device</th><th>Last seen ({$GP_TZ})</th><th>Views</th><th>Dwell (s)</th><th>Max zoom</th><th>Save intent</th><th>Downloads</th></tr></thead>
  <tbody>
  {foreach from=$GP_PHOTO.guests item=g}
    <tr>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$g.sid}">{$g.guest}</a></td>
      <td>{$g.link_name|escape}</td>
      <td>{$g.device}</td>
      <td>{$g.last_seen_display}</td>
      <td>{$g.views}</td>
      <td>{$g.dwell_total}</td>
      <td>{if $g.max_zoom}{$g.max_zoom}&times;{/if}</td>
      <td>{$g.saves}</td>
      <td>{$g.downloads}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}

{if !empty($GP_PHOTO.timeline)}
<h4>Events</h4>
<table class="table2 gp-table">
  <thead><tr><th>Time ({$GP_TZ})</th><th>Guest</th><th>Link</th><th>Event</th><th>Value</th><th>Device</th></tr></thead>
  <tbody>
  {foreach from=$GP_PHOTO.timeline item=e}
    <tr>
      <td>{$e.occurred_at_display}</td>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$e.sid}">{$e.guest}</a></td>
      <td>{$e.link_name|escape}</td>
      <td>{$e.event}{if $e.detail} <span class="gp-detail">{$e.detail}</span>{/if}</td>
      <td>{$e.value}{if $e.event == 'dwell'} s{elseif $e.event == 'zoom'}&times;{/if}</td>
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
<table class="table2 gp-table">
  <thead><tr><th>Time ({$GP_TZ})</th><th>Link</th><th>Event</th><th>Photo / album</th><th>Value</th><th>Device</th></tr></thead>
  <tbody>
  {foreach from=$GP_TIMELINE item=e}
    <tr>
      <td>{$e.occurred_at_display}</td>
      <td>{$e.link_name|escape}</td>
      <td>{$e.event}{if $e.detail} <span class="gp-detail">{$e.detail}</span>{/if}</td>
      <td class="gp-photo">{if $e.thumb}<a href="{$e.detail_url|escape}"><img src="{$e.thumb}" data-preview="{$e.preview}" alt=""> {$e.image_name|escape}</a>{elseif $e.category_name}{$e.category_name|escape}{/if}</td>
      <td>{$e.value}{if $e.event == 'dwell'} s{elseif $e.event == 'zoom'}&times;{/if}</td>
      <td>{$e.device}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}
{else}
<p class="gp-note">One row per browser (a first-party cookie kept 30 days), named by username when a registered user was logged in. "no beacon" collects downloads from browsers that never ran the tracking script. The share link each event came through is on the visitor's timeline.</p>
{if empty($GP_VISITORS)}
<p>No visitors in this period.</p>
{else}
<table class="table2 gp-table">
  <thead><tr><th>Visitor</th><th>Albums</th><th>Device</th><th>First seen ({$GP_TZ})</th><th>Last seen ({$GP_TZ})</th><th>Photos</th><th>Dwell (s)</th><th>Zooms</th><th>Save intent</th><th>Downloads</th></tr></thead>
  <tbody>
  {foreach from=$GP_VISITORS item=v}
    <tr>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$v.sid}">{if $v.username}{$v.username|escape}{else}{$v.guest}{/if}</a></td>
      <td title="{$v.albums_all|escape}">{$v.album_first|escape}{if $v.albums_more} <span class="gp-detail">+{$v.albums_more} more</span>{/if}</td>
      <td>{$v.device}</td>
      <td>{$v.first_seen_display}</td>
      <td>{$v.last_seen_display}</td>
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
<p class="gp-note">One row per album, counting what guests did while looking at it. Since 1.2 a photo's events go to the album it was viewed in; older events go to the first album the photo is filed in. <b>Via</b> lists how guests got there, busiest first: a share link, or <i>direct</i> for public pages and logged-in users. Counts here are for the album itself; its Photos link includes sub-albums.</p>
{if empty($GP_ALBUM_STATS)}
<p>No activity in this period.</p>
{else}
<table class="table2 gp-table">
  <thead><tr><th>Album</th><th>Via</th><th>Guests</th><th>Album views</th><th>Views</th><th>Photo page</th><th>Zooms</th><th>Save intent</th><th>Downloads</th><th>Last activity ({$GP_TZ})</th><th></th></tr></thead>
  <tbody>
  {foreach from=$GP_ALBUM_STATS item=a}
    <tr>
      <td>{if $a.album_name}<a href="{$GP_ADMIN}-photos&amp;days={$GP_DAYS}&amp;cat={$a.category_id}">{$a.album_name|escape}</a>{elseif $a.category_id}#{$a.category_id} <span class="gp-detail">deleted</span>{else}<i>Unknown</i>{/if}</td>
      <td class="gp-detail">{$a.via|escape}</td>
      <td>{$a.guests}</td>
      <td>{$a.album_views}</td>
      <td>{$a.views}</td>
      <td>{$a.page_views}</td>
      <td>{$a.zooms}</td>
      <td>{$a.saves}</td>
      <td>{$a.downloads}</td>
      <td>{$a.last_seen_display}</td>
      <td>{if $a.album_name}<a href="{$GP_ADMIN}-visitors&amp;days={$GP_DAYS}&amp;cat={$a.category_id}">Visitors</a>{/if}</td>
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
  <p><input type="submit" name="save" value="Save" class="buttonLike"></p>
</form>
<p class="gp-note">The event table holds {$GP_TABLE.rows} events{if $GP_TABLE.oldest}, the oldest from {$GP_TABLE.oldest} {$GP_TZ}{/if}, using {$GP_TABLE.size_mb} MB.</p>
{/if}

</div>

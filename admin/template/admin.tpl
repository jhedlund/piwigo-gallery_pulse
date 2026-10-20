{combine_css path=$GP_PATH|cat:"admin/template/admin.css"}

<div class="titrePage">
  <h2>Gallery Pulse</h2>
</div>

{if $GP_TAB != 'live' && !isset($GP_SID)}
<form method="get" action="admin.php" class="gp-filters">
  <input type="hidden" name="page" value="plugin-gallery_pulse-{$GP_TAB}">
  {if isset($GP_SORT)}<input type="hidden" name="sort" value="{$GP_SORT}">{/if}
  <label>Period
    <select name="days" onchange="this.form.submit()">
      {foreach from=$GP_PERIODS key=d item=label}
      <option value="{$d}"{if $d == $GP_DAYS} selected{/if}>{$label}</option>
      {/foreach}
    </select>
  </label>
  {if $GP_TAB != 'links'}
  <label>Share link
    <select name="link" onchange="this.form.submit()">
      <option value="">All</option>
      {foreach from=$GP_LINKS key=t item=name}
      <option value="{$t}"{if $t == $GP_LINK} selected{/if}>{$name|escape}</option>
      {/foreach}
    </select>
  </label>
  {/if}
</form>
{/if}

{* ------------------------------------------------------------------ Live *}
{if $GP_TAB == 'live'}
<p class="gp-note">Newest first, refreshed every 5 seconds. Admin sessions are not tracked — open a share link in a private window to see yourself here.</p>
<table class="table2 gp-table" id="gp-live">
  <thead><tr><th>Time</th><th>Guest</th><th>Link</th><th>Event</th><th>Photo</th><th>Value</th><th>Device</th></tr></thead>
  <tbody>
  {foreach from=$GP_EVENTS item=e}
    <tr data-id="{$e.id}">
      <td>{$e.occurred_at}</td>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$e.sid}">{$e.guest}</a></td>
      <td>{$e.link_name|escape}</td>
      <td>{$e.event}{if $e.detail} <span class="gp-detail">{$e.detail}</span>{/if}</td>
      <td class="gp-photo">{if $e.thumb}<img src="{$e.thumb}" alt=""> {$e.image_name|escape}{elseif $e.category_name}{$e.category_name|escape}{/if}</td>
      <td>{$e.value}</td>
      <td>{$e.device}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{footer_script}
(function () {
  var tbody = document.querySelector('#gp-live tbody');
  // Rows are shown by occurrence time, so the first row isn't necessarily the highest id.
  var sinceId = 0;
  tbody.querySelectorAll('tr[data-id]').forEach(function (tr) {
    sinceId = Math.max(sinceId, parseInt(tr.getAttribute('data-id'), 10));
  });
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
    cell(tr, e.occurred_at);
    var a = document.createElement('a');
    a.href = visitorsUrl + e.sid;
    a.textContent = e.guest;
    cell(tr, null).appendChild(a);
    cell(tr, e.link_name);
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
      var img = document.createElement('img');
      img.src = e.thumb;
      img.alt = '';
      photo.appendChild(img);
      photo.appendChild(document.createTextNode(' ' + (e.image_name || '')));
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
        if (data.stat !== 'ok') { return; }
        // Polls after the first return events oldest first, sorted by time.
        var events = data.result.events.slice().sort(function (a, b) {
          return a.occurred_at < b.occurred_at ? -1 : a.occurred_at > b.occurred_at ? 1 : a.id - b.id;
        });
        events.forEach(function (e) {
          tbody.insertBefore(row(e), tbody.firstChild);
          sinceId = Math.max(sinceId, e.id);
        });
        while (tbody.children.length > 200) { tbody.removeChild(tbody.lastChild); }
        return data.result.events.length >= 100;
      })
      .catch(function () { return false; })
      .then(function (more) {
        busy = false;
        if (more) { poll(); }
      });
  }
  setInterval(poll, 5000);
})();
{/footer_script}
{/if}

{* ---------------------------------------------------------------- Photos *}
{if $GP_TAB == 'photos'}
<p class="gp-note">
  <b>Views</b> = lightbox slides a guest opened (slideshow autoplay excluded); <b>Photo page</b> = opens of the photo's own page, counted separately. <b>Save intent</b> = right-click, long-press or drag on the photo — what people do right before saving; it is intent, not a confirmed save. Screenshots cannot be detected.
  <b>Downloads</b> are logged by this plugin when the download button is used; Piwigo's own History counts them too, including yours — the two are different counts, don't add them.
</p>
{if empty($GP_PHOTOS)}
<p>No photo activity in this period.</p>
{else}
<table class="table2 gp-table">
  <thead><tr>
    <th>Photo</th>
    {foreach from=['views'=>'Views','page_views'=>'Photo page','guests'=>'Guests','dwell'=>'Dwell (total s)','zooms'=>'Zooms','max_zoom'=>'Max zoom','saves'=>'Save intent','downloads'=>'Downloads'] key=k item=label}
    <th>{if $k == $GP_SORT}<b>{$label} &darr;</b>{else}<a href="{$GP_BASE}&amp;days={$GP_DAYS}{if $GP_LINK}&amp;link={$GP_LINK}{/if}&amp;sort={$k}">{$label}</a>{/if}</th>
    {/foreach}
    <th>Avg dwell (s)</th>
  </tr></thead>
  <tbody>
  {foreach from=$GP_PHOTOS item=p}
    <tr>
      <td class="gp-photo">{if $p.thumb}<a href="{$p.image_admin_url}"><img src="{$p.thumb}" alt=""></a> {$p.image_name|escape}{else}#{$p.image_id}{/if}</td>
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

{* -------------------------------------------------------------- Visitors *}
{if $GP_TAB == 'visitors'}
{if isset($GP_SID)}
<p><a href="{$GP_ADMIN}-visitors">&larr; All visitors</a></p>
<h3>Guest {$GP_SID|truncate:8:''}</h3>
{if empty($GP_TIMELINE)}
<p>No events for this guest.</p>
{else}
<table class="table2 gp-table">
  <thead><tr><th>Time</th><th>Link</th><th>Event</th><th>Photo / album</th><th>Value</th><th>Device</th></tr></thead>
  <tbody>
  {foreach from=$GP_TIMELINE item=e}
    <tr>
      <td>{$e.occurred_at}</td>
      <td>{$e.link_name|escape}</td>
      <td>{$e.event}{if $e.detail} <span class="gp-detail">{$e.detail}</span>{/if}</td>
      <td class="gp-photo">{if $e.thumb}<img src="{$e.thumb}" alt=""> {$e.image_name|escape}{elseif $e.category_name}{$e.category_name|escape}{/if}</td>
      <td>{$e.value}{if $e.event == 'dwell'} s{elseif $e.event == 'zoom'}&times;{/if}</td>
      <td>{$e.device}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}
{else}
<p class="gp-note">One row per browser (a first-party cookie kept 30 days). "no beacon" collects downloads from browsers that never ran the tracking script.</p>
{if empty($GP_VISITORS)}
<p>No visitors in this period.</p>
{else}
<table class="table2 gp-table">
  <thead><tr><th>Guest</th><th>Link</th><th>Device</th><th>First seen</th><th>Last seen</th><th>Photos</th><th>Dwell (s)</th><th>Zooms</th><th>Save intent</th><th>Downloads</th></tr></thead>
  <tbody>
  {foreach from=$GP_VISITORS item=v}
    <tr>
      <td><a href="{$GP_ADMIN}-visitors&amp;sid={$v.sid}">{$v.guest}</a></td>
      <td>{$v.link_name|escape}</td>
      <td>{$v.device}</td>
      <td>{$v.first_seen}</td>
      <td>{$v.last_seen}</td>
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

{* ----------------------------------------------------------------- Links *}
{if $GP_TAB == 'links'}
{if empty($GP_LINK_STATS)}
<p>No activity in this period.</p>
{else}
<table class="table2 gp-table">
  <thead><tr><th>Share link</th><th>Guests</th><th>Views</th><th>Zooms</th><th>Save intent</th><th>Downloads</th><th>Last activity</th><th></th></tr></thead>
  <tbody>
  {foreach from=$GP_LINK_STATS item=l}
    <tr>
      <td>{if $l.link_name}{$l.link_name|escape}{else}<i>Not via a share link</i>{/if}</td>
      <td>{$l.guests}</td>
      <td>{$l.views}</td>
      <td>{$l.zooms}</td>
      <td>{$l.saves}</td>
      <td>{$l.downloads}</td>
      <td>{$l.last_seen}</td>
      <td>{if $l.share_token}<a href="{$GP_ADMIN}-photos&amp;days={$GP_DAYS}&amp;link={$l.share_token}">Photos</a> · <a href="{$GP_ADMIN}-visitors&amp;days={$GP_DAYS}&amp;link={$l.share_token}">Visitors</a>{/if}</td>
    </tr>
  {/foreach}
  </tbody>
</table>
{/if}
{/if}

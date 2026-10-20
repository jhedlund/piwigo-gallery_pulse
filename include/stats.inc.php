<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Read side for the admin tabs and the Live poll. All filters arrive validated
 * (ints, a whitelisted sort key, a token/sid matching a strict pattern).
 * Core tables are only ever read here, never written.
 */

// Live: rows on first load and per "Show older" page, rows per 5 s poll
// (a poll that fills it is continued at once), and the most rows kept on screen.
define('GALLERY_PULSE_LIVE_PAGE', 500);
define('GALLERY_PULSE_LIVE_POLL', 100);
define('GALLERY_PULSE_LIVE_MAX_ROWS', 2000);

/**
 * Views = lightbox slides a person opened. Autoplay slides and photo-page
 * opens are excluded: a slideshow left running isn't attention, and a guest
 * who opens the photo page and then its lightbox would count twice. Dwell is
 * only measured on these slides, so avg dwell divides by this.
 */
function gallery_pulse_sql_views()
{
  return 'SUM(e.event = \'lightbox_view\' AND e.detail IS NULL)';
}

/**
 * Distinct browsers, leaving out the shared placeholder for downloads made
 * without a beacon cookie.
 */
function gallery_pulse_sql_guests()
{
  return 'COUNT(DISTINCT CASE WHEN e.sid <> \'' . GALLERY_PULSE_NO_SID . '\' THEN e.sid END)';
}

/**
 * @param int[]|null $cat_ids restrict to events filed under these albums
 */
function gallery_pulse_where($days, $token, $cat_ids = null)
{
  $where = array('e.occurred_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)');
  if ($token !== null)
  {
    $where[] = 'e.share_token = \'' . pwg_db_real_escape_string($token) . '\'';
  }
  if ($cat_ids !== null)
  {
    $where[] = 'e.category_id IN (' . implode(',', array_map('intval', $cat_ids)) . ')';
  }
  return implode("\n    AND ", $where);
}

/**
 * Full album paths ("Parent / Child"), id => path. Cached per request.
 */
function gallery_pulse_category_paths($ids)
{
  static $cache = array();

  $ids = array_values(array_unique(array_map('intval', $ids)));
  $missing = array_values(array_diff($ids, array_keys($cache)));
  if (!empty($missing))
  {
    $uppercats = query2array(
      'SELECT id, uppercats FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', $missing) . ');',
      'id', 'uppercats');
    $all = array();
    foreach ($uppercats as $u)
    {
      $all = array_merge($all, explode(',', $u));
    }
    $names = empty($all) ? array() : query2array(
      'SELECT id, name FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', array_unique(array_map('intval', $all))) . ');',
      'id', 'name');

    foreach ($missing as $id)
    {
      $parts = array();
      if (isset($uppercats[$id]))
      {
        foreach (explode(',', $uppercats[$id]) as $c)
        {
          if (isset($names[$c]))
          {
            $parts[] = trigger_change('render_category_name', $names[$c]);
          }
        }
      }
      $cache[$id] = empty($parts) ? null : implode(' / ', $parts);
    }
  }

  $paths = array();
  foreach ($ids as $id)
  {
    if ($cache[$id] !== null)
    {
      $paths[$id] = $cache[$id];
    }
  }
  return $paths;
}

/**
 * Every album that has events, plus its parents so a whole tree can be picked.
 * id => full path, sorted by path.
 */
function gallery_pulse_album_options()
{
  $ids = query2array('SELECT DISTINCT category_id FROM ' . GALLERY_PULSE_TABLE . ' WHERE category_id IS NOT NULL;', null, 'category_id');
  if (empty($ids))
  {
    return array();
  }
  $uppercats = query2array(
    'SELECT uppercats FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', array_map('intval', $ids)) . ');',
    null, 'uppercats');
  $all = array();
  foreach ($uppercats as $u)
  {
    $all = array_merge($all, explode(',', $u));
  }
  $paths = gallery_pulse_category_paths($all);
  natcasesort($paths);
  return $paths;
}

/**
 * share_token => array(name, category_id) for every link in the event table.
 * name is the shared album's full path: a sub-album shared under its parent's
 * key is told apart by its path. Unknown keys are named by the key itself.
 */
function gallery_pulse_links()
{
  static $links = null;
  if ($links !== null)
  {
    return $links;
  }

  $links = array();
  $tokens = query2array('SELECT DISTINCT share_token FROM ' . GALLERY_PULSE_TABLE . ' WHERE share_token IS NOT NULL;', null, 'share_token');
  foreach ($tokens as $t)
  {
    $links[$t] = array('name' => $t, 'category_id' => null);
  }

  if (!empty($tokens) && defined('UNLISTED_ALBUM_TABLE'))
  {
    $escaped = array_map(function ($t) { return '\'' . pwg_db_real_escape_string($t) . '\''; }, $tokens);
    $cats = query2array(
      'SELECT random_key, category_id FROM ' . UNLISTED_ALBUM_TABLE . ' WHERE random_key IN (' . implode(',', $escaped) . ');',
      'random_key', 'category_id');
    $paths = gallery_pulse_category_paths($cats);
    foreach ($cats as $t => $cat_id)
    {
      if (isset($paths[$cat_id]))
      {
        $links[$t] = array('name' => $paths[$cat_id], 'category_id' => (int)$cat_id);
      }
    }
  }
  return $links;
}

/**
 * share_token => full album path.
 */
function gallery_pulse_link_names()
{
  return array_map(function ($l) { return $l['name']; }, gallery_pulse_links());
}

/**
 * Attach name and thumbnail to rows carrying an image_id.
 */
function gallery_pulse_decorate_images(&$rows)
{
  $ids = array();
  foreach ($rows as $r)
  {
    if (!empty($r['image_id']))
    {
      $ids[] = (int)$r['image_id'];
    }
  }
  $images = array();
  if (!empty($ids))
  {
    $query = '
SELECT id, name, file, path, representative_ext, width, height, rotation
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', array_unique($ids)) . ')
;';
    $images = query2array($query, 'id');
  }

  foreach ($rows as &$r)
  {
    $img = !empty($r['image_id']) && isset($images[$r['image_id']]) ? $images[$r['image_id']] : null;
    $r['image_name'] = $img ? (empty($img['name']) ? $img['file'] : $img['name']) : null;
    $r['thumb'] = $img ? DerivativeImage::thumb_url($img) : null;
    $r['image_admin_url'] = $img ? get_root_url() . 'admin.php?page=photo-' . $img['id'] : null;
    $r['detail_url'] = $img ? GALLERY_PULSE_ADMIN . '-photo&image_id=' . $img['id'] : null;
  }
  unset($r);
}

function gallery_pulse_decorate_categories(&$rows)
{
  $ids = array();
  foreach ($rows as $r)
  {
    if (!empty($r['category_id']))
    {
      $ids[] = (int)$r['category_id'];
    }
  }
  $names = empty($ids) ? array() : query2array(
    'SELECT id, name FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', array_unique($ids)) . ');',
    'id', 'name');

  foreach ($rows as &$r)
  {
    $r['category_name'] = !empty($r['category_id']) && isset($names[$r['category_id']])
      ? trigger_change('render_category_name', $names[$r['category_id']])
      : null;
  }
  unset($r);
}

function gallery_pulse_decorate_events(&$rows)
{
  $links = gallery_pulse_link_names();
  gallery_pulse_decorate_images($rows);
  gallery_pulse_decorate_categories($rows);
  foreach ($rows as &$r)
  {
    $r['id'] = (int)$r['id'];
    $r['guest'] = substr($r['sid'], 0, 8);
    $r['link_name'] = $r['share_token'] !== null && isset($links[$r['share_token']]) ? $links[$r['share_token']] : null;
    $r['value'] = $r['value'] === null ? null : round((float)$r['value'], 2);
  }
  unset($r);
}

/**
 * Default: the newest $limit events. With $poll: the next $limit events after
 * since_id (0 on an empty table), oldest first, so a poll that hits the limit
 * is continued by the next one instead of skipping rows. With before_id: the
 * $limit events just older than it, for paging back.
 */
function gallery_pulse_recent_events($since_id, $limit, $before_id = 0, $poll = false)
{
  if ($before_id > 0)
  {
    $where = 'id < ' . (int)$before_id;
    $order = 'DESC';
  }
  else
  {
    $where = 'id > ' . (int)$since_id;
    $order = $poll ? 'ASC' : 'DESC';
  }
  $query = '
SELECT id, occurred_at, sid, share_token, image_id, category_id, event, value, detail, device
  FROM ' . GALLERY_PULSE_TABLE . '
  WHERE ' . $where . '
  ORDER BY id ' . $order . '
  LIMIT ' . (int)$limit . '
;';
  $rows = query2array($query);
  // Ids follow arrival, not occurrence: server-side downloads land before the
  // beacon batch that preceded them.
  usort($rows, function ($a, $b) {
    return strcmp($b['occurred_at'], $a['occurred_at']) ?: $b['id'] - $a['id'];
  });
  gallery_pulse_decorate_events($rows);
  return $rows;
}

function gallery_pulse_visitor_timeline($sid)
{
  $query = '
SELECT id, occurred_at, sid, share_token, image_id, category_id, event, value, detail, device
  FROM ' . GALLERY_PULSE_TABLE . '
  WHERE sid = \'' . pwg_db_real_escape_string($sid) . '\'
  ORDER BY occurred_at, id
  LIMIT 2000
;';
  $rows = query2array($query);
  gallery_pulse_decorate_events($rows);
  return $rows;
}

/**
 * Sort keys accepted by the Photos tab, mapped to their SQL expressions.
 */
function gallery_pulse_photo_sorts()
{
  return array(
    'views' => 'views',
    'page_views' => 'page_views',
    'guests' => 'guests',
    'dwell' => 'dwell_total',
    'zooms' => 'zooms',
    'max_zoom' => 'max_zoom',
    'saves' => 'saves',
    'downloads' => 'downloads',
  );
}

/**
 * @param int[]|null $cat_ids albums to include, already expanded to sub-albums
 * @param int|null $image_id a single photo, for the detail view
 */
function gallery_pulse_photo_stats($days, $cat_ids, $sort, $image_id = null)
{
  $sorts = gallery_pulse_photo_sorts();
  $order = isset($sorts[$sort]) ? $sorts[$sort] : 'views';

  $query = '
SELECT e.image_id,
    ' . gallery_pulse_sql_views() . ' AS views,
    SUM(e.event = \'page_view\' AND e.detail = \'photo\') AS page_views,
    ' . gallery_pulse_sql_guests() . ' AS guests,
    COALESCE(SUM(CASE WHEN e.event = \'dwell\' THEN e.value END), 0) AS dwell_total,
    SUM(e.event = \'zoom\') AS zooms,
    MAX(CASE WHEN e.event = \'zoom\' THEN e.value END) AS max_zoom,
    SUM(e.event = \'save_intent\') AS saves,
    SUM(e.event = \'download\') AS downloads
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($days, null, $cat_ids) . '
    AND ' . ($image_id === null ? 'e.image_id IS NOT NULL' : 'e.image_id = ' . (int)$image_id) . '
  GROUP BY e.image_id
  ORDER BY ' . $order . ' DESC, views DESC, e.image_id
  LIMIT 200
;';
  $rows = query2array($query);
  gallery_pulse_decorate_images($rows);
  foreach ($rows as &$r)
  {
    $r['dwell_total'] = round((float)$r['dwell_total']);
    $r['dwell_avg'] = $r['views'] > 0 ? round($r['dwell_total'] / $r['views'], 1) : 0;
    $r['max_zoom'] = $r['max_zoom'] === null ? null : round((float)$r['max_zoom'], 1);
  }
  unset($r);
  return $rows;
}

function gallery_pulse_visitors($days, $token)
{
  $query = '
SELECT e.sid,
    MIN(e.occurred_at) AS first_seen,
    MAX(e.occurred_at) AS last_seen,
    MAX(e.share_token) AS share_token,
    MAX(e.device) AS device,
    COUNT(DISTINCT e.image_id) AS photos,
    SUM(e.event = \'zoom\') AS zooms,
    SUM(e.event = \'save_intent\') AS saves,
    SUM(e.event = \'download\') AS downloads,
    COALESCE(SUM(CASE WHEN e.event = \'dwell\' THEN e.value END), 0) AS dwell_total
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($days, $token) . '
  GROUP BY e.sid
  ORDER BY last_seen DESC
  LIMIT 200
;';
  $rows = query2array($query);
  $links = gallery_pulse_link_names();
  foreach ($rows as &$r)
  {
    $r['guest'] = $r['sid'] === GALLERY_PULSE_NO_SID ? 'no beacon' : substr($r['sid'], 0, 8);
    $r['link_name'] = $r['share_token'] !== null && isset($links[$r['share_token']]) ? $links[$r['share_token']] : null;
    $r['dwell_total'] = round((float)$r['dwell_total']);
  }
  unset($r);
  return $rows;
}

function gallery_pulse_link_stats($days)
{
  $query = '
SELECT e.share_token,
    ' . gallery_pulse_sql_guests() . ' AS guests,
    ' . gallery_pulse_sql_views() . ' AS views,
    SUM(e.event = \'page_view\' AND e.detail = \'photo\') AS page_views,
    SUM(e.event = \'zoom\') AS zooms,
    SUM(e.event = \'save_intent\') AS saves,
    SUM(e.event = \'download\') AS downloads,
    MAX(e.occurred_at) AS last_seen
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($days, null) . '
  GROUP BY e.share_token
  ORDER BY last_seen DESC
;';
  $rows = query2array($query);
  $links = gallery_pulse_links();
  foreach ($rows as &$r)
  {
    $link = $r['share_token'] !== null && isset($links[$r['share_token']]) ? $links[$r['share_token']] : null;
    $r['link_name'] = $r['share_token'] === null ? null : ($link ? $link['name'] : $r['share_token']);
    $r['category_id'] = $link ? $link['category_id'] : null;
  }
  unset($r);
  return $rows;
}

/**
 * Everything for one photo's detail view, or null if the photo doesn't exist.
 */
function gallery_pulse_photo_detail($image_id, $days)
{
  $image_id = (int)$image_id;
  $rows = query2array('
SELECT id, name, file, path, representative_ext, width, height, rotation, hit
  FROM ' . IMAGES_TABLE . '
  WHERE id = ' . $image_id . '
;');
  if (empty($rows))
  {
    return null;
  }
  $img = $rows[0];

  $totals = gallery_pulse_photo_stats($days, null, 'views', $image_id);

  $guests = query2array('
SELECT e.sid,
    MAX(e.share_token) AS share_token,
    MAX(e.device) AS device,
    MAX(e.occurred_at) AS last_seen,
    ' . gallery_pulse_sql_views() . ' AS views,
    COALESCE(SUM(CASE WHEN e.event = \'dwell\' THEN e.value END), 0) AS dwell_total,
    MAX(CASE WHEN e.event = \'zoom\' THEN e.value END) AS max_zoom,
    SUM(e.event = \'save_intent\') AS saves,
    SUM(e.event = \'download\') AS downloads
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($days, null) . '
    AND e.image_id = ' . $image_id . '
  GROUP BY e.sid
  ORDER BY last_seen DESC
  LIMIT 200
;');
  $links = gallery_pulse_link_names();
  foreach ($guests as &$g)
  {
    $g['guest'] = $g['sid'] === GALLERY_PULSE_NO_SID ? 'no beacon' : substr($g['sid'], 0, 8);
    $g['link_name'] = $g['share_token'] !== null && isset($links[$g['share_token']]) ? $links[$g['share_token']] : null;
    $g['dwell_total'] = round((float)$g['dwell_total']);
    $g['max_zoom'] = $g['max_zoom'] === null ? null : round((float)$g['max_zoom'], 1);
  }
  unset($g);

  $timeline = query2array('
SELECT e.id, e.occurred_at, e.sid, e.share_token, e.image_id, e.category_id, e.event, e.value, e.detail, e.device
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($days, null) . '
    AND e.image_id = ' . $image_id . '
  ORDER BY e.occurred_at DESC, e.id DESC
  LIMIT 500
;');
  gallery_pulse_decorate_events($timeline);

  // Core History counts every visitor, admins included, from before the plugin
  // too. It has no image_id index; one scan per detail view is fine here.
  list($core_views, $core_downloads) = pwg_db_fetch_row(pwg_query('
SELECT COALESCE(SUM(image_type = \'picture\'), 0), COALESCE(SUM(image_type = \'high\'), 0)
  FROM ' . HISTORY_TABLE . '
  WHERE image_id = ' . $image_id . '
    AND TIMESTAMP(date, time) >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)
;'));

  return array(
    'id' => $image_id,
    'name' => empty($img['name']) ? $img['file'] : $img['name'],
    'src' => DerivativeImage::url(IMG_MEDIUM, $img),
    'admin_url' => get_root_url() . 'admin.php?page=photo-' . $image_id,
    'totals' => empty($totals) ? null : $totals[0],
    'guests' => $guests,
    'timeline' => $timeline,
    'core' => array(
      'hits' => (int)$img['hit'],
      'views' => (int)$core_views,
      'downloads' => (int)$core_downloads,
    ),
  );
}

/**
 * Row count, oldest event and on-disk size of the event table, for Settings.
 */
function gallery_pulse_table_info()
{
  list($count, $oldest) = pwg_db_fetch_row(pwg_query('SELECT COUNT(*), MIN(occurred_at) FROM ' . GALLERY_PULSE_TABLE . ';'));
  list($bytes) = pwg_db_fetch_row(pwg_query('
SELECT data_length + index_length
  FROM information_schema.TABLES
  WHERE table_schema = DATABASE()
    AND table_name = \'' . pwg_db_real_escape_string(GALLERY_PULSE_TABLE) . '\'
;'));
  return array(
    'rows' => (int)$count,
    'oldest' => $oldest,
    'size_mb' => round((int)$bytes / 1048576, 1),
  );
}

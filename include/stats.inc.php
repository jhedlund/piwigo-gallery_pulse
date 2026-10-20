<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Read side for the admin tabs and the Live poll. All filters arrive validated
 * (ints, a whitelisted sort key, a token/sid matching a strict pattern).
 */

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

function gallery_pulse_where($days, $token, $alias = 'e')
{
  $where = array($alias . '.occurred_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)');
  if ($token !== null)
  {
    $where[] = $alias . '.share_token = \'' . pwg_db_real_escape_string($token) . '\'';
  }
  return implode("\n    AND ", $where);
}

/**
 * share_token => album name, for every link that appears in the event table.
 */
function gallery_pulse_link_names()
{
  $names = array();
  $tokens = query2array('SELECT DISTINCT share_token FROM ' . GALLERY_PULSE_TABLE . ' WHERE share_token IS NOT NULL;', null, 'share_token');
  if (empty($tokens))
  {
    return $names;
  }
  foreach ($tokens as $t)
  {
    $names[$t] = $t;
  }

  if (defined('UNLISTED_ALBUM_TABLE'))
  {
    $escaped = array_map(function ($t) { return '\'' . pwg_db_real_escape_string($t) . '\''; }, $tokens);
    $query = '
SELECT u.random_key, c.name
  FROM ' . UNLISTED_ALBUM_TABLE . ' u
    INNER JOIN ' . CATEGORIES_TABLE . ' c ON c.id = u.category_id
  WHERE u.random_key IN (' . implode(',', $escaped) . ')
;';
    foreach (query2array($query) as $row)
    {
      $names[$row['random_key']] = trigger_change('render_category_name', $row['name']);
    }
  }
  return $names;
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
 * With since_id = 0: the newest $limit events. Otherwise the next $limit
 * events after since_id, oldest first, so a poll that hits the limit is
 * continued by the next one instead of skipping rows.
 */
function gallery_pulse_recent_events($since_id, $limit)
{
  $query = '
SELECT id, occurred_at, sid, share_token, image_id, category_id, event, value, detail, device
  FROM ' . GALLERY_PULSE_TABLE . '
  WHERE id > ' . (int)$since_id . '
  ORDER BY id ' . ($since_id > 0 ? 'ASC' : 'DESC') . '
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

function gallery_pulse_photo_stats($days, $token, $sort)
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
  WHERE ' . gallery_pulse_where($days, $token) . '
    AND e.image_id IS NOT NULL
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
  $links = gallery_pulse_link_names();
  foreach ($rows as &$r)
  {
    $r['link_name'] = $r['share_token'] === null
      ? null
      : (isset($links[$r['share_token']]) ? $links[$r['share_token']] : $r['share_token']);
  }
  unset($r);
  return $rows;
}

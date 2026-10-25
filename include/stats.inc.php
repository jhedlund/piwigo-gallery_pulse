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
// Albums listed in a Visitors row before "+N more".
define('GALLERY_PULSE_VISITOR_ALBUMS', 3);
// Rows on the Photos and Visitors tabs.
define('GALLERY_PULSE_TABLE_ROWS', 200);

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
 * Event names the Event filter offers: everything the beacon sends, plus
 * server-side downloads.
 */
function gallery_pulse_event_names()
{
  return array_merge(array_keys(gallery_pulse_client_events()), array('download'));
}

/**
 * Period choices: days => label. The last one is everything kept.
 */
function gallery_pulse_periods()
{
  $retention = gallery_pulse_retention_days();
  $periods = array(1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days');
  if ($retention > 365)
  {
    $periods[365] = 'Last 365 days';
  }
  $periods[$retention] = 'All (' . $retention . ' days)';
  return $periods;
}

/**
 * A filter as the admin pages keep it: the form's own values, validated.
 * Live and a photo's detail open on everything kept, the other tabs on 30 days.
 *
 *   days   int (a key of gallery_pulse_periods()) or 'custom' for from/to
 *   from   Y-m-d in the display zone, or ''; to likewise, inclusive
 *   cat    album id (its sub-albums included), or null
 *   ev     event names; empty = all
 *   guest  browser id prefix, username or "no beacon"; '' = everyone
 *
 * @param array $in raw request or stored values
 * @param array|null $albums id => path to validate cat against; null = any positive id
 */
function gallery_pulse_filter_state($in, $tab, $albums = null)
{
  $in = is_array($in) ? $in : array();
  $periods = gallery_pulse_periods();
  $state = array(
    'days' => $tab == 'live' || $tab == 'photo' ? gallery_pulse_retention_days() : 30,
    'from' => '',
    'to' => '',
    'cat' => null,
    'ev' => array(),
    'guest' => '',
  );

  foreach (array('from', 'to') as $k)
  {
    if (isset($in[$k]) && is_string($in[$k]) && gallery_pulse_parse_date($in[$k]) !== null)
    {
      $state[$k] = $in[$k];
    }
  }
  if (isset($in['days']) && $in['days'] === 'custom' && ($state['from'] !== '' || $state['to'] !== ''))
  {
    $state['days'] = 'custom';
    if ($state['from'] !== '' && $state['to'] !== '' && $state['from'] > $state['to'])
    {
      list($state['from'], $state['to']) = array($state['to'], $state['from']);
    }
  }
  else
  {
    if (isset($in['days']) && is_scalar($in['days']) && isset($periods[(int)$in['days']]))
    {
      $state['days'] = (int)$in['days'];
    }
    $state['from'] = $state['to'] = '';
  }

  if (isset($in['cat']) && is_scalar($in['cat']) && (int)$in['cat'] > 0
    && ($albums === null || isset($albums[(int)$in['cat']])))
  {
    $state['cat'] = (int)$in['cat'];
  }
  if (isset($in['ev']) && is_array($in['ev']))
  {
    $state['ev'] = array_values(array_intersect(gallery_pulse_event_names(), $in['ev']));
  }
  if (isset($in['guest']) && is_string($in['guest']))
  {
    $state['guest'] = substr(trim($in['guest']), 0, 64);
  }
  return $state;
}

/**
 * Y-m-d at midnight in the display zone, or null if not a real date.
 */
function gallery_pulse_parse_date($s)
{
  if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s))
  {
    return null;
  }
  $d = DateTime::createFromFormat('!Y-m-d', $s, gallery_pulse_timezone());
  return $d !== false && $d->format('Y-m-d') === $s ? $d : null;
}

/**
 * The URL query for a filter state, so a link reproduces it.
 */
function gallery_pulse_filter_query($state)
{
  $q = array('days' => $state['days']);
  if ($state['days'] === 'custom')
  {
    $q['from'] = $state['from'];
    $q['to'] = $state['to'];
  }
  if ($state['cat'] !== null)
  {
    $q['cat'] = $state['cat'];
  }
  if (!empty($state['ev']))
  {
    $q['ev'] = $state['ev'];
  }
  if ($state['guest'] !== '')
  {
    $q['guest'] = $state['guest'];
  }
  return http_build_query($q, '', '&');
}

/**
 * SQL conditions on the event table (alias e) for a filter state.
 */
function gallery_pulse_where($state)
{
  $where = array(gallery_pulse_period_where($state, 'e.occurred_at'));
  if ($state['cat'] !== null)
  {
    $cat_ids = array_merge(array($state['cat']), get_subcat_ids(array($state['cat'])));
    $where[] = 'e.category_id IN (' . implode(',', array_map('intval', $cat_ids)) . ')';
  }
  if (!empty($state['ev']))
  {
    $where[] = 'e.event IN (\'' . implode('\',\'', array_intersect(gallery_pulse_event_names(), $state['ev'])) . '\')';
  }
  if ($state['guest'] !== '')
  {
    $where[] = gallery_pulse_guest_where($state['guest']);
  }
  return implode("\n    AND ", $where);
}

/**
 * The filter's period on a UTC datetime column. A custom range runs from the
 * start of its first day to the end of its last, in the display zone.
 */
function gallery_pulse_period_where($state, $column)
{
  if ($state['days'] !== 'custom')
  {
    return $column . ' >= DATE_SUB(NOW(), INTERVAL ' . (int)$state['days'] . ' DAY)';
  }
  $where = array();
  if ($state['from'] !== '')
  {
    $where[] = $column . ' >= \'' . gallery_pulse_utc(gallery_pulse_parse_date($state['from'])) . '\'';
  }
  if ($state['to'] !== '')
  {
    $where[] = $column . ' < \'' . gallery_pulse_utc(gallery_pulse_parse_date($state['to'])->modify('+1 day')) . '\'';
  }
  return implode(' AND ', $where);
}

function gallery_pulse_utc(DateTime $d)
{
  $u = clone $d;
  return $u->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Guest filter: a browser id prefix (as shown, 4+ hex digits), a username, or
 * "no beacon". Matches nothing when it is none of these.
 */
function gallery_pulse_guest_where($guest)
{
  global $conf;

  $or = array();
  if (strtolower($guest) === 'no beacon')
  {
    $or[] = 'e.sid = \'' . GALLERY_PULSE_NO_SID . '\'';
  }
  if (preg_match('/^[a-f0-9]{4,32}$/i', $guest))
  {
    $or[] = 'e.sid LIKE \'' . strtolower($guest) . '%\'';
  }
  $user_ids = query2array('
SELECT ' . $conf['user_fields']['id'] . ' AS id
  FROM ' . USERS_TABLE . '
  WHERE ' . $conf['user_fields']['username'] . ' = \'' . pwg_db_real_escape_string($guest) . '\'
;', null, 'id');
  if (!empty($user_ids))
  {
    $or[] = 'e.user_id IN (' . implode(',', array_map('intval', $user_ids)) . ')';
  }
  return empty($or) ? '0' : '(' . implode(' OR ', $or) . ')';
}

/**
 * The most recent visitors, as the Guest filter names them, for its suggestions.
 */
function gallery_pulse_guest_suggestions()
{
  $rows = query2array('
SELECT sid, MAX(user_id) AS user_id, MAX(occurred_at) AS last_seen
  FROM ' . GALLERY_PULSE_TABLE . '
  GROUP BY sid
  ORDER BY last_seen DESC
  LIMIT 100
;');
  $users = gallery_pulse_usernames(array_map(function ($r) { return $r['user_id']; }, $rows));
  $names = array();
  foreach ($rows as $r)
  {
    if ($r['sid'] === GALLERY_PULSE_NO_SID)
    {
      $names[] = 'no beacon';
    }
    else
    {
      $names[] = $r['user_id'] !== null && isset($users[$r['user_id']]) ? $users[$r['user_id']] : substr($r['sid'], 0, 8);
    }
  }
  return array_values(array_unique($names));
}

/**
 * The filter for each tab, as last used: kept in the admin's user preferences,
 * so it survives logout and follows them to another browser.
 */
function gallery_pulse_saved_filter($tab)
{
  $all = function_exists('userprefs_get_param') ? userprefs_get_param('gallery_pulse_filters', array()) : array();
  return is_array($all) && isset($all[$tab]) && is_array($all[$tab]) ? $all[$tab] : array();
}

function gallery_pulse_save_filter($tab, $state)
{
  if (!function_exists('userprefs_update_param'))
  {
    return;
  }
  $all = userprefs_get_param('gallery_pulse_filters', array());
  $all = is_array($all) ? $all : array();
  if ($state === null)
  {
    unset($all[$tab]);
  }
  else
  {
    $all[$tab] = $state;
  }
  userprefs_update_param('gallery_pulse_filters', $all);
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
    // Sharper image for the hover popup; the browser fetches it on first hover only.
    $r['preview'] = $img ? DerivativeImage::url(IMG_SMALL, $img) : null;
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

/**
 * The GeoIP database reader, once per request; null when locations are off or
 * the file can't be opened (the Settings tab says why).
 */
function gallery_pulse_geoip_reader()
{
  static $reader = false;
  if ($reader === false)
  {
    $reader = null;
    $path = gallery_pulse_geoip_path();
    if ($path !== '' && is_file($path) && is_readable($path))
    {
      gallery_pulse_load_geoip_reader();
      try
      {
        $reader = new MaxMind\Db\Reader($path);
      }
      catch (Throwable $e)
      {
        $reader = null;
      }
    }
  }
  return $reader;
}

function gallery_pulse_load_geoip_reader()
{
  $dir = GALLERY_PULSE_PATH . 'include/vendor/MaxMind/Db/';
  foreach (array('Reader/InvalidDatabaseException', 'Reader/Util', 'Reader/Decoder', 'Reader/Metadata', 'Reader') as $f)
  {
    require_once($dir . $f . '.php');
  }
}

/**
 * Why the configured GeoIP database can't be used, or null when it can (or
 * locations are off). For the Settings tab.
 */
function gallery_pulse_geoip_problem($path)
{
  if ($path === '')
  {
    return null;
  }
  if (!is_file($path) || !is_readable($path))
  {
    return 'The file can\'t be read, so no locations are shown.';
  }
  gallery_pulse_load_geoip_reader();
  try
  {
    $reader = new MaxMind\Db\Reader($path);
    $type = $reader->metadata()->databaseType;
    $reader->close();
  }
  catch (Throwable $e)
  {
    return 'This isn\'t a MaxMind database (' . $e->getMessage() . '), so no locations are shown.';
  }
  return strpos($type, 'City') === false ? 'This is a ' . $type . ' database: locations show the country only.' : null;
}

/**
 * ip => "City, CC" (or "CC" when the database has no city) from the local
 * GeoIP database. Private and reserved addresses are skipped; addresses the
 * database doesn't know are absent. Nothing leaves the server.
 */
function gallery_pulse_ip_locations($ips)
{
  static $cache = array();

  $reader = gallery_pulse_geoip_reader();
  if ($reader === null)
  {
    return array();
  }
  $locations = array();
  foreach (array_unique($ips) as $ip)
  {
    if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
    {
      continue;
    }
    if (!array_key_exists($ip, $cache))
    {
      $cache[$ip] = null;
      try
      {
        $record = $reader->get($ip);
      }
      catch (Throwable $e)
      {
        $record = null;
      }
      $city = isset($record['city']['names']['en']) && is_string($record['city']['names']['en']) ? trim($record['city']['names']['en']) : '';
      $country = isset($record['country']['iso_code']) && is_string($record['country']['iso_code']) ? $record['country']['iso_code'] : '';
      $parts = array_filter(array($city, $country), 'strlen');
      if (!empty($parts))
      {
        $cache[$ip] = implode(', ', $parts);
      }
    }
    if ($cache[$ip] !== null)
    {
      $locations[$ip] = $cache[$ip];
    }
  }
  return $locations;
}

/**
 * An event's value with its unit, shown after the event name.
 */
function gallery_pulse_value_label($event, $value)
{
  if ($value === null)
  {
    return null;
  }
  if ($event == 'dwell')
  {
    return $value . 's';
  }
  if ($event == 'zoom')
  {
    return $value . '×';
  }
  return (string)$value;
}

function gallery_pulse_decorate_events(&$rows)
{
  $links = gallery_pulse_link_names();
  gallery_pulse_decorate_images($rows);
  gallery_pulse_decorate_categories($rows);
  $locations = gallery_pulse_ip_locations(array_map(function ($r) { return $r['ip']; }, $rows));
  $paths = gallery_pulse_category_paths(array_filter(array_map(function ($r) { return $r['category_id']; }, $rows)));
  foreach ($rows as &$r)
  {
    // The album the event is filed under (the one being viewed, since 1.2),
    // linked to Photos filtered to it; no link once the album is deleted.
    $r['album_path'] = $r['category_id'] !== null && isset($paths[$r['category_id']]) ? $paths[$r['category_id']] : null;
    $r['album_url'] = $r['album_path'] === null ? null
      : GALLERY_PULSE_ADMIN . '-photos&cat=' . (int)$r['category_id'] . '&days=' . gallery_pulse_retention_days();
    $r['id'] = (int)$r['id'];
    $r['guest'] = substr($r['sid'], 0, 8);
    $r['link_name'] = $r['share_token'] !== null && isset($links[$r['share_token']]) ? $links[$r['share_token']] : null;
    $r['value'] = $r['value'] === null ? null : round((float)$r['value'], 2);
    $r['value_label'] = gallery_pulse_value_label($r['event'], $r['value']);
    $r['ip_location'] = $r['ip'] !== null && isset($locations[$r['ip']]) ? $locations[$r['ip']] : null;
    // occurred_at stays raw UTC: the Live poll sorts on it.
    $r['occurred_at_display'] = gallery_pulse_format_time($r['occurred_at']);
  }
  unset($r);
}

/**
 * Events matching the Live filter. Default: the newest $limit events. With $poll: the next $limit events after
 * since_id (0 on an empty table), oldest first, so a poll that hits the limit
 * is continued by the next one instead of skipping rows. With before_id: the
 * $limit events just older than it, for paging back.
 */
function gallery_pulse_recent_events($state, $since_id, $limit, $before_id = 0, $poll = false)
{
  if ($before_id > 0)
  {
    $where = 'e.id < ' . (int)$before_id;
    $order = 'DESC';
  }
  else
  {
    $where = 'e.id > ' . (int)$since_id;
    $order = $poll ? 'ASC' : 'DESC';
  }
  $query = '
SELECT e.id, e.occurred_at, e.sid, e.share_token, e.image_id, e.category_id, e.event, e.value, e.detail, e.device, e.ip
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . $where . '
    AND ' . gallery_pulse_where($state) . '
  ORDER BY e.id ' . $order . '
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
SELECT id, occurred_at, sid, share_token, image_id, category_id, event, value, detail, device, ip
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
    'name' => 'name',
    'views' => 'views',
    'page_views' => 'page_views',
    'guests' => 'guests',
    'dwell' => 'dwell_total',
    'zooms' => 'zooms',
    'max_zoom' => 'max_zoom',
    'saves' => 'saves',
    'downloads' => 'downloads',
    'dwell_avg' => 'dwell_avg',
  );
}

/**
 * The top rows by $sort: sorting happens here, not in the page, so a sort
 * reaches photos beyond the first page of another order.
 *
 * @param int|null $image_id a single photo, for the detail view
 */
function gallery_pulse_photo_stats($state, $sort, $dir = 'desc', $image_id = null)
{
  $sorts = gallery_pulse_photo_sorts();
  $order = isset($sorts[$sort]) ? $sorts[$sort] : 'views';
  $dir = $dir == 'asc' ? 'ASC' : 'DESC';
  $dwell = 'COALESCE(SUM(CASE WHEN e.event = \'dwell\' THEN e.value END), 0)';

  // Wrapped so the sort can put blanks last in either direction.
  $query = '
SELECT * FROM (
SELECT e.image_id,
    MAX(COALESCE(NULLIF(i.name, \'\'), i.file)) AS name,
    ' . gallery_pulse_sql_views() . ' AS views,
    SUM(e.event = \'page_view\' AND e.detail = \'photo\') AS page_views,
    ' . gallery_pulse_sql_guests() . ' AS guests,
    ' . $dwell . ' AS dwell_total,
    SUM(e.event = \'zoom\') AS zooms,
    MAX(CASE WHEN e.event = \'zoom\' THEN e.value END) AS max_zoom,
    SUM(e.event = \'save_intent\') AS saves,
    SUM(e.event = \'download\') AS downloads,
    ' . $dwell . ' / NULLIF(' . gallery_pulse_sql_views() . ', 0) AS dwell_avg
  FROM ' . GALLERY_PULSE_TABLE . ' e
    LEFT JOIN ' . IMAGES_TABLE . ' i ON i.id = e.image_id
  WHERE ' . gallery_pulse_where($state) . '
    AND ' . ($image_id === null ? 'e.image_id IS NOT NULL' : 'e.image_id = ' . (int)$image_id) . '
  GROUP BY e.image_id
) t
  ORDER BY t.' . $order . ' IS NULL, t.' . $order . ' ' . $dir . ', t.views DESC, t.image_id
  LIMIT ' . GALLERY_PULSE_TABLE_ROWS . '
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

/**
 * user_id => username for registered users; the shared guest account is left out.
 */
function gallery_pulse_usernames($user_ids)
{
  global $conf;

  $ids = array();
  foreach ($user_ids as $id)
  {
    if ($id !== null && (int)$id > 0 && (int)$id != $conf['guest_id'])
    {
      $ids[] = (int)$id;
    }
  }
  if (empty($ids))
  {
    return array();
  }
  return query2array('
SELECT ' . $conf['user_fields']['id'] . ' AS id, ' . $conf['user_fields']['username'] . ' AS username
  FROM ' . USERS_TABLE . '
  WHERE ' . $conf['user_fields']['id'] . ' IN (' . implode(',', array_unique($ids)) . ')
;', 'id', 'username');
}

/**
 * The most recently active browsers matching the filter, each with the IP it
 * was last seen from and the albums it viewed, latest first.
 */
function gallery_pulse_visitors($state)
{
  $query = '
SELECT e.sid,
    MIN(e.occurred_at) AS first_seen,
    MAX(e.occurred_at) AS last_seen,
    MAX(e.user_id) AS user_id,
    MAX(e.device) AS device,
    COUNT(DISTINCT e.image_id) AS photos,
    SUM(e.event = \'zoom\') AS zooms,
    SUM(e.event = \'save_intent\') AS saves,
    SUM(e.event = \'download\') AS downloads,
    COALESCE(SUM(CASE WHEN e.event = \'dwell\' THEN e.value END), 0) AS dwell_total,
    SUBSTRING_INDEX(GROUP_CONCAT(e.ip ORDER BY e.occurred_at DESC, e.id DESC), \',\', 1) AS last_ip
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($state) . '
  GROUP BY e.sid
  ORDER BY last_seen DESC
  LIMIT ' . GALLERY_PULSE_TABLE_ROWS . '
;';
  $rows = query2array($query);
  if (empty($rows))
  {
    return $rows;
  }

  // Albums each visitor reached, most recently viewed first.
  $sids = array_map(function ($r) { return '\'' . pwg_db_real_escape_string($r['sid']) . '\''; }, $rows);
  $visited = array();
  foreach (query2array('
SELECT e.sid, e.category_id, MAX(e.occurred_at) AS last_seen
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($state) . '
    AND e.sid IN (' . implode(',', $sids) . ')
    AND e.category_id IS NOT NULL
  GROUP BY e.sid, e.category_id
  ORDER BY last_seen DESC, e.category_id
;') as $v)
  {
    $visited[$v['sid']][] = (int)$v['category_id'];
  }
  $all = array();
  foreach ($visited as $cats)
  {
    $all = array_merge($all, $cats);
  }
  $paths = gallery_pulse_category_paths($all);
  $users = gallery_pulse_usernames(array_map(function ($r) { return $r['user_id']; }, $rows));
  $locations = gallery_pulse_ip_locations(array_map(function ($r) { return $r['last_ip']; }, $rows));

  foreach ($rows as &$r)
  {
    $r['ip_location'] = $r['last_ip'] !== null && isset($locations[$r['last_ip']]) ? $locations[$r['last_ip']] : null;
    $r['guest'] = $r['sid'] === GALLERY_PULSE_NO_SID ? 'no beacon' : substr($r['sid'], 0, 8);
    $r['username'] = $r['user_id'] !== null && isset($users[$r['user_id']]) ? $users[$r['user_id']] : null;
    $names = array();
    foreach (isset($visited[$r['sid']]) ? $visited[$r['sid']] : array() as $c)
    {
      if (isset($paths[$c]))
      {
        $names[] = $paths[$c];
      }
    }
    // The latest few inline; the rest behind "+N more".
    $r['albums_shown'] = array_slice($names, 0, GALLERY_PULSE_VISITOR_ALBUMS);
    $r['albums_more'] = max(0, count($names) - GALLERY_PULSE_VISITOR_ALBUMS);
    $r['albums_rest'] = implode("\n", array_slice($names, GALLERY_PULSE_VISITOR_ALBUMS));
    $r['dwell_total'] = round((float)$r['dwell_total']);
    $r['first_seen_display'] = gallery_pulse_format_time($r['first_seen']);
    $r['last_seen_display'] = gallery_pulse_format_time($r['last_seen']);
  }
  unset($r);
  return $rows;
}

/**
 * Username behind a browser, if any of its events came from a registered user.
 */
function gallery_pulse_visitor_username($sid)
{
  list($user_id) = pwg_db_fetch_row(pwg_query('
SELECT MAX(user_id) FROM ' . GALLERY_PULSE_TABLE . ' WHERE sid = \'' . pwg_db_real_escape_string($sid) . '\'
;'));
  $users = gallery_pulse_usernames(array($user_id));
  return $user_id !== null && isset($users[$user_id]) ? $users[$user_id] : null;
}

/**
 * One row per album events were filed under (the album the guest was looking
 * at, for events since 1.2), with the routes that reached it: each share link,
 * or "direct" for public pages and logged-in users.
 */
function gallery_pulse_album_stats($state)
{
  $query = '
SELECT e.category_id,
    ' . gallery_pulse_sql_guests() . ' AS guests,
    SUM(e.event = \'page_view\' AND e.detail = \'album\') AS album_views,
    ' . gallery_pulse_sql_views() . ' AS views,
    SUM(e.event = \'page_view\' AND e.detail = \'photo\') AS page_views,
    SUM(e.event = \'zoom\') AS zooms,
    SUM(e.event = \'save_intent\') AS saves,
    SUM(e.event = \'download\') AS downloads,
    MAX(e.occurred_at) AS last_seen
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($state) . '
  GROUP BY e.category_id
  ORDER BY last_seen DESC
;';
  $rows = query2array($query);

  $routes = array();
  foreach (query2array('
SELECT e.category_id, e.share_token, COUNT(*) AS n
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($state) . '
  GROUP BY e.category_id, e.share_token
  ORDER BY n DESC
;') as $route)
  {
    $routes[(string)$route['category_id']][] = $route['share_token'];
  }

  $paths = gallery_pulse_category_paths(array_filter(array_map(function ($r) { return $r['category_id']; }, $rows)));
  $links = gallery_pulse_link_names();
  foreach ($rows as &$r)
  {
    $r['album_name'] = $r['category_id'] !== null && isset($paths[$r['category_id']]) ? $paths[$r['category_id']] : null;
    $via = array();
    foreach (isset($routes[(string)$r['category_id']]) ? $routes[(string)$r['category_id']] : array() as $token)
    {
      $via[] = $token === null ? 'direct' : 'link: ' . (isset($links[$token]) ? $links[$token] : $token);
    }
    $r['via'] = implode(', ', $via);
    $r['last_seen_display'] = gallery_pulse_format_time($r['last_seen']);
  }
  unset($r);
  return $rows;
}

/**
 * Everything for one photo's detail view, or null if the photo doesn't exist.
 * Only the filter's period applies: the detail is the whole story of one photo.
 */
function gallery_pulse_photo_detail($image_id, $state)
{
  $state = array_merge($state, array('cat' => null, 'ev' => array(), 'guest' => ''));
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

  $totals = gallery_pulse_photo_stats($state, 'views', 'desc', $image_id);

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
  WHERE ' . gallery_pulse_where($state) . '
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
    $g['last_seen_display'] = gallery_pulse_format_time($g['last_seen']);
  }
  unset($g);

  $timeline = query2array('
SELECT e.id, e.occurred_at, e.sid, e.share_token, e.image_id, e.category_id, e.event, e.value, e.detail, e.device, e.ip
  FROM ' . GALLERY_PULSE_TABLE . ' e
  WHERE ' . gallery_pulse_where($state) . '
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
    AND ' . gallery_pulse_period_where($state, 'TIMESTAMP(date, time)') . '
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
    'oldest' => gallery_pulse_format_time($oldest),
    'size_mb' => round((int)$bytes / 1048576, 1),
  );
}

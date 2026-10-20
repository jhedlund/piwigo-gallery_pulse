<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

define('GALLERY_PULSE_RETENTION_DEFAULT', 730);
define('GALLERY_PULSE_RETENTION_MIN', 30);
define('GALLERY_PULSE_RETENTION_MAX', 3650);
define('GALLERY_PULSE_MAX_BATCH', 50);
// Beacon events may be backdated by up to MAX_AGE seconds (queued in the
// browser), so the rate windows cover MAX_AGE plus one minute.
define('GALLERY_PULSE_MAX_AGE', 600);
define('GALLERY_PULSE_MAX_PER_SID', 600);
define('GALLERY_PULSE_MAX_GLOBAL', 20000);
// Stored for downloads made without a beacon cookie (no JS, cookie blocked, direct link).
define('GALLERY_PULSE_NO_SID', str_repeat('0', 32));

/**
 * Events the public beacon may send. 'download' is server-side only.
 * Each maps to the detail values it accepts; null = no detail.
 */
function gallery_pulse_client_events()
{
  return array(
    'page_view' => array('album', 'photo'),
    'lightbox_view' => array(null, 'autoplay'),
    'dwell' => array(null),
    'zoom' => array(null),
    'fullscreen' => array(null),
    'save_intent' => array('contextmenu', 'longpress', 'drag'),
  );
}

/**
 * Days of events to keep, from the plugin settings.
 */
function gallery_pulse_retention_days()
{
  global $conf;

  $days = isset($conf['gallery_pulse_retention_days']) ? (int)$conf['gallery_pulse_retention_days'] : 0;
  if ($days < GALLERY_PULSE_RETENTION_MIN || $days > GALLERY_PULSE_RETENTION_MAX)
  {
    return GALLERY_PULSE_RETENTION_DEFAULT;
  }
  return $days;
}

function gallery_pulse_valid_sid($sid)
{
  return is_string($sid) && preg_match('/^[a-f0-9]{32}$/', $sid);
}

function gallery_pulse_device()
{
  $device = get_device();
  return $device == 'mobile' ? 'phone' : $device;
}

/**
 * Resolve an UnlistedAlbum share key to the categories it grants.
 *
 * @return array|false array('category_id' => int, 'categories' => int[]) or false
 */
function gallery_pulse_token_scope($token)
{
  static $cache = array();

  if (!is_string($token) || !preg_match('/^[A-Za-z0-9]{8,64}$/', $token) || !defined('UNLISTED_ALBUM_TABLE'))
  {
    return false;
  }
  if (isset($cache[$token]))
  {
    return $cache[$token];
  }

  $query = '
SELECT category_id
  FROM ' . UNLISTED_ALBUM_TABLE . '
  WHERE random_key = \'' . pwg_db_real_escape_string($token) . '\'
    AND is_active = 1
  LIMIT 1
;';
  $rows = query2array($query);
  if (empty($rows))
  {
    return $cache[$token] = false;
  }

  $category_id = (int)$rows[0]['category_id'];
  $categories = array_map('intval', array_merge(array($category_id), get_subcat_ids(array($category_id))));

  return $cache[$token] = array(
    'category_id' => $category_id,
    'categories' => array_values(array_unique($categories)),
  );
}

/**
 * Resolve UnlistedAlbum's c-xxxxxxxx sub-album token (the same hash
 * UnlistedAlbum builds) within the link's own categories. Falls back to the
 * link's album.
 */
function gallery_pulse_scope_category($scope, $token, $cat_token)
{
  if (is_string($cat_token) && preg_match('/^c-[a-f0-9]{8}$/', $cat_token))
  {
    foreach ($scope['categories'] as $cat_id)
    {
      if ('c-' . substr(md5($token . 'cat' . $cat_id), 0, 8) === $cat_token)
      {
        return $cat_id;
      }
    }
  }
  return $scope['category_id'];
}

/**
 * Whether a category exists and the current visitor may see it.
 */
function gallery_pulse_visible_category($category_id)
{
  $query = '
SELECT id
  FROM ' . CATEGORIES_TABLE . '
  WHERE id = ' . (int)$category_id . '
' . get_sql_condition_FandF(array('forbidden_categories' => 'id'), '    AND') . '
  LIMIT 1
;';
  return pwg_db_num_rows(pwg_query($query)) > 0;
}

/**
 * For each image id, the category it is filed under — restricted to the share
 * link's categories when a scope is given, otherwise to what the visitor may
 * see. Images not found are absent.
 *
 * @return array image_id => category_id
 */
function gallery_pulse_image_categories($image_ids, $scope)
{
  $image_ids = array_values(array_unique(array_map('intval', $image_ids)));
  if (empty($image_ids))
  {
    return array();
  }

  $query = '
SELECT image_id, MIN(category_id) AS category_id
  FROM ' . IMAGE_CATEGORY_TABLE . '
  WHERE image_id IN (' . implode(',', $image_ids) . ')';
  if ($scope)
  {
    $query .= '
    AND category_id IN (' . implode(',', $scope['categories']) . ')';
  }
  else
  {
    $query .= '
' . get_sql_condition_FandF(array('forbidden_categories' => 'category_id'), '    AND');
  }
  $query .= '
  GROUP BY image_id
;';

  return array_map('intval', query2array($query, 'image_id', 'category_id'));
}

/**
 * Insert already-validated rows. Every value is an int, a float, null, or a
 * string that passed a strict pattern; strings are escaped regardless.
 */
function gallery_pulse_insert($rows)
{
  if (empty($rows))
  {
    return;
  }

  $values = array();
  foreach ($rows as $r)
  {
    $values[] = '('
      . 'DATE_SUB(NOW(), INTERVAL ' . (int)$r['age'] . ' SECOND),'
      . gallery_pulse_sql_value($r['sid']) . ','
      . gallery_pulse_sql_value($r['user_id']) . ','
      . gallery_pulse_sql_value($r['share_token']) . ','
      . gallery_pulse_sql_value($r['image_id']) . ','
      . gallery_pulse_sql_value($r['category_id']) . ','
      . gallery_pulse_sql_value($r['event']) . ','
      . gallery_pulse_sql_value($r['value']) . ','
      . gallery_pulse_sql_value($r['detail']) . ','
      . gallery_pulse_sql_value($r['device'])
      . ')';
  }

  pwg_query('
INSERT INTO ' . GALLERY_PULSE_TABLE . '
  (occurred_at, sid, user_id, share_token, image_id, category_id, event, value, detail, device)
  VALUES ' . implode(",\n    ", $values) . '
;');
}

function gallery_pulse_sql_value($v)
{
  if ($v === null)
  {
    return 'NULL';
  }
  if (is_int($v))
  {
    return (string)$v;
  }
  if (is_float($v))
  {
    return sprintf('%.4F', $v);
  }
  return '\'' . pwg_db_real_escape_string($v) . '\'';
}

/**
 * A per-browser cap, plus a global one because a forger can mint a fresh sid
 * per request. Both sit far above this gallery's real traffic.
 */
function gallery_pulse_rate_limited($sid)
{
  $window = 'occurred_at > DATE_SUB(NOW(), INTERVAL ' . (GALLERY_PULSE_MAX_AGE + 60) . ' SECOND)';

  list($count) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM ' . GALLERY_PULSE_TABLE . '
  WHERE sid = \'' . pwg_db_real_escape_string($sid) . '\'
    AND ' . $window . '
;'));
  if ($count >= GALLERY_PULSE_MAX_PER_SID)
  {
    return true;
  }

  list($count) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*) FROM ' . GALLERY_PULSE_TABLE . ' WHERE ' . $window . '
;'));
  return $count >= GALLERY_PULSE_MAX_GLOBAL;
}

/**
 * Drop events past the retention window, at most once a day. Piwigo has no
 * scheduler, so this rides on incoming beacon traffic.
 */
function gallery_pulse_maybe_prune()
{
  global $conf;

  $today = date('Y-m-d');
  if (isset($conf['gallery_pulse_last_prune']) && $conf['gallery_pulse_last_prune'] == $today)
  {
    return;
  }
  conf_update_param('gallery_pulse_last_prune', $today, true);

  pwg_query('
DELETE FROM ' . GALLERY_PULSE_TABLE . '
  WHERE occurred_at < DATE_SUB(NOW(), INTERVAL ' . gallery_pulse_retention_days() . ' DAY)
;');
}

/**
 * Validate a beacon batch and turn it into insertable rows. Pure apart from the
 * image/category lookups, so the ws handler stays thin.
 *
 * @param array $events decoded JSON from the beacon
 * @return array rows for gallery_pulse_insert()
 */
function gallery_pulse_build_rows($sid, $token, $events)
{
  global $user;

  $allowed = gallery_pulse_client_events();
  $scope = gallery_pulse_token_scope($token);
  $events = array_slice(array_values(array_filter($events, 'is_array')), 0, GALLERY_PULSE_MAX_BATCH);

  $image_ids = array();
  foreach ($events as $ev)
  {
    if (isset($ev['img']) && is_numeric($ev['img']) && $ev['img'] > 0)
    {
      $image_ids[] = (int)$ev['img'];
    }
  }
  $image_cats = gallery_pulse_image_categories($image_ids, $scope);

  $device = gallery_pulse_device();
  $rows = array();
  foreach ($events as $ev)
  {
    $event = isset($ev['e']) ? $ev['e'] : null;
    if (!is_string($event) || !isset($allowed[$event]))
    {
      continue;
    }

    $detail = isset($ev['d']) ? $ev['d'] : null;
    if (!in_array($detail, $allowed[$event], true))
    {
      continue;
    }

    $image_id = null;
    $category_id = null;
    if (isset($ev['img']) && $ev['img'] !== null)
    {
      $image_id = (int)$ev['img'];
      if (!isset($image_cats[$image_id]))
      {
        continue; // unknown image, or not part of this share link
      }
      $category_id = $image_cats[$image_id];
    }

    // An album page_view names its album: by c-token under a share link (the
    // page never prints the id there), by numeric id otherwise.
    if ($event == 'page_view' && $image_id === null)
    {
      if ($scope)
      {
        $category_id = gallery_pulse_scope_category($scope, $token, isset($ev['ctok']) ? $ev['ctok'] : null);
      }
      elseif (isset($ev['cat']) && is_int($ev['cat']) && $ev['cat'] > 0 && gallery_pulse_visible_category($ev['cat']))
      {
        $category_id = $ev['cat'];
      }
    }

    if ($event != 'page_view' && $image_id === null)
    {
      continue; // everything but page_view is about a photo
    }
    if ($event == 'page_view' && $image_id === null && $category_id === null)
    {
      continue;
    }

    $value = null;
    if (isset($ev['v']) && is_numeric($ev['v']))
    {
      $value = (float)$ev['v'];
    }
    if ($event == 'dwell')
    {
      if ($value === null || $value < 0.5)
      {
        continue;
      }
      $value = min($value, 1800.0);
    }
    elseif ($event == 'zoom')
    {
      if ($value === null || $value <= 1.0)
      {
        continue;
      }
      $value = min($value, 50.0);
    }
    else
    {
      $value = null;
    }

    $age = isset($ev['ago']) && is_numeric($ev['ago']) ? (int)round($ev['ago'] / 1000) : 0;

    $rows[] = array(
      'age' => max(0, min($age, GALLERY_PULSE_MAX_AGE)),
      'sid' => $sid,
      'user_id' => isset($user['id']) ? (int)$user['id'] : null,
      'share_token' => $scope ? $token : null,
      'image_id' => $image_id,
      'category_id' => $category_id,
      'event' => $event,
      'value' => $value,
      'detail' => $detail,
      'device' => $device,
    );
  }

  return $rows;
}

/**
 * loc_begin_page_tail: add the beacon and its page context. Admins are not
 * tracked so Jeff's own browsing never lands in the numbers.
 */
function gallery_pulse_inject_beacon()
{
  global $template, $page, $conf;

  if (is_admin() || !isset($template))
  {
    return;
  }

  $script = script_basename();
  if (!in_array($script, array('index', 'picture')))
  {
    return;
  }

  // UnlistedAlbum keeps category ids out of /v/ pages; don't print one back.
  $unlisted = isset($GLOBALS['unlisted_album_context']);

  $context = array(
    'ws' => get_root_url() . 'ws.php?format=json',
    'cookiePath' => cookie_path(),
    'secure' => (bool)(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'page' => $script == 'picture' ? 'photo' : 'album',
    'cat' => (!$unlisted && isset($page['category']['id'])) ? (int)$page['category']['id'] : null,
    'img' => ($script == 'picture' && isset($page['image_id'])) ? (int)$page['image_id'] : null,
  );

  $template->func_combine_script(array(
    'id' => 'gallery_pulse',
    'load' => 'footer',
    'path' => GALLERY_PULSE_PATH . 'js/beacon.js',
    'version' => '1.0.0',
  ));
  $template->block_footer_script(
    array('require' => 'gallery_pulse'),
    'window.GalleryPulse && window.GalleryPulse.start(' . json_encode($context) . ');'
  );
}

/**
 * loc_action_before_http_headers: log an explicit download of a photo.
 * action.php has already checked the guest may see this image, but the hook
 * fires before its 404 and 304 branches, so those are screened out here.
 */
function gallery_pulse_log_download()
{
  global $file;

  if (!isset($_GET['download']) || !isset($_GET['part']) || !in_array($_GET['part'], array('e', 'f')) || is_admin())
  {
    return;
  }
  if ($_SERVER['REQUEST_METHOD'] == 'HEAD' || isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
  {
    return;
  }
  if (empty($file) || (!url_is_remote($file) && !@is_readable($file)))
  {
    return;
  }

  $image_id = (int)$_GET['id'];
  $sid = isset($_COOKIE['pulse_sid']) ? stripslashes($_COOKIE['pulse_sid']) : '';
  if (!gallery_pulse_valid_sid($sid))
  {
    $sid = GALLERY_PULSE_NO_SID;
  }

  // The theme's mobile share flow can fetch the same file twice for one tap
  // (fetch, then a fallback download when sharing fails).
  list($recent) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*)
  FROM ' . GALLERY_PULSE_TABLE . '
  WHERE sid = \'' . pwg_db_real_escape_string($sid) . '\'
    AND image_id = ' . $image_id . '
    AND event = \'download\'
    AND occurred_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
;'));
  if ($recent > 0)
  {
    return;
  }

  // UnlistedAlbum restores the share link's context for action.php from the session.
  $token = null;
  if (isset($GLOBALS['unlisted_album_context']['random_key']))
  {
    $token = $GLOBALS['unlisted_album_context']['random_key'];
  }
  $scope = gallery_pulse_token_scope($token);
  $cats = gallery_pulse_image_categories(array($image_id), $scope);
  if (!isset($cats[$image_id]))
  {
    // In scope of no link we know about: still a download, just not attributable.
    $scope = false;
    $cats = gallery_pulse_image_categories(array($image_id), false);
  }

  global $user;
  gallery_pulse_insert(array(array(
    'age' => 0,
    'sid' => $sid,
    'user_id' => isset($user['id']) ? (int)$user['id'] : null,
    'share_token' => $scope ? $token : null,
    'image_id' => $image_id,
    'category_id' => isset($cats[$image_id]) ? $cats[$image_id] : null,
    'event' => 'download',
    'value' => null,
    'detail' => $_GET['part'] == 'f' ? 'format' : null,
    'device' => gallery_pulse_device(),
  )));
}

<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

check_status(ACCESS_ADMINISTRATOR);

include_once(PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php');
include_once(GALLERY_PULSE_PATH . 'include/stats.inc.php');

$tabs = array(
  'live' => 'Live',
  'photos' => 'Photos',
  'visitors' => 'Visitors',
  'albums' => 'Albums',
  'settings' => 'Settings',
);
$tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : 'live';
// Links became Albums in 1.2; keep old bookmarks working.
if ($tab == 'links')
{
  $tab = 'albums';
}
// One photo's detail view lives under the Photos tab.
if (!isset($tabs[$tab]) && $tab != 'photo')
{
  $tab = 'live';
}

if ($tab == 'settings' && isset($_POST['save']))
{
  check_pwg_token();
  $retention = isset($_POST['retention_days']) ? (int)$_POST['retention_days'] : 0;
  $timezone = isset($_POST['timezone']) && is_string($_POST['timezone']) ? $_POST['timezone'] : '';
  $geoip = isset($_POST['geoip_path']) && is_string($_POST['geoip_path']) ? trim($_POST['geoip_path']) : '';
  if ($retention < GALLERY_PULSE_RETENTION_MIN || $retention > GALLERY_PULSE_RETENTION_MAX)
  {
    $page['errors'][] = 'Retention must be between ' . GALLERY_PULSE_RETENTION_MIN . ' and ' . GALLERY_PULSE_RETENTION_MAX . ' days.';
  }
  elseif (!in_array($timezone, DateTimeZone::listIdentifiers(), true))
  {
    $page['errors'][] = 'Unknown time zone.';
  }
  elseif ($geoip !== '' && (strlen($geoip) > 255 || !preg_match('~^/[A-Za-z0-9._/-]+$~', $geoip)))
  {
    $page['errors'][] = 'The GeoIP database path must be an absolute path of letters, digits and . _ - / (or blank to turn locations off).';
  }
  else
  {
    conf_update_param('gallery_pulse_retention_days', $retention, true);
    conf_update_param('gallery_pulse_timezone', $timezone, true);
    conf_update_param('gallery_pulse_geoip_path', $geoip, true);
    $page['infos'][] = 'Settings saved.';
  }
}

$tabsheet = new tabsheet();
$tabsheet->set_id('gallery_pulse');
foreach ($tabs as $code => $label)
{
  $tabsheet->add($code, $label, GALLERY_PULSE_ADMIN . '-' . $code);
}
$tabsheet->select($tab == 'photo' ? 'photos' : $tab);
$tabsheet->assign();

// Filters (Live, Photos, Visitors, Albums) stick per tab: a URL carrying any
// filter value sets the tab's whole filter and saves it; a bare tab link brings
// back the last one. The visitor timeline and photo detail aren't filtered
// tabs; the detail takes its period from the link.
$filter_keys = array('f', 'days', 'from', 'to', 'cat', 'ev', 'guest', 'sort', 'dir');
$filtered = in_array($tab, array('live', 'photos', 'visitors', 'albums')) && !isset($_GET['sid']);
$albums = $filtered ? gallery_pulse_album_options() : array();
$input = array();
if ($filtered && isset($_GET['reset']))
{
  gallery_pulse_save_filter($tab, null);
}
elseif (!$filtered || count(array_intersect($filter_keys, array_keys($_GET))) > 0)
{
  $input = $_GET;
  if (isset($input['guest']) && is_string($input['guest']))
  {
    $input['guest'] = stripslashes($input['guest']); // Piwigo adds slashes to $_GET
  }
}
else
{
  $input = gallery_pulse_saved_filter($tab);
}
$state = gallery_pulse_filter_state($input, $tab, $filtered ? $albums : null);

// Photos sorts on the server (it shows the top rows of a sort); the sort
// sticks with the filter.
$photo_sorts = gallery_pulse_photo_sorts();
$state['sort'] = isset($input['sort']) && is_string($input['sort']) && isset($photo_sorts[$input['sort']]) ? $input['sort'] : 'views';
$state['dir'] = isset($input['dir']) && $input['dir'] === 'asc' ? 'asc' : 'desc';
if ($filtered && !empty($input) && $input !== gallery_pulse_saved_filter($tab))
{
  gallery_pulse_save_filter($tab, $state);
}

$query = gallery_pulse_filter_query($state);
$template->assign(array(
  'GP_TAB' => $tab,
  'GP_BASE' => GALLERY_PULSE_ADMIN . '-' . $tab,
  'GP_ADMIN' => GALLERY_PULSE_ADMIN,
  'GP_PATH' => GALLERY_PULSE_PATH,
  'GP_PERIODS' => gallery_pulse_periods(),
  'GP_F' => $state,
  'GP_FILTERED' => $filtered,
  // Anything off the tab's default, period and Photos sort included: Reset is
  // offered so a sticky filter isn't forgotten.
  'GP_RESETTABLE' => $state != array_merge(gallery_pulse_filter_state(array(), $tab), array('sort' => 'views', 'dir' => 'desc')),
  'GP_QUERY' => $query,
  // The period alone, for links into the photo detail.
  'GP_PERIOD_QUERY' => gallery_pulse_filter_query(array_merge($state, array('cat' => null, 'ev' => array(), 'guest' => ''))),
  // Everything but the album, for an Albums row's links into Photos and Visitors.
  'GP_QUERY_NOCAT' => gallery_pulse_filter_query(array_merge($state, array('cat' => null))),
  'GP_CUSTOM' => $state['days'] === 'custom',
  'GP_EV_LABEL' => implode(', ', $state['ev']),
  'GP_ALBUMS' => $albums,
  'GP_EVENT_OPTIONS' => array_map(function ($n) use ($state) { return in_array($n, $state['ev'], true); },
    array_combine(gallery_pulse_event_names(), gallery_pulse_event_names())),
  'GP_GUESTS' => $filtered ? gallery_pulse_guest_suggestions() : array(),
  'GP_RETENTION' => gallery_pulse_retention_days(),
  'GP_TZ' => gallery_pulse_timezone_label(),
));

switch ($tab)
{
  case 'live':
    $events = gallery_pulse_recent_events($state, 0, GALLERY_PULSE_LIVE_PAGE);
    $template->assign(array(
      'GP_EVENTS' => $events,
      'GP_LIVE_PAGE' => GALLERY_PULSE_LIVE_PAGE,
      'GP_LIVE_MORE' => count($events) >= GALLERY_PULSE_LIVE_PAGE,
      'GP_LIVE_POLL' => GALLERY_PULSE_LIVE_POLL,
      'GP_LIVE_MAX_ROWS' => GALLERY_PULSE_LIVE_MAX_ROWS,
      'GP_WS' => get_root_url() . 'ws.php?format=json&method=galleryPulse.live&filter='
        . rawurlencode(json_encode(array_diff_key($state, array('sort' => 1, 'dir' => 1)))),
    ));
    break;

  case 'photos':
    $template->assign(array(
      'GP_SORTS' => array(
        'name' => 'Photo',
        'views' => 'Views',
        'page_views' => 'Photo page',
        'guests' => 'Guests',
        'dwell' => 'Dwell (total s)',
        'zooms' => 'Zooms',
        'max_zoom' => 'Max zoom',
        'saves' => 'Save intent',
        'downloads' => 'Downloads',
        'dwell_avg' => 'Avg dwell (s)',
      ),
      'GP_PHOTOS' => gallery_pulse_photo_stats($state, $state['sort'], $state['dir']),
      'GP_ROWS' => GALLERY_PULSE_TABLE_ROWS,
    ));
    break;

  case 'photo':
    $image_id = isset($_GET['image_id']) ? (int)$_GET['image_id'] : 0;
    $template->assign(array(
      'GP_IMAGE_ID' => $image_id,
      'GP_PHOTO' => $image_id > 0 ? gallery_pulse_photo_detail($image_id, $state) : null,
    ));
    break;

  case 'visitors':
    if (isset($_GET['sid']) && gallery_pulse_valid_sid($_GET['sid']))
    {
      $template->assign(array(
        'GP_SID' => $_GET['sid'],
        'GP_USERNAME' => gallery_pulse_visitor_username($_GET['sid']),
        'GP_TIMELINE' => gallery_pulse_visitor_timeline($_GET['sid']),
      ));
    }
    else
    {
      $template->assign(array(
        'GP_VISITORS' => gallery_pulse_visitors($state),
        'GP_ROWS' => GALLERY_PULSE_TABLE_ROWS,
      ));
    }
    break;

  case 'albums':
    $template->assign('GP_ALBUM_STATS', gallery_pulse_album_stats($state));
    break;

  case 'settings':
    $template->assign(array(
      'GP_TOKEN' => get_pwg_token(),
      'GP_RETENTION_MIN' => GALLERY_PULSE_RETENTION_MIN,
      'GP_RETENTION_MAX' => GALLERY_PULSE_RETENTION_MAX,
      'GP_TIMEZONES' => DateTimeZone::listIdentifiers(),
      'GP_TIMEZONE' => gallery_pulse_timezone()->getName(),
      'GP_TABLE' => gallery_pulse_table_info(),
      'GP_GEOIP_PATH' => gallery_pulse_geoip_path(),
      'GP_GEOIP_PROBLEM' => gallery_pulse_geoip_problem(gallery_pulse_geoip_path()),
    ));
    break;
}

$template->set_filename('plugin_admin_content', dirname(__FILE__) . '/admin/template/admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');

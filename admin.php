<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

check_status(ACCESS_ADMINISTRATOR);

include_once(PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php');
include_once(GALLERY_PULSE_PATH . 'include/stats.inc.php');

$tabs = array(
  'live' => 'Live',
  'photos' => 'Photos',
  'visitors' => 'Visitors',
  'links' => 'Links',
  'settings' => 'Settings',
);
// One photo's detail view lives under the Photos tab.
$tab = isset($_GET['tab']) && is_string($_GET['tab']) && (isset($tabs[$_GET['tab']]) || $_GET['tab'] == 'photo') ? $_GET['tab'] : 'live';

if ($tab == 'settings' && isset($_POST['save']))
{
  check_pwg_token();
  $retention = isset($_POST['retention_days']) ? (int)$_POST['retention_days'] : 0;
  if ($retention < GALLERY_PULSE_RETENTION_MIN || $retention > GALLERY_PULSE_RETENTION_MAX)
  {
    $page['errors'][] = 'Retention must be between ' . GALLERY_PULSE_RETENTION_MIN . ' and ' . GALLERY_PULSE_RETENTION_MAX . ' days.';
  }
  else
  {
    conf_update_param('gallery_pulse_retention_days', $retention, true);
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

$retention = gallery_pulse_retention_days();
$periods = array(1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days');
if ($retention > 365)
{
  $periods[365] = 'Last 365 days';
}
$periods[$retention] = 'All (' . $retention . ' days)';
$days = isset($_GET['days']) && isset($periods[(int)$_GET['days']]) ? (int)$_GET['days'] : ($tab == 'photo' ? $retention : 30);

$token = null;
if (isset($_GET['link']) && is_string($_GET['link']) && preg_match('/^[A-Za-z0-9]{8,64}$/', $_GET['link']))
{
  $token = $_GET['link'];
}

$link_names = gallery_pulse_link_names();
natcasesort($link_names);

$template->assign(array(
  'GP_TAB' => $tab,
  'GP_BASE' => GALLERY_PULSE_ADMIN . '-' . $tab,
  'GP_ADMIN' => GALLERY_PULSE_ADMIN,
  'GP_PATH' => GALLERY_PULSE_PATH,
  'GP_PERIODS' => $periods,
  'GP_DAYS' => $days,
  'GP_LINKS' => $link_names,
  'GP_LINK' => $token,
  'GP_RETENTION' => $retention,
));

switch ($tab)
{
  case 'live':
    $events = gallery_pulse_recent_events(0, GALLERY_PULSE_LIVE_PAGE);
    $template->assign(array(
      'GP_EVENTS' => $events,
      'GP_LIVE_PAGE' => GALLERY_PULSE_LIVE_PAGE,
      'GP_LIVE_MORE' => count($events) >= GALLERY_PULSE_LIVE_PAGE,
      'GP_LIVE_POLL' => GALLERY_PULSE_LIVE_POLL,
      'GP_LIVE_MAX_ROWS' => GALLERY_PULSE_LIVE_MAX_ROWS,
      'GP_WS' => get_root_url() . 'ws.php?format=json&method=galleryPulse.live',
    ));
    break;

  case 'photos':
    $albums = gallery_pulse_album_options();
    $cat = isset($_GET['cat']) && isset($albums[(int)$_GET['cat']]) ? (int)$_GET['cat'] : null;
    $cat_ids = $cat === null ? null : array_merge(array($cat), array_map('intval', get_subcat_ids(array($cat))));
    $sort = isset($_GET['sort']) && is_string($_GET['sort']) && array_key_exists($_GET['sort'], gallery_pulse_photo_sorts()) ? $_GET['sort'] : 'views';
    $template->assign(array(
      'GP_ALBUMS' => $albums,
      'GP_CAT' => $cat,
      'GP_SORT' => $sort,
      'GP_PHOTOS' => gallery_pulse_photo_stats($days, $cat_ids, $sort),
    ));
    break;

  case 'photo':
    $image_id = isset($_GET['image_id']) ? (int)$_GET['image_id'] : 0;
    $template->assign(array(
      'GP_IMAGE_ID' => $image_id,
      'GP_PHOTO' => $image_id > 0 ? gallery_pulse_photo_detail($image_id, $days) : null,
    ));
    break;

  case 'visitors':
    if (isset($_GET['sid']) && gallery_pulse_valid_sid($_GET['sid']))
    {
      $template->assign(array(
        'GP_SID' => $_GET['sid'],
        'GP_TIMELINE' => gallery_pulse_visitor_timeline($_GET['sid']),
      ));
    }
    else
    {
      $template->assign('GP_VISITORS', gallery_pulse_visitors($days, $token));
    }
    break;

  case 'links':
    $template->assign('GP_LINK_STATS', gallery_pulse_link_stats($days));
    break;

  case 'settings':
    $template->assign(array(
      'GP_TOKEN' => get_pwg_token(),
      'GP_RETENTION_MIN' => GALLERY_PULSE_RETENTION_MIN,
      'GP_RETENTION_MAX' => GALLERY_PULSE_RETENTION_MAX,
      'GP_TABLE' => gallery_pulse_table_info(),
    ));
    break;
}

$template->set_filename('plugin_admin_content', dirname(__FILE__) . '/admin/template/admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');

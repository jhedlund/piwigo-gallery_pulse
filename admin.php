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
);
$tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'live';

$tabsheet = new tabsheet();
$tabsheet->set_id('gallery_pulse');
foreach ($tabs as $code => $label)
{
  $tabsheet->add($code, $label, GALLERY_PULSE_ADMIN . '-' . $code);
}
$tabsheet->select($tab);
$tabsheet->assign();

$periods = array(1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days', GALLERY_PULSE_RETENTION_DAYS => 'Last ' . GALLERY_PULSE_RETENTION_DAYS . ' days');
$days = isset($_GET['days']) && isset($periods[(int)$_GET['days']]) ? (int)$_GET['days'] : 30;

$token = null;
if (isset($_GET['link']) && preg_match('/^[A-Za-z0-9]{8,64}$/', $_GET['link']))
{
  $token = $_GET['link'];
}

$link_names = gallery_pulse_link_names();
asort($link_names);

$template->assign(array(
  'GP_TAB' => $tab,
  'GP_BASE' => GALLERY_PULSE_ADMIN . '-' . $tab,
  'GP_ADMIN' => GALLERY_PULSE_ADMIN,
  'GP_PATH' => GALLERY_PULSE_PATH,
  'GP_PERIODS' => $periods,
  'GP_DAYS' => $days,
  'GP_LINKS' => $link_names,
  'GP_LINK' => $token,
  'GP_RETENTION' => GALLERY_PULSE_RETENTION_DAYS,
));

switch ($tab)
{
  case 'live':
    $events = gallery_pulse_recent_events(0, 100);
    $template->assign(array(
      'GP_EVENTS' => $events,
      'GP_WS' => get_root_url() . 'ws.php?format=json&method=galleryPulse.live',
    ));
    break;

  case 'photos':
    $sort = isset($_GET['sort']) && array_key_exists($_GET['sort'], gallery_pulse_photo_sorts()) ? $_GET['sort'] : 'views';
    $template->assign(array(
      'GP_SORT' => $sort,
      'GP_PHOTOS' => gallery_pulse_photo_stats($days, $token, $sort),
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
}

$template->set_filename('plugin_admin_content', dirname(__FILE__) . '/admin/template/admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');

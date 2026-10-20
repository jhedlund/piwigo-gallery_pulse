<?php
/*
Plugin Name: Gallery Pulse
Version: 1.5.0
Description: Engagement analytics for shared albums — lightbox views, dwell, zoom, save intent and downloads, per share link and per guest.
Plugin URI: https://jhedlund.com
Author: jhedlund
Author URI: https://jhedlund.com
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'gallery_pulse')
{
  add_event_handler('init', 'gallery_pulse_error');
  function gallery_pulse_error()
  {
    global $page;
    $page['errors'][] = 'Gallery Pulse folder name is incorrect, uninstall the plugin and rename it to "gallery_pulse"';
  }
  return;
}

global $prefixeTable;

define('GALLERY_PULSE_PATH', PHPWG_PLUGINS_PATH . 'gallery_pulse/');
define('GALLERY_PULSE_ADMIN', get_root_url() . 'admin.php?page=plugin-gallery_pulse');
define('GALLERY_PULSE_TABLE', $prefixeTable . 'pulse_event');

include_once(GALLERY_PULSE_PATH . 'include/functions.inc.php');

add_event_handler('ws_add_methods', 'gallery_pulse_ws_add_methods');

if (defined('IN_ADMIN'))
{
  add_event_handler('get_admin_plugin_menu_links', 'gallery_pulse_admin_menu');
}
else
{
  // Inject the beacon on every public page that renders a footer.
  add_event_handler('loc_begin_page_tail', 'gallery_pulse_inject_beacon');

  // Downloads are logged server-side: core history rows for action.php carry no
  // category and no share link, so they can't be attributed per link.
  add_event_handler('loc_action_before_http_headers', 'gallery_pulse_log_download');

  // Batch Downloader zips: one download per photo. Never fires without it.
  add_event_handler('batchdownload_end_zip', 'gallery_pulse_log_zip');
}

function gallery_pulse_admin_menu($menu)
{
  $menu[] = array(
    'URL' => GALLERY_PULSE_ADMIN,
    'NAME' => 'Gallery Pulse',
  );
  return $menu;
}

function gallery_pulse_ws_add_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'galleryPulse.track',
    'gallery_pulse_ws_track',
    array(
      'sid' => array(),
      'token' => array('default' => null),
      'events' => array(),
      'touch' => array('default' => null),
    ),
    'Gallery Pulse: record a batch of engagement events from the public beacon.',
    GALLERY_PULSE_PATH . 'include/ws_functions.inc.php',
    array('post_only' => true, 'hidden' => true)
  );

  $service->addMethod(
    'galleryPulse.live',
    'gallery_pulse_ws_live',
    array(
      'since_id' => array('default' => 0, 'type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'before_id' => array('default' => 0, 'type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
    ),
    'Gallery Pulse: latest events for the admin Live view.',
    GALLERY_PULSE_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'hidden' => true)
  );
}

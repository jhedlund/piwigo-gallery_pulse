<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * galleryPulse.track — public, called by js/beacon.js via sendBeacon.
 * Bad input is dropped quietly (the beacon can't act on errors); only a
 * malformed request gets an error response.
 */
function gallery_pulse_ws_track($params, &$service)
{
  if (is_admin())
  {
    return array('stored' => 0);
  }

  $sid = stripslashes($params['sid']);
  if (!gallery_pulse_valid_sid($sid))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid sid');
  }

  $events = json_decode(stripslashes($params['events']), true);
  if (!is_array($events))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid events');
  }

  if (gallery_pulse_rate_limited($sid))
  {
    return array('stored' => 0);
  }

  $token = isset($params['token']) ? stripslashes($params['token']) : null;
  $rows = gallery_pulse_build_rows($sid, $token, $events);
  gallery_pulse_insert($rows);
  gallery_pulse_maybe_prune();

  return array('stored' => count($rows));
}

/**
 * galleryPulse.live — admin only, polled by the Live tab.
 */
function gallery_pulse_ws_live($params, &$service)
{
  include_once(GALLERY_PULSE_PATH . 'include/stats.inc.php');

  $before_id = (int)$params['before_id'];
  return array(
    'events' => $before_id > 0
      ? gallery_pulse_recent_events(0, GALLERY_PULSE_LIVE_PAGE, $before_id)
      : gallery_pulse_recent_events((int)$params['since_id'], GALLERY_PULSE_LIVE_POLL, 0, true),
  );
}

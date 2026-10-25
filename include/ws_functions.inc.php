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
  // Touch-screen hint, only used to tell an iPad from a Mac.
  $touch = isset($params['touch']) && in_array($params['touch'], array('0', '1'), true) ? $params['touch'] === '1' : null;
  $rows = gallery_pulse_build_rows($sid, $token, $events, $touch);
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

  // The page's filter (JSON), validated again here.
  $filter = is_string($params['filter']) ? json_decode(stripslashes($params['filter']), true) : null;
  $state = gallery_pulse_filter_state($filter, 'live');
  $before_id = (int)$params['before_id'];
  return array(
    'events' => $before_id > 0
      ? gallery_pulse_recent_events($state, 0, GALLERY_PULSE_LIVE_PAGE, $before_id)
      : gallery_pulse_recent_events($state, (int)$params['since_id'], GALLERY_PULSE_LIVE_POLL, 0, true),
  );
}

<?php
/*
 * Fires Batch Downloader's batchdownload_end_zip trigger with given sets, as a
 * guest, optionally inside a share link's context. Lets the e2e test album
 * attribution for set types the UI can't easily produce.
 *
 *   php tests/zip_harness.php '<json [[set, image_ids], ...]>' [share-token]
 *
 * Run from the Piwigo root inside the dev container.
 */
if (PHP_SAPI !== 'cli')
{
  exit;
}
define('PHPWG_ROOT_PATH', './');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/photos/index.php';
$_SERVER['REQUEST_URI'] = '/photos/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTP_USER_AGENT'] = 'zip-harness';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
include(PHPWG_ROOT_PATH . 'include/common.inc.php');

if (!empty($argv[2]))
{
  $GLOBALS['unlisted_album_context'] = array('random_key' => $argv[2]);
}
foreach (json_decode($argv[1], true) as $call)
{
  trigger_notify('batchdownload_end_zip', $call[0], $call[1]);
}
echo "done\n";

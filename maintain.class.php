<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class gallery_pulse_maintain extends PluginMaintain
{
  private $table;

  function __construct($plugin_id)
  {
    global $prefixeTable;
    parent::__construct($plugin_id);
    $this->table = $prefixeTable . 'pulse_event';
  }

  function install($plugin_version, &$errors=array())
  {
    pwg_query('
CREATE TABLE IF NOT EXISTS `' . $this->table . '` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `occurred_at` DATETIME NOT NULL,
  `sid` CHAR(32) NOT NULL,
  `user_id` MEDIUMINT UNSIGNED NULL,
  `share_token` VARCHAR(64) NULL,
  `image_id` MEDIUMINT UNSIGNED NULL,
  `category_id` SMALLINT UNSIGNED NULL,
  `event` ENUM(\'page_view\',\'lightbox_view\',\'dwell\',\'zoom\',\'fullscreen\',\'save_intent\',\'download\') NOT NULL,
  `value` FLOAT NULL,
  `detail` VARCHAR(16) NULL,
  `device` VARCHAR(16) NULL,
  `ip` VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  KEY `k_img` (`image_id`, `event`),
  KEY `k_sid` (`sid`, `occurred_at`),
  KEY `k_time` (`occurred_at`),
  KEY `k_token` (`share_token`, `occurred_at`),
  KEY `k_cat` (`category_id`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

    // 1.5 added the visitor's IP; rows from before it keep NULL.
    if (pwg_db_num_rows(pwg_query('SHOW COLUMNS FROM `' . $this->table . '` LIKE \'ip\';')) == 0)
    {
      pwg_query('ALTER TABLE `' . $this->table . '` ADD `ip` VARCHAR(45) NULL AFTER `device`;');
    }
    // 1.6 lists the Album filter's choices on every page load: the index makes
    // that a scan of distinct albums rather than of every event.
    if (pwg_db_num_rows(pwg_query('SHOW INDEX FROM `' . $this->table . '` WHERE Key_name = \'k_cat\';')) == 0)
    {
      pwg_query('ALTER TABLE `' . $this->table . '` ADD KEY `k_cat` (`category_id`, `occurred_at`);');
    }
  }

  function activate($plugin_version, &$errors=array())
  {
    $this->install($plugin_version, $errors);
  }

  function update($old_version, $new_version, &$errors=array())
  {
    $this->install($new_version, $errors);
  }

  function deactivate()
  {
  }

  function uninstall()
  {
    pwg_query('DROP TABLE IF EXISTS `' . $this->table . '`;');
    conf_delete_param(array('gallery_pulse_last_prune', 'gallery_pulse_retention_days', 'gallery_pulse_timezone', 'gallery_pulse_geoip_path'));
  }
}

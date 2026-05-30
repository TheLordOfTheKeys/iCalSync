#!/usr/bin/env php
<?php
/**
 * Standalone cron trigger for iCalSync.
 * Called directly from system crontab — bootstraps FacturaScripts and runs sync.
 *
 * Usage: php /var/www/html/planeta/Plugins/ICalSync/cron-trigger.php
 */

// Find FS root
$fsRoot = dirname(__DIR__, 2); // Plugins/ICalSync → planéta

// Bootstrap FacturaScripts
define('FS_FOLDER', $fsRoot);
require $fsRoot . '/vendor/autoload.php';
require $fsRoot . '/config.php';

// Check frequency
$frequency = (int) (\FacturaScripts\Core\Tools::settings('icalsync', 'sync_frequency_minutes', 15));
$lastRunFile = __DIR__ . '/last_cron_run.txt';
$lastRun = file_exists($lastRunFile) ? file_get_contents($lastRunFile) : '';
if (!empty($lastRun) && (time() - strtotime(trim($lastRun))) < ($frequency * 60)) {
    exit(0);
}

// Register autoloader for bundled sabre/dav
require __DIR__ . '/Vendor/Autoloader.php';
\FacturaScripts\Plugins\ICalSync\Vendor\Autoloader::register();

// Check if any account is enabled
$db = new \FacturaScripts\Core\Base\DataBase();
if (!$db->tableExists('icalsync_accounts')) {
    exit(0);
}
$rows = $db->select('SELECT id FROM icalsync_accounts WHERE enabled = 1 LIMIT 1');
if (empty($rows)) {
    exit(0); // No enabled accounts
}

// Run sync
file_put_contents(__DIR__ . '/last_cron_run.txt', date('Y-m-d H:i:s'));
\FacturaScripts\Plugins\ICalSync\CronJob\ICalSync::run();

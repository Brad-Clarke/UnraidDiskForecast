#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * Cron entry point, run every 15 minutes and when the array's disks are mounted: takes the
 * readings that are due, saves pending readings to the flash drive once a day, then sends
 * any warnings. With --save (run when the array stops) it only saves pending readings.
 * Run it by hand to see what it does:
 *   /usr/local/emhttp/plugins/diskforecast/scripts/sample.php [--save]
 */

require dirname(__DIR__) . '/include/bootstrap.php';

use DiskForecast\Composition;

$app = Composition::production();

set_exception_handler(static function (Throwable $e) use ($app): void {
    $app->logger()->warning('Reading run failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
});

$saveOnly = in_array('--save', $argv, true);
$runtime = $app->runtimeDir();
if (!is_dir($runtime)) {
    mkdir($runtime, 0777, true);
}

$lock = fopen("{$runtime}/sample.lock", 'c');
$locked = $lock !== false && flock($lock, LOCK_EX | LOCK_NB);
if (!$locked && !$saveOnly) {
    echo "Another reading run is still going; skipped.\n";
    exit(0);
}

for ($wait = 0; !$locked && $wait < 30; $wait++) {
    sleep(1);
    $locked = flock($lock, LOCK_EX | LOCK_NB);
}

$now = time();
$store = $app->historyStore();
if ($saveOnly) {
    echo $store->save($now) ? "Pending readings saved to the flash drive.\n" : "Some readings could not be saved; see the system log.\n";
    exit(0);
}

foreach ($app->sampler()->run($now) as $line) {
    echo $line, "\n";
}

if ($store->saveDue($now, Composition::SAVE_INTERVAL_SECONDS)) {
    echo $store->save($now) ? "Pending readings saved to the flash drive.\n" : "Some readings could not be saved; see the system log.\n";
}

$app->warningMonitor()->check($app->settings(), $now);

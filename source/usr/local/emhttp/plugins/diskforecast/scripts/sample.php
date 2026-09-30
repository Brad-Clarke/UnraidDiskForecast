<?php

declare(strict_types=1);

/**
 * Cron entry point, run every 15 minutes: takes the readings that are due, then sends
 * any warnings. Run it by hand to see what it does:
 *   php /usr/local/emhttp/plugins/diskforecast/scripts/sample.php
 */

require dirname(__DIR__) . '/include/bootstrap.php';

$app = DiskForecast\Composition::production();

set_exception_handler(static function (Throwable $e) use ($app): void {
    $app->logger()->warning('Reading run failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
});

$lockDir = '/tmp/diskforecast';
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0777, true);
}

$lock = fopen("{$lockDir}/sample.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another reading run is still going; skipped.\n";
    exit(0);
}

$now = time();
foreach ($app->sampler()->run($now) as $line) {
    echo $line, "\n";
}

$settings = $app->settings();
if ($app->platform()->pathAvailable($settings->dataDir)) {
    $app->warningMonitor()->check($settings, $now);
}

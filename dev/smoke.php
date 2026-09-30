<?php

declare(strict_types=1);

/**
 * Runs the cron path (readings then warnings) and the Unraid file parsing end to end,
 * locally, against the fake server and the fixture files in dev/fixtures/unraid.
 *
 *   php dev/smoke.php
 */

require __DIR__ . '/bootstrap.php';

use DiskForecast\Composition;
use DiskForecast\Config\Settings;
use DiskForecast\Config\SettingsStore;
use DiskForecast\Config\Target;
use DiskForecast\Config\TargetType;
use DiskForecast\Dev\Preview\ConsoleLogger;
use DiskForecast\Dev\Preview\ConsoleNotifier;
use DiskForecast\Dev\Preview\FakePlatform;
use DiskForecast\HistoryStore;
use DiskForecast\Sample;
use DiskForecast\Storage\UnraidPlatform;

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

$root = sys_get_temp_dir() . '/diskforecast-smoke-' . getmypid();
$logger = new ConsoleLogger();
$notifier = new ConsoleNotifier();
$store = new SettingsStore("{$root}/settings.json", $logger);
$store->save(new Settings([
    new Target('array', 'Array', TargetType::Array, [], 60, 180, 3650),
    new Target('media', 'Media', TargetType::Share, ['Media'], 15, 30, 0),
]));
$app = new Composition(new FakePlatform("{$root}/data"), $store, "{$root}/cache", $logger, $notifier);

$now = 1_800_000_000;
$report = $app->sampler()->run($now);
$check(str_contains($report[0], 'recorded') && str_contains($report[1], 'recorded'), 'first run records both targets');
$check(str_contains($app->sampler()->run($now + 600)[0], 'not due'), 'a reading 10 minutes later is not due');
$check(str_contains($app->sampler()->run($now + 3600 - 60)[0], 'recorded'), 'a reading one minute early still counts');

$history = new HistoryStore("{$root}/data", $logger);
$check($history->read('array')->count() === 2, 'array history holds two readings');
$check($history->lastTime('array') === $now + 3540, 'last reading time is read from the end of the file');
$latest = $history->read('array')->latest();
$check($latest->size === 112_000_000_000_000, 'array size is the six data disks added together');

for ($day = 1; $day <= 30; $day++) {
    $used = $latest->used + $day * 500_000_000_000;
    $history->append('array', new Sample($now + $day * 86400, $latest->size, $used, $latest->size - $used));
}

$app->warningMonitor()->check($app->settings(), $now + 31 * 86400);
$check(count($notifier->sent) === 1 && $notifier->sent[0][2] === 'warning', 'a warning is sent when the estimate is inside the threshold');
$app->warningMonitor()->check($app->settings(), $now + 32 * 86400);
$check(count($notifier->sent) === 1, 'no repeat within a week');
$app->warningMonitor()->check($app->settings(), $now + 39 * 86400);
$check(count($notifier->sent) === 2, 'repeats after a week');

$fixtures = __DIR__ . '/fixtures/unraid';
$unraid = new UnraidPlatform(
    "{$fixtures}/disks.ini",
    "{$fixtures}/pools",
    "{$fixtures}/shares.ini",
    "{$fixtures}/shares",
    "{$fixtures}/share.cfg",
    "{$fixtures}/mounts",
);
$units = array_map(static fn ($u): string => $u->name . ':' . $u->kind->value, $unraid->units());
$check($units === ['disk1:disk', 'disk2:disk', 'disk3:disk', 'disk10:disk', 'cache:pool', 'nvme:pool'], 'disks and pools parsed, parity/flash/empty slots skipped: ' . implode(' ', $units));
$shares = [];
foreach ($unraid->shares() as $share) {
    $shares[$share->name] = implode(',', $share->units);
}

$check(($shares['Media'] ?? '') === 'disk1,disk2,cache', 'Media: included disks plus its cache pool (' . ($shares['Media'] ?? '-') . ')');
$check(($shares['appdata'] ?? '') === 'cache', 'appdata: pool only (' . ($shares['appdata'] ?? '-') . ')');
$check(($shares['Backups'] ?? '') === 'disk2,disk3', 'Backups: all disks minus excluded and globally excluded (' . ($shares['Backups'] ?? '-') . ')');
$check(($shares['fast'] ?? '') === 'cache,nvme', 'fast: pool with a secondary pool, no array (' . ($shares['fast'] ?? '-') . ')');
$check($unraid->pathAvailable('/mnt/cache/appdata/diskforecast'), 'a folder on a mounted pool is available');
$check(!$unraid->pathAvailable('/mnt/user/appdata/diskforecast'), 'a folder under an unmounted /mnt/user is not');
$check($unraid->dataDir() === '/mnt/user/appdata/diskforecast', 'with no appdata folder on a pool, readings go to /mnt/user/appdata');

$remove = static function (string $dir) use (&$remove): void {
    foreach (glob("{$dir}/*") ?: [] as $entry) {
        is_dir($entry) ? $remove($entry) : unlink($entry);
    }

    @rmdir($dir);
};
$remove($root);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);

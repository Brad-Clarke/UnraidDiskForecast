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
use DiskForecast\Config\Target;
use DiskForecast\Config\TargetType;
use DiskForecast\Dev\Preview\ConsoleLogger;
use DiskForecast\Dev\Preview\ConsoleNotifier;
use DiskForecast\Dev\Preview\FakePlatform;
use DiskForecast\Dev\Preview\PreviewData;
use DiskForecast\Sample;
use DiskForecast\Storage\StorageUnit;
use DiskForecast\Storage\UnitKind;
use DiskForecast\Storage\UnraidPlatform;

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

$root = sys_get_temp_dir() . '/diskforecast-smoke-' . getmypid();
$logger = new ConsoleLogger();
$notifier = new ConsoleNotifier();
$store = PreviewData::settingsStore($root, $logger);
$check($store->load() === null, 'with only default.cfg, the plugin is on automatic targets');
$fresh = (new Composition(new FakePlatform(), $store, $root, "{$root}/runtime", $logger, $notifier))->settings()->toArray()['targets'];
$baseline = ['intervalMinutes' => 60, 'windowDays' => 180, 'warnDays' => 90, 'onDashboard' => true];
$check($fresh === [
    ['id' => 'array', 'name' => 'Array', 'type' => 'array', 'members' => []] + $baseline,
    ['id' => 'pool-cache', 'name' => 'Cache', 'type' => 'disks', 'members' => ['cache']] + $baseline,
    ['id' => 'pool-nvme', 'name' => 'Nvme', 'type' => 'disks', 'members' => ['nvme']] + $baseline,
], 'a fresh install tracks the array and each pool: hourly, 180-day trend, warning at 90 days, on the dashboard');
$saved = new Settings([
    new Target('array', 'Array', TargetType::Array, [], 60, 180, 3650),
    new Target('media', 'Media', TargetType::Share, ['Media'], 15, 30, 0),
    new Target('fast', 'Fast "pools"', TargetType::Disks, ['cache', 'nvme'], 30, 0, 90, false),
]);
$store->save($saved);
$loaded = $store->load();
$expected = $saved->toArray();
$expected['targets'][2]['name'] = 'Fast pools';
$check($loaded !== null && $loaded->toArray() === $expected, 'settings round-trip through diskforecast.cfg (quotes dropped from names)');
$cfgText = (string) file_get_contents("{$root}/diskforecast.cfg");
$check(str_contains($cfgText, 'TARGET_3_MEMBERS="cache,nvme"') && str_contains($cfgText, 'TARGET_3_DASHBOARD="no"'), 'the file is plain key="value" lines, including the dashboard choice');
file_put_contents("{$root}/diskforecast.cfg", str_replace('TARGET_1_DASHBOARD="yes"' . "\n", '', $cfgText));
$check($store->load()?->targets[0]->onDashboard === true, 'a target saved without the dashboard choice is shown on the dashboard');

$dashboardTile = static function (Composition $dfApp): bool {
    $mytiles = [];
    $docroot = dirname(__DIR__) . '/src/usr/local/emhttp';
    $display = ['theme' => 'black'];
    $page = str_replace("\r\n", "\n", (string) file_get_contents("{$docroot}/plugins/diskforecast/DiskForecastDashboard.page"));
    ob_start();
    eval('?>' . substr($page, strpos($page, "\n---\n") + 5));
    ob_end_clean();

    return isset($mytiles['diskforecast']['column2']);
};
$tileApp = static fn (): Composition => new Composition(new FakePlatform(), $store, $root, "{$root}/runtime", $logger, $notifier);
$check($dashboardTile($tileApp()), 'the dashboard tile is rendered while any target is shown');
$store->save(new Settings([new Target('fast', 'Fast', TargetType::Disks, ['cache'], 30, 0, 0, false)]));
$check(!$dashboardTile($tileApp()), 'the dashboard tile is left out when every target is hidden');
$store->save(new Settings(array_slice($saved->targets, 0, 2)));
$app = new Composition(new FakePlatform(), $store, $root, "{$root}/runtime", $logger, $notifier);

$now = 1_800_000_000;
$report = $app->sampler()->run($now);
$check(str_contains($report[0], 'recorded') && str_contains($report[1], 'recorded'), 'first run records both targets');
$check(str_contains($app->sampler()->run($now + 600)[0], 'not due'), 'a reading 10 minutes later is not due');
$check(str_contains($app->sampler()->run($now + 3600 - 60)[0], 'recorded'), 'a reading one minute early still counts');

$history = $app->historyStore();
$check($history->read('array')->count() === 2, 'array history holds two readings');
$check(!is_file($history->savedPath('array')), 'readings wait in RAM: nothing is written to the flash drive yet');
$check($history->lastTime('array') === $now + 3540, 'last reading time is read from the end of the pending file');
$check(!$history->saveDue($now, Composition::SAVE_INTERVAL_SECONDS), 'the first check after a boot only starts the save clock');
$check(!$history->saveDue($now + 3600, Composition::SAVE_INTERVAL_SECONDS), 'no save is due an hour later');
$check($history->saveDue($now + 86400, Composition::SAVE_INTERVAL_SECONDS), 'a save is due a day later');
$check($history->save($now + 86400) && !$history->saveDue($now + 86400, Composition::SAVE_INTERVAL_SECONDS), 'saving restarts the clock');
$savedText = (string) file_get_contents($history->savedPath('array'));
$check(substr_count($savedText, "\n") === 3 && str_starts_with($savedText, "time,size,used,free\n"), 'saving moves both readings onto the flash drive, with the header once');
$check($history->read('array')->count() === 2 && $history->lastTime('array') === $now + 3540, 'after a save the history reads the same, from the flash file');
$check(str_contains($app->sampler()->run($now + 7200)[0], 'recorded') && $history->read('array')->count() === 3, 'new readings pend again and read together with the saved ones');
$history->save($now + 7300);
$check(substr_count((string) file_get_contents($history->savedPath('array')), "\n") === 4, 'the next save appends only the new reading');
$check($history->save($now + 7400), 'saving with nothing pending succeeds and writes nothing');
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
$check($unraid->space(new StorageUnit('disk4', UnitKind::Disk, '/mnt/disk4')) === null, 'an unmounted disk is never measured');

$history->delete('array');
$check(!is_file($history->savedPath('array')) && $history->read('array')->count() === 0, 'deleting a target removes its saved and pending readings');

$remove = static function (string $dir) use (&$remove): void {
    foreach (glob("{$dir}/*") ?: [] as $entry) {
        is_dir($entry) ? $remove($entry) : unlink($entry);
    }

    @rmdir($dir);
};
$remove($root);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);

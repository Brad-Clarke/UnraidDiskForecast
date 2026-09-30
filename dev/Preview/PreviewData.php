<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Preview;

use DiskForecast\Config\Settings;
use DiskForecast\Config\SettingsStore;
use DiskForecast\Config\Target;
use DiskForecast\Config\TargetType;
use DiskForecast\Dev\Synthetic\Scenario;
use DiskForecast\Dev\Synthetic\ScenarioLibrary;
use DiskForecast\History;
use DiskForecast\HistoryFile;
use DiskForecast\Logging\Logger;
use DiskForecast\Sample;
use DiskForecast\Storage\Inventory;

/**
 * Writes the preview's settings and histories: the fake scenarios, moved so their newest
 * reading is the current hour, one per kind of state the pages have to show.
 */
final class PreviewData
{
    private const DAY = 86400;

    private function __construct()
    {
    }

    /**
     * Creates the preview data under the folder unless it is already there.
     */
    public static function ensure(string $root): void
    {
        if (is_file("{$root}/diskforecast.cfg")) {
            return;
        }

        @mkdir("{$root}/history", 0777, true);
        $now = intdiv(time(), 3600) * 3600;
        $filling = [];
        foreach (ScenarioLibrary::filling() as $scenario) {
            $filling[$scenario->name] = $scenario;
        }

        $nonFilling = [];
        foreach (ScenarioLibrary::nonFilling() as $scenario) {
            $nonFilling[$scenario->name] = $scenario;
        }

        $plan = [
            [new Target('array', 'Array', TargetType::Array, [], 60, 180, 90), self::beforeFill($filling['regimes'], 480)],
            [new Target('media', 'Media', TargetType::Share, ['Media'], 60, 180, 365), self::beforeFill($filling['accelerating'], 300)],
            [new Target('photos', 'Photos', TargetType::Share, ['Photos'], 60, 90, 60), self::beforeFill($filling['bursty'], 45)],
            [new Target('pool-cache', 'Cache', TargetType::Disks, ['cache'], 30, 30, 0), $nonFilling['cache-churn']->history],
            [new Target('backups', 'Backups', TargetType::Disks, ['disk5', 'disk6'], 360, 180, 0), $nonFilling['flat']->history],
            [new Target('scratch', 'Scratch disk', TargetType::Disks, ['disk6'], 60, 180, 0), self::firstDays($filling['deletes'], 150)],
            [new Target('pool-nvme', 'Nvme', TargetType::Disks, ['nvme'], 60, 180, 0), self::firstDays($filling['steady'], 3)],
        ];

        $inventory = new Inventory(new FakePlatform());
        $targets = [];
        foreach ($plan as [$target, $history]) {
            $capacity = (float) $inventory->spaceFor($target)->size;
            HistoryFile::write("{$root}/history/{$target->id}.csv", self::endingAt($history, $now, $capacity));
            $targets[] = $target;
        }

        self::settingsStore($root, new ConsoleLogger())->save(new Settings($targets));
    }

    /**
     * The settings file under the preview folder, over the plugin's real default.cfg.
     */
    public static function settingsStore(string $root, Logger $logger): SettingsStore
    {
        return new SettingsStore("{$root}/diskforecast.cfg", dirname(__DIR__, 2) . '/src/usr/local/emhttp/plugins/diskforecast/default.cfg', $logger);
    }

    private static function beforeFill(Scenario $scenario, int $days): History
    {
        $cut = (int) $scenario->fillTime - $days * self::DAY;

        return $scenario->history->between(PHP_INT_MIN, $cut);
    }

    private static function firstDays(Scenario $scenario, int $days): History
    {
        $first = $scenario->history->first()->time;

        return $scenario->history->between($first, $first + $days * self::DAY);
    }

    /**
     * The history moved to end at the given time and rescaled to the fake target's capacity.
     */
    private static function endingAt(History $history, int $now, float $capacity): History
    {
        $offset = $now - $history->latest()->time;
        $scale = $capacity / $history->latest()->size;
        $samples = [];
        foreach ($history->samples() as $sample) {
            $samples[] = new Sample(
                $sample->time + $offset,
                (int) round($sample->size * $scale),
                (int) round($sample->used * $scale),
                (int) round($sample->free * $scale),
            );
        }

        return History::fromSamples($samples);
    }
}

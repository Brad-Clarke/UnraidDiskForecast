<?php

declare(strict_types=1);

namespace DiskForecast\Sampling;

use DiskForecast\Config\Settings;
use DiskForecast\HistoryStore;
use DiskForecast\Sample;
use DiskForecast\Storage\Inventory;
use DiskForecast\Storage\Platform;

/**
 * Takes a reading for every target whose interval has passed.
 *
 * Runs from cron every 15 minutes; each target decides for itself whether it is due.
 */
final class Sampler
{
    /** Readings up to this many seconds early still count, so cron jitter does not skip a slot. */
    private const EARLY_TOLERANCE = 120;

    public function __construct(
        private readonly Settings $settings,
        private readonly Platform $platform,
        private readonly Inventory $inventory,
        private readonly HistoryStore $store,
        private readonly string $dataDir,
    ) {
    }

    /**
     * Takes the due readings. Returns one line per target describing what happened.
     *
     * @return list<string>
     */
    public function run(int $now): array
    {
        if (!$this->platform->pathAvailable($this->dataDir)) {
            return ["Readings folder {$this->dataDir} is not available (array stopped?); no readings taken."];
        }

        $report = [];
        foreach ($this->settings->targets as $target) {
            $last = $this->store->lastTime($target->id);
            if ($last !== null && $now - $last < $target->intervalMinutes * 60 - self::EARLY_TOLERANCE) {
                $report[] = "{$target->name}: not due.";
                continue;
            }

            $space = $this->inventory->spaceFor($target);
            if ($space === null) {
                $report[] = "{$target->name}: skipped, some of its storage is not mounted.";
                continue;
            }

            $sample = new Sample($now, $space->size, $space->used(), $space->free);
            $report[] = $this->store->append($target->id, $sample)
                ? "{$target->name}: recorded {$space->used()} of {$space->size} bytes used."
                : "{$target->name}: could not be written.";
        }

        return $report;
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Synthetic;

use DiskForecast\History;

/**
 * A fake target history with a known future, for backtesting and the UI preview.
 */
final class Scenario
{
    /**
     * @param string $name Short identifier, also the preview file name.
     * @param string $description What kind of usage it imitates.
     * @param History $history Hourly readings.
     * @param int|null $fillTime Unix time the target actually fills, or null when it never does.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly History $history,
        public readonly ?int $fillTime,
    ) {
    }
}

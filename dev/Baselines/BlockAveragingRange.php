<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Baselines;

use DiskForecast\History;
use DiskForecast\Points;
use DiskForecast\Range\BlockRates;
use DiskForecast\Range\GrowthRange;
use DiskForecast\Range\LinearGrowthRange;
use DiskForecast\Range\RangeMethod;
use DiskForecast\Stats;
use DiskForecast\Trend\Line;

/**
 * A range centred on the trend whose spread comes from how much block growth varies,
 * narrowing relative to the horizon as more blocks average out.
 */
final class BlockAveragingRange implements RangeMethod
{
    /**
     * @param int $blocks Number of blocks the window is split into.
     * @param float $z Deviations either side of the trend (1.2816 covers the middle 80%).
     */
    public function __construct(
        private readonly int $blocks = 12,
        private readonly float $z = 1.2816,
    ) {
    }

    public function name(): string
    {
        return 'block-averaging';
    }

    public function prepare(History $history, int $now, Points $window, Line $trend): GrowthRange
    {
        $rates = BlockRates::measure($window, $this->blocks);
        $blockSeconds = BlockRates::blockSeconds($window, $this->blocks);
        if (count($rates) < 3 || $blockSeconds <= 0.0) {
            return new LinearGrowthRange($trend->slope, $trend->slope, $trend->slope);
        }

        $growths = array_map(static fn (float $rate): float => $rate * $blockSeconds, $rates);
        [, $deviation] = Stats::meanAndDeviation($growths);

        return new AveragedGrowthRange($trend->slope, $deviation, $blockSeconds, $this->z);
    }
}

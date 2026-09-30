<?php

declare(strict_types=1);

namespace DiskForecast\Range;

use DiskForecast\History;
use DiskForecast\Points;
use DiskForecast\Stats;
use DiskForecast\Trend\Line;

/**
 * Slow and fast cases taken from the slow and fast ends of the window's block rates:
 * "if you keep filling at your slowest normal pace" and "at your fastest normal pace".
 */
final class BlockQuantileRange implements RangeMethod
{
    /**
     * @param int $blocks Number of blocks the window is split into.
     * @param float $slowQuantile Quantile of block rates used for the slow case.
     * @param float $fastQuantile Quantile of block rates used for the fast case.
     */
    public function __construct(
        private readonly int $blocks = 12,
        private readonly float $slowQuantile = 0.1,
        private readonly float $fastQuantile = 0.9,
    ) {
    }

    public function name(): string
    {
        return 'block-quantile';
    }

    public function prepare(History $history, int $now, Points $window, Line $trend): GrowthRange
    {
        $rates = BlockRates::measure($window, $this->blocks);
        if (count($rates) < 3) {
            return new LinearGrowthRange($trend->slope, $trend->slope, $trend->slope);
        }

        sort($rates);
        $slow = min($trend->slope, Stats::quantileOfSorted($rates, $this->slowQuantile));
        $fast = max($trend->slope, Stats::quantileOfSorted($rates, $this->fastQuantile));

        return new LinearGrowthRange($trend->slope, $slow, $fast);
    }
}

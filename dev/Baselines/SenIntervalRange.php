<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Baselines;

use DiskForecast\History;
use DiskForecast\Points;
use DiskForecast\Range\GrowthRange;
use DiskForecast\Range\LinearGrowthRange;
use DiskForecast\Range\RangeMethod;
use DiskForecast\Trend\Line;
use DiskForecast\Trend\TheilSenFit;

/**
 * The textbook confidence interval on a Theil–Sen slope, from the ranks of the pair slopes.
 */
final class SenIntervalRange implements RangeMethod
{
    /**
     * @param float $z Normal quantile for the interval (1.2816 covers the middle 80%).
     */
    public function __construct(private readonly float $z = 1.2816)
    {
    }

    public function name(): string
    {
        return 'sen-interval';
    }

    public function prepare(History $history, int $now, Points $window, Line $trend): GrowthRange
    {
        $slopes = TheilSenFit::pairSlopes($window);
        $pairs = count($slopes);
        $n = $window->count();
        if ($pairs < 3) {
            return new LinearGrowthRange($trend->slope, $trend->slope, $trend->slope);
        }

        $c = $this->z * sqrt($n * ($n - 1) * (2 * $n + 5) / 18);
        $lower = max(0, min($pairs - 1, (int) floor(($pairs - $c) / 2)));
        $upper = max(0, min($pairs - 1, (int) ceil(($pairs + $c) / 2)));

        return new LinearGrowthRange(
            $trend->slope,
            min($trend->slope, $slopes[$lower]),
            max($trend->slope, $slopes[$upper]),
        );
    }
}

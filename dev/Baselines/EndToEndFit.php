<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Baselines;

use DiskForecast\Points;
use DiskForecast\Trend\Line;
use DiskForecast\Trend\TrendFit;

/**
 * The average rate from the first point to the last, ignoring everything between.
 */
final class EndToEndFit implements TrendFit
{
    public function name(): string
    {
        return 'end-to-end';
    }

    public function fit(Points $points): ?Line
    {
        $count = $points->count();
        if ($count < 2 || $points->span() <= 0.0) {
            return null;
        }

        $slope = ($points->values[$count - 1] - $points->values[0]) / $points->span();
        $intercept = $points->values[0] - $slope * $points->times[0];

        return new Line($points->origin, $intercept, $slope);
    }
}

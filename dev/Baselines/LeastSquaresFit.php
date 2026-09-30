<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Baselines;

use DiskForecast\Points;
use DiskForecast\Trend\Line;
use DiskForecast\Trend\TrendFit;

/**
 * Ordinary least-squares line through every point.
 */
final class LeastSquaresFit implements TrendFit
{
    public function name(): string
    {
        return 'least-squares';
    }

    public function fit(Points $points): ?Line
    {
        $count = $points->count();
        if ($count < 2 || $points->span() <= 0.0) {
            return null;
        }

        $meanTime = array_sum($points->times) / $count;
        $meanValue = array_sum($points->values) / $count;
        $covariance = 0.0;
        $variance = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dt = $points->times[$i] - $meanTime;
            $covariance += $dt * ($points->values[$i] - $meanValue);
            $variance += $dt * $dt;
        }

        $slope = $covariance / $variance;

        return new Line($points->origin, $meanValue - $slope * $meanTime, $slope);
    }
}

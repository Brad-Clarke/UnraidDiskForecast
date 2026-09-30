<?php

declare(strict_types=1);

namespace DiskForecast\Trend;

use DiskForecast\Points;
use DiskForecast\Stats;

/**
 * Theil–Sen line: the median of the slopes between every pair of points.
 *
 * A single large copy or delete moves only the pairs that straddle it, so the
 * median barely shifts where a least-squares line would be dragged toward it.
 */
final class TheilSenFit implements TrendFit
{
    public function name(): string
    {
        return 'theil-sen';
    }

    public function fit(Points $points): ?Line
    {
        $slopes = self::pairSlopes($points);
        if ($slopes === []) {
            return null;
        }

        $slope = Stats::quantileOfSorted($slopes, 0.5);
        $residuals = [];
        foreach ($points->times as $i => $time) {
            $residuals[] = $points->values[$i] - $slope * $time;
        }

        return new Line($points->origin, Stats::median($residuals), $slope);
    }

    /**
     * Slopes between every pair of points with distinct times, sorted ascending.
     *
     * @return list<float>
     */
    public static function pairSlopes(Points $points): array
    {
        $times = $points->times;
        $values = $points->values;
        $count = count($times);
        $slopes = [];
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $dt = $times[$j] - $times[$i];
                if ($dt > 0.0) {
                    $slopes[] = ($values[$j] - $values[$i]) / $dt;
                }
            }
        }

        sort($slopes);

        return $slopes;
    }
}

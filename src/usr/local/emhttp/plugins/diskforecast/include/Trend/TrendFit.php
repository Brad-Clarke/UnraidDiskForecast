<?php

declare(strict_types=1);

namespace DiskForecast\Trend;

use DiskForecast\Points;

/**
 * A way of drawing one straight trend through a run of points.
 */
interface TrendFit
{
    /**
     * Short stable name, used in settings and reports.
     */
    public function name(): string;

    /**
     * The trend through the points, or null when there are fewer than two points
     * or they all share one time.
     */
    public function fit(Points $points): ?Line;
}

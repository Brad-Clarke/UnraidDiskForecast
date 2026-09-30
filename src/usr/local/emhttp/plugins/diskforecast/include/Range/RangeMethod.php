<?php

declare(strict_types=1);

namespace DiskForecast\Range;

use DiskForecast\History;
use DiskForecast\Points;
use DiskForecast\Trend\Line;

/**
 * A way of turning a target's history and its trend into a slow-to-fast growth range.
 */
interface RangeMethod
{
    /**
     * Short stable name, used in settings and reports.
     */
    public function name(): string;

    /**
     * The range at the given time.
     *
     * @param History $history The target's whole history; readings after the time must be ignored.
     * @param int $now Unix time the forecast is made for.
     * @param Points $window The trend window, already reduced to points.
     * @param Line $trend The trend already fitted to the window.
     */
    public function prepare(History $history, int $now, Points $window, Line $trend): GrowthRange;
}

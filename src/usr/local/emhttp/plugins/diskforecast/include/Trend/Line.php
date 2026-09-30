<?php

declare(strict_types=1);

namespace DiskForecast\Trend;

/**
 * A straight trend of bytes used against time.
 */
final class Line
{
    /**
     * @param int $origin Unix time the intercept is measured at.
     * @param float $intercept Bytes used at the origin.
     * @param float $slope Bytes per second.
     */
    public function __construct(
        public readonly int $origin,
        public readonly float $intercept,
        public readonly float $slope,
    ) {
    }

    /**
     * Bytes used on the line at the given Unix time.
     */
    public function valueAt(int $time): float
    {
        return $this->intercept + $this->slope * ($time - $this->origin);
    }
}

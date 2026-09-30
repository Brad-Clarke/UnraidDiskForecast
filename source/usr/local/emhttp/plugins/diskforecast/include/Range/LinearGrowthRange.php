<?php

declare(strict_types=1);

namespace DiskForecast\Range;

/**
 * A range that grows at fixed rates, whatever the horizon.
 */
final class LinearGrowthRange implements GrowthRange
{
    /**
     * @param float $rate Expected bytes per second.
     * @param float $slowRate Bytes per second in the slow case.
     * @param float $fastRate Bytes per second in the fast case.
     */
    public function __construct(
        public readonly float $rate,
        public readonly float $slowRate,
        public readonly float $fastRate,
    ) {
    }

    public function estimate(float $seconds): float
    {
        return $this->rate * $seconds;
    }

    public function bounds(float $seconds): array
    {
        return [$this->slowRate * $seconds, $this->fastRate * $seconds];
    }
}

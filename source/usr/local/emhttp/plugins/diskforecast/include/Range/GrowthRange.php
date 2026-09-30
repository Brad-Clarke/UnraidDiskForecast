<?php

declare(strict_types=1);

namespace DiskForecast\Range;

/**
 * How much a target is expected to grow over a stretch of time: an estimate, a slow case and a fast case.
 */
interface GrowthRange
{
    /**
     * The expected growth in bytes over the given number of seconds.
     */
    public function estimate(float $seconds): float;

    /**
     * The slow-case and fast-case growth in bytes over the given number of seconds.
     *
     * @return array{0: float, 1: float} Slow growth, then fast growth.
     */
    public function bounds(float $seconds): array;
}

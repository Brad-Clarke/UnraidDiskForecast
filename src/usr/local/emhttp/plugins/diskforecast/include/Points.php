<?php

declare(strict_types=1);

namespace DiskForecast;

/**
 * A reduced series of (time, used) points ready for fitting.
 *
 * Times are seconds relative to {@see $origin} so that regression sums stay
 * well inside double precision.
 */
final class Points
{
    /**
     * @param int $origin Unix time that relative times are measured from.
     * @param list<float> $times Seconds since the origin, ascending.
     * @param list<float> $values Bytes used at each time.
     */
    public function __construct(
        public readonly int $origin,
        public readonly array $times,
        public readonly array $values,
    ) {
    }

    /**
     * Number of points.
     */
    public function count(): int
    {
        return count($this->times);
    }

    /**
     * Seconds between the first and last point, or zero when there are fewer than two.
     */
    public function span(): float
    {
        $count = $this->count();

        return $count < 2 ? 0.0 : $this->times[$count - 1] - $this->times[0];
    }
}

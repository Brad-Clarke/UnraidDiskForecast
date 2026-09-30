<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Baselines;

use DiskForecast\Range\GrowthRange;

/**
 * A range around a central rate whose spread widens with the square root of the
 * horizon, as it does when many independent blocks of growth are added together.
 */
final class AveragedGrowthRange implements GrowthRange
{
    /**
     * @param float $rate Central bytes per second.
     * @param float $blockDeviation Standard deviation of one block's growth, in bytes.
     * @param float $blockSeconds Length of one block.
     * @param float $z Number of deviations either side of the centre.
     */
    public function __construct(
        public readonly float $rate,
        public readonly float $blockDeviation,
        public readonly float $blockSeconds,
        public readonly float $z,
    ) {
    }

    public function estimate(float $seconds): float
    {
        return $this->rate * $seconds;
    }

    public function bounds(float $seconds): array
    {
        $blocks = $seconds / $this->blockSeconds;
        $spread = $this->z * $this->blockDeviation * ($blocks >= 1.0 ? sqrt($blocks) : $blocks);
        $centre = $this->rate * $seconds;

        return [$centre - $spread, $centre + $spread];
    }
}

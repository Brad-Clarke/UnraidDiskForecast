<?php

declare(strict_types=1);

namespace DiskForecast\Range;

/**
 * An estimate and range built from how far the target's real fill rate strayed from the trend
 * in the past, measured separately for each horizon, widened by a short-term fallback range.
 */
final class HindcastGrowthRange implements GrowthRange
{
    /**
     * @param float $rate Trend bytes per second now.
     * @param array<int, array{0: float, 1: float, 2: float}> $deviations Per horizon in seconds (ascending keys):
     *     the slow-case, typical and fast-case difference between realised and predicted rate, in bytes per second.
     * @param GrowthRange $fallback Range used where nothing was measured, and as the narrowest the range may be.
     */
    public function __construct(
        public readonly float $rate,
        public readonly array $deviations,
        public readonly GrowthRange $fallback,
    ) {
    }

    public function estimate(float $seconds): float
    {
        $deviation = $this->deviationFor($seconds);

        return $deviation === null
            ? $this->fallback->estimate($seconds)
            : ($this->rate + $deviation[1]) * $seconds;
    }

    public function bounds(float $seconds): array
    {
        [$slow, $fast] = $this->fallback->bounds($seconds);
        $estimate = $this->estimate($seconds);
        $deviation = $this->deviationFor($seconds);
        if ($deviation !== null) {
            $slow = min($slow, ($this->rate + $deviation[0]) * $seconds);
            $fast = max($fast, ($this->rate + $deviation[2]) * $seconds);
        }

        return [min($slow, $estimate), max($fast, $estimate)];
    }

    /**
     * The misses at the given horizon: interpolated between the measured horizons either side,
     * held at the nearest one outside them, so projections stay smooth.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function deviationFor(float $seconds): ?array
    {
        $previousHorizon = null;
        $previous = null;
        foreach ($this->deviations as $horizon => $deviation) {
            if ($seconds <= $horizon) {
                if ($previous === null) {
                    return $deviation;
                }

                $weight = ($seconds - $previousHorizon) / ($horizon - $previousHorizon);

                return [
                    $previous[0] + ($deviation[0] - $previous[0]) * $weight,
                    $previous[1] + ($deviation[1] - $previous[1]) * $weight,
                    $previous[2] + ($deviation[2] - $previous[2]) * $weight,
                ];
            }

            $previousHorizon = $horizon;
            $previous = $deviation;
        }

        return $previous;
    }
}

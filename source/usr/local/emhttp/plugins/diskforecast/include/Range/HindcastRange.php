<?php

declare(strict_types=1);

namespace DiskForecast\Range;

use DiskForecast\History;
use DiskForecast\Points;
use DiskForecast\Stats;
use DiskForecast\Trend\Line;
use DiskForecast\Trend\TrendFit;

/**
 * Calibrates the forecast on the target's own past: from many earlier moments it fits the
 * trend as it would have been drawn then and measures how fast the target really filled over
 * each horizon afterwards. The typical miss shifts the estimate (so a target whose growth keeps
 * speeding up is projected to fill sooner) and the spread of misses becomes the range.
 *
 * Until there is enough history to measure a horizon, the fallback range stands alone.
 */
final class HindcastRange implements RangeMethod
{
    private const DAY = 86400;

    /**
     * @param TrendFit $fit The same fit the forecast uses.
     * @param int $windowSeconds The same trend window the forecast uses.
     * @param RangeMethod $fallback Short-term range the hindcast widens.
     * @param list<int> $horizonDays Horizons measured separately.
     * @param int $stepSeconds Spacing between past moments.
     * @param int $minimumSamples Past moments a horizon needs before it is used.
     * @param float $slowQuantile Quantile of misses used for the slow case.
     * @param float $fastQuantile Quantile of misses used for the fast case.
     * @param int $maxPoints Points per past fit; fewer than the forecast's to keep the cost down.
     */
    public function __construct(
        private readonly TrendFit $fit,
        private readonly int $windowSeconds,
        private readonly RangeMethod $fallback,
        private readonly array $horizonDays = [30, 90, 180, 365, 730],
        private readonly int $stepSeconds = 7 * self::DAY,
        private readonly int $minimumSamples = 8,
        private readonly float $slowQuantile = 0.1,
        private readonly float $fastQuantile = 0.9,
        private readonly int $maxPoints = 60,
    ) {
    }

    public function name(): string
    {
        return 'hindcast';
    }

    public function prepare(History $history, int $now, Points $window, Line $trend): GrowthRange
    {
        $fallback = $this->fallback->prepare($history, $now, $window, $trend);
        $first = $history->first();
        if ($first === null) {
            return $fallback;
        }

        $misses = [];
        $slopes = [];
        foreach ($this->horizonDays as $days) {
            $horizon = $days * self::DAY;
            for ($origin = $now - $horizon; $origin - $this->windowSeconds >= $first->time; $origin -= $this->stepSeconds) {
                $slopes[$origin] ??= $this->slopeAt($history, $origin);
                $start = $history->atOrBefore($origin);
                $end = $history->atOrBefore($origin + $horizon);
                if ($slopes[$origin] === null || $start === null || $end === null) {
                    continue;
                }

                $misses[$horizon][] = ($end->used - $start->used) / $horizon - $slopes[$origin];
            }
        }

        $deviations = [];
        foreach ($misses as $horizon => $values) {
            if (count($values) < $this->minimumSamples) {
                continue;
            }

            sort($values);
            $deviations[$horizon] = [
                Stats::quantileOfSorted($values, $this->slowQuantile),
                Stats::quantileOfSorted($values, 0.5),
                Stats::quantileOfSorted($values, $this->fastQuantile),
            ];
        }

        ksort($deviations);

        return new HindcastGrowthRange($trend->slope, $deviations, $fallback);
    }

    private function slopeAt(History $history, int $origin): ?float
    {
        return $this->fit->fit($history->between($origin - $this->windowSeconds, $origin)->toPoints($this->maxPoints))?->slope;
    }
}

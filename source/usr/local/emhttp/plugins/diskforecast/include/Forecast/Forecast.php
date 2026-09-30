<?php

declare(strict_types=1);

namespace DiskForecast\Forecast;

use DiskForecast\Range\GrowthRange;
use DiskForecast\Range\HindcastGrowthRange;
use DiskForecast\Sample;
use DiskForecast\Trend\Line;

/**
 * The outcome of forecasting one target at one moment.
 */
final class Forecast
{
    /**
     * @param ForecastStatus $status Headline state.
     * @param int $time Unix time the forecast was made for.
     * @param Sample|null $latest Newest reading at or before that time.
     * @param Line|null $trend Trend fitted to the window, when there was enough history.
     * @param GrowthRange|null $range Slow-to-fast growth range, when there was a trend.
     * @param float $startUsed Bytes used the projection starts from.
     * @param float $room Bytes of writable space the projection has to fill.
     * @param float|null $secondsToFull Estimated seconds until full, or null when it does not fill within the horizon limit.
     * @param float|null $earliestSeconds Seconds until full in the fast case, or null when it does not fill within the horizon limit.
     * @param float|null $latestSeconds Seconds until full in the slow case, or null when it does not fill within the horizon limit.
     */
    public function __construct(
        public readonly ForecastStatus $status,
        public readonly int $time,
        public readonly ?Sample $latest,
        public readonly ?Line $trend,
        public readonly ?GrowthRange $range,
        public readonly float $startUsed,
        public readonly float $room,
        public readonly ?float $secondsToFull,
        public readonly ?float $earliestSeconds,
        public readonly ?float $latestSeconds,
    ) {
    }

    /**
     * Whether the estimate and range are calibrated on the target's own past,
     * rather than drawn from the trend window alone.
     */
    public function calibrated(): bool
    {
        return $this->range instanceof HindcastGrowthRange && $this->range->deviations !== [];
    }

    /**
     * Estimated fill rate in bytes per second, or null when there is no trend.
     */
    public function ratePerSecond(): ?float
    {
        return $this->trend?->slope;
    }

    /**
     * Projected bytes used after the given number of seconds: estimate, slow case, fast case.
     * Null when there is no trend.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function projection(float $seconds): ?array
    {
        if ($this->trend === null || $this->range === null) {
            return null;
        }

        [$slow, $fast] = $this->range->bounds($seconds);

        return [
            $this->startUsed + $this->range->estimate($seconds),
            $this->startUsed + $slow,
            $this->startUsed + $fast,
        ];
    }
}

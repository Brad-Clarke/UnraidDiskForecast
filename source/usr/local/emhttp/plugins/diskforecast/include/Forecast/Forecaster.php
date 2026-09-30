<?php

declare(strict_types=1);

namespace DiskForecast\Forecast;

use DiskForecast\History;
use DiskForecast\Range\BlockQuantileRange;
use DiskForecast\Range\HindcastRange;
use DiskForecast\Range\RangeMethod;
use DiskForecast\Trend\TheilSenFit;
use DiskForecast\Trend\TrendFit;

/**
 * Projects when a target runs out of space from its recent history.
 *
 * Projections start from the newest reading: today's usage is known exactly, so only
 * the rate is estimated.
 */
final class Forecaster
{
    private const DAY = 86400;

    /** Trend window used when a target does not choose one. */
    public const DEFAULT_WINDOW_SECONDS = 180 * self::DAY;

    /**
     * @param TrendFit $fit How the trend is drawn.
     * @param RangeMethod $range How the estimate, slow and fast cases are drawn.
     * @param int $windowSeconds How much recent history the trend is fitted to.
     * @param int $maxPoints Readings are averaged down to at most this many points before fitting.
     * @param int $minimumSpanSeconds History the window must cover before a trend is drawn.
     * @param float $horizonLimitSeconds Beyond this, a case is reported as not filling.
     */
    public function __construct(
        private readonly TrendFit $fit,
        private readonly RangeMethod $range,
        private readonly int $windowSeconds,
        private readonly int $maxPoints = 240,
        private readonly int $minimumSpanSeconds = 7 * self::DAY,
        private readonly float $horizonLimitSeconds = 100 * 365.25 * self::DAY,
    ) {
    }

    /**
     * The forecaster the plugin uses: a Theil–Sen trend with a range calibrated on the
     * target's own past. See docs/decisions.md for the backtest that chose it.
     */
    public static function standard(int $windowSeconds = self::DEFAULT_WINDOW_SECONDS): self
    {
        $fit = new TheilSenFit();

        return new self($fit, new HindcastRange($fit, $windowSeconds, new BlockQuantileRange()), $windowSeconds);
    }

    /**
     * Forecasts the history as it stood at the given time (default: its newest reading).
     *
     * @param float $extraRoom Bytes of capacity to pretend were added now, for "what if I add a drive".
     */
    public function forecast(History $history, ?int $now = null, float $extraRoom = 0.0): Forecast
    {
        $now ??= $history->latest()?->time ?? time();
        $latest = $history->atOrBefore($now);
        if ($latest === null) {
            return new Forecast(ForecastStatus::Collecting, $now, null, null, null, 0.0, 0.0, null, null, null);
        }

        $startUsed = (float) $latest->used;
        $room = max(0, $latest->free) + max(0.0, $extraRoom);
        $points = $history->between($now - $this->windowSeconds, $now)->toPoints($this->maxPoints);
        $trend = $points->span() >= $this->minimumSpanSeconds ? $this->fit->fit($points) : null;
        if ($trend === null) {
            $status = $room <= 0.0 ? ForecastStatus::Full : ForecastStatus::Collecting;

            return new Forecast($status, $now, $latest, null, null, $startUsed, $room, null, null, null);
        }

        $range = $this->range->prepare($history, $now, $points, $trend);
        if ($room <= 0.0) {
            return new Forecast(ForecastStatus::Full, $now, $latest, $trend, $range, $startUsed, 0.0, 0.0, 0.0, 0.0);
        }

        $secondsToFull = $this->secondsUntil(static fn (float $s): float => $range->estimate($s), $room);

        return new Forecast(
            $secondsToFull === null ? ForecastStatus::NotFilling : ForecastStatus::Filling,
            $now,
            $latest,
            $trend,
            $range,
            $startUsed,
            $room,
            $secondsToFull,
            $this->secondsUntil(static fn (float $s): float => $range->bounds($s)[1], $room),
            $this->secondsUntil(static fn (float $s): float => $range->bounds($s)[0], $room),
        );
    }

    /**
     * First horizon at which the growth reaches the room, or null when it does not within the limit.
     *
     * @param callable(float): float $growth Bytes grown after the given seconds.
     */
    private function secondsUntil(callable $growth, float $room): ?float
    {
        if ($room <= 0.0) {
            return 0.0;
        }

        $previous = 0.0;
        $step = (float) self::DAY;
        while ($growth($step) < $room) {
            if ($step >= $this->horizonLimitSeconds) {
                return null;
            }

            $previous = $step;
            $step = min($this->horizonLimitSeconds, $step * 1.25);
        }

        $low = $previous;
        $high = $step;
        while ($high - $low > 60.0) {
            $middle = ($low + $high) / 2;
            if ($growth($middle) >= $room) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return $high;
    }
}

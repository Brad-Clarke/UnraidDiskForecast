<?php

declare(strict_types=1);

namespace DiskForecast\Web;

use DiskForecast\Config\Target;
use DiskForecast\Forecast\Forecast;
use DiskForecast\Forecast\TargetForecast;
use DiskForecast\History;
use DiskForecast\Notify\WarningMonitor;

/**
 * Shapes forecasts into the JSON the pages draw. Times are Unix seconds, sizes are bytes.
 */
final class ForecastView
{
    private const DAY = 86400;
    private const MONTH = 2629800;
    private const YEAR = 31557600;
    private const HISTORY_POINTS = 400;
    private const PROJECTION_POINTS = 90;

    /** Horizons listed in the projection table, in days. */
    private const MILESTONES = [30, 91, 182, 365, 730, 1826];

    private function __construct()
    {
    }

    /**
     * The headline numbers for one target.
     *
     * @param string $description What the target measures, such as "Whole array" or "Pool · cache".
     * @return array<string, mixed>
     */
    public static function summary(Target $target, TargetForecast $result, string $description): array
    {
        $forecast = $result->forecast;
        $latest = $forecast->latest;

        return [
            'id' => $target->id,
            'name' => $target->name,
            'description' => $description,
            'intervalMinutes' => $target->intervalMinutes,
            'windowDays' => $target->windowDays,
            'warnDays' => $target->warnDays,
            'status' => $forecast->status->value,
            'calibrated' => $forecast->calibrated(),
            'warning' => WarningMonitor::isWarning($target, $forecast),
            'time' => $latest?->time,
            'firstReading' => $result->firstReading,
            'readings' => $result->readings,
            'size' => $latest?->size,
            'used' => $latest?->used,
            'free' => $latest?->free,
            'growthPerMonth' => $forecast->secondsToFull > 0 ? $forecast->room / $forecast->secondsToFull * self::MONTH : null,
            'secondsToFull' => $forecast->secondsToFull,
            'earliestSeconds' => $forecast->earliestSeconds,
            'latestSeconds' => $forecast->latestSeconds,
        ];
    }

    /**
     * The graph for one target: history over the span, the projection ahead, capacity,
     * a table of projected usage at fixed horizons, and the projected times.
     *
     * @param int $spanDays Days of history to show; 0 for all of it.
     * @param float $addedBytes Capacity pretended to be added now ("what if I add a drive"); the forecast already includes it.
     * @return array<string, mixed>
     */
    public static function series(History $history, Forecast $forecast, int $spanDays, float $addedBytes = 0.0): array
    {
        $latest = $forecast->latest;
        $times = [
            'status' => $forecast->status->value,
            'secondsToFull' => $forecast->secondsToFull,
            'earliestSeconds' => $forecast->earliestSeconds,
            'latestSeconds' => $forecast->latestSeconds,
        ];
        if ($latest === null) {
            return ['history' => [], 'projection' => [], 'milestones' => [], 'capacity' => null, 'baseCapacity' => null, 'now' => $forecast->time, 'times' => $times];
        }

        $now = $forecast->time;
        $from = $spanDays > 0 ? $now - $spanDays * self::DAY : ($history->first()?->time ?? $now);
        $points = $history->between($from, $now)->toPoints(self::HISTORY_POINTS);
        $historyRows = [];
        foreach ($points->times as $i => $offset) {
            $historyRows[] = [(int) round($points->origin + $offset), (int) round($points->values[$i])];
        }

        $last = end($historyRows);
        if ($last !== false && $last[0] < $latest->time) {
            $historyRows[] = [$latest->time, $latest->used];
        }

        $capacity = $latest->size + $addedBytes;
        $projection = [];
        $milestones = [];
        if ($forecast->trend !== null) {
            $horizon = self::projectionHorizon($forecast, max(self::DAY, $now - $from));
            for ($step = 0; $step <= self::PROJECTION_POINTS; $step++) {
                $seconds = $horizon * $step / self::PROJECTION_POINTS;
                [$estimate, $slow, $fast] = $forecast->projection($seconds);
                $projection[] = [
                    (int) round($now + $seconds),
                    (int) round(min($capacity, $estimate)),
                    (int) round(min($capacity, $slow)),
                    (int) round(min($capacity, $fast)),
                ];
            }

            foreach (self::MILESTONES as $days) {
                [$estimate, $slow, $fast] = $forecast->projection($days * self::DAY);
                $milestones[] = [
                    'days' => $days,
                    'time' => $now + $days * self::DAY,
                    'estimate' => (int) round($estimate),
                    'slow' => (int) round($slow),
                    'fast' => (int) round($fast),
                ];
            }
        }

        return [
            'history' => $historyRows,
            'projection' => $projection,
            'milestones' => $milestones,
            'capacity' => (int) round($capacity),
            'baseCapacity' => $latest->size,
            'now' => $now,
            'times' => $times,
        ];
    }

    /**
     * How far ahead to draw: well past the estimated full date, at least half the shown
     * history and a month, at most ten years. The range band runs on to the edge.
     */
    private static function projectionHorizon(Forecast $forecast, int $shownSeconds): float
    {
        return min(10 * self::YEAR, max(30 * self::DAY, $shownSeconds / 2, ($forecast->secondsToFull ?? 0.0) * 1.4));
    }
}

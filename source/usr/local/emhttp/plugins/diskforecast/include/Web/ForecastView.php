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
     * @return array<string, mixed>
     */
    public static function summary(Target $target, TargetForecast $result): array
    {
        $forecast = $result->forecast;
        $latest = $forecast->latest;

        return [
            'id' => $target->id,
            'name' => $target->name,
            'type' => $target->type->value,
            'members' => $target->members,
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
            'growthPerMonth' => $forecast->projection(self::MONTH) === null ? null : $forecast->projection(self::MONTH)[0] - $forecast->startUsed,
            'secondsToFull' => $forecast->secondsToFull,
            'earliestSeconds' => $forecast->earliestSeconds,
            'latestSeconds' => $forecast->latestSeconds,
        ];
    }

    /**
     * Only the projected times, for "what if I add a drive".
     *
     * @return array<string, mixed>
     */
    public static function times(Forecast $forecast): array
    {
        return [
            'status' => $forecast->status->value,
            'secondsToFull' => $forecast->secondsToFull,
            'earliestSeconds' => $forecast->earliestSeconds,
            'latestSeconds' => $forecast->latestSeconds,
        ];
    }

    /**
     * The graph for one target: history over the span, the projection ahead, capacity,
     * and a table of projected usage at fixed horizons.
     *
     * @param int $spanDays Days of history to show; 0 for all of it.
     * @return array<string, mixed>
     */
    public static function series(History $history, Forecast $forecast, int $spanDays): array
    {
        $latest = $forecast->latest;
        if ($latest === null) {
            return ['history' => [], 'projection' => [], 'milestones' => [], 'capacity' => null, 'now' => $forecast->time];
        }

        $now = $forecast->time;
        $from = $spanDays > 0 ? $now - $spanDays * self::DAY : ($history->first()?->time ?? $now);
        $points = $history->between($from, $now)->toPoints(self::HISTORY_POINTS);
        $historyRows = [];
        foreach ($points->times as $i => $offset) {
            $historyRows[] = [(int) round($points->origin + $offset), (int) round($points->values[$i])];
        }

        $capacity = (float) $latest->size;
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
            'capacity' => $latest->size,
            'now' => $now,
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

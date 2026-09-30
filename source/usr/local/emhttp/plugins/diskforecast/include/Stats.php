<?php

declare(strict_types=1);

namespace DiskForecast;

/**
 * Small order statistics shared by the fits and ranges.
 */
final class Stats
{
    private function __construct()
    {
    }

    /**
     * Median of the values, or null when there are none.
     *
     * @param list<float> $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return self::quantileOfSorted($values, 0.5);
    }

    /**
     * Linearly interpolated quantile of values already sorted ascending.
     *
     * @param list<float> $sorted Non-empty, ascending.
     * @param float $q Quantile in [0, 1].
     */
    public static function quantileOfSorted(array $sorted, float $q): float
    {
        $count = count($sorted);
        $position = max(0.0, min(1.0, $q)) * ($count - 1);
        $lower = (int) floor($position);
        $upper = min($count - 1, $lower + 1);
        $fraction = $position - $lower;

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * $fraction;
    }

    /**
     * Arithmetic mean and population standard deviation, or null when there are no values.
     *
     * @param list<float> $values
     * @return array{0: float, 1: float}|null
     */
    public static function meanAndDeviation(array $values): ?array
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        $mean = array_sum($values) / $count;
        $squares = 0.0;
        foreach ($values as $value) {
            $squares += ($value - $mean) ** 2;
        }

        return [$mean, sqrt($squares / $count)];
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Range;

use DiskForecast\Points;

/**
 * Splits a window into equal-length blocks and measures the fill rate within each.
 */
final class BlockRates
{
    private function __construct()
    {
    }

    /**
     * The rate in bytes per second for each block holding at least two points.
     *
     * @return list<float>
     */
    public static function measure(Points $points, int $blocks): array
    {
        $span = $points->span();
        if ($span <= 0.0 || $blocks < 1) {
            return [];
        }

        $width = $span / $blocks;
        $start = $points->times[0];
        $firstIndex = array_fill(0, $blocks, null);
        $lastIndex = array_fill(0, $blocks, null);
        foreach ($points->times as $i => $time) {
            $block = min($blocks - 1, (int) floor(($time - $start) / $width));
            $firstIndex[$block] ??= $i;
            $lastIndex[$block] = $i;
        }

        $rates = [];
        for ($block = 0; $block < $blocks; $block++) {
            $first = $firstIndex[$block];
            $last = $lastIndex[$block];
            if ($first === null || $last === $first) {
                continue;
            }

            $dt = $points->times[$last] - $points->times[$first];
            if ($dt > 0.0) {
                $rates[] = ($points->values[$last] - $points->values[$first]) / $dt;
            }
        }

        return $rates;
    }

    /**
     * Length of one block in seconds for the window.
     */
    public static function blockSeconds(Points $points, int $blocks): float
    {
        return $points->span() / max(1, $blocks);
    }
}

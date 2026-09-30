<?php

declare(strict_types=1);

namespace DiskForecast\Range;

use DiskForecast\Points;

/**
 * Splits a window into equal-length blocks and measures the fill rate within each.
 *
 * Blocks are at least a week long and each rate is a least-squares slope through the whole
 * block, so daily and weekly patterns (a cache the mover empties every night) cancel out
 * instead of reading as wild swings in growth.
 */
final class BlockRates
{
    private const MIN_BLOCK_SECONDS = 7 * 86400;
    private const MIN_BLOCKS = 3;

    private function __construct()
    {
    }

    /**
     * The rate in bytes per second for each block holding at least two points.
     *
     * @param int $maxBlocks Most blocks the window is split into; fewer when the window is short.
     * @return list<float>
     */
    public static function measure(Points $points, int $maxBlocks): array
    {
        $blocks = self::count($points, $maxBlocks);
        if ($blocks < 1) {
            return [];
        }

        $width = $points->span() / $blocks;
        $start = $points->times[0];
        $members = array_fill(0, $blocks, []);
        foreach ($points->times as $i => $time) {
            $members[min($blocks - 1, (int) floor(($time - $start) / $width))][] = $i;
        }

        $rates = [];
        foreach ($members as $indices) {
            $rate = self::slope($points, $indices);
            if ($rate !== null) {
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /**
     * Length of one block in seconds for the window.
     */
    public static function blockSeconds(Points $points, int $maxBlocks): float
    {
        $blocks = self::count($points, $maxBlocks);

        return $blocks < 1 ? 0.0 : $points->span() / $blocks;
    }

    /**
     * As many week-or-longer blocks as fit, between three and the maximum.
     */
    private static function count(Points $points, int $maxBlocks): int
    {
        $span = $points->span();
        if ($span <= 0.0 || $maxBlocks < 1) {
            return 0;
        }

        return max(min(self::MIN_BLOCKS, $maxBlocks), min($maxBlocks, (int) floor($span / self::MIN_BLOCK_SECONDS)));
    }

    /**
     * Least-squares slope through the given points, or null when they span no time.
     *
     * @param list<int> $indices
     */
    private static function slope(Points $points, array $indices): ?float
    {
        $count = count($indices);
        if ($count < 2) {
            return null;
        }

        $meanTime = 0.0;
        $meanValue = 0.0;
        foreach ($indices as $i) {
            $meanTime += $points->times[$i];
            $meanValue += $points->values[$i];
        }

        $meanTime /= $count;
        $meanValue /= $count;
        $covariance = 0.0;
        $variance = 0.0;
        foreach ($indices as $i) {
            $dt = $points->times[$i] - $meanTime;
            $covariance += $dt * ($points->values[$i] - $meanValue);
            $variance += $dt * $dt;
        }

        return $variance > 0.0 ? $covariance / $variance : null;
    }
}

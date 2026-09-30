<?php

declare(strict_types=1);

namespace DiskForecast\Tests;

use DiskForecast\Range\HindcastGrowthRange;
use DiskForecast\Range\LinearGrowthRange;
use PHPUnit\Framework\TestCase;

final class HindcastGrowthRangeTest extends TestCase
{
    public function testInterpolatesTheMissBetweenMeasuredHorizons(): void
    {
        $range = new HindcastGrowthRange(10.0, [100 => [-2.0, 2.0, 6.0], 300 => [-4.0, 6.0, 14.0]], new LinearGrowthRange(10.0, 10.0, 10.0));

        self::assertEqualsWithDelta(12.0 * 100, $range->estimate(100), 1e-9);
        self::assertEqualsWithDelta(14.0 * 200, $range->estimate(200), 1e-9);
        self::assertEqualsWithDelta(16.0 * 300, $range->estimate(300), 1e-9);
        self::assertEqualsWithDelta(16.0 * 600, $range->estimate(600), 1e-9);
        self::assertEqualsWithDelta(12.0 * 50, $range->estimate(50), 1e-9);
    }

    public function testRangeAlwaysContainsTheEstimateAndTheFallback(): void
    {
        $range = new HindcastGrowthRange(10.0, [100 => [1.0, 2.0, 3.0]], new LinearGrowthRange(10.0, 5.0, 20.0));

        [$slow, $fast] = $range->bounds(100);

        self::assertEqualsWithDelta(500.0, $slow, 1e-9);
        self::assertEqualsWithDelta(2000.0, $fast, 1e-9);
        self::assertGreaterThanOrEqual($slow, $range->estimate(100));
        self::assertLessThanOrEqual($fast, $range->estimate(100));
    }

    public function testWithoutMeasurementsTheFallbackStandsAlone(): void
    {
        $range = new HindcastGrowthRange(10.0, [], new LinearGrowthRange(10.0, 5.0, 20.0));

        self::assertSame(1000.0, $range->estimate(100));
        self::assertSame([500.0, 2000.0], $range->bounds(100));
    }
}

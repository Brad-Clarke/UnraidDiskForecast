<?php

declare(strict_types=1);

namespace DiskForecast\Tests;

use DiskForecast\Points;
use DiskForecast\Range\BlockRates;
use PHPUnit\Framework\TestCase;

final class BlockRatesTest extends TestCase
{
    public function testADailyFillAndEmptyCycleReadsAsNoGrowth(): void
    {
        $times = [];
        $values = [];
        for ($hour = 0; $hour < 28 * 24; $hour++) {
            $times[] = $hour * 3600.0;
            $values[] = 200e9 + ($hour % 24) * 6e9;
        }

        $rates = BlockRates::measure(new Points(0, $times, $values), 12);

        self::assertCount(4, $rates);
        foreach ($rates as $rate) {
            self::assertLessThan(1e9 / 86400, abs($rate));
        }
    }

    public function testSteadyGrowthReadsTheSameInEveryBlock(): void
    {
        $times = [];
        $values = [];
        for ($day = 0; $day <= 180; $day++) {
            $times[] = $day * 86400.0;
            $values[] = $day * 100e9;
        }

        $rates = BlockRates::measure(new Points(0, $times, $values), 12);

        self::assertCount(12, $rates);
        foreach ($rates as $rate) {
            self::assertEqualsWithDelta(100e9 / 86400, $rate, 1.0);
        }
    }
}

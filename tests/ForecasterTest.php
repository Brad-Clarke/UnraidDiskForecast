<?php

declare(strict_types=1);

namespace DiskForecast\Tests;

use DiskForecast\Forecast\Forecaster;
use DiskForecast\Forecast\ForecastStatus;
use DiskForecast\History;
use DiskForecast\Sample;
use PHPUnit\Framework\TestCase;

final class ForecasterTest extends TestCase
{
    private const DAY = 86400;
    private const GB = 1_000_000_000;

    public function testSteadyGrowthFillsWhenTheRoomRunsOut(): void
    {
        $history = self::daily(60, 10_000 * self::GB, 1_000 * self::GB, 10 * self::GB);

        $forecast = Forecaster::standard()->forecast($history);

        self::assertSame(ForecastStatus::Filling, $forecast->status);
        $expectedDays = (10_000 - 1_000 - 59 * 10) / 10;
        self::assertEqualsWithDelta($expectedDays, $forecast->secondsToFull / self::DAY, 0.01);
        self::assertLessThanOrEqual($forecast->secondsToFull, $forecast->earliestSeconds);
        self::assertGreaterThanOrEqual($forecast->secondsToFull, $forecast->latestSeconds);
    }

    public function testFlatUsageIsNotFilling(): void
    {
        $forecast = Forecaster::standard()->forecast(self::daily(60, 10_000 * self::GB, 1_000 * self::GB, 0));

        self::assertSame(ForecastStatus::NotFilling, $forecast->status);
        self::assertNull($forecast->secondsToFull);
    }

    public function testShortHistoryIsStillCollecting(): void
    {
        $forecast = Forecaster::standard()->forecast(self::daily(5, 10_000 * self::GB, 1_000 * self::GB, 10 * self::GB));

        self::assertSame(ForecastStatus::Collecting, $forecast->status);
    }

    public function testNoFreeSpaceIsFull(): void
    {
        $forecast = Forecaster::standard()->forecast(self::daily(30, 1_000 * self::GB, 800 * self::GB, 10 * self::GB));

        self::assertSame(ForecastStatus::Full, $forecast->status);
    }

    private static function daily(int $days, int $size, int $start, int $perDay): History
    {
        $samples = [];
        for ($day = 0; $day < $days; $day++) {
            $used = min($size, $start + $day * $perDay);
            $samples[] = new Sample(1_700_000_000 + $day * self::DAY, $size, $used, $size - $used);
        }

        return History::fromSamples($samples);
    }
}

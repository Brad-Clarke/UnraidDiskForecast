<?php

declare(strict_types=1);

namespace DiskForecast\Tests;

use DiskForecast\History;
use DiskForecast\Sample;
use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase
{
    public function testSortsReadingsAndSlicesByTime(): void
    {
        $history = History::fromSamples([self::sample(30), self::sample(10), self::sample(20)]);

        self::assertSame([10, 20, 30], array_map(static fn (Sample $s): int => $s->time, $history->samples()));
        self::assertSame(2, $history->between(15, 30)->count());
        self::assertSame(20, $history->atOrBefore(29)?->time);
        self::assertNull($history->atOrBefore(9));
    }

    public function testKeepsEveryReadingWhenUnderThePointLimit(): void
    {
        $points = History::fromSamples([self::sample(100, 1), self::sample(160, 2)])->toPoints(10);

        self::assertSame(100, $points->origin);
        self::assertSame([0.0, 60.0], $points->times);
        self::assertSame([1.0, 2.0], $points->values);
    }

    public function testAveragesReadingsIntoBucketsOverThePointLimit(): void
    {
        $samples = [];
        for ($i = 0; $i < 100; $i++) {
            $samples[] = self::sample($i, $i);
        }

        $points = History::fromSamples($samples)->toPoints(10);

        self::assertSame(10, $points->count());
        self::assertEqualsWithDelta(4.5, $points->values[0], 1e-9);
        self::assertEqualsWithDelta(94.5, $points->values[9], 1e-9);
    }

    private static function sample(int $time, int $used = 0): Sample
    {
        return new Sample($time, 1000, $used, 1000 - $used);
    }
}

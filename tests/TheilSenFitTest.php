<?php

declare(strict_types=1);

namespace DiskForecast\Tests;

use DiskForecast\Points;
use DiskForecast\Trend\TheilSenFit;
use PHPUnit\Framework\TestCase;

final class TheilSenFitTest extends TestCase
{
    public function testFitsAnExactLine(): void
    {
        $line = (new TheilSenFit())->fit(new Points(1000, [0.0, 10.0, 20.0, 30.0], [5.0, 25.0, 45.0, 65.0]));

        self::assertNotNull($line);
        self::assertEqualsWithDelta(2.0, $line->slope, 1e-9);
        self::assertEqualsWithDelta(5.0, $line->valueAt(1000), 1e-9);
        self::assertEqualsWithDelta(65.0, $line->valueAt(1030), 1e-9);
    }

    public function testOneLargeJumpBarelyMovesTheSlope(): void
    {
        $times = [];
        $values = [];
        for ($i = 0; $i < 20; $i++) {
            $times[] = (float) $i;
            $values[] = $i + ($i >= 10 ? 1000.0 : 0.0);
        }

        $line = (new TheilSenFit())->fit(new Points(0, $times, $values));

        self::assertNotNull($line);
        self::assertEqualsWithDelta(1.0, $line->slope, 1e-9);
    }

    public function testNeedsTwoDistinctTimes(): void
    {
        self::assertNull((new TheilSenFit())->fit(new Points(0, [5.0], [1.0])));
        self::assertNull((new TheilSenFit())->fit(new Points(0, [5.0, 5.0], [1.0, 2.0])));
    }
}

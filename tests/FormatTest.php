<?php

declare(strict_types=1);

namespace DiskForecast\Tests;

use DiskForecast\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testDurationShowsTheTwoLargestUnits(): void
    {
        self::assertSame('2 years 7 months', Format::duration(2 * 31557600 + 7 * 2629800 + 86400 * 3));
        self::assertSame('1 day 4 hours', Format::duration(86400 + 4 * 3600 + 120));
        self::assertSame('3 years', Format::duration(3 * 31557600 + 3600));
        self::assertSame('20 minutes', Format::duration(1200));
        self::assertSame('less than a minute', Format::duration(30));
    }

    public function testBytesAreDecimal(): void
    {
        self::assertSame('38.2 TB', Format::bytes(38.2e12));
        self::assertSame('312 GB', Format::bytes(312e9));
        self::assertSame('0 B', Format::bytes(0));
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Tests;

use DiskForecast\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testDurationShowsTheTwoLargestUnits(): void
    {
        $format = new Format();

        self::assertSame('2 years 7 months', $format->duration(2 * 31557600 + 7 * 2629800 + 86400 * 3));
        self::assertSame('1 day 4 hours', $format->duration(86400 + 4 * 3600 + 120));
        self::assertSame('3 years', $format->duration(3 * 31557600 + 3600));
        self::assertSame('20 minutes', $format->duration(1200));
        self::assertSame('less than a minute', $format->duration(30));
    }

    public function testBytesAreDecimalWithUnraidsNumberFormat(): void
    {
        self::assertSame('38.2 TB', (new Format())->bytes(38.2e12));
        self::assertSame('312 GB', (new Format())->bytes(312e9));
        self::assertSame('0 B', (new Format())->bytes(0));
        self::assertSame('38,2 TB', (new Format('%c', ', '))->bytes(38.2e12));
    }

    public function testDatesFollowUnraidsDateFormatWithoutTheWeekday(): void
    {
        $time = gmmktime(12, 0, 0, 7, 5, 2028);

        self::assertSame('5 July 2028', (new Format('%A, %e %B %Y'))->date($time));
        self::assertSame('July 5, 2028', (new Format('%A, %B %e, %Y'))->date($time));
        self::assertSame('2028 July 5', (new Format('%A, %Y %B %e'))->date($time));
        self::assertSame('07/05/2028', (new Format('%A, %m/%d/%Y'))->date($time));
        self::assertSame('05-07-2028', (new Format('%A, %d-%m-%Y'))->date($time));
        self::assertSame('05.07.2028', (new Format('%A, %d.%m.%Y'))->date($time));
        self::assertSame('2028-07-05', (new Format('%A, %Y-%m-%d'))->date($time));
        self::assertSame('5 Jul 2028', (new Format('%c'))->date($time));
    }
}

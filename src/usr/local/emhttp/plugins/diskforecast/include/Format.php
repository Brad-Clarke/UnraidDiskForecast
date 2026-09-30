<?php

declare(strict_types=1);

namespace DiskForecast;

/**
 * Human-readable sizes, durations and dates for notifications.
 * The web pages format the same values in JavaScript.
 */
final class Format
{
    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;
    private const MONTH = 2629800;
    private const YEAR = 31557600;

    private function __construct()
    {
    }

    /**
     * Decimal bytes, as Unraid shows them: "38.2 TB", "312 GB".
     */
    public static function bytes(float $bytes): string
    {
        foreach (['PB' => 1e15, 'TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6, 'KB' => 1e3] as $unit => $scale) {
            if (abs($bytes) >= $scale) {
                $value = $bytes / $scale;

                return (abs($value) >= 100 ? number_format($value, 0) : number_format($value, 1)) . " {$unit}";
            }
        }

        return number_format($bytes, 0) . ' B';
    }

    /**
     * The two largest units of a duration: "2 years 7 months", "5 days 4 hours", "20 minutes".
     */
    public static function duration(float $seconds): string
    {
        $units = [
            ['year', self::YEAR],
            ['month', self::MONTH],
            ['day', self::DAY],
            ['hour', self::HOUR],
            ['minute', self::MINUTE],
        ];
        $remaining = max(0.0, $seconds);
        $parts = [];
        foreach ($units as [$name, $size]) {
            $count = (int) floor($remaining / $size);
            if ($count > 0 || $parts !== []) {
                if ($count > 0) {
                    $parts[] = $count . ' ' . $name . ($count === 1 ? '' : 's');
                }

                $remaining -= $count * $size;
                if (count($parts) === 2 || ($parts !== [] && $count === 0)) {
                    break;
                }
            }
        }

        return $parts === [] ? 'less than a minute' : implode(' ', $parts);
    }

    /**
     * A date such as "14 Apr 2029".
     */
    public static function date(int $time): string
    {
        return date('j M Y', $time);
    }
}

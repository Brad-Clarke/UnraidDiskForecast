<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Synthetic;

use DiskForecast\History;
use DiskForecast\Sample;

/**
 * Reproducible fake histories covering the usage patterns a forecast has to survive.
 *
 * Every scenario seeds its own generator, so the same scenario is byte-identical on every run.
 */
final class ScenarioLibrary
{
    /** Start of every generated history: 2022-01-01 00:00 UTC. */
    public const START = 1640995200;

    private const HOUR = 3600;
    private const DAY = 86400;
    private const GB = 1_000_000_000;
    private const TB = 1_000_000_000_000;
    private const DAYS = 1460;
    private const FILL_DAY = 1300;

    private function __construct()
    {
    }

    /**
     * Scenarios that fill up at a known time, used to score forecasts.
     *
     * @return list<Scenario>
     */
    public static function filling(): array
    {
        return [
            self::fillingScenario('steady', 'Steady 150 GB a day with day-to-day noise.', 101,
                static fn (int $day): float => 150 * self::GB * max(0.0, 1 + 0.3 * self::normal())),
            self::fillingScenario('bursty', 'Small daily growth plus large downloads every few days.', 102,
                static fn (int $day): float => 20 * self::GB
                    + (self::uniform() < 0.2 ? exp(log(300 * self::GB) + 0.8 * self::normal()) : 0.0)),
            self::fillingScenario('deletes', 'Steady growth with an occasional 1-4 TB clear-out.', 103,
                static fn (int $day): float => 120 * self::GB * max(0.0, 1 + 0.3 * self::normal())
                    - (self::uniform() < 1 / 60 ? (1 + 3 * self::uniform()) * self::TB : 0.0)),
            self::fillingScenario('accelerating', 'Growth speeding up from 50 to 250 GB a day.', 104,
                static fn (int $day): float => (50 + 200 * $day / self::DAYS) * self::GB * max(0.0, 1 + 0.3 * self::normal())),
            self::fillingScenario('slowing', 'Growth slowing from 300 to 50 GB a day.', 105,
                static fn (int $day): float => (300 - 250 * $day / self::DAYS) * self::GB * max(0.0, 1 + 0.3 * self::normal())),
            self::fillingScenario('regimes', 'Habits that change every one to four months.', 106, self::regimes()),
            self::fillingScenario('migration', 'Slow growth with a one-off 15 TB import halfway.', 107,
                static fn (int $day): float => 30 * self::GB * max(0.0, 1 + 0.3 * self::normal())
                    + ($day >= 700 && $day < 703 ? 5 * self::TB : 0.0)),
        ];
    }

    /**
     * Scenarios that never fill, for checking the "not filling" and churn cases in the preview.
     *
     * @return list<Scenario>
     */
    public static function nonFilling(): array
    {
        return [self::cacheChurn(), self::flat()];
    }

    /**
     * @param callable(int): float $dailyGrowth Bytes added on the given day (negative for deletes).
     */
    private static function fillingScenario(string $name, string $description, int $seed, callable $dailyGrowth): Scenario
    {
        mt_srand($seed);
        $used = self::usedSeries(60 * self::TB, $dailyGrowth);
        $size = (int) $used[self::FILL_DAY * 24];
        $fillIndex = 0;
        while ($used[$fillIndex] < $size) {
            $fillIndex++;
        }

        $samples = [];
        for ($hour = 0; $hour <= $fillIndex; $hour++) {
            $value = (int) min($size, $used[$hour]);
            $samples[] = new Sample(self::START + $hour * self::HOUR, $size, $value, $size - $value);
        }

        return new Scenario($name, $description, History::fromSamples($samples), self::START + $fillIndex * self::HOUR);
    }

    /**
     * Hourly bytes used, spreading each day's growth evenly over its hours.
     *
     * @param callable(int): float $dailyGrowth
     * @return list<float>
     */
    private static function usedSeries(float $start, callable $dailyGrowth): array
    {
        $used = [$start];
        $value = $start;
        for ($day = 0; $day < self::DAYS; $day++) {
            $perHour = $dailyGrowth($day) / 24;
            for ($hour = 0; $hour < 24; $hour++) {
                $value = max(0.0, $value + $perHour);
                $used[] = $value;
            }
        }

        return $used;
    }

    /**
     * @return callable(int): float
     */
    private static function regimes(): callable
    {
        $rate = 80.0;
        $until = 0;

        return static function (int $day) use (&$rate, &$until): float {
            if ($day >= $until) {
                $rate = [10.0, 80.0, 300.0][mt_rand(0, 2)];
                $until = $day + mt_rand(30, 120);
            }

            return $rate * self::GB * max(0.0, 1 + 0.3 * self::normal());
        };
    }

    private static function cacheChurn(): Scenario
    {
        mt_srand(201);
        $size = 2 * self::TB;
        $samples = [];
        $used = 200 * self::GB;
        for ($hour = 0; $hour <= 365 * 24; $hour++) {
            if ($hour % 24 === 3) {
                $used = 200 * self::GB;
            } elseif ($hour % 24 >= 8) {
                $used += 150 * self::GB / 16 * max(0.0, 1 + 0.5 * self::normal());
            }

            $value = (int) min($size, $used);
            $samples[] = new Sample(self::START + $hour * self::HOUR, $size, $value, $size - $value);
        }

        return new Scenario('cache-churn', 'A cache pool the mover empties every night.', History::fromSamples($samples), null);
    }

    private static function flat(): Scenario
    {
        mt_srand(202);
        $size = 48 * self::TB;
        $samples = [];
        $used = 30 * self::TB;
        for ($hour = 0; $hour <= 365 * 24; $hour++) {
            $used += 0.2 * self::GB * self::normal();
            $value = (int) $used;
            $samples[] = new Sample(self::START + $hour * self::HOUR, $size, $value, $size - $value);
        }

        return new Scenario('flat', 'An archive that barely changes.', History::fromSamples($samples), null);
    }

    private static function uniform(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private static function normal(): float
    {
        $u = max(1e-12, self::uniform());

        return sqrt(-2 * log($u)) * cos(2 * M_PI * self::uniform());
    }
}

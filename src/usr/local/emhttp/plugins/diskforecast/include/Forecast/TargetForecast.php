<?php

declare(strict_types=1);

namespace DiskForecast\Forecast;

/**
 * A target's forecast together with the facts about its history the pages show beside it.
 */
final class TargetForecast
{
    /**
     * @param Forecast $forecast The forecast as of the newest reading.
     * @param int|null $firstReading Unix time of the oldest reading, or null when there are none.
     * @param int $readings Number of readings.
     * @param int|null $forecastFrom Unix time from which there is enough history for a forecast, or null when there are no readings.
     * @param int|null $calibratedFrom Unix time from which there is enough history to calibrate the range, or null when there are no readings.
     */
    public function __construct(
        public readonly Forecast $forecast,
        public readonly ?int $firstReading,
        public readonly int $readings,
        public readonly ?int $forecastFrom,
        public readonly ?int $calibratedFrom,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Forecast;

/**
 * The headline state of a target's forecast.
 */
enum ForecastStatus: string
{
    /** Not enough history in the window to draw a trend yet. */
    case Collecting = 'collecting';

    /** The trend is flat or shrinking. */
    case NotFilling = 'not-filling';

    /** The trend is growing and a full date is projected. */
    case Filling = 'filling';

    /** No writable space is left. */
    case Full = 'full';
}

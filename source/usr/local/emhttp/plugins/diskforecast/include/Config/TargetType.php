<?php

declare(strict_types=1);

namespace DiskForecast\Config;

/**
 * What a target measures.
 */
enum TargetType: string
{
    /** Every data disk in the array, added together. */
    case Array = 'array';

    /** One pool, such as the cache. */
    case Pool = 'pool';

    /** One or more chosen disks or pools, added together. */
    case Disks = 'disks';

    /** The disks and pools one share is allowed to use. */
    case Share = 'share';
}

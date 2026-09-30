<?php

declare(strict_types=1);

namespace DiskForecast\Storage;

/**
 * Whether a storage unit is an array disk or a pool.
 */
enum UnitKind: string
{
    case Disk = 'disk';
    case Pool = 'pool';
}

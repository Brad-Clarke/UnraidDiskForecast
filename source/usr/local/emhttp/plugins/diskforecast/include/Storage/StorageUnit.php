<?php

declare(strict_types=1);

namespace DiskForecast\Storage;

/**
 * One separately mounted filesystem: an array disk or a pool.
 */
final class StorageUnit
{
    /**
     * @param string $name Unraid's name for it, such as "disk3" or "cache".
     * @param UnitKind $kind Array disk or pool.
     * @param string $mount Mount point, such as "/mnt/disk3".
     */
    public function __construct(
        public readonly string $name,
        public readonly UnitKind $kind,
        public readonly string $mount,
    ) {
    }
}

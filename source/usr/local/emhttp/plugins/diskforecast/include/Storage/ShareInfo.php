<?php

declare(strict_types=1);

namespace DiskForecast\Storage;

/**
 * A user share and the storage units it is allowed to write to.
 */
final class ShareInfo
{
    /**
     * @param string $name Share name.
     * @param list<string> $units Names of the disks and pools the share may use.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $units,
    ) {
    }
}

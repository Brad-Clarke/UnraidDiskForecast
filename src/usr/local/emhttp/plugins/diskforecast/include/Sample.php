<?php

declare(strict_types=1);

namespace DiskForecast;

/**
 * One reading of a target's space at a moment in time.
 *
 * Used and free are read independently because filesystems reserve space:
 * free is what can still be written, and does not always equal size minus used.
 */
final class Sample
{
    /**
     * @param int $time Unix time of the reading, in seconds.
     * @param int $size Total capacity in bytes.
     * @param int $used Bytes in use.
     * @param int $free Bytes still writable.
     */
    public function __construct(
        public readonly int $time,
        public readonly int $size,
        public readonly int $used,
        public readonly int $free,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Storage;

/**
 * Capacity and writable space of one or more storage units.
 */
final class Space
{
    /**
     * @param int $size Total bytes.
     * @param int $free Writable bytes.
     */
    public function __construct(
        public readonly int $size,
        public readonly int $free,
    ) {
    }

    /**
     * Bytes not writable: size minus free.
     */
    public function used(): int
    {
        return max(0, $this->size - $this->free);
    }

    /**
     * This space plus another.
     */
    public function plus(self $other): self
    {
        return new self($this->size + $other->size, $this->free + $other->free);
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Storage;

/**
 * The server the plugin runs on: what storage exists and how full it is.
 *
 * Every method only reads. Space comes from the filesystem's own counters, so no call
 * walks folders or wakes a sleeping disk.
 */
interface Platform
{
    /**
     * Every array data disk and pool the server knows about, mounted or not.
     *
     * @return list<StorageUnit>
     */
    public function units(): array;

    /**
     * Every user share and the units it may use.
     *
     * @return list<ShareInfo>
     */
    public function shares(): array;

    /**
     * Current capacity and writable space, or null when the unit is not mounted.
     */
    public function space(StorageUnit $unit): ?Space;
}

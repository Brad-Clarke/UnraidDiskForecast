<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Preview;

use DiskForecast\Storage\Platform;
use DiskForecast\Storage\ShareInfo;
use DiskForecast\Storage\Space;
use DiskForecast\Storage\StorageUnit;
use DiskForecast\Storage\UnitKind;

/**
 * A made-up server for the local preview: six array disks, two pools, four shares.
 */
final class FakePlatform implements Platform
{
    private const TB = 1_000_000_000_000;

    /** @var array<string, array{0: UnitKind, 1: int, 2: float}> name => kind, size, fraction used */
    private const UNITS = [
        'disk1' => [UnitKind::Disk, 20 * self::TB, 0.91],
        'disk2' => [UnitKind::Disk, 20 * self::TB, 0.88],
        'disk3' => [UnitKind::Disk, 20 * self::TB, 0.84],
        'disk4' => [UnitKind::Disk, 18 * self::TB, 0.79],
        'disk5' => [UnitKind::Disk, 18 * self::TB, 0.52],
        'disk6' => [UnitKind::Disk, 16 * self::TB, 0.31],
        'cache' => [UnitKind::Pool, 2 * self::TB, 0.18],
        'nvme' => [UnitKind::Pool, 4 * self::TB, 0.06],
    ];

    public function units(): array
    {
        $units = [];
        foreach (self::UNITS as $name => [$kind]) {
            $units[] = new StorageUnit($name, $kind, "/mnt/{$name}");
        }

        return $units;
    }

    public function shares(): array
    {
        return [
            new ShareInfo('appdata', ['cache']),
            new ShareInfo('Backups', ['disk5', 'disk6']),
            new ShareInfo('Media', ['disk1', 'disk2', 'disk3', 'disk4', 'cache']),
            new ShareInfo('Photos', ['disk4', 'disk5']),
        ];
    }

    public function space(StorageUnit $unit): ?Space
    {
        [, $size, $used] = self::UNITS[$unit->name];

        return new Space($size, (int) ($size * (1 - $used)));
    }
}

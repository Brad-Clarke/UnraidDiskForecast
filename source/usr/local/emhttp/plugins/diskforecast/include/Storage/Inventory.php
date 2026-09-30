<?php

declare(strict_types=1);

namespace DiskForecast\Storage;

use DiskForecast\Config\Target;
use DiskForecast\Config\TargetType;

/**
 * The server's storage as read once, with lookups by name and the units behind each target.
 */
final class Inventory
{
    /** @var array<string, StorageUnit> */
    private array $units = [];

    /** @var array<string, ShareInfo> */
    private array $shares = [];

    public function __construct(private readonly Platform $platform)
    {
        foreach ($platform->units() as $unit) {
            $this->units[$unit->name] = $unit;
        }

        foreach ($platform->shares() as $share) {
            $this->shares[$share->name] = $share;
        }
    }

    /**
     * @return list<StorageUnit>
     */
    public function units(): array
    {
        return array_values($this->units);
    }

    /**
     * @return list<ShareInfo>
     */
    public function shares(): array
    {
        return array_values($this->shares);
    }

    public function unit(string $name): ?StorageUnit
    {
        return $this->units[$name] ?? null;
    }

    public function share(string $name): ?ShareInfo
    {
        return $this->shares[$name] ?? null;
    }

    /**
     * The units a target adds together; empty when none of them are known.
     *
     * @return list<StorageUnit>
     */
    public function unitsFor(Target $target): array
    {
        $names = match ($target->type) {
            TargetType::Array => array_keys(array_filter($this->units, static fn (StorageUnit $u): bool => $u->kind === UnitKind::Disk)),
            TargetType::Pool, TargetType::Disks => $target->members,
            TargetType::Share => $this->share($target->members[0] ?? '')?->units ?? [],
        };

        $units = [];
        foreach ($names as $name) {
            $unit = $this->unit((string) $name);
            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * Current combined space of a target, or null when any of its units is not mounted
     * (a partial total would look like a sudden drop in usage).
     */
    public function spaceFor(Target $target): ?Space
    {
        $units = $this->unitsFor($target);
        if ($units === []) {
            return null;
        }

        $total = new Space(0, 0);
        foreach ($units as $unit) {
            $space = $this->platform->space($unit);
            if ($space === null) {
                return null;
            }

            $total = $total->plus($space);
        }

        return $total;
    }

    /**
     * Current space of one unit, or null when it is not mounted.
     */
    public function spaceOf(StorageUnit $unit): ?Space
    {
        return $this->platform->space($unit);
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Config;

/**
 * One thing being tracked and forecast.
 */
final class Target
{
    /** Reading intervals offered, in minutes. */
    public const INTERVALS = [15, 30, 60, 180, 360, 720, 1440];

    /** Trend windows offered, in days; 0 means all history. */
    public const WINDOWS = [7, 14, 30, 60, 90, 180, 365, 730, 0];

    /** Warning thresholds offered, in days; 0 means no warning. */
    public const WARNINGS = [0, 14, 30, 60, 90, 180, 365];

    /**
     * @param string $id Stable identifier; names the history file, never changes after creation.
     * @param string $name Name shown to the user.
     * @param TargetType $type What is measured.
     * @param list<string> $members Pool name, disk and pool names, or share name, depending on the type; empty for the array.
     * @param int $intervalMinutes Minutes between readings.
     * @param int $windowDays Days of history the trend is fitted to; 0 for all of it.
     * @param int $warnDays Notify when the estimate falls within this many days; 0 for never.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly TargetType $type,
        public readonly array $members,
        public readonly int $intervalMinutes,
        public readonly int $windowDays,
        public readonly int $warnDays,
    ) {
    }

    /**
     * The trend window in seconds, with "all history" as a century.
     */
    public function windowSeconds(): int
    {
        return ($this->windowDays === 0 ? 36525 : $this->windowDays) * 86400;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'members' => $this->members,
            'intervalMinutes' => $this->intervalMinutes,
            'windowDays' => $this->windowDays,
            'warnDays' => $this->warnDays,
        ];
    }

    /**
     * Rebuilds a target saved by {@see toArray()}, or null when the shape is wrong.
     *
     * @param mixed $data
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $type = TargetType::tryFrom((string) ($data['type'] ?? ''));
        $members = $data['members'] ?? null;
        if ($type === null || !is_array($members) || !is_string($data['id'] ?? null) || !is_string($data['name'] ?? null)) {
            return null;
        }

        return new self(
            $data['id'],
            $data['name'],
            $type,
            array_values(array_map('strval', $members)),
            (int) ($data['intervalMinutes'] ?? 60),
            (int) ($data['windowDays'] ?? 180),
            (int) ($data['warnDays'] ?? 0),
        );
    }
}

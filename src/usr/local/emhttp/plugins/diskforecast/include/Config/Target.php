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
     * Baseline for a new target: a reading every hour (about 440 KB of history a year), a
     * 180-day trend (the backtest's best window), and a warning 90 days before full, which
     * leaves time to buy, ship and preclear a drive.
     */
    public const BASELINE = ['intervalMinutes' => 60, 'windowDays' => 180, 'warnDays' => 90];

    /**
     * A target with the baseline settings, shown on the dashboard.
     *
     * @param list<string> $members
     */
    public static function withBaseline(string $id, string $name, TargetType $type, array $members): self
    {
        return new self($id, $name, $type, $members, self::BASELINE['intervalMinutes'], self::BASELINE['windowDays'], self::BASELINE['warnDays']);
    }

    /**
     * @param string $id Stable identifier; names the history file, never changes after creation.
     * @param string $name Name shown to the user.
     * @param TargetType $type What is measured.
     * @param list<string> $members Pool name, disk and pool names, or share name, depending on the type; empty for the array.
     * @param int $intervalMinutes Minutes between readings.
     * @param int $windowDays Days of history the trend is fitted to; 0 for all of it.
     * @param int $warnDays Notify when the estimate falls within this many days; 0 for never.
     * @param bool $onDashboard Whether the target is shown on the dashboard tile.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly TargetType $type,
        public readonly array $members,
        public readonly int $intervalMinutes,
        public readonly int $windowDays,
        public readonly int $warnDays,
        public readonly bool $onDashboard = true,
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
            'onDashboard' => $this->onDashboard,
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
            self::flag($data['onDashboard'] ?? true),
        );
    }

    /**
     * A yes/no value from JSON (true/false) or the settings file ("yes"/"no"); anything
     * missing or unrecognised counts as yes.
     */
    private static function flag(mixed $value): bool
    {
        return !in_array(is_string($value) ? strtolower($value) : $value, [false, 'no', 'false', '0', 0], true);
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Config;

/**
 * Everything the user configures.
 */
final class Settings
{
    /** Where readings are kept unless the user chooses otherwise. */
    public const DEFAULT_DATA_DIR = '/mnt/user/appdata/diskforecast';

    /**
     * @param string $dataDir Folder holding the reading history.
     * @param bool $sharesEnabled Whether share targets may be created.
     * @param list<Target> $targets What is tracked, in display order.
     */
    public function __construct(
        public readonly string $dataDir,
        public readonly bool $sharesEnabled,
        public readonly array $targets,
    ) {
    }

    /**
     * The target with the given id, or null.
     */
    public function target(string $id): ?Target
    {
        foreach ($this->targets as $target) {
            if ($target->id === $id) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataDir' => $this->dataDir,
            'sharesEnabled' => $this->sharesEnabled,
            'targets' => array_map(static fn (Target $t): array => $t->toArray(), $this->targets),
        ];
    }

    /**
     * Rebuilds settings saved by {@see toArray()}, dropping targets that do not parse.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $targets = [];
        foreach (is_array($data['targets'] ?? null) ? $data['targets'] : [] as $raw) {
            $target = Target::fromArray($raw);
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return new self(
            is_string($data['dataDir'] ?? null) ? $data['dataDir'] : self::DEFAULT_DATA_DIR,
            (bool) ($data['sharesEnabled'] ?? false),
            $targets,
        );
    }
}

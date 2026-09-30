<?php

declare(strict_types=1);

namespace DiskForecast\Config;

use DiskForecast\Storage\Inventory;

/**
 * Checks settings submitted from the settings page against the server's real storage.
 */
final class SettingsInput
{
    private const MAX_TARGETS = 50;
    private const MAX_NAME = 60;

    public function __construct(private readonly Inventory $inventory)
    {
    }

    /**
     * The settings to save, or null with the reasons they cannot be.
     *
     * @param mixed $raw Decoded JSON from the page.
     * @param Settings $current The settings in force, whose target ids are kept.
     * @return array{0: Settings|null, 1: list<string>}
     */
    public function parse(mixed $raw, Settings $current): array
    {
        if (!is_array($raw)) {
            return [null, ['The settings could not be read. Reload the page and try again.']];
        }

        $errors = [];
        $rawTargets = is_array($raw['targets'] ?? null) ? array_values($raw['targets']) : [];
        if (count($rawTargets) > self::MAX_TARGETS) {
            $errors[] = 'You can track up to ' . self::MAX_TARGETS . ' targets.';
            $rawTargets = array_slice($rawTargets, 0, self::MAX_TARGETS);
        }

        $targets = [];
        $usedIds = [];
        foreach ($rawTargets as $index => $rawTarget) {
            [$target, $targetErrors] = $this->parseTarget($rawTarget, $index + 1, $current, $usedIds);
            array_push($errors, ...$targetErrors);
            if ($target !== null) {
                $targets[] = $target;
                $usedIds[$target->id] = true;
            }
        }

        return $errors === [] ? [new Settings($targets), []] : [null, $errors];
    }

    /**
     * @param array<string, true> $usedIds
     * @return array{0: Target|null, 1: list<string>}
     */
    private function parseTarget(mixed $raw, int $position, Settings $current, array $usedIds): array
    {
        if (!is_array($raw)) {
            return [null, ["Target {$position} could not be read."]];
        }

        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($raw['name'] ?? '')) ?? '');
        $label = $name === '' ? "Target {$position}" : "Target {$position} ({$name})";
        $errors = [];
        if ($name === '') {
            $errors[] = "Target {$position}: give it a name.";
        } elseif ((int) preg_match_all('/./us', $name) > self::MAX_NAME) {
            $errors[] = "{$label}: the name can be at most " . self::MAX_NAME . ' characters.';
        } elseif (str_contains($name, '"')) {
            $errors[] = "{$label}: the name can't contain double quotes.";
        }

        $type = TargetType::tryFrom((string) ($raw['type'] ?? ''));
        $members = array_values(array_unique(array_map('strval', is_array($raw['members'] ?? null) ? $raw['members'] : [])));
        if ($type === null) {
            $errors[] = "{$label}: choose what to track.";
        } else {
            $memberError = $this->memberError($type, $members);
            if ($memberError !== null) {
                $errors[] = "{$label}: {$memberError}";
            }
        }

        $interval = (int) ($raw['intervalMinutes'] ?? 0);
        $window = (int) ($raw['windowDays'] ?? -1);
        $warn = (int) ($raw['warnDays'] ?? -1);
        if (!in_array($interval, Target::INTERVALS, true)) {
            $errors[] = "{$label}: choose a reading interval from the list.";
        }

        if (!in_array($window, Target::WINDOWS, true)) {
            $errors[] = "{$label}: choose a trend window from the list.";
        }

        if (!in_array($warn, Target::WARNINGS, true)) {
            $errors[] = "{$label}: choose a warning from the list.";
        }

        if ($errors !== [] || $type === null) {
            return [null, $errors];
        }

        $id = (string) ($raw['id'] ?? '');
        if ($current->target($id) === null || isset($usedIds[$id])) {
            $id = self::newId($name, $current, $usedIds);
        }

        $onDashboard = ($raw['onDashboard'] ?? true) !== false;

        return [new Target($id, $name, $type, $type === TargetType::Array ? [] : $members, $interval, $window, $warn, $onDashboard), []];
    }

    /**
     * @param list<string> $members
     */
    private function memberError(TargetType $type, array $members): ?string
    {
        switch ($type) {
            case TargetType::Array:
                return null;
            case TargetType::Disks:
                if ($members === []) {
                    return 'choose at least one disk or pool.';
                }

                foreach ($members as $member) {
                    if ($this->inventory->unit($member) === null) {
                        return "\"{$member}\" is not a disk or pool on this server.";
                    }
                }

                return null;
            case TargetType::Share:
                return count($members) === 1 && $this->inventory->share($members[0]) !== null ? null : 'choose a share.';
        }

        return null;
    }

    /**
     * @param array<string, true> $usedIds
     */
    private static function newId(string $name, Settings $current, array $usedIds): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base === '' ? 'target' : $base, 0, 32);
        $id = $base;
        for ($n = 2; $current->target($id) !== null || isset($usedIds[$id]); $n++) {
            $id = "{$base}-{$n}";
        }

        return $id;
    }
}

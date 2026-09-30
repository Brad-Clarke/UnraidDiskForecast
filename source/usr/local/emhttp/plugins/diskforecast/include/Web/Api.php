<?php

declare(strict_types=1);

namespace DiskForecast\Web;

use DiskForecast\Composition;
use DiskForecast\Config\SettingsInput;
use DiskForecast\Config\Target;
use DiskForecast\Config\TargetType;
use DiskForecast\Storage\UnitKind;

/**
 * The JSON endpoints the pages call. Reads are GET; saving settings is a POST, which
 * Unraid's webGui guards with its CSRF token before this code runs.
 */
final class Api
{
    public function __construct(private readonly Composition $app)
    {
    }

    /**
     * Handles one request.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @return array{0: int, 1: array<string, mixed>} HTTP status and JSON body.
     */
    public function handle(string $method, array $query, array $post): array
    {
        $action = (string) ($query['action'] ?? '');

        return match (true) {
            $method === 'GET' && $action === 'overview' => $this->overview(),
            $method === 'GET' && $action === 'series' => $this->series((string) ($query['target'] ?? ''), (int) ($query['span'] ?? 0), (float) ($query['addTb'] ?? 0)),
            $method === 'GET' && $action === 'settings' => $this->settings(),
            $method === 'POST' && $action === 'save' => $this->save((string) ($post['settings'] ?? '')),
            default => [400, ['error' => 'Unknown request.']],
        };
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function overview(): array
    {
        $settings = $this->app->settings();
        $targets = [];
        foreach ($settings->targets as $target) {
            $targets[] = ForecastView::summary($target, $this->app->forecasts()->forecast($target), $this->describe($target));
        }

        return [200, [
            'saved' => $this->app->hasSavedSettings(),
            'dataDirAvailable' => $this->app->dataDirAvailable(),
            'dataDir' => $this->app->dataDir(),
            'targets' => $targets,
        ]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function series(string $targetId, int $spanDays, float $addTb): array
    {
        $target = $this->target($targetId);
        if ($target === null) {
            return [404, ['error' => 'That target no longer exists.']];
        }

        if (!self::validDriveSize($addTb)) {
            return [400, ['error' => 'Enter a size between 0 and 10,000 TB.']];
        }

        $forecasts = $this->app->forecasts();
        $extraBytes = $addTb * 1e12;
        $forecast = $extraBytes > 0 ? $forecasts->forecastWithExtra($target, $extraBytes) : $forecasts->forecast($target)->forecast;

        return [200, ForecastView::series($forecasts->history($target), $forecast, max(0, $spanDays), $extraBytes)];
    }

    private static function validDriveSize(float $tb): bool
    {
        return is_finite($tb) && $tb >= 0 && $tb <= 10000;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function settings(): array
    {
        $inventory = $this->app->inventory();
        $disks = [];
        $pools = [];
        foreach ($inventory->units() as $unit) {
            $space = $inventory->spaceOf($unit);
            $row = ['name' => $unit->name, 'size' => $space?->size, 'free' => $space?->free];
            if ($unit->kind === UnitKind::Disk) {
                $disks[] = $row;
            } else {
                $pools[] = $row;
            }
        }

        $shares = [];
        foreach ($inventory->shares() as $share) {
            $shares[] = ['name' => $share->name, 'units' => $share->units];
        }

        $history = [];
        foreach ($this->app->settings()->targets as $target) {
            $result = $this->app->forecasts()->forecast($target);
            $history[$target->id] = ['readings' => $result->readings, 'firstReading' => $result->firstReading];
        }

        return [200, [
            'settings' => $this->app->settings()->toArray(),
            'saved' => $this->app->hasSavedSettings(),
            'dataDir' => $this->app->dataDir(),
            'history' => (object) $history,
            'inventory' => ['disks' => $disks, 'pools' => $pools, 'shares' => $shares],
            'options' => [
                'intervals' => Target::INTERVALS,
                'windows' => Target::WINDOWS,
                'warnings' => Target::WARNINGS,
            ],
        ]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function save(string $json): array
    {
        $raw = json_decode($json, true);
        $previous = $this->app->settings();
        $input = new SettingsInput($this->app->inventory());
        [$settings, $errors] = $input->parse($raw, $previous);
        if ($settings === null) {
            return [422, ['errors' => $errors]];
        }

        if (!$this->app->saveSettings($settings)) {
            return [500, ['errors' => ['The settings could not be written to the flash drive. Check the system log.']]];
        }

        $deleted = [];
        $notDeleted = [];
        $store = $this->app->historyStore();
        foreach ($previous->targets as $target) {
            if ($settings->target($target->id) === null) {
                if ($store->delete($target->id)) {
                    $deleted[] = $target->name;
                } else {
                    $notDeleted[] = $target->name;
                }
            }
        }

        return [200, ['settings' => $settings->toArray(), 'deleted' => $deleted, 'notDeleted' => $notDeleted]];
    }

    /**
     * What a target measures, for the row under its name: "Whole array", "Share · Media",
     * "Pool · cache", "Disk · disk6" or a count such as "2 disks + 1 pool".
     */
    private function describe(Target $target): string
    {
        if ($target->type === TargetType::Array) {
            return 'Whole array';
        }

        if ($target->type === TargetType::Share) {
            return 'Share · ' . ($target->members[0] ?? '');
        }

        $inventory = $this->app->inventory();
        if (count($target->members) === 1) {
            $unit = $inventory->unit($target->members[0]);

            return ($unit?->kind === UnitKind::Pool ? 'Pool · ' : 'Disk · ') . $target->members[0];
        }

        $disks = 0;
        $pools = 0;
        foreach ($target->members as $member) {
            if ($inventory->unit($member)?->kind === UnitKind::Pool) {
                $pools++;
            } else {
                $disks++;
            }
        }

        $parts = [];
        if ($disks > 0) {
            $parts[] = $disks . ($disks === 1 ? ' disk' : ' disks');
        }

        if ($pools > 0) {
            $parts[] = $pools . ($pools === 1 ? ' pool' : ' pools');
        }

        return implode(' + ', $parts);
    }

    private function target(string $id): ?Target
    {
        return $this->app->settings()->target($id);
    }
}

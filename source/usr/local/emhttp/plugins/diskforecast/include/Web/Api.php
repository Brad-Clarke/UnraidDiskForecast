<?php

declare(strict_types=1);

namespace DiskForecast\Web;

use DiskForecast\Composition;
use DiskForecast\Config\SettingsInput;
use DiskForecast\Config\Target;
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
            $method === 'GET' && $action === 'series' => $this->series((string) ($query['target'] ?? ''), (int) ($query['span'] ?? 0)),
            $method === 'GET' && $action === 'whatif' => $this->whatIf((string) ($query['target'] ?? ''), (float) ($query['addTb'] ?? 0)),
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
            $targets[] = ForecastView::summary($target, $this->app->forecasts()->forecast($target));
        }

        return [200, [
            'saved' => $this->app->hasSavedSettings(),
            'dataDirAvailable' => $this->app->platform()->pathAvailable($settings->dataDir),
            'dataDir' => $settings->dataDir,
            'targets' => $targets,
        ]];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function series(string $targetId, int $spanDays): array
    {
        $target = $this->target($targetId);
        if ($target === null) {
            return [404, ['error' => 'That target no longer exists.']];
        }

        $forecasts = $this->app->forecasts();

        return [200, ForecastView::series($forecasts->history($target), $forecasts->forecast($target)->forecast, max(0, $spanDays))];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function whatIf(string $targetId, float $addTb): array
    {
        $target = $this->target($targetId);
        if ($target === null) {
            return [404, ['error' => 'That target no longer exists.']];
        }

        if (!is_finite($addTb) || $addTb <= 0 || $addTb > 10000) {
            return [400, ['error' => 'Enter a size between 0 and 10,000 TB.']];
        }

        return [200, ForecastView::times($this->app->forecasts()->forecastWithExtra($target, $addTb * 1e12))];
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

        return [200, [
            'settings' => $this->app->settings()->toArray(),
            'saved' => $this->app->hasSavedSettings(),
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
        $input = new SettingsInput($this->app->platform(), $this->app->inventory());
        [$settings, $errors] = $input->parse($raw, $this->app->settings());
        if ($settings === null) {
            return [422, ['errors' => $errors]];
        }

        if (!$this->app->saveSettings($settings)) {
            return [500, ['errors' => ['The settings could not be written to the flash drive. Check the system log.']]];
        }

        return [200, ['settings' => $settings->toArray()]];
    }

    private function target(string $id): ?Target
    {
        return $this->app->settings()->target($id);
    }
}

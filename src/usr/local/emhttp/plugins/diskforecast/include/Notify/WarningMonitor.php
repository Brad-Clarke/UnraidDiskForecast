<?php

declare(strict_types=1);

namespace DiskForecast\Notify;

use DiskForecast\Config\Settings;
use DiskForecast\Config\Target;
use DiskForecast\Forecast\Forecast;
use DiskForecast\Forecast\ForecastService;
use DiskForecast\Forecast\ForecastStatus;
use DiskForecast\Format;
use DiskForecast\Logging\Logger;

/**
 * Notifies when a target's estimate falls inside its warning threshold: once when it
 * first does, then weekly while it stays there.
 */
final class WarningMonitor
{
    private const REPEAT_SECONDS = 7 * 86400;

    public function __construct(
        private readonly ForecastService $forecasts,
        private readonly Notifier $notifier,
        private readonly string $statePath,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Whether a forecast is inside the target's warning threshold.
     */
    public static function isWarning(Target $target, Forecast $forecast): bool
    {
        if ($target->warnDays <= 0) {
            return false;
        }

        return $forecast->status === ForecastStatus::Full
            || ($forecast->secondsToFull !== null && $forecast->secondsToFull <= $target->warnDays * 86400);
    }

    /**
     * Checks every target with a threshold and sends what is due.
     */
    public function check(Settings $settings, int $now): void
    {
        $state = $this->loadState();
        $changed = false;
        foreach ($settings->targets as $target) {
            $forecast = $target->warnDays > 0 ? $this->forecasts->forecast($target)->forecast : null;
            if ($forecast === null || !self::isWarning($target, $forecast)) {
                if (isset($state[$target->id])) {
                    unset($state[$target->id]);
                    $changed = true;
                }

                continue;
            }

            $last = $state[$target->id] ?? null;
            if ($last !== null && $now - $last < self::REPEAT_SECONDS) {
                continue;
            }

            $this->send($target, $forecast);
            $state[$target->id] = $now;
            $changed = true;
        }

        if ($changed) {
            $this->saveState($state);
        }
    }

    private function send(Target $target, Forecast $forecast): void
    {
        $latest = $forecast->latest;
        $space = $latest === null ? '' : ' ' . Format::bytes($latest->free) . ' free of ' . Format::bytes($latest->size) . '.';
        if ($forecast->status === ForecastStatus::Full) {
            $this->notifier->notify("{$target->name} is full", "{$target->name} has no free space left.{$space}", 'alert');

            return;
        }

        $when = Format::duration((float) $forecast->secondsToFull);
        $date = Format::date($forecast->time + (int) $forecast->secondsToFull);
        $range = '';
        if ($forecast->earliestSeconds !== null) {
            $earliest = Format::date($forecast->time + (int) $forecast->earliestSeconds);
            $latest = $forecast->latestSeconds === null ? 'much later' : Format::date($forecast->time + (int) $forecast->latestSeconds);
            $range = $earliest === $latest ? '' : " Likely between {$earliest} and {$latest}.";
        }

        $this->notifier->notify(
            "{$target->name} predicted full in {$when}",
            "At its current rate {$target->name} fills around {$date}.{$range}{$space}",
            'warning',
        );
    }

    /**
     * @return array<string, int>
     */
    private function loadState(): array
    {
        if (!is_file($this->statePath)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($this->statePath), true);
        if (!is_array($data)) {
            $this->logger->warning("Warning state {$this->statePath} was unreadable; starting afresh.");

            return [];
        }

        return array_map('intval', $data);
    }

    /**
     * @param array<string, int> $state
     */
    private function saveState(array $state): void
    {
        $directory = dirname($this->statePath);
        if ((!is_dir($directory) && !@mkdir($directory, 0777, true)) || @file_put_contents($this->statePath, json_encode($state)) === false) {
            $this->logger->warning("Could not save warning state to {$this->statePath}.");
        }
    }
}

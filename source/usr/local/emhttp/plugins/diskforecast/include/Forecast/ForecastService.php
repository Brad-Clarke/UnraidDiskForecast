<?php

declare(strict_types=1);

namespace DiskForecast\Forecast;

use DiskForecast\Config\Target;
use DiskForecast\History;
use DiskForecast\HistoryStore;
use DiskForecast\Logging\Logger;

/**
 * Forecasts targets from their stored history, remembering each result until the history
 * or the target's window changes. The cache lives in RAM (/tmp), never on the flash drive.
 */
final class ForecastService
{
    /** Bumped whenever the forecast maths changes, so old cached results are ignored. */
    private const CACHE_VERSION = 7;

    public function __construct(
        private readonly HistoryStore $store,
        private readonly string $cacheDir,
        private readonly Logger $logger,
    ) {
    }

    /**
     * The target's history.
     */
    public function history(Target $target): History
    {
        return $this->store->read($target->id);
    }

    /**
     * The target's forecast as of its newest reading.
     */
    public function forecast(Target $target): TargetForecast
    {
        [$size, $modified] = $this->store->fingerprint($target->id);
        $key = md5(implode('|', [self::CACHE_VERSION, $target->id, $target->windowDays, $size, $modified]));
        $path = "{$this->cacheDir}/{$target->id}-{$key}.ser";
        $cached = $this->readCache($path);
        if ($cached !== null) {
            return $cached;
        }

        $history = $this->history($target);
        $result = new TargetForecast(
            Forecaster::standard($target->windowSeconds())->forecast($history),
            $history->first()?->time,
            $history->count(),
        );
        $this->writeCache($target->id, $path, $result);

        return $result;
    }

    /**
     * The target's forecast if the given number of bytes were added to it now. Not cached.
     */
    public function forecastWithExtra(Target $target, float $extraBytes): Forecast
    {
        return Forecaster::standard($target->windowSeconds())->forecast($this->history($target), null, $extraBytes);
    }

    private function readCache(string $path): ?TargetForecast
    {
        if (!is_file($path)) {
            return null;
        }

        $data = @file_get_contents($path);
        $result = $data === false ? false : @unserialize($data);
        if (!$result instanceof TargetForecast) {
            $this->logger->warning("Ignored unreadable cached forecast {$path}.");
            @unlink($path);

            return null;
        }

        return $result;
    }

    private function writeCache(string $targetId, string $path, TargetForecast $result): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
            $this->logger->warning("Could not create forecast cache folder {$this->cacheDir}.");

            return;
        }

        foreach (glob("{$this->cacheDir}/{$targetId}-*.ser") ?: [] as $stale) {
            @unlink($stale);
        }

        if (@file_put_contents($path, serialize($result)) === false) {
            $this->logger->warning("Could not write cached forecast {$path}.");
        }
    }
}

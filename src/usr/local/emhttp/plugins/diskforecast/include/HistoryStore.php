<?php

declare(strict_types=1);

namespace DiskForecast;

use DiskForecast\Logging\Logger;

/**
 * The reading history of every target, one CSV file per target in the readings folder.
 */
final class HistoryStore
{
    public function __construct(
        private readonly string $dataDir,
        private readonly Logger $logger,
    ) {
    }

    /**
     * The history file of a target.
     */
    public function path(string $targetId): string
    {
        return "{$this->dataDir}/history/{$targetId}.csv";
    }

    /**
     * The whole history of a target; empty when it has none yet.
     */
    public function read(string $targetId): History
    {
        $history = HistoryFile::read($this->path($targetId), $skipped);
        if ($skipped > 0) {
            $this->logger->warning("Skipped {$skipped} unreadable line(s) in " . $this->path($targetId) . '.');
        }

        return $history;
    }

    /**
     * Unix time of the newest reading, read from the end of the file, or null when there is none.
     */
    public function lastTime(string $targetId): ?int
    {
        $path = $this->path($targetId);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        fseek($handle, max(0, $size - 256));
        $tail = (string) fread($handle, 256);
        fclose($handle);
        $lines = array_filter(explode("\n", trim($tail)), 'strlen');
        $last = end($lines);
        if ($last === false) {
            return null;
        }

        $time = strstr($last, ',', true);

        return $time !== false && preg_match('/^\d{1,19}$/', $time) === 1 ? (int) $time : null;
    }

    /**
     * Appends one reading, creating the file and folder when needed. Returns false when it could not be written.
     */
    public function append(string $targetId, Sample $sample): bool
    {
        $path = $this->path($targetId);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->logger->warning("Could not create readings folder {$directory}.");

            return false;
        }

        $isNew = !is_file($path) || filesize($path) === 0;
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            $this->logger->warning("Could not open {$path} to add a reading.");

            return false;
        }

        flock($handle, LOCK_EX);
        $written = fwrite($handle, ($isNew ? HistoryFile::HEADER . "\n" : '') . HistoryFile::format($sample) . "\n");
        flock($handle, LOCK_UN);
        fclose($handle);
        if ($written === false) {
            $this->logger->warning("Could not write a reading to {$path}.");

            return false;
        }

        return true;
    }

    /**
     * Deletes a target's history. Returns true when it is gone (or never existed).
     */
    public function delete(string $targetId): bool
    {
        $path = $this->path($targetId);
        if (!is_file($path)) {
            return true;
        }

        if (!@unlink($path)) {
            $this->logger->warning("Could not delete the readings in {$path}.");

            return false;
        }

        $this->logger->info("Deleted the readings in {$path}.");

        return true;
    }

    /**
     * Size and modification time of a target's history, for cache keys; zeros when it has none.
     *
     * @return array{0: int, 1: int}
     */
    public function fingerprint(string $targetId): array
    {
        $path = $this->path($targetId);
        clearstatcache(true, $path);

        return is_file($path) ? [(int) filesize($path), (int) filemtime($path)] : [0, 0];
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast;

use DiskForecast\Logging\Logger;

/**
 * The reading history of every target: one CSV file per target on the flash drive, plus the
 * readings taken since the last save, held in RAM.
 *
 * Each reading is appended to the RAM file; {@see save()} moves them onto the flash drive,
 * once a day and when the array stops, so the USB stick sees one small append per target per
 * day rather than a write at every reading. An unclean shutdown loses at most the readings
 * since the last save.
 */
final class HistoryStore
{
    private const MARKER = '.last-save';

    /**
     * @param string $savedDir Folder of saved histories on the flash drive.
     * @param string $pendingDir Folder of not-yet-saved readings in RAM.
     */
    public function __construct(
        private readonly string $savedDir,
        private readonly string $pendingDir,
        private readonly Logger $logger,
    ) {
    }

    /**
     * The saved history file of a target on the flash drive.
     */
    public function savedPath(string $targetId): string
    {
        return "{$this->savedDir}/{$targetId}.csv";
    }

    /**
     * The whole history of a target, saved and pending; empty when it has none yet.
     */
    public function read(string $targetId): History
    {
        $samples = [];
        foreach ([$this->savedPath($targetId), $this->pendingPath($targetId)] as $path) {
            $history = HistoryFile::read($path, $skipped);
            if ($skipped > 0) {
                $this->logger->warning("Skipped {$skipped} unreadable line(s) in {$path}.");
            }

            array_push($samples, ...$history->samples());
        }

        return History::fromSamples($samples);
    }

    /**
     * Unix time of the newest reading, read from the ends of the files, or null when there is none.
     */
    public function lastTime(string $targetId): ?int
    {
        $pending = self::tailTime($this->pendingPath($targetId));

        return $pending ?? self::tailTime($this->savedPath($targetId));
    }

    /**
     * Adds one reading to the pending (RAM) file. Returns false when it could not be written.
     */
    public function append(string $targetId, Sample $sample): bool
    {
        if (!self::ensureDir($this->pendingDir, $this->logger)) {
            return false;
        }

        $path = $this->pendingPath($targetId);
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            $this->logger->warning("Could not open {$path} to add a reading.");

            return false;
        }

        flock($handle, LOCK_EX);
        $written = fwrite($handle, HistoryFile::format($sample) . "\n");
        flock($handle, LOCK_UN);
        fclose($handle);
        if ($written === false) {
            $this->logger->warning("Could not write a reading to {$path}.");

            return false;
        }

        return true;
    }

    /**
     * Whether a save is due: the last one was at least the interval ago. The first check after
     * a reboot only starts the clock, since there is nothing pending yet.
     */
    public function saveDue(int $now, int $intervalSeconds): bool
    {
        $marker = "{$this->pendingDir}/" . self::MARKER;
        clearstatcache(true, $marker);
        if (!is_file($marker)) {
            if (self::ensureDir($this->pendingDir, $this->logger)) {
                @touch($marker, $now);
            }

            return false;
        }

        return $now - (int) filemtime($marker) >= $intervalSeconds;
    }

    /**
     * Moves every pending reading onto the flash drive. Returns false when any could not be
     * saved; those stay pending and are tried again next time.
     */
    public function save(int $now): bool
    {
        $ok = true;
        foreach (glob("{$this->pendingDir}/*.csv") ?: [] as $pending) {
            $ok = $this->saveOne(basename($pending, '.csv'), $pending) && $ok;
        }

        if ($ok && self::ensureDir($this->pendingDir, $this->logger)) {
            @touch("{$this->pendingDir}/" . self::MARKER, $now);
        }

        return $ok;
    }

    /**
     * Deletes a target's history, saved and pending. Returns true when it is gone (or never existed).
     */
    public function delete(string $targetId): bool
    {
        $ok = true;
        foreach ([$this->savedPath($targetId), $this->pendingPath($targetId)] as $path) {
            if (is_file($path) && !@unlink($path)) {
                $this->logger->warning("Could not delete the readings in {$path}.");
                $ok = false;
            }
        }

        if ($ok) {
            $this->logger->info("Deleted the readings of {$targetId}.");
        }

        return $ok;
    }

    /**
     * Sizes and modification times of a target's files, for cache keys.
     *
     * @return list<int>
     */
    public function fingerprint(string $targetId): array
    {
        $parts = [];
        foreach ([$this->savedPath($targetId), $this->pendingPath($targetId)] as $path) {
            clearstatcache(true, $path);
            array_push($parts, ...(is_file($path) ? [(int) filesize($path), (int) filemtime($path)] : [0, 0]));
        }

        return $parts;
    }

    private function pendingPath(string $targetId): string
    {
        return "{$this->pendingDir}/{$targetId}.csv";
    }

    private function saveOne(string $targetId, string $pendingPath): bool
    {
        $pending = @fopen($pendingPath, 'r+b');
        if ($pending === false) {
            $this->logger->warning("Could not open {$pendingPath} to save its readings.");

            return false;
        }

        flock($pending, LOCK_EX);
        $lines = stream_get_contents($pending);
        $ok = true;
        if (is_string($lines) && trim($lines) !== '') {
            $ok = $this->appendSaved($targetId, $lines);
            if ($ok) {
                ftruncate($pending, 0);
            }
        }

        flock($pending, LOCK_UN);
        fclose($pending);

        return $ok;
    }

    private function appendSaved(string $targetId, string $lines): bool
    {
        if (!self::ensureDir($this->savedDir, $this->logger)) {
            return false;
        }

        $path = $this->savedPath($targetId);
        $isNew = !is_file($path) || filesize($path) === 0;
        $written = @file_put_contents($path, ($isNew ? HistoryFile::HEADER . "\n" : '') . $lines, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            $this->logger->warning("Could not save readings to {$path}; they stay pending.");

            return false;
        }

        return true;
    }

    private static function tailTime(string $path): ?int
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            return null;
        }

        $handle = @fopen($path, 'rb');
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

    private static function ensureDir(string $directory, Logger $logger): bool
    {
        if (is_dir($directory) || @mkdir($directory, 0777, true) || is_dir($directory)) {
            return true;
        }

        $logger->warning("Could not create folder {$directory}.");

        return false;
    }
}

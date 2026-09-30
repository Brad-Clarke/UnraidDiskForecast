<?php

declare(strict_types=1);

namespace DiskForecast;

/**
 * Reads and writes a target's history as CSV lines of "time,size,used,free".
 */
final class HistoryFile
{
    /** Header line written at the top of every file. */
    public const HEADER = 'time,size,used,free';

    private function __construct()
    {
    }

    /**
     * Loads a history, skipping lines that do not parse. A missing file is an empty history.
     *
     * @param int $skipped Receives the number of lines that were not readings.
     */
    public static function read(string $path, ?int &$skipped = null): History
    {
        $skipped = 0;
        if (!is_file($path)) {
            return History::fromSamples([]);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return History::fromSamples([]);
        }

        $samples = [];
        foreach ($lines as $line) {
            if ($line === self::HEADER) {
                continue;
            }

            $sample = self::parse($line);
            if ($sample === null) {
                $skipped++;
                continue;
            }

            $samples[] = $sample;
        }

        return History::fromSamples($samples);
    }

    /**
     * Writes the whole history, replacing the file.
     */
    public static function write(string $path, History $history): void
    {
        $lines = [self::HEADER];
        foreach ($history->samples() as $sample) {
            $lines[] = self::format($sample);
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * One CSV line for the reading, without a line ending.
     */
    public static function format(Sample $sample): string
    {
        return "{$sample->time},{$sample->size},{$sample->used},{$sample->free}";
    }

    private static function parse(string $line): ?Sample
    {
        $fields = explode(',', trim($line));
        if (count($fields) !== 4) {
            return null;
        }

        foreach ($fields as $field) {
            if (preg_match('/^\d{1,19}$/', $field) !== 1) {
                return null;
            }
        }

        return new Sample((int) $fields[0], (int) $fields[1], (int) $fields[2], (int) $fields[3]);
    }
}

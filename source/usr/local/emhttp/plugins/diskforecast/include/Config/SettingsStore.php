<?php

declare(strict_types=1);

namespace DiskForecast\Config;

use DiskForecast\Logging\Logger;
use JsonException;

/**
 * Loads and saves the settings file. It is only written when the user saves, so it can
 * live on the flash drive without wearing it.
 */
final class SettingsStore
{
    public function __construct(
        private readonly string $path,
        private readonly Logger $logger,
    ) {
    }

    /**
     * The saved settings, or null when nothing has been saved or the file cannot be read.
     */
    public function load(): ?Settings
    {
        if (!is_file($this->path)) {
            return null;
        }

        $json = file_get_contents($this->path);
        if ($json === false) {
            $this->logger->warning("Could not read settings file {$this->path}; using defaults.");

            return null;
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning("Settings file {$this->path} is not valid JSON ({$e->getMessage()}); using defaults.");

            return null;
        }

        return is_array($data) ? Settings::fromArray($data) : null;
    }

    /**
     * Writes the settings, replacing the file atomically. Returns false when it could not be written.
     */
    public function save(Settings $settings): bool
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->logger->warning("Could not create settings folder {$directory}.");

            return false;
        }

        $temporary = $this->path . '.tmp';
        $json = json_encode($settings->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $this->path)) {
            $this->logger->warning("Could not write settings file {$this->path}.");

            return false;
        }

        return true;
    }
}

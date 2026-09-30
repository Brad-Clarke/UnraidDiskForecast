<?php

declare(strict_types=1);

namespace DiskForecast\Config;

use DiskForecast\Logging\Logger;

/**
 * Loads and saves the plugin's settings as Unraid's usual key="value" file
 * (/boot/config/plugins/diskforecast/diskforecast.cfg) over the packaged default.cfg.
 *
 * Targets are stored as numbered keys (TARGETS="2", TARGET_1_NAME="Array", ...). While
 * AUTO_TARGETS is "yes" (the packaged default) the plugin tracks the whole array and each pool.
 * The file is only written when the user saves, so it can live on the flash drive.
 */
final class SettingsStore
{
    /** Fields stored for each target, as key suffix => Target::toArray() key. */
    private const FIELDS = [
        'ID' => 'id',
        'NAME' => 'name',
        'TYPE' => 'type',
        'MEMBERS' => 'members',
        'INTERVAL' => 'intervalMinutes',
        'WINDOW' => 'windowDays',
        'WARN' => 'warnDays',
        'DASHBOARD' => 'onDashboard',
    ];

    /**
     * @param string $path The user's settings file on the flash drive.
     * @param string $defaultPath The packaged default.cfg.
     * @param (callable(): array<string, string>)|null $pluginCfg Unraid's parse_plugin_cfg for this plugin, when it is loaded.
     */
    public function __construct(
        private readonly string $path,
        private readonly string $defaultPath,
        private readonly Logger $logger,
        private readonly mixed $pluginCfg = null,
    ) {
    }

    /**
     * The saved settings, or null while the plugin is on its automatic defaults.
     */
    public function load(): ?Settings
    {
        $cfg = $this->pluginCfg !== null ? ($this->pluginCfg)() : $this->read();
        if (($cfg['AUTO_TARGETS'] ?? 'yes') === 'yes') {
            return null;
        }

        $targets = [];
        $count = max(0, (int) ($cfg['TARGETS'] ?? 0));
        for ($n = 1; $n <= $count; $n++) {
            $raw = [];
            foreach (self::FIELDS as $suffix => $field) {
                $raw[$field] = (string) ($cfg["TARGET_{$n}_{$suffix}"] ?? '');
            }

            $raw['members'] = array_values(array_filter(explode(',', $raw['members']), 'strlen'));
            $target = Target::fromArray($raw);
            if ($target === null || $target->id === '') {
                $this->logger->warning("Skipped target {$n} in {$this->path}: it is incomplete.");
                continue;
            }

            $targets[] = $target;
        }

        return new Settings($targets);
    }

    /**
     * Writes the settings, replacing the file atomically. Returns false when it could not be written.
     */
    public function save(Settings $settings): bool
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->logger->warning("Could not create settings folder {$directory}.");

            return false;
        }

        $lines = ['AUTO_TARGETS="no"', 'TARGETS="' . count($settings->targets) . '"'];
        foreach ($settings->targets as $index => $target) {
            $n = $index + 1;
            $values = $target->toArray();
            $values['members'] = implode(',', $target->members);
            $values['onDashboard'] = $target->onDashboard ? 'yes' : 'no';
            foreach (self::FIELDS as $suffix => $field) {
                $lines[] = "TARGET_{$n}_{$suffix}=\"" . str_replace('"', '', (string) $values[$field]) . '"';
            }
        }

        $temporary = $this->path . '.tmp';
        if (@file_put_contents($temporary, implode("\n", $lines) . "\n") === false || !@rename($temporary, $this->path)) {
            $this->logger->warning("Could not write settings file {$this->path}.");

            return false;
        }

        return true;
    }

    /**
     * The packaged defaults overlaid with the user's file, as parse_plugin_cfg does.
     *
     * @return array<string, string>
     */
    private function read(): array
    {
        return array_replace(self::parse($this->defaultPath), self::parse($this->path));
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $data = @parse_ini_file($path, false, INI_SCANNER_RAW);

        return is_array($data) ? array_map(static fn (mixed $value): string => trim((string) $value, '"'), $data) : [];
    }
}

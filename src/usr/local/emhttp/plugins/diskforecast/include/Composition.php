<?php

declare(strict_types=1);

namespace DiskForecast;

use DiskForecast\Config\Settings;
use DiskForecast\Config\SettingsStore;
use DiskForecast\Config\Target;
use DiskForecast\Config\TargetType;
use DiskForecast\Forecast\ForecastService;
use DiskForecast\Logging\Logger;
use DiskForecast\Logging\SyslogLogger;
use DiskForecast\Notify\Notifier;
use DiskForecast\Notify\UnraidNotifier;
use DiskForecast\Notify\WarningMonitor;
use DiskForecast\Sampling\Sampler;
use DiskForecast\Storage\Inventory;
use DiskForecast\Storage\Platform;
use DiskForecast\Storage\UnitKind;
use DiskForecast\Storage\UnraidPlatform;
use DiskForecast\Web\Api;

/**
 * Builds the plugin's objects for one request or cron run, each once.
 */
final class Composition
{
    private ?Settings $settings = null;
    private ?bool $saved = null;
    private ?string $dataDir = null;
    private ?Inventory $inventory = null;
    private ?ForecastService $forecasts = null;

    public function __construct(
        private readonly Platform $platform,
        private readonly SettingsStore $settingsStore,
        private readonly string $cacheDir,
        private readonly Logger $logger,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * The composition used on a real Unraid server.
     */
    public static function production(): self
    {
        $logger = new SyslogLogger();
        $wrappers = '/usr/local/emhttp/webGui/include/Wrappers.php';
        if (!function_exists('parse_plugin_cfg') && is_file($wrappers)) {
            require_once $wrappers;
        }

        return new self(
            new UnraidPlatform(),
            new SettingsStore(
                '/boot/config/plugins/diskforecast/diskforecast.cfg',
                '/usr/local/emhttp/plugins/diskforecast/default.cfg',
                $logger,
                function_exists('parse_plugin_cfg') ? static fn (): array => parse_plugin_cfg('diskforecast') : null,
            ),
            '/tmp/diskforecast',
            $logger,
            new UnraidNotifier($logger),
        );
    }

    public function platform(): Platform
    {
        return $this->platform;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function inventory(): Inventory
    {
        return $this->inventory ??= new Inventory($this->platform);
    }

    /**
     * The saved settings, or defaults (the array and each pool) when nothing is saved yet.
     */
    public function settings(): Settings
    {
        if ($this->settings === null) {
            $loaded = $this->settingsStore->load();
            $this->saved = $loaded !== null;
            $this->settings = $loaded ?? $this->defaults();
        }

        return $this->settings;
    }

    /**
     * Whether the user has saved settings; false while defaults are in use.
     */
    public function hasSavedSettings(): bool
    {
        $this->settings();

        return (bool) $this->saved;
    }

    /**
     * Saves new settings and uses them for the rest of this request.
     */
    public function saveSettings(Settings $settings): bool
    {
        if (!$this->settingsStore->save($settings)) {
            return false;
        }

        $this->settings = $settings;
        $this->saved = true;
        $this->forecasts = null;

        return true;
    }

    /**
     * The readings folder, chosen by the platform (the pool that holds appdata).
     */
    public function dataDir(): string
    {
        return $this->dataDir ??= $this->platform->dataDir();
    }

    /**
     * Whether the readings folder can be used right now (false while the array is stopped).
     */
    public function dataDirAvailable(): bool
    {
        return $this->platform->pathAvailable($this->dataDir());
    }

    public function historyStore(): HistoryStore
    {
        return new HistoryStore($this->dataDir(), $this->logger);
    }

    public function forecasts(): ForecastService
    {
        return $this->forecasts ??= new ForecastService($this->historyStore(), $this->cacheDir . '/forecasts', $this->logger);
    }

    public function sampler(): Sampler
    {
        return new Sampler($this->settings(), $this->platform, $this->inventory(), $this->historyStore(), $this->dataDir());
    }

    public function warningMonitor(): WarningMonitor
    {
        return new WarningMonitor($this->forecasts(), $this->notifier, $this->dataDir() . '/state/warnings.json', $this->logger);
    }

    public function api(): Api
    {
        return new Api($this);
    }

    private function defaults(): Settings
    {
        $targets = [];
        $hasDisks = false;
        foreach ($this->inventory()->units() as $unit) {
            if ($unit->kind === UnitKind::Disk) {
                $hasDisks = true;
                continue;
            }

            $targets[] = new Target('pool-' . $unit->name, ucfirst($unit->name), TargetType::Disks, [$unit->name], 60, 180, 0);
        }

        if ($hasDisks) {
            array_unshift($targets, new Target('array', 'Array', TargetType::Array, [], 60, 180, 0));
        }

        return new Settings($targets);
    }
}

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
    /** How often pending readings are saved to the flash drive, besides when the array stops. */
    public const SAVE_INTERVAL_SECONDS = 86400;

    private ?Settings $settings = null;
    private ?bool $saved = null;
    private ?Inventory $inventory = null;
    private ?ForecastService $forecasts = null;

    /**
     * @param string $pluginDir The plugin's folder on the flash drive: saved readings and warning state.
     * @param string $runtimeDir The plugin's folder in RAM: pending readings, forecast cache, lock.
     * @param Format $format Date and number format for notifications, as set in Unraid.
     */
    public function __construct(
        private readonly Platform $platform,
        private readonly SettingsStore $settingsStore,
        private readonly string $pluginDir,
        private readonly string $runtimeDir,
        private readonly Logger $logger,
        private readonly Notifier $notifier,
        private readonly Format $format = new Format(),
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
            '/boot/config/plugins/diskforecast',
            '/tmp/diskforecast',
            $logger,
            new UnraidNotifier($logger),
            Format::fromUnraid(),
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
     * The saved settings, or the defaults (the whole array and each pool) when nothing is saved yet.
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
     * Where saved readings live on the flash drive.
     */
    public function historyDir(): string
    {
        return $this->pluginDir . '/history';
    }

    /**
     * The plugin's folder in RAM; the cron run keeps its lock here.
     */
    public function runtimeDir(): string
    {
        return $this->runtimeDir;
    }

    public function historyStore(): HistoryStore
    {
        return new HistoryStore($this->historyDir(), $this->runtimeDir . '/pending', $this->logger);
    }

    public function forecasts(): ForecastService
    {
        return $this->forecasts ??= new ForecastService($this->historyStore(), $this->runtimeDir . '/forecasts', $this->logger);
    }

    public function sampler(): Sampler
    {
        return new Sampler($this->settings(), $this->inventory(), $this->historyStore());
    }

    public function warningMonitor(): WarningMonitor
    {
        return new WarningMonitor($this->forecasts(), $this->notifier, $this->pluginDir . '/warnings.json', $this->logger, $this->format);
    }

    public function api(): Api
    {
        return new Api($this);
    }

    /**
     * What a fresh install tracks, all with the baseline settings: the whole array (when
     * there is one), then each pool as its own target named after the pool.
     */
    private function defaults(): Settings
    {
        $targets = [];
        $hasDisks = false;
        foreach ($this->inventory()->units() as $unit) {
            if ($unit->kind === UnitKind::Disk) {
                $hasDisks = true;
                continue;
            }

            $targets[] = Target::withBaseline('pool-' . $unit->name, ucfirst($unit->name), TargetType::Disks, [$unit->name]);
        }

        if ($hasDisks) {
            array_unshift($targets, Target::withBaseline('array', 'Array', TargetType::Array, []));
        }

        return new Settings($targets);
    }
}

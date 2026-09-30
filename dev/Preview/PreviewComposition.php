<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Preview;

use DiskForecast\Composition;
use DiskForecast\Config\SettingsStore;

/**
 * The plugin's real composition wired to the fake server and a local data folder.
 */
final class PreviewComposition
{
    private function __construct()
    {
    }

    public static function create(string $root): Composition
    {
        PreviewData::ensure($root);
        $logger = new ConsoleLogger();

        return new Composition(
            new FakePlatform(),
            PreviewData::settingsStore($root, $logger),
            $root,
            "{$root}/runtime",
            $logger,
            new ConsoleNotifier(),
        );
    }
}

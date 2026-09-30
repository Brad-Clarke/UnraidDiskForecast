<?php

declare(strict_types=1);

namespace DiskForecast\Logging;

/**
 * Where the plugin reports what it did and what it recovered from.
 */
interface Logger
{
    /**
     * Something routine worth a line in the log.
     */
    public function info(string $message): void;

    /**
     * Something went wrong and the plugin carried on without it.
     */
    public function warning(string $message): void;
}

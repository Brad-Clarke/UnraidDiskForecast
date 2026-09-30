<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Preview;

use DiskForecast\Logging\Logger;

/**
 * Writes log lines to the PHP server's console.
 */
final class ConsoleLogger implements Logger
{
    public function info(string $message): void
    {
        error_log("[diskforecast] {$message}");
    }

    public function warning(string $message): void
    {
        error_log("[diskforecast] WARNING {$message}");
    }
}

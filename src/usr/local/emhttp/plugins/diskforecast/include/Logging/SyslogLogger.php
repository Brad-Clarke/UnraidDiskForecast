<?php

declare(strict_types=1);

namespace DiskForecast\Logging;

/**
 * Writes to the system log, where Unraid's Tools > System Log shows it.
 */
final class SyslogLogger implements Logger
{
    public function __construct()
    {
        openlog('diskforecast', LOG_PID, LOG_USER);
    }

    public function info(string $message): void
    {
        syslog(LOG_INFO, $message);
    }

    public function warning(string $message): void
    {
        syslog(LOG_WARNING, $message);
    }
}

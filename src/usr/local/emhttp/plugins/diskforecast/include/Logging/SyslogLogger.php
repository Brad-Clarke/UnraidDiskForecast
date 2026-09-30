<?php

declare(strict_types=1);

namespace DiskForecast\Logging;

/**
 * Writes to the system log, where Unraid's Tools > System Log shows it.
 *
 * The log is opened and closed around each message, so running inside Unraid's own page
 * requests (the dashboard tile) never changes how the rest of that request logs.
 */
final class SyslogLogger implements Logger
{
    public function info(string $message): void
    {
        self::write(LOG_INFO, $message);
    }

    public function warning(string $message): void
    {
        self::write(LOG_WARNING, $message);
    }

    private static function write(int $priority, string $message): void
    {
        openlog('diskforecast', LOG_PID, LOG_USER);
        syslog($priority, $message);
        closelog();
    }
}

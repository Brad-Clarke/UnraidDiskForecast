<?php

declare(strict_types=1);

namespace DiskForecast\Notify;

use DiskForecast\Logging\Logger;

/**
 * Sends through Unraid's notification system, so messages follow the user's own
 * notification settings (browser, email, push agents).
 */
final class UnraidNotifier implements Notifier
{
    public function __construct(
        private readonly Logger $logger,
        private readonly string $script = '/usr/local/emhttp/webGui/scripts/notify',
    ) {
    }

    public function notify(string $subject, string $description, string $importance): void
    {
        if (!is_executable($this->script)) {
            $this->logger->warning("Unraid's notify script was not found at {$this->script}; message not sent: {$subject}");

            return;
        }

        $command = implode(' ', [
            escapeshellarg($this->script),
            '-e', escapeshellarg('Disk Forecast'),
            '-s', escapeshellarg($subject),
            '-d', escapeshellarg($description),
            '-i', escapeshellarg($importance),
        ]);
        exec($command . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->logger->warning("Unraid's notify script failed ({$exitCode}): " . implode(' ', $output));
        }
    }
}

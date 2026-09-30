<?php

declare(strict_types=1);

namespace DiskForecast\Dev\Preview;

use DiskForecast\Notify\Notifier;

/**
 * Prints notifications instead of sending them.
 */
final class ConsoleNotifier implements Notifier
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $sent = [];

    public function notify(string $subject, string $description, string $importance): void
    {
        $this->sent[] = [$subject, $description, $importance];
        error_log("[diskforecast] NOTIFY ({$importance}) {$subject}: {$description}");
    }
}

<?php

declare(strict_types=1);

namespace DiskForecast\Notify;

/**
 * Sends a message to the user.
 */
interface Notifier
{
    /**
     * @param string $subject One-line summary.
     * @param string $description Detail.
     * @param string $importance "normal", "warning" or "alert".
     */
    public function notify(string $subject, string $description, string $importance): void;
}

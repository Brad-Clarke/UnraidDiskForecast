#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * Prints the readings folder when it is available (its pool or share is mounted), and
 * nothing otherwise. Used by the uninstall script to delete the readings.
 */

require dirname(__DIR__) . '/include/bootstrap.php';

$app = DiskForecast\Composition::production();
if ($app->dataDirAvailable()) {
    echo $app->dataDir(), "\n";
}

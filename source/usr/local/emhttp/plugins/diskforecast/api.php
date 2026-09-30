<?php

declare(strict_types=1);

require __DIR__ . '/include/bootstrap.php';

$app = DiskForecast\Composition::production();

set_exception_handler(static function (Throwable $e) use ($app): void {
    $app->logger()->warning('Request failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Something went wrong. Details are in the system log.']);
});

[$status, $body] = $app->api()->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_GET, $_POST);
http_response_code($status);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode($body, JSON_UNESCAPED_SLASHES);

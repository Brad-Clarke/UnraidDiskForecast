<?php

declare(strict_types=1);

/**
 * Local preview of the plugin's pages against a fake server, no Unraid needed.
 *
 *   php -S localhost:8080 dev/preview.php
 *
 * Then open http://localhost:8080/ (the Forecast page, /Tools/DiskForecast). Settings are at
 * /Settings/DiskForecastSettings and the dashboard tile at /Dashboard. Add ?theme=white,
 * black, azure or gray to switch Unraid theme; ?date= and ?number= set Unraid's date and number
 * formats (for example ?date=%A,%20%m/%d/%Y&number=,.); /reset rebuilds the fake data.
 */

require __DIR__ . '/bootstrap.php';

use DiskForecast\Dev\Preview\PreviewComposition;

$pluginRoot = dirname(__DIR__) . '/src/usr/local/emhttp/plugins/diskforecast';
$dataRoot = __DIR__ . '/data/preview';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/plugins/diskforecast/api.php') {
    $app = PreviewComposition::create($dataRoot);
    [$status, $body] = $app->api()->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_GET, $_POST);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);

    return true;
}

if (str_starts_with($path, '/plugins/diskforecast/assets/')) {
    $file = $pluginRoot . '/assets/' . basename($path);
    if (!is_file($file)) {
        http_response_code(404);

        return true;
    }

    $types = ['css' => 'text/css', 'js' => 'text/javascript'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($file);

    return true;
}

if ($path === '/reset') {
    $remove = static function (string $dir) use (&$remove): void {
        foreach (glob("{$dir}/*") ?: [] as $entry) {
            is_dir($entry) ? $remove($entry) : unlink($entry);
        }

        @rmdir($dir);
    };
    $remove($dataRoot);
    header('Location: /');

    return true;
}

if ($path === '/favicon.ico') {
    http_response_code(204);

    return true;
}

$themes = ['white', 'black', 'azure', 'gray'];
$theme = in_array($_GET['theme'] ?? '', $themes, true) ? $_GET['theme'] : ($_COOKIE['df_theme'] ?? 'black');
setcookie('df_theme', $theme, ['path' => '/']);
$previewApp = PreviewComposition::create($dataRoot);

/**
 * Runs a .page file's body the way Unraid does, with the globals its pages see. Pages that
 * build the plugin's objects get the preview's (fake server) composition as $dfApp.
 *
 * @param array<string, mixed> $mytiles
 */
function renderPage(string $file, string $theme, string $pluginRoot, DiskForecast\Composition $dfApp, array &$mytiles = []): string
{
    $text = str_replace("\r\n", "\n", (string) file_get_contents($file));
    $body = substr($text, strpos($text, "\n---\n") + 5);
    $display = ['theme' => $theme, 'date' => $_GET['date'] ?? '%c', 'number' => $_GET['number'] ?? '.,'];
    $var = ['csrf_token' => 'PREVIEW-TOKEN'];
    $docroot = dirname($pluginRoot, 2);
    ob_start();
    eval('?>' . $body);

    return (string) ob_get_clean();
}

$dark = in_array($theme, ['black', 'gray'], true);
$palette = [
    'white' => ['#ffffff', '#1c1c1c', '#f2f2f2', '#e2e2e2'],
    'black' => ['#1c1b1b', '#f2f2f2', '#262626', '#383838'],
    'azure' => ['#e4e2e4', '#1c1c1c', '#f2f2f2', '#c9c7c9'],
    'gray' => ['#121510', '#f2f2f2', '#1c1f1a', '#34372f'],
][$theme];
[$background, $text, $bar, $line] = $palette;
$themeLinks = implode(' ', array_map(
    static fn (string $t): string => $t === $theme ? "<strong>{$t}</strong>" : "<a href=\"?theme={$t}\">{$t}</a>",
    $themes,
));

if ($path === '/Dashboard') {
    $heading = 'Dashboard';
    $mytiles = [];
    renderPage("{$pluginRoot}/DiskForecastDashboard.page", $theme, $pluginRoot, $previewApp, $mytiles);
    $content = isset($mytiles['diskforecast'])
        ? '<table class="dash-tile">' . $mytiles['diskforecast']['column2'] . '</table>'
        : '<p><em>No Disk Forecast tile: no target is set to show on the dashboard.</em></p>';
} elseif ($path === '/Settings/DiskForecastSettings') {
    $heading = 'Settings › User Utilities › Disk Forecast';
    $content = renderPage("{$pluginRoot}/DiskForecastSettings.page", $theme, $pluginRoot, $previewApp);
} else {
    $heading = 'Tools › Disk Utilities › Disk Forecast';
    $content = renderPage("{$pluginRoot}/DiskForecast.page", $theme, $pluginRoot, $previewApp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Disk Forecast preview</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<style>
  html { font-size: 62.5%; }
  body { margin: 0; background: <?= $background ?>; color: <?= $text ?>; font-family: clear-sans, "Segoe UI", Arial, sans-serif; font-size: 1.3rem; }
  a { color: <?= $dark ? '#86b6ef' : '#1c5cab' ?>; }
  .shell-header { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 24px; padding: 10px 24px; min-height: 48px; box-sizing: border-box; background: <?= $bar ?>; border-bottom: 1px solid <?= $line ?>; }
  .shell-header .logo { font-weight: 700; letter-spacing: 0.2em; color: #ff8c2f; }
  .shell-header nav a { margin-right: 18px; color: inherit; text-decoration: none; font-weight: 600; }
  .shell-header .themes { margin-left: auto; font-size: 1.2rem; }
  .shell-header .themes a { margin-left: 6px; }
  main { max-width: 1280px; margin: 0 auto; padding: 18px 16px 48px; }
  h1 { margin: 0 0 14px; font-size: 1.8rem; font-weight: 600; }
  .dash-tile { width: 380px; border-collapse: collapse; background: <?= $bar ?>; border: 1px solid <?= $line ?>; }
  .dash-tile td { padding: 10px 14px; }
  .dash-tile .f32 { float: left; margin-right: 10px; font-size: 32px; }
  .dash-tile .section { display: inline-block; font-weight: 600; }
  .dash-tile .section span { font-weight: 400; font-size: 1.2rem; }
  .dash-tile .control { float: right; color: inherit; }
</style>
</head>
<body>
<header class="shell-header">
  <span class="logo">UNRAID</span>
  <nav><a href="/Dashboard">Dashboard</a><a href="/Tools/DiskForecast">Tools › Disk Forecast</a><a href="/Settings/DiskForecastSettings">Settings › Disk Forecast</a></nav>
  <span class="themes">Theme: <?= $themeLinks ?> · <a href="/reset">reset data</a></span>
</header>
<main>
  <h1><?= $heading ?></h1>
  <?= $content ?>
</main>
</body>
</html>

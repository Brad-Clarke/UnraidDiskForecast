<?php

declare(strict_types=1);

/**
 * Scores forecasting methods against fake histories whose fill date is known.
 *
 * From a point every week in each history it forecasts using only what was known then,
 * and compares the projected full date with the real one.
 *
 * Usage: php dev/backtest.php [standard|fits|ranges]
 *   standard  the plugin's forecaster, per scenario (default)
 *   fits      trend fits and windows compared, with the plain block range
 *   ranges    range methods compared on a 180-day Theil–Sen trend
 */

require __DIR__ . '/bootstrap.php';

use DiskForecast\Dev\Baselines\BlockAveragingRange;
use DiskForecast\Dev\Baselines\EndToEndFit;
use DiskForecast\Dev\Baselines\LeastSquaresFit;
use DiskForecast\Dev\Baselines\SenIntervalRange;
use DiskForecast\Dev\Synthetic\Scenario;
use DiskForecast\Dev\Synthetic\ScenarioLibrary;
use DiskForecast\Forecast\Forecaster;
use DiskForecast\Range\BlockQuantileRange;
use DiskForecast\Range\HindcastRange;
use DiskForecast\Stats;
use DiskForecast\Trend\TheilSenFit;

const DAY = 86400;
const ERROR_CAP = 5.0;

/**
 * @param list<Scenario> $scenarios
 */
function score(Forecaster $forecaster, array $scenarios): array
{
    $result = ['error' => [], 'covered' => [], 'width' => [], 'byHorizon' => [], 'perScenario' => [], 'misses' => []];
    foreach ($scenarios as $scenario) {
        $errors = [];
        $outcomes = ['in' => 0, 'early' => 0, 'late' => 0];
        for ($origin = ScenarioLibrary::START + 400 * DAY; $origin <= $scenario->fillTime - 14 * DAY; $origin += 7 * DAY) {
            $truth = (float) ($scenario->fillTime - $origin);
            $forecast = $forecaster->forecast($scenario->history, $origin);
            $estimate = $forecast->secondsToFull;
            $error = $estimate === null ? ERROR_CAP : min(ERROR_CAP, abs($estimate - $truth) / $truth);
            $earliest = $forecast->earliestSeconds ?? INF;
            $latest = $forecast->latestSeconds ?? INF;
            $outcome = $truth < $earliest ? 'early' : ($truth > $latest ? 'late' : 'in');

            $errors[] = $error;
            $outcomes[$outcome]++;
            $result['error'][] = $error;
            $result['covered'][] = $outcome === 'in';
            $result['width'][] = min(ERROR_CAP, ($latest - $earliest) / $truth);
            $bucket = $truth < 182 * DAY ? '<6m' : ($truth < 548 * DAY ? '6-18m' : '>18m');
            $result['byHorizon'][$bucket][] = $error;
        }

        $total = array_sum($outcomes);
        $result['perScenario'][$scenario->name] = Stats::median($errors);
        $result['misses'][$scenario->name] = sprintf(
            '%s inside / %s filled sooner / %s filled later',
            percent($outcomes['in'] / $total),
            percent($outcomes['early'] / $total),
            percent($outcomes['late'] / $total),
        );
    }

    return $result;
}

function percent(?float $value): string
{
    return $value === null ? '    -' : sprintf('%4.0f%%', $value * 100);
}

function quantile(array $values, float $q): ?float
{
    if ($values === []) {
        return null;
    }

    sort($values);

    return Stats::quantileOfSorted($values, $q);
}

/**
 * @param list<Scenario> $scenarios
 */
function tableHeader(array $scenarios): string
{
    $line = sprintf('%-32s %5s %5s %5s %5s %5s', 'method', 'med', 'p90', '<6m', '6-18m', '>18m');
    foreach ($scenarios as $scenario) {
        $line .= ' ' . str_pad(substr($scenario->name, 0, 5), 5, ' ', STR_PAD_LEFT);
    }

    return $line . '  cover width';
}

function row(string $label, array $score): string
{
    $line = sprintf('%-32s %s %s', $label, percent(Stats::median($score['error'])), percent(quantile($score['error'], 0.9)));
    foreach (['<6m', '6-18m', '>18m'] as $bucket) {
        $line .= ' ' . percent(Stats::median($score['byHorizon'][$bucket] ?? []));
    }

    foreach ($score['perScenario'] as $value) {
        $line .= ' ' . percent($value);
    }

    $covered = count(array_filter($score['covered'])) / count($score['covered']);

    return $line . '  ' . percent($covered) . ' ' . percent(Stats::median($score['width']));
}

$mode = $argv[1] ?? 'standard';
$scenarios = ScenarioLibrary::filling();

echo "Error: |projected - actual time to full| / actual time to full (med = median, p90 = 90th percentile).\n";
echo "Cover: share of forecasts whose actual full date fell inside the range. Width: range length / actual.\n\n";
echo tableHeader($scenarios) . "\n";

if ($mode === 'standard') {
    $score = score(Forecaster::standard(), $scenarios);
    echo row('standard 180d', $score) . "\n\n";
    foreach ($score['misses'] as $name => $misses) {
        echo sprintf("  %-13s %s\n", $name, $misses);
    }
}

if ($mode === 'fits') {
    foreach ([new EndToEndFit(), new LeastSquaresFit(), new TheilSenFit()] as $fit) {
        foreach ([30, 90, 180, 365] as $window) {
            $forecaster = new Forecaster($fit, new BlockQuantileRange(), $window * DAY);
            echo row(sprintf('%s %dd', $fit->name(), $window), score($forecaster, $scenarios)) . "\n";
        }
    }
}

if ($mode === 'ranges') {
    $fit = new TheilSenFit();
    $window = 180 * DAY;
    $ranges = [
        new SenIntervalRange(),
        new BlockAveragingRange(),
        new BlockQuantileRange(),
        new HindcastRange($fit, $window, new BlockQuantileRange()),
    ];
    foreach ($ranges as $range) {
        echo row('theil-sen 180d ' . $range->name(), score(new Forecaster($fit, $range, $window), $scenarios)) . "\n";
    }
}

<?php
declare(strict_types=1);

/** Live station state: latest values, running assets, sidecar health, job freshness. */

[$config, $db] = require dirname(__DIR__) . '/bootstrap.php';

use Tbw\Domain;
use Tbw\Forecast\ForecastClient;
use Tbw\Repository\ForecastRepository;
use Tbw\Repository\GridRepository;
use Tbw\Repository\ReadingRepository;
use Tbw\Scoring\Scorecard;
use Tbw\Web\Json;

Json::endpoint(static function () use ($config, $db): array {
    $readings = new ReadingRepository($db);
    $grid = new GridRepository($db);
    $forecasts = new ForecastRepository($db);
    $scorecard = new Scorecard($db);

    $latest = [];
    foreach ($readings->latestPerTag() as $row) {
        $latest[(string) $row['asset']][(string) $row['signal_name']] = [
            'value'       => (float) $row['value'],
            'observed_at' => (string) $row['observed_at'],
            'unit'        => Domain::UNITS[(string) $row['signal_name']] ?? '',
        ];
    }

    $physics = $grid->latestPhysics();
    $run = $forecasts->latestRun();
    $health = $scorecard->health();

    $assets = [];
    foreach (Domain::ACTIVE_ASSETS as $asset) {
        $assets[] = [
            'asset'   => $asset,
            'status'  => ($physics['is_running|' . $asset] ?? 0.0) > 0.5 ? 'RUNNING' : 'STOPPED',
            'signals' => $latest[$asset] ?? [],
            'retired' => false,
        ];
    }
    foreach (Domain::RETIRED_ASSETS as $asset) {
        // F1: a stopped machine, not a broken sensor. Reporting it as "no data" would
        // put a dead pump back on the dashboard as if it were merely offline.
        $assets[] = ['asset' => $asset, 'status' => 'RETIRED', 'signals' => [], 'retired' => true];
    }

    return [
        'server_time'    => date('Y-m-d H:i:s'),
        'assets'         => $assets,
        'physics'        => $physics,
        'latest_run'     => $run,
        'sidecar'        => ForecastClient::fromConfig($config)->health(),
        'readings_total' => $health['readings_total'],
        'last_poll'      => $health['last_poll'],
        'target_span'    => $health['target_span'],
        'failing_jobs'   => $health['failing_jobs'],
    ];
});

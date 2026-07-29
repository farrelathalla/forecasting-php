<?php
declare(strict_types=1);

/**
 * Every value here can be overridden by an environment variable or a .env file at the
 * repo root, using the dotted path upper-cased with a TBW_ prefix (db.host -> TBW_DB_HOST).
 * Copy .env.example to .env and edit that rather than editing this file, so a git pull
 * never clobbers site-specific settings.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'tbw_forecast',
        'test_db' => 'tbw_forecast_test',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'api' => [
        'url'          => 'https://apps.daesang.net/api/mqtt/latest.php',
        'token'        => 'apps-mqtt-static-7f3c9e2a1b8d4f60',
        'timeout_sec'  => 20,
        // XAMPP on Windows often ships without a CA bundle; set false only on a
        // trusted network and understand it disables certificate checking.
        'verify_tls'   => true,
    ],

    'forecast' => [
        'service_url'      => 'http://127.0.0.1:8008',
        'timeout_sec'      => 120,
        // Falls back to Naive-Seasonal when the sidecar is unreachable. Turning this
        // off makes an outage of the Python service an outage of the whole system.
        'fallback_enabled' => true,
        'model'            => 'chronos-2',
    ],

    'ingest' => [
        // API is a snapshot endpoint, so the poll interval sets our history resolution.
        'poll_interval_sec' => 60,
    ],

    'alarm' => [
        // CUSUM. h=20 with k=0.5 met the 2% alarm budget under Chronos-2 (notebook 15).
        // Under the naive fallback the intervals are wider, so h is scaled down.
        'cusum_k'             => 0.5,
        'cusum_h'             => 20.0,
        'cusum_h_degraded'    => 12.0,
        'min_consecutive'     => 4,
        'alarm_rate_budget'   => 0.02,
        'warn_sigma'          => 3.0,
        'alarm_sigma'         => 4.0,
        // Hysteresis: a channel must fall this far below the tier threshold to clear.
        'clear_margin_sigma'  => 0.5,
    ],

    'business' => [
        // Placeholders until the plant confirms. Notebook section 10.7.
        'tariff_idr_per_kwh'  => 1450,
        'outage_idr_per_hour' => 25_000_000,
    ],

    'app' => [
        'timezone' => 'Asia/Jakarta',
        'title'    => 'TBW Pump Station — Realtime Forecast',
        'locale'   => 'id',
    ],
];

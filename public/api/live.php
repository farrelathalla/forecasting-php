<?php
declare(strict_types=1);

/**
 * Raw readings at poll resolution (~60 s), for the live strip chart.
 *
 * Separate from api/forecast.php on purpose. That one serves the 15-minute modelling
 * grid, which is the right resolution for a 24 h forecast but only moves four times an
 * hour and therefore never looks alive. This one serves what the poller actually
 * captured, which is as fast as the snapshot API can be observed.
 *
 * Supports ?since=<ts> so the page appends new points instead of refetching the window.
 */

[$config, $db] = require dirname(__DIR__) . '/bootstrap.php';

use Tbw\Domain;
use Tbw\Repository\ReadingRepository;
use Tbw\Web\Json;

Json::endpoint(static function () use ($db): array {
    $signal = Json::query('signal', 'POWER');
    if (!in_array($signal, Domain::SIGNALS, true)) {
        Json::error('unknown signal: ' . (string) $signal, 400);
    }

    $minutes = max(5, min(1440, Json::intQuery('minutes', 180)));
    $since = Json::query('since');

    $now = date('Y-m-d H:i:s');
    $from = $since ?? (new DateTimeImmutable($now))->modify("-{$minutes} minutes")->format('Y-m-d H:i:s');

    $readings = new ReadingRepository($db);

    $assets = [];
    foreach (Domain::ACTIVE_ASSETS as $asset) {
        $rows = $readings->series($asset, (string) $signal, $from, $now);
        $assets[$asset] = array_map(
            static fn(array $r) => ['ts' => (string) $r['observed_at'], 'value' => (float) $r['value']],
            // A `since` fetch is exclusive: the client already holds that point.
            $since === null ? $rows : array_values(array_filter(
                $rows,
                static fn(array $r) => (string) $r['observed_at'] > $since
            ))
        );
    }

    $latest = null;
    foreach ($assets as $points) {
        foreach ($points as $p) {
            if ($latest === null || $p['ts'] > $latest) {
                $latest = $p['ts'];
            }
        }
    }

    return [
        'signal'         => $signal,
        'unit'           => Domain::UNITS[(string) $signal] ?? '',
        'resolution_sec' => 60,
        'server_time'    => $now,
        'latest_ts'      => $latest,
        'incremental'    => $since !== null,
        'assets'         => $assets,
        'signals'        => Domain::SIGNALS,
    ];
});

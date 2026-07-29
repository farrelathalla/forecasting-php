<?php
declare(strict_types=1);

/**
 * One forecast run: 9 targets, 24 h ahead, 9 quantiles. Schedule every 15 minutes.
 *
 *   php bin/forecast.php                    origin = next grid step after the newest data
 *   php bin/forecast.php --origin=...       replay a specific origin
 *   php bin/forecast.php --no-fallback      fail loudly instead of degrading to naive
 */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;
use Tbw\Domain;
use Tbw\Forecast\ContextBuilder;
use Tbw\Forecast\ForecastClient;
use Tbw\JobLogger;
use Tbw\Repository\ForecastRepository;
use Tbw\Repository\GridRepository;

$opts = cli_options($argv);
$db = Db::connect($config);
$grid = new GridRepository($db);
$forecasts = new ForecastRepository($db);

$lastTs = $grid->maxTargetTs();
if ($lastTs === null) {
    warn('no target data yet — run bin/aggregate.php (and bin/seed_history.php) first');
    exit(1);
}

$origin = $opts['origin'] ?? ContextBuilder::nextOrigin($lastTs);

$client = isset($opts['no-fallback'])
    ? new Tbw\Forecast\ForecastClient(
        new Tbw\Forecast\CurlJsonTransport(),
        rtrim($config->str('forecast.service_url'), '/'),
        $config->int('forecast.timeout_sec', 120),
        false
    )
    : ForecastClient::fromConfig($config);

JobLogger::run($db, 'forecast', static function () use ($client, $grid, $forecasts, $origin): int {
    $contexts = (new ContextBuilder($grid))->build(Domain::TARGETS, $origin, Domain::CONTEXT);
    $coverage = ContextBuilder::coverage($contexts);

    $result = $client->forecast($contexts, Domain::HORIZON, Domain::QUANTILES);

    if ($result->forecasts === []) {
        warn('no forecast produced: ' . $result->note);
        return 0;
    }

    $runId = $forecasts->save($result, $origin, $coverage);

    say(sprintf(
        'run %d  origin %s  model %-15s %d targets  %d ms  context %.1f%%%s',
        $runId,
        $origin,
        $result->model,
        count($result->forecasts),
        $result->elapsedMs,
        $coverage * 100,
        $result->degraded ? '  [DEGRADED]' : ''
    ));
    if ($result->skipped !== []) {
        // Skipped, not guessed: a target with less than 2 days of history gets no
        // forecast rather than a fabricated one.
        warn('skipped for thin history: ' . implode(', ', $result->skipped));
    }
    if ($result->degraded) {
        warn($result->note);
    }

    return count($result->forecasts) * Domain::HORIZON;
});

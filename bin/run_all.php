<?php
declare(strict_types=1);

/**
 * One-shot or daemon runner for the whole pipeline.
 *
 *   php bin/run_all.php                 one cycle: aggregate -> forecast -> evaluate
 *   php bin/run_all.php --daemon        keep running: poll every minute, cycle every 15
 *   php bin/run_all.php --daemon --minutes=120   stop after two hours
 *
 * Handy on Windows, where keeping one process alive is simpler than wiring four
 * scheduled tasks. A failing step is logged and the loop continues — an outage of one
 * job must not take the others down with it.
 */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;
use Tbw\Domain;
use Tbw\Forecast\ContextBuilder;
use Tbw\Forecast\ForecastClient;
use Tbw\Grid\GridBuilder;
use Tbw\Ingest\SensorApiClient;
use Tbw\JobLogger;
use Tbw\Repository\ForecastRepository;
use Tbw\Repository\GridRepository;
use Tbw\Repository\ReadingRepository;

$opts = cli_options($argv);
$daemon = isset($opts['daemon']);
$stopAfterMinutes = (int) ($opts['minutes'] ?? 0);

$db = Db::connect($config);
$readings = new ReadingRepository($db);
$grid = new GridRepository($db);
$forecasts = new ForecastRepository($db);

$poll = static function () use ($db, $config, $readings): void {
    JobLogger::run($db, 'poll', static function () use ($config, $readings): int {
        $result = SensorApiClient::fromConfig($config)->fetchLatest();
        return $readings->insertMany($result->readings);
    });
};

$cycle = static function () use ($db, $config, $grid, $forecasts): void {
    $to = date('Y-m-d H:i:s');
    $from = (new DateTimeImmutable($to))->modify('-6 hours')->format('Y-m-d H:i:s');

    JobLogger::run($db, 'aggregate', static function () use ($db, $from, $to): int {
        $result = GridBuilder::make($db)->rebuild($from, $to);
        say(sprintf('aggregate: %d steps, %d targets', $result['steps'], $result['targets']));
        return $result['targets'];
    });

    JobLogger::run($db, 'forecast', static function () use ($config, $grid, $forecasts): int {
        $lastTs = $grid->maxTargetTs();
        if ($lastTs === null) {
            warn('forecast skipped: no target data');
            return 0;
        }
        $origin = ContextBuilder::nextOrigin($lastTs);
        $contexts = (new ContextBuilder($grid))->build(Domain::TARGETS, $origin, Domain::CONTEXT);
        $coverage = ContextBuilder::coverage($contexts);

        $result = ForecastClient::fromConfig($config)->forecast($contexts);
        if ($result->forecasts === []) {
            warn('forecast produced nothing: ' . $result->note);
            return 0;
        }
        $runId = $forecasts->save($result, $origin, $coverage);
        say(sprintf(
            'forecast: run %d, %s, %d targets, %d ms, context %.1f%%%s',
            $runId,
            $result->model,
            count($result->forecasts),
            $result->elapsedMs,
            $coverage * 100,
            $result->degraded ? ' [DEGRADED]' : ''
        ));
        return count($result->forecasts);
    });

    // evaluate.php owns the detector wiring; running it as a subprocess keeps that in
    // one place rather than duplicating it here.
    $evaluate = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/evaluate.php');
    exec($evaluate . ' 2>&1', $output, $status);
    foreach ($output as $line) {
        if (trim($line) !== '') {
            echo $line . "\n";
        }
    }
    if ($status !== 0) {
        warn("evaluate exited with status {$status}");
    }
};

$safely = static function (string $label, callable $fn): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        // One broken job must not stop the others.
        warn($label . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
    }
};

if (!$daemon) {
    $safely('poll', $poll);
    $safely('cycle', $cycle);
    say('single cycle complete');
    exit(0);
}

$pollInterval = $config->int('ingest.poll_interval_sec', 60);
$cycleInterval = Domain::MODEL_FREQ_MIN * 60;
$startedAt = time();
$lastCycle = 0;

say(sprintf(
    'daemon started: poll every %ds, cycle every %ds%s',
    $pollInterval,
    $cycleInterval,
    $stopAfterMinutes > 0 ? ", stopping after {$stopAfterMinutes} min" : ''
));

while (true) {
    $safely('poll', $poll);

    if (time() - $lastCycle >= $cycleInterval) {
        $safely('cycle', $cycle);
        $lastCycle = time();
    }

    if ($stopAfterMinutes > 0 && (time() - $startedAt) >= $stopAfterMinutes * 60) {
        say('daemon stopping: time limit reached');
        break;
    }
    sleep($pollInterval);
}

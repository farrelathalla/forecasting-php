<?php
declare(strict_types=1);

/**
 * Long-running poller. Simpler to keep alive on Windows than a per-minute scheduled
 * task, and it survives a transient API outage instead of logging one failure and dying.
 *
 *   php bin/poll_loop.php [--interval=60] [--max-iterations=0]
 */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;
use Tbw\Ingest\SensorApiClient;
use Tbw\JobLogger;
use Tbw\Repository\ReadingRepository;

$opts = cli_options($argv);
$interval = (int) ($opts['interval'] ?? $config->int('ingest.poll_interval_sec', 60));
$maxIterations = (int) ($opts['max-iterations'] ?? 0);

$db = Db::connect($config);
$repo = new ReadingRepository($db);
$client = SensorApiClient::fromConfig($config);

say("poll loop started, interval {$interval}s" . ($maxIterations > 0 ? ", {$maxIterations} iterations" : ''));

$iteration = 0;
$consecutiveFailures = 0;

while ($maxIterations === 0 || $iteration < $maxIterations) {
    $iteration++;
    $startedAt = microtime(true);

    try {
        JobLogger::run($db, 'poll', static function () use ($client, $repo, $iteration): int {
            $result = $client->fetchLatest();
            $written = $repo->insertMany($result->readings);
            if ($written > 0 || $iteration % 15 === 0) {
                say(sprintf('#%d fetched %d, wrote %d new', $iteration, $result->count(), $written));
            }
            return $written;
        });
        $consecutiveFailures = 0;
    } catch (\Throwable $e) {
        $consecutiveFailures++;
        warn("poll #{$iteration} failed ({$consecutiveFailures} in a row): " . $e->getMessage());
        // Back off so a dead endpoint does not fill job_run with one row per second,
        // but cap it so recovery is never more than a minute late.
        sleep(min(60, 5 * $consecutiveFailures));
    }

    if ($maxIterations > 0 && $iteration >= $maxIterations) {
        break;
    }
    $elapsed = microtime(true) - $startedAt;
    $sleep = max(1, (int) round($interval - $elapsed));
    sleep($sleep);
}

say("poll loop finished after {$iteration} iterations");

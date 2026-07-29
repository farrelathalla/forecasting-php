<?php
declare(strict_types=1);

/**
 * One poll of the sensor API. Run every minute (Task Scheduler) or use poll_loop.php.
 *
 * The endpoint is a snapshot with no history, so anything not polled is gone forever.
 */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;
use Tbw\Ingest\SensorApiClient;
use Tbw\JobLogger;
use Tbw\Repository\ReadingRepository;

$db = Db::connect($config);
$repo = new ReadingRepository($db);
$client = SensorApiClient::fromConfig($config);

JobLogger::run($db, 'poll', static function () use ($client, $repo): int {
    $result = $client->fetchLatest();
    $written = $repo->insertMany($result->readings);

    $notes = [];
    if ($result->sentinelsDropped > 0) {
        // F5 is a firmware defect at source. Surfacing the count makes it visible
        // rather than silently filtered forever.
        $notes[] = $result->sentinelsDropped . ' sentinel';
    }
    if ($result->invalidDropped > 0) {
        $notes[] = $result->invalidDropped . ' invalid';
    }

    say(sprintf(
        'fetched %d readings, wrote %d new%s',
        $result->count(),
        $written,
        $notes === [] ? '' : ' (dropped: ' . implode(', ', $notes) . ')'
    ));
    return $written;
});

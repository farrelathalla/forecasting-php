<?php
declare(strict_types=1);

/**
 * Rebuilds the 15-minute grid, the 9 targets and the physics channels from raw readings.
 *
 *   php bin/aggregate.php                     rolling window (default 2 days)
 *   php bin/aggregate.php --days=90           wider rebuild
 *   php bin/aggregate.php --from=... --to=...
 *
 * Idempotent: every write is an upsert keyed on (series, ts), so re-running it over an
 * overlapping window corrects rows instead of duplicating them.
 */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;
use Tbw\Grid\GridBuilder;
use Tbw\JobLogger;
use Tbw\Repository\ReadingRepository;

$opts = cli_options($argv);
$db = Db::connect($config);
$readings = new ReadingRepository($db);

$to = $opts['to'] ?? date('Y-m-d H:i:s');
if (isset($opts['from'])) {
    $from = $opts['from'];
} else {
    // 12 hours by default. At a 10 s poll interval a 2-day window would pull ~276k raw
    // rows into PHP memory at once, and 12 h is already far more slack than any
    // late-arriving reading needs.  Use --days explicitly for a wider rebuild, with
    // php -d memory_limit=1G.
    $days = (float) ($opts['days'] ?? 0.5);
    $from = (new DateTimeImmutable($to))->modify('-' . (int) round($days * 24) . ' hours')->format('Y-m-d H:i:s');
    // Never reach back before the first reading we actually hold.
    $earliest = $readings->minObservedAt();
    if ($earliest !== null && $from < $earliest) {
        $from = $earliest;
    }
}

JobLogger::run($db, 'aggregate', static function () use ($db, $from, $to): int {
    $result = GridBuilder::make($db)->rebuild($from, $to);
    say(sprintf(
        'aggregated %s .. %s : %d steps, %d grid rows, %d target rows, %d physics rows',
        $from,
        $to,
        $result['steps'],
        $result['grid'],
        $result['targets'],
        $result['physics']
    ));
    return $result['targets'];
});

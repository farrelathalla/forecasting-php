<?php
declare(strict_types=1);

/**
 * Applies the retention windows. Schedule daily.
 *
 *   php bin/prune.php              use the windows in config/config.php
 *   php bin/prune.php --dry-run    report what would go, delete nothing
 *   php bin/prune.php --readings=60
 *
 * Only matters once the poll interval is short: at 10 s, reading_raw grows by ~138k rows
 * a day. The 15-minute grid is never pruned — it is the long-term store everything else
 * is built on.
 */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;
use Tbw\JobLogger;
use Tbw\Maintenance\Retention;

$opts = cli_options($argv);
$dryRun = isset($opts['dry-run']);

$db = Db::connect($config);
$retention = new Retention($db);

$windows = [
    'readings'    => (int) ($opts['readings'] ?? $config->int('retention.readings_days', 30)),
    'forecasts'   => (int) ($opts['forecasts'] ?? $config->int('retention.forecasts_days', 90)),
    'job_log'     => (int) ($opts['job-log'] ?? $config->int('retention.job_log_days', 30)),
    'spc'         => (int) ($opts['spc'] ?? $config->int('retention.spc_days', 365)),
    'projections' => (int) ($opts['projections'] ?? $config->int('retention.projections_days', 365)),
];

$before = $retention->tableCounts();
say('table sizes before:');
foreach ($before as $table => $n) {
    say(sprintf('  %-16s %s', $table, number_format($n)));
}

if ($dryRun) {
    say('');
    say('dry run — nothing deleted. windows: ' . json_encode($windows));
    exit(0);
}

JobLogger::run($db, 'prune', static function () use ($retention, $windows): int {
    $deleted = 0;
    $deleted += $n = $retention->pruneReadings($windows['readings']);
    say(sprintf('reading_raw   : %s deleted (keep %d days)', number_format($n), $windows['readings']));

    $deleted += $n = $retention->pruneForecasts($windows['forecasts']);
    say(sprintf('forecast_run  : %s deleted (keep %d days, points cascade)', number_format($n), $windows['forecasts']));

    $deleted += $n = $retention->pruneJobLog($windows['job_log']);
    say(sprintf('job_run       : %s deleted (keep %d days, failures kept forever)', number_format($n), $windows['job_log']));

    $deleted += $n = $retention->pruneSpcHistory($windows['spc']);
    say(sprintf('spc_state     : %s deleted (keep %d days)', number_format($n), $windows['spc']));

    $deleted += $n = $retention->pruneProjections($windows['projections']);
    say(sprintf('projection    : %s deleted (keep %d days)', number_format($n), $windows['projections']));

    return $deleted;
});

<?php
declare(strict_types=1);

/**
 * Seeds grid_15min / target_15min / physics_15min from the notebook's cached extract.
 *
 *   php service/export_history.py      # parquet -> var/seed_15min.csv
 *   php bin/seed_history.php
 *
 * Rows land with source='seed' so seeded history is always distinguishable from data the
 * poller collected. Targets and physics are rebuilt by the same TargetBuilder and
 * PhysicsCalculator the live path uses, so there is exactly one definition of each.
 *
 * Idempotent.
 */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;
use Tbw\Domain;
use Tbw\Grid\TargetBuilder;
use Tbw\JobLogger;
use Tbw\Physics\PhysicsCalculator;
use Tbw\Repository\GridRepository;

$opts = cli_options($argv);
$path = $opts['file'] ?? dirname(__DIR__) . '/var/seed_15min.csv';

if (!is_file($path)) {
    warn("seed file not found: {$path}");
    warn('generate it first:  service/.venv/Scripts/python service/export_history.py');
    exit(1);
}

$db = Db::connect($config);
$grid = new GridRepository($db);

JobLogger::run($db, 'seed', static function () use ($path, $grid): int {
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException("cannot open {$path}");
    }
    $header = fgetcsv($handle);
    if ($header === false || array_slice($header, 0, 4) !== ['ts', 'asset', 'signal', 'value']) {
        throw new RuntimeException('unexpected seed header: ' . implode(',', (array) $header));
    }

    $series = [];
    $timestamps = [];
    $gridRows = [];
    $read = 0;

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 4) {
            continue;
        }
        [$ts, $asset, $signal, $value] = $row;
        if (!is_numeric($value)) {
            continue;
        }
        $v = (float) $value;
        // F5 again: the cached grid is already filtered, but a seeder that trusts its
        // input is one bad export away from poisoning every statistic.
        if (abs($v) >= Domain::SENTINEL_HI) {
            continue;
        }
        $read++;
        $series[$signal . '|' . $asset][$ts] = $v;
        $timestamps[$ts] = true;
        $gridRows[] = ['asset' => $asset, 'signal' => $signal, 'ts' => $ts, 'value' => $v, 'is_held' => false];
    }
    fclose($handle);

    if ($timestamps === []) {
        warn('seed file had no usable rows');
        return 0;
    }

    $stamps = array_keys($timestamps);
    sort($stamps);
    say(sprintf('read %s rows, %s steps, %s .. %s', number_format($read), number_format(count($stamps)), $stamps[0], $stamps[count($stamps) - 1]));

    $written = $grid->upsertGrid($gridRows, 'seed');
    say("grid_15min: {$written} rows");

    $built = (new TargetBuilder())->build($series, $stamps);
    $written = $grid->upsertTargets($built['targets'], 'seed');
    say("target_15min: {$written} rows");

    $physicsInput = $series;
    foreach ($built['targets'] as $name => $column) {
        $physicsInput[$name] = $column;
    }
    $channels = (new PhysicsCalculator())->compute($physicsInput, $built['running'], $stamps);
    foreach ($built['aux'] as $name => $column) {
        $channels[$name] = $column;
    }
    foreach ($built['running'] as $asset => $flags) {
        $channels['is_running|' . $asset] = array_map(static fn(bool $f) => $f ? 1.0 : 0.0, $flags);
    }
    $channels['n_running'] = array_map(static fn(int $n) => (float) $n, $built['n_running']);

    $written = $grid->upsertPhysics($channels);
    say("physics_15min: {$written} rows");

    return count($stamps);
});

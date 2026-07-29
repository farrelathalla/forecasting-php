<?php
declare(strict_types=1);

/** History plus the latest forecast band for one target. */

[$config, $db] = require dirname(__DIR__) . '/bootstrap.php';

use Tbw\Domain;
use Tbw\Repository\ForecastRepository;
use Tbw\Repository\GridRepository;
use Tbw\Web\Json;

Json::endpoint(static function () use ($db): array {
    $target = Json::query('target', Domain::TARGETS[0]);
    if (!in_array($target, Domain::TARGETS, true)) {
        Json::error('unknown target: ' . $target, 400);
    }
    // Default to the full model context (14 d). A shorter default window lands squarely
    // inside the gap between the seeded extract and the start of live polling, so the
    // chart would come up empty on perfectly healthy data.
    $historyHours = max(1, min(336, Json::intQuery('hours', 336)));

    $grid = new GridRepository($db);
    $forecasts = new ForecastRepository($db);

    $run = $forecasts->latestRun();
    $origin = $run === null ? date('Y-m-d H:i:s') : (string) $run['origin_ts'];

    $from = (new DateTimeImmutable($origin))->modify("-{$historyHours} hours")->format('Y-m-d H:i:s');
    $history = $grid->targetSeries($target, $from, $origin);

    $band = [];
    if ($run !== null) {
        foreach ($forecasts->points((int) $run['id'], $target) as $row) {
            $band[] = [
                'ts'  => (string) $row['ts'],
                'q10' => $row['q10'] === null ? null : (float) $row['q10'],
                'q50' => $row['q50'] === null ? null : (float) $row['q50'],
                'q90' => $row['q90'] === null ? null : (float) $row['q90'],
            ];
        }
    }

    // Actuals that have arrived inside the forecast window, so the operator can watch
    // the forecast being tested in real time rather than only after it matures.
    $realised = [];
    if ($band !== []) {
        $end = $band[count($band) - 1]['ts'];
        foreach ($grid->targetSeries($target, $origin, $end) as $row) {
            if ($row['value'] !== null) {
                $realised[] = $row;
            }
        }
    }

    $signal = Domain::signalFromTarget($target);

    // Reported so the UI can explain an empty-looking chart instead of leaving the
    // operator to guess whether the system is broken or the history simply has a hole.
    $known = 0;
    foreach ($history as $row) {
        if ($row['value'] !== null) {
            $known++;
        }
    }
    $expected = max(1, (int) round($historyHours * 60 / Domain::MODEL_FREQ_MIN));

    return [
        'target'           => $target,
        'unit'             => Domain::UNITS[$signal] ?? ($target === 'HEADER_PRESSURE' ? 'kg/cm2' : ''),
        'origin'           => $origin,
        'run'              => $run,
        'history'          => $history,
        'history_points'   => $known,
        'history_coverage' => min(1.0, $known / $expected),
        'forecast'         => $band,
        'realised'         => $realised,
        'targets'          => Domain::TARGETS,
    ];
});

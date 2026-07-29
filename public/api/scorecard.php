<?php
declare(strict_types=1);

/** Rolling leaderboard, per-target scores and the error-growth curve. */

[$config, $db] = require dirname(__DIR__) . '/bootstrap.php';

use Tbw\Repository\ForecastRepository;
use Tbw\Scoring\Scorecard;
use Tbw\Web\Json;

Json::endpoint(static function () use ($db): array {
    $days = max(1, min(90, Json::intQuery('days', 14)));
    $scorecard = new Scorecard($db);
    $forecasts = new ForecastRepository($db);

    $models = $scorecard->byModel($days);
    $best = $models[0]['model'] ?? null;

    return [
        'days'         => $days,
        'models'       => $models,
        'best_model'   => $best,
        'per_target'   => $scorecard->byTarget($best, $days),
        'error_growth' => $scorecard->errorGrowth($best, $days),
        'recent_runs'  => $forecasts->recentRuns(20),
        'health'       => $scorecard->health(),
    ];
});

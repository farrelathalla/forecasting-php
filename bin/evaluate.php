<?php
declare(strict_types=1);

/**
 * The early-warning pass. Schedule every 15 minutes, after bin/forecast.php.
 *
 * Three detectors, because each catches a different class of problem:
 *   1. residual CUSUM       the unexpected
 *   2. physics SPC          the plausible-looking
 *   3. threshold projection the schedulable
 *
 * Also scores any forecast run whose 24 h window has fully matured.
 */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;
use Tbw\EarlyWarning\AlarmPolicy;
use Tbw\EarlyWarning\Cusum;
use Tbw\EarlyWarning\HuberTrend;
use Tbw\EarlyWarning\Spc;
use Tbw\JobLogger;
use Tbw\Repository\AlarmRepository;
use Tbw\Repository\ForecastRepository;
use Tbw\Repository\GridRepository;
use Tbw\Scoring\Evaluator;

$db = Db::connect($config);
$grid = new GridRepository($db);
$forecasts = new ForecastRepository($db);
$alarms = new AlarmRepository($db);
$evaluator = new Evaluator($db, $grid);
$policy = AlarmPolicy::fromConfig($config);

JobLogger::run($db, 'evaluate', static function () use ($config, $grid, $forecasts, $alarms, $evaluator, $policy): int {
    $now = date('Y-m-d H:i:s');
    $touched = 0;

    // ---- 1. score matured runs, then run CUSUM on their residuals -------------------

    $matured = $forecasts->unscoredMaturedRuns($now);
    foreach ($matured as $run) {
        $result = $evaluator->scoreRun($run);
        if ($result['scored'] === 0) {
            continue;
        }
        $touched += $result['scored'];
        say(sprintf('scored run %d (%s, %s): %d targets', $run['id'], $run['model'], $run['origin_ts'], $result['scored']));

        $threshold = AlarmPolicy::cusumThreshold(
            (bool) $run['degraded'],
            $config->float('alarm.cusum_h', 20.0),
            $config->float('alarm.cusum_h_degraded', 12.0)
        );
        $cusum = new Cusum($config->float('alarm.cusum_k', 0.5), $threshold, true);

        $episodes = $evaluator->standardisedResiduals($run);
        $flagged = 0;
        $samples = 0;

        foreach ($episodes as $target => $z) {
            // One run is one episode: the statistic starts from zero here, because
            // accumulating across unrelated 24 h forecasts is meaningless.
            $outcome = $cusum->run($z);
            $tripped = in_array(true, $outcome['alarm'], true);
            foreach ($outcome['alarm'] as $a) {
                $samples++;
                if ($a) {
                    $flagged++;
                }
            }

            $peak = 0.0;
            foreach ($outcome['sp'] as $i => $sp) {
                $peak = max($peak, (float) ($sp ?? 0.0), (float) ($outcome['sm'][$i] ?? 0.0));
            }

            $channel = 'residual|' . $target;
            $state = $alarms->loadState($channel, 'cusum');
            // Expressed in threshold units so one policy can serve both detectors.
            $magnitude = $threshold > 0 ? $peak / $threshold * $config->float('alarm.alarm_sigma', 4.0) : 0.0;
            $state = $policy->classify($state, $tripped ? $magnitude : 0.0);
            $alarms->saveState($state, 'cusum', (string) $run['origin_ts']);
            if ($state->changed) {
                $alarms->recordTransition($state, 'cusum', (string) $run['origin_ts'], [
                    'run_id'      => (int) $run['id'],
                    'model'       => (string) $run['model'],
                    'peak_cusum'  => round($peak, 3),
                    'threshold_h' => $threshold,
                ]);
                say("  alarm {$channel}: {$state->previousTier} -> {$state->tier}");
            }
        }

        if ($samples > 0) {
            $rate = $flagged / $samples;
            $budget = $config->float('alarm.alarm_rate_budget', 0.02);
            say(sprintf('  residual alarm rate %.2f%% (budget %.2f%%, h=%.0f)', $rate * 100, $budget * 100, $threshold));
            if ($rate > $budget) {
                // Above ~2% the control room mutes the system within a week regardless
                // of statistical soundness, so this is a real operational failure.
                warn(sprintf('alarm rate %.2f%% exceeds the %.2f%% budget — raise alarm.cusum_h', $rate * 100, $budget * 100));
            }
        }
    }

    // ---- 2. physics SPC against the frozen healthy-window limits --------------------

    $spc = Spc::default($config);
    $latest = $grid->latestPhysics();
    $lastTs = $grid->maxTargetTs() ?? $now;

    $states = $spc->evaluateAll(array_intersect_key($latest, array_flip($spc->channels())));
    $alarms->upsertSpcStates(array_values($states), $lastTs);
    $touched += count($states);

    foreach ($states as $channel => $spcState) {
        $state = $alarms->loadState((string) $channel, 'spc');
        if ($state->ts === $lastTs) {
            // Already classified on this sample. Without the guard, a cron overlap or a
            // manual re-run would count one observation several times and satisfy
            // min_consecutive without the persistence it is supposed to require.
            continue;
        }
        $state = $policy->classify($state, $spcState['drift_sigma']);
        $alarms->saveState($state, 'spc', $lastTs);
        if ($state->changed) {
            $alarms->recordTransition($state, 'spc', $lastTs, [
                'value'       => $spcState['value'],
                'mu'          => $spcState['mu'],
                'sigma'       => $spcState['sigma'],
                'drift_sigma' => $spcState['drift_sigma'],
            ]);
        }
        if ($spcState['drift_sigma'] !== null && abs($spcState['drift_sigma']) >= 2.0) {
            say(sprintf('  SPC %-22s %+7.2f sigma  %s', $channel, $spcState['drift_sigma'], $state->tier));
        }
    }

    // ---- 3. threshold projection: a drift becomes a date ----------------------------

    $limits = require dirname(__DIR__) . '/config/projection_limits.php';
    $from = (new DateTimeImmutable($lastTs))->modify('-30 days')->format('Y-m-d H:i:s');

    foreach ($limits as $channel => $limit) {
        $series = $grid->physicsSeries((string) $channel, $from, $lastTs);
        if (count($series) < 2) {
            continue;
        }
        // Huber, not OLS: 16 stoppage episodes in 90 days would drag a least-squares
        // slope towards the trips instead of the underlying trend.
        $projection = HuberTrend::project($series, (float) $limit);
        $alarms->insertProjection((string) $channel, $projection);
        $touched++;

        if ($projection['days_to_limit'] !== null) {
            say(sprintf(
                '  projection %-22s %+.4f/day -> limit %.3f in %.1f days (%s)',
                $channel,
                $projection['slope_per_day'],
                $projection['limit'],
                $projection['days_to_limit'],
                (string) $projection['eta']
            ));
        }
    }

    say(sprintf('evaluate complete: %d runs scored, %d SPC channels, %d rows touched', count($matured), count($states), $touched));
    return $touched;
});

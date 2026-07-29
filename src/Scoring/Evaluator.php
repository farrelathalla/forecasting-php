<?php
declare(strict_types=1);

namespace Tbw\Scoring;

use Tbw\Db;
use Tbw\Domain;
use Tbw\Repository\GridRepository;

/**
 * Scores matured forecast runs against the actuals that have since arrived, and produces
 * the standardised residuals the CUSUM detector consumes.
 *
 * A run is only scored once its full 24 h window has passed. Partial scoring would let
 * the near horizon, where every model looks good, dominate the average.
 */
final class Evaluator
{
    public function __construct(private Db $db, private GridRepository $grid)
    {
    }

    /**
     * @return array{scored:int,targets:list<string>}
     */
    public function scoreRun(array $run): array
    {
        $runId = (int) $run['id'];
        $origin = (string) $run['origin_ts'];

        $points = $this->db->select(
            'SELECT target, ts, horizon_step, q10, q50, q90,
                    q20, q30, q40, q60, q70, q80
               FROM forecast_point WHERE run_id = ? ORDER BY target, ts',
            [$runId]
        );
        if ($points === []) {
            return ['scored' => 0, 'targets' => []];
        }

        $byTarget = [];
        foreach ($points as $row) {
            $byTarget[(string) $row['target']][] = $row;
        }

        $end = (new \DateTimeImmutable($origin))
            ->modify('+' . (Domain::HORIZON * Domain::MODEL_FREQ_MIN) . ' minutes')
            ->format('Y-m-d H:i:s');

        $scored = 0;
        $targets = [];

        foreach ($byTarget as $target => $rows) {
            $actualRows = $this->grid->targetSeries($target, $origin, $end);
            $actualByTs = [];
            foreach ($actualRows as $r) {
                $actualByTs[$r['ts']] = $r['value'];
            }

            $actual = [];
            $median = [];
            $lower = [];
            $upper = [];
            $quantiles = [];
            foreach (Domain::QUANTILES as $q) {
                $quantiles[(string) $q] = [];
            }

            foreach ($rows as $row) {
                $ts = (string) $row['ts'];
                $actual[] = $actualByTs[$ts] ?? null;
                $median[] = $row['q50'] === null ? null : (float) $row['q50'];
                $lower[] = $row['q10'] === null ? null : (float) $row['q10'];
                $upper[] = $row['q90'] === null ? null : (float) $row['q90'];
                foreach (Domain::QUANTILES as $q) {
                    $column = 'q' . (int) round($q * 100);
                    $quantiles[(string) $q][] = $row[$column] === null ? null : (float) $row[$column];
                }
            }

            $known = array_filter($actual, static fn(?float $v) => $v !== null);
            if ($known === []) {
                continue;
            }

            // Scaling history: everything before the origin, which is exactly what the
            // model was allowed to see.
            $historyFrom = (new \DateTimeImmutable($origin))
                ->modify('-' . (Domain::CONTEXT * Domain::MODEL_FREQ_MIN) . ' minutes')
                ->format('Y-m-d H:i:s');
            $history = array_map(
                static fn(array $r) => $r['value'],
                $this->grid->targetSeries($target, $historyFrom, $origin)
            );

            $this->db->execute(
                'INSERT INTO forecast_score (run_id, target, model, origin_ts, mase, wql, cov80, rmse, mae, n_points)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     mase = VALUES(mase), wql = VALUES(wql), cov80 = VALUES(cov80),
                     rmse = VALUES(rmse), mae = VALUES(mae), n_points = VALUES(n_points),
                     scored_at = NOW()',
                [
                    $runId,
                    $target,
                    (string) $run['model'],
                    $origin,
                    Metrics::mase($actual, $median, $history),
                    Metrics::wql($actual, $quantiles),
                    Metrics::coverage($actual, $lower, $upper),
                    Metrics::rmse($actual, $median),
                    Metrics::mae($actual, $median),
                    count($known),
                ]
            );
            $scored++;
            $targets[] = (string) $target;
        }

        return ['scored' => $scored, 'targets' => $targets];
    }

    /**
     * Standardised residuals for one run, one episode per target.
     *
     * The residual scale is floored at a robust MAD estimate rather than trusting the
     * model's own interval: over-tight intervals (SARIMAX was the worst offender) produce
     * enormous standardised residuals and the CUSUM never stops.
     *
     * @return array<string,list<?float>>
     */
    public function standardisedResiduals(array $run): array
    {
        $runId = (int) $run['id'];
        $origin = (string) $run['origin_ts'];
        $end = (new \DateTimeImmutable($origin))
            ->modify('+' . (Domain::HORIZON * Domain::MODEL_FREQ_MIN) . ' minutes')
            ->format('Y-m-d H:i:s');

        $points = $this->db->select(
            'SELECT target, ts, q10, q50, q90 FROM forecast_point WHERE run_id = ? ORDER BY target, ts',
            [$runId]
        );

        $byTarget = [];
        foreach ($points as $row) {
            $byTarget[(string) $row['target']][] = $row;
        }

        $out = [];
        foreach ($byTarget as $target => $rows) {
            $actualRows = $this->grid->targetSeries($target, $origin, $end);
            $actualByTs = [];
            foreach ($actualRows as $r) {
                $actualByTs[$r['ts']] = $r['value'];
            }

            $residuals = [];
            $spreads = [];
            foreach ($rows as $row) {
                $actual = $actualByTs[(string) $row['ts']] ?? null;
                $median = $row['q50'] === null ? null : (float) $row['q50'];
                if ($actual === null || $median === null) {
                    $residuals[] = null;
                    continue;
                }
                $residuals[] = $actual - $median;
                if ($row['q10'] !== null && $row['q90'] !== null) {
                    // q90 - q10 spans 2.563 sigma under normality.
                    $spreads[] = ((float) $row['q90'] - (float) $row['q10']) / 2.563;
                }
            }

            $modelSigma = $spreads === [] ? 0.0 : array_sum($spreads) / count($spreads);
            $robustSigma = self::mad($residuals);
            $sigma = max($modelSigma, $robustSigma, 1e-9);

            $out[(string) $target] = array_map(
                static fn(?float $r) => $r === null ? null : $r / $sigma,
                $residuals
            );
        }
        return $out;
    }

    /** @param list<?float> $values */
    private static function mad(array $values): float
    {
        $known = [];
        foreach ($values as $v) {
            if ($v !== null && is_finite($v)) {
                $known[] = abs((float) $v);
            }
        }
        if ($known === []) {
            return 0.0;
        }
        sort($known);
        $n = count($known);
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $known[$mid] : ($known[$mid - 1] + $known[$mid]) / 2.0;
        return $median * 1.4826;
    }
}

<?php
declare(strict_types=1);

namespace Tbw\Scoring;

use Tbw\Db;
use Tbw\Domain;

/**
 * Rolling leaderboard from forecast_score.
 *
 * Reports the SD across runs next to every mean, because the SD is what decided the
 * model choice: LGBM-Local's 6.4% MASE edge over Chronos-2 was smaller than its own
 * cross-fold SD of 0.690, so the edge was not real.
 */
final class Scorecard
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @return list<array<string,mixed>> one row per model
     */
    public function byModel(int $days = 14): array
    {
        $rows = $this->db->select(
            'SELECT model, run_id, target, mase, wql, cov80, rmse
               FROM forecast_score
              WHERE origin_ts >= DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );

        $byModel = [];
        foreach ($rows as $row) {
            $model = (string) $row['model'];
            $byModel[$model]['mase'][] = $row['mase'] === null ? null : (float) $row['mase'];
            $byModel[$model]['wql'][] = $row['wql'] === null ? null : (float) $row['wql'];
            $byModel[$model]['cov80'][] = $row['cov80'] === null ? null : (float) $row['cov80'];
            $byModel[$model]['rmse'][] = $row['rmse'] === null ? null : (float) $row['rmse'];
            $byModel[$model]['runs'][(int) $row['run_id']] = true;
        }

        $out = [];
        foreach ($byModel as $model => $values) {
            $out[] = [
                'model'    => $model,
                'mase'     => Metrics::mean($values['mase']),
                'mase_sd'  => Metrics::sd($values['mase']),
                'wql'      => Metrics::mean($values['wql']),
                'cov80'    => Metrics::mean($values['cov80']),
                'rmse'     => Metrics::mean($values['rmse']),
                'n_scores' => count($values['mase']),
                'n_runs'   => count($values['runs']),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            // Null MASE sorts last rather than winning by being absent.
            if ($a['mase'] === null) {
                return 1;
            }
            if ($b['mase'] === null) {
                return -1;
            }
            return $a['mase'] <=> $b['mase'];
        });

        return $out;
    }

    /** @return list<array<string,mixed>> one row per target for a given model */
    public function byTarget(?string $model = null, int $days = 14): array
    {
        $sql = 'SELECT target, model, mase, wql, cov80 FROM forecast_score
                 WHERE origin_ts >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params = [$days];
        if ($model !== null) {
            $sql .= ' AND model = ?';
            $params[] = $model;
        }

        $grouped = [];
        foreach ($this->db->select($sql, $params) as $row) {
            $key = (string) $row['target'];
            $grouped[$key]['mase'][] = $row['mase'] === null ? null : (float) $row['mase'];
            $grouped[$key]['wql'][] = $row['wql'] === null ? null : (float) $row['wql'];
            $grouped[$key]['cov80'][] = $row['cov80'] === null ? null : (float) $row['cov80'];
        }

        $out = [];
        foreach (Domain::TARGETS as $target) {
            $values = $grouped[$target] ?? ['mase' => [], 'wql' => [], 'cov80' => []];
            $out[] = [
                'target'  => $target,
                'mase'    => Metrics::mean($values['mase']),
                'mase_sd' => Metrics::sd($values['mase']),
                'wql'     => Metrics::mean($values['wql']),
                'cov80'   => Metrics::mean($values['cov80']),
                'n'       => count($values['mase']),
            ];
        }
        return $out;
    }

    /**
     * Mean absolute error per horizon bucket, scaled per target so the buckets are
     * comparable across a panel whose units span 4.9 kg/cm2 to 187 kW.
     *
     * @return array<string,?float>
     */
    public function errorGrowth(?string $model = null, int $days = 14): array
    {
        $sql = 'SELECT p.horizon_step, p.target, p.q50, t.value AS actual
                  FROM forecast_point p
                  JOIN forecast_run r ON r.id = p.run_id
                  JOIN target_15min t ON t.target = p.target AND t.ts = p.ts
                 WHERE r.origin_ts >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND t.value IS NOT NULL';
        $params = [$days];
        if ($model !== null) {
            $sql .= ' AND r.model = ?';
            $params[] = $model;
        }

        $rows = $this->db->select($sql, $params);
        if ($rows === []) {
            return ['0-1h' => null, '1-4h' => null, '4-12h' => null, '12-24h' => null];
        }

        // Per-target scale so a 187 kW error does not swamp a 4.9 kg/cm2 one.
        $scales = [];
        foreach ($rows as $row) {
            $scales[(string) $row['target']][] = abs((float) $row['actual']);
        }
        foreach ($scales as $target => $values) {
            $mean = array_sum($values) / count($values);
            $scales[$target] = $mean > 1e-9 ? $mean : 1.0;
        }

        $byStep = [];
        foreach ($rows as $row) {
            $step = (int) $row['horizon_step'];
            $error = abs((float) $row['actual'] - (float) $row['q50']) / $scales[(string) $row['target']];
            $byStep[$step][] = $error;
        }
        $means = [];
        foreach ($byStep as $step => $errors) {
            $means[$step] = array_sum($errors) / count($errors);
        }
        return Metrics::horizonBuckets($means);
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        $lastRun = $this->db->selectOne(
            'SELECT model, origin_ts, degraded, elapsed_ms, context_coverage
               FROM forecast_run ORDER BY origin_ts DESC, id DESC LIMIT 1'
        );
        $lastPoll = $this->db->selectOne(
            "SELECT started_at, status, message FROM job_run WHERE job = 'poll' ORDER BY id DESC LIMIT 1"
        );
        $failing = $this->db->select(
            "SELECT job, COUNT(*) n FROM job_run
              WHERE status = 'error' AND started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
              GROUP BY job"
        );

        return [
            'last_run'       => $lastRun,
            'last_poll'      => $lastPoll,
            'failing_jobs'   => $failing,
            'readings_total' => (int) $this->db->scalar('SELECT COUNT(*) FROM reading_raw'),
            'target_span'    => $this->db->selectOne('SELECT MIN(ts) AS first_ts, MAX(ts) AS last_ts FROM target_15min'),
        ];
    }
}

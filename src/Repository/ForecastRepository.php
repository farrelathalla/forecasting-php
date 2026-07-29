<?php
declare(strict_types=1);

namespace Tbw\Repository;

use DateTimeImmutable;
use Tbw\Db;
use Tbw\Domain;
use Tbw\Forecast\ForecastResult;

final class ForecastRepository
{
    private const QUANTILE_COLUMNS = ['q10', 'q20', 'q30', 'q40', 'q50', 'q60', 'q70', 'q80', 'q90'];

    public function __construct(private Db $db)
    {
    }

    /**
     * Writes the run and all its points. The run is keyed on (model, origin_ts), so a
     * repeat overwrites rather than appends — the same defect that made the notebook's
     * PatchTST row report n=81 instead of 72.
     */
    public function save(ForecastResult $result, string $originTs, float $contextCoverage): int
    {
        return $this->db->transaction(function (Db $db) use ($result, $originTs, $contextCoverage): int {
            $db->execute(
                'INSERT INTO forecast_run (model, origin_ts, elapsed_ms, degraded, context_coverage, n_targets, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     elapsed_ms = VALUES(elapsed_ms), degraded = VALUES(degraded),
                     context_coverage = VALUES(context_coverage), n_targets = VALUES(n_targets),
                     note = VALUES(note), created_at = NOW()',
                [
                    $result->model,
                    $originTs,
                    $result->elapsedMs,
                    $result->degraded ? 1 : 0,
                    $contextCoverage,
                    count($result->forecasts),
                    $result->note === '' ? null : mb_substr($result->note, 0, 255),
                ]
            );

            $runId = (int) $db->scalar(
                'SELECT id FROM forecast_run WHERE model = ? AND origin_ts = ?',
                [$result->model, $originTs]
            );

            $origin = new DateTimeImmutable($originTs);
            $rows = [];
            foreach ($result->forecasts as $target => $forecast) {
                $horizon = count($forecast['median']);
                for ($h = 0; $h < $horizon; $h++) {
                    $ts = $origin->modify('+' . ($h * Domain::MODEL_FREQ_MIN) . ' minutes')->format('Y-m-d H:i:s');
                    $row = [$runId, (string) $target, $ts, $h + 1];
                    foreach (Domain::QUANTILES as $q) {
                        $column = $forecast['quantiles'][(string) $q] ?? null;
                        $row[] = $column === null ? null : (float) $column[$h];
                    }
                    $rows[] = $row;
                }
            }

            $db->upsert(
                'forecast_point',
                array_merge(['run_id', 'target', 'ts', 'horizon_step'], self::QUANTILE_COLUMNS),
                $rows,
                self::QUANTILE_COLUMNS,
                100
            );

            return $runId;
        });
    }

    /** @return list<array<string,mixed>> */
    public function points(int $runId, ?string $target = null): array
    {
        $sql = 'SELECT * FROM forecast_point WHERE run_id = ?';
        $params = [$runId];
        if ($target !== null) {
            $sql .= ' AND target = ?';
            $params[] = $target;
        }
        return $this->db->select($sql . ' ORDER BY target, ts', $params);
    }

    /** Newest run, preferring a healthy one over a degraded one at the same origin. */
    public function latestRun(): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM forecast_run ORDER BY origin_ts DESC, degraded ASC, id DESC LIMIT 1'
        );
    }

    public function runAt(string $originTs, ?string $model = null): ?array
    {
        if ($model !== null) {
            return $this->db->selectOne(
                'SELECT * FROM forecast_run WHERE origin_ts = ? AND model = ?',
                [$originTs, $model]
            );
        }
        return $this->db->selectOne(
            'SELECT * FROM forecast_run WHERE origin_ts = ? ORDER BY degraded ASC, id DESC LIMIT 1',
            [$originTs]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentRuns(int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM forecast_run ORDER BY origin_ts DESC, id DESC LIMIT ' . (int) $limit
        );
    }

    /**
     * Runs whose 24 h window has fully matured and that have not been scored yet.
     *
     * @return list<array<string,mixed>>
     */
    public function unscoredMaturedRuns(string $now, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT r.* FROM forecast_run r
              WHERE r.origin_ts <= DATE_SUB(?, INTERVAL ? MINUTE)
                AND NOT EXISTS (SELECT 1 FROM forecast_score s WHERE s.run_id = r.id)
              ORDER BY r.origin_ts
              LIMIT ' . (int) $limit,
            [$now, Domain::HORIZON * Domain::MODEL_FREQ_MIN]
        );
    }

    public function deleteOlderThan(string $cutoff): int
    {
        return $this->db->execute('DELETE FROM forecast_run WHERE origin_ts < ?', [$cutoff]);
    }
}

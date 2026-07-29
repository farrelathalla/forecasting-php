<?php
declare(strict_types=1);

namespace Tbw\Maintenance;

use Tbw\Db;

/**
 * Expiry for the high-volume tables.
 *
 * Needed once the poll interval drops: at 10 s, reading_raw grows by ~138k rows a day —
 * roughly the rate the plant historian itself records — which is ~50M rows a year.
 *
 * What is NOT pruned is as deliberate as what is. The 15-minute grid is the long-term
 * store: every target, every score and every SPC value is built from it, it is three
 * orders of magnitude smaller, and shortening it would silently shorten the model
 * context. Raw readings are the reconstruction input, and once they have been folded
 * into the grid their job is done.
 */
final class Retention
{
    /** Below this a typo would wipe history the snapshot API can never give back. */
    private const MIN_DAYS = 1;

    public function __construct(private Db $db)
    {
    }

    public function pruneReadings(int $keepDays): int
    {
        $cutoff = $this->cutoff($keepDays);
        return $this->db->execute('DELETE FROM reading_raw WHERE observed_at < ?', [$cutoff]);
    }

    /** forecast_point cascades from forecast_run, so deleting runs is enough. */
    public function pruneForecasts(int $keepDays): int
    {
        $cutoff = $this->cutoff($keepDays);
        return $this->db->execute('DELETE FROM forecast_run WHERE origin_ts < ?', [$cutoff]);
    }

    /**
     * Successes age out; failures do not. A months-old success is noise, while a
     * months-old failure is the only record that something was broken then.
     */
    public function pruneJobLog(int $keepDays): int
    {
        $cutoff = $this->cutoff($keepDays);
        return $this->db->execute(
            "DELETE FROM job_run WHERE started_at < ? AND status <> 'error'",
            [$cutoff]
        );
    }

    public function pruneSpcHistory(int $keepDays): int
    {
        $cutoff = $this->cutoff($keepDays);
        return $this->db->execute('DELETE FROM spc_state WHERE ts < ?', [$cutoff]);
    }

    public function pruneProjections(int $keepDays): int
    {
        $cutoff = $this->cutoff($keepDays);
        return $this->db->execute('DELETE FROM projection WHERE computed_at < ?', [$cutoff]);
    }

    /** @return array<string,int> */
    public function tableCounts(): array
    {
        $tables = [
            'reading_raw', 'grid_15min', 'target_15min', 'physics_15min',
            'forecast_run', 'forecast_point', 'forecast_score',
            'spc_state', 'projection', 'alarm_event', 'job_run',
        ];
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = (int) $this->db->scalar("SELECT COUNT(*) FROM `{$table}`");
        }
        return $out;
    }

    private function cutoff(int $keepDays): string
    {
        if ($keepDays < self::MIN_DAYS) {
            throw new \InvalidArgumentException(
                "retention window must be at least " . self::MIN_DAYS . " day, got {$keepDays}"
            );
        }
        return date('Y-m-d H:i:s', time() - $keepDays * 86400);
    }
}

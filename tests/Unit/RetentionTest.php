<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Maintenance\Retention;
use Tests\DbTestCase;

/**
 * At a 10 s poll interval reading_raw grows by ~138k rows a day — about the same rate
 * the plant historian itself records — which is ~50M rows a year. That is fine to hold
 * for a while and not fine to hold forever, so raw readings expire.
 *
 * The 15-minute grid is the long-term store and is never pruned: it is what the model
 * and every score are built on, and it is three orders of magnitude smaller.
 */
final class RetentionTest extends DbTestCase
{
    private Retention $retention;

    public function setUp(): void
    {
        parent::setUp();
        $this->retention = new Retention($this->db);
    }

    private function reading(string $observedAt, string $asset = 'TBW1'): void
    {
        $this->db->execute(
            'INSERT INTO reading_raw (asset, signal_name, observed_at, value) VALUES (?, ?, ?, ?)',
            [$asset, 'POWER', $observedAt, 183.5]
        );
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400);
    }

    public function testDeletesRawReadingsOlderThanTheWindow(): void
    {
        $this->reading($this->daysAgo(40));
        $this->reading($this->daysAgo(31), 'TBW3');
        $this->reading($this->daysAgo(5));

        $deleted = $this->retention->pruneReadings(30);

        $this->assertSame(2, $deleted);
        $this->assertSame(1, $this->count('reading_raw'));
    }

    public function testKeepsEverythingInsideTheWindow(): void
    {
        $this->reading($this->daysAgo(29));
        $this->reading($this->daysAgo(1), 'TBW3');

        $this->assertSame(0, $this->retention->pruneReadings(30));
        $this->assertSame(2, $this->count('reading_raw'));
    }

    public function testNeverTouchesTheFifteenMinuteGrid(): void
    {
        // The grid is the long-term store: every target, score and SPC value is built
        // from it, and pruning it would silently shorten the model context.
        $this->db->execute(
            'INSERT INTO target_15min (target, ts, value, source) VALUES (?, ?, ?, ?)',
            ['POWER|TBW1', '2025-01-01 00:00:00', 186.0, 'seed']
        );
        $this->db->execute(
            'INSERT INTO grid_15min (asset, signal_name, ts, value) VALUES (?, ?, ?, ?)',
            ['TBW1', 'POWER', '2025-01-01 00:00:00', 186.0]
        );

        $this->retention->pruneReadings(30);

        $this->assertSame(1, $this->count('target_15min'));
        $this->assertSame(1, $this->count('grid_15min'));
    }

    public function testRefusesAnAbsurdlyShortWindow(): void
    {
        // A typo like --days=0 would delete the entire raw history in one go, and the
        // snapshot API cannot give it back. Guard it rather than trust the caller.
        $this->reading($this->daysAgo(1));
        $this->assertThrows(\InvalidArgumentException::class, function (): void {
            $this->retention->pruneReadings(0);
        });
        $this->assertSame(1, $this->count('reading_raw'));
    }

    public function testPrunesOldForecastRunsButKeepsRecentOnes(): void
    {
        $this->db->execute(
            'INSERT INTO forecast_run (model, origin_ts) VALUES (?, ?), (?, ?)',
            ['chronos-2', $this->daysAgo(120), 'chronos-2', $this->daysAgo(3)]
        );
        $deleted = $this->retention->pruneForecasts(90);

        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->count('forecast_run'));
    }

    public function testPruningARunTakesItsPointsWithIt(): void
    {
        // forecast_point has an ON DELETE CASCADE; if that ever regresses, orphaned
        // points would quietly become the largest table in the database.
        $this->db->execute('INSERT INTO forecast_run (model, origin_ts) VALUES (?, ?)', ['chronos-2', $this->daysAgo(120)]);
        $runId = $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO forecast_point (run_id, target, ts, horizon_step, q50) VALUES (?, ?, ?, ?, ?)',
            [$runId, 'POWER|TBW1', $this->daysAgo(120), 1, 186.0]
        );

        $this->retention->pruneForecasts(90);
        $this->assertSame(0, $this->count('forecast_point'));
    }

    public function testPrunesJobLogButKeepsFailures(): void
    {
        // A months-old success is noise. A months-old failure is the only record that
        // something was broken then, and it is tiny.
        $this->db->execute(
            'INSERT INTO job_run (job, started_at, status) VALUES (?, ?, ?), (?, ?, ?)',
            ['poll', $this->daysAgo(60), 'ok', 'poll', $this->daysAgo(60), 'error']
        );

        $this->retention->pruneJobLog(30);

        $this->assertSame(1, $this->count('job_run'));
        $row = $this->db->selectOne('SELECT status FROM job_run');
        $this->assertSame('error', $row['status']);
    }

    public function testReportsTableSizesForCapacityPlanning(): void
    {
        $this->reading($this->daysAgo(1));
        $sizes = $this->retention->tableCounts();
        $this->assertSame(1, $sizes['reading_raw']);
        $this->assertTrue(array_key_exists('target_15min', $sizes));
    }
}

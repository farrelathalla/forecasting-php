<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Scoring\Scorecard;
use Tests\DbTestCase;

final class ScorecardTest extends DbTestCase
{
    private Scorecard $scorecard;

    public function setUp(): void
    {
        parent::setUp();
        $this->scorecard = new Scorecard($this->db);
    }

    private function score(string $model, string $target, float $mase, float $wql, float $cov, int $runId = 1): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO forecast_run (id, model, origin_ts) VALUES (?, ?, ?)',
            [$runId, $model, '2026-07-29 ' . str_pad((string) ($runId % 24), 2, '0', STR_PAD_LEFT) . ':00:00']
        );
        $this->db->execute(
            'INSERT INTO forecast_score (run_id, target, model, origin_ts, mase, wql, cov80, n_points)
             VALUES (?, ?, ?, NOW(), ?, ?, ?, 96)',
            [$runId, $target, $model, $mase, $wql, $cov]
        );
    }

    public function testRanksModelsByMase(): void
    {
        $this->score('naive-seasonal', 'POWER|TBW1', 0.68, 0.0103, 0.905, 1);
        $this->score('chronos-2', 'POWER|TBW1', 0.43, 0.0059, 0.842, 2);

        $rows = $this->scorecard->byModel();
        $this->assertSame('chronos-2', $rows[0]['model']);
        $this->assertSame('naive-seasonal', $rows[1]['model']);
    }

    public function testReportsSdAlongsideTheMean(): void
    {
        // The SD is not decoration. It is what showed LGBM-Local's MASE edge over
        // Chronos-2 was smaller than its own cross-fold spread.
        $this->score('chronos-2', 'POWER|TBW1', 0.30, 0.005, 0.84, 1);
        $this->score('chronos-2', 'POWER|TBW1', 0.50, 0.007, 0.84, 2);

        $row = $this->scorecard->byModel()[0];
        $this->assertFloatEquals(0.40, $row['mase'], 1e-9);
        $this->assertNotNull($row['mase_sd']);
        $this->assertGreaterThan(0.0, $row['mase_sd']);
    }

    public function testCoverageIsKeptSeparateFromWql(): void
    {
        // A model can hold good WQL while being systematically over-confident, so the
        // two must never be collapsed into one number.
        $this->score('overconfident', 'POWER|TBW1', 0.40, 0.0065, 0.684, 1);
        $row = $this->scorecard->byModel()[0];
        $this->assertFloatEquals(0.684, $row['cov80'], 1e-9);
        $this->assertFloatEquals(0.0065, $row['wql'], 1e-9);
    }

    public function testCountsRunsAndScoresSeparately(): void
    {
        $this->score('chronos-2', 'POWER|TBW1', 0.4, 0.005, 0.84, 1);
        $this->score('chronos-2', 'INLET_TEMP|TBW1', 0.5, 0.006, 0.85, 1);

        $row = $this->scorecard->byModel()[0];
        $this->assertSame(2, $row['n_scores']);
        $this->assertSame(1, $row['n_runs']);
    }

    public function testPerTargetListsEveryTargetEvenWithNoScoresYet(): void
    {
        // A missing target must show as an empty row, not vanish. Silently dropping it
        // would hide a target that has stopped being forecast at all.
        $this->score('chronos-2', 'POWER|TBW1', 0.4, 0.005, 0.84, 1);
        $rows = $this->scorecard->byTarget('chronos-2');

        $this->assertCount(9, $rows);
        $byTarget = array_column($rows, null, 'target');
        $this->assertFloatEquals(0.4, $byTarget['POWER|TBW1']['mase'], 1e-9);
        $this->assertNull($byTarget['FLOWRATE|TBW3']['mase']);
        $this->assertSame(0, $byTarget['FLOWRATE|TBW3']['n']);
    }

    public function testEmptyDatabaseYieldsAValidEmptyLeaderboard(): void
    {
        $this->assertCount(0, $this->scorecard->byModel());
        $this->assertCount(9, $this->scorecard->byTarget());
    }

    public function testErrorGrowthReturnsAllFourBucketsEvenWithNoData(): void
    {
        $buckets = $this->scorecard->errorGrowth();
        $this->assertCount(4, $buckets);
        $this->assertNull($buckets['0-1h']);
    }

    public function testHealthReportsTheLastRunAndReadingCount(): void
    {
        $this->score('chronos-2', 'POWER|TBW1', 0.4, 0.005, 0.84, 1);
        $health = $this->scorecard->health();
        $this->assertNotNull($health['last_run']);
        $this->assertSame('chronos-2', $health['last_run']['model']);
        $this->assertSame(0, $health['readings_total']);
    }

    public function testNullMaseSortsLastRatherThanWinning(): void
    {
        $this->score('broken', 'POWER|TBW1', 0.0, 0.0, 0.0, 1);
        $this->db->execute("UPDATE forecast_score SET mase = NULL WHERE model = 'broken'");
        $this->score('chronos-2', 'POWER|TBW1', 0.43, 0.0059, 0.842, 2);

        $rows = $this->scorecard->byModel();
        $this->assertSame('chronos-2', $rows[0]['model']);
    }
}

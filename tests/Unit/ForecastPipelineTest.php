<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Domain;
use Tbw\Forecast\ContextBuilder;
use Tbw\Forecast\ForecastResult;
use Tbw\Repository\ForecastRepository;
use Tbw\Repository\GridRepository;
use Tests\DbTestCase;

final class ForecastPipelineTest extends DbTestCase
{
    private GridRepository $grid;
    private ForecastRepository $forecasts;

    public function setUp(): void
    {
        parent::setUp();
        $this->grid = new GridRepository($this->db);
        $this->forecasts = new ForecastRepository($this->db);
    }

    /** Writes a clean daily cycle ending just before $origin. */
    private function seedTarget(string $target, string $origin, int $steps, float $level = 186.0): void
    {
        $column = [];
        $ts = new \DateTimeImmutable($origin);
        for ($i = $steps; $i >= 1; $i--) {
            $at = $ts->modify('-' . ($i * Domain::MODEL_FREQ_MIN) . ' minutes')->format('Y-m-d H:i:s');
            $column[$at] = $level + 2.0 * sin(2 * M_PI * $i / 96.0);
        }
        $this->grid->upsertTargets([$target => $column]);
    }

    public function testContextEndsStrictlyBeforeTheOrigin(): void
    {
        // The notebook gotcha: FEAT.loc[:cutoff_ts] is inclusive, which both leaked the
        // first test point and made predict_df reject the future frame. iloc[ci-C:ci]
        // is the fix, and this is its test.
        $origin = '2026-07-29 14:00:00';
        $this->seedTarget('POWER|TBW1', $origin, 300);
        $this->grid->upsertTargets(['POWER|TBW1' => [$origin => 999.0]]);

        $contexts = (new ContextBuilder($this->grid))->build(['POWER|TBW1'], $origin, 300);

        $this->assertCount(300, $contexts['POWER|TBW1']);
        foreach ($contexts['POWER|TBW1'] as $v) {
            $this->assertTrue($v === null || abs($v - 999.0) > 1e-6, 'the origin value leaked into the context');
        }
    }

    public function testContextIsDenseEvenWhereRowsAreMissing(): void
    {
        // Chronos-2 assumes a regular grid. Handing it a compacted series would shift
        // every lag and silently destroy the daily seasonality.
        $origin = '2026-07-29 14:00:00';
        $this->grid->upsertTargets(['POWER|TBW1' => [
            '2026-07-29 13:00:00' => 186.0,
            '2026-07-29 13:45:00' => 187.0,
        ]]);

        $contexts = (new ContextBuilder($this->grid))->build(['POWER|TBW1'], $origin, 8);
        $this->assertCount(8, $contexts['POWER|TBW1']);

        $known = array_filter($contexts['POWER|TBW1'], static fn(?float $v) => $v !== null);
        $this->assertCount(2, $known, 'missing steps must be null, not dropped');
    }

    public function testContextIsOldestFirst(): void
    {
        $origin = '2026-07-29 14:00:00';
        $this->grid->upsertTargets(['POWER|TBW1' => [
            '2026-07-29 13:30:00' => 1.0,
            '2026-07-29 13:45:00' => 2.0,
        ]]);
        $contexts = (new ContextBuilder($this->grid))->build(['POWER|TBW1'], $origin, 2);
        $this->assertFloatEquals(1.0, $contexts['POWER|TBW1'][0]);
        $this->assertFloatEquals(2.0, $contexts['POWER|TBW1'][1]);
    }

    public function testStoresRunAndEveryHorizonStep(): void
    {
        $result = $this->syntheticResult('chronos-2');
        $runId = $this->forecasts->save($result, '2026-07-29 14:00:00', 0.87);

        $this->assertGreaterThan(0, $runId);
        $this->assertSame(1, $this->count('forecast_run'));
        $this->assertSame(96 * 2, $this->count('forecast_point'));

        $run = $this->db->selectOne('SELECT * FROM forecast_run WHERE id = ?', [$runId]);
        $this->assertSame('chronos-2', $run['model']);
        $this->assertSame(0, (int) $run['degraded']);
        $this->assertFloatEquals(0.87, (float) $run['context_coverage'], 1e-6);
        $this->assertSame(2, (int) $run['n_targets']);
    }

    public function testFirstForecastStepIsTheOriginItself(): void
    {
        $runId = $this->forecasts->save($this->syntheticResult('chronos-2'), '2026-07-29 14:00:00', 1.0);
        $row = $this->db->selectOne(
            'SELECT ts, horizon_step FROM forecast_point WHERE run_id = ? ORDER BY ts LIMIT 1',
            [$runId]
        );
        $this->assertSame('2026-07-29 14:00:00', $row['ts']);
        $this->assertSame(1, (int) $row['horizon_step']);
    }

    public function testLastForecastStepIsTwentyFourHoursOut(): void
    {
        $runId = $this->forecasts->save($this->syntheticResult('chronos-2'), '2026-07-29 14:00:00', 1.0);
        $row = $this->db->selectOne(
            'SELECT ts, horizon_step FROM forecast_point WHERE run_id = ? ORDER BY ts DESC LIMIT 1',
            [$runId]
        );
        $this->assertSame('2026-07-30 13:45:00', $row['ts']);
        $this->assertSame(96, (int) $row['horizon_step']);
    }

    public function testRerunningTheSameOriginDoesNotDuplicate(): void
    {
        // The notebook's append-only RESULTS reported PatchTST at n=81 and made its
        // score untrustworthy. Here the same mistake is structurally impossible.
        $this->forecasts->save($this->syntheticResult('chronos-2'), '2026-07-29 14:00:00', 1.0);
        $this->forecasts->save($this->syntheticResult('chronos-2'), '2026-07-29 14:00:00', 1.0);

        $this->assertSame(1, $this->count('forecast_run'));
        $this->assertSame(96 * 2, $this->count('forecast_point'));
    }

    public function testDifferentModelsAtTheSameOriginCoexist(): void
    {
        // Without this, a degraded run would overwrite the Chronos-2 run at the same
        // origin and the two could never be compared.
        $this->forecasts->save($this->syntheticResult('chronos-2'), '2026-07-29 14:00:00', 1.0);
        $this->forecasts->save($this->syntheticResult('naive-seasonal', true), '2026-07-29 14:00:00', 1.0);
        $this->assertSame(2, $this->count('forecast_run'));
    }

    public function testDegradedRunIsFlaggedAndKeepsItsReason(): void
    {
        $runId = $this->forecasts->save($this->syntheticResult('naive-seasonal', true), '2026-07-29 14:00:00', 0.5);
        $run = $this->db->selectOne('SELECT * FROM forecast_run WHERE id = ?', [$runId]);
        $this->assertSame(1, (int) $run['degraded']);
        $this->assertStringContains('sidecar', (string) $run['note']);
    }

    public function testReadsBackTheIntervalForCharting(): void
    {
        $runId = $this->forecasts->save($this->syntheticResult('chronos-2'), '2026-07-29 14:00:00', 1.0);
        $rows = $this->forecasts->points($runId, 'POWER|TBW1');

        $this->assertCount(96, $rows);
        $this->assertTrue($rows[0]['q10'] < $rows[0]['q50']);
        $this->assertTrue($rows[0]['q50'] < $rows[0]['q90']);
    }

    public function testLatestRunPrefersTheNonDegradedModel(): void
    {
        $this->forecasts->save($this->syntheticResult('naive-seasonal', true), '2026-07-29 14:00:00', 1.0);
        $this->forecasts->save($this->syntheticResult('chronos-2'), '2026-07-29 14:00:00', 1.0);

        $latest = $this->forecasts->latestRun();
        $this->assertSame('chronos-2', $latest['model']);
    }

    private function syntheticResult(string $model, bool $degraded = false): ForecastResult
    {
        $forecasts = [];
        foreach (['POWER|TBW1', 'INLET_TEMP|TBW1'] as $target) {
            $quantiles = [];
            foreach (Domain::QUANTILES as $q) {
                $column = [];
                for ($h = 0; $h < 96; $h++) {
                    $column[] = 100.0 + ($q - 0.5) * 8.0 + $h * 0.01;
                }
                $quantiles[(string) $q] = $column;
            }
            $forecasts[$target] = ['median' => $quantiles['0.5'], 'quantiles' => $quantiles];
        }
        return new ForecastResult(
            $model,
            $forecasts,
            5500,
            $degraded,
            $degraded ? 'sidecar unavailable, naive fallback: connection refused' : ''
        );
    }
}

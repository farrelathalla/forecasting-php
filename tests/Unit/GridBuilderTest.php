<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Domain;
use Tbw\Grid\GridBuilder;
use Tbw\Ingest\Reading;
use Tbw\Repository\GridRepository;
use Tbw\Repository\ReadingRepository;
use Tests\DbTestCase;

final class GridBuilderTest extends DbTestCase
{
    private ReadingRepository $readings;
    private GridRepository $grid;

    public function setUp(): void
    {
        parent::setUp();
        $this->readings = new ReadingRepository($this->db);
        $this->grid = new GridRepository($this->db);
    }

    /** A full 16-tag snapshot at one instant, healthy values. */
    private function snapshot(string $at, float $powerTbw1 = 183.5): void
    {
        $values = [
            'TBW1' => [
                'FLOWRATE' => 33.4, 'INLET_PRESS' => 201.0, 'INLET_TEMP' => 38.6,
                'MOTOR_CURRENT' => 183.5, 'MOTOR_RPM' => 45.9,
                'OUTLET_PRESSURE' => 5.19, 'OUTLET_TEMP' => 43.8, 'POWER' => $powerTbw1,
            ],
            'TBW3' => [
                'FLOWRATE' => 29.5, 'INLET_PRESS' => 78.0, 'INLET_TEMP' => 41.2,
                'MOTOR_CURRENT' => 183.9, 'MOTOR_RPM' => 45.9,
                'OUTLET_PRESSURE' => 5.17, 'OUTLET_TEMP' => 44.7, 'POWER' => 171.4,
            ],
        ];
        $rows = [];
        foreach ($values as $asset => $signals) {
            foreach ($signals as $signal => $value) {
                $rows[] = Reading::of($asset, $signal, $at, $value);
            }
        }
        $this->readings->insertMany($rows);
    }

    public function testBuildsTargetsForTheBucketCurrentlyInProgress(): void
    {
        // Regression: flooring the window end to the bucket label made the newest
        // bucket cover an empty minute range, so every live target came out NULL while
        // the API was returning perfectly good data.
        $this->snapshot('2026-07-29 13:49:10');
        $result = GridBuilder::make($this->db)->rebuild('2026-07-29 13:49:00', '2026-07-29 13:54:42');

        $this->assertGreaterThan(0, $result['targets']);

        $row = $this->db->selectOne(
            "SELECT value FROM target_15min WHERE target = 'POWER|TBW1' AND ts = '2026-07-29 13:45:00'"
        );
        $this->assertNotNull($row);
        $this->assertFloatEquals(183.5, (float) $row['value'], 1e-9);
    }

    public function testMarksAssetsRunningWhenCurrentIsAboveThreshold(): void
    {
        $this->snapshot('2026-07-29 13:49:10');
        GridBuilder::make($this->db)->rebuild('2026-07-29 13:49:00', '2026-07-29 13:54:42');

        $row = $this->db->selectOne(
            "SELECT value FROM physics_15min WHERE channel = 'is_running|TBW1' ORDER BY ts DESC LIMIT 1"
        );
        $this->assertFloatEquals(1.0, (float) $row['value']);
    }

    public function testProducesPhysicsChannelsWithRealValues(): void
    {
        $this->snapshot('2026-07-29 13:49:10');
        GridBuilder::make($this->db)->rebuild('2026-07-29 13:49:00', '2026-07-29 13:54:42');

        $dt = $this->db->selectOne(
            "SELECT value FROM physics_15min WHERE channel = 'dT|TBW1' AND value IS NOT NULL ORDER BY ts DESC LIMIT 1"
        );
        $this->assertNotNull($dt, 'dT must be computed for a running asset');
        $this->assertFloatEquals(43.8 - 38.6, (float) $dt['value'], 1e-6);
    }

    public function testCollapsesHeaderPressureIntoTheStationTarget(): void
    {
        $this->snapshot('2026-07-29 13:49:10');
        GridBuilder::make($this->db)->rebuild('2026-07-29 13:49:00', '2026-07-29 13:54:42');

        $row = $this->db->selectOne(
            "SELECT value FROM target_15min WHERE target = 'HEADER_PRESSURE' AND value IS NOT NULL ORDER BY ts DESC LIMIT 1"
        );
        $this->assertFloatEquals(5.18, (float) $row['value'], 1e-6);
    }

    public function testRerunningOverTheSameWindowDoesNotDuplicate(): void
    {
        $this->snapshot('2026-07-29 13:49:10');
        $builder = GridBuilder::make($this->db);
        $builder->rebuild('2026-07-29 13:00:00', '2026-07-29 13:54:42');
        $before = $this->count('target_15min');
        $builder->rebuild('2026-07-29 13:00:00', '2026-07-29 13:54:42');
        $this->assertSame($before, $this->count('target_15min'));
    }

    public function testCorrectsAnEarlierValueOnRerun(): void
    {
        // Late-arriving readings must be able to fix a bucket, not sit beside it.
        $this->snapshot('2026-07-29 13:46:00', 100.0);
        $builder = GridBuilder::make($this->db);
        $builder->rebuild('2026-07-29 13:45:00', '2026-07-29 13:47:00');

        $this->readings->insertMany([Reading::of('TBW1', 'POWER', '2026-07-29 13:50:00', 200.0)]);
        $builder->rebuild('2026-07-29 13:45:00', '2026-07-29 13:52:00');

        $row = $this->db->selectOne(
            "SELECT value FROM target_15min WHERE target = 'POWER|TBW1' AND ts = '2026-07-29 13:45:00'"
        );
        $this->assertGreaterThan(100.0, (float) $row['value'], 'the newer reading must move the bucket mean');
    }

    public function testWritesEveryTargetForEveryStep(): void
    {
        $this->snapshot('2026-07-29 13:49:10');
        GridBuilder::make($this->db)->rebuild('2026-07-29 13:00:00', '2026-07-29 13:54:42');

        $distinct = (int) $this->db->scalar('SELECT COUNT(DISTINCT target) FROM target_15min');
        $this->assertSame(count(Domain::TARGETS), $distinct);
    }

    public function testDoesNotWriteRetiredAssetWithNoData(): void
    {
        // F1: TBW2 is a stopped machine, not a broken sensor. Writing a wall of nulls
        // for it would put a dead pump back on the dashboard.
        $this->snapshot('2026-07-29 13:49:10');
        GridBuilder::make($this->db)->rebuild('2026-07-29 13:00:00', '2026-07-29 13:54:42');

        $n = (int) $this->db->scalar("SELECT COUNT(*) FROM grid_15min WHERE asset = 'TBW2'");
        $this->assertSame(0, $n);
    }

    public function testTargetTailStopsStrictlyBeforeTheOrigin(): void
    {
        // The off-by-one that leaked the first test point in the notebook: FEAT.loc[:cut]
        // is inclusive. Context must end strictly before the origin.
        $this->snapshot('2026-07-29 13:01:00');
        $this->snapshot('2026-07-29 13:16:00');
        $this->snapshot('2026-07-29 13:31:00');
        GridBuilder::make($this->db)->rebuild('2026-07-29 13:00:00', '2026-07-29 13:34:00');

        $tail = $this->grid->targetTail('POWER|TBW1', '2026-07-29 13:30:00', 10);
        $this->assertGreaterThan(0, count($tail));
        foreach ($tail as $row) {
            $this->assertTrue($row['ts'] < '2026-07-29 13:30:00', "context leaked {$row['ts']}");
        }
    }
}

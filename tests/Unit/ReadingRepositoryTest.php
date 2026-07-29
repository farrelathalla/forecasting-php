<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tbw\Ingest\Reading;
use Tbw\Repository\ReadingRepository;
use Tests\DbTestCase;

final class ReadingRepositoryTest extends DbTestCase
{
    private ReadingRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->repo = new ReadingRepository($this->db);
    }

    /** @return list<Reading> */
    private function batch(string $at = '2026-07-29 13:29:10'): array
    {
        return [
            Reading::of('TBW1', 'POWER', $at, 183.5),
            Reading::of('TBW1', 'FLOWRATE', $at, 33.4),
            Reading::of('TBW3', 'POWER', $at, 171.4),
        ];
    }

    public function testStoresEveryReading(): void
    {
        $this->assertSame(3, $this->repo->insertMany($this->batch()));
        $this->assertSame(3, $this->count('reading_raw'));
    }

    public function testSamePayloadTwiceStoresNoDuplicates(): void
    {
        // F6: the historian writes only on deadband crossing with a 30-min heartbeat,
        // so polling every minute re-reads the same updated_at for most tags. Without
        // dedup on (asset, signal, observed_at) the table grows by 16 rows a minute
        // of pure noise and every downstream aggregate is wrong.
        $this->repo->insertMany($this->batch());
        $this->repo->insertMany($this->batch());
        $this->assertSame(3, $this->count('reading_raw'));
    }

    public function testNewTimestampAddsRows(): void
    {
        $this->repo->insertMany($this->batch('2026-07-29 13:29:10'));
        $this->repo->insertMany($this->batch('2026-07-29 13:30:10'));
        $this->assertSame(6, $this->count('reading_raw'));
    }

    public function testLatestPerTagReturnsOneRowPerTag(): void
    {
        $this->repo->insertMany($this->batch('2026-07-29 13:29:10'));
        $this->repo->insertMany([Reading::of('TBW1', 'POWER', '2026-07-29 13:35:10', 190.0)]);

        $latest = $this->repo->latestPerTag();
        $this->assertCount(3, $latest);

        $power = null;
        foreach ($latest as $row) {
            if ($row['asset'] === 'TBW1' && $row['signal_name'] === 'POWER') {
                $power = $row;
            }
        }
        $this->assertNotNull($power);
        $this->assertFloatEquals(190.0, (float) $power['value']);
        $this->assertSame('2026-07-29 13:35:10', $power['observed_at']);
    }

    public function testEmptyBatchIsNoOp(): void
    {
        $this->assertSame(0, $this->repo->insertMany([]));
        $this->assertSame(0, $this->count('reading_raw'));
    }

    public function testRangeQueryReturnsOrderedSeries(): void
    {
        $this->repo->insertMany([
            Reading::of('TBW1', 'POWER', '2026-07-29 13:31:00', 3.0),
            Reading::of('TBW1', 'POWER', '2026-07-29 13:29:00', 1.0),
            Reading::of('TBW1', 'POWER', '2026-07-29 13:30:00', 2.0),
            Reading::of('TBW1', 'FLOWRATE', '2026-07-29 13:30:00', 9.0),
        ]);
        $rows = $this->repo->series('TBW1', 'POWER', '2026-07-29 13:00:00', '2026-07-29 14:00:00');
        $this->assertCount(3, $rows);
        $this->assertFloatEquals(1.0, (float) $rows[0]['value']);
        $this->assertFloatEquals(3.0, (float) $rows[2]['value']);
    }

    public function testMaxObservedAtDrivesIncrementalAggregation(): void
    {
        $this->assertNull($this->repo->maxObservedAt());
        $this->repo->insertMany($this->batch('2026-07-29 13:29:10'));
        $this->assertSame('2026-07-29 13:29:10', $this->repo->maxObservedAt());
    }
}

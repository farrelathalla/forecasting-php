<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\DbTestCase;

final class MigrationTest extends DbTestCase
{
    private const EXPECTED = [
        'alarm_event', 'alarm_state', 'forecast_point', 'forecast_run', 'forecast_score',
        'grid_15min', 'job_run', 'physics_15min', 'projection', 'reading_raw',
        'spc_state', 'target_15min',
    ];

    public function testCreatesEveryExpectedTable(): void
    {
        $tables = $this->tableNames();
        foreach (self::EXPECTED as $t) {
            $this->assertContains($t, $tables, "table {$t} missing after migrate");
        }
    }

    public function testMigrationIsIdempotent(): void
    {
        $this->db->migrate();
        $this->db->migrate();
        $this->assertCount(count(self::EXPECTED), self::EXPECTED);
        foreach (self::EXPECTED as $t) {
            $this->assertContains($t, $this->tableNames());
        }
    }

    public function testDedupKeysExistOnEveryResultTable(): void
    {
        // Idempotent writes are structural here, not a habit. The notebook's
        // append-only RESULTS silently reported PatchTST at n=81 and corrupted its
        // mean; a UNIQUE key makes that class of defect impossible.
        $expected = [
            'reading_raw'    => ['asset', 'signal_name', 'observed_at'],
            'grid_15min'     => ['asset', 'signal_name', 'ts'],
            'target_15min'   => ['target', 'ts'],
            'physics_15min'  => ['channel', 'ts'],
            'forecast_run'   => ['model', 'origin_ts'],
            'forecast_point' => ['run_id', 'target', 'ts'],
            'forecast_score' => ['run_id', 'target'],
            'spc_state'      => ['channel', 'ts'],
        ];
        foreach ($expected as $table => $cols) {
            $rows = $this->db->pdo()->query("SHOW INDEX FROM `{$table}` WHERE Non_unique = 0")->fetchAll();
            $byName = [];
            foreach ($rows as $r) {
                $byName[$r['Key_name']][(int) $r['Seq_in_index']] = $r['Column_name'];
            }
            $found = false;
            foreach ($byName as $columns) {
                ksort($columns);
                if (array_values($columns) === $cols) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "no UNIQUE index " . implode(',', $cols) . " on {$table}");
        }
    }

    public function testUniqueKeyActuallyRejectsDuplicates(): void
    {
        $sql = 'INSERT INTO target_15min (target, ts, value, source) VALUES (?, ?, ?, ?)';
        $this->db->pdo()->prepare($sql)->execute(['POWER|TBW1', '2026-07-29 13:00:00', 186.0, 'live']);
        $this->assertThrows(\PDOException::class, function (): void {
            $this->db->pdo()
                ->prepare('INSERT INTO target_15min (target, ts, value, source) VALUES (?, ?, ?, ?)')
                ->execute(['POWER|TBW1', '2026-07-29 13:00:00', 999.0, 'live']);
        });
        $this->assertSame(1, $this->count('target_15min'));
    }

    public function testGridAndTargetValuesAreNullable(): void
    {
        // F6: a gap longer than the hold limit must be stored as unknown, not as zero.
        // Storing 0.0 there would look like a stopped pump and poison every statistic.
        $this->db->pdo()
            ->prepare('INSERT INTO target_15min (target, ts, value, source) VALUES (?, ?, ?, ?)')
            ->execute(['POWER|TBW1', '2026-07-29 13:00:00', null, 'live']);
        $row = $this->db->pdo()->query('SELECT value FROM target_15min')->fetch();
        $this->assertNull($row['value']);
    }
}

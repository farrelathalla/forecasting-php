<?php
declare(strict_types=1);

namespace Tbw\Repository;

use Tbw\Db;
use Tbw\Domain;

final class GridRepository
{
    public function __construct(private Db $db)
    {
    }

    /** @param list<array{asset:string,signal:string,ts:string,value:?float,is_held:bool}> $rows */
    public function upsertGrid(array $rows, string $source = 'live'): int
    {
        if ($rows === []) {
            return 0;
        }
        $payload = array_map(
            static fn(array $r) => [$r['asset'], $r['signal'], $r['ts'], $r['value'], $r['is_held'] ? 1 : 0, $source],
            $rows
        );
        return $this->db->upsert(
            'grid_15min',
            ['asset', 'signal_name', 'ts', 'value', 'is_held', 'source'],
            $payload,
            ['value', 'is_held', 'source']
        );
    }

    /** @param array<string,array<string,?float>> $targets target => ts => value */
    public function upsertTargets(array $targets, string $source = 'live'): int
    {
        $rows = [];
        foreach ($targets as $target => $column) {
            foreach ($column as $ts => $value) {
                $rows[] = [$target, $ts, $value, $source];
            }
        }
        if ($rows === []) {
            return 0;
        }
        return $this->db->upsert('target_15min', ['target', 'ts', 'value', 'source'], $rows, ['value', 'source']);
    }

    /** @param array<string,array<string,?float>> $channels channel => ts => value */
    public function upsertPhysics(array $channels): int
    {
        $rows = [];
        foreach ($channels as $channel => $column) {
            foreach ($column as $ts => $value) {
                $rows[] = [$channel, $ts, $value];
            }
        }
        if ($rows === []) {
            return 0;
        }
        return $this->db->upsert('physics_15min', ['channel', 'ts', 'value'], $rows, ['value']);
    }

    /**
     * Dense series for one target, ordered by time.
     *
     * @return list<array{ts:string,value:?float}>
     */
    public function targetSeries(string $target, string $from, string $to): array
    {
        $rows = $this->db->select(
            'SELECT ts, value FROM target_15min WHERE target = ? AND ts >= ? AND ts <= ? ORDER BY ts',
            [$target, $from, $to]
        );
        return array_map(
            static fn(array $r) => ['ts' => (string) $r['ts'], 'value' => $r['value'] === null ? null : (float) $r['value']],
            $rows
        );
    }

    /**
     * The most recent N steps of a target, oldest first. Used to assemble the forecast
     * context, which must end strictly before the origin.
     *
     * @return list<array{ts:string,value:?float}>
     */
    public function targetTail(string $target, string $beforeTs, int $steps): array
    {
        $rows = $this->db->select(
            'SELECT ts, value FROM target_15min WHERE target = ? AND ts < ? ORDER BY ts DESC LIMIT ' . (int) $steps,
            [$target, $beforeTs]
        );
        $rows = array_reverse($rows);
        return array_map(
            static fn(array $r) => ['ts' => (string) $r['ts'], 'value' => $r['value'] === null ? null : (float) $r['value']],
            $rows
        );
    }

    /** @return list<array{ts:string,value:?float}> */
    public function physicsSeries(string $channel, string $from, string $to): array
    {
        $rows = $this->db->select(
            'SELECT ts, value FROM physics_15min WHERE channel = ? AND ts >= ? AND ts <= ? ORDER BY ts',
            [$channel, $from, $to]
        );
        return array_map(
            static fn(array $r) => ['ts' => (string) $r['ts'], 'value' => $r['value'] === null ? null : (float) $r['value']],
            $rows
        );
    }

    /** @return array<string,?float> channel => latest non-null value */
    public function latestPhysics(): array
    {
        $rows = $this->db->select(
            'SELECT p.channel, p.value, p.ts
               FROM physics_15min p
               JOIN (SELECT channel, MAX(ts) AS mx FROM physics_15min
                      WHERE value IS NOT NULL GROUP BY channel) m
                 ON m.channel = p.channel AND m.mx = p.ts'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['channel']] = $r['value'] === null ? null : (float) $r['value'];
        }
        return $out;
    }

    /** @return array<string,array{ts:string,value:?float}> target => latest point */
    public function latestTargets(): array
    {
        $rows = $this->db->select(
            'SELECT t.target, t.ts, t.value
               FROM target_15min t
               JOIN (SELECT target, MAX(ts) AS mx FROM target_15min
                      WHERE value IS NOT NULL GROUP BY target) m
                 ON m.target = t.target AND m.mx = t.ts'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['target']] = [
                'ts'    => (string) $r['ts'],
                'value' => $r['value'] === null ? null : (float) $r['value'],
            ];
        }
        return $out;
    }

    public function maxTargetTs(): ?string
    {
        $v = $this->db->scalar('SELECT MAX(ts) FROM target_15min');
        return $v === false || $v === null ? null : (string) $v;
    }

    public function maxGridTs(): ?string
    {
        $v = $this->db->scalar('SELECT MAX(ts) FROM grid_15min');
        return $v === false || $v === null ? null : (string) $v;
    }

    /**
     * Last timestamp carrying a real value, per asset.
     *
     * The dashboard needs this to say when a pump was last heard from rather than assert
     * from a constant which pumps exist. An asset that stopped reporting months ago is
     * only distinguishable from one that stopped an hour ago by its own last reading --
     * and if a stopped asset ever comes back, this is what notices without an edit.
     *
     * @return array<string,string> asset => timestamp
     */
    public function lastSeenPerAsset(): array
    {
        $rows = $this->db->select(
            'SELECT asset, MAX(ts) AS last_ts FROM grid_15min
              WHERE value IS NOT NULL GROUP BY asset'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['asset']] = (string) $r['last_ts'];
        }
        return $out;
    }

    /**
     * Fraction of the last CONTEXT steps that carry a real value, per target. Reported on
     * the dashboard so a forecast made on a thin context is never read as if it were made
     * on a full one.
     */
    public function contextCoverage(string $beforeTs, int $steps = Domain::CONTEXT): float
    {
        $expected = $steps * count(Domain::TARGETS);
        if ($expected === 0) {
            return 0.0;
        }
        $sql = 'SELECT COUNT(*) FROM (
                    SELECT target, ts FROM target_15min
                     WHERE ts < ? AND value IS NOT NULL
                     ORDER BY ts DESC LIMIT ' . (int) $expected . '
                ) x';
        $have = (int) $this->db->scalar($sql, [$beforeTs]);
        return min(1.0, $have / $expected);
    }
}

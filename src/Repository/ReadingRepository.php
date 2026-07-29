<?php
declare(strict_types=1);

namespace Tbw\Repository;

use Tbw\Db;
use Tbw\Ingest\Reading;

final class ReadingRepository
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @param list<Reading> $readings
     * @return int rows written (duplicates are silently skipped, not errors)
     */
    public function insertMany(array $readings): int
    {
        if ($readings === []) {
            return 0;
        }
        $rows = [];
        foreach ($readings as $r) {
            $rows[] = [$r->asset, $r->signal, $r->observedAt->format('Y-m-d H:i:s'), $r->value];
        }
        // INSERT IGNORE, not upsert: a reading at a given (tag, observed_at) is a fact
        // that cannot change. Rewriting it would mean the historian lied, and we would
        // rather keep the first version we saw.
        $affected = 0;
        foreach (array_chunk($rows, 200) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?)'));
            $params = [];
            foreach ($batch as $row) {
                foreach ($row as $v) {
                    $params[] = $v;
                }
            }
            $affected += $this->db->execute(
                "INSERT IGNORE INTO reading_raw (asset, signal_name, observed_at, value) VALUES {$placeholders}",
                $params
            );
        }
        return $affected;
    }

    /** @return list<array<string,mixed>> */
    public function latestPerTag(): array
    {
        return $this->db->select(
            'SELECT r.asset, r.signal_name, r.observed_at, r.value, r.ingested_at
               FROM reading_raw r
               JOIN (SELECT asset, signal_name, MAX(observed_at) AS mx
                       FROM reading_raw GROUP BY asset, signal_name) m
                 ON m.asset = r.asset AND m.signal_name = r.signal_name AND m.mx = r.observed_at
              ORDER BY r.asset, r.signal_name'
        );
    }

    /** @return list<array<string,mixed>> */
    public function series(string $asset, string $signal, string $from, string $to): array
    {
        return $this->db->select(
            'SELECT observed_at, value FROM reading_raw
              WHERE asset = ? AND signal_name = ? AND observed_at >= ? AND observed_at <= ?
              ORDER BY observed_at',
            [$asset, $signal, $from, $to]
        );
    }

    /** @return list<array<string,mixed>> every reading in a window, all tags, ordered */
    public function allInRange(string $from, string $to): array
    {
        return $this->db->select(
            'SELECT asset, signal_name, observed_at, value FROM reading_raw
              WHERE observed_at >= ? AND observed_at <= ?
              ORDER BY asset, signal_name, observed_at',
            [$from, $to]
        );
    }

    public function maxObservedAt(): ?string
    {
        $v = $this->db->scalar('SELECT MAX(observed_at) FROM reading_raw');
        return $v === false || $v === null ? null : (string) $v;
    }

    public function minObservedAt(): ?string
    {
        $v = $this->db->scalar('SELECT MIN(observed_at) FROM reading_raw');
        return $v === false || $v === null ? null : (string) $v;
    }

    public function total(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM reading_raw');
    }
}

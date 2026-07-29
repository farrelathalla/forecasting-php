<?php
declare(strict_types=1);

namespace Tbw;

/**
 * Audit row per CLI job. Without this, a cron job that has been failing silently for
 * three days looks identical to one that never ran.
 */
final class JobLogger
{
    private int $id = 0;

    public function __construct(private Db $db, private string $job)
    {
    }

    public static function start(Db $db, string $job): self
    {
        $logger = new self($db, $job);
        $db->execute(
            'INSERT INTO job_run (job, started_at, status) VALUES (?, NOW(), ?)',
            [$job, 'running']
        );
        $logger->id = $db->lastInsertId();
        return $logger;
    }

    public function succeed(int $rows = 0, string $message = ''): void
    {
        $this->finish('ok', $rows, $message);
    }

    public function fail(string $message): void
    {
        $this->finish('error', 0, $message);
    }

    private function finish(string $status, int $rows, string $message): void
    {
        if ($this->id === 0) {
            return;
        }
        $this->db->execute(
            'UPDATE job_run SET finished_at = NOW(), status = ?, rows_affected = ?, message = ? WHERE id = ?',
            [$status, $rows, $message === '' ? null : mb_substr($message, 0, 2000), $this->id]
        );
    }

    /** Wraps a job so a throw is recorded rather than lost to a cron black hole. */
    public static function run(Db $db, string $job, callable $fn): int
    {
        $logger = self::start($db, $job);
        try {
            $rows = (int) $fn($logger);
            $logger->succeed($rows);
            return $rows;
        } catch (\Throwable $e) {
            $logger->fail(get_class($e) . ': ' . $e->getMessage());
            throw $e;
        }
    }
}

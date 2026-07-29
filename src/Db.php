<?php
declare(strict_types=1);

namespace Tbw;

use PDO;
use PDOException;

final class Db
{
    private static ?self $instance = null;

    private function __construct(private PDO $pdo, public readonly string $database)
    {
    }

    public static function connect(?Config $config = null, ?string $database = null): self
    {
        $config ??= Config::load();
        $name = $database ?? $config->str('db.name', 'tbw_forecast');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->str('db.host', '127.0.0.1'),
            $config->int('db.port', 3306),
            $name,
            $config->str('db.charset', 'utf8mb4')
        );
        $pdo = new PDO($dsn, $config->str('db.user', 'root'), $config->str('db.pass', ''), self::options());
        return new self($pdo, $name);
    }

    /** Server-level connection with no database selected, for CREATE DATABASE. */
    public static function connectServer(?Config $config = null): PDO
    {
        $config ??= Config::load();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $config->str('db.host', '127.0.0.1'),
            $config->int('db.port', 3306),
            $config->str('db.charset', 'utf8mb4')
        );
        return new PDO($dsn, $config->str('db.user', 'root'), $config->str('db.pass', ''), self::options());
    }

    public static function instance(): self
    {
        return self::$instance ??= self::connect();
    }

    public static function setInstance(self $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Applies db/schema.sql. Idempotent — every statement is CREATE TABLE IF NOT EXISTS. */
    public function migrate(): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/db/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('cannot read db/schema.sql');
        }
        foreach (self::splitStatements($sql) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /**
     * @param array<int|string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $rows = $this->select($sql, $params);
        return $rows[0] ?? null;
    }

    /** @param array<int|string,mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @param array<int|string,mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Chunked multi-row upsert. Chunking matters: a 15-minute forecast writes 864 rows
     * and a single statement with 8k placeholders trips max_prepared_stmt_count.
     *
     * @param list<string>            $columns
     * @param list<array<int,mixed>>  $rows
     * @param list<string>            $updateColumns  columns refreshed on duplicate key
     */
    public function upsert(string $table, array $columns, array $rows, array $updateColumns, int $chunk = 200): int
    {
        if ($rows === []) {
            return 0;
        }
        $colSql = '`' . implode('`,`', $columns) . '`';
        $placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $updates = $updateColumns === []
            ? '`' . $columns[0] . '` = `' . $columns[0] . '`'
            : implode(',', array_map(static fn(string $c) => "`{$c}` = VALUES(`{$c}`)", $updateColumns));

        $affected = 0;
        foreach (array_chunk($rows, $chunk) as $batch) {
            $sql = "INSERT INTO `{$table}` ({$colSql}) VALUES "
                . implode(',', array_fill(0, count($batch), $placeholder))
                . " ON DUPLICATE KEY UPDATE {$updates}";
            $params = [];
            foreach ($batch as $row) {
                foreach ($row as $v) {
                    $params[] = $v;
                }
            }
            $affected += $this->execute($sql, $params);
        }
        return $affected;
    }

    public function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
    }

    /**
     * Splits on semicolons that are not inside a quoted string. A naive explode(';')
     * breaks any COMMENT 'text; more text', which is exactly how this first failed.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $sql = implode("\n", $lines);

        $out = [];
        $buffer = '';
        $quote = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buffer .= $sql[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $out[] = $statement;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        $tail = trim($buffer);
        if ($tail !== '') {
            $out[] = $tail;
        }
        return $out;
    }
}

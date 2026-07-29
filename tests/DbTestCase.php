<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use Tbw\Config;
use Tbw\Db;

/**
 * Base class for tests that need a real MariaDB. Uses a separate database
 * (db.test_db) so a botched test can never touch production rows.
 */
abstract class DbTestCase extends TestCase
{
    protected Db $db;

    public function setUp(): void
    {
        $config = Config::load();
        $name = $config->str('db.test_db', 'tbw_forecast_test');

        try {
            $server = Db::connectServer($config);
        } catch (\Throwable $e) {
            \skip('MariaDB unavailable: ' . $e->getMessage());
        }
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4");

        $this->db = Db::connect($config, $name);
        $this->db->migrate();
        $this->truncateAll();
    }

    protected function truncateAll(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tableNames() as $t) {
            $pdo->exec("TRUNCATE TABLE `{$t}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    protected function tableNames(): array
    {
        return $this->db->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    protected function count(string $table): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
}

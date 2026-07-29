<?php
declare(strict_types=1);

/** Creates the database if needed and applies db/schema.sql. Safe to re-run. */

$config = require __DIR__ . '/_bootstrap.php';

use Tbw\Db;

$name = $config->str('db.name', 'tbw_forecast');

$server = Db::connectServer($config);
$server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
say("database `{$name}` ready");

$db = Db::connect($config, $name);
$db->migrate();

$tables = $db->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
say('migrated ' . count($tables) . ' tables: ' . implode(', ', $tables));

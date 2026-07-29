<?php
declare(strict_types=1);

/** Shared web bootstrap. */

require dirname(__DIR__) . '/src/autoload.php';

use Tbw\Config;
use Tbw\Db;

$config = Config::load();
date_default_timezone_set($config->str('app.timezone', 'Asia/Jakarta'));
mb_internal_encoding('UTF-8');

// Display errors on a plant LAN tool is the right trade: a blank white page tells an
// operator nothing, and this system is never exposed to the internet.
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    $db = Db::connect($config);
} catch (\Throwable $e) {
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'database unavailable: ' . $e->getMessage()]);
        exit;
    }
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Database tidak tersedia</title>';
    echo '<div style="font:16px system-ui;padding:2rem;max-width:44rem;margin:auto">';
    echo '<h1>Database tidak tersedia</h1>';
    echo '<p>Pastikan MySQL di XAMPP Control Panel sudah <strong>Start</strong>, lalu jalankan:</p>';
    echo '<pre style="background:#f4f4f5;padding:1rem;border-radius:6px">php bin/migrate.php</pre>';
    echo '<p style="color:#71717a">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
    echo '</div>';
    exit;
}

return [$config, $db];

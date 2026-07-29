<?php
declare(strict_types=1);

/**
 * Shared CLI bootstrap. Every bin/ script starts with this.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/src/autoload.php';

use Tbw\Config;

$config = Config::load();
date_default_timezone_set($config->str('app.timezone', 'Asia/Jakarta'));

mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/** Prefixed, timestamped line on stdout. Cron output is the only log some sites keep. */
function say(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
}

function warn(string $message): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] WARN ' . $message . "\n");
}

/** @return array<string,string> */
function cli_options(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = '1';
        }
    }
    return $out;
}

return $config;

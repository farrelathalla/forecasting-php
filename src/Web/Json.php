<?php
declare(strict_types=1);

namespace Tbw\Web;

/** JSON endpoint helper: correct headers, and errors that stay JSON. */
final class Json
{
    public static function send(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        exit;
    }

    public static function error(string $message, int $status = 500): never
    {
        self::send(['error' => $message], $status);
    }

    /**
     * Wraps an endpoint so a thrown exception becomes a JSON error rather than an HTML
     * fatal page inside a fetch() the browser cannot parse.
     */
    public static function endpoint(callable $fn): never
    {
        try {
            self::send($fn());
        } catch (\Throwable $e) {
            self::error(get_class($e) . ': ' . $e->getMessage(), 500);
        }
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : $default;
    }

    public static function intQuery(string $key, int $default): int
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) && is_numeric($v) ? (int) $v : $default;
    }
}

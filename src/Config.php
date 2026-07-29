<?php
declare(strict_types=1);

namespace Tbw;

/**
 * Dot-notation config with environment override.
 *
 * Precedence: environment variable > .env file > config/config.php.
 * Env keys are the dotted path upper-cased with TBW_ prefix: db.host -> TBW_DB_HOST.
 */
final class Config
{
    private static ?self $instance = null;

    /** @param array<string,mixed> $data */
    private function __construct(private array $data)
    {
    }

    /** @param array<string,mixed> $data @param array<string,string> $env */
    public static function fromArray(array $data, array $env = []): self
    {
        $config = new self($data);
        $config->applyEnv($env);
        return $config;
    }

    public static function load(bool $fresh = false): self
    {
        if (self::$instance !== null && !$fresh) {
            return self::$instance;
        }
        $root = dirname(__DIR__);
        $data = require $root . '/config/config.php';
        if (!is_array($data)) {
            throw new \RuntimeException('config/config.php must return an array');
        }

        $env = self::readDotEnv($root . '/.env');
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'TBW_')) {
                $env[$k] = (string) $v;
            }
        }
        foreach (self::flattenKeys($data) as $path) {
            $name = 'TBW_' . strtoupper(str_replace('.', '_', $path));
            $fromEnv = getenv($name);
            if ($fromEnv !== false) {
                $env[$name] = $fromEnv;
            }
        }

        self::$instance = self::fromArray($data, $env);
        return self::$instance;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public function int(string $path, int $default = 0): int
    {
        $v = $this->get($path, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $path, float $default = 0.0): float
    {
        $v = $this->get($path, $default);
        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $path, bool $default = false): bool
    {
        $v = $this->get($path, $default);
        return is_bool($v) ? $v : $default;
    }

    public function str(string $path, string $default = ''): string
    {
        $v = $this->get($path, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** @param array<string,string> $env */
    private function applyEnv(array $env): void
    {
        foreach (self::flattenKeys($this->data) as $path) {
            $name = 'TBW_' . strtoupper(str_replace('.', '_', $path));
            if (!array_key_exists($name, $env)) {
                continue;
            }
            $this->set($path, self::cast($env[$name]));
        }
    }

    private function set(string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $node =& $this->data;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $node[$segment] = $value;
                return;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node =& $node[$segment];
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private static function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $k => $v) {
            $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v) && $v !== [] && !array_is_list($v)) {
                $keys = array_merge($keys, self::flattenKeys($v, $path));
            } else {
                $keys[] = $path;
            }
        }
        return $keys;
    }

    private static function cast(string $raw): mixed
    {
        $lower = strtolower(trim($raw));
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null' || $lower === '') {
            return $raw === '' ? '' : null;
        }
        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        return $raw;
    }

    /** @return array<string,string> */
    private static function readDotEnv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v, " \t\"'");
        }
        return $out;
    }
}

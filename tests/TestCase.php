<?php
declare(strict_types=1);

namespace Tests;

/**
 * Minimal xUnit-style base class. No Composer, no PHPUnit: XAMPP ships neither and
 * every extra install step is a reason the plant team never runs the tests.
 */
abstract class TestCase
{
    /** @var list<array{name:string,message:string}> */
    public array $failures = [];
    public int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function fail(string $message): void
    {
        throw new AssertionFailed($message);
    }

    protected function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual !== true) {
            $this->fail($message ?: 'Expected true, got ' . self::dump($actual));
        }
    }

    protected function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual !== false) {
            $this->fail($message ?: 'Expected false, got ' . self::dump($actual));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail($message ?: 'Expected ' . self::dump($expected) . ', got ' . self::dump($actual));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected != $actual) {
            $this->fail($message ?: 'Expected ' . self::dump($expected) . ', got ' . self::dump($actual));
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual !== null) {
            $this->fail($message ?: 'Expected null, got ' . self::dump($actual));
        }
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual === null) {
            $this->fail($message ?: 'Expected not null');
        }
    }

    protected function assertCount(int $expected, array|\Countable $actual, string $message = ''): void
    {
        $this->assertions++;
        $n = count($actual);
        if ($n !== $expected) {
            $this->fail($message ?: "Expected count {$expected}, got {$n}");
        }
    }

    protected function assertFloatEquals(float $expected, mixed $actual, float $eps = 1e-9, string $message = ''): void
    {
        $this->assertions++;
        if (!is_float($actual) && !is_int($actual)) {
            $this->fail($message ?: 'Expected numeric, got ' . self::dump($actual));
        }
        if (is_nan((float) $actual) || abs((float) $actual - $expected) > $eps) {
            $this->fail($message ?: "Expected {$expected} (+/- {$eps}), got " . self::dump($actual));
        }
    }

    protected function assertGreaterThan(float $bound, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if (!((float) $actual > $bound)) {
            $this->fail($message ?: 'Expected > ' . $bound . ', got ' . self::dump($actual));
        }
    }

    protected function assertLessThan(float $bound, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if (!((float) $actual < $bound)) {
            $this->fail($message ?: 'Expected < ' . $bound . ', got ' . self::dump($actual));
        }
    }

    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!in_array($needle, $haystack, true)) {
            $this->fail($message ?: self::dump($needle) . ' not found in ' . self::dump($haystack));
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            $this->fail($message ?: "'{$needle}' not found in '{$haystack}'");
        }
    }

    protected function assertThrows(string $exceptionClass, callable $fn, string $message = ''): \Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!($e instanceof $exceptionClass)) {
                $this->fail($message ?: 'Expected ' . $exceptionClass . ', got ' . get_class($e) . ': ' . $e->getMessage());
            }
            return $e;
        }
        $this->fail($message ?: 'Expected ' . $exceptionClass . ' to be thrown, nothing was');
    }

    private static function dump(mixed $v): string
    {
        if (is_array($v)) {
            return json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'array';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_float($v)) {
            return var_export($v, true);
        }
        return is_scalar($v) ? (string) $v : get_debug_type($v);
    }
}

class AssertionFailed extends \RuntimeException
{
}

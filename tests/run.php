<?php
declare(strict_types=1);

/**
 * Test runner.  php tests/run.php [NameFilter] [--integration]
 *
 * Discovers tests/**\/*Test.php, instantiates each class, runs every public test* method.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/TestCase.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Tests\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Tests\AssertionFailed;
use Tests\TestCase;

$filter      = null;
$integration = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--integration') {
        $integration = true;
    } elseif (!str_starts_with($arg, '--')) {
        $filter = $arg;
    }
}
putenv('TBW_INTEGRATION=' . ($integration ? '1' : '0'));
putenv('TBW_TESTING=1');

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);

$classesBefore = get_declared_classes();
foreach ($files as $f) {
    require_once $f;
}
$testClasses = array_values(array_filter(
    array_diff(get_declared_classes(), $classesBefore),
    static fn(string $c) => is_subclass_of($c, TestCase::class)
));
sort($testClasses);

$passed = 0;
$failed = 0;
$skipped = 0;
$assertions = 0;
$failures = [];
$t0 = microtime(true);

$green = static fn(string $s) => "\033[32m{$s}\033[0m";
$red   = static fn(string $s) => "\033[31m{$s}\033[0m";
$yellow = static fn(string $s) => "\033[33m{$s}\033[0m";
$dim   = static fn(string $s) => "\033[2m{$s}\033[0m";

foreach ($testClasses as $class) {
    $short = (new ReflectionClass($class))->getShortName();
    if ($filter !== null && stripos($short, $filter) === false) {
        continue;
    }
    $methods = array_filter(
        get_class_methods($class),
        static fn(string $m) => str_starts_with($m, 'test')
    );
    sort($methods);
    if ($methods === []) {
        continue;
    }

    echo $dim($short) . "\n";
    foreach ($methods as $method) {
        /** @var TestCase $instance */
        $instance = new $class();
        $label = self_label($method);
        try {
            $instance->setUp();
            $instance->$method();
            $instance->tearDown();
            $assertions += $instance->assertions;
            $passed++;
            echo '  ' . $green('PASS') . " {$label}\n";
        } catch (SkippedTest $e) {
            try { $instance->tearDown(); } catch (Throwable) {}
            $skipped++;
            echo '  ' . $yellow('SKIP') . " {$label} " . $dim('(' . $e->getMessage() . ')') . "\n";
        } catch (AssertionFailed $e) {
            try { $instance->tearDown(); } catch (Throwable) {}
            $assertions += $instance->assertions;
            $failed++;
            $failures[] = "{$short}::{$method} — " . $e->getMessage();
            echo '  ' . $red('FAIL') . " {$label}\n        " . $red($e->getMessage()) . "\n";
        } catch (Throwable $e) {
            try { $instance->tearDown(); } catch (Throwable) {}
            $assertions += $instance->assertions;
            $failed++;
            $where = basename($e->getFile()) . ':' . $e->getLine();
            $failures[] = "{$short}::{$method} — " . get_class($e) . ': ' . $e->getMessage() . " ({$where})";
            echo '  ' . $red('ERR ') . " {$label}\n        " . $red(get_class($e) . ': ' . $e->getMessage()) . "\n        " . $dim($where) . "\n";
        }
    }
}

$elapsed = number_format(microtime(true) - $t0, 2);
echo "\n";
if ($failed > 0) {
    echo $red("FAILED") . ": {$failed} failed, {$passed} passed, {$skipped} skipped, {$assertions} assertions, {$elapsed}s\n\n";
    foreach ($failures as $f) {
        echo '  - ' . $f . "\n";
    }
    exit(1);
}
echo $green("OK") . ": {$passed} passed, {$skipped} skipped, {$assertions} assertions, {$elapsed}s\n";
exit(0);

function self_label(string $method): string
{
    $s = preg_replace('/^test/', '', $method) ?? $method;
    $s = preg_replace('/(?<!^)[A-Z]/', ' $0', $s) ?? $s;
    return strtolower(trim($s));
}

class SkippedTest extends RuntimeException
{
}

function skip(string $reason): never
{
    throw new SkippedTest($reason);
}

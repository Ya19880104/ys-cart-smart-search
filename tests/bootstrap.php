<?php
declare(strict_types=1);

$ysss_test_root = dirname(__DIR__);
if (!defined('ABSPATH')) {
    define('ABSPATH', $ysss_test_root . DIRECTORY_SEPARATOR);
}

final class YSSsTestState
{
    public static int $pass = 0;
    public static int $fail = 0;

    /** @var list<string> */
    public static array $failures = [];
}

function ysss_test(string $label, callable $test): void
{
    try {
        $test();
        ++YSSsTestState::$pass;
        echo "[PASS] {$label}\n";
    } catch (Throwable $error) {
        ++YSSsTestState::$fail;
        $message = "{$label}: {$error->getMessage()}";
        YSSsTestState::$failures[] = $message;
        echo "[FAIL] {$message}\n";
    }
}

function ysss_assert_true(bool $actual, string $message = 'Expected true'): void
{
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function ysss_assert_false(bool $actual, string $message = 'Expected false'): void
{
    if ($actual) {
        throw new RuntimeException($message);
    }
}

function ysss_assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        );
        throw new RuntimeException('' === $message ? $detail : "{$message}: {$detail}");
    }
}

function ysss_assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        $detail = "Expected output to contain {$needle}";
        throw new RuntimeException('' === $message ? $detail : "{$message}: {$detail}");
    }
}

function ysss_source_path(string $relative): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
}

function ysss_require_source(string $relative): void
{
    $path = ysss_source_path($relative);
    if (!is_file($path)) {
        throw new RuntimeException("Missing production source: {$relative}");
    }
    require_once $path;
}

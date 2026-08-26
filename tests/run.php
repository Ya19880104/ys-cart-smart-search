<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$behaviorFiles = glob(__DIR__ . '/behavior/*.php');
if (false === $behaviorFiles) {
    $behaviorFiles = [];
}
sort($behaviorFiles, SORT_STRING);

$jsFiles = glob(__DIR__ . '/js/*.js');
if (false === $jsFiles) {
    $jsFiles = [];
}
sort($jsFiles, SORT_STRING);

$all = [
    'static/smart-search-contract.php' => __DIR__ . '/static/smart-search-contract.php',
];
foreach ($behaviorFiles as $file) {
    $all['behavior/' . basename($file)] = $file;
}
foreach ($jsFiles as $file) {
    $all['js/' . basename($file)] = $file;
}

$requested = array_slice($argv ?? [], 1);
if ($requested) {
    if (count($requested) !== count(array_unique($requested))) {
        fwrite(STDERR, "Duplicate test selector.\n");
        exit(1);
    }
    foreach ($requested as $selector) {
        if (!is_string($selector) || !isset($all[$selector])) {
            fwrite(STDERR, "Unknown test selector: " . (string) $selector . "\n");
            exit(1);
        }
    }
    $selected = $requested;
} else {
    $selected = array_keys($all);
}

if ([] === $requested && [] === $behaviorFiles) {
    ++YSSsTestState::$fail;
    $message = 'behavior suite: no behavior test files found';
    YSSsTestState::$failures[] = $message;
    echo "[FAIL] {$message}\n";
}

foreach ($selected as $selector) {
    $file = $all[$selector];
    if ('static/' === substr($selector, 0, 7)) {
        ysss_test($selector, static function () use ($file): void {
            require $file;
        });
        continue;
    }
    if ('behavior/' === substr($selector, 0, 9)) {
        $before = YSSsTestState::$pass + YSSsTestState::$fail;
        try {
            require $file;
        } catch (Throwable $error) {
            ++YSSsTestState::$fail;
            $message = 'load ' . $selector . ': ' . $error->getMessage();
            YSSsTestState::$failures[] = $message;
            echo "[FAIL] {$message}\n";
        }

        if ($before === YSSsTestState::$pass + YSSsTestState::$fail) {
            ++YSSsTestState::$fail;
            $message = "{$selector}: suite registered no tests";
            YSSsTestState::$failures[] = $message;
            echo "[FAIL] {$message}\n";
        }
        continue;
    }

    ysss_test($selector, static function () use ($file): void {
        if (!function_exists('exec')) {
            throw new RuntimeException('PHP exec() is unavailable for Node behavior tests');
        }
        $output = [];
        $status = 0;
        exec('node ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        if (0 !== $status) {
            throw new RuntimeException(implode("\n", $output));
        }
    });
}

echo sprintf(
    "\nYS Smart Search test summary: PASS=%d FAIL=%d\n",
    YSSsTestState::$pass,
    YSSsTestState::$fail
);

if (YSSsTestState::$fail > 0) {
    exit(1);
}

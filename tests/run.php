<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

ysss_test('static/smart-search-contract.php', static function (): void {
    require __DIR__ . '/static/smart-search-contract.php';
});

$behaviorFiles = glob(__DIR__ . '/behavior/*.php');
if (false === $behaviorFiles) {
    $behaviorFiles = [];
}
sort($behaviorFiles, SORT_STRING);

if ([] === $behaviorFiles) {
    ++YSSsTestState::$fail;
    $message = 'behavior suite: no behavior test files found';
    YSSsTestState::$failures[] = $message;
    echo "[FAIL] {$message}\n";
}

foreach ($behaviorFiles as $behaviorFile) {
    $before = YSSsTestState::$pass + YSSsTestState::$fail;
    try {
        require $behaviorFile;
    } catch (Throwable $error) {
        ++YSSsTestState::$fail;
        $label = 'load ' . str_replace('\\', '/', substr($behaviorFile, strlen(__DIR__) + 1));
        $message = "{$label}: {$error->getMessage()}";
        YSSsTestState::$failures[] = $message;
        echo "[FAIL] {$message}\n";
    }

    if ($before === YSSsTestState::$pass + YSSsTestState::$fail) {
        ++YSSsTestState::$fail;
        $label = str_replace('\\', '/', substr($behaviorFile, strlen(__DIR__) + 1));
        $message = "{$label}: suite registered no tests";
        YSSsTestState::$failures[] = $message;
        echo "[FAIL] {$message}\n";
    }
}

echo sprintf(
    "\nYS Smart Search test summary: PASS=%d FAIL=%d\n",
    YSSsTestState::$pass,
    YSSsTestState::$fail
);

if (YSSsTestState::$fail > 0) {
    exit(1);
}

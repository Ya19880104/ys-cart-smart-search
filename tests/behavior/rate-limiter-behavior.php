<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Api\YSSsPublicController;
use YangSheep\SmartSearch\Security\YSSsLogReceipt;
use YangSheep\SmartSearch\Security\YSSsRateLimiter;

foreach ([
    'src/Support/YSSsText.php',
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Security/YSSsRateLimiter.php',
    'src/Security/YSSsLogReceipt.php',
    'src/Analytics/YSSsAnalyticsAdmission.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/Api/YSSsPublicController.php',
] as $source) {
    ysss_require_source($source);
}

ysss_test('rate decision is one acquire-read-write-release critical section', static function (): void {
    YSSsWpFake::reset();
    $events = [];
    $GLOBALS['wpdb']->getVarHandler = static function (string $sql) use (&$events): int {
        if (str_contains($sql, 'GET_LOCK')) {
            $events[] = 'acquire';
            return 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $events[] = 'release';
            return 1;
        }
        return 0;
    };
    YSSsWpFake::$getTransientHandler = static function (string $key) use (&$events): mixed {
        $events[] = 'read';
        return false;
    };
    YSSsWpFake::$setTransientHandler = static function (string $key, mixed $value, int $expiration) use (&$events): bool {
        $events[] = 'write';
        YSSsWpFake::$transients[$key] = $value;
        return true;
    };

    ysss_assert_true(YSSsRateLimiter::allow('log', 30));
    ysss_assert_same(['acquire', 'read', 'write', 'release'], $events);
    $acquire = array_values(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'GET_LOCK')
    ));
    $release = array_values(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    ));
    ysss_assert_same(1, count($acquire));
    ysss_assert_same(1, count($release));
    preg_match("/GET_LOCK\\('([^']+)'/", $acquire[0], $acquireMatch);
    preg_match("/RELEASE_LOCK\\('([^']+)'/", $release[0], $releaseMatch);
    ysss_assert_same($acquireMatch[1] ?? null, $releaseMatch[1] ?? null, 'Rate limiter released a different authority lock');
    ysss_assert_true(strlen((string) ($acquireMatch[1] ?? '')) <= 64, 'MySQL advisory lock name exceeds 64 bytes');
});

ysss_test('contended rate authority denies before transient access', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->getVarHandler = static fn(string $sql): int => str_contains($sql, 'GET_LOCK') ? 0 : 1;

    ysss_assert_false(YSSsRateLimiter::allow('query', 60));
    ysss_assert_same([], YSSsWpFake::$transientGets, 'Lock loser still read a stale counter');
    ysss_assert_same([], YSSsWpFake::$transientSets, 'Lock loser still wrote a counter');
    ysss_assert_same(0, count(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    )), 'Lock loser attempted to release a lock it never owned');
});

ysss_test('rate authority database failure denies without transient work', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->getVarHandler = static function (string $sql): int {
        if (str_contains($sql, 'GET_LOCK')) {
            throw new RuntimeException('fixture authority failure');
        }
        return 1;
    };

    ysss_assert_false(YSSsRateLimiter::allow('suggest', 60));
    ysss_assert_same([], YSSsWpFake::$transientGets);
    ysss_assert_same([], YSSsWpFake::$transientSets);
});

ysss_test('counter write failure denies and still releases the authority lock', static function (): void {
    YSSsWpFake::reset();
    YSSsWpFake::$setTransientHandler = static fn(string $key, mixed $value, int $expiration): bool => false;

    ysss_assert_false(YSSsRateLimiter::allow('log', 30));
    ysss_assert_same(1, count(YSSsWpFake::$transientSets));
    ysss_assert_same(1, count(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    )), 'Counter write failure leaked the rate authority lock');
});

ysss_test('counter read or write uncertainty fails closed and releases an acquired lock', static function (): void {
    $cases = [
        'read-throw' => static function (): void {
            YSSsWpFake::$getTransientHandler = static function (string $key): mixed {
                throw new RuntimeException('fixture transient read failure');
            };
        },
        'read-malformed' => static function (): void {
            YSSsWpFake::$getTransientHandler = static fn(string $key): array => ['not-a-counter'];
        },
        'write-throw' => static function (): void {
            YSSsWpFake::$setTransientHandler = static function (string $key, mixed $value, int $expiration): bool {
                throw new RuntimeException('fixture transient write failure');
            };
        },
    ];

    foreach ($cases as $label => $configure) {
        YSSsWpFake::reset();
        $configure();
        ysss_assert_false(YSSsRateLimiter::allow('log', 30), "{$label} was admitted");
        ysss_assert_same(1, count(array_filter(
            $GLOBALS['wpdb']->queries,
            static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
        )), "{$label} leaked the acquired authority lock");
    }
});

ysss_test('counter parser accepts only bounded canonical nonnegative integers', static function (): void {
    YSSsWpFake::reset();
    YSSsWpFake::$getTransientHandler = static fn(string $key): string => '12';
    ysss_assert_true(YSSsRateLimiter::allow('log', 30));
    ysss_assert_same(13, YSSsWpFake::$transientSets[0]['value'] ?? null);

    foreach ([-1, 1.5, [], '01', '+1', (string) PHP_INT_MAX . '0'] as $malformed) {
        YSSsWpFake::reset();
        YSSsWpFake::$getTransientHandler = static fn(string $key): mixed => $malformed;
        ysss_assert_false(YSSsRateLimiter::allow('log', 30), 'Malformed counter was admitted');
        ysss_assert_same([], YSSsWpFake::$transientSets, 'Malformed counter was rewritten');
    }

    YSSsWpFake::reset();
    YSSsWpFake::$getTransientHandler = static fn(string $key): int => PHP_INT_MAX - 1;
    ysss_assert_true(YSSsRateLimiter::allow('log', PHP_INT_MAX));
    ysss_assert_same(PHP_INT_MAX, YSSsWpFake::$transientSets[0]['value'] ?? null);

    YSSsWpFake::reset();
    YSSsWpFake::$getTransientHandler = static fn(string $key): string => (string) PHP_INT_MAX;
    ysss_assert_false(YSSsRateLimiter::allow('log', PHP_INT_MAX));
    ysss_assert_same([], YSSsWpFake::$transientSets, 'At-limit counter overflowed');
});

ysss_test('uncertain rate authority release denies the current request', static function (): void {
    foreach ([0, null, false, 'throw'] as $releaseResult) {
        YSSsWpFake::reset();
        $GLOBALS['wpdb']->getVarHandler = static function (string $sql) use ($releaseResult): mixed {
            if (str_contains($sql, 'GET_LOCK')) {
                return 1;
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                if ('throw' === $releaseResult) {
                    throw new RuntimeException('fixture release failure');
                }
                return $releaseResult;
            }
            return 0;
        };

        ysss_assert_false(YSSsRateLimiter::allow('query', 60), 'Uncertain lock release admitted the request');
        ysss_assert_same(1, count(YSSsWpFake::$transientSets), 'Fixture did not reach the counted decision');
    }
});

ysss_test('interleaved contenders cannot both consume the same stale counter state', static function (): void {
    YSSsWpFake::reset();
    /** @var YSSsFakeWpdb $firstDb */
    $firstDb = $GLOBALS['wpdb'];
    $secondDb = new YSSsFakeWpdb();
    $owner = null;
    $nested = null;
    $insideRead = false;

    $configureSession = static function (YSSsFakeWpdb $db, string $session) use (&$owner): void {
        $db->getVarHandler = static function (string $sql) use (&$owner, $session): int {
            if (str_contains($sql, 'GET_LOCK')) {
                if (null === $owner) {
                    $owner = $session;
                    return 1;
                }
                // MySQL permits same-session reentry, but a distinct session cannot acquire it.
                return $owner === $session ? 1 : 0;
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                if ($owner !== $session) {
                    return 0;
                }
                $owner = null;
                return 1;
            }
            return 0;
        };
    };
    $configureSession($firstDb, 'first');
    $configureSession($secondDb, 'second');

    YSSsWpFake::$getTransientHandler = static function (string $key) use (&$nested, &$insideRead, $firstDb, $secondDb): mixed {
        if (!$insideRead) {
            $insideRead = true;
            $GLOBALS['wpdb'] = $secondDb;
            try {
                $nested = YSSsRateLimiter::allow('log', 1);
            } finally {
                $GLOBALS['wpdb'] = $firstDb;
                $insideRead = false;
            }
        }
        return false;
    };

    $outer = YSSsRateLimiter::allow('log', 1);

    ysss_assert_true($outer, 'The lock owner was not admitted');
    ysss_assert_false((bool) $nested, 'Interleaved contender consumed the same old counter');
    ysss_assert_same(1, count(YSSsWpFake::$transientGets), 'Lock loser reached a stale transient read');
    ysss_assert_same(1, count(YSSsWpFake::$transientSets), 'Both stale readers wrote the same counter state');
    ysss_assert_same(2, count(array_filter(
        array_merge($firstDb->queries, $secondDb->queries),
        static fn(string $sql): bool => str_contains($sql, 'GET_LOCK')
    )), 'Forced interleaving did not attempt two serialized decisions');
    ysss_assert_same(1, count(array_filter(
        array_merge($firstDb->queries, $secondDb->queries),
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    )), 'Forced interleaving did not release exactly the owned lock');
    ysss_assert_same(null, $owner, 'Serialized fixture finished with the lock held');
});

ysss_test('query and suggest return 429 before content work when rate authority is busy', static function (): void {
    foreach (['query', 'suggest'] as $method) {
        YSSsWpFake::reset();
        $GLOBALS['wpdb']->getVarHandler = static fn(string $sql): int => str_contains($sql, 'GET_LOCK') ? 0 : 1;
        $controller = new YSSsPublicController();
        $response = 'query' === $method
            ? $controller->query(new WP_REST_Request(['q' => 'nova']))
            : $controller->suggest(new WP_REST_Request());

        ysss_assert_true($response instanceof WP_Error, "{$method} did not fail closed");
        ysss_assert_same('ys_ss_rate_limited', $response->get_error_code());
        ysss_assert_same(429, $response->get_error_data()['status'] ?? null);
        ysss_assert_same([], $GLOBALS['wpdb']->inserts, "{$method} reached a write path");
        ysss_assert_false((bool) array_filter(
            $GLOBALS['wpdb']->queries,
            static fn(string $sql): bool => str_contains($sql, 'ys_ec_products') || str_contains($sql, 'ys_ss_terms_daily')
        ), "{$method} reached content or suggestion SQL");
    }
});

ysss_test('public log performs zero analytics work when counter storage fails', static function (): void {
    YSSsWpFake::reset();
    $visitor = YSSsRateLimiter::visitor_hash();
    $receipt = YSSsLogReceipt::issue('nova', 1, 'products', $visitor);
    YSSsWpFake::$setTransientHandler = static fn(string $key, mixed $value, int $expiration): bool => false;

    $response = (new YSSsPublicController())->log(new WP_REST_Request([
        'q' => 'nova',
        'receipt' => $receipt,
        'source' => 'bar',
    ]));

    ysss_assert_true($response instanceof WP_Error);
    ysss_assert_same('ys_ss_rate_limited', $response->get_error_code());
    ysss_assert_same(429, $response->get_error_data()['status'] ?? null);
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Fail-open rate storage reached analytics insert');
});

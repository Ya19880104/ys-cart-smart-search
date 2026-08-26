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

function ysss_rate_option_name(string $action, string $ip = '192.0.2.10'): string
{
    $packed = inet_pton($ip);
    $mappedPrefix = str_repeat("\0", 10) . "\xff\xff";
    if (false !== $packed && 16 === strlen($packed) && 0 === strncmp($packed, $mappedPrefix, 12)) {
        $material = substr($packed, 12, 4);
    } elseif (false !== $packed && 16 === strlen($packed)) {
        $material = substr($packed, 0, 8) . str_repeat("\0", 8);
    } elseif (false !== $packed && 4 === strlen($packed)) {
        $material = $packed;
    } else {
        $material = $ip;
    }
    return 'ys_ss_rate_v1_' . sanitize_key($action) . '_'
        . substr(hash_hmac('sha256', $material, wp_salt('nonce')), 0, 24);
}

/** @return array{option_name:string,option_value:string} */
function ysss_rate_row(string $name, string $value): array
{
    return ['option_name' => $name, 'option_value' => $value];
}

/** @return array{name:string,value:string} */
function ysss_rate_upsert_values(string $sql): array
{
    preg_match(
        "/VALUES\\s*\\(\\s*'([^']+)'\\s*,\\s*'(v1:[0-9]+:[0-9]+)'\\s*,\\s*'no'\\s*\\)/s",
        $sql,
        $match
    );
    return ['name' => (string) ($match[1] ?? ''), 'value' => (string) ($match[2] ?? '')];
}

ysss_test('durable rate state cannot be reset by a transient-layer miss', static function (): void {
    YSSsWpFake::reset();
    $name = ysss_rate_option_name('log');
    $value = 'v1:' . (time() + MINUTE_IN_SECONDS) . ':30';
    YSSsWpFake::$options[$name] = $value;
    YSSsWpFake::$getTransientHandler = static fn(string $key): mixed => false;

    ysss_assert_false(YSSsRateLimiter::allow('log', 30), 'A transient miss reset a durable full counter');
    ysss_assert_same($value, YSSsWpFake::$options[$name] ?? null, 'At-limit durable state was overwritten');
    ysss_assert_same([], YSSsWpFake::$transientGets, 'The transient layer remained a rate authority');
    ysss_assert_same([], YSSsWpFake::$transientSets, 'A transient miss rewrote the protected budget');
    ysss_assert_same(0, count(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'INSERT INTO `wp_options`')
    )), 'At-limit request still wrote a state transition');
});

ysss_test('durable rate read failure is not treated as a genuinely new key', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->rateGetResultsHandler = static function (
        string $sql,
        mixed $output,
        YSSsFakeWpdb $db
    ): array {
        $db->last_error = 'fixture durable read failure';
        return [];
    };

    ysss_assert_false(YSSsRateLimiter::allow('query', 60), 'A failed durable read was admitted as a first request');
    ysss_assert_same([], YSSsWpFake::$transientGets);
    ysss_assert_same([], YSSsWpFake::$transientSets);
    ysss_assert_same(1, count(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    )), 'Durable read failure leaked the acquired lock');
});

ysss_test('rate decision is one acquire-read-upsert-readback-release critical section', static function (): void {
    YSSsWpFake::reset();
    $events = [];
    $readCount = 0;
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
    $GLOBALS['wpdb']->rateGetResultsHandler = static function (string $sql) use (&$events, &$readCount): array {
        ++$readCount;
        $events[] = 1 === $readCount ? 'read' : 'readback';
        preg_match("/option_name\\s*=\\s*'([^']+)'/", $sql, $match);
        $name = (string) ($match[1] ?? '');
        return array_key_exists($name, YSSsWpFake::$options)
            ? [ysss_rate_row($name, (string) YSSsWpFake::$options[$name])]
            : [];
    };
    $GLOBALS['wpdb']->rateQueryHandler = static function (string $sql) use (&$events): int {
        $events[] = 'write';
        $upsert = ysss_rate_upsert_values($sql);
        YSSsWpFake::$options[$upsert['name']] = $upsert['value'];
        return 1;
    };

    ysss_assert_true(YSSsRateLimiter::allow('log', 30));
    ysss_assert_same(['acquire', 'read', 'write', 'readback', 'release'], $events);
    ysss_assert_same([], YSSsWpFake::$transientGets);
    ysss_assert_same([], YSSsWpFake::$transientSets);
    $acquire = array_values(array_filter($GLOBALS['wpdb']->queries, static fn(string $sql): bool => str_contains($sql, 'GET_LOCK')));
    $release = array_values(array_filter($GLOBALS['wpdb']->queries, static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')));
    preg_match("/GET_LOCK\\('([^']+)'/", $acquire[0] ?? '', $acquireMatch);
    preg_match("/RELEASE_LOCK\\('([^']+)'/", $release[0] ?? '', $releaseMatch);
    ysss_assert_same($acquireMatch[1] ?? null, $releaseMatch[1] ?? null, 'Released a different authority lock');
    ysss_assert_true(strlen((string) ($acquireMatch[1] ?? '')) <= 64, 'MySQL lock name exceeds 64 bytes');
});

ysss_test('contended rate authority denies before durable or transient access', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->getVarHandler = static fn(string $sql): int => str_contains($sql, 'GET_LOCK') ? 0 : 1;

    ysss_assert_false(YSSsRateLimiter::allow('query', 60));
    ysss_assert_same([], YSSsWpFake::$transientGets);
    ysss_assert_same([], YSSsWpFake::$transientSets);
    ysss_assert_same(0, count(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'SELECT option_name, option_value')
            || str_contains($sql, 'INSERT INTO `wp_options`')
    )), 'Lock loser reached durable state');
    ysss_assert_same(0, count(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    )), 'Lock loser released a lock it never owned');
});

ysss_test('rate authority lock query failure denies without state work', static function (): void {
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
    ysss_assert_same(0, count(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'SELECT option_name, option_value')
    )));
});

ysss_test('durable write failure denies and still releases the authority lock', static function (): void {
    foreach (['false', 'throw'] as $mode) {
        YSSsWpFake::reset();
        $GLOBALS['wpdb']->rateQueryHandler = static function (string $sql) use ($mode): int|false {
            if ('throw' === $mode) {
                throw new RuntimeException('fixture durable write failure');
            }
            return false;
        };

        ysss_assert_false(YSSsRateLimiter::allow('log', 30), "{$mode} durable write was admitted");
        ysss_assert_same(1, count(array_filter(
            $GLOBALS['wpdb']->queries,
            static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
        )), "{$mode} durable write failure leaked the lock");
    }
});

ysss_test('malformed duplicate unexpected and throwing durable reads fail closed', static function (): void {
    $future = time() + MINUTE_IN_SECONDS;
    foreach (['malformed', 'duplicate', 'unexpected', 'throw'] as $mode) {
        YSSsWpFake::reset();
        $name = ysss_rate_option_name('log');
        $GLOBALS['wpdb']->rateGetResultsHandler = static function (string $sql) use ($mode, $name, $future): array {
            if ('throw' === $mode) {
                throw new RuntimeException('fixture durable read throw');
            }
            if ('duplicate' === $mode) {
                return [ysss_rate_row($name, "v1:{$future}:1"), ysss_rate_row($name, "v1:{$future}:1")];
            }
            if ('unexpected' === $mode) {
                return [ysss_rate_row($name . '_other', "v1:{$future}:1")];
            }
            return [ysss_rate_row($name, "v1:{$future}:01")];
        };

        ysss_assert_false(YSSsRateLimiter::allow('log', 30), "{$mode} durable state was admitted");
        ysss_assert_same(0, count(array_filter(
            $GLOBALS['wpdb']->queries,
            static fn(string $sql): bool => str_contains($sql, 'INSERT INTO `wp_options`')
        )), "{$mode} durable state was rewritten");
    }
});

ysss_test('counter parser is canonical bounded and preserves a fixed first-request window', static function (): void {
    YSSsWpFake::reset();
    $name = ysss_rate_option_name('log');
    $expiry = time() + MINUTE_IN_SECONDS;
    YSSsWpFake::$options[$name] = "v1:{$expiry}:12";
    ysss_assert_true(YSSsRateLimiter::allow('log', 30));
    ysss_assert_same("v1:{$expiry}:13", YSSsWpFake::$options[$name] ?? null, 'Successful increment moved the fixed expiry');

    foreach (['v1:' . $expiry . ':0', 'v1:' . $expiry . ':01', 'v1:+1:1', 'v2:' . $expiry . ':1', 'array'] as $bad) {
        YSSsWpFake::reset();
        $name = ysss_rate_option_name('log');
        YSSsWpFake::$options[$name] = $bad;
        ysss_assert_false(YSSsRateLimiter::allow('log', 30), "Malformed state {$bad} was admitted");
        ysss_assert_same($bad, YSSsWpFake::$options[$name] ?? null, 'Malformed state was rewritten');
    }

    YSSsWpFake::reset();
    $name = ysss_rate_option_name('log');
    $expiry = time() + MINUTE_IN_SECONDS;
    YSSsWpFake::$options[$name] = 'v1:' . $expiry . ':' . (PHP_INT_MAX - 1);
    ysss_assert_true(YSSsRateLimiter::allow('log', PHP_INT_MAX));
    ysss_assert_same('v1:' . $expiry . ':' . PHP_INT_MAX, YSSsWpFake::$options[$name] ?? null);

    YSSsWpFake::reset();
    $name = ysss_rate_option_name('log');
    $overflow = (string) PHP_INT_MAX . '0';
    YSSsWpFake::$options[$name] = "v1:{$expiry}:{$overflow}";
    ysss_assert_false(YSSsRateLimiter::allow('log', PHP_INT_MAX));
});

ysss_test('genuinely absent and expired states start a fresh one-minute window', static function (): void {
    YSSsWpFake::reset();
    $name = ysss_rate_option_name('query');
    $before = time();
    ysss_assert_true(YSSsRateLimiter::allow('query', 60));
    preg_match('/\Av1:([0-9]+):1\z/D', (string) (YSSsWpFake::$options[$name] ?? ''), $created);
    ysss_assert_true((int) ($created[1] ?? 0) >= $before + MINUTE_IN_SECONDS);
    ysss_assert_true((int) ($created[1] ?? 0) <= time() + MINUTE_IN_SECONDS);

    YSSsWpFake::reset();
    $name = ysss_rate_option_name('query');
    YSSsWpFake::$options[$name] = 'v1:' . (time() - 1) . ':60';
    $before = time();
    ysss_assert_true(YSSsRateLimiter::allow('query', 60));
    preg_match('/\Av1:([0-9]+):1\z/D', (string) (YSSsWpFake::$options[$name] ?? ''), $reset);
    ysss_assert_true((int) ($reset[1] ?? 0) >= $before + MINUTE_IN_SECONDS);
});

ysss_test('successful write with mismatching readback still fails closed', static function (): void {
    YSSsWpFake::reset();
    $reads = 0;
    $GLOBALS['wpdb']->rateGetResultsHandler = static function (string $sql) use (&$reads): array {
        ++$reads;
        if (1 === $reads) {
            return [];
        }
        preg_match("/option_name\\s*=\\s*'([^']+)'/", $sql, $match);
        return [ysss_rate_row((string) ($match[1] ?? ''), 'v1:' . (time() + MINUTE_IN_SECONDS) . ':2')];
    };
    $GLOBALS['wpdb']->rateQueryHandler = static fn(string $sql): int => 1;

    ysss_assert_false(YSSsRateLimiter::allow('log', 30));
    ysss_assert_same(2, $reads, 'Fixture did not reach exact readback');
    ysss_assert_same(1, count(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    )));
});

ysss_test('uncertain authority release denies after conservatively consuming quota', static function (): void {
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

        ysss_assert_false(YSSsRateLimiter::allow('query', 60), 'Uncertain release admitted the request');
        $stored = (string) (YSSsWpFake::$options[ysss_rate_option_name('query')] ?? '');
        ysss_assert_true(1 === preg_match('/\Av1:[0-9]+:1\z/D', $stored), 'Quota was not conservatively consumed');
    }
});

ysss_test('interleaved database sessions cannot consume the same old state', static function (): void {
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

    $firstDb->rateGetResultsHandler = static function (string $sql) use (
        &$nested,
        &$insideRead,
        $firstDb,
        $secondDb
    ): array {
        preg_match("/option_name\\s*=\\s*'([^']+)'/", $sql, $match);
        $name = (string) ($match[1] ?? '');
        if (!$insideRead && !array_key_exists($name, YSSsWpFake::$options)) {
            $insideRead = true;
            $GLOBALS['wpdb'] = $secondDb;
            try {
                $nested = YSSsRateLimiter::allow('log', 1);
            } finally {
                $GLOBALS['wpdb'] = $firstDb;
                $insideRead = false;
            }
        }
        return array_key_exists($name, YSSsWpFake::$options)
            ? [ysss_rate_row($name, (string) YSSsWpFake::$options[$name])]
            : [];
    };

    $outer = YSSsRateLimiter::allow('log', 1);

    ysss_assert_true($outer, 'Lock owner was not admitted');
    ysss_assert_false((bool) $nested, 'Interleaved contender consumed the same old state');
    ysss_assert_same([], YSSsWpFake::$transientGets);
    ysss_assert_same([], YSSsWpFake::$transientSets);
    ysss_assert_same('v1:', substr((string) (YSSsWpFake::$options[ysss_rate_option_name('log')] ?? ''), 0, 3));
    ysss_assert_same(null, $owner, 'Serialized fixture ended with the lock held');
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

ysss_test('public log performs zero analytics work when durable counter write fails', static function (): void {
    YSSsWpFake::reset();
    $visitor = YSSsRateLimiter::visitor_hash();
    $receipt = YSSsLogReceipt::issue('nova', 1, 'products', $visitor);
    $GLOBALS['wpdb']->rateQueryHandler = static fn(string $sql): int|false => false;

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

ysss_test('bounded cleanup removes only currently canonical expired plugin rows', static function (): void {
    YSSsWpFake::reset();
    $expired = ysss_rate_option_name('log');
    $live = ysss_rate_option_name('query');
    $malformed = ysss_rate_option_name('suggest');
    YSSsWpFake::$options[$expired] = 'v1:' . (time() - 10) . ':5';
    YSSsWpFake::$options[$live] = 'v1:' . (time() + 60) . ':5';
    YSSsWpFake::$options[$malformed] = 'broken';
    YSSsWpFake::$options['other_plugin_rate'] = 'v1:' . (time() - 10) . ':5';

    ysss_assert_true(YSSsRateLimiter::cleanup_expired());
    ysss_assert_false(array_key_exists($expired, YSSsWpFake::$options));
    ysss_assert_true(array_key_exists($live, YSSsWpFake::$options));
    ysss_assert_true(array_key_exists($malformed, YSSsWpFake::$options));
    ysss_assert_true(array_key_exists('other_plugin_rate', YSSsWpFake::$options));
    $cleanup = array_values(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_starts_with(trim($sql), 'DELETE FROM `wp_options`')
    ));
    ysss_assert_same(1, count($cleanup));
    ysss_assert_contains('option_value REGEXP', $cleanup[0]);
    ysss_assert_contains('LIMIT 5000', $cleanup[0]);
});

ysss_test('cleanup current-value predicate cannot erase a concurrently refreshed live row', static function (): void {
    YSSsWpFake::reset();
    $name = ysss_rate_option_name('log');
    YSSsWpFake::$options[$name] = 'v1:' . (time() - 1) . ':30';
    $GLOBALS['wpdb']->rateCleanupBeforeApply = static function (string $sql) use ($name): void {
        YSSsWpFake::$options[$name] = 'v1:' . (time() + MINUTE_IN_SECONDS) . ':1';
        ysss_assert_contains('option_value REGEXP', $sql);
        ysss_assert_contains('SUBSTRING_INDEX(option_value', $sql);
    };

    ysss_assert_true(YSSsRateLimiter::cleanup_expired());
    ysss_assert_true(str_ends_with((string) (YSSsWpFake::$options[$name] ?? ''), ':1'));
});

ysss_test('rate identity is site-keyed and aggregates IPv6 privacy addresses by slash 64', static function (): void {
    YSSsWpFake::reset();
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678:1111:2222:3333:4444';
    $packed = inet_pton((string) $_SERVER['REMOTE_ADDR']);
    ysss_assert_true(false !== $packed);
    $network = substr((string) $packed, 0, 8) . str_repeat("\0", 8);
    $expected = 'ys_ss_rate_v1_log_' . substr(hash_hmac('sha256', $network, wp_salt('nonce')), 0, 24);

    ysss_assert_true(YSSsRateLimiter::allow('log', 1));
    ysss_assert_true(array_key_exists($expected, YSSsWpFake::$options), 'Durable key was not site-keyed IPv6 /64 identity');

    $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd';
    ysss_assert_false(YSSsRateLimiter::allow('log', 1), 'A privacy address in the same /64 reset the rate budget');
    ysss_assert_same(1, count(array_filter(
        array_keys(YSSsWpFake::$options),
        static fn(string $key): bool => str_starts_with($key, 'ys_ss_rate_v1_log_')
    )));

    YSSsWpFake::reset();
    $_SERVER['REMOTE_ADDR'] = '192.0.2.44';
    ysss_assert_true(YSSsRateLimiter::allow('query', 1));
    $_SERVER['REMOTE_ADDR'] = '::ffff:192.0.2.44';
    ysss_assert_false(
        YSSsRateLimiter::allow('query', 1),
        'IPv4-mapped IPv6 spelling escaped the same client quota'
    );
});

ysss_test('cleanup drains a bounded multi-batch backlog and detects an exhausted work budget', static function (): void {
    YSSsWpFake::reset();
    $calls = 0;
    $results = [5000, 1];
    $GLOBALS['wpdb']->rateQueryHandler = static function (string $sql) use (&$calls, &$results): int {
        ++$calls;
        return (int) array_shift($results);
    };
    ysss_assert_true(YSSsRateLimiter::cleanup_expired(), 'A two-batch bounded backlog was not drained');
    ysss_assert_same(2, $calls, 'Cleanup did not continue after a full first batch');

    YSSsWpFake::reset();
    $calls = 0;
    $GLOBALS['wpdb']->rateQueryHandler = static function (string $sql) use (&$calls): int {
        ++$calls;
        return 5000;
    };
    ysss_assert_false(YSSsRateLimiter::cleanup_expired(), 'An exhausted cleanup work budget was reported complete');
    ysss_assert_same(20, $calls, 'Cleanup did not enforce the exact bounded batch budget');
});

ysss_test('cleanup grammar preserves noncanonical and zero-count rows', static function (): void {
    YSSsWpFake::reset();
    $leading = ysss_rate_option_name('log');
    $zero = ysss_rate_option_name('query');
    YSSsWpFake::$options[$leading] = 'v1:0000000001:05';
    YSSsWpFake::$options[$zero] = 'v1:1:0';

    ysss_assert_true(YSSsRateLimiter::cleanup_expired());
    ysss_assert_true(array_key_exists($leading, YSSsWpFake::$options), 'Cleanup deleted leading-zero state rejected by runtime');
    ysss_assert_true(array_key_exists($zero, YSSsWpFake::$options), 'Cleanup deleted zero-count state rejected by runtime');
});

ysss_test('fake cleanup adapter rejects a near-miss SQL without scope predicates', static function (): void {
    YSSsWpFake::reset();
    $name = ysss_rate_option_name('log');
    YSSsWpFake::$options[$name] = 'v1:' . (time() - 1) . ':3';
    $nearMiss = "DELETE FROM `wp_options`
        WHERE option_name LIKE 'ys\\_ss\\_rate\\_v1\\_%'
        AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, ':', 2), ':', -1) AS UNSIGNED) <= " . time() . "
        LIMIT 5000";

    $GLOBALS['wpdb']->query($nearMiss);

    ysss_assert_true(array_key_exists($name, YSSsWpFake::$options), 'Near-miss cleanup SQL was rewritten into authoritative deletion');
});

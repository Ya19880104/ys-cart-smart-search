<?php
declare(strict_types=1);

namespace YangSheep\SmartSearch\Api {
    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            $GLOBALS['ysss_receipt_api_time_calls'] = (int) ($GLOBALS['ysss_receipt_api_time_calls'] ?? 0) + 1;
            return array_key_exists('ysss_receipt_api_now', $GLOBALS)
                ? (int) $GLOBALS['ysss_receipt_api_now']
                : \time();
        }
    }
}

namespace YangSheep\SmartSearch\Security {
    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            $GLOBALS['ysss_receipt_security_time_calls'] = (int) ($GLOBALS['ysss_receipt_security_time_calls'] ?? 0) + 1;
            return array_key_exists('ysss_receipt_security_now', $GLOBALS)
                ? (int) $GLOBALS['ysss_receipt_security_now']
                : \time();
        }
    }
}

namespace {

use YangSheep\SmartSearch\Api\YSSsPublicController;
use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Security\YSSsLogReceipt;
use YangSheep\SmartSearch\Security\YSSsRateLimiter;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Security/YSSsRateLimiter.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/YSSmartSearchDetector.php',
    'src/Frontend/YSSsResultsPage.php',
    'src/Services/YSSsSearchService.php',
    'src/Api/YSSsPublicController.php',
] as $source) {
    ysss_require_source($source);
}

$receiptPath = ysss_source_path('src/Security/YSSsLogReceipt.php');
if (is_file($receiptPath)) {
    require_once $receiptPath;
}

$b64url = static function (string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
};

$signedFixture = static function (array $claims) use ($b64url): string {
    $json = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $payload = $b64url($json);
    $signature = hash_hmac('sha256', $payload, wp_salt('auth'), true);
    return $payload . '.' . $b64url($signature);
};

$receiptClaims = static function (string $receipt): array {
    [$payload] = explode('.', $receipt, 2);
    $padding = (4 - strlen($payload) % 4) % 4;
    $json = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', $padding), true);
    return is_string($json) ? (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
};

// These literals straddle the actual UTC day boundary. The plan's original
// 1787673570 / 1787673615 pair straddles Taipei midnight, not gmdate('Ymd').
$issueAt = 1787702370;  // 2026-08-25 23:59:30Z.
$verifyAt = 1787702415; // 2026-08-26 00:00:15Z.
$issueVisitor = '52ca42cf72e739e7';
$verifyDayVisitor = '038835b6e90662a7';

ysss_test('log receipt issues and verifies trusted claims', static function (): void {
    ysss_assert_true(class_exists(YSSsLogReceipt::class), 'YSSsLogReceipt is missing');
    $receipt = YSSsLogReceipt::issue('nova', 3, 'products,categories', 'visitor-12345678');
    ysss_assert_true('' !== $receipt, 'Receipt must not be empty');
    ysss_assert_true(strlen($receipt) <= 1024, 'Receipt exceeds bounded size');
    ysss_assert_same([
        'query' => 'nova',
        'total' => 3,
        'content_types' => 'products,categories',
        'visitor_hash' => 'visitor-12345678',
    ], YSSsLogReceipt::verify($receipt, 'nova', 'visitor-12345678'));
});

ysss_test('log receipt rejects tamper query and visitor mismatch', static function (): void {
    ysss_assert_true(class_exists(YSSsLogReceipt::class), 'YSSsLogReceipt is missing');
    $receipt = YSSsLogReceipt::issue('nova', 0, 'products', 'visitor-12345678');
    $last = substr($receipt, -1);
    $tampered = substr($receipt, 0, -1) . ('A' === $last ? 'B' : 'A');
    ysss_assert_same(null, YSSsLogReceipt::verify($tampered, 'nova', 'visitor-12345678'));
    ysss_assert_same(null, YSSsLogReceipt::verify($receipt, 'other', 'visitor-12345678'));
    ysss_assert_same(null, YSSsLogReceipt::verify($receipt, 'nova', 'visitor-other'));
});

ysss_test('log receipt rejects non-canonical base64url signature spelling', static function (): void {
    $receipt = YSSsLogReceipt::issue('nova', 0, 'products', 'visitor-12345678');
    [$payload, $signature] = explode('.', $receipt, 2);
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $last = substr($signature, -1);
    $index = strpos($alphabet, $last);
    ysss_assert_true(false !== $index && 0 === $index % 4, 'Unexpected canonical final base64url character');
    $variant = substr($signature, 0, -1) . $alphabet[$index + 1];
    ysss_assert_same(null, YSSsLogReceipt::verify($payload . '.' . $variant, 'nova', 'visitor-12345678'));
});

ysss_test('log receipt rejects an independently signed expired claim', static function () use ($signedFixture): void {
    ysss_assert_true(class_exists(YSSsLogReceipt::class), 'YSSsLogReceipt is missing');
    $expired = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 0,
        'c' => 'products',
        'vh' => 'visitor-12345678',
        'iat' => time() - 300,
        'exp' => time() - 180,
    ]);
    ysss_assert_same(null, YSSsLogReceipt::verify($expired, 'nova', 'visitor-12345678'));
});

ysss_test('visitor hash derives the supplied UTC day', static function () use ($issueAt, $verifyAt, $issueVisitor, $verifyDayVisitor): void {
    YSSsWpFake::reset();
    ysss_assert_same($issueVisitor, YSSsRateLimiter::visitor_hash_at($issueAt));
    ysss_assert_same($verifyDayVisitor, YSSsRateLimiter::visitor_hash_at($verifyAt));
    ysss_assert_false($issueVisitor === $verifyDayVisitor, 'UTC boundary fixture did not change the daily identity');
});

ysss_test('legacy v1 receipt verifies across UTC midnight with explicit compatibility ABI', static function () use ($signedFixture, $issueAt, $verifyAt, $issueVisitor): void {
    YSSsWpFake::reset();
    $receipt = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 5,
        'c' => 'products',
        'vh' => $issueVisitor,
        'iat' => $issueAt,
        'exp' => $issueAt + 120,
    ]);
    ysss_assert_same([
        'query' => 'nova',
        'total' => 5,
        'content_types' => 'products',
        'visitor_hash' => $issueVisitor,
    ], YSSsLogReceipt::verify($receipt, 'nova', $issueVisitor, $verifyAt));
    ysss_assert_same(null, YSSsLogReceipt::verify($receipt, 'other', $issueVisitor, $verifyAt));
    ysss_assert_same(null, YSSsLogReceipt::verify($receipt, 'nova', 'visitor-other', $verifyAt));
});

ysss_test('issued v1 receipt preserves the supplied issue clock and exact TTL', static function () use ($receiptClaims, $issueAt, $verifyAt, $issueVisitor): void {
    YSSsWpFake::reset();
    $receipt = YSSsLogReceipt::issue('nova', 5, 'products', $issueVisitor, $issueAt);
    ysss_assert_true('' !== $receipt, 'Cross-midnight receipt was not issued');
    $wire = $receiptClaims($receipt);
    ysss_assert_same(1, $wire['v'] ?? null);
    ysss_assert_same($issueAt, $wire['iat'] ?? null);
    ysss_assert_same($issueAt + 120, $wire['exp'] ?? null);
    ysss_assert_same($issueVisitor, $wire['vh'] ?? null);
    ysss_assert_same([
        'query' => 'nova',
        'total' => 5,
        'content_types' => 'products',
        'visitor_hash' => $issueVisitor,
    ], YSSsLogReceipt::verify_for_request($receipt, 'nova', $verifyAt));
});

ysss_test('request verification uses signed issue day and rejects changed IP or UA', static function () use ($signedFixture, $issueAt, $verifyAt, $issueVisitor): void {
    YSSsWpFake::reset();
    $receipt = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 5,
        'c' => 'products',
        'vh' => $issueVisitor,
        'iat' => $issueAt,
        'exp' => $issueAt + 120,
    ]);
    ysss_assert_same([
        'query' => 'nova',
        'total' => 5,
        'content_types' => 'products',
        'visitor_hash' => $issueVisitor,
    ], YSSsLogReceipt::verify_for_request($receipt, 'nova', $verifyAt));

    $_SERVER['REMOTE_ADDR'] = '198.51.100.44';
    ysss_assert_same(null, YSSsLogReceipt::verify_for_request($receipt, 'nova', $verifyAt));
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $_SERVER['HTTP_USER_AGENT'] = 'changed-user-agent';
    ysss_assert_same(null, YSSsLogReceipt::verify_for_request($receipt, 'nova', $verifyAt));
});

ysss_test('verified parser rejects expiry old age tamper future issue time and wrong TTL', static function () use ($signedFixture, $issueAt, $verifyAt, $issueVisitor): void {
    YSSsWpFake::reset();
    $valid = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 5,
        'c' => 'products',
        'vh' => $issueVisitor,
        'iat' => $issueAt,
        'exp' => $issueAt + 120,
    ]);
    $last = substr($valid, -1);
    $tampered = substr($valid, 0, -1) . ('A' === $last ? 'B' : 'A');
    $twoDaysOld = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 5,
        'c' => 'products',
        'vh' => $issueVisitor,
        'iat' => $issueAt - 172800,
        'exp' => $issueAt - 172680,
    ]);
    $future = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 5,
        'c' => 'products',
        'vh' => $issueVisitor,
        'iat' => $verifyAt + 31,
        'exp' => $verifyAt + 151,
    ]);
    $wrongTtl = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 5,
        'c' => 'products',
        'vh' => $issueVisitor,
        'iat' => $issueAt,
        'exp' => $issueAt + 121,
    ]);

    ysss_assert_same(null, YSSsLogReceipt::verify($valid, 'nova', $issueVisitor, $issueAt + 121));
    ysss_assert_same(null, YSSsLogReceipt::verify($twoDaysOld, 'nova', $issueVisitor, $verifyAt));
    ysss_assert_same(null, YSSsLogReceipt::verify($tampered, 'nova', $issueVisitor, $verifyAt));
    ysss_assert_same(null, YSSsLogReceipt::verify($future, 'nova', $issueVisitor, $verifyAt));
    ysss_assert_same(null, YSSsLogReceipt::verify($wrongTtl, 'nova', $issueVisitor, $verifyAt));
});

ysss_test('empty visitor hashes are never issued or accepted', static function () use ($signedFixture, $issueAt, $verifyAt): void {
    YSSsWpFake::reset();
    ysss_assert_same('', YSSsLogReceipt::issue('nova', 1, 'products', '   ', $issueAt));
    $emptyVisitor = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 1,
        'c' => 'products',
        'vh' => '',
        'iat' => $issueAt,
        'exp' => $issueAt + 120,
    ]);
    ysss_assert_same(null, YSSsLogReceipt::verify($emptyVisitor, 'nova', '', $verifyAt));
    ysss_assert_same(null, YSSsLogReceipt::verify_for_request($emptyVisitor, 'nova', $verifyAt));
});

ysss_test('query signs the non-zero total produced by the server search', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->resultSets = [[
        [
            'id' => 17,
            'title' => 'Nova Jacket',
            'slug' => 'nova-jacket',
            'sku' => 'NOVA-17',
            'price' => '1200',
            'sale_price' => '990',
            'image_url' => 'https://example.test/nova.jpg',
        ],
        [
            'id' => 18,
            'title' => 'Nova Coat',
            'slug' => 'nova-coat',
            'sku' => 'NOVA-18',
            'price' => '1500',
            'sale_price' => '0',
            'image_url' => '',
        ],
    ]];
    $response = (new YSSsPublicController())->query(new WP_REST_Request(['q' => 'Nova']));
    ysss_assert_true($response instanceof WP_REST_Response);
    $data = $response->get_data();
    ysss_assert_same('nova', $data['q'] ?? null);
    ysss_assert_same(2, $data['total'] ?? null, 'Server search fixture did not produce the expected non-zero total');
    ysss_assert_true(is_string($data['log_receipt'] ?? null) && '' !== $data['log_receipt'], 'Valid query did not receive a receipt');
    $visitor = YSSsRateLimiter::visitor_hash();
    $claims = YSSsLogReceipt::verify($data['log_receipt'], 'nova', $visitor);
    ysss_assert_same([
        'query' => 'nova',
        'total' => 2,
        'content_types' => 'products',
        'visitor_hash' => $visitor,
    ], $claims, 'Receipt did not bind the complete server-computed result');
});

ysss_test('query receipt matches filter-final groups and preserves searched scopes', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->resultSets = [[[
        'id' => 17,
        'title' => 'Nova Jacket',
        'slug' => 'nova-jacket',
        'sku' => 'NOVA-17',
        'price' => '1200',
        'sale_price' => '990',
        'image_url' => '',
    ]]];
    add_filter('ys_ss_result_groups', static fn(array $groups): array => []);

    $response = (new YSSsPublicController())->query(new WP_REST_Request(['q' => 'Nova']));
    ysss_assert_true($response instanceof WP_REST_Response);
    $data = $response->get_data();
    ysss_assert_same([], $data['groups'] ?? null);
    ysss_assert_same(0, $data['total'] ?? null, 'Total was not recomputed from filter-final groups');
    $visitor = YSSsRateLimiter::visitor_hash();
    $claims = YSSsLogReceipt::verify(
        (string) ($data['log_receipt'] ?? ''),
        'nova',
        $visitor
    );
    ysss_assert_same([
        'query' => 'nova',
        'total' => 0,
        'content_types' => 'products',
        'visitor_hash' => $visitor,
    ], $claims, 'Receipt did not preserve the authoritative searched scopes and final total');
});

ysss_test('query response and receipt share the same total upper bound', static function (): void {
    YSSsWpFake::reset();
    add_filter('ys_ss_result_groups', static fn(array $groups): array => [[
        'type' => 'products',
        'total' => 2000000,
        'items' => [['title' => 'Synthetic result']],
    ]]);
    $response = (new YSSsPublicController())->query(new WP_REST_Request(['q' => 'Nova']));
    ysss_assert_true($response instanceof WP_REST_Response);
    $data = $response->get_data();
    ysss_assert_same(1000000, $data['total'] ?? null, 'Public total exceeded the signed claim bound');
    $claims = YSSsLogReceipt::verify(
        (string) ($data['log_receipt'] ?? ''),
        'nova',
        YSSsRateLimiter::visitor_hash()
    );
    ysss_assert_same(1000000, $claims['total'] ?? null, 'Receipt total diverged from the public response');
});

ysss_test('query controller captures one issue clock for visitor and receipt', static function () use ($issueAt, $issueVisitor): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->resultSets = [[[
        'id' => 17,
        'title' => 'Nova Jacket',
        'slug' => 'nova-jacket',
        'sku' => 'NOVA-17',
        'price' => '1200',
        'sale_price' => '990',
        'image_url' => '',
    ]]];
    $GLOBALS['ysss_receipt_api_now'] = $issueAt;
    $GLOBALS['ysss_receipt_api_time_calls'] = 0;
    $GLOBALS['ysss_receipt_security_now'] = $issueAt + 60;
    $GLOBALS['ysss_receipt_security_time_calls'] = 0;

    try {
        $response = (new YSSsPublicController())->query(new WP_REST_Request(['q' => 'Nova']));
        ysss_assert_true($response instanceof WP_REST_Response);
        $data = $response->get_data();
        $claims = YSSsLogReceipt::verify(
            (string) ($data['log_receipt'] ?? ''),
            'nova',
            $issueVisitor,
            $issueAt
        );
        $apiCalls = (int) $GLOBALS['ysss_receipt_api_time_calls'];
        $securityCalls = (int) $GLOBALS['ysss_receipt_security_time_calls'];
    } finally {
        unset(
            $GLOBALS['ysss_receipt_api_now'],
            $GLOBALS['ysss_receipt_api_time_calls'],
            $GLOBALS['ysss_receipt_security_now'],
            $GLOBALS['ysss_receipt_security_time_calls']
        );
    }

    ysss_assert_same(1, $apiCalls, 'Query did not capture exactly one issue clock');
    ysss_assert_same(0, $securityCalls, 'Query re-read the clock inside visitor or receipt generation');
    ysss_assert_same([
        'query' => 'nova',
        'total' => 1,
        'content_types' => 'products',
        'visitor_hash' => $issueVisitor,
    ], $claims, 'Query did not bind all receipt claims to its captured issue clock');
});

ysss_test('receipt payload bound admits all individually valid claim maxima', static function (): void {
    $query = str_repeat('😀', 100);
    $visitor = str_repeat('a', 64);
    $receipt = YSSsLogReceipt::issue($query, 1000000, 'products,categories,posts', $visitor);
    ysss_assert_true('' !== $receipt, 'Individually valid maximum claims could not be issued together');
    ysss_assert_true(strlen($receipt) <= 1024, 'Maximum valid receipt exceeded the token abuse bound');
    ysss_assert_same([
        'query' => $query,
        'total' => 1000000,
        'content_types' => 'products,categories,posts',
        'visitor_hash' => $visitor,
    ], YSSsLogReceipt::verify($receipt, $query, $visitor));
});

ysss_test('log without receipt returns neutral success and performs zero insert', static function (): void {
    YSSsWpFake::reset();
    $response = (new YSSsPublicController())->log(new WP_REST_Request([
        'q' => 'nova',
        'total' => 999,
        'source' => 'bar',
    ]));
    ysss_assert_true($response instanceof WP_REST_Response);
    ysss_assert_same(['ok' => true], $response->get_data());
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Missing receipt still wrote analytics');
});

ysss_test('empty query without a valid receipt uses the same neutral response', static function (): void {
    YSSsWpFake::reset();
    $response = (new YSSsPublicController())->log(new WP_REST_Request([
        'q' => '',
        'receipt' => '',
        'source' => 'bar',
    ]));
    ysss_assert_true($response instanceof WP_REST_Response, 'Empty invalid analytics request exposed a distinct error shape');
    ysss_assert_same(['ok' => true], $response->get_data());
    ysss_assert_same([], $GLOBALS['wpdb']->inserts);
});

ysss_test('log ignores client total and stores signed server total', static function (): void {
    YSSsWpFake::reset();
    $visitor = YSSsRateLimiter::visitor_hash();
    $receipt = YSSsLogReceipt::issue('nova', 0, 'products', $visitor);
    $response = (new YSSsPublicController())->log(new WP_REST_Request([
        'q' => 'nova',
        'total' => 999,
        'receipt' => $receipt,
        'source' => 'bar',
    ]));
    ysss_assert_same(['ok' => true], $response->get_data());
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts));
    $data = $GLOBALS['wpdb']->inserts[0]['data'];
    ysss_assert_same(0, $data['results_total'] ?? null);
    ysss_assert_same(0, $data['has_results'] ?? null);
    ysss_assert_same('products', $data['content_types'] ?? null);
});

ysss_test('log controller replays across UTC midnight with one repository dedupe identity', static function () use ($signedFixture, $issueAt, $verifyAt, $issueVisitor, $verifyDayVisitor): void {
    YSSsWpFake::reset();
    ysss_assert_false($issueVisitor === $verifyDayVisitor, 'Cross-midnight identities unexpectedly match');
    $receipt = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 2,
        'c' => 'products',
        'vh' => $issueVisitor,
        'iat' => $issueAt,
        'exp' => $issueAt + 120,
    ]);
    $dedupeSql = [];
    $dedupeClockPhases = [];
    $GLOBALS['wpdb']->getVarHandler = static function (string $sql) use (&$dedupeSql, &$dedupeClockPhases): int {
        if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
            return 1;
        }
        if (str_contains($sql, 'SELECT 1 FROM')) {
            $dedupeSql[] = $sql;
            $dedupeClockPhases[] = (int) ($GLOBALS['ysss_receipt_security_now'] ?? 0);
            return count($dedupeSql) > 1 ? 1 : 0;
        }
        return 0;
    };
    $GLOBALS['ysss_receipt_security_now'] = $issueAt;
    $GLOBALS['ysss_receipt_security_time_calls'] = 0;

    try {
        $first = (new YSSsPublicController())->log(new WP_REST_Request([
            'q' => 'nova',
            'total' => 999,
            'receipt' => $receipt,
            'source' => 'bar',
        ]));
        $GLOBALS['ysss_receipt_security_now'] = $verifyAt;
        $second = (new YSSsPublicController())->log(new WP_REST_Request([
            'q' => 'nova',
            'total' => 999,
            'receipt' => $receipt,
            'source' => 'bar',
        ]));
        $securityTimeCalls = (int) $GLOBALS['ysss_receipt_security_time_calls'];
    } finally {
        unset($GLOBALS['ysss_receipt_security_now'], $GLOBALS['ysss_receipt_security_time_calls']);
    }

    ysss_assert_same(['ok' => true], $first->get_data());
    ysss_assert_same(['ok' => true], $second->get_data());
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), 'Cross-midnight replay bypassed repository dedupe');
    ysss_assert_same($issueVisitor, $GLOBALS['wpdb']->inserts[0]['data']['visitor_hash'] ?? null, 'Repository received verification-day identity instead of the signed issue-day identity');
    ysss_assert_same(2, count($dedupeSql), 'Both replay attempts did not reach the real repository dedupe check');
    ysss_assert_same([$issueAt, $verifyAt], $dedupeClockPhases, 'Repository was not reached once before and once after UTC midnight');
    ysss_assert_same(2, $securityTimeCalls, 'Each log verification did not capture exactly one phase clock');
    $wrongIdentitySql = array_values(array_filter(
        $dedupeSql,
        static fn(string $sql): bool => !str_contains($sql, "visitor_hash = '{$issueVisitor}'")
    ));
    ysss_assert_same([], $wrongIdentitySql, 'Repository dedupe queries did not consistently use the signed issue-day identity');
});

ysss_test('invalid or expired receipts perform zero insert with the same response shape', static function () use ($signedFixture): void {
    YSSsWpFake::reset();
    $visitor = YSSsRateLimiter::visitor_hash();
    $expired = $signedFixture([
        'v' => 1,
        'q' => 'nova',
        't' => 1,
        'c' => 'products',
        'vh' => $visitor,
        'iat' => time() - 300,
        'exp' => time() - 180,
    ]);
    foreach (['not-a-receipt', $expired] as $receipt) {
        $response = (new YSSsPublicController())->log(new WP_REST_Request([
            'q' => 'nova',
            'total' => 999,
            'receipt' => $receipt,
            'source' => 'bar',
        ]));
        ysss_assert_same(['ok' => true], $response->get_data());
    }
    ysss_assert_same([], $GLOBALS['wpdb']->inserts);
});

ysss_test('non-scalar receipt and source fail neutral without PHP warnings', static function (): void {
    foreach ([
        ['q' => 'nova', 'receipt' => ['not-a-string'], 'source' => 'bar'],
        ['q' => 'nova', 'receipt' => 'not-a-receipt', 'source' => ['not-a-string']],
    ] as $params) {
        YSSsWpFake::reset();
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];
            return true;
        });
        try {
            $response = (new YSSsPublicController())->log(new WP_REST_Request($params));
        } finally {
            restore_error_handler();
        }
        ysss_assert_true($response instanceof WP_REST_Response);
        ysss_assert_same(['ok' => true], $response->get_data());
        ysss_assert_same([], $warnings, 'Malformed public JSON emitted a PHP warning');
        ysss_assert_same([], $GLOBALS['wpdb']->inserts);
    }
});

ysss_test('concurrent receipt replay is serialized before dedupe and insert', static function (): void {
    YSSsWpFake::reset();
    $visitor = YSSsRateLimiter::visitor_hash();
    $lockHeld = false;
    $interleaved = false;
    $GLOBALS['wpdb']->getVarHandler = static function (string $sql) use (&$lockHeld, &$interleaved, $visitor): int {
        if (str_contains($sql, 'GET_LOCK')) {
            if ($lockHeld) {
                return 0;
            }
            $lockHeld = true;
            return 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $lockHeld = false;
            return 1;
        }
        if (str_contains($sql, 'SELECT 1 FROM')) {
            if (!$interleaved) {
                $interleaved = true;
                YSSsQueryRepository::log('nova', 2, 'products', 'bar', $visitor);
            }
            return 0;
        }
        return 0;
    };

    YSSsQueryRepository::log('nova', 2, 'products', 'bar', $visitor);
    ysss_assert_true($interleaved, 'Forced interleaving did not execute');
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), 'Concurrent replay inserted the same analytics event twice');
    ysss_assert_false($lockHeld, 'Replay lock was not released');
    ysss_assert_true((bool) array_filter($GLOBALS['wpdb']->queries, static fn(string $sql): bool => str_contains($sql, 'GET_LOCK')), 'No atomic serialization lock was attempted');
});
}

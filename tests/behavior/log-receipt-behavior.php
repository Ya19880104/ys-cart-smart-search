<?php
declare(strict_types=1);

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

ysss_test('log receipt issues and verifies trusted claims', static function (): void {
    ysss_assert_true(class_exists(YSSsLogReceipt::class), 'YSSsLogReceipt is missing');
    $receipt = YSSsLogReceipt::issue('nova', 3, 'products,categories', 'visitor-12345678');
    ysss_assert_true('' !== $receipt, 'Receipt must not be empty');
    ysss_assert_true(strlen($receipt) <= 1024, 'Receipt exceeds bounded size');
    ysss_assert_same([
        'query' => 'nova',
        'total' => 3,
        'content_types' => 'products,categories',
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
    $claims = YSSsLogReceipt::verify(
        (string) ($data['log_receipt'] ?? ''),
        'nova',
        YSSsRateLimiter::visitor_hash()
    );
    ysss_assert_same([
        'query' => 'nova',
        'total' => 0,
        'content_types' => 'products',
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

ysss_test('receipt payload bound admits all individually valid claim maxima', static function (): void {
    ysss_assert_true(function_exists('mb_substr'), 'Behavior suite requires the same mbstring support used by production');
    $query = str_repeat('😀', 100);
    $visitor = str_repeat('a', 64);
    $receipt = YSSsLogReceipt::issue($query, 1000000, 'products,categories,posts', $visitor);
    ysss_assert_true('' !== $receipt, 'Individually valid maximum claims could not be issued together');
    ysss_assert_true(strlen($receipt) <= 1024, 'Maximum valid receipt exceeded the token abuse bound');
    ysss_assert_same([
        'query' => $query,
        'total' => 1000000,
        'content_types' => 'products,categories,posts',
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

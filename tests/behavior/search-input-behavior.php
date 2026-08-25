<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Api\YSSsPublicController;
use YangSheep\SmartSearch\Frontend\YSSsResultsPage;
use YangSheep\SmartSearch\Security\YSSsInjectionGuard;
use YangSheep\SmartSearch\Security\YSSsSearchInput;
use YangSheep\SmartSearch\Services\YSSsSearchService;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsRateLimiter.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/YSSmartSearchDetector.php',
    'src/Frontend/YSSsShortcodes.php',
    'src/Frontend/YSSsResultsPage.php',
    'src/Services/YSSsSearchService.php',
    'src/Api/YSSsPublicController.php',
] as $source) {
    ysss_require_source($source);
}

$searchInputPath = ysss_source_path('src/Security/YSSsSearchInput.php');
if (is_file($searchInputPath)) {
    require_once $searchInputPath;
}

/** @var array{blocked:array<string,string>,allowed:array<string,string>} $cases */
$cases = require __DIR__ . '/../fixtures/search-input-cases.php';

foreach ($cases['blocked'] as $label => $input) {
    ysss_test("raw ingress blocks {$label}", static function () use ($input): void {
        ysss_assert_true(class_exists(YSSsSearchInput::class), 'YSSsSearchInput is missing');
        $decision = YSSsSearchInput::inspect($input);
        ysss_assert_same(true, $decision['blocked'] ?? null);
        ysss_assert_same('', $decision['query'] ?? null);
    });
}

foreach ($cases['allowed'] as $label => $input) {
    ysss_test("raw ingress allows {$label}", static function () use ($input): void {
        ysss_assert_true(class_exists(YSSsSearchInput::class), 'YSSsSearchInput is missing');
        $decision = YSSsSearchInput::inspect($input);
        ysss_assert_same(false, $decision['blocked'] ?? null);
        ysss_assert_same($input, $decision['query'] ?? null, 'Accepted search bytes were changed');
    });
}

ysss_test('guard no longer rejects benign technical syntax', static function () use ($cases): void {
    foreach ($cases['allowed'] as $label => $input) {
        ysss_assert_false(YSSsInjectionGuard::is_attack($input), "False positive: {$label}");
    }
});

ysss_test('query route preserves raw q for the centralized ingress decision', static function (): void {
    YSSsWpFake::reset();
    (new YSSsPublicController())->register_routes();
    $route = YSSsWpFake::$routes['ys-ecommerce-headless/v1/smart-search/query'] ?? [];
    $qArgs = $route['args']['q'] ?? [];
    ysss_assert_false(array_key_exists('sanitize_callback', $qArgs), 'REST must not sanitize q before the raw ingress guard');
});

ysss_test('query rejects lossy markup before product search', static function (): void {
    YSSsWpFake::reset();
    $response = (new YSSsPublicController())->query(new WP_REST_Request([
        'q' => '<svg onload=alert(1)>nova</svg>',
    ]));
    ysss_assert_true($response instanceof WP_REST_Response, 'Expected neutral REST response');
    $data = $response->get_data();
    ysss_assert_same('', $data['q'] ?? null, 'Blocked response must not reflect a sanitized fragment');
    ysss_assert_same('', $data['view_all'] ?? null, 'Blocked response must not build a query URL');
    ysss_assert_same('', $data['log_receipt'] ?? null, 'Blocked response must not issue an analytics receipt');
    $productSql = array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'ys_ec_products')
    );
    ysss_assert_same([], array_values($productSql), 'Blocked input executed product SQL');
});

ysss_test('query scans the raw suffix beyond character 100', static function (): void {
    YSSsWpFake::reset();
    $payload = str_repeat('a', 100) . '{{7*7}}';
    $response = (new YSSsPublicController())->query(new WP_REST_Request(['q' => $payload]));
    ysss_assert_true($response instanceof WP_REST_Response, 'Expected neutral REST response');
    $data = $response->get_data();
    ysss_assert_same('', $data['q'] ?? null, 'Tail marker was truncated before inspection');
    ysss_assert_same([], $data['groups'] ?? null);
});

ysss_test('raw ingress preserves legitimate Windows path bytes', static function (): void {
    $input = 'C:\\Users\\Nova\\manual.pdf';
    $decision = YSSsSearchInput::inspect($input);
    ysss_assert_same(false, $decision['blocked'] ?? null);
    ysss_assert_same($input, $decision['query'] ?? null, 'REST-ready raw input was unslashed twice');
});

ysss_test('REST backslash traversal is blocked before product search', static function (): void {
    YSSsWpFake::reset();
    $response = (new YSSsPublicController())->query(new WP_REST_Request([
        'q' => '..\\..\\etc\\passwd',
    ]));
    ysss_assert_true($response instanceof WP_REST_Response, 'Expected neutral REST response');
    ysss_assert_same('', $response->get_data()['q'] ?? null);
    $productSql = array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'ys_ec_products')
    );
    ysss_assert_same([], array_values($productSql), 'Blocked traversal executed product SQL');
});

ysss_test('neutral result never reflects blocked payload or consults settings', static function (): void {
    YSSsWpFake::reset();
    $result = YSSsSearchService::empty_result('{{7*7}}');
    ysss_assert_same([
        'q' => '',
        'total' => 0,
        'groups' => [],
        'view_all' => '',
        'log_receipt' => '',
    ], $result);
    ysss_assert_same([], $GLOBALS['wpdb']->queries);
});

ysss_test('list mode blocks the core products shortcode before callback execution', static function (): void {
    YSSsWpFake::reset();
    ysss_assert_true(class_exists(YSSsSearchInput::class), 'YSSsSearchInput is missing');
    YSSsSearchInput::register();
    ysss_assert_true(isset(YSSsWpFake::$filters['pre_do_shortcode_tag']), 'A-mode pre-shortcode filter was not registered');
    $_GET['ys_ec_search'] = '<svg onload=alert(1)>nova</svg>';
    $coreCallbackCount = 0;
    $output = apply_filters('pre_do_shortcode_tag', false, 'ys_ec_products', [], []);
    if (false === $output) {
        ++$coreCallbackCount;
        $output = 'core callback output';
    }
    ysss_assert_same(0, $coreCallbackCount, 'Core shortcode callback executed');
    ysss_assert_true(is_string($output), 'Blocked A-mode request was not short-circuited');
    ysss_assert_contains('沒有符合的結果', $output);
    ysss_assert_false(str_contains($output, 'svg'), 'Blocked raw input was reflected');
});

ysss_test('list mode leaves legitimate core shortcode execution untouched', static function (): void {
    YSSsWpFake::reset();
    ysss_assert_true(class_exists(YSSsSearchInput::class), 'YSSsSearchInput is missing');
    $_GET['ys_ec_search'] = 'C++ <vector> 入門';
    ysss_assert_same(false, YSSsSearchInput::pre_do_shortcode_tag(false, 'ys_ec_products', [], []));
    ysss_assert_same('already-rendered', YSSsSearchInput::pre_do_shortcode_tag('already-rendered', 'ys_ec_products', [], []));
    ysss_assert_same(false, YSSsSearchInput::pre_do_shortcode_tag(false, 'unrelated_shortcode', [], []));
});

ysss_test('page mode blocks raw markup before search and analytics writes', static function (): void {
    YSSsWpFake::reset();
    $_GET['ys_ec_search'] = '<svg onload=alert(1)>nova</svg>';
    $html = YSSsResultsPage::render();
    $productSql = array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'ys_ec_products')
    );
    ysss_assert_same([], array_values($productSql), 'Blocked B-mode input executed product SQL');
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Blocked B-mode input wrote analytics');
    ysss_assert_contains('沒有符合的結果', $html);
    ysss_assert_false(str_contains($html, 'onload=alert(1)'), 'Blocked raw input was reflected');
});

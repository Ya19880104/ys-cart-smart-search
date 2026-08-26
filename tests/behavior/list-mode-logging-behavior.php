<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Frontend\YSSsShortcodes;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Security/YSSsRateLimiter.php',
    'src/Analytics/YSSsAnalyticsAdmission.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/YSSmartSearchDetector.php',
    'src/Frontend/YSSsResultsPage.php',
    'src/Services/YSSsSearchService.php',
    'src/Frontend/YSSsShortcodes.php',
] as $source) {
    ysss_require_source($source);
}

function ysss_list_mode_product_row(): array
{
    return [
        'id' => 17,
        'title' => 'Quick Nova Jacket',
        'slug' => 'quick-nova-jacket',
        'sku' => 'NOVA-17',
        'price' => '1200',
        'sale_price' => '990',
        'image_url' => '',
    ];
}

ysss_test('list-mode quick submit receives one server-side authoritative analytics record', static function (): void {
    YSSsWpFake::reset();
    $_GET = ['ys_ec_search' => 'Quick Nova'];
    $_SERVER['REQUEST_TIME_FLOAT'] = 1787750000.125;
    $GLOBALS['wpdb']->resultSets = [[ysss_list_mode_product_row()]];

    YSSsShortcodes::maybe_log_list_search();

    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), 'Quick list-mode submit was not recorded');
    $stored = $GLOBALS['wpdb']->inserts[0]['data'] ?? [];
    ysss_assert_same('quick nova', $stored['query_norm'] ?? null);
    ysss_assert_same(1, $stored['has_results'] ?? null, 'Server fallback did not use actual product authority');
    ysss_assert_same(1, $stored['results_total'] ?? null);
});

ysss_test('proved client-owned list submit does not receive a duplicate page record', static function (): void {
    YSSsWpFake::reset();
    $_GET = ['ys_ec_search' => 'Quick Nova', 'ys_ss_client_logged' => '1'];
    YSSsShortcodes::maybe_log_list_search();
    ysss_assert_same([], $GLOBALS['wpdb']->inserts);
    ysss_assert_same([], array_values(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'ys_ec_products')
    )), 'Client-owned submit still executed fallback product search');
});

ysss_test('malicious list-mode ingress never reaches fallback search or analytics', static function (): void {
    YSSsWpFake::reset();
    $_GET = ['ys_ec_search' => "'; DROP TABLE wp_users; --"];
    YSSsShortcodes::maybe_log_list_search();
    ysss_assert_same([], $GLOBALS['wpdb']->inserts);
    ysss_assert_same([], array_values(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'ys_ec_products')
    )));
});

ysss_test('non-scalar list-mode ingress fails neutral before search or analytics', static function (): void {
    YSSsWpFake::reset();
    $_GET = ['ys_ec_search' => ['Quick Nova']];
    YSSsShortcodes::maybe_log_list_search();
    ysss_assert_same([], $GLOBALS['wpdb']->inserts);
    ysss_assert_same([], array_values(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'ys_ec_products')
    )));
});

$_GET = [];

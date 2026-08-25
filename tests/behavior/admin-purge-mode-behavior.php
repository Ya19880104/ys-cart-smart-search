<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Api\YSSsAdminController;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/Database/YSSsKeywordRepository.php',
    'src/Services/YSSsSuggestService.php',
    'src/Api/YSSsAdminController.php',
] as $source) {
    ysss_require_source($source);
}

$adminMutationExceptionPath = ysss_source_path('src/Database/YSSsAnalyticsMutationException.php');
if (is_file($adminMutationExceptionPath)) {
    require_once $adminMutationExceptionPath;
}

ysss_test('purge mode is required and unknown modes never fall through to expired purge', static function (): void {
    foreach ([[], ['mode' => 'unknown'], ['mode' => 'EXPIRED'], ['mode' => ['expired']]] as $params) {
        YSSsWpFake::reset();
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];
            return true;
        });
        try {
            $result = (new YSSsAdminController())->purge(new WP_REST_Request($params));
        } finally {
            restore_error_handler();
        }
        ysss_assert_true($result instanceof WP_Error);
        ysss_assert_same('ys_ss_bad_purge_mode', $result->get_error_code());
        ysss_assert_same(400, $result->get_error_data()['status'] ?? null);
        ysss_assert_same([], $warnings, 'Malformed purge mode emitted a PHP warning');
        ysss_assert_same([], $GLOBALS['wpdb']->queries, 'Unknown mode executed database work');
    }
});

ysss_test('legacy injection purge is retired with 409 and zero database work', static function (): void {
    YSSsWpFake::reset();
    $result = (new YSSsAdminController())->purge(new WP_REST_Request(['mode' => 'injection']));
    ysss_assert_true($result instanceof WP_Error);
    ysss_assert_same('ys_ss_preview_required', $result->get_error_code());
    ysss_assert_same(409, $result->get_error_data()['status'] ?? null);
    ysss_assert_same([], $GLOBALS['wpdb']->queries, 'Retired injection mode still touched analytics tables');
});

ysss_test('exact term route preserves REST-ready bytes without lossy sanitation', static function (): void {
    YSSsWpFake::reset();
    $controller = new YSSsAdminController();
    $controller->register_routes();
    $route = YSSsWpFake::$routes['ys-ecommerce-headless/v1/admin/smart-search/term'] ?? [];
    ysss_assert_false(isset($route['args']['term']['sanitize_callback']), 'Route still applies a lossy term sanitizer');

    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_')) {
            return 1;
        }
        return 1;
    };
    $result = $controller->delete_term(new WP_REST_Request(['term' => 'C:\\Docs\\<vector>']));
    ysss_assert_true($result instanceof WP_REST_Response);
    $deleteSql = implode("\n", array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM wp_ys_ss_')
    ));
    ysss_assert_contains("c:\\docs\\<vector>", $deleteSql, 'Exact term bytes were altered before prepared DELETE');
});

ysss_test('exact delete lock failure returns a sanitized admin error', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->last_error = 'SECRET SQL backend detail';
    $GLOBALS['wpdb']->getVarHandler = static fn(string $sql): int => str_contains($sql, 'GET_LOCK') ? 0 : 1;
    $result = (new YSSsAdminController())->delete_term(new WP_REST_Request(['term' => 'Nova']));
    ysss_assert_true($result instanceof WP_Error);
    ysss_assert_same('ys_ss_analytics_busy', $result->get_error_code());
    ysss_assert_same(409, $result->get_error_data()['status'] ?? null);
    ysss_assert_false(str_contains($result->get_error_message(), 'SECRET'));
    ysss_assert_false(str_contains(strtoupper($result->get_error_message()), 'SQL'));
});

ysss_test('exact delete database failure returns a sanitized 500 error', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->last_error = 'SECRET DELETE FROM wp_ys_ss_terms_daily';
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 2;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return false;
        }
        return 1;
    };
    $result = (new YSSsAdminController())->delete_term(new WP_REST_Request(['term' => 'Nova']));
    ysss_assert_true($result instanceof WP_Error);
    ysss_assert_same('ys_ss_analytics_mutation_failed', $result->get_error_code());
    ysss_assert_same(500, $result->get_error_data()['status'] ?? null);
    ysss_assert_false(str_contains($result->get_error_message(), 'SECRET'));
    ysss_assert_false(str_contains(strtoupper($result->get_error_message()), 'DELETE'));
    ysss_assert_false(isset(YSSsWpFake::$options['ys_ss_suggest_cache_generation']), 'Failed delete invalidated suggestions');
});

ysss_test('successful exact delete returns total and invalidates suggestions once', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 2;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return 1;
        }
        return 0;
    };
    $result = (new YSSsAdminController())->delete_term(new WP_REST_Request(['term' => 'Nova']));
    ysss_assert_true($result instanceof WP_REST_Response);
    ysss_assert_same(3, $result->get_data()['deleted']['total'] ?? null);
    $generationUpdates = array_values(array_filter(
        YSSsWpFake::$optionUpdates,
        static fn(array $update): bool => 'ys_ss_suggest_cache_generation' === $update['key']
    ));
    ysss_assert_same(1, count($generationUpdates), 'Successful delete did not invalidate exactly once');
    ysss_assert_true((bool) preg_match('/\A[a-f0-9]{32}\z/D', (string) ($generationUpdates[0]['value'] ?? '')), 'Successful delete did not issue a fresh cache epoch');
});

ysss_test('full purge failure is sanitized and does not invalidate suggestions', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->last_error = 'SECRET TRUNCATE or DELETE detail';
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 4;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return false;
        }
        return 0;
    };
    $result = (new YSSsAdminController())->purge(new WP_REST_Request(['mode' => 'all', 'confirm' => 'DELETE']));
    ysss_assert_true($result instanceof WP_Error);
    ysss_assert_same('ys_ss_analytics_mutation_failed', $result->get_error_code());
    ysss_assert_same(500, $result->get_error_data()['status'] ?? null);
    ysss_assert_false(str_contains($result->get_error_message(), 'SECRET'));
    ysss_assert_false(isset(YSSsWpFake::$options['ys_ss_suggest_cache_generation']), 'Failed full purge invalidated suggestions');
});

ysss_test('successful full purge commits and invalidates suggestions once', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static fn(string $sql): int|false => 0;
    $result = (new YSSsAdminController())->purge(new WP_REST_Request(['mode' => 'all', 'confirm' => 'DELETE']));
    ysss_assert_true($result instanceof WP_REST_Response);
    ysss_assert_same(true, $result->get_data()['ok'] ?? null);
    $generationUpdates = array_values(array_filter(
        YSSsWpFake::$optionUpdates,
        static fn(array $update): bool => 'ys_ss_suggest_cache_generation' === $update['key']
    ));
    ysss_assert_same(1, count($generationUpdates), 'Successful full purge did not invalidate exactly once');
    ysss_assert_true((bool) preg_match('/\A[a-f0-9]{32}\z/D', (string) ($generationUpdates[0]['value'] ?? '')), 'Successful full purge did not issue a fresh cache epoch');
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_contains('COMMIT', $sql);
    ysss_assert_false(str_contains($sql, 'TRUNCATE'));
});

ysss_test('expired purge database failure is sanitized and does not invalidate suggestions', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->last_error = 'SECRET expired cleanup detail';
    $GLOBALS['wpdb']->queryHandler = static fn(string $sql): int|false => str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries') ? false : 0;
    $result = (new YSSsAdminController())->purge(new WP_REST_Request(['mode' => 'expired']));
    ysss_assert_true($result instanceof WP_Error);
    ysss_assert_same('ys_ss_analytics_mutation_failed', $result->get_error_code());
    ysss_assert_same(500, $result->get_error_data()['status'] ?? null);
    ysss_assert_false(str_contains($result->get_error_message(), 'SECRET'));
    ysss_assert_false(isset(YSSsWpFake::$options['ys_ss_suggest_cache_generation']), 'Failed expired purge invalidated suggestions');
});

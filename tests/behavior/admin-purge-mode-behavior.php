<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Api\YSSsAdminController;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Services\YSSsSuggestService;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/Database/YSSsKeywordRepository.php',
    'src/Services/YSSsSuggestService.php',
    'src/Frontend/YSSsResultsPage.php',
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
    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, $result->get_data()['cache_status'] ?? null);
    ysss_assert_false(isset($result->get_data()['cache_warning']));
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
    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, $result->get_data()['cache_status'] ?? null);
    ysss_assert_false(isset($result->get_data()['cache_warning']));
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

ysss_test('idempotent settings save succeeds only when normalized readback matches', static function (): void {
    YSSsWpFake::reset();
    YSSsWpFake::$options[YSSsSettings::OPTION] = YSSsSettings::all();
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload): bool {
        if (YSSsSettings::OPTION === $key) {
            return false;
        }
        YSSsWpFake::$options[$key] = $value;
        return true;
    };

    $result = (new YSSsAdminController())->settings_save(new WP_REST_Request(['suggest_count' => 8]));
    ysss_assert_true($result instanceof WP_REST_Response);
    ysss_assert_same(8, $result->get_data()['settings']['suggest_count'] ?? null);
    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, $result->get_data()['cache_status'] ?? null);
    $generationWrites = array_values(array_filter(
        YSSsWpFake::$optionUpdateCalls,
        static fn(array $call): bool => 'ys_ss_suggest_cache_generation' === $call['key']
    ));
    ysss_assert_same(1, count($generationWrites), 'Matching settings readback did not invalidate exactly once');
});

ysss_test('first page-mode save settles on the synchronously provisioned authoritative page', static function (): void {
    YSSsWpFake::reset();
    YSSsWpFake::$options[YSSsSettings::OPTION] = YSSsSettings::all();
    YSSsWpFake::$posts[731] = new WP_Post(731, 'page', 'publish', '[ys_ss_search_results]');
    $provisioned = false;
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload) use (&$provisioned): bool {
        YSSsWpFake::$options[$key] = $value;
        if (YSSsSettings::OPTION === $key
            && !$provisioned
            && 'page' === ($value['results_mode'] ?? null)
            && 0 === ($value['results_page_id'] ?? null)) {
            $provisioned = true;
            YSSsSettings::update(['results_page_id' => 731]);
        }
        return true;
    };

    $result = (new YSSsAdminController())->settings_save(new WP_REST_Request(['results_mode' => 'page']));

    ysss_assert_true($result instanceof WP_REST_Response);
    ysss_assert_true($provisioned, 'The fixture did not execute synchronous nested provisioning');
    ysss_assert_same('page', $result->get_data()['settings']['results_mode'] ?? null);
    ysss_assert_same(731, $result->get_data()['settings']['results_page_id'] ?? null);
    ysss_assert_same(731, YSSsSettings::all()['results_page_id'] ?? null, 'Response was not final storage authority');
    $generationWrites = array_values(array_filter(
        YSSsWpFake::$optionUpdateCalls,
        static fn(array $call): bool => 'ys_ss_suggest_cache_generation' === $call['key']
    ));
    ysss_assert_same(1, count($generationWrites), 'Nested page provisioning invalidated before final settlement or more than once');
});

ysss_test('page-mode save fails closed when final storage has no contract-complete result page', static function (): void {
    YSSsWpFake::reset();
    YSSsWpFake::$options[YSSsSettings::OPTION] = YSSsSettings::all();

    $result = (new YSSsAdminController())->settings_save(new WP_REST_Request(['results_mode' => 'page']));

    ysss_assert_true($result instanceof WP_Error);
    ysss_assert_same('ys_ss_settings_write_failed', $result->get_error_code());
    ysss_assert_same(500, $result->get_error_data()['status'] ?? null);
    ysss_assert_same([], YSSsWpFake::$optionAdds, 'Contract-incomplete settings invalidated suggestions');
    $generationWrites = array_values(array_filter(
        YSSsWpFake::$optionUpdateCalls,
        static fn(array $call): bool => 'ys_ss_suggest_cache_generation' === $call['key']
    ));
    ysss_assert_same([], $generationWrites, 'Contract-incomplete settings rotated cache');
});

ysss_test('idempotent page-mode save with a valid page remains successful', static function (): void {
    YSSsWpFake::reset();
    $stored = YSSsSettings::all();
    $stored['results_mode'] = 'page';
    $stored['results_page_id'] = 732;
    YSSsWpFake::$options[YSSsSettings::OPTION] = $stored;
    YSSsWpFake::$posts[732] = new WP_Post(732, 'page', 'publish', '[ys_ss_search_results]');
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload): bool {
        if (YSSsSettings::OPTION === $key) {
            return false;
        }
        YSSsWpFake::$options[$key] = $value;
        return true;
    };

    $result = (new YSSsAdminController())->settings_save(new WP_REST_Request([
        'results_mode' => 'page',
        'results_page_id' => 732,
    ]));

    ysss_assert_true($result instanceof WP_REST_Response);
    ysss_assert_same(732, $result->get_data()['settings']['results_page_id'] ?? null);
    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, $result->get_data()['cache_status'] ?? null);
});

ysss_test('mismatching settings readback returns fixed 500 with zero invalidation', static function (): void {
    YSSsWpFake::reset();
    YSSsWpFake::$options[YSSsSettings::OPTION] = YSSsSettings::all();
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload): bool {
        if (YSSsSettings::OPTION === $key) {
            return false;
        }
        YSSsWpFake::$options[$key] = $value;
        return true;
    };

    $result = (new YSSsAdminController())->settings_save(new WP_REST_Request(['suggest_count' => 17]));
    ysss_assert_true($result instanceof WP_Error);
    ysss_assert_same('ys_ss_settings_write_failed', $result->get_error_code());
    ysss_assert_same(500, $result->get_error_data()['status'] ?? null);
    ysss_assert_false(str_contains($result->get_error_message(), 'SECRET'));
    ysss_assert_same([], YSSsWpFake::$optionAdds, 'Mismatching settings readback created a cache marker');
    $generationWrites = array_values(array_filter(
        YSSsWpFake::$optionUpdateCalls,
        static fn(array $call): bool => 'ys_ss_suggest_cache_generation' === $call['key']
    ));
    ysss_assert_same([], $generationWrites, 'Mismatching settings readback rotated cache');
});

ysss_test('exact delete committed payload survives total cache-authority failure', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 2;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return 1;
        }
        return 1;
    };
    YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    $result = (new YSSsAdminController())->delete_term(new WP_REST_Request(['term' => 'Nova']));
    ysss_assert_true($result instanceof WP_REST_Response);
    ysss_assert_same(3, $result->get_data()['deleted']['total'] ?? null);
    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, $result->get_data()['cache_status'] ?? null);
    ysss_assert_same('資料已更新，但熱門建議快取可能延遲更新。', $result->get_data()['cache_warning'] ?? null);
});

ysss_test('expired purge committed counts survive total cache-authority failure', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 2;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return 1;
        }
        return 1;
    };
    YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    $result = (new YSSsAdminController())->purge(new WP_REST_Request(['mode' => 'expired']));
    ysss_assert_true($result instanceof WP_REST_Response);
    ysss_assert_same(3, $result->get_data()['deleted'] ?? null);
    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, $result->get_data()['cache_status'] ?? null);
    ysss_assert_same('資料已更新，但熱門建議快取可能延遲更新。', $result->get_data()['cache_warning'] ?? null);
});

ysss_test('full purge committed result survives total cache-authority failure', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static fn(string $sql): int|false => 0;
    YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    $result = (new YSSsAdminController())->purge(new WP_REST_Request(['mode' => 'all', 'confirm' => 'DELETE']));
    ysss_assert_true($result instanceof WP_REST_Response);
    ysss_assert_same(true, $result->get_data()['ok'] ?? null);
    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, $result->get_data()['cache_status'] ?? null);
    ysss_assert_same('資料已更新，但熱門建議快取可能延遲更新。', $result->get_data()['cache_warning'] ?? null);
});

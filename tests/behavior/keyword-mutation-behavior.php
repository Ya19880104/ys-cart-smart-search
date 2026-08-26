<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Api\YSSsAdminController;
use YangSheep\SmartSearch\Services\YSSsSuggestService;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsAnalyticsMutationException.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/Database/YSSsKeywordRepository.php',
    'src/Services/YSSsSuggestService.php',
    'src/Api/YSSsAdminController.php',
] as $source) {
    ysss_require_source($source);
}

/** @return array<string,mixed> */
function ysss_keyword_item(int $id, string $keyword, int $sort = 0, bool $active = true): array
{
    return ['id' => $id, 'keyword' => $keyword, 'sort_order' => $sort, 'is_active' => $active];
}

function ysss_keyword_assert_one_invalidation(string $expectedStatus, WP_REST_Response $response): void
{
    $generationWrites = array_values(array_filter(
        YSSsWpFake::$optionUpdateCalls,
        static fn(array $call): bool => 'ys_ss_suggest_cache_generation' === $call['key']
    ));
    ysss_assert_same(1, count($generationWrites), 'Mutation did not invalidate exactly once');
    ysss_assert_same($expectedStatus, $response->get_data()['cache_status'] ?? null);
}

function ysss_keyword_assert_fixed_write_error(mixed $result): void
{
    ysss_assert_true($result instanceof WP_Error);
    ysss_assert_same('ys_ss_keyword_write_failed', $result->get_error_code());
    ysss_assert_same(500, $result->get_error_data()['status'] ?? null);
    ysss_assert_false(str_contains($result->get_error_message(), 'SECRET'));
    ysss_assert_false(str_contains(strtoupper($result->get_error_message()), 'SQL'));
    ysss_assert_same([], YSSsWpFake::$optionAdds, 'Failed repository mutation invalidated cache');
    ysss_assert_same([], YSSsWpFake::$optionUpdateCalls, 'Failed repository mutation rotated cache');
}

ysss_test('keyword create stores approved technical bytes and returns authoritative items', static function (): void {
    YSSsWpFake::reset();
    $term = 'C++ <vector> 入門';
    $GLOBALS['wpdb']->insertHandler = static function (string $table, array $data, YSSsFakeWpdb $db): int {
        $db->insert_id = 41;
        return 1;
    };
    $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(41, $term, 7)]];

    $response = (new YSSsAdminController())->keywords_create(new WP_REST_Request([
        'keyword' => $term,
        'sort_order' => 7,
    ]));
    ysss_assert_true($response instanceof WP_REST_Response);
    ysss_assert_same($term, $GLOBALS['wpdb']->inserts[0]['data']['keyword'] ?? null, 'Technical bytes were corrupted before insert');
    ysss_assert_same(41, $response->get_data()['id'] ?? null);
    ysss_assert_same([ysss_keyword_item(41, $term, 7, true)], $response->get_data()['items'] ?? null);
    ysss_keyword_assert_one_invalidation(YSSsSuggestService::INVALIDATION_ROTATED, $response);
    ysss_assert_false(isset($response->get_data()['cache_warning']), 'Rotated response emitted a warning');
});

ysss_test('keyword update stores approved technical bytes exactly', static function (): void {
    YSSsWpFake::reset();
    $term = 'C++ <vector> 入門';
    $GLOBALS['wpdb']->updateHandler = static fn(string $table, array $data, array $where, YSSsFakeWpdb $db): int => 1;
    $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(9, $term, 0)]];

    $response = (new YSSsAdminController())->keywords_update(new WP_REST_Request([
        'id' => 9,
        'keyword' => $term,
    ]));
    ysss_assert_true($response instanceof WP_REST_Response);
    ysss_assert_same($term, $GLOBALS['wpdb']->updates[0]['data']['keyword'] ?? null, 'Technical bytes were corrupted before update');
    ysss_assert_same([ysss_keyword_item(9, $term)], $response->get_data()['items'] ?? null);
    ysss_keyword_assert_one_invalidation(YSSsSuggestService::INVALIDATION_ROTATED, $response);
});

ysss_test('invalid keyword inputs and an unrecognized patch perform zero write and invalidation work', static function (): void {
    $cases = [
        ['method' => 'create', 'params' => ['keyword' => '']],
        ['method' => 'create', 'params' => ['keyword' => '{{7*7}}']],
        ['method' => 'create', 'params' => ['keyword' => ['nova']]],
        ['method' => 'update', 'params' => ['id' => 3, 'keyword' => '']],
        ['method' => 'update', 'params' => ['id' => 3, 'keyword' => '{{7*7}}']],
        ['method' => 'update', 'params' => ['id' => 3, 'keyword' => ['nova']]],
        ['method' => 'update', 'params' => ['id' => 3]],
        ['method' => 'update', 'params' => ['id' => 3, 'keyword' => ['bad'], 'sort_order' => 0, 'is_active' => false]],
    ];

    foreach ($cases as $case) {
        YSSsWpFake::reset();
        $controller = new YSSsAdminController();
        $result = 'create' === $case['method']
            ? $controller->keywords_create(new WP_REST_Request($case['params']))
            : $controller->keywords_update(new WP_REST_Request($case['params']));
        ysss_assert_true($result instanceof WP_Error);
        ysss_assert_same('ys_ss_invalid_keyword', $result->get_error_code());
        ysss_assert_same(400, $result->get_error_data()['status'] ?? null);
        ysss_assert_same([], $GLOBALS['wpdb']->inserts);
        ysss_assert_same([], $GLOBALS['wpdb']->updates);
        ysss_assert_same([], $GLOBALS['wpdb']->deletes);
        ysss_assert_same([], YSSsWpFake::$optionAdds);
        ysss_assert_same([], YSSsWpFake::$optionUpdateCalls);
    }
});

ysss_test('sort zero and inactive false are recognized keyword patches', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->updateHandler = static fn(string $table, array $data, array $where, YSSsFakeWpdb $db): int => 0;
    $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(3, 'nova', 0, false)]];
    $response = (new YSSsAdminController())->keywords_update(new WP_REST_Request([
        'id' => 3,
        'sort_order' => 0,
        'is_active' => false,
    ]));
    ysss_assert_true($response instanceof WP_REST_Response);
    ysss_assert_same(['sort_order' => 0, 'is_active' => 0], $GLOBALS['wpdb']->updates[0]['data'] ?? null);
    ysss_keyword_assert_one_invalidation(YSSsSuggestService::INVALIDATION_ROTATED, $response);
});

ysss_test('failed insert clears stale insert id and never reads items or invalidates', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->insert_id = 987654;
    $GLOBALS['wpdb']->last_error = 'SECRET SQL insert detail';
    $GLOBALS['wpdb']->insertHandler = static function (string $table, array $data, YSSsFakeWpdb $db): false {
        $db->insert_id = 555;
        return false;
    };
    $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(555, 'stale')]];

    $result = (new YSSsAdminController())->keywords_create(new WP_REST_Request(['keyword' => 'nova']));
    ysss_keyword_assert_fixed_write_error($result);
    ysss_assert_same(0, $GLOBALS['wpdb']->insert_id, 'Failed insert retained a stale positive insert id');
    ysss_assert_same(1, count($GLOBALS['wpdb']->resultSets), 'Failed insert called all() and could masquerade as success');
});

ysss_test('failed keyword update returns fixed 500 without all or invalidation', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->last_error = 'SECRET SQL update detail';
    $GLOBALS['wpdb']->updateHandler = static fn(string $table, array $data, array $where, YSSsFakeWpdb $db): false => false;
    $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(2, 'stale')]];
    $result = (new YSSsAdminController())->keywords_update(new WP_REST_Request(['id' => 2, 'keyword' => 'nova']));
    ysss_keyword_assert_fixed_write_error($result);
    ysss_assert_same(1, count($GLOBALS['wpdb']->resultSets), 'Failed update called all()');
});

ysss_test('failed keyword delete returns fixed 500 without all or invalidation', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->last_error = 'SECRET SQL delete detail';
    $GLOBALS['wpdb']->deleteHandler = static fn(string $table, array $where, YSSsFakeWpdb $db): false => false;
    $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(2, 'stale')]];
    $result = (new YSSsAdminController())->keywords_delete(new WP_REST_Request(['id' => 2]));
    ysss_keyword_assert_fixed_write_error($result);
    ysss_assert_same(1, count($GLOBALS['wpdb']->resultSets), 'Failed delete called all()');
});

ysss_test('zero-row keyword update and delete are SQL successes with authoritative items', static function (): void {
    foreach (['update', 'delete'] as $operation) {
        YSSsWpFake::reset();
        $GLOBALS['wpdb']->updateHandler = static fn(string $table, array $data, array $where, YSSsFakeWpdb $db): int => 0;
        $GLOBALS['wpdb']->deleteHandler = static fn(string $table, array $where, YSSsFakeWpdb $db): int => 0;
        $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(8, 'confirmed')]];
        $controller = new YSSsAdminController();
        $response = 'update' === $operation
            ? $controller->keywords_update(new WP_REST_Request(['id' => 8, 'sort_order' => 0]))
            : $controller->keywords_delete(new WP_REST_Request(['id' => 999]));
        ysss_assert_true($response instanceof WP_REST_Response);
        ysss_assert_same([ysss_keyword_item(8, 'confirmed')], $response->get_data()['items'] ?? null);
        ysss_keyword_assert_one_invalidation(YSSsSuggestService::INVALIDATION_ROTATED, $response);
    }
});

ysss_test('committed keyword mutation reports failed cache authority with one fixed warning', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->insertHandler = static function (string $table, array $data, YSSsFakeWpdb $db): int {
        $db->insert_id = 12;
        return 1;
    };
    $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(12, 'nova')]];
    YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    $response = (new YSSsAdminController())->keywords_create(new WP_REST_Request(['keyword' => 'nova']));
    ysss_assert_true($response instanceof WP_REST_Response);
    $data = $response->get_data();
    ysss_assert_same(12, $data['id'] ?? null, 'Committed create payload was lost');
    ysss_assert_same([ysss_keyword_item(12, 'nova')], $data['items'] ?? null);
    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, $data['cache_status'] ?? null);
    ysss_assert_same('資料已更新，但熱門建議快取可能延遲更新。', $data['cache_warning'] ?? null);
    ysss_assert_same(1, substr_count(json_encode($data, JSON_UNESCAPED_UNICODE), '資料已更新，但熱門建議快取可能延遲更新。'));
});

ysss_test('durable bypass is committed success without a warning', static function (): void {
    YSSsWpFake::reset();
    $generation = 'mutation-old';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    YSSsWpFake::$options[$marker] = $generation;
    $GLOBALS['wpdb']->deleteHandler = static fn(string $table, array $where, YSSsFakeWpdb $db): int => 1;
    $GLOBALS['wpdb']->resultSets = [[]];
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    $response = (new YSSsAdminController())->keywords_delete(new WP_REST_Request(['id' => 2]));
    ysss_assert_true($response instanceof WP_REST_Response);
    ysss_assert_same(YSSsSuggestService::INVALIDATION_BYPASS_FRESH, $response->get_data()['cache_status'] ?? null);
    ysss_assert_false(isset($response->get_data()['cache_warning']), 'Bypass response emitted a warning');
});

ysss_test('unexpected invalidation throwable is folded into committed failed cache status', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->insertHandler = static function (string $table, array $data, YSSsFakeWpdb $db): int {
        $db->insert_id = 14;
        return 1;
    };
    $GLOBALS['wpdb']->resultSets = [[ysss_keyword_item(14, 'nova')]];
    YSSsWpFake::$addOptionHandler = static function (string $key, mixed $value, string $deprecated, mixed $autoload): bool {
        throw new RuntimeException('SECRET option backend');
    };

    $response = (new YSSsAdminController())->keywords_create(new WP_REST_Request(['keyword' => 'nova']));
    ysss_assert_true($response instanceof WP_REST_Response);
    $data = $response->get_data();
    ysss_assert_same(14, $data['id'] ?? null);
    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, $data['cache_status'] ?? null);
    ysss_assert_same('資料已更新，但熱門建議快取可能延遲更新。', $data['cache_warning'] ?? null);
    ysss_assert_false(str_contains(json_encode($data, JSON_UNESCAPED_UNICODE), 'SECRET'));
});

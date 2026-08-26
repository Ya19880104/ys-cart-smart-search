<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Frontend\YSSsResultsPage;
use YangSheep\SmartSearch\Services\YSSsSearchService;

foreach ([
    'src/Support/YSSsText.php',
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Security/YSSsRateLimiter.php',
    'src/Analytics/YSSsAnalyticsAdmission.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/YSSmartSearchDetector.php',
    'src/Frontend/YSSsShortcodes.php',
    'src/Frontend/YSSsResultsPage.php',
    'src/Services/YSSsSearchService.php',
] as $source) {
    ysss_require_source($source);
}

/** @return array<string,mixed> */
function ysss_pagination_product_row(int $id): array
{
    return [
        'id' => $id,
        'title' => 'Nova Product ' . $id,
        'slug' => 'nova-' . $id,
        'sku' => 'NOVA-' . $id,
        'price' => 1000 + $id,
        'sale_price' => 900 + $id,
        'image_url' => 'https://example.test/media/nova-' . $id . '.jpg',
    ];
}

/** @return array<string,string> */
function ysss_pagination_cached_product_item(): array
{
    return [
        'title' => 'Cached Nova',
        'url' => 'https://example.test/shop/?product=cached-nova',
        'image' => 'https://example.test/media/cached-nova.jpg',
        'price' => 'NT$900',
        'price_original' => 'NT$1,000',
        'sku' => 'CACHED-NOVA',
    ];
}

/**
 * @param list<string> $groupOrder
 * @param list<array<string,mixed>> $categoryRows
 * @param list<mixed> $postRows
 */
function ysss_pagination_fixture(
    int $productTotal,
    array $groupOrder = ['products'],
    array $categoryRows = [],
    array $postRows = []
): YSSsFakeWpdb {
    YSSsWpFake::reset();

    $settings = YSSsSettings::defaults();
    $settings['group_order'] = $groupOrder;
    $settings['results_mode'] = 'page';
    $settings['products']['page_limit'] = 24;
    $settings['products']['show_image'] = true;
    $settings['products']['show_price'] = true;
    $settings['products']['show_sku'] = true;
    $settings['categories']['enabled'] = in_array('categories', $groupOrder, true);
    $settings['categories']['show_count'] = false;
    $settings['posts']['enabled'] = in_array('posts', $groupOrder, true);
    $settings['posts']['show_thumb'] = false;
    $settings['posts']['excerpt_len'] = 0;
    YSSsWpFake::$options[YSSsSettings::OPTION] = $settings;
    YSSsWpFake::$wpQueryPosts = $postRows;

    /** @var YSSsFakeWpdb $db */
    $db = $GLOBALS['wpdb'];
    $db->getVarHandler = static function (string $sql) use ($productTotal): mixed {
        if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+wp_ys_ec_products/i', $sql)) {
            return $productTotal;
        }
        if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
            return 1;
        }
        if (str_contains($sql, 'SELECT 1 FROM wp_ys_ss_queries')) {
            return 0;
        }
        return 0;
    };
    $db->getResultsHandler = static function (string $sql, mixed $output) use ($productTotal, $categoryRows): array {
        if (preg_match('/SELECT\s+id,\s*title,\s*slug,\s*sku,\s*price,\s*sale_price,\s*image_url\s+FROM\s+wp_ys_ec_products/is', $sql)) {
            $limit = 24;
            $offset = 0;
            if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $match)) {
                $limit = (int) $match[1];
                $offset = (int) $match[2];
            }
            $rows = [];
            $last = min($productTotal, $offset + $limit);
            for ($id = $offset + 1; $id <= $last; ++$id) {
                $rows[] = ysss_pagination_product_row($id);
            }
            return $rows;
        }
        if (str_contains($sql, 'FROM wp_ys_ec_categories')) {
            return $categoryRows;
        }
        if (str_contains($sql, 'FROM wp_ys_ec_product_categories')) {
            return [];
        }
        return [];
    };

    return $db;
}

/** @return list<string> */
function ysss_pagination_count_sql(YSSsFakeWpdb $db): array
{
    return array_values(array_filter(
        $db->queries,
        static fn(string $sql): bool => 1 === preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+wp_ys_ec_products/i', $sql)
    ));
}

/** @return list<string> */
function ysss_pagination_row_sql(YSSsFakeWpdb $db): array
{
    return array_values(array_filter(
        $db->queries,
        static fn(string $sql): bool => 1 === preg_match('/SELECT\s+id,\s*title,\s*slug,\s*sku,\s*price,\s*sale_price,\s*image_url\s+FROM\s+wp_ys_ec_products/is', $sql)
    ));
}

/** @return list<string> */
function ysss_pagination_group_types(array $groups): array
{
    return array_values(array_map(
        static fn(array $group): string => (string) ($group['type'] ?? ''),
        $groups
    ));
}

/** @param array<string,mixed> $settings */
function ysss_pagination_cache_key(string $norm, int $page, array $settings): string
{
    return 'ys_ss_sp_' . md5(YS_SMART_SEARCH_VERSION . '|' . $norm . '|' . $page . '|' . (string) wp_json_encode([
        $settings['group_order'],
        $settings['products'],
        $settings['categories'],
        $settings['posts'],
    ]));
}

function ysss_pagination_product_where(string $sql): string
{
    if (1 !== preg_match("/WHERE\s+status\s*=\s*'publish'\s+AND\s+(.+?)(?:\s+ORDER\s+BY|\s*$)/is", $sql, $match)) {
        throw new RuntimeException('Could not extract product WHERE clause');
    }
    return trim((string) preg_replace('/\s+/', ' ', $match[1]));
}

ysss_test('deep request resolves to the last real product page before selecting rows', static function (): void {
    $db = ysss_pagination_fixture(25);
    $result = YSSsSearchService::search_page('nova', 99);

    ysss_assert_same(2, $result['page'] ?? null);
    ysss_assert_same(2, $result['total_pages'] ?? null);
    ysss_assert_same(25, $result['products_total'] ?? null);
    ysss_assert_same(['products'], ysss_pagination_group_types($result['groups'] ?? []));
    ysss_assert_same('Nova Product 25', $result['groups'][0]['items'][0]['title'] ?? null);
    ysss_assert_same('NOVA-25', $result['groups'][0]['items'][0]['sku'] ?? null);
    ysss_assert_same('https://example.test/shop/?product=nova-25', $result['groups'][0]['items'][0]['url'] ?? null);
    ysss_assert_same('https://example.test/media/nova-25.jpg', $result['groups'][0]['items'][0]['image'] ?? null);
    ysss_assert_same('NT$925', $result['groups'][0]['items'][0]['price'] ?? null);
    ysss_assert_same('NT$1,025', $result['groups'][0]['items'][0]['price_original'] ?? null);

    $counts = ysss_pagination_count_sql($db);
    $rows = ysss_pagination_row_sql($db);
    ysss_assert_same(1, count($counts), 'Expected exactly one product COUNT');
    ysss_assert_same(1, count($rows), 'Expected exactly one product row query');
    ysss_assert_contains('LIMIT 24 OFFSET 24', $rows[0]);
    ysss_assert_true(
        array_search($counts[0], $db->queries, true) < array_search($rows[0], $db->queries, true),
        'Product rows were selected before page authority was counted'
    );
});

ysss_test('an exact second page retains offset 24 when total is 48', static function (): void {
    $db = ysss_pagination_fixture(48);
    $result = YSSsSearchService::search_page('nova', 2);

    ysss_assert_same(2, $result['page'] ?? null);
    ysss_assert_same(2, $result['total_pages'] ?? null);
    ysss_assert_same(48, $result['products_total'] ?? null);
    $rows = ysss_pagination_row_sql($db);
    ysss_assert_same(1, count(ysss_pagination_count_sql($db)));
    ysss_assert_same(1, count($rows));
    ysss_assert_contains('LIMIT 24 OFFSET 24', $rows[0]);
    ysss_assert_same('Nova Product 25', $result['groups'][0]['items'][0]['title'] ?? null);
});

ysss_test('zero products resolve a huge request to page one without a huge row query', static function (): void {
    $db = ysss_pagination_fixture(0);
    $result = YSSsSearchService::search_page('nova', 99);

    ysss_assert_same(1, $result['page'] ?? null);
    ysss_assert_same(1, $result['total_pages'] ?? null);
    ysss_assert_same(0, $result['products_total'] ?? null);
    ysss_assert_same(1, count(ysss_pagination_count_sql($db)));
    ysss_assert_same(0, count(ysss_pagination_row_sql($db)), 'Zero product total still selected product rows');
});

ysss_test('nonpositive service page inputs clamp to page one and offset zero', static function (): void {
    foreach ([0, -7] as $requested) {
        $db = ysss_pagination_fixture(25);
        $result = YSSsSearchService::search_page('nova', $requested);
        ysss_assert_same(1, $result['page'] ?? null, 'Nonpositive page did not resolve to one');
        $rows = ysss_pagination_row_sql($db);
        ysss_assert_same(1, count($rows));
        ysss_assert_contains('LIMIT 24 OFFSET 0', $rows[0]);
    }
});

ysss_test('the visible-page safety cap resolves request 101 to page 100', static function (): void {
    $db = ysss_pagination_fixture(5000);
    $result = YSSsSearchService::search_page('nova', 101);

    ysss_assert_same(100, $result['page'] ?? null);
    ysss_assert_same(100, $result['total_pages'] ?? null);
    ysss_assert_same(5000, $result['products_total'] ?? null);
    $rows = ysss_pagination_row_sql($db);
    ysss_assert_same(1, count($rows));
    ysss_assert_contains('LIMIT 24 OFFSET 2376', $rows[0]);
    ysss_assert_same('Nova Product 2377', $result['groups'][0]['items'][0]['title'] ?? null);
});

ysss_test('products-last order still resolves category and post visibility from product count first', static function (): void {
    $db = ysss_pagination_fixture(
        0,
        ['categories', 'posts', 'products'],
        [['id' => 7, 'name' => 'Nova 分類', 'slug' => 'nova-category']],
        [41]
    );
    $result = YSSsSearchService::search_page('nova', 99);

    ysss_assert_same(1, $result['page'] ?? null);
    ysss_assert_same(1, $result['total_pages'] ?? null);
    ysss_assert_same(['categories', 'posts'], ysss_pagination_group_types($result['groups'] ?? []));
    ysss_assert_same(['categories', 'posts', 'products'], $result['content_types'] ?? null);
    ysss_assert_same('Nova 分類', $result['groups'][0]['items'][0]['title'] ?? null);
    ysss_assert_same(1, count(YSSsWpFake::$wpQueryArgs), 'Post group was not queried on the resolved first page');
    ysss_assert_same([
        's' => 'nova',
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'posts_per_page' => 12,
        'no_found_rows' => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ], YSSsWpFake::$wpQueryArgs[0] ?? null);
    ysss_assert_same(1, count(ysss_pagination_count_sql($db)));
    ysss_assert_same(0, count(ysss_pagination_row_sql($db)), 'Zero product total should not need a product row query');
});

ysss_test('COUNT and rows share asymmetric SKU/slug exclusions exactly', static function (): void {
    $db = ysss_pagination_fixture(25);
    YSSsWpFake::$options[YSSsSettings::OPTION]['products']['fields'] = ['sku', 'slug'];
    YSSsWpFake::$options[YSSsSettings::OPTION]['products']['exclude'] = 'skip-slug, 77';

    $result = YSSsSearchService::search_page('nova', 2);
    ysss_assert_same(2, $result['page'] ?? null);
    $counts = ysss_pagination_count_sql($db);
    $rows = ysss_pagination_row_sql($db);
    ysss_assert_same(1, count($counts));
    ysss_assert_same(1, count($rows));
    $countWhere = ysss_pagination_product_where($counts[0]);
    $rowWhere = ysss_pagination_product_where($rows[0]);
    ysss_assert_same($countWhere, $rowWhere, 'COUNT and row SELECT used different product authorities');
    ysss_assert_contains("sku LIKE '%nova%'", $countWhere);
    ysss_assert_contains("slug LIKE '%nova%'", $countWhere);
    ysss_assert_contains("slug NOT IN ('skip-slug')", $countWhere);
    ysss_assert_contains('id NOT IN (77)', $countWhere);
    ysss_assert_false(str_contains($countWhere, 'title LIKE'), 'Disabled product title field leaked into WHERE');
});

ysss_test('legacy deep-page poison is ignored and one canonical page-two cache is shared', static function (): void {
    $db = ysss_pagination_fixture(25);
    $settings = YSSsSettings::all();
    $rawQuery = '  NOVA  ';
    $norm = YSSsQueryRepository::normalize($rawQuery);
    ysss_assert_same('nova', $norm);
    $legacyPage99Key = 'ys_ss_sp_' . md5($norm . '|99|' . (string) wp_json_encode([
        $settings['group_order'],
        $settings['products'],
        $settings['categories'],
        $settings['posts'],
    ]));
    $requestedPage99Key = ysss_pagination_cache_key($norm, 99, $settings);
    $canonicalPage2Key = ysss_pagination_cache_key($norm, 2, $settings);
    YSSsWpFake::$transients[$legacyPage99Key] = [
        'q' => $norm,
        'products_total' => 25,
        'page' => 99,
        'per_page' => 24,
        'total_pages' => 2,
        'groups' => [],
        'content_types' => ['products'],
        'poison' => true,
    ];

    $fromDeepRequest = YSSsSearchService::search_page($rawQuery, 99);
    ysss_assert_same('nova', $fromDeepRequest['q'] ?? null, 'Canonical payload did not expose normalized query');
    ysss_assert_same(2, $fromDeepRequest['page'] ?? null, 'Legacy poison controlled resolved page');
    ysss_assert_false(isset($fromDeepRequest['poison']), 'Legacy pre-v1.5.3 payload was returned');
    ysss_assert_same(1, count(YSSsWpFake::$transientSets), 'Deep request published more than one payload');
    $canonicalKey = YSSsWpFake::$transientSets[0]['key'] ?? '';
    ysss_assert_same($canonicalPage2Key, $canonicalKey, 'Canonical cache did not use exact versioned page-two identity');
    ysss_assert_same(2, YSSsWpFake::$transientSets[0]['value']['page'] ?? null);
    ysss_assert_false(
        in_array($legacyPage99Key, array_column(YSSsWpFake::$transientSets, 'key'), true),
        'A new page99 transient was published'
    );
    ysss_assert_same(
        [$requestedPage99Key, $canonicalPage2Key],
        array_column(YSSsWpFake::$transientGets, 'key'),
        'Deep miss did not consult only versioned requested then canonical keys'
    );
    ysss_assert_false(
        in_array($legacyPage99Key, array_column(YSSsWpFake::$transientGets, 'key'), true),
        'Legacy pre-v1.5.3 transient was consulted'
    );

    $countBefore = count(ysss_pagination_count_sql($db));
    $rowsBefore = count(ysss_pagination_row_sql($db));
    $fromCanonicalRequest = YSSsSearchService::search_page($rawQuery, 2);
    ysss_assert_same($fromDeepRequest, $fromCanonicalRequest, 'Canonical page request did not reuse identical payload');
    ysss_assert_same($countBefore, count(ysss_pagination_count_sql($db)), 'Canonical cache hit reran COUNT');
    ysss_assert_same($rowsBefore, count(ysss_pagination_row_sql($db)), 'Canonical cache hit reran product rows');
    ysss_assert_same(1, count(YSSsWpFake::$transientSets), 'Canonical cache hit republished payload');
    ysss_assert_same(
        [$requestedPage99Key, $canonicalPage2Key, $canonicalPage2Key],
        array_column(YSSsWpFake::$transientGets, 'key'),
        'Canonical request did not hit the exact page-two key'
    );
});

ysss_test('requested cache hits require a complete self-consistent canonical payload', static function (): void {
    $invalidPayloads = [
        'missing groups' => static function (array $payload): array {
            unset($payload['groups']);
            return $payload;
        },
        'wrong query' => static function (array $payload): array {
            $payload['q'] = 'other';
            return $payload;
        },
        'wrong page' => static function (array $payload): array {
            $payload['page'] = 1;
            return $payload;
        },
        'wrong per page' => static function (array $payload): array {
            $payload['per_page'] = 25;
            return $payload;
        },
        'string product total' => static function (array $payload): array {
            $payload['products_total'] = '25';
            return $payload;
        },
        'wrong total pages' => static function (array $payload): array {
            $payload['total_pages'] = 3;
            return $payload;
        },
        'non-array content types' => static function (array $payload): array {
            $payload['content_types'] = 'products';
            return $payload;
        },
        'associative content types' => static function (array $payload): array {
            $payload['content_types'] = ['primary' => 'products'];
            return $payload;
        },
        'unknown content type' => static function (array $payload): array {
            $payload['content_types'] = ['unknown'];
            return $payload;
        },
        'duplicate content type' => static function (array $payload): array {
            $payload['content_types'] = ['products', 'products'];
            return $payload;
        },
        'associative groups' => static function (array $payload): array {
            $payload['groups'] = ['primary' => $payload['groups'][0]];
            return $payload;
        },
        'unknown group type' => static function (array $payload): array {
            $payload['groups'][0]['type'] = 'unknown';
            return $payload;
        },
        'negative group total' => static function (array $payload): array {
            $payload['groups'][0]['total'] = -1;
            return $payload;
        },
        'non-array cached item' => static function (array $payload): array {
            $payload['groups'][0]['items'] = ['not-an-item'];
            return $payload;
        },
        'associative items' => static function (array $payload): array {
            $payload['groups'][0]['items'] = ['first' => ysss_pagination_cached_product_item()];
            return $payload;
        },
        'missing product item fields' => static function (array $payload): array {
            $payload['groups'][0]['items'] = [[]];
            return $payload;
        },
        'wrong product field type' => static function (array $payload): array {
            $item = ysss_pagination_cached_product_item();
            $item['title'] = [];
            $payload['groups'][0]['items'] = [$item];
            return $payload;
        },
        'negative category count' => static function (array $payload): array {
            $payload['groups'][0] = [
                'type' => 'categories',
                'label' => '分類',
                'total' => 1,
                'items' => [['title' => 'Nova 分類', 'url' => '/category/nova', 'count' => -1]],
            ];
            return $payload;
        },
        'wrong post field type' => static function (array $payload): array {
            $payload['groups'][0] = [
                'type' => 'posts',
                'label' => '文章',
                'total' => 1,
                'items' => [['title' => 'Nova Post', 'url' => '/post/nova', 'image' => '', 'excerpt' => []]],
            ];
            return $payload;
        },
        'negative product total' => static function (array $payload): array {
            $payload['products_total'] = -1;
            return $payload;
        },
    ];

    foreach ($invalidPayloads as $label => $mutate) {
        $db = ysss_pagination_fixture(25);
        $settings = YSSsSettings::all();
        $key = ysss_pagination_cache_key('nova', 2, $settings);
        $payload = [
            'q' => 'nova',
            'products_total' => 25,
            'page' => 2,
            'per_page' => 24,
            'total_pages' => 2,
            'groups' => [['type' => 'products', 'label' => '商品', 'total' => 25, 'items' => []]],
            'content_types' => ['products'],
        ];
        YSSsWpFake::$transients[$key] = $mutate($payload);

        $result = YSSsSearchService::search_page('nova', 2);
        ysss_assert_same(2, $result['page'] ?? null, "{$label} cache controlled the result page");
        ysss_assert_same(25, $result['products_total'] ?? null, "{$label} cache controlled product total");
        ysss_assert_same('Nova Product 25', $result['groups'][0]['items'][0]['title'] ?? null, "{$label} cache was returned");
        ysss_assert_same(1, count(ysss_pagination_count_sql($db)), "{$label} cache skipped rebuild COUNT");
        ysss_assert_same(1, count(ysss_pagination_row_sql($db)), "{$label} cache skipped row rebuild");
    }
});

ysss_test('malformed current-version product cache is rebuilt before real render', static function (): void {
    $db = ysss_pagination_fixture(1);
    $settings = YSSsSettings::all();
    $key = ysss_pagination_cache_key('nova', 1, $settings);
    YSSsWpFake::$transients[$key] = [
        'q' => 'nova',
        'products_total' => 1,
        'page' => 1,
        'per_page' => 24,
        'total_pages' => 1,
        'groups' => [[
            'type' => 'products',
            'label' => '商品',
            'total' => 1,
            'items' => [[]],
        ]],
        'content_types' => ['products'],
    ];
    $_GET['ys_ec_search'] = 'nova';
    $_GET['ys_ss_page'] = '1';

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    try {
        $html = YSSsResultsPage::render();
    } finally {
        restore_error_handler();
    }

    ysss_assert_same([], $warnings, 'Malformed cached item reached the renderer');
    ysss_assert_contains('Nova Product 1', $html, 'Malformed cache was not replaced by SQL-backed payload');
    ysss_assert_same(1, count(ysss_pagination_count_sql($db)), 'Malformed cache skipped repair COUNT');
    ysss_assert_same(1, count(ysss_pagination_row_sql($db)), 'Malformed cache skipped repair row SELECT');
    $pageCacheWrites = array_values(array_filter(
        YSSsWpFake::$transientSets,
        static fn(array $write): bool => $key === ($write['key'] ?? null)
    ));
    ysss_assert_same(1, count($pageCacheWrites), 'Repaired canonical payload was not published exactly once');
    ysss_assert_same($key, $pageCacheWrites[0]['key'] ?? null);
    ysss_assert_same('Nova Product 1', $pageCacheWrites[0]['value']['groups'][0]['items'][0]['title'] ?? null);
});

ysss_test('non-scalar page ingress renders page one without warnings', static function (): void {
    $db = ysss_pagination_fixture(1);
    $_GET['ys_ec_search'] = 'nova';
    $_GET['ys_ss_page'] = [];
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    try {
        $html = YSSsResultsPage::render();
    } finally {
        restore_error_handler();
    }

    ysss_assert_same([], $warnings, 'Non-scalar page emitted a warning');
    ysss_assert_contains('Nova Product 1', $html);
    $rows = ysss_pagination_row_sql($db);
    ysss_assert_same(1, count($rows));
    ysss_assert_contains('LIMIT 24 OFFSET 0', $rows[0]);
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), 'Non-scalar page did not use the brief-mandated page-one default');
});

ysss_test('only the canonical scalar page-one literal grants page analytics authority', static function (): void {
    foreach (['0', '-5', 'abc', '1x'] as $rawPage) {
        ysss_pagination_fixture(1);
        $_GET['ys_ec_search'] = 'nova';
        $_GET['ys_ss_page'] = $rawPage;
        $html = YSSsResultsPage::render();
        ysss_assert_contains('Nova Product 1', $html, "{$rawPage} did not resolve defensively to visible page one");
        ysss_assert_same([], $GLOBALS['wpdb']->inserts, "{$rawPage} manufactured page-one analytics");
    }
});

ysss_test('render uses canonical page two and never reflects request page 99', static function (): void {
    ysss_pagination_fixture(25);
    $_GET['ys_ec_search'] = 'nova';
    $_GET['ys_ss_page'] = '99';
    $html = YSSsResultsPage::render();

    ysss_assert_contains('Nova Product 25', $html);
    ysss_assert_contains('ys-ss-pager__link is-current">2</span>', $html);
    ysss_assert_false(str_contains($html, 'ys_ss_page=99'), 'Untrusted request page leaked into pager URLs');
    ysss_assert_false(str_contains($html, 'is-current">99</span>'), 'Untrusted request page leaked into current-page markup');
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Original request99 was logged after canonical rendering');
});

ysss_test('rendered safety-cap page 100 has no page 101 or next link', static function (): void {
    ysss_pagination_fixture(5000);
    $_GET['ys_ec_search'] = 'nova';
    $_GET['ys_ss_page'] = '101';
    $html = YSSsResultsPage::render();

    ysss_assert_contains('Nova Product 2377', $html);
    ysss_assert_contains('ys-ss-pager__link is-current">100</span>', $html);
    ysss_assert_false(str_contains($html, 'ys_ss_page=101'), 'Page 101 escaped the visible-page cap');
    ysss_assert_false(str_contains($html, '下一頁'), 'Page 100 exposed an impossible next link');
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Original request101 was logged as page one');
});

ysss_test('category and post-only page analytics persist as a zero-product event', static function (): void {
    ysss_pagination_fixture(
        0,
        ['categories', 'posts', 'products'],
        [['id' => 7, 'name' => 'Nova 分類', 'slug' => 'nova-category']],
        [41]
    );
    $_GET['ys_ec_search'] = 'nova';
    $_GET['ys_ss_page'] = '1';
    $html = YSSsResultsPage::render();

    ysss_assert_contains('ys-ss-results__group--categories', $html);
    ysss_assert_contains('ys-ss-results__group--posts', $html);
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), 'Recognizable zero-product page was not logged once');
    $data = $GLOBALS['wpdb']->inserts[0]['data'] ?? [];
    ysss_assert_same(0, $data['results_total'] ?? null);
    ysss_assert_same(0, $data['has_results'] ?? null);
    ysss_assert_same('page', $data['source'] ?? null);
});

ysss_test('mixed page analytics use the exact product total only', static function (): void {
    ysss_pagination_fixture(
        5,
        ['categories', 'posts', 'products'],
        [['id' => 7, 'name' => 'Nova 分類', 'slug' => 'nova-category']],
        [41]
    );
    $_GET['ys_ec_search'] = 'nova';
    $_GET['ys_ss_page'] = '1';
    $html = YSSsResultsPage::render();

    ysss_assert_contains('ys-ss-results__group--products', $html);
    ysss_assert_contains('ys-ss-results__group--categories', $html);
    ysss_assert_contains('ys-ss-results__group--posts', $html);
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts));
    $data = $GLOBALS['wpdb']->inserts[0]['data'] ?? [];
    ysss_assert_same(5, $data['results_total'] ?? null, 'Category/post items inflated product authority');
    ysss_assert_same(1, $data['has_results'] ?? null);
});

ysss_test('B page records a normal full ingress with a parameter tail', static function (): void {
    ysss_pagination_fixture(1);
    $_GET['ys_ec_search'] = str_repeat('nova ', 20) . 'utm_source=tail';
    $_GET['ys_ss_page'] = '1';

    $html = YSSsResultsPage::render();

    ysss_assert_contains('Nova Product 1', $html, 'Full-ingress policy blocked the legitimate page search');
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), 'Normal parameter-tail search was not recorded');
});

ysss_test('B page records a recognizable long ingress but stores only its bounded canonical query', static function (): void {
    ysss_pagination_fixture(1);
    $raw = str_repeat('Nova wool ', 12) . 'ISBN-9781234567890';
    $_GET['ys_ec_search'] = $raw;
    $_GET['ys_ss_page'] = '1';

    $html = YSSsResultsPage::render();

    ysss_assert_contains('Nova Product 1', $html);
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), 'Recognizable long B-page ingress lost analytics');
    $stored = $GLOBALS['wpdb']->inserts[0]['data'] ?? [];
    ysss_assert_true(strlen((string) ($stored['query_norm'] ?? '')) > 0 && strlen((string) ($stored['query_norm'] ?? '')) <= 100);
    ysss_assert_same($stored['query_norm'] ?? null, strtolower((string) ($stored['query_raw'] ?? '')));
    ysss_assert_false(str_contains((string) ($stored['query_raw'] ?? ''), 'ISBN-9781234567890'), 'Full B-page ingress tail leaked into analytics storage');
});

ysss_test('distinct page-one terms share the thirty-per-minute log budget without affecting rendering', static function (): void {
    ysss_pagination_fixture(1);
    $terms = [
        'nova alpha', 'nova bravo', 'nova charlie', 'nova delta', 'nova echo',
        'nova foxtrot', 'nova golf', 'nova hotel', 'nova india', 'nova juliet',
        'nova kilo', 'nova lima', 'nova mike', 'nova november', 'nova oscar',
        'nova papa', 'nova quebec', 'nova romeo', 'nova sierra', 'nova tango',
        'nova uniform', 'nova victor', 'nova whiskey', 'nova xray', 'nova yankee',
        'nova zulu', 'nova amber', 'nova birch', 'nova cedar', 'nova dahlia',
        'nova elm', 'nova fern', 'nova ginger', 'nova hazel', 'nova iris',
    ];

    foreach ($terms as $term) {
        $_GET['ys_ec_search'] = $term;
        $_GET['ys_ss_page'] = '1';
        $html = YSSsResultsPage::render();
        ysss_assert_contains('Nova Product 1', $html, "Rate exhaustion changed rendering for {$term}");
    }

    ysss_assert_same(30, count($GLOBALS['wpdb']->inserts), 'Page-one analytics bypassed or underfilled the shared log budget');
    $rateRows = array_filter(
        YSSsWpFake::$options,
        static fn(mixed $value, string $key): bool => str_starts_with($key, 'ys_ss_rate_v1_log_'),
        ARRAY_FILTER_USE_BOTH
    );
    ysss_assert_same(1, count($rateRows), 'Page analytics did not share one durable REST log budget row');
    ysss_assert_true(
        1 === preg_match('/\Av1:[0-9]+:30\z/D', (string) reset($rateRows)),
        'Page rendering did not consume exactly the thirty admitted log events'
    );
    $transientRateReads = array_filter(
        YSSsWpFake::$transientGets,
        static fn(array $read): bool => str_contains((string) ($read['key'] ?? ''), 'ss_rate')
            || str_contains((string) ($read['key'] ?? ''), 'ss_rl_')
    );
    $transientRateWrites = array_filter(
        YSSsWpFake::$transientSets,
        static fn(array $write): bool => str_contains((string) ($write['key'] ?? ''), 'ss_rate')
            || str_contains((string) ($write['key'] ?? ''), 'ss_rl_')
    );
    ysss_assert_same([], array_values($transientRateReads), 'Page analytics still read transient rate authority');
    ysss_assert_same([], array_values($transientRateWrites), 'Page analytics still wrote transient rate authority');
});

ysss_test('unavailable page query authority stops before all search and analytics work', static function (): void {
    $db = ysss_pagination_fixture(1);
    $baseGetVar = $db->getVarHandler;
    $db->getVarHandler = static function (string $sql, YSSsFakeWpdb $wpdb) use ($baseGetVar): mixed {
        if (str_contains($sql, 'GET_LOCK') && str_contains($sql, 'ys_ss_rate_')) {
            return 0;
        }
        return null !== $baseGetVar ? $baseGetVar($sql, $wpdb) : 0;
    };
    $_GET['ys_ec_search'] = 'nova';
    $_GET['ys_ss_page'] = '1';

    $html = YSSsResultsPage::render();

    ysss_assert_contains('請求過於頻繁，請稍後再試。', $html, 'Rate authority failure did not render the fixed safe status');
    ysss_assert_false(str_contains($html, 'Nova Product 1'), 'Rate authority failure still rendered a product');
    ysss_assert_same([], ysss_pagination_count_sql($db), 'Rate authority failure reached product COUNT');
    ysss_assert_same([], ysss_pagination_row_sql($db), 'Rate authority failure reached product row SQL');
    ysss_assert_same([], YSSsWpFake::$transientSets, 'Rate authority failure published a search cache');
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Rate authority failure reached page analytics insert');
});

ysss_test('B page shares the sixty-request query budget and stops request sixty-one before search', static function (): void {
    $db = ysss_pagination_fixture(1);
    $lastHtml = '';
    for ($index = 1; $index <= 61; ++$index) {
        $_GET['ys_ec_search'] = 'nova bounded ' . $index;
        $_GET['ys_ss_page'] = '1';
        $lastHtml = YSSsResultsPage::render();
    }

    ysss_assert_contains('請求過於頻繁，請稍後再試。', $lastHtml, 'The sixty-first request did not hit the shared query budget');
    ysss_assert_false(str_contains($lastHtml, 'Nova Product 1'), 'The sixty-first request rendered cached or searched content');
    ysss_assert_same(60, count(ysss_pagination_count_sql($db)), 'Query budget did not bound product COUNT work');
    ysss_assert_same(60, count(ysss_pagination_row_sql($db)), 'Query budget did not bound product row work');
    $searchCacheWrites = array_values(array_filter(
        YSSsWpFake::$transientSets,
        static fn(array $write): bool => str_starts_with((string) ($write['key'] ?? ''), 'ys_ss_sp_')
    ));
    ysss_assert_same(60, count($searchCacheWrites), 'Query budget did not bound unique transient allocation');
    $queryRows = array_filter(
        YSSsWpFake::$options,
        static fn(mixed $value, string $key): bool => str_starts_with($key, 'ys_ss_rate_v1_query_'),
        ARRAY_FILTER_USE_BOTH
    );
    ysss_assert_same(1, count($queryRows), 'B page used a separate or missing query budget row');
    ysss_assert_true(1 === preg_match('/\Av1:[0-9]+:60\z/D', (string) reset($queryRows)), 'Shared query row did not stop at sixty');
});

ysss_test('a malicious deep request resolved to page one cannot manufacture page-one analytics', static function (): void {
    ysss_pagination_fixture(
        0,
        ['categories', 'posts', 'products'],
        [['id' => 7, 'name' => 'Nova 分類', 'slug' => 'nova-category']],
        [41]
    );
    $_GET['ys_ec_search'] = 'nova';
    $_GET['ys_ss_page'] = '99';
    $html = YSSsResultsPage::render();

    ysss_assert_contains('ys-ss-results__group--categories', $html, 'Resolved first-page groups were hidden');
    ysss_assert_contains('ys-ss-results__group--posts', $html, 'Resolved first-page posts were hidden');
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Original page99 request was logged as a page-one event');
});

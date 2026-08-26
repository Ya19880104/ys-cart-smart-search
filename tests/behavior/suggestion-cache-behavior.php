<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Api\YSSsPublicController;
use YangSheep\SmartSearch\Services\YSSsSearchService;
use YangSheep\SmartSearch\Services\YSSsSuggestService;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Security/YSSsRateLimiter.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/Database/YSSsKeywordRepository.php',
    'src/Services/YSSsSearchService.php',
    'src/Services/YSSsSuggestService.php',
    'src/Api/YSSsPublicController.php',
] as $source) {
    ysss_require_source($source);
}

/**
 * Install an options-table authority fixture without changing the shared bootstrap fake.
 * Product authority receives one visible row by default; the two-name cache-authority
 * SELECT is delegated to the supplied resolver.
 *
 * @param callable(string,mixed,YSSsFakeWpdb):array<int,mixed> $resolver
 */
function ysss_suggest_set_authority_rows(callable $resolver): void
{
    $GLOBALS['wpdb']->getResultsHandler = static function (
        string $query,
        mixed $output,
        YSSsFakeWpdb $database
    ) use ($resolver): array {
        if (str_contains($query, 'FROM wp_ys_ec_products')) {
            return [[
                'id' => 70,
                'title' => 'Published Product',
                'slug' => 'published-product',
                'sku' => 'PUBLISHED-70',
                'price' => '1200',
                'sale_price' => '0',
                'image_url' => '',
            ]];
        }
        if (!str_contains($query, 'ys_ss_suggest_cache_generation')
            || !str_contains($query, 'ys_ss_suggest_tombstone_')
            || 1 !== preg_match('/\bFROM\s+`?' . preg_quote($database->prefix, '/') . 'options`?/i', $query)) {
            return [];
        }

        $GLOBALS['ysss_suggest_authority_queries'][] = $query;
        return $resolver($query, $output, $database);
    };
}

/** Reset the focused fixture and expose the in-memory options as uncached DB rows. */
function ysss_suggest_reset(): void
{
    \YSSsWpFake::reset();
    $GLOBALS['ysss_suggest_authority_queries'] = [];
    $GLOBALS['wpdb']->getVarHandler = static function (string $query): int {
        if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) {
            return 1;
        }
        return str_contains($query, 'SELECT 1')
            && str_contains($query, 'FROM wp_ys_ec_products')
            ? 1
            : 0;
    };
    ysss_suggest_set_authority_rows(static function (string $query): array {
        preg_match_all(
            "/'(ys_ss_(?:suggest_cache_generation|suggest_tombstone_[a-f0-9]{64}))'/i",
            $query,
            $matches
        );

        $rows = [];
        foreach (array_unique($matches[1] ?? []) as $name) {
            if (array_key_exists($name, YSSsWpFake::$options)) {
                $rows[] = [
                    'option_name' => $name,
                    'option_value' => YSSsWpFake::$options[$name],
                ];
            }
        }
        return $rows;
    });
}

/** Keep Options API reads request-locally stale while the DB fixture can move ahead. */
function ysss_suggest_stale_option_cache(string $generation): void
{
    YSSsWpFake::$getOptionHandler = static function (string $key, mixed $default) use ($generation): mixed {
        if ('ys_ss_suggest_cache_generation' === $key) {
            return $generation;
        }
        if (str_starts_with($key, 'ys_ss_suggest_tombstone_')) {
            return false; // Simulate request-local `notoptions` for every marker.
        }
        return YSSsWpFake::$options[$key] ?? $default;
    };
}

ysss_test('cached suggestions pass through the final raw-input filter', static function (): void {
    ysss_suggest_reset();
    $unsafe = [
        'count' => 2,
        'recent_enabled' => true,
        'items' => [
            ['term' => '{{7*7}}', 'source' => 'auto'],
            ['term' => 'utm_source=bot', 'source' => 'auto'],
            ['term' => 'utm_source=curated', 'source' => 'manual'],
            ['term' => 'nova', 'source' => 'auto'],
        ],
    ];
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = '1';
    YSSsWpFake::$transients['ys_ss_suggest_cache'] = $unsafe;
    YSSsWpFake::$transients['ys_ss_suggest_cache_v1'] = $unsafe;
    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([
        ['term' => 'utm_source=bot', 'source' => 'auto'],
        ['term' => 'utm_source=curated', 'source' => 'manual'],
    ], $payload['items'] ?? null);
});

ysss_test('external suggestion filter cannot reintroduce blocked output', static function (): void {
    ysss_suggest_reset();
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [
        ['term' => '<svg onload=alert(1)>nova</svg>', 'source' => 'external'],
        ['term' => '羊毛外套', 'source' => 'external'],
    ]);
    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([
        ['term' => '羊毛外套', 'source' => 'external'],
    ], $payload['items'] ?? null);
});

ysss_test('automatic suggestions require a current published product match even from cache or filters', static function (): void {
    ysss_suggest_reset();
    $authorityHandler = $GLOBALS['wpdb']->getResultsHandler;
    $GLOBALS['wpdb']->getResultsHandler = static function (
        string $sql,
        mixed $output,
        YSSsFakeWpdb $database
    ) use ($authorityHandler): array {
        if (str_contains($sql, 'FROM wp_ys_ec_products')) {
            if (!str_contains($sql, 'live-product')) {
                return [];
            }
            return [[
                'id' => 72,
                'title' => 'Live Product',
                'slug' => 'live-product',
                'sku' => 'LIVE-72',
                'price' => '1200',
                'sale_price' => '0',
                'image_url' => '',
            ]];
        }
        return null === $authorityHandler ? [] : $authorityHandler($sql, $output, $database);
    };
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = '1';
    YSSsWpFake::$transients['ys_ss_suggest_cache_v1'] = [
        'count' => 3,
        'recent_enabled' => true,
        'items' => [
            ['term' => 'stale-product', 'source' => 'auto'],
            ['term' => 'live-product', 'source' => 'auto'],
            ['term' => 'manual-curated', 'source' => 'manual'],
        ],
    ];
    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([
        ['term' => 'live-product', 'source' => 'auto'],
        ['term' => 'manual-curated', 'source' => 'manual'],
    ], $payload['items'] ?? null);
    ysss_assert_true(YSSsSearchService::has_product_match('live-product'));
    ysss_assert_false(YSSsSearchService::has_product_match('stale-product'));
});

ysss_test('automatic suggestions use the complete contextual filter-final search result', static function (): void {
    ysss_suggest_reset();
    $settings = YSSsWpFake::$options['ys_ss_settings'];
    $settings['group_order'] = ['products', 'categories'];
    $settings['categories'] = ['enabled' => true, 'limit' => 3, 'show_count' => false];
    YSSsWpFake::$options['ys_ss_settings'] = $settings;
    $authorityHandler = $GLOBALS['wpdb']->getResultsHandler;
    $GLOBALS['wpdb']->getResultsHandler = static function (
        string $sql,
        mixed $output,
        YSSsFakeWpdb $database
    ) use ($authorityHandler): array {
        if (str_contains($sql, 'FROM wp_ys_ec_products')) {
            return [[
                'id' => 71,
                'title' => 'Visible Product',
                'slug' => 'visible-product',
                'sku' => 'VISIBLE-71',
                'price' => '1200',
                'sale_price' => '990',
                'image_url' => '',
            ]];
        }
        if (str_contains($sql, 'FROM wp_ys_ec_categories')) {
            return [[
                'id' => 81,
                'name' => 'Bundle Category',
                'slug' => 'bundle-category',
            ]];
        }
        return null === $authorityHandler ? [] : $authorityHandler($sql, $output, $database);
    };
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = '1';
    YSSsWpFake::$transients['ys_ss_suggest_cache_v1'] = [
        'count' => 1,
        'recent_enabled' => true,
        'items' => [['term' => 'bundle', 'source' => 'auto']],
    ];
    add_filter('ys_ss_result_groups', static function (array $groups): array {
        $types = array_column($groups, 'type');
        if (!in_array('categories', $types, true)) {
            return $groups;
        }
        return array_values(array_filter(
            $groups,
            static fn(array $group): bool => 'products' !== ($group['type'] ?? null)
        ));
    }, 10, 2);

    $actual = YSSsSearchService::search('bundle');
    ysss_assert_same(0, $actual['products_total'] ?? null, 'Contextual filter fixture did not remove the public product result');
    ysss_assert_same(['categories'], array_column($actual['groups'] ?? [], 'type'), 'Contextual filter fixture lost its category condition');
    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([], $payload['items'] ?? null, 'Automatic term ignored the complete contextual filter-final result');
});

ysss_test('suggest count zero stays disabled for cached candidates', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_settings']['suggest_count'] = 0;
    YSSsWpFake::$transients['ys_ss_suggest_cache_v1'] = [
        'count' => 0,
        'recent_enabled' => true,
        'items' => [['term' => 'nova', 'source' => 'auto']],
    ];
    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same(0, $payload['count'] ?? null);
    ysss_assert_same([], $payload['items'] ?? null, 'Disabled cached suggestions leaked a candidate');
});

ysss_test('suggest count zero stays disabled after the external filter', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_settings']['suggest_count'] = 0;
    add_filter('ys_ss_suggestions', static fn(array $items): array => [
        ['term' => 'nova', 'source' => 'external'],
    ]);
    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same(0, $payload['count'] ?? null);
    ysss_assert_same([], $payload['items'] ?? null, 'Disabled fresh suggestions leaked a filtered candidate');
});

ysss_test('public suggest tolerates a non-scalar external source', static function (): void {
    ysss_suggest_reset();
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [
        ['term' => 'nova', 'source' => new stdClass()],
    ]);
    $response = (new YSSsPublicController())->suggest(new WP_REST_Request());
    ysss_assert_true($response instanceof WP_REST_Response);
    $payload = $response->get_data();
    ysss_assert_same([
        ['term' => 'nova', 'source' => 'auto'],
    ], $payload['items'] ?? null);
});

ysss_test('uncached cache authority closes stale generation and notoptions ABA across all four states', static function (): void {
    $captured = 'matrix-g';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $captured);
    $cases = [
        'G marker absent' => ['current' => $captured, 'marker' => false, 'accept' => true],
        'G marker present despite cached notoptions miss' => ['current' => $captured, 'marker' => true, 'accept' => false],
        'N marker present while generation cache stays G' => ['current' => 'matrix-n', 'marker' => true, 'accept' => false],
        'N marker absent after cleanup while generation cache stays G' => ['current' => 'matrix-n', 'marker' => false, 'accept' => false],
    ];

    foreach ($cases as $label => $case) {
        ysss_suggest_reset();
        YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $captured;
        ysss_suggest_stale_option_cache($captured);
        YSSsWpFake::$transients['ys_ss_suggest_cache_v' . $captured] = [
            'count' => 1,
            'recent_enabled' => true,
            'items' => [['term' => 'cached-' . $label, 'source' => 'manual']],
        ];
        $rows = [[
            'option_name' => 'ys_ss_suggest_cache_generation',
            'option_value' => $case['current'],
        ]];
        if ($case['marker']) {
            // Presence is invalidating authority even if the stored value is unexpected.
            $rows[] = ['option_name' => $marker, 'option_value' => 'mismatched-marker-value'];
        }
        ysss_suggest_set_authority_rows(static fn(): array => $rows);
        $GLOBALS['wpdb']->columnSets = [[], []];
        add_filter('ys_ss_suggestions', static fn(array $items): array => [
            ['term' => 'fresh-' . $label, 'source' => 'external'],
        ]);

        $payload = YSSsSuggestService::suggestions();
        $expectedTerm = $case['accept'] ? 'cached-' . $label : 'fresh-' . $label;
        ysss_assert_same($expectedTerm, $payload['items'][0]['term'] ?? null, $label);
        ysss_assert_same([], YSSsWpFake::$transientSets, $label . ' unexpectedly published a cache entry');
        ysss_assert_same(1, count($GLOBALS['ysss_suggest_authority_queries']), $label . ' did not use one authority snapshot');
        $query = $GLOBALS['ysss_suggest_authority_queries'][0] ?? '';
        ysss_assert_true(str_contains($query, 'ys_ss_suggest_cache_generation') && str_contains($query, $marker), $label . ' did not read both rows together');
        ysss_assert_same(1, preg_match_all('/\bSELECT\b/i', $query), $label . ' authority was not a single SELECT');
    }
});

ysss_test('authority SQL errors malformed rows and duplicates fail closed without cache publication', static function (): void {
    $generation = 'authority-failure-g';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    $generationRow = [
        'option_name' => 'ys_ss_suggest_cache_generation',
        'option_value' => $generation,
    ];
    $scenarios = [
        'database error' => static function (string $query, mixed $output, YSSsFakeWpdb $database) use ($generationRow): array {
            $database->last_error = 'simulated authority read error';
            return [$generationRow];
        },
        'adapter exception' => static function (): array {
            throw new RuntimeException('simulated authority adapter failure');
        },
        'malformed generation' => static fn(): array => [[
            'option_name' => 'ys_ss_suggest_cache_generation',
            'option_value' => 'bad token!',
        ]],
        'malformed row' => static fn(): array => [['option_name' => 'ys_ss_suggest_cache_generation']],
        'duplicate generation' => static fn(): array => [$generationRow, $generationRow],
        'duplicate marker' => static fn(): array => [
            $generationRow,
            ['option_name' => $marker, 'option_value' => $generation],
            ['option_name' => $marker, 'option_value' => $generation],
        ],
    ];

    foreach ($scenarios as $label => $resolver) {
        ysss_suggest_reset();
        YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
        ysss_suggest_stale_option_cache($generation);
        ysss_suggest_set_authority_rows($resolver);
        $GLOBALS['wpdb']->columnSets = [[], []];
        add_filter('ys_ss_suggestions', static fn(array $items): array => [
            ['term' => 'fresh-' . $label, 'source' => 'external'],
        ]);

        $payload = YSSsSuggestService::suggestions();
        ysss_assert_same('fresh-' . $label, $payload['items'][0]['term'] ?? null, $label . ' did not return fresh data');
        ysss_assert_same([], YSSsWpFake::$transientSets, $label . ' published without unambiguous DB authority');
        ysss_assert_same(1, count($GLOBALS['ysss_suggest_authority_queries']), $label . ' did not fail at its first authority snapshot');
    }
});

ysss_test('in-process invalidation prevents a late writer from publishing its captured generation', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'old7';
    $GLOBALS['wpdb']->columnSets = [[], []];
    $invalidations = 0;
    add_filter('ys_ss_suggestions', static function (array $items) use (&$invalidations): array {
        if (0 === $invalidations) {
            ++$invalidations;
            YSSsSuggestService::invalidate();
        }
        return [['term' => 'late-old-writer', 'source' => 'external']];
    });

    $latePayload = YSSsSuggestService::suggestions();
    ysss_assert_same(1, $invalidations, 'The in-process invalidation hook did not execute');
    $current = (string) (YSSsWpFake::$options['ys_ss_suggest_cache_generation'] ?? '');
    ysss_assert_true((bool) preg_match('/\A[a-f0-9]{32}\z/D', $current), 'Invalidation did not issue a fresh epoch token');
    ysss_assert_same([['term' => 'late-old-writer', 'source' => 'external']], $latePayload['items'] ?? null);
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache_vold7']), 'Late writer published into its superseded generation');
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache_v' . $current]), 'Late writer polluted the newly current generation');

    YSSsWpFake::$transients['ys_ss_suggest_cache_v' . $current] = [
        'count' => 1,
        'recent_enabled' => true,
        'items' => [['term' => 'fresh-current', 'source' => 'manual']],
    ];
    $currentPayload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'fresh-current', 'source' => 'manual']], $currentPayload['items'] ?? null, 'Current generation reader consumed stale old7 data');
    ysss_assert_same(1, $invalidations, 'Current-generation cache hit unexpectedly re-entered the filter');
});

ysss_test('invalidate persists an autoload-disabled tombstone before generation rotation', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'old-marker';
    YSSsWpFake::$transients['ys_ss_suggest_cache_vold-marker'] = ['items' => [['term' => 'old']]];
    YSSsWpFake::$transients['ys_ss_suggest_cache'] = ['items' => [['term' => 'legacy']]];

    $status = YSSsSuggestService::invalidate();
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', 'old-marker');
    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, $status);
    ysss_assert_same([
        'key' => $marker,
        'value' => 'old-marker',
        'deprecated' => '',
        'autoload' => false,
    ], YSSsWpFake::$optionAdds[0] ?? null);
    $markerRead = array_search($marker, array_column(YSSsWpFake::$optionGets, 'key'), true);
    $generationWrite = array_search('ys_ss_suggest_cache_generation', array_column(YSSsWpFake::$optionUpdateCalls, 'key'), true);
    ysss_assert_true(false !== $markerRead, 'Tombstone was not read back');
    ysss_assert_same(0, $generationWrite, 'Generation was not rotated exactly once');
    $accesses = array_map(
        static fn(array $access): string => $access['operation'] . ':' . $access['key'],
        YSSsWpFake::$optionAccesses
    );
    $markerAddAt = array_search('add:' . $marker, $accesses, true);
    $markerGetAt = array_search('get:' . $marker, $accesses, true);
    $rotationAt = array_search('update:ys_ss_suggest_cache_generation', $accesses, true);
    ysss_assert_true(is_int($markerAddAt) && is_int($markerGetAt) && is_int($rotationAt)
        && $markerAddAt < $markerGetAt && $markerGetAt < $rotationAt, 'Marker was not persisted and verified before rotation');
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache_vold-marker']));
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache']));
    ysss_assert_same($marker, YSSsWpFake::$optionDeletes[0]['key'] ?? null, 'Successful rotation did not remove the captured old marker');
});

ysss_test('durable marker makes a failed generation write bypass fresh and deletes old caches', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'old8';
    YSSsWpFake::$transients['ys_ss_suggest_cache_vold8'] = ['items' => [['term' => 'stale']]];
    YSSsWpFake::$transients['ys_ss_suggest_cache'] = ['items' => [['term' => 'legacy']]];
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    $status = YSSsSuggestService::invalidate();
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', 'old8');
    ysss_assert_same(YSSsSuggestService::INVALIDATION_BYPASS_FRESH, $status);
    ysss_assert_same('old8', YSSsWpFake::$options[$marker] ?? null, 'Durable marker was not retained');
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache_vold8']));
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache']));
});

ysss_test('durable final marker overrides an earlier generation update exception', static function (): void {
    ysss_suggest_reset();
    $generation = 'throwing-update';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload): bool {
        throw new RuntimeException('SECRET update provider');
    };

    $status = YSSsSuggestService::invalidate();

    ysss_assert_same(YSSsSuggestService::INVALIDATION_BYPASS_FRESH, $status);
    ysss_assert_same($generation, YSSsWpFake::$options['ys_ss_suggest_cache_generation'] ?? null);
    ysss_assert_same($generation, YSSsWpFake::$options[$marker] ?? null, 'Final marker was not durable after update exception');
});

ysss_test('durable final marker overrides an earlier add exception after persistence', static function (): void {
    ysss_suggest_reset();
    $generation = 'throwing-add';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    YSSsWpFake::$addOptionHandler = static function (string $key, mixed $value, string $deprecated, mixed $autoload): bool {
        YSSsWpFake::$options[$key] = $value;
        throw new RuntimeException('SECRET add provider');
    };

    $status = YSSsSuggestService::invalidate();

    ysss_assert_same(YSSsSuggestService::INVALIDATION_BYPASS_FRESH, $status);
    ysss_assert_same($generation, YSSsWpFake::$options['ys_ss_suggest_cache_generation'] ?? null);
    ysss_assert_same($generation, YSSsWpFake::$options[$marker] ?? null, 'Final marker was not durable after add exception');
    ysss_assert_same([], YSSsWpFake::$optionUpdateCalls, 'Add exception unexpectedly attempted generation rotation');
});

ysss_test('false marker add with strict readback mismatch never attempts generation rotation', static function (): void {
    ysss_suggest_reset();
    $generation = 'false-marker';
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;

    $status = YSSsSuggestService::invalidate();

    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, $status);
    ysss_assert_same($generation, YSSsWpFake::$options['ys_ss_suggest_cache_generation'] ?? null);
    ysss_assert_same([], array_values(array_filter(
        YSSsWpFake::$optionUpdateCalls,
        static fn(array $call): bool => 'ys_ss_suggest_cache_generation' === $call['key']
    )), 'Generation was written without durable marker authority');
});

ysss_test('false marker add may rotate only when an identical marker already exists', static function (): void {
    ysss_suggest_reset();
    $generation = 'existing-marker';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    YSSsWpFake::$options[$marker] = $generation;

    $status = YSSsSuggestService::invalidate();

    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, $status);
    ysss_assert_same(1, count(array_filter(
        YSSsWpFake::$optionUpdateCalls,
        static fn(array $call): bool => 'ys_ss_suggest_cache_generation' === $call['key']
    )));
});

ysss_test('failed marker authority cannot promote an interleaved stale payload into the current generation', static function (): void {
    ysss_suggest_reset();
    $generation = 'interleave-old';
    $candidate = str_repeat('ab', 16);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;
    YSSsWpFake::$randomBytesHandler = static fn(int $length): string => str_repeat("\xAB", $length);
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload) use ($candidate): bool {
        YSSsWpFake::$options[$key] = $value;
        if ('ys_ss_suggest_cache_generation' === $key && $candidate === $value) {
            YSSsWpFake::$transients['ys_ss_suggest_cache_v' . $candidate] = [
                'count' => 1,
                'recent_enabled' => true,
                'items' => [['term' => 'interleaved-stale', 'source' => 'manual']],
            ];
        }
        return true;
    };

    $status = YSSsSuggestService::invalidate();
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [['term' => 'fresh-after-failed-authority', 'source' => 'external']]);
    $payload = YSSsSuggestService::suggestions();

    ysss_assert_same([['term' => 'fresh-after-failed-authority', 'source' => 'external']], $payload['items'] ?? null, 'A falsely authorized rotation exposed interleaved stale cache bytes');
    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, $status);
    ysss_assert_same($generation, YSSsWpFake::$options['ys_ss_suggest_cache_generation'] ?? null);
    ysss_assert_same([], array_values(array_filter(
        YSSsWpFake::$optionUpdateCalls,
        static fn(array $call): bool => 'ys_ss_suggest_cache_generation' === $call['key']
    )), 'Failed marker authority still attempted a generation write');
});

ysss_test('total authority persistence failure returns failed while still deleting old caches', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'old9';
    YSSsWpFake::$transients['ys_ss_suggest_cache_vold9'] = ['items' => [['term' => 'stale']]];
    YSSsWpFake::$transients['ys_ss_suggest_cache'] = ['items' => [['term' => 'legacy']]];
    YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, YSSsSuggestService::invalidate());
    ysss_assert_same(['ys_ss_suggest_cache_vold9', 'ys_ss_suggest_cache'], array_column(YSSsWpFake::$transientDeletes, 'key'));
});

ysss_test('a concurrent generation change is rotated even when this update reports false', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'old10';
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload): bool {
        YSSsWpFake::$options[$key] = 'concurrent-token';
        return false;
    };

    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, YSSsSuggestService::invalidate());
    ysss_assert_same('concurrent-token', YSSsWpFake::$options['ys_ss_suggest_cache_generation'] ?? null);
});

ysss_test('tombstoned reader heals once without reading the stale key and resumes new cache', static function (): void {
    ysss_suggest_reset();
    $old = 'reader-old';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $old);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $old;
    YSSsWpFake::$options[$marker] = $old;
    YSSsWpFake::$transients['ys_ss_suggest_cache_v' . $old] = [
        'count' => 1,
        'recent_enabled' => true,
        'items' => [['term' => 'late-stale', 'source' => 'manual']],
    ];
    YSSsWpFake::$transients['ys_ss_suggest_cache_vhealed'] = [
        'count' => 1,
        'recent_enabled' => true,
        'items' => [['term' => 'healed-current', 'source' => 'manual']],
    ];
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload): bool {
        YSSsWpFake::$options[$key] = 'healed';
        return true;
    };

    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'healed-current', 'source' => 'manual']], $payload['items'] ?? null);
    ysss_assert_false(in_array('ys_ss_suggest_cache_v' . $old, array_column(YSSsWpFake::$transientGets, 'key'), true), 'Tombstoned reader read the stale transient');
    ysss_assert_same(['ys_ss_suggest_cache_vhealed'], array_column(YSSsWpFake::$transientGets, 'key'));
    ysss_assert_false(isset(YSSsWpFake::$options[$marker]), 'Heal did not remove only the captured old marker');
});

ysss_test('tombstoned reader with failed healing computes fresh without transient read or write', static function (): void {
    ysss_suggest_reset();
    $old = 'reader-stuck';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $old);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $old;
    YSSsWpFake::$options[$marker] = $old;
    YSSsWpFake::$transients['ys_ss_suggest_cache_v' . $old] = [
        'count' => 1,
        'recent_enabled' => true,
        'items' => [['term' => 'late-stale', 'source' => 'manual']],
    ];
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [['term' => 'fresh-only', 'source' => 'external']]);

    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'fresh-only', 'source' => 'external']], $payload['items'] ?? null);
    ysss_assert_same([], YSSsWpFake::$transientGets, 'Bypass reader touched a transient');
    ysss_assert_same([], YSSsWpFake::$transientSets, 'Bypass reader published a transient');
});

ysss_test('builder that observes a new tombstone returns fresh but never publishes', static function (): void {
    ysss_suggest_reset();
    $generation = 'builder-old';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static function (array $items) use ($marker, $generation): array {
        YSSsWpFake::$options[$marker] = $generation;
        return [['term' => 'fresh-return', 'source' => 'external']];
    });

    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'fresh-return', 'source' => 'external']], $payload['items'] ?? null);
    ysss_assert_same([], YSSsWpFake::$transientSets, 'Tombstoned builder published after its final check');
});

ysss_test('successful rotation removes the old marker but never a marker for the new generation', static function (): void {
    ysss_suggest_reset();
    $old = 'cleanup-old';
    $oldMarker = 'ys_ss_suggest_tombstone_' . hash('sha256', $old);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $old;
    YSSsWpFake::$options[$oldMarker] = $old;
    $newMarker = '';
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload) use (&$newMarker): bool {
        YSSsWpFake::$options[$key] = $value;
        $newMarker = 'ys_ss_suggest_tombstone_' . hash('sha256', (string) $value);
        YSSsWpFake::$options[$newMarker] = $value;
        return true;
    };

    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, YSSsSuggestService::invalidate());
    ysss_assert_false(isset(YSSsWpFake::$options[$oldMarker]));
    ysss_assert_true('' !== $newMarker && isset(YSSsWpFake::$options[$newMarker]), 'Rotation deleted a current/new marker');
});

ysss_test('random token failure is contained and resolves from durable marker state', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'random-old';
    YSSsWpFake::$randomBytesHandler = static function (int $length): string {
        throw new RuntimeException('SECRET random provider');
    };

    $status = YSSsSuggestService::invalidate();
    ysss_assert_same(YSSsSuggestService::INVALIDATION_BYPASS_FRESH, $status);
    ysss_assert_same([], YSSsWpFake::$optionUpdateCalls, 'Random failure still attempted a generation write');
});

ysss_test('cached hit is discarded when its generation becomes tombstoned during transient read', static function (): void {
    ysss_suggest_reset();
    $generation = 'read-race';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    YSSsWpFake::$getTransientHandler = static function (string $key) use ($generation, $marker): mixed {
        if ('ys_ss_suggest_cache_v' . $generation === $key) {
            YSSsWpFake::$options[$marker] = $generation;
            return [
                'count' => 1,
                'recent_enabled' => true,
                'items' => [['term' => 'stale-hit', 'source' => 'manual']],
            ];
        }
        return false;
    };
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [['term' => 'fresh-after-race', 'source' => 'external']]);

    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'fresh-after-race', 'source' => 'external']], $payload['items'] ?? null, 'Reader returned a hit invalidated during get_transient');
    ysss_assert_same([], YSSsWpFake::$transientSets, 'Race-losing reader published into a tombstoned generation');
});

ysss_test('cached hit is discarded when generation changes during transient read', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'read-old';
    YSSsWpFake::$getTransientHandler = static function (string $key): mixed {
        if ('ys_ss_suggest_cache_vread-old' === $key) {
            YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'read-new';
            return [
                'count' => 1,
                'recent_enabled' => true,
                'items' => [['term' => 'stale-generation-hit', 'source' => 'manual']],
            ];
        }
        return false;
    };
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [['term' => 'fresh-generation', 'source' => 'external']]);

    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'fresh-generation', 'source' => 'external']], $payload['items'] ?? null);
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache_vread-old']), 'Superseded reader published its old key');
});

ysss_test('heal refuses a newly tombstoned generation without reading or writing its cache', static function (): void {
    ysss_suggest_reset();
    $old = 'heal-old';
    $oldMarker = 'ys_ss_suggest_tombstone_' . hash('sha256', $old);
    $new = 'heal-new';
    $newMarker = 'ys_ss_suggest_tombstone_' . hash('sha256', $new);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $old;
    YSSsWpFake::$options[$oldMarker] = $old;
    YSSsWpFake::$transients['ys_ss_suggest_cache_v' . $new] = [
        'count' => 1,
        'recent_enabled' => true,
        'items' => [['term' => 'new-but-tombstoned', 'source' => 'manual']],
    ];
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload) use ($new, $newMarker): bool {
        YSSsWpFake::$options[$key] = $new;
        YSSsWpFake::$options[$newMarker] = $new;
        return true;
    };
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [['term' => 'fresh-no-cache', 'source' => 'external']]);

    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'fresh-no-cache', 'source' => 'external']], $payload['items'] ?? null);
    ysss_assert_false(in_array('ys_ss_suggest_cache_v' . $new, array_column(YSSsWpFake::$transientGets, 'key'), true), 'Heal read a newly tombstoned generation');
    ysss_assert_same([], YSSsWpFake::$transientSets, 'Heal wrote a newly tombstoned generation');
    ysss_assert_false(isset(YSSsWpFake::$options[$oldMarker]), 'Heal retained the captured old marker');
    ysss_assert_same($new, YSSsWpFake::$options[$newMarker] ?? null, 'Heal deleted the new/current marker');
});

ysss_test('malformed stored generation is non-cacheable and never falls back to historical v1', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'bad token!';
    YSSsWpFake::$transients['ys_ss_suggest_cache_v1'] = [
        'count' => 1,
        'recent_enabled' => true,
        'items' => [['term' => 'historical-v1', 'source' => 'manual']],
    ];
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [['term' => 'fresh-malformed', 'source' => 'external']]);

    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'fresh-malformed', 'source' => 'external']], $payload['items'] ?? null);
    ysss_assert_same([], YSSsWpFake::$transientGets, 'Malformed generation read a historical cache key');
    ysss_assert_same([], YSSsWpFake::$transientSets, 'Malformed generation published a cache key');
});

ysss_test('non-string scalar generation aliases never activate historical v1 cache', static function (): void {
    foreach ([1, true] as $malformed) {
        ysss_suggest_reset();
        YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $malformed;
        YSSsWpFake::$transients['ys_ss_suggest_cache_v1'] = [
            'count' => 1,
            'recent_enabled' => true,
            'items' => [['term' => 'scalar-alias-stale', 'source' => 'manual']],
        ];
        $GLOBALS['wpdb']->columnSets = [[], []];
        add_filter('ys_ss_suggestions', static fn(array $items): array => [['term' => 'scalar-alias-fresh', 'source' => 'external']]);

        $payload = YSSsSuggestService::suggestions();
        ysss_assert_same([['term' => 'scalar-alias-fresh', 'source' => 'external']], $payload['items'] ?? null);
        ysss_assert_same([], YSSsWpFake::$transientGets, 'Non-string scalar generation read historical v1');
        ysss_assert_same([], YSSsWpFake::$transientSets, 'Non-string scalar generation published historical v1');
    }
});

ysss_test('update true with unchanged generation resolves from final readback as bypass', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'lying-write';
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => true;

    ysss_assert_same(YSSsSuggestService::INVALIDATION_BYPASS_FRESH, YSSsSuggestService::invalidate());
});

ysss_test('marker durability is decided by the final strict readback', static function (): void {
    ysss_suggest_reset();
    $generation = 'vanishing-marker';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    $markerReads = 0;
    YSSsWpFake::$getOptionHandler = static function (string $key, mixed $default) use ($marker, $generation, &$markerReads): mixed {
        if ($marker === $key) {
            ++$markerReads;
            return 1 === $markerReads ? $generation : false;
        }
        return YSSsWpFake::$options[$key] ?? $default;
    };
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, YSSsSuggestService::invalidate());
    ysss_assert_true($markerReads >= 2, 'Invalidation reused an early marker read instead of final authority');
});

ysss_test('fresh writer cleans up its just-written key when authority changes during set', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'post-write-old';
    $GLOBALS['wpdb']->columnSets = [[], []];
    add_filter('ys_ss_suggestions', static fn(array $items): array => [['term' => 'fresh-returned', 'source' => 'external']]);
    YSSsWpFake::$setTransientHandler = static function (string $key, mixed $value, int $expiration): bool {
        YSSsWpFake::$transients[$key] = $value;
        YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'post-write-new';
        return true;
    };

    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([['term' => 'fresh-returned', 'source' => 'external']], $payload['items'] ?? null);
    ysss_assert_same(1, count(YSSsWpFake::$transientSets));
    ysss_assert_same(3, count($GLOBALS['ysss_suggest_authority_queries']), 'Writer did not check authority after transient read and on both sides of publication');
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache_vpost-write-old']), 'Race-losing transient survived post-write cleanup');
    ysss_assert_true(in_array('ys_ss_suggest_cache_vpost-write-old', array_column(YSSsWpFake::$transientDeletes, 'key'), true));
});

ysss_test('overlapping invalidations never reuse an already issued generation', static function (): void {
    ysss_suggest_reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'seed';
    $writes = [];
    $nested = false;

    YSSsWpFake::$updateOptionBeforeWrite = static function (string $key, mixed $value) use (&$writes, &$nested): void {
        if ('ys_ss_suggest_cache_generation' !== $key) {
            return;
        }
        $writes[] = (string) $value;
        if ($nested) {
            return;
        }

        $nested = true;
        YSSsSuggestService::invalidate();
        YSSsSuggestService::invalidate();
    };

    YSSsSuggestService::invalidate();
    YSSsWpFake::$updateOptionBeforeWrite = null;

    ysss_assert_same(3, count($writes), 'The overlap fixture did not execute all three invalidations');
    ysss_assert_same(3, count(array_unique($writes)), 'An overlapping invalidation reused an issued generation');
    ysss_assert_same($writes[0], (string) (YSSsWpFake::$options['ys_ss_suggest_cache_generation'] ?? ''), 'The overlap fixture did not resume the delayed outer writer last');
});

ysss_test('auto terms overfetch before filtering and then backfill accepted rows', static function (): void {
    ysss_suggest_reset();
    $GLOBALS['wpdb']->respectColumnLimit = true;
    $GLOBALS['wpdb']->columnSets = [[
        '{{7*7}}',
        '<svg onload=alert(1)>',
        'nova',
        '羊毛外套',
    ]];
    ysss_assert_same(['nova', '羊毛外套'], YSSsQueryRepository::auto_terms(30, 2));
    $sql = $GLOBALS['wpdb']->queries[0] ?? '';
    ysss_assert_true((bool) preg_match('/\bLIMIT\s+(?:[3-9]|[1-9]\d+)/i', $sql), 'SQL did not overfetch beyond requested limit');
    ysss_assert_true(str_contains($sql, 'SUM(hits) - SUM(zero_hits)'), 'Automatic terms are not qualified by positive-result events');
    ysss_assert_true(str_contains($sql, 'ORDER BY SUM(hits) - SUM(zero_hits) DESC'), 'Automatic terms are still ranked by all searches instead of positive-result events');
    ysss_assert_true(str_contains($sql, 'SUM(zero_hits) ASC, term ASC'), 'Positive-hit ties still reward noisier zero-result terms');
});

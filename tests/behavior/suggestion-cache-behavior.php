<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Api\YSSsPublicController;
use YangSheep\SmartSearch\Services\YSSsSuggestService;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Security/YSSsRateLimiter.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
    'src/Database/YSSsQueryRepository.php',
    'src/Database/YSSsKeywordRepository.php',
    'src/Services/YSSsSuggestService.php',
    'src/Api/YSSsPublicController.php',
] as $source) {
    ysss_require_source($source);
}

ysss_test('cached suggestions pass through the final raw-input filter', static function (): void {
    YSSsWpFake::reset();
    $unsafe = [
        'count' => 2,
        'recent_enabled' => true,
        'items' => [
            ['term' => '{{7*7}}', 'source' => 'auto'],
            ['term' => 'nova', 'source' => 'auto'],
        ],
    ];
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 1;
    YSSsWpFake::$transients['ys_ss_suggest_cache'] = $unsafe;
    YSSsWpFake::$transients['ys_ss_suggest_cache_v1'] = $unsafe;
    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same([
        ['term' => 'nova', 'source' => 'auto'],
    ], $payload['items'] ?? null);
});

ysss_test('external suggestion filter cannot reintroduce blocked output', static function (): void {
    YSSsWpFake::reset();
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

ysss_test('suggest count zero stays disabled for cached candidates', static function (): void {
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
    YSSsWpFake::$options['ys_ss_settings']['suggest_count'] = 0;
    add_filter('ys_ss_suggestions', static fn(array $items): array => [
        ['term' => 'nova', 'source' => 'external'],
    ]);
    $payload = YSSsSuggestService::suggestions();
    ysss_assert_same(0, $payload['count'] ?? null);
    ysss_assert_same([], $payload['items'] ?? null, 'Disabled fresh suggestions leaked a filtered candidate');
});

ysss_test('public suggest tolerates a non-scalar external source', static function (): void {
    YSSsWpFake::reset();
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

ysss_test('in-process invalidation keeps a late writer on its captured generation', static function (): void {
    YSSsWpFake::reset();
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
    ysss_assert_same($latePayload, YSSsWpFake::$transients['ys_ss_suggest_cache_vold7'] ?? null, 'Late writer did not use the generation captured at request start');
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

ysss_test('overlapping invalidations never reuse an already issued generation', static function (): void {
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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
});

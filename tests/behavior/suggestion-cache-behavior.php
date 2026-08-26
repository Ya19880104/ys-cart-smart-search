<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Api\YSSsPublicController;
use YangSheep\SmartSearch\Services\YSSsSuggestService;

if (!function_exists('YangSheep\\SmartSearch\\Services\\random_bytes')) {
    eval(<<<'PHP'
namespace YangSheep\SmartSearch\Services {
    function random_bytes(int $length): string {
        if (null !== \YSSsWpFake::$randomBytesHandler) {
            return (\YSSsWpFake::$randomBytesHandler)($length);
        }
        return \random_bytes($length);
    }
}
PHP);
}

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
        ['term' => 'utm_source=curated', 'source' => 'manual'],
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

ysss_test('in-process invalidation prevents a late writer from publishing its captured generation', static function (): void {
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
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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

ysss_test('total authority persistence failure returns failed while still deleting old caches', static function (): void {
    YSSsWpFake::reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'old9';
    YSSsWpFake::$transients['ys_ss_suggest_cache_vold9'] = ['items' => [['term' => 'stale']]];
    YSSsWpFake::$transients['ys_ss_suggest_cache'] = ['items' => [['term' => 'legacy']]];
    YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;

    ysss_assert_same(YSSsSuggestService::INVALIDATION_FAILED, YSSsSuggestService::invalidate());
    ysss_assert_same(['ys_ss_suggest_cache_vold9', 'ys_ss_suggest_cache'], array_column(YSSsWpFake::$transientDeletes, 'key'));
});

ysss_test('a concurrent generation change is rotated even when this update reports false', static function (): void {
    YSSsWpFake::reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'old10';
    YSSsWpFake::$updateOptionHandler = static function (string $key, mixed $value, mixed $autoload): bool {
        YSSsWpFake::$options[$key] = 'concurrent-token';
        return false;
    };

    ysss_assert_same(YSSsSuggestService::INVALIDATION_ROTATED, YSSsSuggestService::invalidate());
    ysss_assert_same('concurrent-token', YSSsWpFake::$options['ys_ss_suggest_cache_generation'] ?? null);
});

ysss_test('tombstoned reader heals once without reading the stale key and resumes new cache', static function (): void {
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'random-old';
    YSSsWpFake::$randomBytesHandler = static function (int $length): string {
        throw new RuntimeException('SECRET random provider');
    };

    $status = YSSsSuggestService::invalidate();
    ysss_assert_same(YSSsSuggestService::INVALIDATION_BYPASS_FRESH, $status);
    ysss_assert_same([], YSSsWpFake::$optionUpdateCalls, 'Random failure still attempted a generation write');
});

ysss_test('cached hit is discarded when its generation becomes tombstoned during transient read', static function (): void {
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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
        YSSsWpFake::reset();
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
    YSSsWpFake::reset();
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = 'lying-write';
    YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => true;

    ysss_assert_same(YSSsSuggestService::INVALIDATION_BYPASS_FRESH, YSSsSuggestService::invalidate());
});

ysss_test('marker durability is decided by the final strict readback', static function (): void {
    YSSsWpFake::reset();
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
    YSSsWpFake::reset();
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
    ysss_assert_false(isset(YSSsWpFake::$transients['ys_ss_suggest_cache_vpost-write-old']), 'Race-losing transient survived post-write cleanup');
    ysss_assert_true(in_array('ys_ss_suggest_cache_vpost-write-old', array_column(YSSsWpFake::$transientDeletes, 'key'), true));
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
    ysss_assert_true(str_contains($sql, 'SUM(hits) - SUM(zero_hits)'), 'Automatic terms are not qualified by positive-result events');
    ysss_assert_true(str_contains($sql, 'ORDER BY SUM(hits) - SUM(zero_hits) DESC'), 'Automatic terms are still ranked by all searches instead of positive-result events');
    ysss_assert_true(str_contains($sql, 'SUM(zero_hits) ASC, term ASC'), 'Positive-hit ties still reward noisier zero-result terms');
});

<?php
declare(strict_types=1);

ysss_test('behavior harness is active', static function (): void {
    ysss_assert_same(4, 2 + 2);
});

ysss_test('options authority adapter accepts only the exact product SQL shape', static function (): void {
    YSSsWpFake::reset();
    $generation = 'harness-g';
    $marker = 'ys_ss_suggest_tombstone_' . hash('sha256', $generation);
    YSSsWpFake::$options['ys_ss_suggest_cache_generation'] = $generation;
    YSSsWpFake::$options[$marker] = $generation;
    $GLOBALS['wpdb']->resultSets = [[['sentinel' => 'fallthrough']]];

    $malformed = "SELECT option_value FROM wp_options WHERE option_name NOT IN ('ys_ss_suggest_cache_generation', '{$marker}')";
    ysss_assert_same(
        [['sentinel' => 'fallthrough']],
        $GLOBALS['wpdb']->get_results($malformed, ARRAY_A),
        'Malformed options SQL was rewritten into authority rows'
    );

    $exact = "SELECT option_name, option_value FROM `wp_options` WHERE option_name IN ('ys_ss_suggest_cache_generation', '{$marker}')";
    ysss_assert_same([
        ['option_name' => 'ys_ss_suggest_cache_generation', 'option_value' => $generation],
        ['option_name' => $marker, 'option_value' => $generation],
    ], $GLOBALS['wpdb']->get_results($exact, ARRAY_A));
});

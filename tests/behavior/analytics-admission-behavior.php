<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Analytics\YSSsAnalyticsAdmission;
use YangSheep\SmartSearch\Database\YSSsQueryRepository;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsSettings.php',
] as $source) {
    ysss_require_source($source);
}

$admissionPath = ysss_source_path('src/Analytics/YSSsAnalyticsAdmission.php');
if (is_file($admissionPath)) {
    require_once $admissionPath;
}
ysss_require_source('src/Database/YSSsQueryRepository.php');

ysss_test('analytics admission keeps positive and human zero-result searches', static function (): void {
    ysss_assert_true(class_exists(YSSsAnalyticsAdmission::class), 'Analytics admission SOT is missing');
    ysss_assert_same('admit_positive_result', YSSsAnalyticsAdmission::classify('羊毛外套', 3));
    ysss_assert_same('admit_positive_result', YSSsAnalyticsAdmission::classify('SKU-9F8A7B6C5D4E3F2A', 1));
    ysss_assert_same('admit_human_zero_result', YSSsAnalyticsAdmission::classify('SKU-9F8A7B6C5D4E3F2A', 0));
    ysss_assert_same('admit_human_zero_result', YSSsAnalyticsAdmission::classify('找不到的羊毛披肩', 0));
    ysss_assert_same('admit_human_zero_result', YSSsAnalyticsAdmission::classify('online=only 商品', 0));
    ysss_assert_same('admit_positive_result', YSSsAnalyticsAdmission::classify('Q=5 無線喇叭', 2));
    ysss_assert_same('admit_positive_result', YSSsAnalyticsAdmission::classify('query=string PHP 入門', 2));
    ysss_assert_same('admit_positive_result', YSSsAnalyticsAdmission::classify('model=A100 capacity=256GB', 4));
    ysss_assert_same('admit_positive_result', YSSsAnalyticsAdmission::classify('size=XL color=black', 5));
});

ysss_test('analytics admission rejects attacks known parameters and opaque machine noise', static function (): void {
    ysss_assert_true(class_exists(YSSsAnalyticsAdmission::class), 'Analytics admission SOT is missing');
    ysss_assert_same('reject_attack', YSSsAnalyticsAdmission::classify('<div/onmouseover=alert(1)>nova</div>', 2));
    ysss_assert_same('reject_known_parameter', YSSsAnalyticsAdmission::classify('utm_source=bot&utm_campaign=sale', 2));
    ysss_assert_same('reject_known_parameter', YSSsAnalyticsAdmission::classify('q=nova&redirect_to=/wp-admin', 2));
    ysss_assert_same('reject_known_parameter', YSSsAnalyticsAdmission::classify('utm_source%253Dbot', 2));
    ysss_assert_same('reject_known_parameter', YSSsAnalyticsAdmission::classify('utm_source%2525253Dbot', 2));
    ysss_assert_same('reject_known_parameter', YSSsAnalyticsAdmission::classify('ｕｔｍ＿ｓｏｕｒｃｅ＝bot', 0));
    ysss_assert_same(
        'reject_known_parameter',
        YSSsAnalyticsAdmission::classify(str_repeat('羊毛外套 ', 30) . 'utm_source=tail', 2),
        'Known parameter escaped detection behind the 100-character search clamp'
    );
    ysss_assert_same(
        'reject_machine_token',
        YSSsAnalyticsAdmission::classify('550e8400-e29b-41d4-a716-446655440000 123e4567-e89b-12d3-a456-426614174000', 2)
    );
    ysss_assert_same(
        'reject_machine_token',
        YSSsAnalyticsAdmission::classify('AbCdEfGhIjKlMn0pQrStUv1x Yz0123456789AbCdEfGhIjKl', 0)
    );
    ysss_assert_same(
        'reject_machine_token',
        YSSsAnalyticsAdmission::classify(
            str_repeat('可辨識商品 ', 30) . 'AbCdEfGhIjKlMn0pQrStUv1x Yz0123456789AbCdEfGhIjKl',
            2
        ),
        'Multiple opaque tokens escaped detection behind the 100-character search clamp'
    );
    ysss_assert_same('reject_empty', YSSsAnalyticsAdmission::classify('---___...', 0));
    ysss_assert_same('reject_machine_token', YSSsAnalyticsAdmission::classify('zqxwvutsrqponmlkjihgfed', 0));
    ysss_assert_same('reject_machine_token', YSSsAnalyticsAdmission::classify('abcdefghijklmnopqrstuvwx', 0));
    ysss_assert_same('reject_machine_token', YSSsAnalyticsAdmission::classify('qwertyuiopasdfghjklzxcvb', 0));
});

$compatibility_noise_cases = [
        ['known parameter at zero results', '𝐮𝐭𝐦_𝐬𝐨𝐮𝐫𝐜𝐞=𝐛𝐨𝐭', 0, YSSsAnalyticsAdmission::REJECT_KNOWN_PARAMETER],
        ['known parameter at positive results', '𝐮𝐭𝐦_𝐬𝐨𝐮𝐫𝐜𝐞=𝐛𝐨𝐭', 3, YSSsAnalyticsAdmission::REJECT_KNOWN_PARAMETER],
        ['Letterlike-hole parameter at zero results', '𝓊𝓉𝓂_𝓈ℴ𝓊𝓇𝒸ℯ=𝒷ℴ𝓉', 0, YSSsAnalyticsAdmission::REJECT_KNOWN_PARAMETER],
        ['single opaque token at zero results', '𝟎𝟏𝟐𝟑𝟒𝟓𝟔𝟕𝟖𝟗𝟎𝟏𝟐𝟑𝟒𝟓', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN],
        ['two opaque tokens at positive results', '𝟎𝟏𝟐𝟑𝟒𝟓𝟔𝟕𝟖𝟗𝟎𝟏𝟐𝟑𝟒𝟓 𝟗𝟖𝟕𝟔𝟓𝟒𝟑𝟐𝟏𝟎𝟗𝟖𝟕𝟔𝟓𝟒', 4, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN],
    ];

    foreach ($compatibility_noise_cases as $index => [$label, $query, $total, $expected]) {
        ysss_test("analytics admission applies dependency-free compatibility folding to {$label}", static function () use ($index, $label, $query, $total, $expected): void {
            ysss_assert_same($expected, YSSsAnalyticsAdmission::classify($query, $total), "Compatibility fixture {$index} admitted {$label}");
        });

        ysss_test("analytics repository rejects compatibility-folded {$label}", static function () use ($index, $label, $query, $total): void {
            YSSsWpFake::reset();
            YSSsQueryRepository::log($query, $total, 'products', 'bar', "compatibility-noise-{$index}");
            ysss_assert_same([], $GLOBALS['wpdb']->inserts, "Compatibility fixture {$index} inserted analytics for {$label}");
            ysss_assert_same([], $GLOBALS['wpdb']->queries, "Compatibility fixture {$index} reached a database query for {$label}");
        });
    }

ysss_test('analytics rejects incomplete canonical closure with zero repository work', static function (): void {
    $payload = 'utm_source%3Dbot';
    for ($depth = 0; $depth < 70; ++$depth) {
        $payload = str_replace('%', '%25', $payload);
    }

    ysss_assert_same(YSSsAnalyticsAdmission::REJECT_ATTACK, YSSsAnalyticsAdmission::classify($payload, 4));
    YSSsWpFake::reset();
    YSSsQueryRepository::log($payload, 4, 'products', 'bar', 'closure-exhaustion');
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Incomplete closure inserted analytics');
    ysss_assert_same([], $GLOBALS['wpdb']->queries, 'Incomplete closure reached repository SQL');
});

$token_local_cases = [
    ['SKU-9F8A7B6C5D4E3F2A', 0, YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO, true],
    ['SKU-9F8A7B6C5D4E3F2A MPN-A1B2C3D4E5F6G7H8', 0, YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO, true],
    ['SKU-ABCD qwertyuiopasdfghjklzxcvb', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN, false],
    ['SKU-9F8A7B6C5D4E3F2A 123e4567-e89b-12d3-a456-426614174000', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN, false],
    ['ISBN-9781234567890 utm_source=test', 2, YSSsAnalyticsAdmission::REJECT_KNOWN_PARAMETER, false],
    ['SKU-9F8A7B6C5D4E3F2A MPN-A1B2C3D4E5F6G7H8', 2, YSSsAnalyticsAdmission::ADMIT_POSITIVE_RESULT, true],
    ['SKU-9F8A7B6C5D4E3F2A %71wertyuiopasdfghjklzxcvb', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN, false],
    ['ＳＫＵ－９Ｆ８Ａ７Ｂ６Ｃ５Ｄ４Ｅ３Ｆ２Ａ qwertyuiopasdfghjklzxcvb', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN, false],
    ['SKU: qwertyuiopasdfghjklzxcvb qwertyuiopasdfghjklzxcvb', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN, false],
    ['qwertyuiopas%64fghjklzxcvb', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN, false],
    ['ｑｗｅｒｔｙｕｉｏｐａｓｄｆｇｈｊｋｌｚｘｃｖｂ', 0, YSSsAnalyticsAdmission::REJECT_MACHINE_TOKEN, false],
    ['SKU-ABCD qwertyuiopasdfghjklzxcvb', 2, YSSsAnalyticsAdmission::ADMIT_POSITIVE_RESULT, true],
];

foreach ($token_local_cases as $index => [$query, $total, $expected, $should_insert]) {
    ysss_test("analytics admission keeps identifier exemption local for fixture {$index}", static function () use ($index, $query, $total, $expected, $should_insert): void {
        ysss_assert_same($expected, YSSsAnalyticsAdmission::classify($query, $total), "Unexpected admission for fixture {$index}");

        YSSsWpFake::reset();
        YSSsQueryRepository::log($query, $total, 'products', 'bar', "identifier-span-{$index}");
        ysss_assert_same(
            $should_insert ? 1 : 0,
            count($GLOBALS['wpdb']->inserts),
            "Repository insert boundary disagreed for fixture {$index}"
        );
    });
}

ysss_test('analytics write bottleneck ignores machine parameters but retains human zero results', static function (): void {
    ysss_assert_true(class_exists(YSSsAnalyticsAdmission::class), 'Analytics admission SOT is missing');

    YSSsWpFake::reset();
    YSSsQueryRepository::log('utm_source=bot&utm_campaign=sale', 4, 'products', 'bar', 'visitor-known-param');
    ysss_assert_same([], $GLOBALS['wpdb']->queries, 'Rejected analytics noise reached the database');
    ysss_assert_same([], $GLOBALS['wpdb']->inserts, 'Rejected analytics noise was inserted');

    YSSsWpFake::reset();
    YSSsQueryRepository::log('想買但找不到的商品', 0, 'products', 'bar', 'visitor-human-zero');
    ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), 'Human zero-result search was not retained for analysis');
    ysss_assert_same(0, $GLOBALS['wpdb']->inserts[0]['data']['has_results'] ?? null);
});

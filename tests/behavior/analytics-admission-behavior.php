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

ysss_test('analytics admission keeps product and zero-result searches', static function (): void {
    ysss_assert_true(class_exists(YSSsAnalyticsAdmission::class), 'Analytics admission SOT is missing');
    ysss_assert_same(YSSsAnalyticsAdmission::ADMIT_POSITIVE_RESULT, YSSsAnalyticsAdmission::classify('羊毛外套', 3));
    ysss_assert_same(YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO, YSSsAnalyticsAdmission::classify('找不到的羊毛披肩', 0));
    ysss_assert_same(YSSsAnalyticsAdmission::ADMIT_POSITIVE_RESULT, YSSsAnalyticsAdmission::classify('C++ <vector> 入門', 1));
    ysss_assert_same(YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO, YSSsAnalyticsAdmission::classify('---___...', 0));
});

$normalCases = [
    ['known parameter at zero results', 'utm_source=summer', 0, YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO],
    ['known parameters at positive results', 'utm_source=bot&utm_campaign=sale', 2, YSSsAnalyticsAdmission::ADMIT_POSITIVE_RESULT],
    ['control-looking parameter search', 'q=nova&redirect_to=/wp-admin', 2, YSSsAnalyticsAdmission::ADMIT_POSITIVE_RESULT],
    ['UUID product lookup', '550e8400-e29b-41d4-a716-446655440000', 0, YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO],
    ['two opaque model numbers', 'AbCdEfGhIjKlMn0pQrStUv1x Yz0123456789AbCdEfGhIjKl', 4, YSSsAnalyticsAdmission::ADMIT_POSITIVE_RESULT],
    ['fullwidth parameter text', '𝐮𝐭𝐦_𝐬𝐨𝐮𝐫𝐜𝐞=𝐛𝐨𝐭', 0, YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO],
    ['emoji product lookup', '☕', 2, YSSsAnalyticsAdmission::ADMIT_POSITIVE_RESULT],
    ['symbol-only zero-result lookup', '❤️', 0, YSSsAnalyticsAdmission::ADMIT_HUMAN_ZERO],
];

foreach ($normalCases as $index => [$label, $query, $total, $expected]) {
    ysss_test("analytics records normal fixture {$index}: {$label}", static function () use ($index, $label, $query, $total, $expected): void {
        ysss_assert_same($expected, YSSsAnalyticsAdmission::classify($query, $total), "Normal fixture {$index} rejected {$label}");
        YSSsWpFake::reset();
        YSSsQueryRepository::log($query, $query, $total, 'products', 'bar', "normal-{$index}");
        ysss_assert_same(1, count($GLOBALS['wpdb']->inserts), "Normal fixture {$index} was not recorded");
    });
}

$attackCases = [
    '<div/onmouseover=alert(1)>nova</div>',
    "'; DROP TABLE wp_users; --",
    "'; DELETE FROM wp_users; --",
    '1; SELECT * FROM wp_users; --',
    '1 UNION /*!50000 SELECT*/ user_pass FROM wp_users',
    "'; DROP DATABASE wordpress",
    "'; SELECT @@version",
    "'; REPLACE INTO wp_users VALUES (1)",
    '1 /*!50000 UNION SELECT ' . str_repeat('a', 513) . '*/',
    '<a ' . str_repeat('x', 513) . ' onmouseover=alert(1)>nova',
    '{{' . str_repeat('a', 513) . '}}',
    '$(' . str_repeat('a', 513) . ')',
];

foreach ($attackCases as $index => $query) {
    ysss_test("analytics rejects malicious fixture {$index} before repository work", static function () use ($index, $query): void {
        ysss_assert_same(YSSsAnalyticsAdmission::REJECT_ATTACK, YSSsAnalyticsAdmission::classify($query, 0));
        YSSsWpFake::reset();
        YSSsQueryRepository::log($query, $query, 0, 'products', 'bar', "attack-{$index}");
        ysss_assert_same([], $GLOBALS['wpdb']->queries, "Malicious fixture {$index} reached repository SQL");
        ysss_assert_same([], $GLOBALS['wpdb']->inserts, "Malicious fixture {$index} was inserted");
    });
}

ysss_test('incomplete canonical closure remains malicious and performs zero repository work', static function (): void {
    $payload = '%3Cscript%3E';
    for ($depth = 0; $depth < 70; ++$depth) {
        $payload = str_replace('%', '%25', $payload);
    }

    ysss_assert_same(YSSsAnalyticsAdmission::REJECT_ATTACK, YSSsAnalyticsAdmission::classify($payload, 4));
    YSSsWpFake::reset();
    YSSsQueryRepository::log($payload, $payload, 4, 'products', 'bar', 'closure-exhaustion');
    ysss_assert_same([], $GLOBALS['wpdb']->queries);
    ysss_assert_same([], $GLOBALS['wpdb']->inserts);
});

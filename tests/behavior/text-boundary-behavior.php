<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Support\YSSsText;

$textPath = ysss_source_path('src/Support/YSSsText.php');
if (is_file($textPath)) {
    require_once $textPath;
}

ysss_test('Unicode truncation counts characters without requiring mbstring', static function (): void {
    ysss_assert_true(class_exists(YSSsText::class), 'Shared Unicode text helper is missing');
    $value = str_repeat('咖', 34);
    $truncated = YSSsText::truncate_chars($value, 33);
    ysss_assert_same(str_repeat('咖', 33), $truncated);
    ysss_assert_same(1, preg_match('//u', $truncated), 'Truncation produced invalid UTF-8');
});

ysss_test('fullwidth ASCII folding does not require mbstring or intl', static function (): void {
    ysss_assert_same(
        'utm_source=bot',
        YSSsText::fold_fullwidth_ascii('ｕｔｍ＿ｓｏｕｒｃｅ＝bot')
    );
});

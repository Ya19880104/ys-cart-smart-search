<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Frontend\YSSsShortcodes;

ysss_require_source('src/Database/YSSsSettings.php');
ysss_require_source('src/YSSmartSearchDetector.php');
ysss_require_source('src/Frontend/YSSsResultsPage.php');
ysss_require_source('src/Frontend/YSSsShortcodes.php');

ysss_test('rendered bars and popup expose unique matched combobox and listbox contracts', static function (): void {
    YSSsWpFake::reset();
    $html = YSSsShortcodes::render_bar() . YSSsShortcodes::render_bar();
    ob_start();
    YSSsShortcodes::print_popup();
    $html .= (string) ob_get_clean();
    preg_match_all('/<input\b[^>]*class="ys-ss-input"[^>]*>/u', $html, $inputs);
    preg_match_all('/<div\b[^>]*class="ys-ss-panel"[^>]*>/u', $html, $panels);
    ysss_assert_same(3, count($inputs[0]), 'Expected two bars and the real popup search input');
    ysss_assert_same(3, count($panels[0]), 'Expected two bars and the real popup result panel');
    $panelIds = [];
    foreach ($panels[0] as $panel) {
        ysss_assert_contains('role="listbox"', $panel);
        ysss_assert_contains('aria-live="polite"', $panel);
        ysss_assert_contains('aria-busy="false"', $panel);
        ysss_assert_true(1 === preg_match('/\bid="([^"]+)"/u', $panel, $id), 'Panel is missing an ID');
        $panelIds[] = $id[1];
    }
    ysss_assert_same(3, count(array_unique($panelIds)), 'Rendered bar/popup panel IDs are not unique');
    foreach ($inputs[0] as $index => $input) {
        ysss_assert_contains('role="combobox"', $input);
        ysss_assert_contains('aria-autocomplete="list"', $input);
        ysss_assert_contains('aria-expanded="false"', $input);
        ysss_assert_contains('aria-busy="false"', $input);
        ysss_assert_contains('aria-controls="' . $panelIds[$index] . '"', $input, 'Input aria-controls does not match its panel');
    }
});

ysss_test('popup trigger advertises its dialog relationship', static function (): void {
    $html = YSSsShortcodes::render_icon();
    ysss_assert_contains('data-ys-ss-open', $html);
    ysss_assert_contains('aria-haspopup="dialog"', $html);
});

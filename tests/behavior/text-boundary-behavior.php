<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Support\YSSsText;

function ysss_text_fixture_codepoint(int $codepoint): string {
    if ($codepoint <= 0x7F) {
        return chr($codepoint);
    }
    if ($codepoint <= 0x7FF) {
        return chr(0xC0 | ($codepoint >> 6))
            . chr(0x80 | ($codepoint & 0x3F));
    }
    if ($codepoint <= 0xFFFF) {
        return chr(0xE0 | ($codepoint >> 12))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }
    return chr(0xF0 | ($codepoint >> 18))
        . chr(0x80 | (($codepoint >> 12) & 0x3F))
        . chr(0x80 | (($codepoint >> 6) & 0x3F))
        . chr(0x80 | ($codepoint & 0x3F));
}

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

ysss_test('compatibility ASCII subset composes mathematical and Letterlike holes without intl', static function (): void {
    ysss_assert_same(
        'utm_source=bot',
        YSSsText::fold_compatibility_ascii('𝓊𝓉𝓂_𝓈ℴ𝓊𝓇𝒸ℯ=𝒷ℴ𝓉')
    );
    ysss_assert_same('<script>', YSSsText::fold_compatibility_ascii('<𝓈𝒸𝓇𝒾𝓅𝓉>'));
    ysss_assert_same('javascript:', YSSsText::fold_compatibility_ascii('𝒿𝒶𝓋𝒶𝓈𝒸𝓇𝒾𝓅𝓉:'));
    ysss_assert_same('0123456789', YSSsText::fold_compatibility_ascii('𝟎𝟏𝟐𝟑𝟒𝟓𝟔𝟕𝟖𝟗'));
    ysss_assert_same('ij', YSSsText::fold_compatibility_ascii('𝚤𝚥'));
});

ysss_test('canonical candidates reach a deterministic percent and entity fixed point', static function (): void {
    $raw = '%26lt%3Bscript%26gt%3B';
    $closure = YSSsText::canonical_candidates($raw);
    ysss_assert_same(true, $closure['complete'] ?? null);
    ysss_assert_same(
        [$raw, '&lt;script&gt;', '<script>'],
        $closure['candidates'] ?? null,
        'Canonical BFS order or fixed-point completion drifted'
    );

    $fourEntityLayers = '&amp;amp;amp;lt;script&amp;amp;amp;gt;alert(1)&amp;amp;amp;lt;/script&amp;amp;amp;gt;';
    $entityClosure = YSSsText::canonical_candidates($fourEntityLayers);
    ysss_assert_same(true, $entityClosure['complete'] ?? null);
    ysss_assert_true(
        in_array('<script>alert(1)</script>', $entityClosure['candidates'] ?? [], true),
        'Four HTML-entity layers did not reach the attack spelling'
    );
});

ysss_test('dependency-free compatibility folding covers every assigned mathematical Latin letter', static function (): void {
    $starts = [
        0x1D400, 0x1D434, 0x1D468, 0x1D49C, 0x1D4D0, 0x1D504, 0x1D538,
        0x1D56C, 0x1D5A0, 0x1D5D4, 0x1D608, 0x1D63C, 0x1D670,
    ];
    $holes = array_fill_keys([
        0x1D455,
        0x1D49D, 0x1D4A0, 0x1D4A1, 0x1D4A3, 0x1D4A4, 0x1D4A7, 0x1D4A8, 0x1D4AD, 0x1D4BA, 0x1D4BC, 0x1D4C4,
        0x1D506, 0x1D50B, 0x1D50C, 0x1D515, 0x1D51D,
        0x1D53A, 0x1D53F, 0x1D545, 0x1D547, 0x1D548, 0x1D549, 0x1D551,
    ], true);

    foreach ($starts as $start) {
        $source = '';
        $expected = '';
        for ($offset = 0; $offset < 52; ++$offset) {
            $codepoint = $start + $offset;
            if (isset($holes[$codepoint])) {
                continue;
            }
            $source .= ysss_text_fixture_codepoint($codepoint);
            $expected .= $offset < 26 ? chr(65 + $offset) : chr(97 + $offset - 26);
        }
        $closure = YSSsText::canonical_candidates($source);
        ysss_assert_same(true, $closure['complete'] ?? null, sprintf('Style U+%X did not close', $start));
        ysss_assert_true(
            in_array($expected, $closure['candidates'] ?? [], true),
            sprintf('Style U+%X did not fold to ASCII', $start)
        );
    }

    $unassigned = implode('', array_map('ysss_text_fixture_codepoint', array_keys($holes)));
    $holeClosure = YSSsText::canonical_candidates($unassigned);
    ysss_assert_same(true, $holeClosure['complete'] ?? null);
    ysss_assert_same([$unassigned], $holeClosure['candidates'] ?? null, 'Unassigned mathematical holes were folded');
});

ysss_test('dependency-free compatibility folding covers all mathematical digit styles', static function (): void {
    foreach ([0x1D7CE, 0x1D7D8, 0x1D7E2, 0x1D7EC, 0x1D7F6] as $start) {
        $source = '';
        for ($offset = 0; $offset < 10; ++$offset) {
            $source .= ysss_text_fixture_codepoint($start + $offset);
        }
        $closure = YSSsText::canonical_candidates($source);
        ysss_assert_same(true, $closure['complete'] ?? null, sprintf('Digit style U+%X did not close', $start));
        ysss_assert_true(in_array('0123456789', $closure['candidates'] ?? [], true));
    }
});

ysss_test('dependency-free compatibility folding covers the explicit Letterlike Symbols set', static function (): void {
    $mapping = [
        0x2102 => 'C', 0x210A => 'g', 0x210B => 'H', 0x210C => 'H',
        0x210D => 'H', 0x210E => 'h', 0x2110 => 'I', 0x2111 => 'I',
        0x2112 => 'L', 0x2113 => 'l', 0x2115 => 'N', 0x2119 => 'P',
        0x211A => 'Q', 0x211B => 'R', 0x211C => 'R', 0x211D => 'R',
        0x2124 => 'Z', 0x2128 => 'Z', 0x212A => 'K', 0x212C => 'B',
        0x212D => 'C', 0x212F => 'e', 0x2130 => 'E', 0x2131 => 'F',
        0x2133 => 'M', 0x2134 => 'o', 0x2139 => 'i', 0x2145 => 'D',
        0x2146 => 'd', 0x2147 => 'e', 0x2148 => 'i', 0x2149 => 'j',
    ];
    $source = '';
    foreach (array_keys($mapping) as $codepoint) {
        $source .= ysss_text_fixture_codepoint($codepoint);
    }
    $closure = YSSsText::canonical_candidates($source);
    ysss_assert_same(true, $closure['complete'] ?? null);
    ysss_assert_true(in_array(implode('', $mapping), $closure['candidates'] ?? [], true));
});

ysss_test('canonical work limits report incomplete rather than a false fixed point', static function (): void {
    $payload = '%3Cscript%3E';
    for ($depth = 0; $depth < 70; ++$depth) {
        $payload = str_replace('%', '%25', $payload);
    }
    $closure = YSSsText::canonical_candidates($payload);
    ysss_assert_same(false, $closure['complete'] ?? null, 'Candidate exhaustion was reported complete');
    ysss_assert_same(64, count($closure['candidates'] ?? []), 'Candidate budget drifted');

    $invalidGenerated = YSSsText::canonical_candidates('%C3%28');
    ysss_assert_same(false, $invalidGenerated['complete'] ?? null, 'Invalid generated UTF-8 was reported complete');

    $oversize = YSSsText::canonical_candidates(str_repeat('a', 2049));
    ysss_assert_same(false, $oversize['complete'] ?? null, 'Oversize seed was reported complete');
});

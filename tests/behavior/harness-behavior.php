<?php
declare(strict_types=1);

ysss_test('behavior harness is active', static function (): void {
    ysss_assert_same(4, 2 + 2);
});

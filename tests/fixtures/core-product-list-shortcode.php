<?php
declare(strict_types=1);

namespace YangSheep\Ecommerce\Frontend;

/** Test double whose call stack matches the YS CART Core sanitizer call site. */
final class YSProductListShortcode
{
    public function render(string $raw): string
    {
        return \sanitize_text_field($raw);
    }
}

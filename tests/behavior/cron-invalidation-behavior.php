<?php
declare(strict_types=1);

namespace YangSheep\SmartSearch\Cron {
    function error_log(string $message): bool
    {
        \YSSsWpFake::$errorLogs[] = $message;
        return true;
    }
}

namespace {
    use YangSheep\SmartSearch\Cron\YSSsCronBridge;

    foreach ([
        'src/Security/YSSsInjectionGuard.php',
        'src/Security/YSSsSearchInput.php',
        'src/Database/YSSsSchema.php',
        'src/Database/YSSsAnalyticsMutationException.php',
        'src/Database/YSSsSettings.php',
        'src/Database/YSSsQueryRepository.php',
        'src/Database/YSSsKeywordRepository.php',
        'src/Services/YSSsSuggestService.php',
        'src/Cron/YSSsCronBridge.php',
    ] as $source) {
        ysss_require_source($source);
    }

    ysss_test('cron rebuilds suggestions once after rotated invalidation', static function (): void {
        YSSsWpFake::reset();
        $builds = 0;
        add_filter('ys_ss_suggestions', static function (array $items) use (&$builds): array {
            ++$builds;
            return [];
        });

        YSSsCronBridge::run_daily();
        ysss_assert_same(1, $builds, 'Rotated cron did not execute one suggestion rebuild');
        ysss_assert_same(1, count(YSSsWpFake::$transientSets), 'Rotated cron did not publish one rebuilt cache');
        ysss_assert_same([], YSSsWpFake::$errorLogs);
    });

    ysss_test('cron rebuilds once after durable bypass without claiming a cache publish', static function (): void {
        YSSsWpFake::reset();
        $builds = 0;
        YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;
        add_filter('ys_ss_suggestions', static function (array $items) use (&$builds): array {
            ++$builds;
            return [];
        });

        YSSsCronBridge::run_daily();
        ysss_assert_same(1, $builds, 'Bypass cron did not execute one fresh rebuild');
        ysss_assert_same([], YSSsWpFake::$transientSets, 'Bypass cron published despite durable tombstone');
        ysss_assert_same([], YSSsWpFake::$errorLogs);
    });

    ysss_test('cron failed invalidation skips suggestions and emits only a fixed safe message', static function (): void {
        YSSsWpFake::reset();
        $GLOBALS['wpdb']->last_error = 'SECRET SQL cache authority detail';
        $builds = 0;
        YSSsWpFake::$addOptionHandler = static fn(string $key, mixed $value, string $deprecated, mixed $autoload): bool => false;
        YSSsWpFake::$updateOptionHandler = static fn(string $key, mixed $value, mixed $autoload): bool => false;
        add_filter('ys_ss_suggestions', static function (array $items) use (&$builds): array {
            ++$builds;
            return [];
        });

        YSSsCronBridge::run_daily();
        ysss_assert_same(0, $builds, 'Failed invalidation still rebuilt suggestions');
        ysss_assert_same([], YSSsWpFake::$transientSets);
        ysss_assert_same(['[ys-cart-smart-search] daily cron error.'], YSSsWpFake::$errorLogs);
        ysss_assert_false(str_contains(implode('\n', YSSsWpFake::$errorLogs), 'SECRET'));
    });

    ysss_test('cron catch never appends throwable detail from rollup purge or suggestions', static function (): void {
        foreach (['rollup', 'purge', 'suggestions'] as $source) {
            YSSsWpFake::reset();
            if ('rollup' === $source) {
                $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
                    throw new RuntimeException('SECRET SQL rollup');
                };
            } elseif ('purge' === $source) {
                $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
                    if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_')) {
                        throw new RuntimeException('SECRET SQL purge');
                    }
                    return 1;
                };
            } else {
                add_filter('ys_ss_suggestions', static function (array $items): array {
                    throw new RuntimeException('SECRET suggestions backend');
                });
            }

            YSSsCronBridge::run_daily();
            ysss_assert_same(['[ys-cart-smart-search] daily cron error.'], YSSsWpFake::$errorLogs, ucfirst($source) . ' detail reached cron log');
            ysss_assert_false(str_contains(implode('\n', YSSsWpFake::$errorLogs), 'SECRET'));
        }
    });
}

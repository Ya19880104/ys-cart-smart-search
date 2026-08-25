<?php
declare(strict_types=1);

use YangSheep\SmartSearch\Database\YSSsQueryRepository;

foreach ([
    'src/Security/YSSsInjectionGuard.php',
    'src/Security/YSSsSearchInput.php',
    'src/Database/YSSsSchema.php',
    'src/Database/YSSsQueryRepository.php',
] as $source) {
    ysss_require_source($source);
}

$mutationExceptionPath = ysss_source_path('src/Database/YSSsAnalyticsMutationException.php');
if (is_file($mutationExceptionPath)) {
    require_once $mutationExceptionPath;
}

$assertMutationException = static function (?Throwable $error, string $message): void {
    ysss_assert_true(null !== $error, $message);
    ysss_assert_same(
        'YangSheep\\SmartSearch\\Database\\YSSsAnalyticsMutationException',
        get_class($error),
        $message
    );
};

ysss_test('exact term deletion commits both tables and returns an exact total', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 3;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return 2;
        }
        return 0;
    };

    $deleted = YSSsQueryRepository::delete_term('Nova');
    ysss_assert_same(['queries' => 3, 'daily' => 2, 'total' => 5], $deleted);
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_contains('GET_LOCK', $sql);
    ysss_assert_contains('START TRANSACTION', $sql);
    ysss_assert_contains('COMMIT', $sql);
    ysss_assert_contains('RELEASE_LOCK', $sql);
    ysss_assert_false(str_contains($sql, 'ROLLBACK'));
});

ysss_test('zero affected rows are a successful exact-delete transaction', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static fn(string $sql): int|false => 0;
    $deleted = YSSsQueryRepository::delete_term('missing-term');
    ysss_assert_same(['queries' => 0, 'daily' => 0, 'total' => 0], $deleted);
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_contains('COMMIT', $sql);
    ysss_assert_false(str_contains($sql, 'ROLLBACK'));
});

ysss_test('failed transaction start performs zero delete and still releases maintenance lock', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static fn(string $sql): int|false => 'START TRANSACTION' === $sql ? false : 0;
    $caught = null;
    try {
        YSSsQueryRepository::delete_term('Nova');
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'START TRANSACTION failure was not converted to a mutation exception');
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_false(str_contains($sql, 'DELETE FROM'), 'DELETE ran after START TRANSACTION failed');
    ysss_assert_false(str_contains($sql, 'COMMIT'), 'COMMIT ran after START TRANSACTION failed');
    ysss_assert_contains('RELEASE_LOCK', $sql);
});

ysss_test('non-transactional analytics tables fail closed before exact deletion', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->getResultsHandler = static fn(string $sql): array => str_contains($sql, 'information_schema.TABLES')
        ? [
            ['TABLE_NAME' => 'wp_ys_ss_queries', 'ENGINE' => 'InnoDB'],
            ['TABLE_NAME' => 'wp_ys_ss_terms_daily', 'ENGINE' => 'MyISAM'],
        ]
        : [];
    $caught = null;
    try {
        YSSsQueryRepository::delete_term('Nova');
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'Non-transactional table engines did not fail closed');
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_contains('information_schema.TABLES', $sql);
    ysss_assert_false(str_contains($sql, 'START TRANSACTION'), 'Transaction started without two InnoDB tables');
    ysss_assert_false(str_contains($sql, 'DELETE FROM'), 'Delete ran without two InnoDB tables');
    ysss_assert_contains('RELEASE_LOCK', $sql);
});

ysss_test('first exact delete failure rolls back before touching the second table', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return false;
        }
        return 0;
    };
    $caught = null;
    try {
        YSSsQueryRepository::delete_term('Nova');
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'First DELETE failure was not converted to a mutation exception');
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_contains('ROLLBACK', $sql);
    ysss_assert_false(str_contains($sql, 'DELETE FROM wp_ys_ss_terms_daily'), 'Second DELETE ran after the first failed');
    ysss_assert_false(str_contains($sql, 'COMMIT'));
    ysss_assert_contains('RELEASE_LOCK', $sql);
});

ysss_test('second exact delete failure rolls back and releases maintenance lock', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 4;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return false;
        }
        return 1;
    };
    $caught = null;
    try {
        YSSsQueryRepository::delete_term('Nova');
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'Second DELETE failure was not converted to a mutation exception');
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_contains('ROLLBACK', $sql);
    ysss_assert_contains('RELEASE_LOCK', $sql);
    ysss_assert_false(str_contains($sql, 'COMMIT'), 'Failed transaction was committed');
});

ysss_test('maintenance lock busy causes zero exact-delete mutation', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->getVarHandler = static fn(string $sql): int => str_contains($sql, 'GET_LOCK') ? 0 : 1;
    $caught = null;
    try {
        YSSsQueryRepository::delete_term('Nova');
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'Busy maintenance lock did not fail closed');
    $mutations = array_values(array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'DELETE FROM') || str_contains($sql, 'TRANSACTION') || str_contains($sql, 'COMMIT')
    ));
    ysss_assert_same([], $mutations, 'Busy maintenance lock still allowed a mutation');
    ysss_assert_false((bool) array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    ), 'Code released a maintenance lock it never acquired');
});

ysss_test('maintenance lock database error is not misreported as contention', static function () use ($assertMutationException): void {
    foreach ([null, false] as $lockResult) {
        YSSsWpFake::reset();
        $GLOBALS['wpdb']->getVarHandler = static fn(string $sql): mixed => str_contains($sql, 'GET_LOCK') ? $lockResult : 1;
        $caught = null;
        try {
            YSSsQueryRepository::delete_term('Nova');
        } catch (Throwable $error) {
            $caught = $error;
        }
        $assertMutationException($caught, 'GET_LOCK database error was not converted to a mutation exception');
        ysss_assert_same(
            'database',
            $caught->reason(),
            'GET_LOCK database error was misclassified as lock contention'
        );
    }
});

ysss_test('full purge rolls back both tables when the second delete fails', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 5;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return false;
        }
        return 0;
    };
    $caught = null;
    try {
        YSSsQueryRepository::purge_all();
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'Second full-purge delete failure was not converted to a mutation exception');
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_false(str_contains($sql, 'TRUNCATE'), 'Full purge still uses non-rollbackable TRUNCATE');
    ysss_assert_contains('START TRANSACTION', $sql);
    ysss_assert_contains('ROLLBACK', $sql);
    ysss_assert_false(str_contains($sql, 'COMMIT'), 'Partial full purge was committed');
    ysss_assert_contains('RELEASE_LOCK', $sql);
});

ysss_test('expired purge reports incomplete at its batch ceiling and leaves daily rows for retry', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 5000;
        }
        return 0;
    };
    $caught = null;
    try {
        YSSsQueryRepository::purge_older_than(180);
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'Batch ceiling incorrectly returned purge success');
    $rawDeletes = array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')
    );
    ysss_assert_same(200, count($rawDeletes), 'Expired purge did not retain its bounded batch ceiling');
    ysss_assert_false((bool) array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')
    ), 'Daily rows were deleted before raw cleanup was known complete');
    ysss_assert_contains('RELEASE_LOCK', implode("\n", $GLOBALS['wpdb']->queries));
});

ysss_test('expired purge daily-table failure reports error and releases maintenance lock', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_queries')) {
            return 0;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_terms_daily')) {
            return false;
        }
        return 0;
    };
    $caught = null;
    try {
        YSSsQueryRepository::purge_older_than(180);
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'Daily expired-purge failure was not converted to a mutation exception');
    ysss_assert_contains('RELEASE_LOCK', implode("\n", $GLOBALS['wpdb']->queries));
});

ysss_test('commit failure rolls back and releases maintenance lock', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static function (string $sql): int|false {
        if (str_starts_with($sql, 'DELETE FROM wp_ys_ss_')) {
            return 1;
        }
        if ('COMMIT' === $sql) {
            return false;
        }
        return 0;
    };
    $caught = null;
    try {
        YSSsQueryRepository::delete_term('Nova');
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'COMMIT failure was not converted to a mutation exception');
    $sql = implode("\n", $GLOBALS['wpdb']->queries);
    ysss_assert_contains('COMMIT', $sql);
    ysss_assert_contains('ROLLBACK', $sql);
    ysss_assert_contains('RELEASE_LOCK', $sql);
});

ysss_test('rollup uses the same maintenance lock and fails closed when busy', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->getVarHandler = static fn(string $sql): int => str_contains($sql, 'GET_LOCK') ? 0 : 1;
    $caught = null;
    try {
        YSSsQueryRepository::rollup_date('2026-08-25');
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'Busy maintenance lock did not stop rollup');
    ysss_assert_false((bool) array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'INSERT INTO wp_ys_ss_terms_daily')
    ), 'Busy maintenance lock still executed rollup');
});

ysss_test('rollup and exact delete use the identical per-site maintenance lock name', static function (): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static fn(string $sql): int|false => 0;
    YSSsQueryRepository::delete_term('Nova');
    YSSsQueryRepository::rollup_date('2026-08-25');
    $names = [];
    foreach ($GLOBALS['wpdb']->queries as $sql) {
        if (preg_match("/GET_LOCK\\('([^']+)'/", $sql, $match)) {
            $names[] = $match[1];
        }
    }
    ysss_assert_same(2, count($names), 'Expected one maintenance lock acquisition per operation');
    ysss_assert_same($names[0], $names[1], 'Rollup and exact delete used different maintenance locks');
    ysss_assert_true(strlen($names[0]) <= 64, 'Maintenance lock name exceeds MySQL bound');

    $firstSiteName = $names[0];
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->prefix = 'wp_2_';
    $GLOBALS['wpdb']->queryHandler = static fn(string $sql): int|false => 0;
    YSSsQueryRepository::delete_term('Nova');
    $secondSiteName = '';
    foreach ($GLOBALS['wpdb']->queries as $sql) {
        if (preg_match("/GET_LOCK\\('([^']+)'/", $sql, $match)) {
            $secondSiteName = $match[1];
            break;
        }
    }
    ysss_assert_true('' !== $secondSiteName, 'Second site did not acquire a maintenance lock');
    ysss_assert_false($firstSiteName === $secondSiteName, 'Different site prefixes shared one maintenance lock name');
});

ysss_test('rollup database failure releases the maintenance lock', static function () use ($assertMutationException): void {
    YSSsWpFake::reset();
    $GLOBALS['wpdb']->queryHandler = static fn(string $sql): int|false => str_starts_with($sql, 'INSERT INTO wp_ys_ss_terms_daily') ? false : 0;
    $caught = null;
    try {
        YSSsQueryRepository::rollup_date('2026-08-25');
    } catch (Throwable $error) {
        $caught = $error;
    }
    $assertMutationException($caught, 'Rollup database failure was not converted to a mutation exception');
    ysss_assert_true((bool) array_filter(
        $GLOBALS['wpdb']->queries,
        static fn(string $sql): bool => str_contains($sql, 'RELEASE_LOCK')
    ), 'Rollup failure did not release maintenance lock');
});

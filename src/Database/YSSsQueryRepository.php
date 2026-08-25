<?php
/**
 * 搜尋紀錄 repository：寫入（旁路）、彙總 rollup、保留期清除、報表查詢。
 *
 * 全 prepared；分析失敗絕不影響搜尋主流程（caller 不依賴回傳）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Database;

use YangSheep\SmartSearch\Analytics\YSSsAnalyticsAdmission;
use YangSheep\SmartSearch\Security\YSSsSearchInput;
use YangSheep\SmartSearch\Support\YSSsText;

defined( 'ABSPATH' ) || exit;

final class YSSsQueryRepository {

	/**
	 * 正規化：trim、壓空白、小寫、截 100。
	 */
	public static function normalize( string $q ): string {
		$q = trim( preg_replace( '/\s+/u', ' ', $q ) ?? '' );
		if ( function_exists( 'mb_strtolower' ) ) {
			$q = mb_strtolower( $q, 'UTF-8' );
		} else {
			$q = strtolower( $q );
		}
		return YSSsText::truncate_chars( $q, 100 );
	}

	/**
	 * 行為紀錄寫入（唯一寫入瓶頸）。內建伺服器端 600 秒去重：對「同訪客 + 同正規化詞」於近
	 * 600 秒內已記錄者略過（跨來源），因此公開 /log 端點即使被重複呼叫也無法灌爆搜尋分析
	 *（前端 sessionStorage 去重僅為體驗、非安全邊界）。失敗絕不影響搜尋主流程。
	 */
	public static function log( string $raw, int $results_total, string $content_types, string $source, string $visitor_hash ): void {
		global $wpdb;

		$norm = self::normalize( $raw );
		if ( '' === $norm ) {
			return;
		}

		// Analytics-only admission：搜尋本身由 prepared SQL／escaping 保護；這裡只拒絕攻擊、
		// 已知 query parameters 與高信心機器亂數，避免污染報表與自動熱門詞。
		if ( ! YSSsAnalyticsAdmission::should_record( $raw, $results_total ) ) {
			return;
		}

		$table = YSSsSchema::queries_table();
		$vh    = substr( $visitor_hash, 0, 16 );
		$lock  = 'ys_ss_log_' . substr( hash( 'sha256', $wpdb->prefix . '|' . $vh . '|' . $norm ), 0, 54 );

		// GET_LOCK 將同訪客 + 同詞的 check-and-insert 串行化；鎖忙時分析旁路直接捨棄此次事件。
		// 這避免兩個同 receipt 併發請求同時看到 miss 後雙寫。
		$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		if ( 1 !== $acquired ) {
			return;
		}

		try {
			// 伺服器端去重：同訪客 + 同詞近 600 秒內已記錄則略過（用 norm_time 索引：同詞
			// 600 秒內列數極少，再濾 visitor_hash 很快）。
			$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 600 );
			$dup   = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE query_norm = %s AND created_at >= %s AND visitor_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$norm,
				$since,
				$vh
			) );
			if ( $dup ) {
				return;
			}

			$wpdb->insert( $table, [
				'query_norm'    => $norm,
				'query_raw'     => YSSsText::truncate_chars( trim( $raw ), 150 ),
				'results_total' => max( 0, $results_total ),
				'has_results'   => $results_total > 0 ? 1 : 0,
				'content_types' => substr( $content_types, 0, 60 ),
				'source'        => in_array( $source, [ 'bar', 'popup', 'page' ], true ) ? $source : 'bar',
				'visitor_hash'  => $vh,
				'created_at'    => current_time( 'mysql' ),
			] );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * 結果頁（B 模式）server 端記錄（source='page'）。去重已下沉至 log()
	 *（同訪客 + 同詞 600 秒跨來源），此處僅標記來源、不再重複去重邏輯。
	 */
	public static function log_page( string $raw, int $results_total, string $content_types, string $visitor_hash ): void {
		self::log( $raw, $results_total, $content_types, 'page', $visitor_hash );
	}

	/**
	 * 將指定日期（站台時區）的原始紀錄彙總進 terms_daily（冪等，可重跑）。
	 */
	public static function rollup_date( string $date ): void {
		self::with_maintenance_lock( static function () use ( $date ): void {
			global $wpdb;
			$queries = YSSsSchema::queries_table();
			$daily   = YSSsSchema::terms_daily_table();

			$result = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$daily} (term, stat_date, hits, zero_hits)
				 SELECT query_norm, %s, COUNT(*), SUM(CASE WHEN has_results = 0 THEN 1 ELSE 0 END)
				 FROM {$queries}
				 WHERE created_at >= %s AND created_at < %s
				 GROUP BY query_norm
				 ON DUPLICATE KEY UPDATE hits = VALUES(hits), zero_hits = VALUES(zero_hits)",
				$date,
				$date . ' 00:00:00',
				gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ) . ' 00:00:00'
			) );
			if ( false === $result ) {
				throw YSSsAnalyticsMutationException::database_failure();
			}
		} );
	}

	/**
	 * 保留期清除（批次 LIMIT 迴圈防鎖表）。0 = 永久保留。
	 */
	public static function purge_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		return self::with_maintenance_lock( static function () use ( $days ): int {
			global $wpdb;
			$cutoff_dt   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );
			$cutoff_date = substr( $cutoff_dt, 0, 10 );
			$queries     = YSSsSchema::queries_table();
			$daily       = YSSsSchema::terms_daily_table();
			$deleted     = 0;
			$complete    = false;

			for ( $i = 0; $i < 200; $i++ ) {
				$result = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$queries} WHERE created_at < %s LIMIT 5000",
					$cutoff_dt
				) );
				if ( false === $result ) {
					throw YSSsAnalyticsMutationException::database_failure();
				}
				$n        = (int) $result;
				$deleted += $n;
				if ( $n < 5000 ) {
					$complete = true;
					break;
				}
			}

			// A full final batch cannot prove that the bounded cleanup reached the end. Fail honestly;
			// the next cron/admin run resumes from the remaining rows before touching daily aggregates.
			if ( ! $complete ) {
				throw YSSsAnalyticsMutationException::database_failure();
			}

			$daily_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE stat_date < %s", $cutoff_date ) );
			if ( false === $daily_deleted ) {
				throw YSSsAnalyticsMutationException::database_failure();
			}

			return $deleted + (int) $daily_deleted;
		} );
	}

	/**
	 * 全清（設定頁「清除全部分析資料」）。
	 */
	public static function purge_all(): void {
		self::with_maintenance_lock( static function (): void {
			global $wpdb;
			$queries = YSSsSchema::queries_table();
			$daily   = YSSsSchema::terms_daily_table();
			self::assert_transactional_tables( $queries, $daily );

			self::with_transaction( static function () use ( $wpdb, $queries, $daily ): void {
				if ( false === $wpdb->query( "DELETE FROM {$queries}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					throw YSSsAnalyticsMutationException::database_failure();
				}
				if ( false === $wpdb->query( "DELETE FROM {$daily}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					throw YSSsAnalyticsMutationException::database_failure();
				}
			} );
		} );
	}

	/**
	 * 報表總覽：KPI + 每日趨勢 + Top / 零結果排行。
	 * 資料源 = terms_daily（含已 rollup 日）∪ 今日原始（即時）。
	 *
	 * @return array<string,mixed>
	 */
	public static function overview( string $from, string $to, int $limit = 50 ): array {
		global $wpdb;
		$queries = YSSsSchema::queries_table();
		$daily   = YSSsSchema::terms_daily_table();
		$today   = substr( current_time( 'mysql' ), 0, 10 );
		$limit   = max( 1, min( 200, $limit ) );

		$include_today = ( $to >= $today && $from <= $today );

		// 統一視圖：daily（< 今日）∪ 今日 raw 聚合
		$union = "SELECT term, stat_date, hits, zero_hits FROM {$daily}
				  WHERE stat_date >= %s AND stat_date <= %s AND stat_date < %s";
		$args  = [ $from, $to, $today ];
		if ( $include_today ) {
			$union .= " UNION ALL
				  SELECT query_norm AS term, %s AS stat_date, COUNT(*) AS hits,
						 SUM(CASE WHEN has_results = 0 THEN 1 ELSE 0 END) AS zero_hits
				  FROM {$queries}
				  WHERE created_at >= %s AND created_at < %s
				  GROUP BY query_norm";
			$args[] = $today;
			$args[] = $today . ' 00:00:00';
			$args[] = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) ) . ' 00:00:00';
		}

		// KPI
		$kpi_sql = "SELECT COALESCE(SUM(hits),0) AS total, COUNT(DISTINCT term) AS uniq, COALESCE(SUM(zero_hits),0) AS zero_total FROM ({$union}) u";
		$kpi     = $wpdb->get_row( $wpdb->prepare( $kpi_sql, ...$args ), ARRAY_A ) ?: [ 'total' => 0, 'uniq' => 0, 'zero_total' => 0 ]; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 趨勢（每日）
		$trend_sql = "SELECT stat_date, SUM(hits) AS hits FROM ({$union}) u GROUP BY stat_date ORDER BY stat_date ASC";
		$trend     = $wpdb->get_results( $wpdb->prepare( $trend_sql, ...$args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Top 排行
		$top_sql = "SELECT term, SUM(hits) AS hits, SUM(zero_hits) AS zero_hits FROM ({$union}) u GROUP BY term ORDER BY hits DESC, term ASC LIMIT {$limit}";
		$top     = $wpdb->get_results( $wpdb->prepare( $top_sql, ...$args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 零結果排行
		$zero_sql = "SELECT term, SUM(hits) AS hits, SUM(zero_hits) AS zero_hits FROM ({$union}) u GROUP BY term HAVING SUM(zero_hits) > 0 ORDER BY zero_hits DESC, hits DESC LIMIT {$limit}";
		$zero     = $wpdb->get_results( $wpdb->prepare( $zero_sql, ...$args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total = (int) $kpi['total'];

		return [
			'from'  => $from,
			'to'    => $to,
			'kpi'   => [
				'total'     => $total,
				'unique'    => (int) $kpi['uniq'],
				'zero'      => (int) $kpi['zero_total'],
				'zero_rate' => $total > 0 ? round( (int) $kpi['zero_total'] / $total * 100, 1 ) : 0.0,
			],
			'trend' => array_map( static fn( $r ) => [ 'date' => (string) $r['stat_date'], 'hits' => (int) $r['hits'] ], $trend ),
			'top'   => array_map( static fn( $r ) => [ 'term' => (string) $r['term'], 'hits' => (int) $r['hits'], 'zero_hits' => (int) $r['zero_hits'] ], $top ),
			'zero'  => array_map( static fn( $r ) => [ 'term' => (string) $r['term'], 'hits' => (int) $r['hits'], 'zero_hits' => (int) $r['zero_hits'] ], $zero ),
		];
	}

	/**
	 * 自動熱門詞（建議用）：只採有結果事件，以 positive hits 排序並排除零結果率 > 80%。
	 *
	 * @return array<int,string>
	 */
	public static function auto_terms( int $window_days, int $limit ): array {
		global $wpdb;
		$daily = YSSsSchema::terms_daily_table();
		$from  = gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) - $window_days * DAY_IN_SECONDS );
		$requested = max( 1, min( 50, $limit ) );
		$scan_limit = min( 200, max( 50, $requested * 5 ) );

		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT term FROM {$daily}
			 WHERE stat_date >= %s
			 GROUP BY term
			 HAVING SUM(hits) - SUM(zero_hits) > 0 AND ( SUM(zero_hits) / SUM(hits) ) <= 0.8
			 ORDER BY SUM(hits) - SUM(zero_hits) DESC, SUM(zero_hits) ASC, term ASC
			 LIMIT {$scan_limit}",
			$from
		) );

		// SQL 先有界 over-fetch，再以 raw-input SOT 過濾並於 accepted rows 補滿 requested limit。
		$accepted = [];
		foreach ( array_map( 'strval', $rows ?: [] ) as $term ) {
			$input = YSSsSearchInput::inspect( $term );
			if ( $input['blocked'] || '' === $input['query'] || ! YSSsAnalyticsAdmission::should_record( $term, 1 ) ) {
				continue;
			}
			$accepted[] = $input['query'];
			if ( count( $accepted ) >= $requested ) {
				break;
			}
		}

		return $accepted;
	}

	/**
	 * 單筆刪除：移除某關鍵字（正規化後）在原始表與彙總表的全部紀錄。
	 * 分析報表以「詞」為單位，故單筆刪除 = 刪除該詞的所有紀錄。
	 *
	 * @return array{queries:int,daily:int,total:int} 各表與合計刪除筆數。
	 */
	public static function delete_term( string $term ): array {
		global $wpdb;
		$norm = self::normalize( $term );
		if ( '' === $norm ) {
			return [ 'queries' => 0, 'daily' => 0, 'total' => 0 ];
		}
		$queries = YSSsSchema::queries_table();
		$daily   = YSSsSchema::terms_daily_table();

		return self::with_maintenance_lock( static function () use ( $wpdb, $queries, $daily, $norm ): array {
			self::assert_transactional_tables( $queries, $daily );

			return self::with_transaction( static function () use ( $wpdb, $queries, $daily, $norm ): array {
				$dq = $wpdb->query( $wpdb->prepare( "DELETE FROM {$queries} WHERE query_norm = %s", $norm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( false === $dq ) {
					throw YSSsAnalyticsMutationException::database_failure();
				}

				$dd = $wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE term = %s", $norm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( false === $dd ) {
					throw YSSsAnalyticsMutationException::database_failure();
				}

				return [
					'queries' => (int) $dq,
					'daily'   => (int) $dd,
					'total'   => (int) $dq + (int) $dd,
				];
			} );
		} );
	}

	/**
	 * Refuse transaction-dependent mutations unless both analytics tables are InnoDB.
	 */
	private static function assert_transactional_tables( string $queries, string $daily ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
			$queries,
			$daily
		), ARRAY_A );
		if ( ! is_array( $rows ) || 2 !== count( $rows ) ) {
			throw YSSsAnalyticsMutationException::database_failure();
		}
		foreach ( $rows as $row ) {
			$engine = (string) ( $row['ENGINE'] ?? $row['engine'] ?? '' );
			if ( 'innodb' !== strtolower( $engine ) ) {
				throw YSSsAnalyticsMutationException::database_failure();
			}
		}
	}

	/**
	 * @template T
	 * @param callable():T $operation
	 * @return T
	 */
	private static function with_transaction( callable $operation ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw YSSsAnalyticsMutationException::database_failure();
		}

		$transaction_open = true;
		try {
			$result = $operation();
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw YSSsAnalyticsMutationException::database_failure();
			}
			$transaction_open = false;
			return $result;
		} catch ( \Throwable $error ) {
			if ( $transaction_open ) {
				$wpdb->query( 'ROLLBACK' );
			}
			if ( $error instanceof YSSsAnalyticsMutationException ) {
				throw $error;
			}
			throw YSSsAnalyticsMutationException::database_failure();
		}
	}

	/**
	 * Serialize rollup and exact-term deletion for this site's analytics tables.
	 *
	 * @template T
	 * @param callable():T $operation
	 * @return T
	 */
	private static function with_maintenance_lock( callable $operation ) {
		global $wpdb;
		$identity  = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . YSSsSchema::queries_table();
		$lock_name = 'ys_ss_maint_' . substr( hash( 'sha256', $identity ), 0, 52 );
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock_name ) );
		if ( null === $acquired || false === $acquired ) {
			throw YSSsAnalyticsMutationException::database_failure();
		}
		if ( 0 === $acquired || '0' === $acquired ) {
			throw YSSsAnalyticsMutationException::busy();
		}
		if ( 1 !== $acquired && '1' !== $acquired ) {
			throw YSSsAnalyticsMutationException::database_failure();
		}

		try {
			return $operation();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * 兩表筆數（設定頁顯示）。
	 *
	 * @return array{queries:int,daily:int}
	 */
	public static function counts(): array {
		global $wpdb;
		return [
			'queries' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . YSSsSchema::queries_table() ),     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'daily'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . YSSsSchema::terms_daily_table() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		];
	}
}

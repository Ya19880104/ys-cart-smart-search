<?php
/**
 * 搜尋紀錄 repository：寫入（旁路）、彙總 rollup、保留期清除、報表查詢。
 *
 * 全 prepared；分析失敗絕不影響搜尋主流程（caller 不依賴回傳）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Database;

use YangSheep\SmartSearch\Security\YSSsInjectionGuard;
use YangSheep\SmartSearch\Security\YSSsSearchInput;

defined( 'ABSPATH' ) || exit;

final class YSSsQueryRepository {

	/**
	 * 正規化：trim、壓空白、小寫、截 100。
	 */
	public static function normalize( string $q ): string {
		$q = trim( preg_replace( '/\s+/u', ' ', $q ) ?? '' );
		if ( function_exists( 'mb_strtolower' ) ) {
			$q = mb_strtolower( $q, 'UTF-8' );
			return mb_substr( $q, 0, 100, 'UTF-8' );
		}
		return substr( strtolower( $q ), 0, 100 );
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

		// 進站攔截（唯一寫入瓶頸）：攻擊探測（SSTI/XSS/穿越/SQLi/SSRF）一律不記錄，
		// 避免污染分析與自動熱門建議。原始詞也一併檢查（normalize 不會移除注入標記，
		// 但保險起見兩者都驗）。
		if ( YSSsInjectionGuard::is_attack( $norm ) || YSSsInjectionGuard::is_attack( $raw ) ) {
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
				'query_raw'     => mb_substr( trim( $raw ), 0, 150 ),
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
		global $wpdb;
		$queries = YSSsSchema::queries_table();
		$daily   = YSSsSchema::terms_daily_table();

		$wpdb->query( $wpdb->prepare(
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
	}

	/**
	 * 保留期清除（批次 LIMIT 迴圈防鎖表）。0 = 永久保留。
	 */
	public static function purge_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$cutoff_dt   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );
		$cutoff_date = substr( $cutoff_dt, 0, 10 );
		$queries     = YSSsSchema::queries_table();
		$daily       = YSSsSchema::terms_daily_table();
		$deleted     = 0;

		for ( $i = 0; $i < 200; $i++ ) {
			$n = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$queries} WHERE created_at < %s LIMIT 5000",
				$cutoff_dt
			) );
			$deleted += $n;
			if ( $n < 5000 ) {
				break;
			}
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE stat_date < %s", $cutoff_date ) );

		return $deleted;
	}

	/**
	 * 全清（設定頁「清除全部分析資料」）。
	 */
	public static function purge_all(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . YSSsSchema::queries_table() );      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . YSSsSchema::terms_daily_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
	 * 自動熱門詞（建議用）：取樣窗內 hits Top、排除零結果率 > 80%。
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
			 HAVING SUM(hits) > 0 AND ( SUM(zero_hits) / SUM(hits) ) <= 0.8
			 ORDER BY SUM(hits) DESC
			 LIMIT {$scan_limit}",
			$from
		) );

		// SQL 先有界 over-fetch，再以 raw-input SOT 過濾並於 accepted rows 補滿 requested limit。
		$accepted = [];
		foreach ( array_map( 'strval', $rows ?: [] ) as $term ) {
			$input = YSSsSearchInput::inspect( $term );
			if ( $input['blocked'] || '' === $input['query'] ) {
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
	 * @return array{queries:int,daily:int} 各表刪除筆數。
	 */
	public static function delete_term( string $term ): array {
		global $wpdb;
		$norm = self::normalize( $term );
		if ( '' === $norm ) {
			return [ 'queries' => 0, 'daily' => 0 ];
		}
		$queries = YSSsSchema::queries_table();
		$daily   = YSSsSchema::terms_daily_table();

		$dq = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$queries} WHERE query_norm = %s", $norm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dd = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE term = %s", $norm ) );          // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [ 'queries' => $dq, 'daily' => $dd ];
	}

	/**
	 * 清理注入/攻擊探測紀錄：掃描兩表，凡辨識為攻擊探測的詞一律刪除。
	 * 只移除攻擊列，保留正常搜尋（含正常的零結果商機詞）。批次防鎖表。
	 *
	 * @return int 刪除的原始紀錄筆數。
	 */
	public static function purge_injection(): int {
		global $wpdb;
		$queries = YSSsSchema::queries_table();
		$daily   = YSSsSchema::terms_daily_table();

		// 以 id 分頁掃全表（不靠脆弱的 SQL 前置過濾），PHP 端 is_attack 為唯一判準。
		// 保留期已上限資料量；本動作由後台手動觸發、頻率低。
		$deleted = 0;

		$last = 0;
		for ( $i = 0; $i < 1000; $i++ ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, query_norm FROM {$queries} WHERE id > %d ORDER BY id ASC LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$last
			), ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			$kill = [];
			foreach ( $rows as $r ) {
				$last = (int) $r['id'];
				if ( YSSsInjectionGuard::is_attack( (string) $r['query_norm'] ) ) {
					$kill[] = (int) $r['id'];
				}
			}
			if ( $kill ) {
				$in       = implode( ',', array_map( 'intval', $kill ) );
				$deleted += (int) $wpdb->query( "DELETE FROM {$queries} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			if ( count( $rows ) < 2000 ) {
				break;
			}
		}

		$dlast = 0;
		for ( $i = 0; $i < 1000; $i++ ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, term FROM {$daily} WHERE id > %d ORDER BY id ASC LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dlast
			), ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			$dkill = [];
			foreach ( $rows as $r ) {
				$dlast = (int) $r['id'];
				if ( YSSsInjectionGuard::is_attack( (string) $r['term'] ) ) {
					$dkill[] = (int) $r['id'];
				}
			}
			if ( $dkill ) {
				$in = implode( ',', array_map( 'intval', $dkill ) );
				$wpdb->query( "DELETE FROM {$daily} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			if ( count( $rows ) < 2000 ) {
				break;
			}
		}

		return $deleted;
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

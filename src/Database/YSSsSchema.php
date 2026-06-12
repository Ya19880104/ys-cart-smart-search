<?php
/**
 * 自有資料表（三張，獨立於核心 54 張之外；ADR-058 §3）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Database;

defined( 'ABSPATH' ) || exit;

final class YSSsSchema {

	private const DB_VERSION        = '1.0.0';
	private const OPTION_DB_VERSION = 'ys_ss_db_version';

	public static function queries_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ys_ss_queries';
	}

	public static function terms_daily_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ys_ss_terms_daily';
	}

	public static function keywords_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ys_ss_keywords';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$queries = self::queries_table();
		$daily   = self::terms_daily_table();
		$kw      = self::keywords_table();

		dbDelta( "CREATE TABLE {$queries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			query_norm varchar(100) NOT NULL,
			query_raw varchar(150) NOT NULL DEFAULT '',
			results_total int(10) unsigned NOT NULL DEFAULT 0,
			has_results tinyint(1) NOT NULL DEFAULT 1,
			content_types varchar(60) NOT NULL DEFAULT 'products',
			source varchar(12) NOT NULL DEFAULT 'bar',
			visitor_hash char(16) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY norm_time (query_norm, created_at),
			KEY time_idx (created_at),
			KEY zero_idx (has_results, created_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$daily} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term varchar(100) NOT NULL,
			stat_date date NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			zero_hits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY term_date (term, stat_date),
			KEY date_hits (stat_date, hits)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$kw} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword varchar(100) NOT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY active_sort (is_active, sort_order)
		) {$charset};" );

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );

		// 預設設定（已存在則不覆寫）
		YSSsSettings::ensure_defaults();
	}

	/**
	 * 檔案更新（無啟用 hook）時補跑 schema。
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_DB_VERSION, '' ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}

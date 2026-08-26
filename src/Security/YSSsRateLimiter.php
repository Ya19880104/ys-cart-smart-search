<?php
/**
 * 輕量限流（transient 計數、60 秒滑動窗近似）。
 *
 * 公開端點（query / suggest / log）唯一的寫入防線之一；
 * 超限回 429（caller 處理）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

defined( 'ABSPATH' ) || exit;

final class YSSsRateLimiter {

	/**
	 * @return bool true = 放行；false = 超限。
	 */
	public static function allow( string $action, int $limit_per_minute ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'ys_ss_rl_' . sanitize_key( $action ) . '_' . substr( md5( $ip ), 0, 12 );

		$count = (int) get_transient( $key );
		if ( $count >= $limit_per_minute ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * 訪客雜湊：IP+UA+指定 UTC 日期鹽 → 16 字截斷。
	 * 只能做「同日去重」維度，無法跨日追蹤、無法回推（無 PII 入庫）。
	 */
	public static function visitor_hash_at( int $timestamp ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return substr( hash( 'sha256', $ip . '|' . $ua . '|' . gmdate( 'Ymd', $timestamp ) . '|' . wp_salt( 'nonce' ) ), 0, 16 );
	}

	public static function visitor_hash(): string {
		return self::visitor_hash_at( time() );
	}
}

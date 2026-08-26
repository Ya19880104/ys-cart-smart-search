<?php
/**
 * 公開端點限流（MySQL advisory lock 串行化 transient 計數）。
 *
 * 公開端點（query / suggest / log）唯一的寫入防線之一；
 * 超限或計數權威不可用皆回 false（caller 以 429／分析旁路處理）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

defined( 'ABSPATH' ) || exit;

final class YSSsRateLimiter {

	/**
	 * @return bool true = 放行；false = 超限或權威不可用。
	 */
	public static function allow( string $action, int $limit_per_minute ): bool {
		global $wpdb;

		$action_key = sanitize_key( $action );
		if ( '' === $action_key || $limit_per_minute <= 0
			|| ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key      = 'ys_ss_rl_' . $action_key . '_' . substr( md5( $ip ), 0, 12 );
		$identity = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . (string) ( $wpdb->prefix ?? '' ) . '|' . $key;
		$lock     = 'ys_ss_rate_' . substr( hash( 'sha256', $identity ), 0, 52 );
		$owned    = false;
		$decision = false;

		try {
			// Fail-fast：公開流量不等待被其他請求持有的鎖。鎖內的 transient read/increment/write
			// 才是一個決策；這要求標準單一 primary wpdb connection 與順序一致的 object cache。
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
			if ( self::lock_succeeded( $acquired ) ) {
				$owned  = true;
				$count  = self::counter_value( get_transient( $key ) );
				if ( null !== $count && $count < $limit_per_minute ) {
					// count 每次必定遞增，因此 false 不是「值未變」，而是無法證明已持久化。
					$decision = true === set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
				}
			}
		} catch ( \Throwable $error ) {
			$decision = false;
		} finally {
			if ( $owned ) {
				try {
					$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
					if ( ! self::lock_succeeded( $released ) ) {
						$decision = false;
					}
				} catch ( \Throwable $error ) {
					$decision = false;
				}
			}
		}

		return $decision;
	}

	/**
	 * wpdb 正常可能回 int 1 或 string "1"；其他值一律不猜測。
	 *
	 * @param mixed $value
	 */
	private static function lock_succeeded( $value ): bool {
		return 1 === $value || '1' === $value;
	}

	/**
	 * @param mixed $stored
	 */
	private static function counter_value( $stored ): ?int {
		if ( false === $stored ) {
			return 0;
		}
		if ( is_int( $stored ) ) {
			return $stored >= 0 ? $stored : null;
		}
		if ( ! is_string( $stored ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $stored ) ) {
			return null;
		}

		$max = (string) PHP_INT_MAX;
		if ( strlen( $stored ) > strlen( $max )
			|| ( strlen( $stored ) === strlen( $max ) && strcmp( $stored, $max ) > 0 ) ) {
			return null;
		}
		return (int) $stored;
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

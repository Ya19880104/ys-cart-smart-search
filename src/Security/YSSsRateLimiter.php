<?php
/**
 * 公開端點限流（MySQL advisory lock 串行化持久 DB 計數）。
 *
 * 公開端點（query / suggest / log）唯一的寫入防線之一；
 * 超限或計數權威不可用皆回 false（caller 以 429／分析旁路處理）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

defined( 'ABSPATH' ) || exit;

final class YSSsRateLimiter {

	private const ROW_PREFIX = 'ys_ss_rate_v1_';
	private const STATE_TAG  = 'v1';
	private const WINDOW     = MINUTE_IN_SECONDS;
	private const CLEAN_LIMIT = 5000;
	private const CLEAN_BATCHES = 20;

	/**
	 * @return bool true = 放行；false = 超限或權威不可用。
	 */
	public static function allow( string $action, int $limit_per_minute ): bool {
		global $wpdb;

		$action_key = sanitize_key( $action );
		if ( '' === $action_key || strlen( $action_key ) > 32 || $limit_per_minute <= 0
			|| ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_var' )
			|| ! method_exists( $wpdb, 'get_results' )
			|| ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		$table = self::options_table( $wpdb );
		if ( null === $table ) {
			return false;
		}

		$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$client_id = self::client_identity( $ip );
		if ( null === $client_id ) {
			return false;
		}
		$key      = self::ROW_PREFIX . $action_key . '_' . $client_id;
		$identity = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $table . '|' . $key;
		$lock     = 'ys_ss_rate_' . substr( hash( 'sha256', $identity ), 0, 52 );
		$owned    = false;
		$decision = false;

		try {
			// Fail-fast：公開流量不等待被其他請求持有的鎖。GET_LOCK、直接 DB read/write/readback
			// 與 RELEASE_LOCK 必須使用標準 WordPress 同一個 single-primary wpdb authority。
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
			if ( self::lock_succeeded( $acquired ) ) {
				$owned = true;
				$state = self::read_state( $wpdb, $table, $key );
				if ( null !== $state ) {
					$now = \time();
					if ( ! $state['exists'] || $state['expires'] <= $now ) {
						$next_expires = $now + self::WINDOW;
						$next_count   = 1;
					} elseif ( $state['count'] < $limit_per_minute ) {
						// 固定的一分鐘視窗：後續成功不延長第一筆建立的 expiry。
						$next_expires = $state['expires'];
						$next_count   = $state['count'] + 1;
					} else {
						$next_expires = 0;
						$next_count   = 0;
					}

					if ( $next_count > 0 ) {
						$decision = self::write_and_verify_state(
							$wpdb,
							$table,
							$key,
							$next_expires,
							$next_count
						);
					}
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
	 * 移除本外掛 namespace 內目前仍為 canonical 且已過期的限流列。
	 * WHERE 直接比對「目前值」並在同一個 bounded DELETE 執行，避免先查名單後誤刪剛刷新列。
	 */
	public static function cleanup_expired(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' )
			|| ! method_exists( $wpdb, 'esc_like' ) ) {
			return false;
		}

		$table = self::options_table( $wpdb );
		if ( null === $table ) {
			return false;
		}

		$now = time();
		for ( $batch = 0; $batch < self::CLEAN_BATCHES; ++$batch ) {
			try {
				$sql = $wpdb->prepare(
					"DELETE FROM `{$table}`
					WHERE option_name LIKE %s
					AND option_name REGEXP %s
					AND option_value REGEXP %s
					AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, ':', 2), ':', -1) AS UNSIGNED) <= %d
					LIMIT %d",
					$wpdb->esc_like( self::ROW_PREFIX ) . '%',
					'^' . self::ROW_PREFIX . '[a-z0-9_-]{1,32}_[a-f0-9]{24}$',
					'^' . self::STATE_TAG . ':[1-9][0-9]*:[1-9][0-9]*$',
					$now,
					self::CLEAN_LIMIT
				);
				if ( ! is_string( $sql ) || '' === $sql ) {
					return false;
				}
				$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} catch ( \Throwable $error ) {
				return false;
			}

			if ( ! is_int( $result ) || $result < 0 || ! self::database_result_is_clear( $wpdb ) ) {
				return false;
			}
			if ( $result < self::CLEAN_LIMIT ) {
				return true;
			}
		}

		// 每批都滿代表仍可能有 backlog；不假報完成，交由下一次排程續跑。
		return false;
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
	 * @param mixed $wpdb
	 */
	private static function options_table( $wpdb ): ?string {
		if ( ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) ) {
			return null;
		}
		$table = $wpdb->prefix . 'options';
		return 1 === preg_match( '/\A[a-z0-9_]+\z/iD', $table ) ? $table : null;
	}

	private static function client_identity( string $ip ): ?string {
		try {
			$salt = wp_salt( 'nonce' );
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_string( $salt ) || '' === $salt ) {
			return null;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid REMOTE_ADDR falls back to keyed raw bytes.
		$mapped_prefix = str_repeat( "\0", 10 ) . "\xff\xff";
		if ( false !== $packed && 16 === strlen( $packed )
			&& 0 === strncmp( $packed, $mapped_prefix, 12 ) ) {
			$material = substr( $packed, 12, 4 );
		} elseif ( false !== $packed && 16 === strlen( $packed ) ) {
			// IPv6 privacy addresses in one routed /64 share a quota and one durable row.
			$material = substr( $packed, 0, 8 ) . str_repeat( "\0", 8 );
		} elseif ( false !== $packed && 4 === strlen( $packed ) ) {
			$material = $packed;
		} else {
			$material = $ip;
		}

		return substr( hash_hmac( 'sha256', $material, $salt ), 0, 24 );
	}

	/**
	 * @param mixed $wpdb
	 * @return array{exists:bool,expires:int,count:int}|null null = authority unavailable/ambiguous.
	 */
	private static function read_state( $wpdb, string $table, string $key ): ?array {
		try {
			$sql = $wpdb->prepare(
				"SELECT option_name, option_value FROM `{$table}` WHERE option_name = %s LIMIT 2",
				$key
			);
			if ( ! is_string( $sql ) || '' === $sql ) {
				return null;
			}
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $error ) {
			return null;
		}

		if ( ! is_array( $rows ) || ! self::database_result_is_clear( $wpdb ) ) {
			return null;
		}
		if ( [] === $rows ) {
			return [ 'exists' => false, 'expires' => 0, 'count' => 0 ];
		}
		if ( 1 !== count( $rows ) ) {
			return null;
		}

		$row = $rows[0];
		if ( ! is_array( $row )
			|| 2 !== count( $row )
			|| ! isset( $row['option_name'], $row['option_value'] )
			|| ! is_string( $row['option_name'] )
			|| ! is_string( $row['option_value'] )
			|| $key !== $row['option_name'] ) {
			return null;
		}

		$parts = explode( ':', $row['option_value'] );
		if ( 3 !== count( $parts ) || self::STATE_TAG !== $parts[0] ) {
			return null;
		}
		$expires = self::unsigned_integer( $parts[1] );
		$count   = self::unsigned_integer( $parts[2] );
		if ( null === $expires || null === $count || $expires <= 0 || $count <= 0 ) {
			return null;
		}

		return [ 'exists' => true, 'expires' => $expires, 'count' => $count ];
	}

	/**
	 * @param mixed $wpdb
	 */
	private static function write_and_verify_state(
		$wpdb,
		string $table,
		string $key,
		int $expires,
		int $count
	): bool {
		$value = self::STATE_TAG . ':' . $expires . ':' . $count;
		try {
			$sql = $wpdb->prepare(
				"INSERT INTO `{$table}` (option_name, option_value, autoload)
				VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
				$key,
				$value
			);
			if ( ! is_string( $sql ) || '' === $sql ) {
				return false;
			}
			$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( \Throwable $error ) {
			return false;
		}
		if ( false === $written || ! self::database_result_is_clear( $wpdb ) ) {
			return false;
		}

		$verified = self::read_state( $wpdb, $table, $key );
		return null !== $verified
			&& $verified['exists']
			&& $expires === $verified['expires']
			&& $count === $verified['count'];
	}

	/**
	 * @param mixed $value
	 */
	private static function unsigned_integer( $value ): ?int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) ) {
			return null;
		}

		$max = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max )
			|| ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			return null;
		}
		return (int) $value;
	}

	/**
	 * @param mixed $wpdb
	 */
	private static function database_result_is_clear( $wpdb ): bool {
		return ! isset( $wpdb->last_error )
			|| ( is_string( $wpdb->last_error ) && '' === $wpdb->last_error );
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

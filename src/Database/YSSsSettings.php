<?php
/**
 * 設定（單一 option `ys_ss_settings`，集中預設值與 sanitize）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Database;

defined( 'ABSPATH' ) || exit;

final class YSSsSettings {

	public const OPTION = 'ys_ss_settings';

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'takeover'            => false,   // 接管核心 [ys_ec_search] / [ys_ec_search_icon]
			'suggest_count'       => 8,       // 熱門搜尋 chips 數量（需求③，預設 8）
			'suggest_window_days' => 30,      // 自動統計取樣窗
			'recent_enabled'      => true,    // 最近搜尋（純 localStorage）
			'retention_days'      => 180,     // 需求⑤ 預設 180 天
			'group_order'         => [ 'products', 'categories', 'posts' ],
			'products'            => [
				'limit'      => 6,
				'show_image' => true,
				'show_price' => true,
				'show_sku'   => false,
			],
			'categories'          => [
				'enabled'    => true,
				'limit'      => 3,
				'show_count' => true,
			],
			'posts'               => [
				'enabled'     => false,  // 預設純商品搜尋（需求⑦）
				'limit'       => 3,
				'post_types'  => [ 'post', 'page' ],
				'show_thumb'  => true,
				'excerpt_len' => 40,
			],
		];
	}

	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', 'no' );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		return self::sanitize( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * 部分更新（淺層覆寫 + sanitize），回傳更新後設定。
	 *
	 * @param array<string,mixed> $patch
	 * @return array<string,mixed>
	 */
	public static function update( array $patch ): array {
		$merged = array_replace_recursive( self::all(), $patch );
		$clean  = self::sanitize( $merged );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		$d   = self::defaults();
		$out = $d;

		$out['takeover']            = ! empty( $raw['takeover'] );
		$out['recent_enabled']      = array_key_exists( 'recent_enabled', $raw ) ? ! empty( $raw['recent_enabled'] ) : $d['recent_enabled'];
		$out['suggest_count']       = max( 0, min( 20, (int) ( $raw['suggest_count'] ?? $d['suggest_count'] ) ) );
		$out['suggest_window_days'] = in_array( (int) ( $raw['suggest_window_days'] ?? 30 ), [ 7, 30, 90 ], true )
			? (int) $raw['suggest_window_days'] : 30;
		$out['retention_days']      = (int) ( $raw['retention_days'] ?? $d['retention_days'] );
		if ( 0 !== $out['retention_days'] ) {
			$out['retention_days'] = max( 7, min( 3650, $out['retention_days'] ) );
		}

		$order = array_values( array_intersect(
			array_map( 'sanitize_key', (array) ( $raw['group_order'] ?? [] ) ),
			[ 'products', 'categories', 'posts' ]
		) );
		$out['group_order'] = array_values( array_unique( array_merge( $order, $d['group_order'] ) ) );

		$p = (array) ( $raw['products'] ?? [] );
		$out['products'] = [
			'limit'      => max( 1, min( 12, (int) ( $p['limit'] ?? 6 ) ) ),
			'show_image' => array_key_exists( 'show_image', $p ) ? ! empty( $p['show_image'] ) : true,
			'show_price' => array_key_exists( 'show_price', $p ) ? ! empty( $p['show_price'] ) : true,
			'show_sku'   => ! empty( $p['show_sku'] ),
		];

		$c = (array) ( $raw['categories'] ?? [] );
		$out['categories'] = [
			'enabled'    => array_key_exists( 'enabled', $c ) ? ! empty( $c['enabled'] ) : true,
			'limit'      => max( 1, min( 10, (int) ( $c['limit'] ?? 3 ) ) ),
			'show_count' => array_key_exists( 'show_count', $c ) ? ! empty( $c['show_count'] ) : true,
		];

		$po          = (array) ( $raw['posts'] ?? [] );
		$public_pts  = get_post_types( [ 'public' => true ], 'names' );
		unset( $public_pts['attachment'] );
		$picked = array_values( array_intersect(
			array_map( 'sanitize_key', (array) ( $po['post_types'] ?? [ 'post', 'page' ] ) ),
			array_values( $public_pts )
		) );
		$out['posts'] = [
			'enabled'     => ! empty( $po['enabled'] ),
			'limit'       => max( 1, min( 10, (int) ( $po['limit'] ?? 3 ) ) ),
			'post_types'  => $picked ?: [ 'post', 'page' ],
			'show_thumb'  => array_key_exists( 'show_thumb', $po ) ? ! empty( $po['show_thumb'] ) : true,
			'excerpt_len' => max( 0, min( 200, (int) ( $po['excerpt_len'] ?? 40 ) ) ),
		];

		return $out;
	}
}

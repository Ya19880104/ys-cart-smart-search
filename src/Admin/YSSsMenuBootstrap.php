<?php
/**
 * 後台選單整合（5 原則①：不自建主選單）。
 *
 * 比照 ys-cart-affiliate 的文件化整合模式：
 *   - 子選單掛核心「電商系統」（parent `ys-cart`）；slug 用 `ys-cart-` 前綴
 *     → 核心 YSAdminAssets 對該前綴自動載入 takeover 資產。
 *   - `ys_ec_admin_nav_groups` 加 nav 群組（takeover shell 側欄）。
 *   - `ys_ec_admin_nav_parent_slugs` / `ys_ec_external_admin_pages(+labels)`
 *     讓 router/權限系統認得本外掛頁面。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Admin;

defined( 'ABSPATH' ) || exit;

final class YSSsMenuBootstrap {

	public const SLUG_SETTINGS  = 'ys-cart-smart-search';
	public const SLUG_ANALYTICS = 'ys-cart-smart-search-analytics';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'register_submenus' ], 58 );
		add_filter( 'ys_ec_admin_nav_groups', [ self::class, 'add_nav_group' ] );
		add_filter( 'ys_ec_admin_nav_parent_slugs', [ self::class, 'add_nav_parent_slugs' ] );
		add_filter( 'ys_ec_external_admin_pages', [ self::class, 'external_admin_pages' ] );
		add_filter( 'ys_ec_external_admin_page_labels', [ self::class, 'external_admin_page_labels' ] );
	}

	public static function register_submenus(): void {
		$cap = current_user_can( 'manage_ys_ecommerce' ) ? 'manage_ys_ecommerce' : 'manage_options';

		add_submenu_page(
			'ys-cart',
			__( '智慧搜尋', 'ys-cart-smart-search' ),
			__( '智慧搜尋', 'ys-cart-smart-search' ),
			$cap,
			self::SLUG_SETTINGS,
			[ YSSsSettingsAdmin::class, 'render' ]
		);

		add_submenu_page(
			'ys-cart',
			__( '搜尋分析', 'ys-cart-smart-search' ),
			__( '搜尋分析', 'ys-cart-smart-search' ),
			$cap,
			self::SLUG_ANALYTICS,
			[ YSSsAnalyticsAdmin::class, 'render' ]
		);
	}

	/**
	 * @param array<string,mixed> $groups
	 * @return array<string,mixed>
	 */
	public static function add_nav_group( array $groups ): array {
		$groups['smart_search'] = [
			'label' => __( '智慧搜尋', 'ys-cart-smart-search' ),
			'icon'  => 'dashicons-search',
			'slugs' => [ self::SLUG_SETTINGS, self::SLUG_ANALYTICS ],
		];
		return $groups;
	}

	/**
	 * @param array<int,string> $parents
	 * @return array<int,string>
	 */
	public static function add_nav_parent_slugs( array $parents ): array {
		$parents[] = self::SLUG_SETTINGS;
		$parents[] = self::SLUG_ANALYTICS;
		return array_values( array_unique( array_map( 'sanitize_key', $parents ) ) );
	}

	/**
	 * @param array<int,string> $pages
	 * @return array<int,string>
	 */
	public static function external_admin_pages( array $pages ): array {
		$pages[] = self::SLUG_SETTINGS;
		$pages[] = self::SLUG_ANALYTICS;
		return array_values( array_unique( array_map( 'sanitize_key', $pages ) ) );
	}

	/**
	 * @param array<string,string> $labels
	 * @return array<string,string>
	 */
	public static function external_admin_page_labels( array $labels ): array {
		$labels[ self::SLUG_SETTINGS ]  = __( '智慧搜尋', 'ys-cart-smart-search' );
		$labels[ self::SLUG_ANALYTICS ] = __( '搜尋分析', 'ys-cart-smart-search' );
		return $labels;
	}
}

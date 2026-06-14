<?php
/**
 * 後台選單整合（5 原則①：不自建主選單）。
 *
 *   - 子選單掛核心「電商系統」（parent `ys-cart`）；slug 用 `ys-cart-` 前綴
 *     → 核心 YSAdminAssets 對該前綴自動載入 takeover 資產。
 *   - takeover 側欄：自成「進階搜尋」群組（設定＋說明），排在「有聲書」群組之前。
 *   - 搜尋分析改整合進核心「報表分析」的「搜尋分析」tab（核心 2.52.44+ 的
 *     ys_ec_report_tabs / ys_ec_report_render_tab_search）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Admin;

defined( 'ABSPATH' ) || exit;

final class YSSsMenuBootstrap {

	public const SLUG_SETTINGS  = 'ys-cart-smart-search';
	public const SLUG_ANALYTICS = 'ys-cart-smart-search-analytics'; // 保留常數相容；不再註冊獨立頁。
	public const SLUG_HELP      = 'ys-cart-smart-search-help';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'register_submenus' ], 58 );
		// 自成群組需排在有聲書之前 → 用較晚 priority 確保有聲書群組已注入。
		add_filter( 'ys_ec_admin_nav_groups', [ self::class, 'add_nav_group' ], 20 );
		add_filter( 'ys_ec_admin_nav_parent_slugs', [ self::class, 'add_nav_parent_slugs' ] );
		add_filter( 'ys_ec_external_admin_pages', [ self::class, 'external_admin_pages' ] );
		add_filter( 'ys_ec_external_admin_page_labels', [ self::class, 'external_admin_page_labels' ] );

		// 搜尋分析 → 核心「報表分析」tab（核心 2.52.44+ 才有此擴充點；舊核心自動無效）。
		add_filter( 'ys_ec_report_tabs', [ self::class, 'add_report_tab' ] );
		add_action( 'ys_ec_report_render_tab_search', [ YSSsAnalyticsAdmin::class, 'render_body' ], 10, 2 );
	}

	public static function register_submenus(): void {
		$cap = current_user_can( 'manage_ys_ecommerce' ) ? 'manage_ys_ecommerce' : 'manage_options';

		add_submenu_page(
			'ys-cart',
			__( '進階搜尋', 'ys-cart-smart-search' ),
			__( '進階搜尋', 'ys-cart-smart-search' ),
			$cap,
			self::SLUG_SETTINGS,
			[ YSSsSettingsAdmin::class, 'render' ]
		);

		add_submenu_page(
			'ys-cart',
			__( '進階搜尋說明', 'ys-cart-smart-search' ),
			__( '進階搜尋說明', 'ys-cart-smart-search' ),
			$cap,
			self::SLUG_HELP,
			[ YSSsHelpAdmin::class, 'render' ]
		);
	}

	/**
	 * 自成「進階搜尋」側欄群組（設定＋說明），插在「有聲書」群組之前；
	 * 無有聲書時 append。priority 20 確保有聲書（預設 10）已注入。
	 *
	 * @param array<string,mixed> $groups
	 * @return array<string,mixed>
	 */
	public static function add_nav_group( array $groups ): array {
		$our = [
			'label' => __( '進階搜尋', 'ys-cart-smart-search' ),
			'icon'  => 'dashicons-search',
			'slugs' => [ self::SLUG_SETTINGS, self::SLUG_HELP ],
		];

		if ( isset( $groups['audiobooks'] ) ) {
			$rebuilt = [];
			foreach ( $groups as $key => $group ) {
				if ( 'audiobooks' === $key && ! isset( $rebuilt['advanced_search'] ) ) {
					$rebuilt['advanced_search'] = $our;
				}
				$rebuilt[ $key ] = $group;
			}
			return $rebuilt;
		}

		$groups['advanced_search'] = $our;
		return $groups;
	}

	/**
	 * @param array<int,string> $parents
	 * @return array<int,string>
	 */
	public static function add_nav_parent_slugs( array $parents ): array {
		$parents[] = self::SLUG_SETTINGS;
		return array_values( array_unique( array_map( 'sanitize_key', $parents ) ) );
	}

	/**
	 * @param array<int,string> $pages
	 * @return array<int,string>
	 */
	public static function external_admin_pages( array $pages ): array {
		$pages[] = self::SLUG_SETTINGS;
		$pages[] = self::SLUG_HELP;
		return array_values( array_unique( array_map( 'sanitize_key', $pages ) ) );
	}

	/**
	 * @param array<string,string> $labels
	 * @return array<string,string>
	 */
	public static function external_admin_page_labels( array $labels ): array {
		$labels[ self::SLUG_SETTINGS ] = __( '進階搜尋', 'ys-cart-smart-search' );
		$labels[ self::SLUG_HELP ]     = __( '進階搜尋說明', 'ys-cart-smart-search' );
		return $labels;
	}

	/**
	 * 在核心「報表分析」加「搜尋分析」分頁（核心 2.52.44+）。
	 *
	 * @param array<string,mixed> $tabs
	 * @return array<string,mixed>
	 */
	public static function add_report_tab( $tabs ): array {
		if ( ! is_array( $tabs ) ) {
			$tabs = [];
		}
		$tabs['search'] = [
			'label' => __( '搜尋分析', 'ys-cart-smart-search' ),
			'icon'  => 'search',
		];
		return $tabs;
	}
}

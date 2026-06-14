<?php
/**
 * 後台選單整合（5 原則①：不自建主選單）。
 *
 *   - 子選單掛核心「電商系統」（parent `ys-cart`）；slug 用 `ys-cart-` 前綴
 *     → 核心 YSAdminAssets 對該前綴自動載入 takeover 資產。
 *   - takeover 側欄：自成「進階搜尋」群組（設定＋說明），穩定插在核心「商店設定」
 *     (settings) 群組之後（自然落在「有聲書」群組之前，且不依賴其存在）。
 *   - 搜尋分析整合進核心「報表分析」的「搜尋分析」tab（核心 2.52.44+）。
 *     **舊核心（< 2.52.44 無擴充點）時 fail-soft 保留獨立「搜尋分析」子選單作為入口**，
 *     避免分析無 UI 入口。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Admin;

defined( 'ABSPATH' ) || exit;

final class YSSsMenuBootstrap {

	public const SLUG_SETTINGS  = 'ys-cart-smart-search';
	public const SLUG_ANALYTICS = 'ys-cart-smart-search-analytics';
	public const SLUG_HELP      = 'ys-cart-smart-search-help';

	/**
	 * 核心是否支援報表分頁擴充點（2.52.44+ 的 ys_ec_report_tabs）。
	 * 否則 fail-soft 保留獨立分析頁入口。
	 */
	public static function core_has_report_tabs(): bool {
		return defined( 'YS_ECOMMERCE_VERSION' )
			&& version_compare( YS_ECOMMERCE_VERSION, '2.52.44', '>=' );
	}

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'register_submenus' ], 58 );
		// 穩定群組位置需在核心 settings 群組存在後插入 → priority 20。
		add_filter( 'ys_ec_admin_nav_groups', [ self::class, 'add_nav_group' ], 20 );
		add_filter( 'ys_ec_admin_nav_parent_slugs', [ self::class, 'add_nav_parent_slugs' ] );
		add_filter( 'ys_ec_external_admin_pages', [ self::class, 'external_admin_pages' ] );
		add_filter( 'ys_ec_external_admin_page_labels', [ self::class, 'external_admin_page_labels' ] );

		// 搜尋分析 → 核心「報表分析」tab（核心 2.52.44+ 才有此擴充點；舊核心自動無效，
		// 改由 register_submenus 的 fallback 獨立頁承接）。
		add_filter( 'ys_ec_report_tabs', [ self::class, 'add_report_tab' ] );
		add_action( 'ys_ec_report_render_tab_search', [ YSSsAnalyticsAdmin::class, 'render_body' ], 10, 2 );
	}

	public static function register_submenus(): void {
		$cap = current_user_can( 'manage_ys_ecommerce' ) ? 'manage_ys_ecommerce' : 'manage_options';

		add_submenu_page(
			'ys-cart',
			__( '進階搜尋設定', 'ys-cart-smart-search' ),
			__( '進階搜尋設定', 'ys-cart-smart-search' ),
			$cap,
			self::SLUG_SETTINGS,
			[ YSSsSettingsAdmin::class, 'render' ]
		);

		// 舊核心 fallback：保留獨立「搜尋分析」入口（新核心改走報表分頁、不註冊此頁）。
		if ( ! self::core_has_report_tabs() ) {
			add_submenu_page(
				'ys-cart',
				__( '搜尋分析', 'ys-cart-smart-search' ),
				__( '搜尋分析', 'ys-cart-smart-search' ),
				$cap,
				self::SLUG_ANALYTICS,
				[ YSSsAnalyticsAdmin::class, 'render' ]
			);
		}

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
	 * 自成「進階搜尋」側欄群組，穩定插在核心「商店設定」(settings) 群組**之後**
	 *（自然落在「有聲書」之前，且不依賴另一個 add-on 是否存在）。無 settings 群組時 append。
	 *
	 * @param array<string,mixed> $groups
	 * @return array<string,mixed>
	 */
	public static function add_nav_group( array $groups ): array {
		$slugs = [ self::SLUG_SETTINGS, self::SLUG_HELP ];
		// 舊核心：在群組內補回獨立分析入口（設定、分析、說明）。
		if ( ! self::core_has_report_tabs() ) {
			array_splice( $slugs, 1, 0, [ self::SLUG_ANALYTICS ] );
		}

		$our = [
			'label' => __( '進階搜尋', 'ys-cart-smart-search' ),
			'icon'  => 'dashicons-search',
			'slugs' => $slugs,
		];

		if ( isset( $groups['settings'] ) ) {
			$rebuilt = [];
			foreach ( $groups as $key => $group ) {
				$rebuilt[ $key ] = $group;
				if ( 'settings' === $key && ! isset( $rebuilt['advanced_search'] ) ) {
					$rebuilt['advanced_search'] = $our;
				}
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
		if ( ! self::core_has_report_tabs() ) {
			$parents[] = self::SLUG_ANALYTICS;
		}
		return array_values( array_unique( array_map( 'sanitize_key', $parents ) ) );
	}

	/**
	 * @param array<int,string> $pages
	 * @return array<int,string>
	 */
	public static function external_admin_pages( array $pages ): array {
		$pages[] = self::SLUG_SETTINGS;
		$pages[] = self::SLUG_HELP;
		if ( ! self::core_has_report_tabs() ) {
			$pages[] = self::SLUG_ANALYTICS;
		}
		return array_values( array_unique( array_map( 'sanitize_key', $pages ) ) );
	}

	/**
	 * @param array<string,string> $labels
	 * @return array<string,string>
	 */
	public static function external_admin_page_labels( array $labels ): array {
		$labels[ self::SLUG_SETTINGS ] = __( '進階搜尋設定', 'ys-cart-smart-search' );
		$labels[ self::SLUG_HELP ]     = __( '進階搜尋說明', 'ys-cart-smart-search' );
		if ( ! self::core_has_report_tabs() ) {
			$labels[ self::SLUG_ANALYTICS ] = __( '搜尋分析', 'ys-cart-smart-search' );
		}
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

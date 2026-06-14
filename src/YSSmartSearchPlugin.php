<?php
/**
 * 外掛 bootstrap（ADR-058）。
 *
 * 依附 YS CART：缺核心時全休眠（僅外掛頁提示、自有資料表保留）。
 * 5 原則：無自建主選單（掛核心電商選單）、YSAdminApp chrome、REST 全在核心
 * namespace、零 admin-ajax、後台全 ysca primitives。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch;

defined( 'ABSPATH' ) || exit;

final class YSSmartSearchPlugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', [ self::class, 'load_textdomain' ] );
		add_action( 'admin_notices', [ self::class, 'maybe_render_dependency_notice' ] );

		if ( ! YSSmartSearchDetector::has_ys_cart() ) {
			return; // 休眠：不註冊任何前後台行為。
		}

		Database\YSSsSchema::maybe_upgrade();

		// 後台
		Admin\YSSsMenuBootstrap::register();
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_admin_assets' ] );

		// REST（核心 namespace）
		add_action( 'rest_api_init', function (): void {
			( new Api\YSSsPublicController() )->register_routes();
			( new Api\YSSsAdminController() )->register_routes();
		} );

		// 前台
		Frontend\YSSsShortcodes::register();
		Frontend\YSSsResultsPage::register();

		// Cron（核心每日 hook）
		Cron\YSSsCronBridge::register();
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'ys-cart-smart-search',
			false,
			dirname( plugin_basename( YS_SMART_SEARCH_FILE ) ) . '/languages'
		);
	}

	public static function maybe_render_dependency_notice(): void {
		if ( YSSmartSearchDetector::has_ys_cart() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'YS CART 智慧搜尋：尚未偵測到 YS CART 外掛，功能暫停（資料保留）。', 'ys-cart-smart-search' );
		echo '</p></div>';
	}

	public static function enqueue_admin_assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, [ Admin\YSSsMenuBootstrap::SLUG_SETTINGS, Admin\YSSsMenuBootstrap::SLUG_ANALYTICS, Admin\YSSsMenuBootstrap::SLUG_HELP ], true ) ) {
			return;
		}

		wp_enqueue_style(
			'ys-ss-admin',
			YS_SMART_SEARCH_URL . 'assets/css/ys-ss-admin.css',
			[],
			YS_SMART_SEARCH_VERSION
		);
		wp_enqueue_script(
			'ys-ss-admin',
			YS_SMART_SEARCH_URL . 'assets/js/ys-ss-admin.js',
			[],
			YS_SMART_SEARCH_VERSION,
			true
		);
		wp_localize_script( 'ys-ss-admin', 'ysSsAdmin', [
			'restUrl' => esc_url_raw( rest_url( 'ys-ecommerce-headless/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'page'    => $page,
		] );
	}
}

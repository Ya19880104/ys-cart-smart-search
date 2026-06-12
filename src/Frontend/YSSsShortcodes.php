<?php
/**
 * 前台元件（需求②）：[ys_ss_search] 搜尋框、[ys_ss_search_icon] icon→彈窗。
 * 接管模式（設定開啟時）：同名後註冊覆蓋核心 [ys_ec_search] / [ys_ec_search_icon]。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Frontend;

use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\YSSmartSearchDetector;

defined( 'ABSPATH' ) || exit;

final class YSSsShortcodes {

	private static bool $assets_needed = false;
	private static bool $popup_printed = false;

	public static function register(): void {
		add_shortcode( 'ys_ss_search', [ self::class, 'render_bar' ] );
		add_shortcode( 'ys_ss_search_icon', [ self::class, 'render_icon' ] );

		// 接管：init 晚於核心 shortcode 註冊（核心於 plugins_loaded 建構時註冊），
		// WP 同名後註冊者勝；關閉設定即自動還原核心版本。
		add_action( 'init', [ self::class, 'maybe_takeover' ], 20 );

		add_action( 'wp_enqueue_scripts', [ self::class, 'register_assets' ] );
		add_action( 'wp_footer', [ self::class, 'print_popup' ], 5 );
	}

	public static function maybe_takeover(): void {
		$settings = YSSsSettings::all();
		if ( empty( $settings['takeover'] ) ) {
			return;
		}
		add_shortcode( 'ys_ec_search', [ self::class, 'render_bar' ] );
		add_shortcode( 'ys_ec_search_icon', [ self::class, 'render_icon' ] );
	}

	public static function register_assets(): void {
		wp_register_style(
			'ys-ss-front',
			YS_SMART_SEARCH_URL . 'assets/css/ys-ss-front.css',
			[],
			YS_SMART_SEARCH_VERSION
		);
		wp_register_script(
			'ys-ss-front',
			YS_SMART_SEARCH_URL . 'assets/js/ys-ss-front.js',
			[],
			YS_SMART_SEARCH_VERSION,
			true
		);
	}

	private static function enqueue_assets(): void {
		if ( self::$assets_needed ) {
			return;
		}
		self::$assets_needed = true;

		wp_enqueue_style( 'ys-ss-front' );
		wp_enqueue_script( 'ys-ss-front' );

		$settings = YSSsSettings::all();
		wp_localize_script( 'ys-ss-front', 'ysSsFront', [
			'restUrl'       => esc_url_raw( rest_url( 'ys-ecommerce-headless/v1/smart-search' ) ),
			'shopUrl'       => YSSmartSearchDetector::shop_url(),
			'recentEnabled' => ! empty( $settings['recent_enabled'] ),
			'i18n'          => [
				'popular'   => __( '熱門搜尋', 'ys-cart-smart-search' ),
				'recent'    => __( '最近搜尋', 'ys-cart-smart-search' ),
				'viewAll'   => __( '查看全部商品結果 →', 'ys-cart-smart-search' ),
				'noResults' => __( '找不到符合的結果，試試其他關鍵字：', 'ys-cart-smart-search' ),
				'searching' => __( '搜尋中…', 'ys-cart-smart-search' ),
			],
		] );
	}

	/**
	 * [ys_ss_search] 行內搜尋框。
	 *
	 * @param array<string,mixed>|string $atts
	 */
	public static function render_bar( $atts = [] ): string {
		if ( ! YSSmartSearchDetector::has_ys_cart() ) {
			return '';
		}
		self::enqueue_assets();

		$atts = shortcode_atts( [ 'placeholder' => __( '搜尋商品…', 'ys-cart-smart-search' ) ], (array) $atts, 'ys_ss_search' );

		ob_start();
		?>
		<form class="ys-ss-form" role="search" method="get"
			action="<?php echo esc_url( YSSmartSearchDetector::shop_url() ); ?>" data-ys-ss data-ys-ss-source="bar">
			<div class="ys-ss-inputwrap">
				<input type="search" name="ys_ec_search" class="ys-ss-input"
					placeholder="<?php echo esc_attr( (string) $atts['placeholder'] ); ?>"
					autocomplete="off" aria-label="<?php esc_attr_e( '搜尋', 'ys-cart-smart-search' ); ?>">
				<button type="submit" class="ys-ss-submit" aria-label="<?php esc_attr_e( '搜尋', 'ys-cart-smart-search' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
				</button>
				<div class="ys-ss-panel" hidden></div>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * [ys_ss_search_icon] icon → 彈窗。
	 *
	 * @param array<string,mixed>|string $atts
	 */
	public static function render_icon( $atts = [] ): string {
		if ( ! YSSmartSearchDetector::has_ys_cart() ) {
			return '';
		}
		self::enqueue_assets();

		return '<button type="button" class="ys-ss-icon-trigger" data-ys-ss-open aria-label="' . esc_attr__( '開啟搜尋', 'ys-cart-smart-search' ) . '">'
			. '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>'
			. '</button>';
	}

	/**
	 * 彈窗容器（wp_footer 一次；資產有被需要才印）。
	 */
	public static function print_popup(): void {
		if ( ! self::$assets_needed || self::$popup_printed ) {
			return;
		}
		self::$popup_printed = true;
		?>
		<div class="ys-ss-popup" id="ys-ss-popup" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( '商品搜尋', 'ys-cart-smart-search' ); ?>">
			<div class="ys-ss-popup__backdrop" data-ys-ss-close></div>
			<div class="ys-ss-popup__content">
				<button type="button" class="ys-ss-popup__close" data-ys-ss-close aria-label="<?php esc_attr_e( '關閉', 'ys-cart-smart-search' ); ?>">&times;</button>
				<form class="ys-ss-form" role="search" method="get"
					action="<?php echo esc_url( YSSmartSearchDetector::shop_url() ); ?>" data-ys-ss data-ys-ss-source="popup">
					<div class="ys-ss-inputwrap">
						<input type="search" name="ys_ec_search" class="ys-ss-input"
							placeholder="<?php esc_attr_e( '搜尋商品…', 'ys-cart-smart-search' ); ?>"
							autocomplete="off" aria-label="<?php esc_attr_e( '搜尋', 'ys-cart-smart-search' ); ?>">
						<button type="submit" class="ys-ss-submit" aria-label="<?php esc_attr_e( '搜尋', 'ys-cart-smart-search' ); ?>">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
						</button>
						<div class="ys-ss-panel" hidden></div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}

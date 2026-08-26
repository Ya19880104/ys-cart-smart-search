<?php
/**
 * 前台元件（需求②）：[ys_ss_search] 搜尋框、[ys_ss_search_icon] icon→彈窗。
 * 接管模式（設定開啟時）：同名後註冊覆蓋核心 [ys_ec_search] / [ys_ec_search_icon]。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Frontend;

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Security\YSSsRateLimiter;
use YangSheep\SmartSearch\Security\YSSsSearchInput;
use YangSheep\SmartSearch\Services\YSSsSearchService;
use YangSheep\SmartSearch\YSSmartSearchDetector;

defined( 'ABSPATH' ) || exit;

final class YSSsShortcodes {

	private static bool $assets_needed = false;
	private static bool $popup_printed = false;
	private static int $panel_sequence = 0;

	public static function register(): void {
		add_shortcode( 'ys_ss_search', [ self::class, 'render_bar' ] );
		add_shortcode( 'ys_ss_search_icon', [ self::class, 'render_icon' ] );

		// 接管：init 晚於核心 shortcode 註冊（核心於 plugins_loaded 建構時註冊），
		// WP 同名後註冊者勝；關閉設定即自動還原核心版本。
		add_action( 'init', [ self::class, 'maybe_takeover' ], 20 );

		add_action( 'wp_enqueue_scripts', [ self::class, 'register_assets' ] );
		add_action( 'wp_footer', [ self::class, 'print_popup' ], 5 );
		add_action( 'template_redirect', [ self::class, 'maybe_log_list_search' ], 20 );
	}

	/**
	 * Record a list-mode submit that navigated before the live query produced a signed receipt.
	 * Proved client-owned submits carry a harmless marker and are not counted twice here.
	 */
	public static function maybe_log_list_search(): void {
		$settings = YSSsSettings::all();
		if ( 'list' !== ( $settings['results_mode'] ?? 'list' )
			|| ! array_key_exists( 'ys_ec_search', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$client_logged = $_GET['ys_ss_client_logged'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_scalar( $client_logged ) && '1' === (string) wp_unslash( $client_logged ) ) {
			return;
		}

		$raw_param = $_GET['ys_ec_search']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_string( $raw_param ) ) {
			return;
		}
		$raw   = wp_unslash( $raw_param );
		$input = YSSsSearchInput::inspect( $raw );
		if ( $input['blocked'] || '' === trim( $input['query'] ) ) {
			return;
		}

		try {
			if ( ! YSSsRateLimiter::allow( 'log', 30 ) ) {
				return;
			}
			$result = YSSsSearchService::search( $input['query'] );
			YSSsQueryRepository::log_page(
				$input['query'],
				$input['raw'],
				(int) ( $result['products_total'] ?? 0 ),
				implode( ',', (array) ( $result['content_types'] ?? [] ) ),
				YSSsRateLimiter::visitor_hash()
			);
		} catch ( \Throwable $error ) {
			// Analytics remains a side-channel and never blocks the destination page.
		}
	}

	public static function maybe_takeover(): void {
		$settings = YSSsSettings::all();
		if ( empty( $settings['takeover'] ) ) {
			return;
		}
		add_shortcode( 'ys_ec_search', [ self::class, 'render_bar' ] );
		add_shortcode( 'ys_ec_search_icon', [ self::class, 'render_icon' ] );

		// 接管模式同步接管核心「篩選側邊欄」的搜尋表單（核心 2.52.39+ 提供 filter），
		// 讓側邊欄與頁首／短代碼一致改用進階搜尋。核心較舊無此 filter 時自動無效（不影響）。
		add_filter( 'ys_ec_sidebar_search_form', [ self::class, 'sidebar_search_form' ], 10, 2 );
	}

	/**
	 * 側邊欄搜尋表單接管（接管模式開啟時掛上）。
	 *
	 * @param string $default        核心預設表單 HTML。
	 * @param string $current_search 當前搜尋字串（由原生 GET 帶入，智慧框沿用 name=ys_ec_search）。
	 */
	public static function sidebar_search_form( string $default, string $current_search ): string {
		if ( ! YSSmartSearchDetector::has_ys_cart() ) {
			return $default;
		}
		$html = self::render_bar();
		return '' !== $html ? $html : $default;
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
			'resultsMode'   => (string) ( $settings['results_mode'] ?? 'list' ),
			'i18n'          => [
				'popular'   => __( '熱門搜尋', 'ys-cart-smart-search' ),
				'recent'    => __( '最近搜尋', 'ys-cart-smart-search' ),
				'viewAll'   => __( '查看全部商品結果 →', 'ys-cart-smart-search' ),
				'noResults' => __( '找不到符合的結果，試試其他關鍵字：', 'ys-cart-smart-search' ),
				'searching' => __( '搜尋中…', 'ys-cart-smart-search' ),
				'error'     => __( '搜尋暫時無法使用，請稍後再試。', 'ys-cart-smart-search' ),
			],
		] );
	}

	/**
	 * 公開的資產載入入口（結果頁短代碼 [ys_ss_search_results] 也需要前台 CSS/JS）。
	 */
	public static function ensure_assets(): void {
		self::enqueue_assets();
	}

	/**
	 * 搜尋表單 action：list=商店列表頁(A)；page=獨立混合結果頁(B，無有效頁面時退回商店頁)。
	 */
	public static function form_action(): string {
		$settings = YSSsSettings::all();
		if ( 'page' === ( $settings['results_mode'] ?? 'list' ) ) {
			$url = YSSsResultsPage::page_url();
			if ( '' !== $url ) {
				return $url;
			}
		}
		return YSSmartSearchDetector::shop_url();
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
		$panel_id = 'ys-ss-panel-' . ++self::$panel_sequence;

		ob_start();
		?>
		<form class="ys-ss-form" role="search" method="get"
			action="<?php echo esc_url( self::form_action() ); ?>" data-ys-ss data-ys-ss-source="bar">
			<div class="ys-ss-inputwrap">
				<input type="search" name="ys_ec_search" class="ys-ss-input"
					placeholder="<?php echo esc_attr( (string) $atts['placeholder'] ); ?>"
					autocomplete="off" aria-label="<?php esc_attr_e( '搜尋', 'ys-cart-smart-search' ); ?>"
					role="combobox" aria-autocomplete="list" aria-expanded="false"
					aria-busy="false"
					aria-controls="<?php echo esc_attr( $panel_id ); ?>">
				<button type="submit" class="ys-ss-submit" aria-label="<?php esc_attr_e( '搜尋', 'ys-cart-smart-search' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
				</button>
				<div class="ys-ss-panel" id="<?php echo esc_attr( $panel_id ); ?>" role="listbox" aria-live="polite" aria-busy="false" hidden></div>
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

		return '<button type="button" class="ys-ss-icon-trigger" data-ys-ss-open aria-haspopup="dialog" aria-label="' . esc_attr__( '開啟搜尋', 'ys-cart-smart-search' ) . '">'
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
		$panel_id = 'ys-ss-panel-' . ++self::$panel_sequence;
		?>
		<div class="ys-ss-popup" id="ys-ss-popup" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( '商品搜尋', 'ys-cart-smart-search' ); ?>">
			<div class="ys-ss-popup__backdrop" data-ys-ss-close></div>
			<div class="ys-ss-popup__content">
				<button type="button" class="ys-ss-popup__close" data-ys-ss-close aria-label="<?php esc_attr_e( '關閉', 'ys-cart-smart-search' ); ?>">&times;</button>
				<form class="ys-ss-form" role="search" method="get"
					action="<?php echo esc_url( self::form_action() ); ?>" data-ys-ss data-ys-ss-source="popup">
					<div class="ys-ss-inputwrap">
						<input type="search" name="ys_ec_search" class="ys-ss-input"
							placeholder="<?php esc_attr_e( '搜尋商品…', 'ys-cart-smart-search' ); ?>"
							autocomplete="off" aria-label="<?php esc_attr_e( '搜尋', 'ys-cart-smart-search' ); ?>"
							role="combobox" aria-autocomplete="list" aria-expanded="false"
							aria-busy="false"
							aria-controls="<?php echo esc_attr( $panel_id ); ?>">
						<button type="submit" class="ys-ss-submit" aria-label="<?php esc_attr_e( '搜尋', 'ys-cart-smart-search' ); ?>">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
						</button>
						<div class="ys-ss-panel" id="<?php echo esc_attr( $panel_id ); ?>" role="listbox" aria-live="polite" aria-busy="false" hidden></div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}

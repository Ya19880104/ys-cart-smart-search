<?php
/**
 * 獨立混合搜尋結果頁（B 模式）：短代碼 [ys_ss_search_results]。
 *
 * 商品 grid（分頁）＋分類＋文章/頁面分區，重用 YSSsSearchService::search_page()。
 * 落地此頁的搜尋於 server 端記錄（source='page'，600 秒去重）→ 與下拉一致計入分析報表。
 * 切到 page 模式時自動供裝一個含本短代碼的頁面。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Frontend;

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Security\YSSsInjectionGuard;
use YangSheep\SmartSearch\Security\YSSsRateLimiter;
use YangSheep\SmartSearch\Services\YSSsSearchService;
use YangSheep\SmartSearch\YSSmartSearchDetector;

defined( 'ABSPATH' ) || exit;

final class YSSsResultsPage {

	public const SHORTCODE = 'ys_ss_search_results';

	public static function register(): void {
		add_shortcode( self::SHORTCODE, [ self::class, 'render' ] );
		// 設定切到 page 模式且尚無有效頁面時，自動供裝。
		add_action( 'update_option_' . YSSsSettings::OPTION, [ self::class, 'on_settings_saved' ], 10, 0 );
	}

	public static function on_settings_saved(): void {
		static $busy = false;
		if ( $busy ) {
			return;
		}
		$settings = YSSsSettings::all();
		if ( 'page' !== ( $settings['results_mode'] ?? 'list' ) ) {
			return;
		}
		if ( self::valid_page_id( (int) ( $settings['results_page_id'] ?? 0 ) ) ) {
			return;
		}
		$busy = true;
		self::ensure_page();
		$busy = false;
	}

	public static function valid_page_id( int $pid ): bool {
		if ( $pid <= 0 ) {
			return false;
		}
		$post = get_post( $pid );
		return $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& 'trash' !== $post->post_status
			&& has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * 確保結果頁存在，回傳頁面 ID（0 = 建立失敗）。
	 */
	public static function ensure_page(): int {
		// 縱深防禦：自動建立 publish 頁面僅限有管理權限的情境（正常經設定儲存觸發）。
		// 防止 CLI/import/他 addon 寫此 option 時，在非預期身分下自動建頁。
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_ys_ecommerce' ) ) {
			return 0;
		}

		$settings = YSSsSettings::all();
		$pid      = (int) ( $settings['results_page_id'] ?? 0 );
		if ( self::valid_page_id( $pid ) ) {
			return $pid;
		}

		$new = wp_insert_post( [
			'post_title'     => __( '搜尋結果', 'ys-cart-smart-search' ),
			'post_name'      => 'ys-search',
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_content'   => '[' . self::SHORTCODE . ']',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		], true );

		if ( ! $new || is_wp_error( $new ) ) {
			return 0;
		}

		YSSsSettings::update( [ 'results_page_id' => (int) $new ] );
		return (int) $new;
	}

	/**
	 * 結果頁 URL（表單 action / 搜尋落點）。無有效頁面時退回商店頁。
	 */
	public static function page_url(): string {
		$settings = YSSsSettings::all();
		$pid      = (int) ( $settings['results_page_id'] ?? 0 );
		if ( self::valid_page_id( $pid ) ) {
			$url = get_permalink( $pid );
			if ( $url ) {
				return (string) $url;
			}
		}
		return YSSmartSearchDetector::shop_url();
	}

	/**
	 * 「查看全部」/ 即時面板落點 URL（mode-aware；集中落點，避免下拉與表單 action 漂移）。
	 * B 模式且有有效結果頁 → 結果頁 permalink + ys_ec_search；
	 * 否則（A 模式或無有效結果頁）→ 商店頁 + ys_ec_search（與既有 A 行為一致）。
	 * 與 form_action()／核心搜尋同一參數 ys_ec_search。
	 */
	public static function search_url( string $query ): string {
		$settings = YSSsSettings::all();
		$is_page  = 'page' === ( $settings['results_mode'] ?? 'list' )
			&& self::valid_page_id( (int) ( $settings['results_page_id'] ?? 0 ) );
		if ( $is_page ) {
			return add_query_arg( 'ys_ec_search', $query, self::page_url() );
		}
		return YSSmartSearchDetector::shop_url( [ 'ys_ec_search' => $query ] );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 */
	public static function render( $atts = [] ): string {
		if ( ! YSSmartSearchDetector::has_ys_cart() ) {
			return '';
		}
		YSSsShortcodes::ensure_assets();

		$raw = isset( $_GET['ys_ec_search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ys_ec_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 100, 'UTF-8' ) : substr( $raw, 0, 100 );
		$page = isset( $_GET['ys_ss_page'] ) ? max( 1, (int) $_GET['ys_ss_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		echo '<div class="ys-ss-results">';
		echo '<div class="ys-ss-results__searchbar">' . YSSsShortcodes::render_bar( [ 'placeholder' => __( '搜尋商品、分類、文章…', 'ys-cart-smart-search' ) ] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已自含跳脫。

		if ( '' === trim( $raw ) ) {
			echo '<p class="ys-ss-results__hint">' . esc_html__( '請輸入搜尋關鍵字。', 'ys-cart-smart-search' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		// 防注入：辨識為攻擊探測即拒絕執行（不查 DB、不記錄、不回顯原字串），
		// 與 query 端點一致；顯示與零結果相同的中性提示，不給攻擊者 oracle。
		if ( YSSsInjectionGuard::is_attack( $raw ) ) {
			echo '<p class="ys-ss-results__hint">' . esc_html__( '沒有符合的結果。', 'ys-cart-smart-search' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		$res = YSSsSearchService::search_page( $raw, $page );

		// 分析記錄（去重）：僅第一頁；有/無結果都記（零結果＝商機）。
		if ( 1 === $page ) {
			$total_for_log = (int) $res['products_total'];
			foreach ( $res['groups'] as $g ) {
				if ( 'products' !== $g['type'] ) {
					$total_for_log += count( $g['items'] );
				}
			}
			try {
				YSSsQueryRepository::log_page( $raw, $total_for_log, implode( ',', $res['content_types'] ), YSSsRateLimiter::visitor_hash() );
			} catch ( \Throwable $e ) {
				// 分析旁路：寫入失敗不影響頁面。
			}
		}

		echo '<h1 class="ys-ss-results__title">'
			. esc_html( sprintf(
				/* translators: 1: 關鍵字, 2: 商品筆數 */
				__( '「%1$s」的搜尋結果', 'ys-cart-smart-search' ),
				$raw
			) )
			. ' <span class="ys-ss-results__count">'
			. esc_html( sprintf(
				/* translators: %s: 商品筆數 */
				__( '商品 %s 筆', 'ys-cart-smart-search' ),
				number_format( (int) $res['products_total'] )
			) )
			. '</span></h1>';

		if ( empty( $res['groups'] ) ) {
			echo '<p class="ys-ss-results__hint">' . esc_html__( '找不到符合的結果，試試其他關鍵字。', 'ys-cart-smart-search' ) . '</p>';
		} else {
			foreach ( $res['groups'] as $g ) {
				self::render_group( $g );
			}
			self::render_pagination( $raw, (int) $res['page'], (int) $res['total_pages'] );
		}

		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $g
	 */
	private static function render_group( array $g ): void {
		$type  = (string) ( $g['type'] ?? '' );
		$items = (array) ( $g['items'] ?? [] );
		if ( ! $items ) {
			return;
		}

		echo '<section class="ys-ss-results__group ys-ss-results__group--' . esc_attr( $type ) . '">';
		echo '<h2 class="ys-ss-results__group-title">' . esc_html( (string) ( $g['label'] ?? '' ) ) . '</h2>';

		if ( 'products' === $type ) {
			echo '<div class="ys-ss-results__products">';
			foreach ( $items as $it ) {
				echo '<a class="ys-ss-pcard" href="' . esc_url( (string) $it['url'] ) . '">';
				if ( ! empty( $it['image'] ) ) {
					echo '<span class="ys-ss-pcard__media"><img src="' . esc_url( (string) $it['image'] ) . '" alt="" loading="lazy"></span>';
				} else {
					echo '<span class="ys-ss-pcard__media ys-ss-pcard__media--empty"></span>';
				}
				echo '<span class="ys-ss-pcard__title">' . esc_html( (string) $it['title'] ) . '</span>';
				if ( ! empty( $it['price'] ) ) {
					echo '<span class="ys-ss-pcard__price">' . esc_html( (string) $it['price'] );
					if ( ! empty( $it['price_original'] ) ) {
						echo ' <del>' . esc_html( (string) $it['price_original'] ) . '</del>';
					}
					echo '</span>';
				}
				if ( ! empty( $it['sku'] ) ) {
					echo '<span class="ys-ss-pcard__sku">' . esc_html( (string) $it['sku'] ) . '</span>';
				}
				echo '</a>';
			}
			echo '</div>';
		} elseif ( 'categories' === $type ) {
			echo '<div class="ys-ss-results__cats">';
			foreach ( $items as $it ) {
				echo '<a class="ys-ss-catchip" href="' . esc_url( (string) $it['url'] ) . '">'
					. esc_html( (string) $it['title'] );
				if ( ! empty( $it['count'] ) ) {
					echo ' <span class="ys-ss-catchip__count">(' . esc_html( (string) (int) $it['count'] ) . ')</span>';
				}
				echo '</a>';
			}
			echo '</div>';
		} else { // posts / pages
			echo '<div class="ys-ss-results__posts">';
			foreach ( $items as $it ) {
				echo '<a class="ys-ss-presult" href="' . esc_url( (string) $it['url'] ) . '">';
				if ( ! empty( $it['image'] ) ) {
					echo '<span class="ys-ss-presult__thumb"><img src="' . esc_url( (string) $it['image'] ) . '" alt="" loading="lazy"></span>';
				}
				echo '<span class="ys-ss-presult__body">';
				echo '<span class="ys-ss-presult__title">' . esc_html( (string) $it['title'] ) . '</span>';
				if ( ! empty( $it['excerpt'] ) ) {
					echo '<span class="ys-ss-presult__excerpt">' . esc_html( (string) $it['excerpt'] ) . '</span>';
				}
				echo '</span></a>';
			}
			echo '</div>';
		}

		echo '</section>';
	}

	private static function render_pagination( string $q, int $page, int $total_pages ): void {
		if ( $total_pages <= 1 ) {
			return;
		}
		$base = self::page_url();
		$mk   = static function ( int $p ) use ( $base, $q ): string {
			return esc_url( add_query_arg( [ 'ys_ec_search' => $q, 'ys_ss_page' => $p ], $base ) );
		};

		echo '<nav class="ys-ss-results__pager" aria-label="' . esc_attr__( '搜尋結果分頁', 'ys-cart-smart-search' ) . '">';
		if ( $page > 1 ) {
			echo '<a class="ys-ss-pager__link" href="' . $mk( $page - 1 ) . '">' . esc_html__( '上一頁', 'ys-cart-smart-search' ) . '</a>';
		}

		$start = max( 1, $page - 2 );
		$end   = min( $total_pages, $page + 2 );
		for ( $p = $start; $p <= $end; $p++ ) {
			if ( $p === $page ) {
				echo '<span class="ys-ss-pager__link is-current">' . esc_html( (string) $p ) . '</span>';
			} else {
				echo '<a class="ys-ss-pager__link" href="' . $mk( $p ) . '">' . esc_html( (string) $p ) . '</a>';
			}
		}

		if ( $page < $total_pages ) {
			echo '<a class="ys-ss-pager__link" href="' . $mk( $page + 1 ) . '">' . esc_html__( '下一頁', 'ys-cart-smart-search' ) . '</a>';
		}
		echo '</nav>';
	}
}

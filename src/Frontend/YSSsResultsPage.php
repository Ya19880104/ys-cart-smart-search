<?php
/**
 * 獨立混合搜尋結果頁（B 模式）：短代碼 [ys_ss_search_results]。
 *
 * 商品 grid（分頁）＋分類＋文章/頁面分區，重用 YSSsSearchService::search_page()。
 * 落地此頁的搜尋於 server 端記錄（source='page'，同事件重入去重）→ 與下拉一致計入分析報表。
 * 切到 page 模式時自動供裝一個含本短代碼的頁面。
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
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& self::has_executable_shortcode( (string) $post->post_content );
	}

	/**
	 * 使用 quote-aware HTML context scanner 與 WordPress shortcode grammar，只接受正常 HTML
	 * flow 文字區實際會執行的 shortcode；attribute、comment、CDATA、raw/inert context 與
	 * [[escaped]] 形式不構成可用結果頁。這個 gate 不宣稱證明 CSS visibility 或任意管理員 JS。
	 */
	private static function has_executable_shortcode( string $content ): bool {
		if ( false === strpos( $content, '[' )
			|| ! function_exists( 'get_shortcode_regex' ) ) {
			return false;
		}

		$pattern = '~' . get_shortcode_regex( [ self::SHORTCODE ] ) . '~s';
		$blocked = array_fill_keys( [
			'script',
			'style',
			'textarea',
			'title',
			'template',
			'noscript',
			'iframe',
			'noembed',
			'noframes',
			'xmp',
			'plaintext',
			'select',
			'option',
			'optgroup',
			'datalist',
			'canvas',
			'audio',
			'video',
			'object',
			'svg',
			'math',
			'picture',
			'dialog',
			'head',
		], true );
		$raw = array_fill_keys( [
			'script', 'style', 'textarea', 'title', 'noscript', 'iframe',
			'noembed', 'noframes', 'xmp',
		], true );
		$stack  = [];
		$offset = 0;
		$length = strlen( $content );
		while ( $offset < $length ) {
			if ( [] !== $stack ) {
				$top = (string) end( $stack );
				if ( 'plaintext' === $top ) {
					return false; // HTML plaintext never returns to normal parsing mode.
				}
				if ( isset( $raw[ $top ] ) ) {
					$raw_end = self::raw_context_end( $content, $offset, $top );
					if ( null === $raw_end ) {
						return false;
					}
					array_pop( $stack );
					$offset = $raw_end;
					continue;
				}
			}

			$tag_start = strpos( $content, '<', $offset );
			$text_end  = false === $tag_start ? $length : $tag_start;
			if ( [] === $stack && $text_end > $offset
				&& self::text_has_shortcode( substr( $content, $offset, $text_end - $offset ), $pattern ) ) {
				return true;
			}
			if ( false === $tag_start ) {
				break;
			}

			$token = self::html_token_at( $content, $tag_start );
			if ( null === $token ) {
				return false; // An unterminated quote/tag cannot authorize later bytes.
			}
			$offset = $token['end'];
			$tag    = $token['tag'];
			if ( null === $tag || ! isset( $blocked[ $tag['name'] ] ) ) {
				continue;
			}

			if ( $tag['closing'] ) {
				if ( [] !== $stack && $tag['name'] === end( $stack ) ) {
					array_pop( $stack );
				}
				continue;
			}

			// Blocked elements are never treated as void: `<script/>` still opens raw context.
			$stack[] = $tag['name'];
		}
		return false;
	}

	private static function text_has_shortcode( string $text, string $pattern ): bool {
		$matches = [];
		$result  = preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER );
		if ( false === $result || 0 === $result ) {
			return false;
		}
		foreach ( $matches as $match ) {
			if ( self::SHORTCODE !== ( $match[2] ?? '' ) ) {
				continue;
			}
			if ( '[' === ( $match[1] ?? '' ) && ']' === ( $match[6] ?? '' ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Quote-aware token consumption. Comments and CDATA are returned without a tag.
	 *
	 * @return array{end:int,tag:array{name:string,closing:bool}|null}|null
	 */
	private static function html_token_at( string $html, int $start ): ?array {
		$length = strlen( $html );
		if ( $start < 0 || $start >= $length || '<' !== $html[ $start ] ) {
			return null;
		}
		if ( 0 === substr_compare( $html, '<!--', $start, 4 ) ) {
			$end = strpos( $html, '-->', $start + 4 );
			return [ 'end' => false === $end ? $length : $end + 3, 'tag' => null ];
		}
		if ( 0 === substr_compare( $html, '<![CDATA[', $start, 9 ) ) {
			$end = strpos( $html, ']]>', $start + 9 );
			return [ 'end' => false === $end ? $length : $end + 3, 'tag' => null ];
		}

		$next = $html[ $start + 1 ] ?? '';
		if ( '' === $next || 1 !== preg_match( '/[A-Za-z!\/?]/D', $next ) ) {
			return [ 'end' => $start + 1, 'tag' => null ]; // Literal less-than text.
		}

		$quote = '';
		for ( $i = $start + 1; $i < $length; ++$i ) {
			$byte = $html[ $i ];
			if ( '' !== $quote ) {
				if ( $byte === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $byte || "'" === $byte ) {
				$quote = $byte;
				continue;
			}
			if ( '>' === $byte ) {
				$token = substr( $html, $start, $i - $start + 1 );
				return [ 'end' => $i + 1, 'tag' => self::html_context_tag( $token ) ];
			}
		}
		return null;
	}

	private static function raw_context_end( string $html, int $offset, string $name ): ?int {
		$needle = '</' . $name;
		$search = $offset;
		while ( false !== ( $candidate = stripos( $html, $needle, $search ) ) ) {
			$token = self::html_token_at( $html, $candidate );
			if ( null === $token ) {
				return null;
			}
			$tag = $token['tag'];
			if ( null !== $tag && $tag['closing'] && $name === $tag['name'] ) {
				return $token['end'];
			}
			$search = $candidate + 2;
		}
		return null;
	}

	/**
	 * @return array{name:string,closing:bool}|null
	 */
	private static function html_context_tag( string $token ): ?array {
		$match = [];
		// HTML tag names end only before ASCII whitespace, `/` or `>`; `\b` would
		// incorrectly accept tokens such as `</script!>` and release a raw context.
		if ( 1 !== preg_match( '/\A<(\/?)([a-z][a-z0-9:-]*)(?=[\x09\x0A\x0C\x0D\x20\/>])/isD', $token, $match ) ) {
			return null;
		}
		return [
			'name'    => strtolower( $match[2] ),
			'closing' => '/' === $match[1],
		];
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

		$stored_ok = false;
		try {
			YSSsSettings::update( [ 'results_page_id' => (int) $new ] );
			$stored    = YSSsSettings::all();
			$stored_ok = (int) ( $stored['results_page_id'] ?? 0 ) === (int) $new
				&& self::valid_page_id( (int) $new );
		} catch ( \Throwable $error ) {
			$stored_ok = false;
		}
		if ( ! $stored_ok ) {
			try {
				wp_delete_post( (int) $new, true );
			} catch ( \Throwable $error ) {
				// Best-effort rollback: the settings contract still fails closed below.
			}
			return 0;
		}
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

		$has_query = array_key_exists( 'ys_ec_search', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw       = wp_unslash( $_GET['ys_ec_search'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query_allowed = ! $has_query || YSSsRateLimiter::allow_public_query();
		$input = $query_allowed
			? YSSsSearchInput::inspect( $raw )
			: [ 'blocked' => true, 'query' => '', 'raw' => '' ];
		$query = $input['query'];

		// 分頁呈現與分析授權刻意分離：service 可把任意整數解析到可見頁，但只有原始
		// canonical literal `1`（或參數缺席／非 scalar 的安全預設）能建立 page-1 event。
		$page               = 1;
		$analytics_page_one = true;
		if ( array_key_exists( 'ys_ss_page', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_page = $_GET['ys_ss_page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_scalar( $raw_page ) ) {
				$raw_page           = wp_unslash( $raw_page );
				$page               = (int) $raw_page;
				$analytics_page_one = ( is_string( $raw_page ) && '1' === $raw_page )
					|| ( is_int( $raw_page ) && 1 === $raw_page );
			}
		}

		ob_start();
		echo '<div class="ys-ss-results">';
		echo '<div class="ys-ss-results__searchbar">' . YSSsShortcodes::render_bar( [ 'placeholder' => __( '搜尋商品、分類、文章…', 'ys-cart-smart-search' ) ] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已自含跳脫。

		if ( ! $query_allowed ) {
			echo '<p class="ys-ss-results__hint">' . esc_html__( '請求過於頻繁，請稍後再試。', 'ys-cart-smart-search' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		if ( $input['blocked'] ) {
			echo '<p class="ys-ss-results__hint">' . esc_html__( '沒有符合的結果。', 'ys-cart-smart-search' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		if ( '' === trim( $query ) ) {
			echo '<p class="ys-ss-results__hint">' . esc_html__( '請輸入搜尋關鍵字。', 'ys-cart-smart-search' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		$res = YSSsSearchService::search_page( $query, $page );

		// 分析記錄（去重）：僅第一頁；有/無結果都記（零結果＝商機）。
		if ( $analytics_page_one ) {
			try {
				if ( YSSsRateLimiter::allow( 'log', 30 ) ) {
					$total_for_log = (int) $res['products_total'];
					YSSsQueryRepository::log_page( $query, $input['raw'], $total_for_log, implode( ',', $res['content_types'] ), YSSsRateLimiter::visitor_hash() );
				}
			} catch ( \Throwable $e ) {
				// 分析旁路：寫入失敗不影響頁面。
			}
		}

		echo '<h1 class="ys-ss-results__title">'
			. esc_html( sprintf(
				/* translators: 1: 關鍵字, 2: 商品筆數 */
				__( '「%1$s」的搜尋結果', 'ys-cart-smart-search' ),
				$query
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
			self::render_pagination( $query, (int) $res['page'], (int) $res['total_pages'] );
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

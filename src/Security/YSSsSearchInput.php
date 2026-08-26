<?php
/**
 * 所有公開搜尋入口共用的 raw-first 決策器。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

use YangSheep\SmartSearch\Support\YSSsText;

defined( 'ABSPATH' ) || exit;

final class YSSsSearchInput {

	private const MAX_RAW_BYTES   = 2048;
	private const MAX_QUERY_CHARS = 100;
	private const CORE_PRODUCT_LIST_CLASS = 'YangSheep\\Ecommerce\\Frontend\\YSProductListShortcode';

	public static function register(): void {
		add_filter( 'pre_do_shortcode_tag', [ self::class, 'pre_do_shortcode_tag' ], PHP_INT_MAX, 4 );
		add_filter( 'sanitize_text_field', [ self::class, 'preserve_core_search_query' ], 10, 2 );
	}

	/**
	 * @return array{blocked:bool,query:string}
	 */
	public static function inspect( mixed $value ): array {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return self::blocked();
		}

		$raw = (string) ( $value ?? '' );
		if ( strlen( $raw ) > self::MAX_RAW_BYTES || 1 !== preg_match( '//u', $raw ) ) {
			return self::blocked();
		}

		$closure = YSSsText::canonical_candidates( $raw );
		if ( ! $closure['complete'] ) {
			return self::blocked();
		}

		foreach ( $closure['candidates'] as $candidate ) {
			if ( YSSsInjectionGuard::is_attack( $candidate ) ) {
				return self::blocked();
			}
		}

		// The raw value has already passed UTF-8/control and canonical abuse checks. Preserve benign
		// technical syntax verbatim; prepared SQL and context-aware output escaping remain the safety
		// boundaries, so a lossy HTML-oriented sanitizer is neither required nor appropriate here.
		$query = trim( preg_replace( '/\s+/u', ' ', $raw ) ?? '' );
		if ( 1 !== preg_match( '//u', $query ) || YSSsInjectionGuard::is_attack( $query ) ) {
			return self::blocked();
		}
		$query = YSSsText::truncate_chars( $query, self::MAX_QUERY_CHARS );

		return [ 'blocked' => false, 'query' => $query ];
	}

	/**
	 * 在核心 `[ys_ec_products]` callback 前攔截 A/list 模式的 blocked request。
	 *
	 * @param mixed               $output 已有 short-circuit output，`false` 表示尚未處理。
	 * @param array<string,mixed> $attr
	 * @param array<int,string>   $match
	 */
	public static function pre_do_shortcode_tag( mixed $output, string $tag, array $attr, array $match ): mixed {
		if ( 'ys_ec_products' !== $tag ) {
			return $output;
		}
		if ( false !== $output || ! array_key_exists( 'ys_ec_search', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $output;
		}

		$raw      = wp_unslash( $_GET['ys_ec_search'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$decision = self::inspect( $raw );
		if ( ! $decision['blocked'] ) {
			return false;
		}

		return '<div class="ys-ss-results ys-ss-results--neutral"><p class="ys-ss-results__hint">'
			. esc_html__( '沒有符合的結果。', 'ys-cart-smart-search' )
			. '</p></div>';
	}

	public static function preserve_core_search_query( mixed $filtered, mixed $raw ): string {
		$fallback = is_string( $filtered ) ? $filtered : '';
		if ( ! is_string( $raw ) || ! array_key_exists( 'ys_ec_search', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $fallback;
		}

		$request_raw = wp_unslash( $_GET['ys_ec_search'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_scalar( $request_raw ) || (string) $request_raw !== $raw || ! self::is_core_product_list_call() ) {
			return $fallback;
		}

		$decision = self::inspect( $raw );
		return $decision['blocked'] ? $fallback : $decision['query'];
	}

	private static function is_core_product_list_call(): bool {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 ) as $frame ) {
			if ( 'render' === ( $frame['function'] ?? '' )
				&& self::CORE_PRODUCT_LIST_CLASS === ( $frame['class'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{blocked:true,query:string}
	 */
	private static function blocked(): array {
		return [ 'blocked' => true, 'query' => '' ];
	}
}

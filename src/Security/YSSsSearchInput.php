<?php
/**
 * 所有公開搜尋入口共用的 raw-first 決策器。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

defined( 'ABSPATH' ) || exit;

final class YSSsSearchInput {

	private const MAX_RAW_BYTES   = 2048;
	private const MAX_QUERY_CHARS = 100;

	public static function register(): void {
		add_filter( 'pre_do_shortcode_tag', [ self::class, 'pre_do_shortcode_tag' ], 10, 4 );
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

		foreach ( self::canonical_candidates( $raw ) as $candidate ) {
			if ( YSSsInjectionGuard::is_attack( $candidate ) ) {
				return self::blocked();
			}
		}

		$query = sanitize_text_field( $raw );
		if ( 1 !== preg_match( '//u', $query ) || YSSsInjectionGuard::is_attack( $query ) ) {
			return self::blocked();
		}
		$query = trim( preg_replace( '/\s+/u', ' ', $query ) ?? '' );
		$query = function_exists( 'mb_substr' )
			? mb_substr( $query, 0, self::MAX_QUERY_CHARS, 'UTF-8' )
			: substr( $query, 0, self::MAX_QUERY_CHARS );

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
		if ( false !== $output || 'ys_ec_products' !== $tag || ! array_key_exists( 'ys_ec_search', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $output;
		}

		$decision = self::inspect( wp_unslash( $_GET['ys_ec_search'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $decision['blocked'] ) {
			return false;
		}

		return '<div class="ys-ss-results ys-ss-results--neutral"><p class="ys-ss-results__hint">'
			. esc_html__( '沒有符合的結果。', 'ys-cart-smart-search' )
			. '</p></div>';
	}

	/**
	 * @return list<string>
	 */
	private static function canonical_candidates( string $raw ): array {
		$candidates = [ $raw ];
		$frontier   = [ $raw ];

		// 三輪有界閉包：每輪都可 percent decode、entity decode、width fold/NFKC，
		// 因而涵蓋 percent→entity、entity→percent、double entity 與 entity→fullwidth→percent 組合。
		for ( $round = 0; $round < 3 && $frontier; $round++ ) {
			$next = [];
			foreach ( $frontier as $candidate ) {
				$transforms = [
					rawurldecode( $candidate ),
					html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					self::fold_security_width( $candidate ),
				];
				if ( class_exists( '\Normalizer' ) ) {
					$normalized = \Normalizer::normalize( $candidate, \Normalizer::FORM_KC );
					if ( is_string( $normalized ) ) {
						$transforms[] = $normalized;
					}
				}

				foreach ( $transforms as $transformed ) {
					if ( $transformed === $candidate || strlen( $transformed ) > self::MAX_RAW_BYTES
						|| in_array( $transformed, $candidates, true ) ) {
						continue;
					}
					$candidates[] = $transformed;
					$next[]       = $transformed;
				}
			}
			$frontier = $next;
		}

		return $candidates;
	}

	private static function fold_security_width( string $value ): string {
		return strtr( $value, [
			'％' => '%', '＜' => '<', '＞' => '>', '：' => ':', '／' => '/', '＼' => '\\',
			'＄' => '$', '＃' => '#', '｛' => '{', '｝' => '}', '（' => '(', '）' => ')', '＝' => '=',
		] );
	}

	/**
	 * @return array{blocked:true,query:string}
	 */
	private static function blocked(): array {
		return [ 'blocked' => true, 'query' => '' ];
	}
}

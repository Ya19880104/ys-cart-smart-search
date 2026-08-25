<?php
/**
 * 混合式熱門搜尋建議（需求③）：手動置頂優先 → 自動統計補滿至 N。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Services;

use YangSheep\SmartSearch\Database\YSSsKeywordRepository;
use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Security\YSSsSearchInput;

defined( 'ABSPATH' ) || exit;

final class YSSsSuggestService {

	private const CACHE_PREFIX      = 'ys_ss_suggest_cache_v';
	private const LEGACY_CACHE_KEY  = 'ys_ss_suggest_cache';
	private const GENERATION_OPTION = 'ys_ss_suggest_cache_generation';
	private const CACHE_TTL         = 600; // 10 分鐘

	/**
	 * @return array{count:int,recent_enabled:bool,items:array<int,array{term:string,source:string}>}
	 */
	public static function suggestions(): array {
		$generation = self::generation();
		$cache_key  = self::cache_key( $generation );
		$cached     = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['items'] ) ) {
			return self::finalize_payload( $cached );
		}

		$settings = YSSsSettings::all();
		$count    = (int) $settings['suggest_count'];
		$items    = [];

		if ( $count > 0 ) {
			$seen = [];
			foreach ( YSSsKeywordRepository::active_keywords() as $keyword ) {
				$decision = YSSsSearchInput::inspect( $keyword );
				$norm     = YSSsQueryRepository::normalize( $decision['query'] );
				if ( $decision['blocked'] || '' === $norm || isset( $seen[ $norm ] ) ) {
					continue;
				}
				$seen[ $norm ] = true;
				$items[]       = [ 'term' => $decision['query'], 'source' => 'manual' ];
				if ( count( $items ) >= $count ) {
					break;
				}
			}

			if ( count( $items ) < $count ) {
				$auto = YSSsQueryRepository::auto_terms( (int) $settings['suggest_window_days'], $count * 3 );
				foreach ( $auto as $term ) {
					$norm = YSSsQueryRepository::normalize( $term );
					if ( '' === $norm || isset( $seen[ $norm ] ) ) {
						continue;
					}
					$seen[ $norm ] = true;
					$items[]       = [ 'term' => $term, 'source' => 'auto' ];
					if ( count( $items ) >= $count ) {
						break;
					}
				}
			}
		}

		/**
		 * 允許其他外掛調整候選；回傳前仍會經最後一道 raw-input filter。
		 *
		 * @param array $items
		 */
		$items = (array) apply_filters( 'ys_ss_suggestions', $items );

		$payload = self::finalize_payload( [
			'count'          => $count,
			'recent_enabled' => ! empty( $settings['recent_enabled'] ),
			'items'          => $items,
		] );

		// 使用函式開始時捕捉的 generation；若中途 invalidate，這個 late writer 只會寫舊 key。
		set_transient( $cache_key, $payload, self::CACHE_TTL );

		return $payload;
	}

	public static function invalidate(): void {
		$current = self::generation();
		update_option( self::GENERATION_OPTION, $current + 1, false );
		delete_transient( self::cache_key( $current ) );
		delete_transient( self::LEGACY_CACHE_KEY );
	}

	private static function generation(): int {
		return max( 1, (int) get_option( self::GENERATION_OPTION, 1 ) );
	}

	private static function cache_key( int $generation ): string {
		return self::CACHE_PREFIX . max( 1, $generation );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{count:int,recent_enabled:bool,items:array<int,array{term:string,source:string}>}
	 */
	private static function finalize_payload( array $payload ): array {
		$count = max( 0, min( 20, (int) ( $payload['count'] ?? 0 ) ) );
		if ( 0 === $count ) {
			return [
				'count'          => 0,
				'recent_enabled' => ! empty( $payload['recent_enabled'] ),
				'items'          => [],
			];
		}

		$items = [];
		$seen  = [];

		foreach ( (array) ( $payload['items'] ?? [] ) as $item ) {
			$record = is_array( $item ) ? $item : [ 'term' => $item, 'source' => 'auto' ];
			$term         = is_scalar( $record['term'] ?? null ) ? (string) $record['term'] : '';
			$source_value = $record['source'] ?? 'auto';
			$source       = is_scalar( $source_value ) ? sanitize_key( (string) $source_value ) : 'auto';
			$input  = YSSsSearchInput::inspect( $term );
			$norm   = YSSsQueryRepository::normalize( $input['query'] );
			if ( $input['blocked'] || '' === $norm || isset( $seen[ $norm ] ) ) {
				continue;
			}
			$seen[ $norm ] = true;
			$items[]       = [
				'term'   => $input['query'],
				'source' => '' !== $source ? $source : 'auto',
			];
			if ( count( $items ) >= $count ) {
				break;
			}
		}

		return [
			'count'          => $count,
			'recent_enabled' => ! empty( $payload['recent_enabled'] ),
			'items'          => $items,
		];
	}
}

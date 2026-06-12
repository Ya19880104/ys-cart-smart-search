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

defined( 'ABSPATH' ) || exit;

final class YSSsSuggestService {

	private const CACHE_KEY = 'ys_ss_suggest_cache';
	private const CACHE_TTL = 600; // 10 分鐘

	/**
	 * @return array{count:int,recent_enabled:bool,items:array<int,array{term:string,source:string}>}
	 */
	public static function suggestions(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['items'] ) ) {
			return $cached;
		}

		$settings = YSSsSettings::all();
		$count    = (int) $settings['suggest_count'];
		$items    = [];

		if ( $count > 0 ) {
			$seen = [];
			foreach ( YSSsKeywordRepository::active_keywords() as $kw ) {
				$norm = YSSsQueryRepository::normalize( $kw );
				if ( '' === $norm || isset( $seen[ $norm ] ) ) {
					continue;
				}
				$seen[ $norm ] = true;
				$items[]       = [ 'term' => $kw, 'source' => 'manual' ];
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
		 * 允許過濾最終建議清單。
		 *
		 * @param array $items
		 */
		$items = (array) apply_filters( 'ys_ss_suggestions', $items );

		$payload = [
			'count'          => $count,
			'recent_enabled' => ! empty( $settings['recent_enabled'] ),
			'items'          => array_values( $items ),
		];

		set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL );

		return $payload;
	}

	public static function invalidate(): void {
		delete_transient( self::CACHE_KEY );
	}
}

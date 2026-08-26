<?php
/**
 * 混合式熱門搜尋建議（需求③）：手動置頂優先 → 自動統計補滿至 N。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Services;

use YangSheep\SmartSearch\Analytics\YSSsAnalyticsAdmission;
use YangSheep\SmartSearch\Database\YSSsKeywordRepository;
use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Security\YSSsSearchInput;

defined( 'ABSPATH' ) || exit;

final class YSSsSuggestService {

	public const INVALIDATION_ROTATED      = 'rotated';
	public const INVALIDATION_BYPASS_FRESH = 'bypass_fresh';
	public const INVALIDATION_FAILED       = 'failed';

	private const CACHE_PREFIX      = 'ys_ss_suggest_cache_v';
	private const LEGACY_CACHE_KEY  = 'ys_ss_suggest_cache';
	private const GENERATION_OPTION = 'ys_ss_suggest_cache_generation';
	private const TOMBSTONE_PREFIX  = 'ys_ss_suggest_tombstone_';
	private const CACHE_TTL         = 600; // 10 分鐘

	/**
	 * @return array{count:int,recent_enabled:bool,items:array<int,array{term:string,source:string}>}
	 */
	public static function suggestions(): array {
		$state         = self::generation_state();
		$generation    = $state['generation'];
		$cache_allowed = $state['cacheable'];
		$cache_key     = $cache_allowed ? self::cache_key( $generation ) : '';

		if ( $cache_allowed && self::is_tombstoned( $generation ) ) {
			$captured = $generation;
			$status   = self::invalidate();
			$state    = self::generation_state();
			if ( self::INVALIDATION_ROTATED === $status
				&& $state['cacheable']
				&& $captured !== $state['generation']
				&& ! self::is_tombstoned( $state['generation'] ) ) {
				$generation = $state['generation'];
				$cache_key  = self::cache_key( $generation );
			} else {
				$cache_allowed = false;
				$cache_key     = '';
			}
		}

		if ( $cache_allowed ) {
			$cached = get_transient( $cache_key );
			// Invalidation writes the tombstone before rotating. Revalidate after the read so a
			// cache hit that overlapped either authority write cannot escape as current data.
			if ( self::generation_is_current( $generation ) ) {
				if ( is_array( $cached ) && isset( $cached['items'] ) ) {
					return self::finalize_payload( $cached );
				}
			} else {
				$cache_allowed = false;
			}
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

		if ( $cache_allowed && self::generation_is_current( $generation ) ) {
			set_transient( $cache_key, $payload, self::CACHE_TTL );
			// The pre-write check is the authority boundary. This post-write check is cleanup for
			// an invalidation that began between that check and the transient write.
			if ( ! self::generation_is_current( $generation ) ) {
				self::delete_transient_safely( $cache_key );
			}
		}

		return $payload;
	}

	public static function invalidate(): string {
		try {
			$state = self::generation_state();
		} catch ( \Throwable $error ) {
			self::delete_transient_safely( self::LEGACY_CACHE_KEY );
			return self::INVALIDATION_FAILED;
		}

		$captured     = $state['generation'];
		$captured_ok  = $state['cacheable'];
		$marker_name  = $captured_ok ? self::tombstone_name( $captured ) : '';
		$marker_error = false;

		if ( $captured_ok ) {
			try {
				add_option( $marker_name, $captured, '', false );
				// Always verify the stored bytes. add_option(false) is authoritative only when an
				// identical marker already exists.
				self::is_tombstoned( $captured );
			} catch ( \Throwable $error ) {
				$marker_error = true;
			}
		}

		$token = null;
		try {
			for ( $attempt = 0; $attempt < 4; $attempt++ ) {
				$candidate = bin2hex( random_bytes( 16 ) );
				if ( ! $captured_ok || $candidate !== $captured ) {
					$token = $candidate;
					break;
				}
			}
		} catch ( \Throwable $error ) {
			// A durable tombstone still supplies fail-closed authority when entropy is unavailable.
			$token = null;
		}

		$rotation_error = false;
		if ( null !== $token && ! $marker_error ) {
			try {
				// The boolean is not mutation truth: idempotence, races, and storage layers can all
				// return false. Final readback below is the sole authority.
				update_option( self::GENERATION_OPTION, $token, false );
			} catch ( \Throwable $error ) {
				$rotation_error = true;
			}
		}

		$final_state = [ 'cacheable' => false, 'generation' => '' ];
		try {
			$final_state = self::generation_state();
		} catch ( \Throwable $error ) {
			$rotation_error = true;
		}

		if ( $captured_ok ) {
			self::delete_transient_safely( self::cache_key( $captured ) );
		}
		self::delete_transient_safely( self::LEGACY_CACHE_KEY );

		if ( $final_state['cacheable']
			&& ( ! $captured_ok || $final_state['generation'] !== $captured ) ) {
			if ( $captured_ok ) {
				self::delete_option_safely( $marker_name );
			}
			return self::INVALIDATION_ROTATED;
		}

		$marker_durable = false;
		if ( $captured_ok && $final_state['cacheable'] && $final_state['generation'] === $captured ) {
			try {
				// Re-read at the decision point; an early successful read is not final authority.
				$marker_durable = self::is_tombstoned( $captured );
			} catch ( \Throwable $error ) {
				$marker_error = true;
			}
		}

		return $marker_durable && ! $marker_error && ! $rotation_error
			? self::INVALIDATION_BYPASS_FRESH
			: self::INVALIDATION_FAILED;
	}

	/**
	 * Missing state keeps the historical initial generation. Malformed stored state is explicitly
	 * non-cacheable so it cannot resurrect an old v1 transient.
	 *
	 * @return array{cacheable:bool,generation:string}
	 */
	private static function generation_state(): array {
		$value = get_option( self::GENERATION_OPTION, null );
		if ( null === $value ) {
			return [ 'cacheable' => true, 'generation' => '1' ];
		}
		if ( ! is_string( $value ) ) {
			return [ 'cacheable' => false, 'generation' => '' ];
		}
		$token = $value;
		return 1 === preg_match( '/\A[a-z0-9_-]{1,64}\z/iD', $token )
			? [ 'cacheable' => true, 'generation' => $token ]
			: [ 'cacheable' => false, 'generation' => '' ];
	}

	private static function cache_key( string $generation ): string {
		return self::CACHE_PREFIX . $generation;
	}

	private static function tombstone_name( string $generation ): string {
		return self::TOMBSTONE_PREFIX . hash( 'sha256', $generation );
	}

	private static function is_tombstoned( string $generation ): bool {
		return $generation === get_option( self::tombstone_name( $generation ), null );
	}

	private static function generation_is_current( string $generation ): bool {
		$state = self::generation_state();
		return $state['cacheable']
			&& $generation === $state['generation']
			&& ! self::is_tombstoned( $generation );
	}

	private static function delete_transient_safely( string $key ): void {
		try {
			delete_transient( $key );
		} catch ( \Throwable $error ) {
			// Invalidation status describes persisted authority, not best-effort cache cleanup.
		}
	}

	private static function delete_option_safely( string $key ): void {
		try {
			delete_option( $key );
		} catch ( \Throwable $error ) {
			// An old-generation marker is harmless after a verified rotation.
		}
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
			$input     = YSSsSearchInput::inspect( $term );
			$norm      = YSSsQueryRepository::normalize( $input['query'] );
			$is_manual = 'manual' === $source;
			if ( $input['blocked'] || '' === $norm || isset( $seen[ $norm ] )
				|| ( ! $is_manual && ! YSSsAnalyticsAdmission::should_record( $term, 1 ) ) ) {
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

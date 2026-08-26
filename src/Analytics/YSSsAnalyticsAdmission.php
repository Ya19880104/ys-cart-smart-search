<?php
/**
 * Search-analytics admission policy.
 *
 * This policy decides only whether a completed search is useful analytics. It never authorizes SQL,
 * renders output, or blocks a legitimate search from running.
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Analytics;

use YangSheep\SmartSearch\Security\YSSsSearchInput;
use YangSheep\SmartSearch\Support\YSSsText;

defined( 'ABSPATH' ) || exit;

final class YSSsAnalyticsAdmission {

	public const ADMIT_POSITIVE_RESULT  = 'admit_positive_result';
	public const ADMIT_HUMAN_ZERO       = 'admit_human_zero_result';
	public const REJECT_ATTACK          = 'reject_attack';
	public const REJECT_KNOWN_PARAMETER = 'reject_known_parameter';
	public const REJECT_MACHINE_TOKEN   = 'reject_machine_token';
	public const REJECT_EMPTY           = 'reject_empty';

	private const KNOWN_PARAMETER_PATTERN = '(?:utm_[a-z0-9_]+|fbclid|gclid|msclkid|yclid|_wpnonce|rest_route|wc-ajax|add-to-cart|ys_ec_search|redirect_to|redirect_uri|return_url|callback)';

	public static function classify( string $raw, int $server_total ): string {
		$input = YSSsSearchInput::inspect( $raw );
		if ( $input['blocked'] ) {
			return self::REJECT_ATTACK;
		}

		$query = $input['query'];
		if ( '' === $query || 1 !== preg_match( '/[\p{L}\p{N}]/u', $query ) ) {
			return self::REJECT_EMPTY;
		}

		// Inspect the complete accepted ingress, not only SearchInput's 100-character search clamp.
		// Otherwise a human-looking prefix could hide analytics noise in the discarded tail.
		$closure = YSSsText::canonical_candidates( $raw, $query );
		if ( ! $closure['complete'] ) {
			return self::REJECT_ATTACK;
		}

		$opaque_count = 0;
		foreach ( $closure['candidates'] as $candidate ) {
			if ( self::has_known_parameter_noise( $candidate ) ) {
				return self::REJECT_KNOWN_PARAMETER;
			}

			$opaque_count = max( $opaque_count, self::residual_opaque_count( $candidate ) );
		}
		if ( $opaque_count >= 2 || ( $server_total <= 0 && $opaque_count >= 1 ) ) {
			return self::REJECT_MACHINE_TOKEN;
		}

		return $server_total > 0 ? self::ADMIT_POSITIVE_RESULT : self::ADMIT_HUMAN_ZERO;
	}

	public static function should_record( string $raw, int $server_total ): bool {
		return str_starts_with( self::classify( $raw, $server_total ), 'admit_' );
	}

	private static function has_known_parameter_noise( string $query ): bool {
		if ( preg_match( '/(?:^|[?&\s])' . self::KNOWN_PARAMETER_PATTERN . '\s*=/iu', $query ) ) {
			return true;
		}
		// Generic search keys are ambiguous in human technical searches (for example "Q=5 speaker").
		// Treat them as parameters only in a compact query-string shape.
		if ( preg_match( '/(?:^|[?&])(?:q|query|search)\s*=\s*[^&\s]+(?:&|$)/iu', $query ) ) {
			return true;
		}

		preg_match_all( '/(?:^|[?&])[\p{L}][\p{L}\p{N}_-]{0,31}\s*=\s*[^&\s]{1,256}/u', $query, $matches );
		return count( $matches[0] ?? [] ) >= 2;
	}

	/**
	 * @return list<array{token:string,start:int,length:int}>
	 */
	private static function opaque_token_spans( string $query ): array {
		preg_match_all(
			'/(?<![\p{L}\p{N}])[A-Za-z0-9_-]{16,}(?![\p{L}\p{N}])/u',
			$query,
			$matches,
			PREG_OFFSET_CAPTURE
		);

		$spans = [];
		foreach ( $matches[0] ?? [] as $match ) {
			[ $token, $start ] = $match;
			$is_uuid = 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $token );
			$is_hex  = strlen( $token ) >= 16 && 1 === preg_match( '/\A[0-9a-f]+\z/iD', $token );
			$is_mixed_long = strlen( $token ) >= 20
				&& 1 === preg_match( '/[A-Za-z]/', $token )
				&& 1 === preg_match( '/[0-9]/', $token );
			$is_consonant_noise = strlen( $token ) >= 20
				&& 1 === preg_match( '/\A[A-Za-z]+\z/D', $token )
				&& 1 === preg_match( '/[bcdfghjklmnpqrstvwxyz]{5,}/i', $token )
				&& preg_match_all( '/[aeiou]/i', $token ) <= (int) floor( strlen( $token ) * 0.2 );
			$is_obvious_sequence = self::is_obvious_sequence( $token );
			if ( $is_uuid || $is_hex || $is_mixed_long || $is_consonant_noise || $is_obvious_sequence ) {
				$spans[] = [
					'token'  => $token,
					'start'  => (int) $start,
					'length' => strlen( $token ),
				];
			}
		}

		return $spans;
	}

	/**
	 * @return list<array{start:int,length:int}>
	 */
	private static function identifier_spans( string $query ): array {
		preg_match_all(
			'/(?:^|[^\p{L}\p{N}])((?:sku|isbn(?:-1[03])?|ean|upc|mpn|型號|料號)\s*[-:#]?\s*[A-Za-z0-9][A-Za-z0-9._-]{3,})(?=$|[^\p{L}\p{N}])/iu',
			$query,
			$matches,
			PREG_OFFSET_CAPTURE
		);

		$spans = [];
		foreach ( $matches[1] ?? [] as $match ) {
			[ $identifier, $start ] = $match;
			$spans[] = [
				'start'  => (int) $start,
				'length' => strlen( $identifier ),
			];
		}

		return $spans;
	}

	private static function residual_opaque_count( string $query ): int {
		$identifiers = self::identifier_spans( $query );
		$residual    = [];

		foreach ( self::opaque_token_spans( $query ) as $opaque ) {
			$opaque_end = $opaque['start'] + $opaque['length'];
			foreach ( $identifiers as $identifier ) {
				$identifier_end = $identifier['start'] + $identifier['length'];
				if ( $opaque['start'] >= $identifier['start'] && $opaque_end <= $identifier_end ) {
					continue 2;
				}
			}
			$residual[] = $opaque['token'];
		}

		return count( array_unique( $residual ) );
	}

	private static function is_obvious_sequence( string $token ): bool {
		$token = strtolower( $token );
		if ( 1 === preg_match( '/\A(.)\1{15,}\z/D', $token ) ) {
			return true;
		}

		foreach ( [
			'abcdefghijklmnopqrstuvwxyz',
			'zyxwvutsrqponmlkjihgfedcba',
			'qwertyuiopasdfghjklzxcvbnm',
			'mnbvcxzlkjhgfdsaqpoiuytrewq',
		] as $sequence ) {
			if ( strlen( $token ) >= 16 && str_contains( $sequence, $token ) ) {
				return true;
			}
		}
		return false;
	}
}

<?php
/**
 * Search-analytics admission policy.
 *
 * Every non-empty search that passes the shared malicious-input decision is analytics data. This
 * class must not invent a second machine/noise classifier: rate limiting controls volume, while
 * prepared SQL and context-aware escaping remain the injection boundaries.
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Analytics;

use YangSheep\SmartSearch\Security\YSSsSearchInput;

defined( 'ABSPATH' ) || exit;

final class YSSsAnalyticsAdmission {

	public const ADMIT_POSITIVE_RESULT  = 'admit_positive_result';
	public const ADMIT_HUMAN_ZERO       = 'admit_human_zero_result';
	public const REJECT_ATTACK          = 'reject_attack';
	public const REJECT_EMPTY           = 'reject_empty';

	// Retained as internal compatibility constants; classify() no longer guesses these categories.
	public const REJECT_KNOWN_PARAMETER = 'reject_known_parameter';
	public const REJECT_MACHINE_TOKEN   = 'reject_machine_token';

	public static function classify( string $raw, int $server_total ): string {
		$input = YSSsSearchInput::inspect( $raw );
		if ( $input['blocked'] ) {
			return self::REJECT_ATTACK;
		}

		$query = $input['query'];
		if ( '' === $query ) {
			return self::REJECT_EMPTY;
		}

		return $server_total > 0 ? self::ADMIT_POSITIVE_RESULT : self::ADMIT_HUMAN_ZERO;
	}

	public static function should_record( string $raw, int $server_total ): bool {
		return str_starts_with( self::classify( $raw, $server_total ), 'admit_' );
	}
}

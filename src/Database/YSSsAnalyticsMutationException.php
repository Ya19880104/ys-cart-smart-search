<?php
/**
 * Fixed-reason exception for analytics maintenance mutations.
 *
 * The exception intentionally carries no SQL text or database error detail so REST callers can
 * map it to a stable, sanitized response without leaking backend state.
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Database;

defined( 'ABSPATH' ) || exit;

final class YSSsAnalyticsMutationException extends \RuntimeException {

	public const REASON_BUSY     = 'busy';
	public const REASON_DATABASE = 'database';

	private function __construct( private readonly string $reason ) {
		parent::__construct( self::REASON_BUSY === $reason ? 'analytics maintenance busy' : 'analytics mutation failed' );
	}

	public static function busy(): self {
		return new self( self::REASON_BUSY );
	}

	public static function database_failure(): self {
		return new self( self::REASON_DATABASE );
	}

	public function reason(): string {
		return $this->reason;
	}
}

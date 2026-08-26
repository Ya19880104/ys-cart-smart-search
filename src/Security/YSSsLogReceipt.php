<?php
/**
 * 短效 server-signed analytics receipt。
 *
 * 公開 `/log` 不再信任 client total；只接受 `/query` 對同一 query／visitor 簽發的 claims。
 * v2 claim `t`／驗證結果 `total` 代表 server-derived product-positive count，
 * 而不是公開搜尋回應的 aggregate display total。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

use YangSheep\SmartSearch\Support\YSSsText;

defined( 'ABSPATH' ) || exit;

final class YSSsLogReceipt {

	public const MAX_TOTAL = 1000000;

	private const VERSION           = 2;
	private const TTL_SECONDS       = 120;
	private const MAX_TOKEN_BYTES   = 1024;
	private const MAX_PAYLOAD_BYTES = 768;

	/**
	 * @param int $total Server-derived product-positive count stored in claim `t`.
	 */
	public static function issue( string $query, int $total, string $content_types, string $visitor_hash, ?int $now = null ): string {
		return self::issue_for_request( $query, $total, $content_types, $visitor_hash, $query, $now );
	}

	/**
	 * Issue a v2 receipt whose signature is bound to the exact accepted ingress bytes.
	 */
	public static function issue_for_request(
		string $query,
		int $total,
		string $content_types,
		string $visitor_hash,
		string $raw,
		?int $now = null
	): string {
		$now          = $now ?? time();
		$query        = self::bounded_query( $query );
		$visitor_hash = self::bounded_visitor( $visitor_hash );
		if ( '' === $query || '' === $visitor_hash || ! self::valid_raw( $raw ) ) {
			return '';
		}
		try {
			$event_id = bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $error ) {
			return '';
		}

		$claims = [
			'v'   => self::VERSION,
			'q'   => $query,
			't'   => max( 0, min( self::MAX_TOTAL, $total ) ),
			'c'   => self::normalize_content_types( $content_types ),
			'vh'  => $visitor_hash,
			'eid' => $event_id,
			'iat' => $now,
			'exp' => $now + self::TTL_SECONDS,
		];
		$json   = wp_json_encode( $claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			return '';
		}

		$payload   = self::base64url_encode( $json );
		$signature = self::base64url_encode( hash_hmac(
			'sha256',
			self::signature_input( $payload, $raw ),
			wp_salt( 'auth' ),
			true
		) );
		$receipt   = $payload . '.' . $signature;

		return strlen( $receipt ) <= self::MAX_TOKEN_BYTES ? $receipt : '';
	}

	/**
	 * @return array{query:string,total:int,content_types:string,visitor_hash:string}|null
	 */
	public static function verify( string $receipt, string $query, string $visitor_hash, ?int $now = null ): ?array {
		return self::verify_bound( $receipt, $query, $query, $visitor_hash, $now );
	}

	/**
	 * Verify a receipt against an explicit ingress and caller-supplied visitor identity.
	 * Legacy v1 remains available only to this non-production compatibility ABI.
	 *
	 * @return array{query:string,total:int,content_types:string,visitor_hash:string}|null
	 */
	public static function verify_bound(
		string $receipt,
		string $query,
		string $raw,
		string $visitor_hash,
		?int $now = null
	): ?array {
		$now     = $now ?? time();
		$claims  = self::verified_claims( $receipt, $query, $raw, $now, true );
		$visitor = self::bounded_visitor( $visitor_hash );
		if ( null === $claims || '' === $visitor || ! hash_equals( $claims['visitor_hash'], $visitor ) ) {
			return null;
		}

		return self::public_claims( $claims );
	}

	/**
	 * Verify a public log request against the visitor identity from the signed issue day.
	 *
	 * @return array{query:string,total:int,content_types:string,visitor_hash:string}|null
	 */
	public static function verify_for_request( string $receipt, string $query, string $raw, ?int $now = null ): ?array {
		$now    = $now ?? time();
		$claims = self::verified_claims( $receipt, $query, $raw, $now, false );
		if ( null === $claims ) {
			return null;
		}

		$visitor = YSSsRateLimiter::visitor_hash_at( $claims['iat'] );
		if ( ! hash_equals( $claims['visitor_hash'], $visitor ) ) {
			return null;
		}

		return self::public_claims( $claims );
	}

	/**
	 * @return array{query:string,total:int,content_types:string,visitor_hash:string,iat:int}|null
	 */
	private static function verified_claims( string $receipt, string $query, string $raw, int $now, bool $allow_legacy ): ?array {
		if ( '' === $receipt || strlen( $receipt ) > self::MAX_TOKEN_BYTES || 1 !== substr_count( $receipt, '.' ) ) {
			return null;
		}

		[ $payload, $encoded_signature ] = explode( '.', $receipt, 2 );
		$signature = self::base64url_decode( $encoded_signature );
		if ( null === $signature || 32 !== strlen( $signature ) ) {
			return null;
		}
		$json = self::base64url_decode( $payload );
		if ( null === $json || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			return null;
		}
		$claims = json_decode( $json, true );
		if ( ! is_array( $claims )
			|| ! is_int( $claims['v'] ?? null )
			|| ! is_string( $claims['q'] ?? null )
			|| ! is_int( $claims['t'] ?? null )
			|| ! is_string( $claims['c'] ?? null )
			|| ! is_string( $claims['vh'] ?? null )
			|| ! is_int( $claims['iat'] ?? null )
			|| ! is_int( $claims['exp'] ?? null ) ) {
			return null;
		}

		$version = $claims['v'];
		if ( self::VERSION === $version ) {
			if ( ! self::valid_raw( $raw )
				|| ! is_string( $claims['eid'] ?? null )
				|| 1 !== preg_match( '/\A[a-f0-9]{16}\z/D', $claims['eid'] ) ) {
				return null;
			}
			$signature_input = self::signature_input( $payload, $raw );
		} elseif ( 1 === $version && $allow_legacy ) {
			$signature_input = $payload;
		} else {
			return null;
		}
		$expected = hash_hmac( 'sha256', $signature_input, wp_salt( 'auth' ), true );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		$claim_visitor = self::bounded_visitor( $claims['vh'] );
		if ( $claims['t'] < 0 || $claims['t'] > self::MAX_TOTAL
			|| $claims['exp'] - $claims['iat'] !== self::TTL_SECONDS
			|| $claims['iat'] > $now + 30
			|| $claims['exp'] < $now
			|| '' === $claims['q']
			|| $claims['q'] !== self::bounded_query( $claims['q'] )
			|| $claims['c'] !== self::normalize_content_types( $claims['c'] )
			|| ! hash_equals( $claims['q'], self::bounded_query( $query ) )
			|| '' === $claim_visitor
			|| ! hash_equals( $claims['vh'], $claim_visitor ) ) {
			return null;
		}

		return [
			'query'         => $claims['q'],
			'total'         => $claims['t'],
			'content_types' => $claims['c'],
			'visitor_hash'  => $claims['vh'],
			'iat'           => $claims['iat'],
		];
	}

	/**
	 * @param array{query:string,total:int,content_types:string,visitor_hash:string,iat:int} $claims
	 * @return array{query:string,total:int,content_types:string,visitor_hash:string}
	 */
	private static function public_claims( array $claims ): array {
		return [
			'query'         => $claims['query'],
			'total'         => $claims['total'],
			'content_types' => $claims['content_types'],
			'visitor_hash'  => $claims['visitor_hash'],
		];
	}

	private static function bounded_query( string $query ): string {
		$query = trim( $query );
		return YSSsText::truncate_chars( $query, 100 );
	}

	private static function bounded_visitor( string $visitor_hash ): string {
		return substr( trim( $visitor_hash ), 0, 64 );
	}

	private static function normalize_content_types( string $content_types ): string {
		$allowed = [ 'products', 'categories', 'posts' ];
		$types   = array_values( array_unique( array_filter(
			array_map( 'trim', explode( ',', strtolower( $content_types ) ) ),
			static fn( string $type ): bool => in_array( $type, $allowed, true )
		) ) );
		return implode( ',', $types );
	}

	private static function valid_raw( string $raw ): bool {
		return '' !== $raw && strlen( $raw ) <= 2048 && 1 === preg_match( '//u', $raw );
	}

	private static function signature_input( string $payload, string $raw ): string {
		return "ys-ss-log-receipt-v2\0"
			. pack( 'N', strlen( $payload ) )
			. $payload
			. pack( 'N', strlen( $raw ) )
			. $raw;
	}

	private static function base64url_encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $encoded ): ?string {
		if ( '' === $encoded || preg_match( '/[^A-Za-z0-9_-]/', $encoded ) ) {
			return null;
		}
		$padding = ( 4 - strlen( $encoded ) % 4 ) % 4;
		$decoded = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', $padding ), true );
		if ( false === $decoded || self::base64url_encode( $decoded ) !== $encoded ) {
			return null;
		}

		return $decoded;
	}
}

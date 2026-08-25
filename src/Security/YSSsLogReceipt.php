<?php
/**
 * 短效 server-signed analytics receipt。
 *
 * 公開 `/log` 不再信任 client total；只接受 `/query` 對同一 query／visitor 簽發的 claims。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

defined( 'ABSPATH' ) || exit;

final class YSSsLogReceipt {

	public const MAX_TOTAL = 1000000;

	private const VERSION           = 1;
	private const TTL_SECONDS       = 120;
	private const MAX_TOKEN_BYTES   = 1024;
	private const MAX_PAYLOAD_BYTES = 768;

	public static function issue( string $query, int $total, string $content_types, string $visitor_hash ): string {
		$query = self::bounded_query( $query );
		if ( '' === $query ) {
			return '';
		}

		$now    = time();
		$claims = [
			'v'   => self::VERSION,
			'q'   => $query,
			't'   => max( 0, min( self::MAX_TOTAL, $total ) ),
			'c'   => self::normalize_content_types( $content_types ),
			'vh'  => self::bounded_visitor( $visitor_hash ),
			'iat' => $now,
			'exp' => $now + self::TTL_SECONDS,
		];
		$json   = wp_json_encode( $claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			return '';
		}

		$payload   = self::base64url_encode( $json );
		$signature = self::base64url_encode( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ), true ) );
		$receipt   = $payload . '.' . $signature;

		return strlen( $receipt ) <= self::MAX_TOKEN_BYTES ? $receipt : '';
	}

	/**
	 * @return array{query:string,total:int,content_types:string}|null
	 */
	public static function verify( string $receipt, string $query, string $visitor_hash ): ?array {
		if ( '' === $receipt || strlen( $receipt ) > self::MAX_TOKEN_BYTES || 1 !== substr_count( $receipt, '.' ) ) {
			return null;
		}

		[ $payload, $encoded_signature ] = explode( '.', $receipt, 2 );
		$signature = self::base64url_decode( $encoded_signature );
		if ( null === $signature || 32 !== strlen( $signature ) ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ), true );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		$json = self::base64url_decode( $payload );
		if ( null === $json || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			return null;
		}
		$claims = json_decode( $json, true );
		if ( ! is_array( $claims )
			|| self::VERSION !== ( $claims['v'] ?? null )
			|| ! is_string( $claims['q'] ?? null )
			|| ! is_int( $claims['t'] ?? null )
			|| ! is_string( $claims['c'] ?? null )
			|| ! is_string( $claims['vh'] ?? null )
			|| ! is_int( $claims['iat'] ?? null )
			|| ! is_int( $claims['exp'] ?? null ) ) {
			return null;
		}

		$now = time();
		if ( $claims['t'] < 0 || $claims['t'] > self::MAX_TOTAL
			|| $claims['exp'] - $claims['iat'] !== self::TTL_SECONDS
			|| $claims['iat'] > $now + 30
			|| $claims['exp'] < $now
			|| $claims['q'] !== self::bounded_query( $claims['q'] )
			|| $claims['c'] !== self::normalize_content_types( $claims['c'] )
			|| ! hash_equals( $claims['q'], self::bounded_query( $query ) )
			|| ! hash_equals( $claims['vh'], self::bounded_visitor( $visitor_hash ) ) ) {
			return null;
		}

		return [
			'query'         => $claims['q'],
			'total'         => $claims['t'],
			'content_types' => $claims['c'],
		];
	}

	private static function bounded_query( string $query ): string {
		$query = trim( $query );
		return function_exists( 'mb_substr' )
			? mb_substr( $query, 0, 100, 'UTF-8' )
			: substr( $query, 0, 100 );
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

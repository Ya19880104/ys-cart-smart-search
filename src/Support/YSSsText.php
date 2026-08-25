<?php
/**
 * UTF-8 text helpers that do not require mbstring.
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Support;

defined( 'ABSPATH' ) || exit;

final class YSSsText {

	public static function fold_fullwidth_ascii( string $value ): string {
		$folded = preg_replace_callback(
			'/[\x{FF01}-\x{FF5E}]/u',
			static function ( array $match ): string {
				$bytes = unpack( 'C*', $match[0] );
				if ( ! is_array( $bytes ) || 3 !== count( $bytes ) ) {
					return $match[0];
				}
				$codepoint = ( ( $bytes[1] & 0x0F ) << 12 )
					| ( ( $bytes[2] & 0x3F ) << 6 )
					| ( $bytes[3] & 0x3F );
				return chr( $codepoint - 0xFEE0 );
			},
			$value
		);

		return str_replace( "\xE3\x80\x80", ' ', is_string( $folded ) ? $folded : $value );
	}

	public static function truncate_chars( string $value, int $max_chars ): string {
		if ( $max_chars <= 0 || '' === $value || 1 !== preg_match( '//u', $value ) ) {
			return '';
		}

		$pattern = '/\A(.{0,' . $max_chars . '})/us';
		return 1 === preg_match( $pattern, $value, $match ) ? (string) $match[1] : '';
	}
}

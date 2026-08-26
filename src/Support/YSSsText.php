<?php
/**
 * UTF-8 text helpers that do not require mbstring.
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Support;

defined( 'ABSPATH' ) || exit;

final class YSSsText {

	private const MAX_CANONICAL_CANDIDATES = 64;
	private const MAX_CANONICAL_TRANSFORMS = 256;
	private const MAX_CANONICAL_BYTES      = 2048;

	private const MATHEMATICAL_LATIN_STARTS = [
		0x1D400,
		0x1D434,
		0x1D468,
		0x1D49C,
		0x1D4D0,
		0x1D504,
		0x1D538,
		0x1D56C,
		0x1D5A0,
		0x1D5D4,
		0x1D608,
		0x1D63C,
		0x1D670,
	];

	private const MATHEMATICAL_LATIN_HOLES = [
		0x1D455 => true,
		0x1D49D => true,
		0x1D4A0 => true,
		0x1D4A1 => true,
		0x1D4A3 => true,
		0x1D4A4 => true,
		0x1D4A7 => true,
		0x1D4A8 => true,
		0x1D4AD => true,
		0x1D4BA => true,
		0x1D4BC => true,
		0x1D4C4 => true,
		0x1D506 => true,
		0x1D50B => true,
		0x1D50C => true,
		0x1D515 => true,
		0x1D51D => true,
		0x1D53A => true,
		0x1D53F => true,
		0x1D545 => true,
		0x1D547 => true,
		0x1D548 => true,
		0x1D549 => true,
		0x1D551 => true,
	];

	private const MATHEMATICAL_DIGIT_STARTS = [
		0x1D7CE,
		0x1D7D8,
		0x1D7E2,
		0x1D7EC,
		0x1D7F6,
	];

	// The Mathematical Alphanumeric block ends its Latin family with dotless i/j.
	// NFKC preserves their dotless spelling, so the abuse-control subset maps them explicitly.
	private const MATHEMATICAL_SPECIAL_ASCII = [
		0x1D6A4 => 'i',
		0x1D6A5 => 'j',
	];

	private const LETTERLIKE_ASCII = [
		0x2102 => 'C',
		0x210A => 'g',
		0x210B => 'H',
		0x210C => 'H',
		0x210D => 'H',
		0x210E => 'h',
		0x2110 => 'I',
		0x2111 => 'I',
		0x2112 => 'L',
		0x2113 => 'l',
		0x2115 => 'N',
		0x2119 => 'P',
		0x211A => 'Q',
		0x211B => 'R',
		0x211C => 'R',
		0x211D => 'R',
		0x2124 => 'Z',
		0x2128 => 'Z',
		0x212A => 'K',
		0x212C => 'B',
		0x212D => 'C',
		0x212F => 'e',
		0x2130 => 'E',
		0x2131 => 'F',
		0x2133 => 'M',
		0x2134 => 'o',
		0x2139 => 'i',
		0x2145 => 'D',
		0x2146 => 'd',
		0x2147 => 'e',
		0x2148 => 'i',
		0x2149 => 'j',
	];

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

	/**
	 * Fold the dependency-free compatibility ASCII security subset.
	 *
	 * This covers fullwidth ASCII/space, Mathematical Latin/digits and the explicit
	 * Letterlike Symbols table below. It is intentionally not a complete NFKC implementation.
	 */
	public static function fold_compatibility_ascii( string $value ): string|false {
		$value = self::fold_fullwidth_ascii( $value );
		return preg_replace_callback(
			'/[\x{2100}-\x{214F}\x{1D400}-\x{1D7FF}]/u',
			static function ( array $match ): string {
				$codepoint = self::utf8_codepoint( $match[0] );
				if ( null === $codepoint ) {
					return $match[0];
				}
				if ( isset( self::LETTERLIKE_ASCII[ $codepoint ] ) ) {
					return self::LETTERLIKE_ASCII[ $codepoint ];
				}
				if ( isset( self::MATHEMATICAL_SPECIAL_ASCII[ $codepoint ] ) ) {
					return self::MATHEMATICAL_SPECIAL_ASCII[ $codepoint ];
				}

				foreach ( self::MATHEMATICAL_LATIN_STARTS as $start ) {
					$offset = $codepoint - $start;
					if ( $offset < 0 || $offset >= 52 ) {
						continue;
					}
					if ( isset( self::MATHEMATICAL_LATIN_HOLES[ $codepoint ] ) ) {
						return $match[0];
					}
					return $offset < 26 ? chr( 65 + $offset ) : chr( 97 + $offset - 26 );
				}

				foreach ( self::MATHEMATICAL_DIGIT_STARTS as $start ) {
					$offset = $codepoint - $start;
					if ( $offset >= 0 && $offset < 10 ) {
						return chr( 48 + $offset );
					}
				}

				return $match[0];
			},
			$value
		);
	}

	/**
	 * Build the bounded canonical closure shared by ingress and analytics.
	 *
	 * The dependency-free compatibility edge is deliberately an ASCII security subset, not a
	 * replacement for Unicode NFKC. When intl is available, NFKC is one additional graph edge.
	 * `complete` is true only when every queued candidate and transform reached a fixed point.
	 *
	 * @return array{candidates:list<string>,complete:bool}
	 */
	public static function canonical_candidates( string ...$seeds ): array {
		$candidates = [];
		$queue      = [];
		$seen       = [];

		foreach ( $seeds as $seed ) {
			if ( strlen( $seed ) > self::MAX_CANONICAL_BYTES || 1 !== preg_match( '//u', $seed ) ) {
				return [ 'candidates' => $candidates, 'complete' => false ];
			}

			$key = "\0" . $seed;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			if ( count( $candidates ) >= self::MAX_CANONICAL_CANDIDATES ) {
				return [ 'candidates' => $candidates, 'complete' => false ];
			}

			$seen[ $key ] = true;
			$candidates[] = $seed;
			$queue[]      = $seed;
		}

		$cursor          = 0;
		$transform_count = 0;
		try {
			$has_normalizer = class_exists( '\\Normalizer' );
		} catch ( \Throwable ) {
			return [ 'candidates' => $candidates, 'complete' => false ];
		}

		while ( $cursor < count( $queue ) ) {
			$candidate = $queue[ $cursor++ ];
			$transforms = [
				static fn( string $value ): string => rawurldecode( $value ),
				static fn( string $value ): string => html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				static fn( string $value ): string|false => self::fold_compatibility_ascii( $value ),
			];

			if ( $has_normalizer ) {
				$transforms[] = static fn( string $value ): string|false => \Normalizer::normalize( $value, \Normalizer::FORM_KC );
			}

			foreach ( $transforms as $transform ) {
				if ( $transform_count >= self::MAX_CANONICAL_TRANSFORMS ) {
					return [ 'candidates' => $candidates, 'complete' => false ];
				}
				++$transform_count;

				try {
					$transformed = $transform( $candidate );
				} catch ( \Throwable ) {
					return [ 'candidates' => $candidates, 'complete' => false ];
				}

				if ( ! is_string( $transformed ) || strlen( $transformed ) > self::MAX_CANONICAL_BYTES
					|| 1 !== preg_match( '//u', $transformed ) ) {
					return [ 'candidates' => $candidates, 'complete' => false ];
				}

				$key = "\0" . $transformed;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				if ( count( $candidates ) >= self::MAX_CANONICAL_CANDIDATES ) {
					return [ 'candidates' => $candidates, 'complete' => false ];
				}

				$seen[ $key ] = true;
				$candidates[] = $transformed;
				$queue[]      = $transformed;
			}
		}

		return [ 'candidates' => $candidates, 'complete' => true ];
	}

	public static function truncate_chars( string $value, int $max_chars ): string {
		if ( $max_chars <= 0 || '' === $value || 1 !== preg_match( '//u', $value ) ) {
			return '';
		}

		$pattern = '/\A(.{0,' . $max_chars . '})/us';
		return 1 === preg_match( $pattern, $value, $match ) ? (string) $match[1] : '';
	}

	private static function utf8_codepoint( string $value ): ?int {
		$bytes = unpack( 'C*', $value );
		if ( ! is_array( $bytes ) ) {
			return null;
		}

		return match ( count( $bytes ) ) {
			1 => $bytes[1],
			2 => ( ( $bytes[1] & 0x1F ) << 6 ) | ( $bytes[2] & 0x3F ),
			3 => ( ( $bytes[1] & 0x0F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F ),
			4 => ( ( $bytes[1] & 0x07 ) << 18 ) | ( ( $bytes[2] & 0x3F ) << 12 )
				| ( ( $bytes[3] & 0x3F ) << 6 ) | ( $bytes[4] & 0x3F ),
			default => null,
		};
	}
}

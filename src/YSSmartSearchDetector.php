<?php
/**
 * 環境偵測（fail-soft 的根）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch;

defined( 'ABSPATH' ) || exit;

final class YSSmartSearchDetector {

	public static function has_ys_cart(): bool {
		return defined( 'YS_ECOMMERCE_VERSION' )
			|| class_exists( '\YangSheep\Ecommerce\YSEcommerce' );
	}

	/**
	 * 商店頁 URL（搜尋表單 action / 「查看全部」連結）。
	 */
	public static function shop_url( array $query_args = [] ): string {
		if ( class_exists( '\YangSheep\Ecommerce\Services\Setup\YSPageResolver' ) ) {
			try {
				return (string) \YangSheep\Ecommerce\Services\Setup\YSPageResolver::shop_url( $query_args );
			} catch ( \Throwable $e ) {
				// fall through
			}
		}
		$url = home_url( '/shop/' );
		return $query_args ? add_query_arg( $query_args, $url ) : $url;
	}

	public static function product_url( string $slug ): string {
		if ( class_exists( '\YangSheep\Ecommerce\Services\Setup\YSPageResolver' ) ) {
			try {
				return (string) \YangSheep\Ecommerce\Services\Setup\YSPageResolver::product_url( $slug );
			} catch ( \Throwable $e ) {
				// fall through
			}
		}
		return self::shop_url( [ 'product' => sanitize_title( $slug ) ] );
	}

	public static function category_url( string $slug ): string {
		if ( class_exists( '\YangSheep\Ecommerce\Services\Setup\YSPageResolver' ) ) {
			try {
				return (string) \YangSheep\Ecommerce\Services\Setup\YSPageResolver::category_url( $slug );
			} catch ( \Throwable $e ) {
				// fall through
			}
		}
		return self::shop_url( [ 'ys_ec_cat' => sanitize_title( $slug ) ] );
	}

	/**
	 * 貨幣符號（跟核心基本設定）。
	 */
	public static function currency_symbol(): string {
		$currency = 'TWD';
		if ( class_exists( '\YangSheep\Ecommerce\Settings\YSSettingsRepository' ) ) {
			try {
				$currency = (string) \YangSheep\Ecommerce\Settings\YSSettingsRepository::get( 'currency', 'TWD' );
			} catch ( \Throwable $e ) {
				$currency = 'TWD';
			}
		}
		return 'USD' === strtoupper( $currency ) ? '$' : 'NT$';
	}
}

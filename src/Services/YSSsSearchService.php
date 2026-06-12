<?php
/**
 * 分組搜尋管線（ADR-058 §5.4）。
 *
 * 商品＝直查 `ys_ec_products`（獨立運作，相關性：標題前綴 > 標題含 > SKU），
 * 尊重核心 `ys_ec_search_excluded_slugs` 排除設定；分類＝`ys_ec_categories`；
 * 文章/頁面＝WP_Query（post_type 白名單）。各組依後台 item 呈現設定截取。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Services;

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\YSSmartSearchDetector;

defined( 'ABSPATH' ) || exit;

final class YSSsSearchService {

	/**
	 * @return array<string,mixed> { q, total, groups: [ {type,label,total,items[]} ] }
	 */
	public static function search( string $q ): array {
		$settings = YSSsSettings::all();
		$norm     = YSSsQueryRepository::normalize( $q );

		$groups = [];
		$total  = 0;

		if ( '' !== $norm ) {
			foreach ( $settings['group_order'] as $type ) {
				$group = null;
				if ( 'products' === $type ) {
					$group = self::products_group( $norm, $settings['products'] );
				} elseif ( 'categories' === $type && ! empty( $settings['categories']['enabled'] ) ) {
					$group = self::categories_group( $norm, $settings['categories'] );
				} elseif ( 'posts' === $type && ! empty( $settings['posts']['enabled'] ) ) {
					$group = self::posts_group( $norm, $settings['posts'] );
				}
				if ( $group && $group['items'] ) {
					$groups[] = $group;
					$total   += (int) $group['total'];
				}
			}
		}

		/**
		 * 允許其他外掛調整分組結果。
		 *
		 * @param array  $groups
		 * @param string $norm
		 */
		$groups = (array) apply_filters( 'ys_ss_result_groups', $groups, $norm );

		return [
			'q'        => $norm,
			'total'    => $total,
			'groups'   => $groups,
			'view_all' => YSSmartSearchDetector::shop_url( [ 'ys_ec_search' => $norm ] ),
		];
	}

	/**
	 * @param array<string,mixed> $cfg
	 * @return array<string,mixed>
	 */
	private static function products_group( string $q, array $cfg ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ys_ec_products';
		$like  = '%' . $wpdb->esc_like( $q ) . '%';
		$pref  = $wpdb->esc_like( $q ) . '%';
		$limit = (int) $cfg['limit'];

		// 核心既有排除設定（逗號/換行分隔 slugs）
		$excluded     = [];
		if ( class_exists( '\YangSheep\Ecommerce\Settings\YSSettingsRepository' ) ) {
			try {
				$raw_excluded = (string) \YangSheep\Ecommerce\Settings\YSSettingsRepository::get( 'ys_ec_search_excluded_slugs', '' );
				$excluded     = array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', $raw_excluded ) ?: [] ) );
			} catch ( \Throwable $e ) {
				$excluded = [];
			}
		}
		$exclude_sql = '';
		$args        = [ $pref, $like, $like, $like, $pref, $like ];
		if ( $excluded ) {
			$placeholders = implode( ',', array_fill( 0, count( $excluded ), '%s' ) );
			$exclude_sql  = " AND slug NOT IN ({$placeholders})";
			array_splice( $args, 4, 0, $excluded ); // WHERE 段在 ORDER 參數之前
		}

		// 相關性：標題前綴(0) > 標題含(1) > SKU(2)
		$sql = "SELECT id, title, slug, sku, price, sale_price, image_url
				FROM {$table}
				WHERE status = 'publish'
				  AND ( title LIKE %s OR title LIKE %s OR sku LIKE %s OR slug LIKE %s ){$exclude_sql}
				ORDER BY CASE WHEN title LIKE %s THEN 0 WHEN title LIKE %s THEN 1 ELSE 2 END, id DESC
				LIMIT " . ( $limit + 1 );
		// 第一個 %s 用前綴、第二三四用 contains
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );
		$symbol   = YSSmartSearchDetector::currency_symbol();

		$items = [];
		foreach ( $rows as $r ) {
			$price      = (float) $r['price'];
			$sale_price = (float) $r['sale_price'];
			$items[]    = [
				'title' => (string) $r['title'],
				'url'   => YSSmartSearchDetector::product_url( (string) $r['slug'] ),
				'image' => ! empty( $cfg['show_image'] ) ? (string) $r['image_url'] : '',
				'price' => ! empty( $cfg['show_price'] ) ? $symbol . number_format( $sale_price > 0 && $sale_price < $price ? $sale_price : $price ) : '',
				'price_original' => ( ! empty( $cfg['show_price'] ) && $sale_price > 0 && $sale_price < $price ) ? $symbol . number_format( $price ) : '',
				'sku'   => ! empty( $cfg['show_sku'] ) ? (string) $r['sku'] : '',
			];
		}

		return [
			'type'  => 'products',
			'label' => __( '商品', 'ys-cart-smart-search' ),
			'total' => count( $items ) + ( $has_more ? 1 : 0 ), // 精確 total 需 COUNT；「+1」僅示意還有更多
			'items' => $items,
		];
	}

	/**
	 * @param array<string,mixed> $cfg
	 * @return array<string,mixed>
	 */
	private static function categories_group( string $q, array $cfg ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ys_ec_categories';
		$like  = '%' . $wpdb->esc_like( $q ) . '%';
		$limit = (int) $cfg['limit'];

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, slug FROM {$table} WHERE is_active = 1 AND name LIKE %s ORDER BY id ASC LIMIT {$limit}",
			$like
		), ARRAY_A ) ?: [];

		$items = [];
		foreach ( $rows as $r ) {
			$count = 0;
			if ( ! empty( $cfg['show_count'] ) ) {
				$pivot = $wpdb->prefix . 'ys_ec_product_categories';
				$count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$pivot} WHERE category_id = %d",
					(int) $r['id']
				) );
			}
			$items[] = [
				'title' => (string) $r['name'],
				'url'   => YSSmartSearchDetector::category_url( (string) $r['slug'] ),
				'count' => $count,
			];
		}

		return [
			'type'  => 'categories',
			'label' => __( '分類', 'ys-cart-smart-search' ),
			'total' => count( $items ),
			'items' => $items,
		];
	}

	/**
	 * @param array<string,mixed> $cfg
	 * @return array<string,mixed>
	 */
	private static function posts_group( string $q, array $cfg ): array {
		$query = new \WP_Query( [
			's'                      => $q,
			'post_type'              => (array) $cfg['post_types'],
			'post_status'            => 'publish',
			'posts_per_page'         => (int) $cfg['limit'],
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		] );

		$items = [];
		foreach ( $query->posts as $post ) {
			$excerpt = '';
			if ( (int) $cfg['excerpt_len'] > 0 ) {
				$excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
				$excerpt = mb_substr( $excerpt, 0, (int) $cfg['excerpt_len'] );
			}
			$items[] = [
				'title'   => (string) get_the_title( $post ),
				'url'     => (string) get_permalink( $post ),
				'image'   => ! empty( $cfg['show_thumb'] ) ? (string) get_the_post_thumbnail_url( $post, 'thumbnail' ) : '',
				'excerpt' => $excerpt,
			];
		}

		return [
			'type'  => 'posts',
			'label' => __( '文章／頁面', 'ys-cart-smart-search' ),
			'total' => count( $items ),
			'items' => $items,
		];
	}
}

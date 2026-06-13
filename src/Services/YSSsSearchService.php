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
	 * 蒐集商品排除清單：核心 ys_ec_search_excluded_slugs + ys_ec_search_excluded_ids
	 *（與核心搜尋一致）＋ 本外掛設定的自有排除（ID 或 slug 混填）。
	 *
	 * @param array<string,mixed> $cfg
	 * @return array{0:array<int,string>,1:array<int,int>} [slugs, ids]
	 */
	private static function collect_exclusions( array $cfg ): array {
		$slugs = [];
		$ids   = [];

		if ( class_exists( '\YangSheep\Ecommerce\Settings\YSSettingsRepository' ) ) {
			try {
				foreach ( self::split_tokens( (string) \YangSheep\Ecommerce\Settings\YSSettingsRepository::get( 'ys_ec_search_excluded_slugs', '' ) ) as $t ) {
					$slugs[] = sanitize_title( $t );
				}
				foreach ( self::split_tokens( (string) \YangSheep\Ecommerce\Settings\YSSettingsRepository::get( 'ys_ec_search_excluded_ids', '' ) ) as $t ) {
					if ( ctype_digit( $t ) ) {
						$ids[] = (int) $t;
					}
				}
			} catch ( \Throwable $e ) {
				// 核心不可用時略過核心排除。
			}
		}

		// 本外掛自有排除（B）：純數字→ID、其餘→slug。
		foreach ( self::split_tokens( (string) ( $cfg['exclude'] ?? '' ) ) as $t ) {
			if ( ctype_digit( $t ) ) {
				$ids[] = (int) $t;
			} else {
				$slugs[] = sanitize_title( $t );
			}
		}

		return [
			array_values( array_unique( array_filter( $slugs ) ) ),
			array_values( array_unique( array_filter( $ids ) ) ),
		];
	}

	/**
	 * 拆解以空白／逗號／換行分隔的字串為 token 陣列。
	 *
	 * @return array<int,string>
	 */
	private static function split_tokens( string $raw ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $raw ) ?: [] ), static fn( $t ) => '' !== $t ) );
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

		// B：搜尋欄位選擇（名稱/SKU/slug 可個別開關，預設全開、至少保名稱）。
		$fields = is_array( $cfg['fields'] ?? null ) ? array_values( array_intersect( (array) $cfg['fields'], [ 'name', 'sku', 'slug' ] ) ) : [ 'name', 'sku', 'slug' ];
		if ( ! $fields ) {
			$fields = [ 'name' ];
		}
		$search_name = in_array( 'name', $fields, true );

		$field_clauses = [];
		$field_args    = [];
		if ( $search_name )                       { $field_clauses[] = 'title LIKE %s'; $field_args[] = $like; }
		if ( in_array( 'sku', $fields, true ) )   { $field_clauses[] = 'sku LIKE %s';   $field_args[] = $like; }
		if ( in_array( 'slug', $fields, true ) )  { $field_clauses[] = 'slug LIKE %s';  $field_args[] = $like; }

		// A+B：排除＝核心 excluded_slugs + excluded_ids（與核心一致）＋本外掛自有排除清單。
		[ $excl_slugs, $excl_ids ] = self::collect_exclusions( $cfg );

		$where      = '( ' . implode( ' OR ', $field_clauses ) . ' )';
		$where_args = $field_args;
		if ( $excl_slugs ) {
			$where     .= ' AND slug NOT IN (' . implode( ',', array_fill( 0, count( $excl_slugs ), '%s' ) ) . ')';
			$where_args = array_merge( $where_args, $excl_slugs );
		}
		if ( $excl_ids ) {
			$where     .= ' AND id NOT IN (' . implode( ',', array_fill( 0, count( $excl_ids ), '%d' ) ) . ')';
			$where_args = array_merge( $where_args, array_map( 'intval', $excl_ids ) );
		}

		// 相關性：含「名稱」欄位時用標題前綴(0) > 標題含(1) > 其他(2)；否則 id DESC。
		$order      = 'id DESC';
		$order_args = [];
		if ( $search_name ) {
			$order      = 'CASE WHEN title LIKE %s THEN 0 WHEN title LIKE %s THEN 1 ELSE 2 END, id DESC';
			$order_args = [ $pref, $like ];
		}

		$sql  = "SELECT id, title, slug, sku, price, sale_price, image_url
				FROM {$table}
				WHERE status = 'publish' AND {$where}
				ORDER BY {$order}
				LIMIT " . ( $limit + 1 );
		$args = array_merge( $where_args, $order_args );
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

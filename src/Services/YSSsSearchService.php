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
use YangSheep\SmartSearch\Frontend\YSSsResultsPage;
use YangSheep\SmartSearch\YSSmartSearchDetector;

defined( 'ABSPATH' ) || exit;

final class YSSsSearchService {

	/**
	 * @return array<string,mixed> { q, total, groups: [ {type,label,total,items[]} ], content_types: string[] }
	 */
	public static function search( string $q ): array {
		$settings = YSSsSettings::all();
		$norm     = YSSsQueryRepository::normalize( $q );

		$groups         = [];
		$searched_types = [];

		if ( '' !== $norm ) {
			foreach ( $settings['group_order'] as $type ) {
				$group = null;
				if ( 'products' === $type ) {
					$searched_types[] = 'products';
					$group = self::products_group( $norm, $settings['products'] );
				} elseif ( 'categories' === $type && ! empty( $settings['categories']['enabled'] ) ) {
					$searched_types[] = 'categories';
					$group = self::categories_group( $norm, $settings['categories'] );
				} elseif ( 'posts' === $type && ! empty( $settings['posts']['enabled'] ) ) {
					$searched_types[] = 'posts';
					$group = self::posts_group( $norm, $settings['posts'] );
				}
				if ( $group && $group['items'] ) {
					$groups[] = $group;
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
		$total  = 0;
		foreach ( $groups as $group ) {
			if ( is_array( $group ) && ! empty( $group['items'] ) ) {
				$total += max( 0, (int) ( $group['total'] ?? 0 ) );
			}
		}

		return [
			'q'             => $norm,
			'total'         => $total,
			'groups'        => $groups,
			'content_types' => array_values( array_unique( $searched_types ) ),
			'view_all'      => YSSsResultsPage::search_url( $norm ),
			'log_receipt'   => '',
		];
	}

	/**
	 * 空結果（防注入時回傳，形狀與 search() 一致，不執行任何 DB 查詢）。
	 *
	 * @return array<string,mixed>
	 */
	public static function empty_result(): array {
		return [
			'q'           => '',
			'total'       => 0,
			'groups'      => [],
			'view_all'    => '',
			'log_receipt' => '',
		];
	}

	/**
	 * 結果頁（B 模式）：商品分頁 + 分類/文章（僅第一頁顯示，因通常筆數少）。
	 *
	 * @return array<string,mixed> { q, products_total, page, per_page, total_pages, groups[], content_types[] }
	 */
	public static function search_page( string $q, int $page = 1 ): array {
		$settings = YSSsSettings::all();
		$norm     = YSSsQueryRepository::normalize( $q );
		$page     = min( max( 1, $page ), 100 ); // 上限防護：避免病態深分頁產生巨大 OFFSET。
		$per_page = max( 6, (int) ( $settings['products']['page_limit'] ?? 24 ) );

		// 短 TTL 快取：避免大型商品表每次結果頁／翻頁都重跑 LIKE 全表掃描 + COUNT
		//（中文熱門詞命中率高）。鍵含 norm／頁碼／影響結果的設定；60 秒內視為新鮮。
		// 分析記錄（log_page）在 YSSsResultsPage 另行呼叫、不受此快取影響。
		$cache_key = 'ys_ss_sp_' . md5( $norm . '|' . $page . '|' . (string) wp_json_encode( [
			$settings['group_order'], $settings['products'], $settings['categories'], $settings['posts'],
		] ) );
		if ( '' !== $norm ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$groups         = [];
		$products_total = 0;
		$content_types  = [];

		if ( '' !== $norm ) {
			foreach ( $settings['group_order'] as $type ) {
				$group = null;

				if ( 'products' === $type ) {
					$content_types[] = 'products';
					$paged           = self::products_group_paged( $norm, $settings['products'], $per_page, ( $page - 1 ) * $per_page );
					$products_total  = (int) $paged['total_count'];
					$group           = $paged['group'];
				} elseif ( 'categories' === $type && ! empty( $settings['categories']['enabled'] ) ) {
					$content_types[] = 'categories';
					if ( 1 === $page ) {
						$cfg          = $settings['categories'];
						$cfg['limit'] = min( 24, max( (int) $cfg['limit'], 12 ) ); // 結果頁顯示更多
						$group        = self::categories_group( $norm, $cfg );
					}
				} elseif ( 'posts' === $type && ! empty( $settings['posts']['enabled'] ) ) {
					$content_types[] = 'posts';
					if ( 1 === $page ) {
						$cfg          = $settings['posts'];
						$cfg['limit'] = min( 24, max( (int) $cfg['limit'], 12 ) );
						$group        = self::posts_group( $norm, $cfg );
					}
				}

				if ( $group && $group['items'] ) {
					$groups[] = $group;
				}
			}
		}

		$result = [
			'q'              => $norm,
			'products_total' => $products_total,
			'page'           => $page,
			'per_page'       => $per_page,
			'total_pages'    => max( 1, (int) ceil( $products_total / $per_page ) ),
			'groups'         => $groups,
			'content_types'  => $content_types,
		];

		if ( '' !== $norm ) {
			set_transient( $cache_key, $result, MINUTE_IN_SECONDS );
		}
		return $result;
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
	 * 組裝商品查詢的 WHERE / ORDER（dropdown 與結果頁共用）。
	 *
	 * 比對策略：title/sku/slug 以 LIKE '%q%' 子字串比對（與核心 YSCatalogService 一致）。
	 * 刻意「不」用 FULLTEXT —— 面向中文(CJK)商品：MySQL FULLTEXT 預設 parser 不斷中文詞，
	 * 需 InnoDB ngram parser，且單字查詢會落在 ngram_token_size 之下而失配；核心 ys_ec_products
	 * 表本身亦無 FULLTEXT 索引。若日後要做索引級全文檢索，應在「核心」以 ngram FULLTEXT 統一改造。
	 *
	 * @param array<string,mixed> $cfg
	 * @return array{0:string,1:array<int,mixed>,2:string,3:array<int,mixed>,4:bool} [where, where_args, order, order_args, search_name]
	 */
	private static function build_products_where( string $q, array $cfg ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$pref = $wpdb->esc_like( $q ) . '%';

		// B：搜尋欄位選擇（名稱/SKU/slug 可個別開關，預設全開、至少保名稱）。
		$fields = is_array( $cfg['fields'] ?? null ) ? array_values( array_intersect( (array) $cfg['fields'], [ 'name', 'sku', 'slug' ] ) ) : [ 'name', 'sku', 'slug' ];
		if ( ! $fields ) {
			$fields = [ 'name' ];
		}
		$search_name = in_array( 'name', $fields, true );

		$field_clauses = [];
		$field_args    = [];
		if ( $search_name )                      { $field_clauses[] = 'title LIKE %s'; $field_args[] = $like; }
		if ( in_array( 'sku', $fields, true ) )  { $field_clauses[] = 'sku LIKE %s';   $field_args[] = $like; }
		if ( in_array( 'slug', $fields, true ) ) { $field_clauses[] = 'slug LIKE %s';  $field_args[] = $like; }

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

		return [ $where, $where_args, $order, $order_args, $search_name ];
	}

	/**
	 * 商品列 → 前台 item 形狀（dropdown 與結果頁共用）。
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $cfg
	 * @return array<int,array<string,mixed>>
	 */
	private static function map_product_rows( array $rows, array $cfg ): array {
		$symbol = YSSmartSearchDetector::currency_symbol();
		$items  = [];
		foreach ( $rows as $r ) {
			$price      = (float) $r['price'];
			$sale_price = (float) $r['sale_price'];
			$items[]    = [
				'title'          => (string) $r['title'],
				'url'            => YSSmartSearchDetector::product_url( (string) $r['slug'] ),
				'image'          => ! empty( $cfg['show_image'] ) ? (string) $r['image_url'] : '',
				'price'          => ! empty( $cfg['show_price'] ) ? $symbol . number_format( $sale_price > 0 && $sale_price < $price ? $sale_price : $price ) : '',
				'price_original' => ( ! empty( $cfg['show_price'] ) && $sale_price > 0 && $sale_price < $price ) ? $symbol . number_format( $price ) : '',
				'sku'            => ! empty( $cfg['show_sku'] ) ? (string) $r['sku'] : '',
			];
		}
		return $items;
	}

	/**
	 * @param array<string,mixed> $cfg
	 * @return array<string,mixed>
	 */
	private static function products_group( string $q, array $cfg ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ys_ec_products';
		$limit = (int) $cfg['limit'];
		[ $where, $where_args, $order, $order_args ] = self::build_products_where( $q, $cfg );

		$sql  = "SELECT id, title, slug, sku, price, sale_price, image_url
				FROM {$table}
				WHERE status = 'publish' AND {$where}
				ORDER BY {$order}
				LIMIT " . ( $limit + 1 );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where_args, $order_args ) ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );

		return [
			'type'  => 'products',
			'label' => __( '商品', 'ys-cart-smart-search' ),
			'total' => count( $rows ) + ( $has_more ? 1 : 0 ), // 精確 total 需 COUNT；「+1」僅示意還有更多
			'items' => self::map_product_rows( $rows, $cfg ),
		];
	}

	/**
	 * 結果頁（B 模式）：商品分頁查詢，回傳精確總數（COUNT）＋當頁項目。
	 *
	 * @param array<string,mixed> $cfg
	 * @return array{group:array<string,mixed>,total_count:int}
	 */
	private static function products_group_paged( string $q, array $cfg, int $per_page, int $offset ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ys_ec_products';
		[ $where, $where_args, $order, $order_args ] = self::build_products_where( $q, $cfg );

		$total_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'publish' AND {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_args
		) );

		$sql  = "SELECT id, title, slug, sku, price, sale_price, image_url
				FROM {$table}
				WHERE status = 'publish' AND {$where}
				ORDER BY {$order}
				LIMIT %d OFFSET %d";
		$args = array_merge( $where_args, $order_args, [ max( 1, $per_page ), max( 0, $offset ) ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return [
			'group'       => [
				'type'  => 'products',
				'label' => __( '商品', 'ys-cart-smart-search' ),
				'total' => $total_count,
				'items' => self::map_product_rows( $rows, $cfg ),
			],
			'total_count' => $total_count,
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

		// 商品數（可選）：以單一 GROUP BY 批次取得，取代每列一次 COUNT 的 N+1 查詢。
		// 沒有對應商品的分類不會出現在結果中，於下方以 `?? 0` 補回（與舊行為一致）。
		$counts = [];
		if ( ! empty( $cfg['show_count'] ) && $rows ) {
			$ids          = array_map( static fn( $r ) => (int) $r['id'], $rows );
			$pivot        = $wpdb->prefix . 'ys_ec_product_categories';
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$count_rows   = $wpdb->get_results( $wpdb->prepare(
				"SELECT category_id, COUNT(*) AS c FROM {$pivot} WHERE category_id IN ({$placeholders}) GROUP BY category_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			), ARRAY_A ) ?: [];
			foreach ( $count_rows as $cr ) {
				$counts[ (int) $cr['category_id'] ] = (int) $cr['c'];
			}
		}

		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'title' => (string) $r['name'],
				'url'   => YSSmartSearchDetector::category_url( (string) $r['slug'] ),
				'count' => $counts[ (int) $r['id'] ] ?? 0,
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

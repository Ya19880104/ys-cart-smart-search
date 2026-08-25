<?php
/**
 * 手動關鍵字 repository（混合式建議的「手動」半邊）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Database;

use YangSheep\SmartSearch\Support\YSSsText;

defined( 'ABSPATH' ) || exit;

final class YSSsKeywordRepository {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		global $wpdb;
		$table = YSSsSchema::keywords_table();
		$rows  = $wpdb->get_results( "SELECT id, keyword, sort_order, is_active FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( static fn( $r ) => [
			'id'         => (int) $r['id'],
			'keyword'    => (string) $r['keyword'],
			'sort_order' => (int) $r['sort_order'],
			'is_active'  => (bool) $r['is_active'],
		], $rows ?: [] );
	}

	/**
	 * @return array<int,string> 啟用中的關鍵字（依排序）。
	 */
	public static function active_keywords(): array {
		global $wpdb;
		$table = YSSsSchema::keywords_table();
		$rows  = $wpdb->get_col( "SELECT keyword FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'strval', $rows ?: [] );
	}

	public static function create( string $keyword, int $sort_order = 0 ): int {
		global $wpdb;
		$keyword = YSSsText::truncate_chars( trim( $keyword ), 100 );
		if ( '' === $keyword ) {
			return 0;
		}
		$wpdb->insert( YSSsSchema::keywords_table(), [
			'keyword'    => $keyword,
			'sort_order' => $sort_order,
			'is_active'  => 1,
			'created_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $patch keyword / sort_order / is_active
	 */
	public static function update( int $id, array $patch ): bool {
		global $wpdb;
		$data = [];
		if ( array_key_exists( 'keyword', $patch ) ) {
			$kw = YSSsText::truncate_chars( trim( (string) $patch['keyword'] ), 100 );
			if ( '' === $kw ) {
				return false;
			}
			$data['keyword'] = $kw;
		}
		if ( array_key_exists( 'sort_order', $patch ) ) {
			$data['sort_order'] = (int) $patch['sort_order'];
		}
		if ( array_key_exists( 'is_active', $patch ) ) {
			$data['is_active'] = ! empty( $patch['is_active'] ) ? 1 : 0;
		}
		if ( ! $data ) {
			return false;
		}
		return false !== $wpdb->update( YSSsSchema::keywords_table(), $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( YSSsSchema::keywords_table(), [ 'id' => $id ] );
	}
}

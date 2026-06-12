<?php
/**
 * 管理 REST（核心 namespace；capability + wp_rest nonce 雙閘）。
 *
 *   GET    /admin/smart-search/overview          報表總覽（from/to）
 *   GET    /admin/smart-search/export            CSV 匯出（含公式注入防護）
 *   GET    /admin/smart-search/keywords          手動關鍵字列表
 *   POST   /admin/smart-search/keywords          新增
 *   POST   /admin/smart-search/keywords/{id}     更新（keyword/sort_order/is_active）
 *   DELETE /admin/smart-search/keywords/{id}     刪除
 *   GET    /admin/smart-search/settings          讀設定（含資料量）
 *   POST   /admin/smart-search/settings          存設定
 *   POST   /admin/smart-search/purge             清理（mode=expired|all，all 需確認碼）
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Api;

use YangSheep\SmartSearch\Database\YSSsKeywordRepository;
use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Services\YSSsSuggestService;

defined( 'ABSPATH' ) || exit;

final class YSSsAdminController {

	private const NS = 'ys-ecommerce-headless/v1';

	public function register_routes(): void {
		$base = '/admin/smart-search';

		register_rest_route( self::NS, $base . '/overview', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'overview' ],
			'permission_callback' => [ $this, 'permission_admin' ],
		] );

		register_rest_route( self::NS, $base . '/export', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'export_csv' ],
			'permission_callback' => [ $this, 'permission_admin' ],
		] );

		register_rest_route( self::NS, $base . '/keywords', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'keywords_list' ],
				'permission_callback' => [ $this, 'permission_admin' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'keywords_create' ],
				'permission_callback' => [ $this, 'permission_admin' ],
			],
		] );

		register_rest_route( self::NS, $base . '/keywords/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'keywords_update' ],
				'permission_callback' => [ $this, 'permission_admin' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'keywords_delete' ],
				'permission_callback' => [ $this, 'permission_admin' ],
			],
		] );

		register_rest_route( self::NS, $base . '/settings', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'settings_get' ],
				'permission_callback' => [ $this, 'permission_admin' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'settings_save' ],
				'permission_callback' => [ $this, 'permission_admin' ],
			],
		] );

		register_rest_route( self::NS, $base . '/purge', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'purge' ],
			'permission_callback' => [ $this, 'permission_admin' ],
		] );
	}

	/**
	 * 比照核心：登入 + 管理權限 + 有效 wp_rest nonce（header 或 _wpnonce query）。
	 */
	public function permission_admin( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'ys_ss_forbidden', 'forbidden', [ 'status' => 401 ] );
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_ys_ecommerce' ) ) {
			return new \WP_Error( 'ys_ss_forbidden', 'forbidden', [ 'status' => 403 ] );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'ys_ss_bad_nonce', 'invalid nonce', [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * @return array{0:string,1:string} [from, to]（Y-m-d，含端點，已 clamp）
	 */
	private function range_from_request( \WP_REST_Request $request ): array {
		$today = substr( current_time( 'mysql' ), 0, 10 );
		$from  = (string) $request->get_param( 'from' );
		$to    = (string) $request->get_param( 'to' );

		$valid = static fn( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && false !== strtotime( $d );

		if ( ! $valid( $to ) || $to > $today ) {
			$to = $today;
		}
		if ( ! $valid( $from ) || $from > $to ) {
			$from = gmdate( 'Y-m-d', strtotime( $to . ' -29 days' ) );
		}
		// 範圍上限 366 天
		if ( strtotime( $to ) - strtotime( $from ) > 366 * DAY_IN_SECONDS ) {
			$from = gmdate( 'Y-m-d', strtotime( $to . ' -366 days' ) );
		}

		return [ $from, $to ];
	}

	public function overview( \WP_REST_Request $request ) {
		[ $from, $to ] = $this->range_from_request( $request );
		$limit         = max( 1, min( 200, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		return rest_ensure_response( YSSsQueryRepository::overview( $from, $to, $limit ) );
	}

	/**
	 * CSV 串流（公式注入防護：=,+,-,@ 開頭前置 '）。
	 */
	public function export_csv( \WP_REST_Request $request ) {
		[ $from, $to ] = $this->range_from_request( $request );
		$data          = YSSsQueryRepository::overview( $from, $to, 200 );

		$guard = static function ( string $v ): string {
			return ( '' !== $v && in_array( $v[0], [ '=', '+', '-', '@' ], true ) ) ? "'" . $v : $v;
		};

		$lines   = [];
		$lines[] = "\xEF\xBB\xBF" . '"關鍵字","搜尋次數","零結果次數"'; // BOM for Excel
		foreach ( $data['top'] as $row ) {
			$lines[] = sprintf(
				'"%s","%d","%d"',
				str_replace( '"', '""', $guard( $row['term'] ) ),
				$row['hits'],
				$row['zero_hits']
			);
		}
		$csv = implode( "\r\n", $lines ) . "\r\n";

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ys-smart-search-' . $from . '_' . $to . '.csv"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV 串流，欄位已防護。
		exit;
	}

	public function keywords_list() {
		return rest_ensure_response( [ 'items' => YSSsKeywordRepository::all() ] );
	}

	public function keywords_create( \WP_REST_Request $request ) {
		$keyword = sanitize_text_field( wp_unslash( (string) $request->get_param( 'keyword' ) ) );
		$sort    = (int) $request->get_param( 'sort_order' );
		$id      = YSSsKeywordRepository::create( $keyword, $sort );
		if ( $id <= 0 ) {
			return new \WP_Error( 'ys_ss_invalid_keyword', __( '關鍵字不可為空。', 'ys-cart-smart-search' ), [ 'status' => 400 ] );
		}
		YSSsSuggestService::invalidate();
		return rest_ensure_response( [ 'id' => $id, 'items' => YSSsKeywordRepository::all() ] );
	}

	public function keywords_update( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$patch = [];
		if ( null !== $request->get_param( 'keyword' ) ) {
			$patch['keyword'] = sanitize_text_field( wp_unslash( (string) $request->get_param( 'keyword' ) ) );
		}
		if ( null !== $request->get_param( 'sort_order' ) ) {
			$patch['sort_order'] = (int) $request->get_param( 'sort_order' );
		}
		if ( null !== $request->get_param( 'is_active' ) ) {
			$patch['is_active'] = rest_sanitize_boolean( $request->get_param( 'is_active' ) );
		}
		YSSsKeywordRepository::update( $id, $patch );
		YSSsSuggestService::invalidate();
		return rest_ensure_response( [ 'items' => YSSsKeywordRepository::all() ] );
	}

	public function keywords_delete( \WP_REST_Request $request ) {
		YSSsKeywordRepository::delete( (int) $request['id'] );
		YSSsSuggestService::invalidate();
		return rest_ensure_response( [ 'items' => YSSsKeywordRepository::all() ] );
	}

	public function settings_get() {
		return rest_ensure_response( [
			'settings' => YSSsSettings::all(),
			'counts'   => YSSsQueryRepository::counts(),
		] );
	}

	public function settings_save( \WP_REST_Request $request ) {
		$patch = $request->get_json_params();
		if ( ! is_array( $patch ) ) {
			return new \WP_Error( 'ys_ss_bad_payload', 'invalid payload', [ 'status' => 400 ] );
		}
		$settings = YSSsSettings::update( $patch );
		YSSsSuggestService::invalidate();
		return rest_ensure_response( [ 'settings' => $settings ] );
	}

	public function purge( \WP_REST_Request $request ) {
		$mode = (string) $request->get_param( 'mode' );

		if ( 'all' === $mode ) {
			if ( 'DELETE' !== strtoupper( (string) $request->get_param( 'confirm' ) ) ) {
				return new \WP_Error( 'ys_ss_confirm_required', __( '請輸入確認碼 DELETE。', 'ys-cart-smart-search' ), [ 'status' => 400 ] );
			}
			YSSsQueryRepository::purge_all();
			YSSsSuggestService::invalidate();
			return rest_ensure_response( [ 'ok' => true, 'counts' => YSSsQueryRepository::counts() ] );
		}

		$settings = YSSsSettings::all();
		$deleted  = YSSsQueryRepository::purge_older_than( (int) $settings['retention_days'] );
		return rest_ensure_response( [ 'ok' => true, 'deleted' => $deleted, 'counts' => YSSsQueryRepository::counts() ] );
	}
}

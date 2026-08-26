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
use YangSheep\SmartSearch\Database\YSSsAnalyticsMutationException;
use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Frontend\YSSsResultsPage;
use YangSheep\SmartSearch\Security\YSSsSearchInput;
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

		register_rest_route( self::NS, $base . '/term', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'delete_term' ],
			'permission_callback' => [ $this, 'permission_admin' ],
			'args'                => [
				'term' => [ 'type' => 'string', 'required' => true ],
			],
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
		$keyword = $this->parse_keyword( $request->get_param( 'keyword' ) );
		if ( $keyword instanceof \WP_Error ) {
			return $keyword;
		}
		$sort    = (int) $request->get_param( 'sort_order' );
		$id      = YSSsKeywordRepository::create( $keyword, $sort );
		if ( $id <= 0 ) {
			return $this->keyword_write_error();
		}
		return $this->mutation_response( [ 'id' => $id, 'items' => YSSsKeywordRepository::all() ] );
	}

	public function keywords_update( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$patch = [];
		if ( $request->has_param( 'keyword' ) ) {
			$keyword = $this->parse_keyword( $request->get_param( 'keyword' ) );
			if ( $keyword instanceof \WP_Error ) {
				return $keyword;
			}
			$patch['keyword'] = $keyword;
		}
		if ( $request->has_param( 'sort_order' ) ) {
			$patch['sort_order'] = (int) $request->get_param( 'sort_order' );
		}
		if ( $request->has_param( 'is_active' ) ) {
			$patch['is_active'] = rest_sanitize_boolean( $request->get_param( 'is_active' ) );
		}
		if ( ! $patch ) {
			return $this->invalid_keyword_error();
		}
		if ( ! YSSsKeywordRepository::update( $id, $patch ) ) {
			return $this->keyword_write_error();
		}
		return $this->mutation_response( [ 'items' => YSSsKeywordRepository::all() ] );
	}

	public function keywords_delete( \WP_REST_Request $request ) {
		if ( ! YSSsKeywordRepository::delete( (int) $request['id'] ) ) {
			return $this->keyword_write_error();
		}
		return $this->mutation_response( [ 'items' => YSSsKeywordRepository::all() ] );
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
		$expected = YSSsSettings::update( $patch );
		$settings = YSSsSettings::all();
		if ( 'page' === ( $settings['results_mode'] ?? '' )
			&& ! YSSsResultsPage::valid_page_id( (int) ( $settings['results_page_id'] ?? 0 ) ) ) {
			YSSsResultsPage::ensure_page();
			$settings = YSSsSettings::all();
		}

		// update_option hooks run synchronously. Switching to page mode may therefore provision
		// the results page through a nested settings write before update() returns. Accept only
		// that one contract-completing difference; every other final-storage drift is a failure.
		$settled_expected = $expected;
		if ( 'page' === ( $expected['results_mode'] ?? '' )
			&& ! YSSsResultsPage::valid_page_id( (int) ( $expected['results_page_id'] ?? 0 ) )
			&& 'page' === ( $settings['results_mode'] ?? '' )
			&& YSSsResultsPage::valid_page_id( (int) ( $settings['results_page_id'] ?? 0 ) ) ) {
			$settled_expected['results_page_id'] = (int) $settings['results_page_id'];
		}

		$page_contract_complete = 'page' !== ( $settings['results_mode'] ?? '' )
			|| YSSsResultsPage::valid_page_id( (int) ( $settings['results_page_id'] ?? 0 ) );
		if ( ! $page_contract_complete || $settings !== $settled_expected ) {
			return new \WP_Error(
				'ys_ss_settings_write_failed',
				__( '設定儲存失敗，請稍後再試。', 'ys-cart-smart-search' ),
				[ 'status' => 500 ]
			);
		}
		return $this->mutation_response( [ 'settings' => $settings ] );
	}

	public function purge( \WP_REST_Request $request ) {
		$mode = $request->get_param( 'mode' );
		if ( ! is_string( $mode ) || ! in_array( $mode, [ 'all', 'expired', 'injection' ], true ) ) {
			return new \WP_Error( 'ys_ss_bad_purge_mode', __( '無效的清理模式。', 'ys-cart-smart-search' ), [ 'status' => 400 ] );
		}

		// 歷史 heuristic bulk-delete 已退役；若未來要恢復，必須另做 preview/confirm 工作流。
		if ( 'injection' === $mode ) {
			return new \WP_Error( 'ys_ss_preview_required', __( '自動掃描刪除已停用；請改用逐詞精確刪除。', 'ys-cart-smart-search' ), [ 'status' => 409 ] );
		}

		try {
			if ( 'all' === $mode ) {
				$confirm = $request->get_param( 'confirm' );
				if ( ! is_string( $confirm ) || 'DELETE' !== strtoupper( $confirm ) ) {
					return new \WP_Error( 'ys_ss_confirm_required', __( '請輸入確認碼 DELETE。', 'ys-cart-smart-search' ), [ 'status' => 400 ] );
				}
				YSSsQueryRepository::purge_all();
				return $this->mutation_response( [ 'ok' => true, 'counts' => YSSsQueryRepository::counts() ] );
			}

			$settings = YSSsSettings::all();
			$deleted  = YSSsQueryRepository::purge_older_than( (int) $settings['retention_days'] );
			return $this->mutation_response( [ 'ok' => true, 'deleted' => $deleted, 'counts' => YSSsQueryRepository::counts() ] );
		} catch ( YSSsAnalyticsMutationException $error ) {
			return $this->mutation_error( $error );
		}
	}

	/**
	 * 單筆刪除：移除某關鍵字在原始表與彙總表的全部紀錄（分析以「詞」為單位）。
	 */
	public function delete_term( \WP_REST_Request $request ) {
		$term = $request->get_param( 'term' );
		if ( ! is_string( $term ) ) {
			return new \WP_Error( 'ys_ss_empty_term', __( '請指定要刪除的關鍵字。', 'ys-cart-smart-search' ), [ 'status' => 400 ] );
		}
		if ( '' === trim( $term ) ) {
			return new \WP_Error( 'ys_ss_empty_term', __( '請指定要刪除的關鍵字。', 'ys-cart-smart-search' ), [ 'status' => 400 ] );
		}

		try {
			$deleted = YSSsQueryRepository::delete_term( $term );
			return $this->mutation_response( [ 'ok' => true, 'deleted' => $deleted, 'counts' => YSSsQueryRepository::counts() ] );
		} catch ( YSSsAnalyticsMutationException $error ) {
			return $this->mutation_error( $error );
		}
	}

	/**
	 * @return string|\WP_Error
	 */
	private function parse_keyword( mixed $value ) {
		if ( ! is_string( $value ) ) {
			return $this->invalid_keyword_error();
		}
		// REST JSON values are already decoded, unslashed bytes. A second wp_unslash() corrupts
		// legitimate technical terms such as Windows paths.
		$decision = YSSsSearchInput::inspect( $value );
		if ( $decision['blocked'] || '' === $decision['query'] ) {
			return $this->invalid_keyword_error();
		}
		return $decision['query'];
	}

	private function invalid_keyword_error(): \WP_Error {
		return new \WP_Error(
			'ys_ss_invalid_keyword',
			__( '關鍵字不可為空。', 'ys-cart-smart-search' ),
			[ 'status' => 400 ]
		);
	}

	private function keyword_write_error(): \WP_Error {
		return new \WP_Error(
			'ys_ss_keyword_write_failed',
			__( '關鍵字資料更新失敗，請稍後再試。', 'ys-cart-smart-search' ),
			[ 'status' => 500 ]
		);
	}

	private function mutation_response( array $payload ): \WP_REST_Response {
		$status = YSSsSuggestService::INVALIDATION_FAILED;
		try {
			$candidate = YSSsSuggestService::invalidate();
			if ( in_array( $candidate, [
				YSSsSuggestService::INVALIDATION_ROTATED,
				YSSsSuggestService::INVALIDATION_BYPASS_FRESH,
				YSSsSuggestService::INVALIDATION_FAILED,
			], true ) ) {
				$status = $candidate;
			}
		} catch ( \Throwable $error ) {
			$status = YSSsSuggestService::INVALIDATION_FAILED;
		}

		$payload['cache_status'] = $status;
		if ( YSSsSuggestService::INVALIDATION_FAILED === $status ) {
			$payload['cache_warning'] = __( '資料已更新，但熱門建議快取可能延遲更新。', 'ys-cart-smart-search' );
		}
		return rest_ensure_response( $payload );
	}

	private function mutation_error( YSSsAnalyticsMutationException $error ): \WP_Error {
		if ( YSSsAnalyticsMutationException::REASON_BUSY === $error->reason() ) {
			return new \WP_Error(
				'ys_ss_analytics_busy',
				__( '搜尋分析正在更新，請稍後再試。', 'ys-cart-smart-search' ),
				[ 'status' => 409 ]
			);
		}

		return new \WP_Error(
			'ys_ss_analytics_mutation_failed',
			__( '搜尋分析資料操作失敗，請稍後再試。', 'ys-cart-smart-search' ),
			[ 'status' => 500 ]
		);
	}
}

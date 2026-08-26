<?php
/**
 * 公開 REST（核心 namespace `ys-ecommerce-headless/v1` 下，5 原則③）。
 *
 *   GET  /smart-search/query    搜尋（分組結果）
 *   GET  /smart-search/suggest  熱門搜尋建議（混合式）
 *   POST /smart-search/log      行為紀錄（唯一公開寫入面：限流 + 嚴格 sanitize）
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Api;

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Security\YSSsLogReceipt;
use YangSheep\SmartSearch\Security\YSSsRateLimiter;
use YangSheep\SmartSearch\Security\YSSsSearchInput;
use YangSheep\SmartSearch\Services\YSSsSearchService;
use YangSheep\SmartSearch\Services\YSSsSuggestService;

defined( 'ABSPATH' ) || exit;

final class YSSsPublicController {

	private const NS = 'ys-ecommerce-headless/v1';

	public function register_routes(): void {
		register_rest_route( self::NS, '/smart-search/query', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'query' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'q' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NS, '/smart-search/suggest', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'suggest' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NS, '/smart-search/log', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'log' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function query( \WP_REST_Request $request ) {
		if ( ! YSSsRateLimiter::allow( 'query', 60 ) ) {
			return new \WP_Error( 'ys_ss_rate_limited', __( '請求過於頻繁，請稍後再試。', 'ys-cart-smart-search' ), [ 'status' => 429 ] );
		}

		$input = YSSsSearchInput::inspect( $request->get_param( 'q' ) );
		if ( $input['blocked'] ) {
			$response = rest_ensure_response( YSSsSearchService::empty_result() );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		$q = $input['query'];
		if ( '' === trim( $q ) ) {
			return new \WP_Error( 'ys_ss_empty_query', __( '請輸入搜尋字詞。', 'ys-cart-smart-search' ), [ 'status' => 400 ] );
		}

		$result = YSSsSearchService::search( $q );
		$types  = array_values( array_filter(
			(array) ( $result['content_types'] ?? [] ),
			static fn( $type ): bool => is_string( $type ) && '' !== $type
		) );
		$now     = time();
		$visitor = YSSsRateLimiter::visitor_hash_at( $now );
		$result['total']          = max( 0, min( YSSsLogReceipt::MAX_TOTAL, (int) ( $result['total'] ?? 0 ) ) );
		$result['products_total'] = max( 0, min( YSSsLogReceipt::MAX_TOTAL, (int) ( $result['products_total'] ?? 0 ) ) );
		$result['log_receipt'] = YSSsLogReceipt::issue(
			(string) ( $result['q'] ?? '' ),
			(int) ( $result['products_total'] ?? 0 ),
			implode( ',', array_values( array_unique( $types ) ) ),
			$visitor,
			$now
		);

		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function suggest( \WP_REST_Request $request ) {
		if ( ! YSSsRateLimiter::allow( 'suggest', 60 ) ) {
			return new \WP_Error( 'ys_ss_rate_limited', __( '請求過於頻繁，請稍後再試。', 'ys-cart-smart-search' ), [ 'status' => 429 ] );
		}

		return rest_ensure_response( YSSsSuggestService::suggestions() );
	}

	public function log( \WP_REST_Request $request ) {
		if ( ! YSSsRateLimiter::allow( 'log', 30 ) ) {
			return new \WP_Error( 'ys_ss_rate_limited', __( '請求過於頻繁。', 'ys-cart-smart-search' ), [ 'status' => 429 ] );
		}

		$input = YSSsSearchInput::inspect( $request->get_param( 'q' ) );
		if ( $input['blocked'] ) {
			return rest_ensure_response( [ 'ok' => true ] );
		}

		$q = $input['query'];
		if ( '' === trim( $q ) ) {
			return rest_ensure_response( [ 'ok' => true ] );
		}

		$source_param  = $request->get_param( 'source' );
		$receipt_param = $request->get_param( 'receipt' );
		if ( ( null !== $source_param && ! is_string( $source_param ) ) || ! is_string( $receipt_param ) ) {
			return rest_ensure_response( [ 'ok' => true ] );
		}

		$source = 'popup' === $source_param ? 'popup' : 'bar';
		$claims = YSSsLogReceipt::verify_for_request( $receipt_param, $q );
		if ( null === $claims ) {
			return rest_ensure_response( [ 'ok' => true ] );
		}

		try {
			YSSsQueryRepository::log( $claims['query'], $claims['total'], $claims['content_types'], $source, $claims['visitor_hash'] );
		} catch ( \Throwable $e ) {
			// 分析旁路：任何寫入失敗都不回錯誤給前台。
		}

		return rest_ensure_response( [ 'ok' => true ] );
	}
}

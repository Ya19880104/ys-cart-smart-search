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
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Security\YSSsInjectionGuard;
use YangSheep\SmartSearch\Security\YSSsRateLimiter;
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
				'q' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
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

		$q = (string) $request->get_param( 'q' );
		$q = sanitize_text_field( wp_unslash( $q ) );
		if ( '' === trim( $q ) ) {
			return new \WP_Error( 'ys_ss_empty_query', __( '請輸入搜尋字詞。', 'ys-cart-smart-search' ), [ 'status' => 400 ] );
		}
		if ( function_exists( 'mb_substr' ) ) {
			$q = mb_substr( $q, 0, 100, 'UTF-8' );
		} else {
			$q = substr( $q, 0, 100 );
		}

		// 防注入：辨識為攻擊探測（SSTI/XSS/穿越/SQLi/SSRF）即拒絕執行搜尋，回空結果
		// （不報錯、不洩漏偵測，避免給攻擊者 oracle），且不記錄。
		if ( YSSsInjectionGuard::is_attack( $q ) ) {
			$response = rest_ensure_response( YSSsSearchService::empty_result( $q ) );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		$result = YSSsSearchService::search( $q );

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

		$q = sanitize_text_field( wp_unslash( (string) $request->get_param( 'q' ) ) );
		if ( '' === trim( $q ) ) {
			return new \WP_Error( 'ys_ss_empty_query', 'empty', [ 'status' => 400 ] );
		}

		$total  = max( 0, min( 1000000, (int) $request->get_param( 'total' ) ) );
		$source = 'popup' === (string) $request->get_param( 'source' ) ? 'popup' : 'bar';

		$settings = YSSsSettings::all();
		$types    = [ 'products' ];
		if ( ! empty( $settings['categories']['enabled'] ) ) {
			$types[] = 'categories';
		}
		if ( ! empty( $settings['posts']['enabled'] ) ) {
			$types[] = 'posts';
		}

		try {
			YSSsQueryRepository::log( $q, $total, implode( ',', $types ), $source, YSSsRateLimiter::visitor_hash() );
		} catch ( \Throwable $e ) {
			// 分析旁路：任何寫入失敗都不回錯誤給前台。
		}

		return rest_ensure_response( [ 'ok' => true ] );
	}
}

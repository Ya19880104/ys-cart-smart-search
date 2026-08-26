<?php
/**
 * Cron 橋接：掛核心 `ys_ec_cron_daily`（headless 站也由系統 crontab 驅動，
 * 不依賴前後台訪問——核心 license 驗證同模式）。
 *
 * 每日：① 前 3 日彙總 rollup（冪等補跑） ② 保留期清除 ③ 建議快取重算。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Cron;

use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Security\YSSsRateLimiter;
use YangSheep\SmartSearch\Services\YSSsSuggestService;

defined( 'ABSPATH' ) || exit;

final class YSSsCronBridge {

	public static function register(): void {
		add_action( 'ys_ec_cron_daily', [ self::class, 'run_daily' ] );
	}

	public static function run_daily(): void {
		try {
			$today = substr( current_time( 'mysql' ), 0, 10 );
			for ( $i = 1; $i <= 3; $i++ ) {
				YSSsQueryRepository::rollup_date( gmdate( 'Y-m-d', strtotime( $today . " -{$i} days" ) ) );
			}

			$settings = YSSsSettings::all();
			YSSsQueryRepository::purge_older_than( (int) $settings['retention_days'] );

			$status = YSSsSuggestService::invalidate();
			if ( ! in_array( $status, [
				YSSsSuggestService::INVALIDATION_ROTATED,
				YSSsSuggestService::INVALIDATION_BYPASS_FRESH,
			], true ) ) {
				throw new \RuntimeException( 'suggestion cache authority unavailable' );
			}
			YSSsSuggestService::suggestions(); // 重建快取
		} catch ( \Throwable $e ) {
			// 分析旁路：cron 例外不向外拋（不影響核心每日批次的其他訂閱者）。
			error_log( '[ys-cart-smart-search] daily cron error.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// 限流列維護放在原有 daily work 之後且獨立 fail-soft；清理不可阻止
		// rollup、retention 或 suggestions，錯誤也不外洩 DB detail。
		try {
			if ( ! YSSsRateLimiter::cleanup_expired() ) {
				throw new \RuntimeException( 'rate cleanup unavailable' );
			}
		} catch ( \Throwable $e ) {
			error_log( '[ys-cart-smart-search] daily rate cleanup error.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

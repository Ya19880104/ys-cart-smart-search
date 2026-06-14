<?php
/**
 * 搜尋分析頁（需求④：期間篩選＋KPI＋趨勢＋Top／零結果排行＋CSV）。
 *
 * 圖表用零依賴 CSS bar（不引 Chart.js，避免外掛級依賴）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Admin;

defined( 'ABSPATH' ) || exit;

final class YSSsAnalyticsAdmin {

	/**
	 * 獨立頁（保留相容）：包 YSAdminApp 殼 + 主體。實際入口已改為核心「報表分析」tab。
	 */
	public static function render(): void {
		$has_app = class_exists( '\YangSheep\Ecommerce\Admin\YSAdminApp' );

		if ( $has_app ) {
			\YangSheep\Ecommerce\Admin\YSAdminApp::open(
				__( '搜尋分析', 'ys-cart-smart-search' ),
				__( 'YS CART / 進階搜尋 / 搜尋分析', 'ys-cart-smart-search' )
			);
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( '搜尋分析', 'ys-cart-smart-search' ) . '</h1>';
		}

		self::render_body();

		if ( $has_app ) {
			\YangSheep\Ecommerce\Admin\YSAdminApp::close();
		} else {
			echo '</div>';
		}
	}

	/**
	 * 分析主體（不含後台外殼）。由獨立頁 render() 或核心報表 tab
	 * （ys_ec_report_render_tab_search）直接呼叫。
	 *
	 * 本分析自帶區間切換（today/7/30/90/自訂），故報表頁傳入的 $date_from/$date_to
	 * 僅作相容簽章不使用；同時隱藏核心報表工具列避免兩組日期控制衝突。
	 *
	 * @param string $date_from
	 * @param string $date_to
	 */
	public static function render_body( string $date_from = '', string $date_to = '' ): void {
		// 核心 2.52.45+ 對 addon 報表分頁已不渲染核心日期工具列（本分析自帶區間列）；
		// 無需再注入隱藏樣式。
		?>
		<div class="ys-ss-admin ys-ss-analytics-wrap" id="ys-ss-analytics-app">

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<div class="ys-ss-rangebar" role="group" aria-label="<?php esc_attr_e( '期間篩選', 'ys-cart-smart-search' ); ?>">
					<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--outline" data-ss-range="0"><?php esc_html_e( '今日', 'ys-cart-smart-search' ); ?></button>
					<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--outline" data-ss-range="6"><?php esc_html_e( '7 天', 'ys-cart-smart-search' ); ?></button>
					<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--outline is-active" data-ss-range="29"><?php esc_html_e( '30 天', 'ys-cart-smart-search' ); ?></button>
					<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--outline" data-ss-range="89"><?php esc_html_e( '90 天', 'ys-cart-smart-search' ); ?></button>
					<span class="ys-ss-rangebar__custom">
						<input type="date" id="ys-ss-from" aria-label="<?php esc_attr_e( '起日', 'ys-cart-smart-search' ); ?>">
						<span>—</span>
						<input type="date" id="ys-ss-to" aria-label="<?php esc_attr_e( '迄日', 'ys-cart-smart-search' ); ?>">
						<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--primary" id="ys-ss-apply"><?php esc_html_e( '套用', 'ys-cart-smart-search' ); ?></button>
					</span>
					<a class="ysca-btn ysca-btn--sm ysca-btn--ghost" id="ys-ss-export" href="#" download><?php esc_html_e( '匯出 CSV', 'ys-cart-smart-search' ); ?></a>
				</div>
			</div>

			<div class="ys-ss-kpis">
				<div class="ysca-card ysca-card--soft ys-ss-kpi ys-ss-kpi--total"><span><?php esc_html_e( '總搜尋次數', 'ys-cart-smart-search' ); ?></span><strong id="ys-ss-kpi-total">–</strong></div>
				<div class="ysca-card ysca-card--soft ys-ss-kpi ys-ss-kpi--unique"><span><?php esc_html_e( '獨立關鍵字', 'ys-cart-smart-search' ); ?></span><strong id="ys-ss-kpi-unique">–</strong></div>
				<div class="ysca-card ysca-card--soft ys-ss-kpi ys-ss-kpi--zero"><span><?php esc_html_e( '零結果次數', 'ys-cart-smart-search' ); ?></span><strong id="ys-ss-kpi-zero">–</strong></div>
				<div class="ysca-card ysca-card--soft ys-ss-kpi ys-ss-kpi--rate"><span><?php esc_html_e( '零結果率', 'ys-cart-smart-search' ); ?></span><strong id="ys-ss-kpi-zerorate">–</strong></div>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title"><?php esc_html_e( '每日搜尋量', 'ys-cart-smart-search' ); ?></h2>
				<div class="ys-ss-trend" id="ys-ss-trend" aria-label="<?php esc_attr_e( '每日搜尋量長條圖', 'ys-cart-smart-search' ); ?>"></div>
			</div>

			<div class="ys-ss-twocol">
				<div class="ysca-card ysca-card--soft ys-ss-card">
					<h2 class="ys-ss-card__title"><?php esc_html_e( '熱門關鍵字排行', 'ys-cart-smart-search' ); ?></h2>
					<table class="ys-ss-table">
						<thead><tr>
							<th>#</th>
							<th><?php esc_html_e( '關鍵字', 'ys-cart-smart-search' ); ?></th>
							<th class="ys-ss-w90"><?php esc_html_e( '次數', 'ys-cart-smart-search' ); ?></th>
							<th class="ys-ss-w90"><?php esc_html_e( '零結果', 'ys-cart-smart-search' ); ?></th>
							<th class="ys-ss-w90"></th>
						</tr></thead>
						<tbody id="ys-ss-top-body"><tr><td colspan="5"><?php esc_html_e( '載入中…', 'ys-cart-smart-search' ); ?></td></tr></tbody>
					</table>
				</div>

				<div class="ysca-card ysca-card--soft ys-ss-card">
					<h2 class="ys-ss-card__title"><?php esc_html_e( '零結果關鍵字（商機）', 'ys-cart-smart-search' ); ?></h2>
					<p class="ys-ss-muted"><?php esc_html_e( '顧客搜了但找不到的詞：考慮補貨、調整商品命名或設定同義關鍵字。', 'ys-cart-smart-search' ); ?></p>
					<table class="ys-ss-table">
						<thead><tr>
							<th>#</th>
							<th><?php esc_html_e( '關鍵字', 'ys-cart-smart-search' ); ?></th>
							<th class="ys-ss-w90"><?php esc_html_e( '零結果', 'ys-cart-smart-search' ); ?></th>
							<th class="ys-ss-w90"><?php esc_html_e( '總次數', 'ys-cart-smart-search' ); ?></th>
						</tr></thead>
						<tbody id="ys-ss-zero-body"><tr><td colspan="4"><?php esc_html_e( '載入中…', 'ys-cart-smart-search' ); ?></td></tr></tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}

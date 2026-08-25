<?php
/**
 * 使用說明頁（靜態文件式：快速開始／短代碼／Blocksy 元件／接管／建議原理／隱私／FAQ）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Admin;

defined( 'ABSPATH' ) || exit;

final class YSSsHelpAdmin {

	public static function render(): void {
		$has_app = class_exists( '\YangSheep\Ecommerce\Admin\YSAdminApp' );

		if ( $has_app ) {
			\YangSheep\Ecommerce\Admin\YSAdminApp::open(
				__( '進階搜尋使用說明', 'ys-cart-smart-search' ),
				__( 'YS CART / 進階搜尋 / 使用說明', 'ys-cart-smart-search' )
			);
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( '進階搜尋使用說明', 'ys-cart-smart-search' ) . '</h1>';
		}

		$settings_url  = admin_url( 'admin.php?page=' . YSSsMenuBootstrap::SLUG_SETTINGS );
		$analytics_url = admin_url( 'admin.php?page=ys-ec-reports&tab=search' );
		$has_blocksy   = defined( 'YS_CART_BLOCKSY_VERSION' );
		?>
		<div class="ys-ss-admin">

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title">🚀 <?php esc_html_e( '快速開始', 'ys-cart-smart-search' ); ?></h2>
				<ol class="ys-ss-help-list">
					<li><?php esc_html_e( '把搜尋元件放上前台：用下方短代碼，或（建議）用 Blocksy 頁首建構器的進階搜尋元件。', 'ys-cart-smart-search' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s = settings page link */
							esc_html__( '到「%s」調整熱門搜尋數量、搜尋內容與呈現、資料保留。', 'ys-cart-smart-search' ),
							'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( '進階搜尋設定', 'ys-cart-smart-search' ) . '</a>'
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: %s = analytics page link */
							esc_html__( '上線後到「報表分析 → %s」分頁看熱門關鍵字、每日趨勢與零結果商機。', 'ys-cart-smart-search' ),
							'<a href="' . esc_url( $analytics_url ) . '">' . esc_html__( '搜尋分析', 'ys-cart-smart-search' ) . '</a>'
						);
						?>
					</li>
				</ol>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title">🧩 <?php esc_html_e( '短代碼', 'ys-cart-smart-search' ); ?></h2>
				<table class="ys-ss-table">
					<thead><tr><th style="width:280px"><?php esc_html_e( '短代碼', 'ys-cart-smart-search' ); ?></th><th><?php esc_html_e( '說明', 'ys-cart-smart-search' ); ?></th></tr></thead>
					<tbody>
						<tr>
							<td><code>[ys_ss_search]</code></td>
							<td><?php esc_html_e( '行內搜尋框（即時建議＋分組結果）。可選參數 placeholder="提示文字"。', 'ys-cart-smart-search' ); ?></td>
						</tr>
						<tr>
							<td><code>[ys_ss_search_icon]</code></td>
							<td><?php esc_html_e( '搜尋圖示，點擊開啟全屏搜尋彈窗。適合頁首窄空間。', 'ys-cart-smart-search' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title">🎨 <?php esc_html_e( 'Blocksy 頁首元件', 'ys-cart-smart-search' ); ?></h2>
				<p class="ys-ss-muted">
					<?php esc_html_e( '若佈景主題為 Blocksy 並安裝「YS CART Blocksy 整合」外掛（v1.1+），「外觀 → 自訂 → 頁首」會多出兩個元件：「YS 進階搜尋 Icon」「YS 進階搜尋框」——拖放即用、尺寸顏色可調。本外掛停用時這兩個元件會自動隱藏。', 'ys-cart-smart-search' ); ?>
				</p>
				<p>
					<?php if ( $has_blocksy ) : ?>
						<span class="ys-ss-help-ok">✓ <?php esc_html_e( '已偵測到 YS CART Blocksy 整合，可直接到頁首建構器拖放。', 'ys-cart-smart-search' ); ?></span>
					<?php else : ?>
						<?php esc_html_e( '尚未安裝 YS CART Blocksy 整合 — 可至 YS Plugin Hub 安裝。', 'ys-cart-smart-search' ); ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title">🔁 <?php esc_html_e( '接管核心搜尋', 'ys-cart-smart-search' ); ?></h2>
				<p class="ys-ss-muted">
					<?php esc_html_e( '設定頁開啟「接管核心搜尋短代碼」後，既有頁面裡的 [ys_ec_search] 與 [ys_ec_search_icon] 會改由進階搜尋渲染（含建議與分析），不需逐頁改短代碼；關閉開關即還原核心版本。', 'ys-cart-smart-search' ); ?>
				</p>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title">📊 <?php esc_html_e( '搜尋分析報表', 'ys-cart-smart-search' ); ?></h2>
				<p class="ys-ss-muted">
					<?php esc_html_e( '顧客搜了什麼、哪些詞找不到結果（零結果商機）、每日搜尋趨勢，都整合在核心「報表分析」的「搜尋分析」分頁：KPI 四卡、每日趨勢、熱門關鍵字排行（可一鍵設為手動關鍵字）、零結果排行、CSV 匯出。', 'ys-cart-smart-search' ); ?>
				</p>
				<p>
					<a class="ysca-btn ysca-btn--primary" href="<?php echo esc_url( $analytics_url ); ?>">
						<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
						<?php esc_html_e( '前往報表分析 → 搜尋分析', 'ys-cart-smart-search' ); ?>
					</a>
				</p>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title">💡 <?php esc_html_e( '熱門搜尋建議怎麼運作', 'ys-cart-smart-search' ); ?></h2>
				<ul class="ys-ss-help-list">
					<li><?php esc_html_e( '混合式：手動關鍵字（依排序）優先顯示，不足設定數量時由「自動統計」補滿。', 'ys-cart-smart-search' ); ?></li>
					<li><?php esc_html_e( '自動統計取樣窗可選 7／30／90 天，只以有結果事件排名，並排除零結果率過高（>80%）的詞。', 'ys-cart-smart-search' ); ?></li>
					<li><?php esc_html_e( '建議清單有 10 分鐘快取；修改手動關鍵字會立即重建。', 'ys-cart-smart-search' ); ?></li>
					<li><?php esc_html_e( '「最近搜尋」只存在訪客瀏覽器（localStorage），不會上傳伺服器。', 'ys-cart-smart-search' ); ?></li>
				</ul>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title">🔒 <?php esc_html_e( '分析與隱私', 'ys-cart-smart-search' ); ?></h2>
				<ul class="ys-ss-help-list">
					<li><?php esc_html_e( '搜尋紀錄存於獨立資料表，與商店訂單／商品資料完全分離；分析故障不影響搜尋。', 'ys-cart-smart-search' ); ?></li>
					<li><?php esc_html_e( '不記錄 IP 與個資：訪客識別為「當日鹽」16 字雜湊，只能做同日去重、無法跨日追蹤。', 'ys-cart-smart-search' ); ?></li>
					<li><?php esc_html_e( '原始紀錄依「保留天數」（預設 180 天）每日自動清除。', 'ys-cart-smart-search' ); ?></li>
					<li><?php esc_html_e( '公開端點有頻率限制（防灌水）；紀錄時機僅限送出／點擊結果／停頓 1.2 秒，絕不逐鍵記錄。', 'ys-cart-smart-search' ); ?></li>
				</ul>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title">❓ FAQ</h2>
				<p><strong><?php esc_html_e( '為什麼剛搜尋的詞沒有馬上出現在自動建議？', 'ys-cart-smart-search' ); ?></strong><br>
				<span class="ys-ss-muted"><?php esc_html_e( '自動統計讀「每日彙總」，新詞最快隔日進榜；且建議清單有 10 分鐘快取。想立即置頂請用手動關鍵字。', 'ys-cart-smart-search' ); ?></span></p>
				<p><strong><?php esc_html_e( '零結果排行可以拿來做什麼？', 'ys-cart-smart-search' ); ?></strong><br>
				<span class="ys-ss-muted"><?php esc_html_e( '這是顧客「想買但找不到」的清單：考慮補貨、調整商品命名（把熱搜詞放進標題／SKU），或把正確商品詞設為手動關鍵字引導。', 'ys-cart-smart-search' ); ?></span></p>
				<p><strong><?php esc_html_e( '搜尋結果的排序邏輯？', 'ys-cart-smart-search' ); ?></strong><br>
				<span class="ys-ss-muted"><?php esc_html_e( '商品相關性：標題開頭符合 > 標題包含 > SKU 符合；僅顯示已發佈商品，並尊重核心「搜尋排除」設定。', 'ys-cart-smart-search' ); ?></span></p>
			</div>
		</div>
		<?php

		if ( $has_app ) {
			\YangSheep\Ecommerce\Admin\YSAdminApp::close();
		} else {
			echo '</div>';
		}
	}
}

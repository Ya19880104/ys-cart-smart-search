<?php
/**
 * 進階搜尋設定頁（YSAdminApp chrome + 核心 ysca primitives；頁籤式；存讀全走 REST）。
 *
 * 頁籤切換由核心 ys-cart-admin-shell.js 的 data-ysca-tabs 機制處理（不自寫 tab JS）。
 * 全程套用核心 ysca 設計系統（ysca-card / ysca-section-card / ysca-form-grid /
 * ysca-field / ysca-input / ysca-select / ysca-switch），對齊 YS CART 後台外觀。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Admin;

use YangSheep\SmartSearch\Database\YSSsKeywordRepository;
use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;
use YangSheep\SmartSearch\Frontend\YSSsResultsPage;

defined( 'ABSPATH' ) || exit;

final class YSSsSettingsAdmin {

	public static function render(): void {
		$settings = YSSsSettings::all();
		$keywords = YSSsKeywordRepository::all();
		$counts   = YSSsQueryRepository::counts();
		$has_app  = class_exists( '\YangSheep\Ecommerce\Admin\YSAdminApp' );

		if ( $has_app ) {
			\YangSheep\Ecommerce\Admin\YSAdminApp::open(
				__( '進階搜尋設定', 'ys-cart-smart-search' ),
				__( 'YS CART / 進階搜尋 / 設定', 'ys-cart-smart-search' )
			);
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( '進階搜尋設定', 'ys-cart-smart-search' ) . '</h1>';
		}

		$switch = static function ( string $key, string $label, bool $on, string $desc = '' ): void {
			?>
			<div class="ys-ec-form-group">
				<label class="ysca-switch-label">
					<span class="ysca-switch">
						<input type="checkbox" data-ss-key="<?php echo esc_attr( $key ); ?>"<?php echo $on ? ' checked' : ''; ?>>
						<span class="ysca-switch-slider"></span>
					</span>
					<strong><?php echo esc_html( $label ); ?></strong>
				</label>
				<?php if ( '' !== $desc ) : ?><p class="description"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</div>
			<?php
		};
		?>
		<div class="ys-ss-admin" id="ys-ss-settings-app"
			data-settings="<?php echo esc_attr( (string) wp_json_encode( $settings ) ); ?>"
			data-keywords="<?php echo esc_attr( (string) wp_json_encode( $keywords ) ); ?>">

			<nav class="ysca-tabs ysca-tabs--navigation ysca-tabs--scroll" role="tablist" data-ysca-tabs aria-label="<?php esc_attr_e( '進階搜尋設定分頁', 'ys-cart-smart-search' ); ?>">
				<button type="button" role="tab" class="ysca-tab" aria-controls="ys-ss-tab-frontend" aria-selected="true">
					<span class="ysca-tab__icon dashicons dashicons-welcome-widgets-menus" aria-hidden="true"></span><?php esc_html_e( '前台與接管', 'ys-cart-smart-search' ); ?>
				</button>
				<button type="button" role="tab" class="ysca-tab" aria-controls="ys-ss-tab-content" aria-selected="false">
					<span class="ysca-tab__icon dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e( '搜尋內容', 'ys-cart-smart-search' ); ?>
				</button>
				<button type="button" role="tab" class="ysca-tab" aria-controls="ys-ss-tab-suggest" aria-selected="false">
					<span class="ysca-tab__icon dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( '熱門建議', 'ys-cart-smart-search' ); ?>
				</button>
				<button type="button" role="tab" class="ysca-tab" aria-controls="ys-ss-tab-data" aria-selected="false">
					<span class="ysca-tab__icon dashicons dashicons-database" aria-hidden="true"></span><?php esc_html_e( '資料與保留', 'ys-cart-smart-search' ); ?>
				</button>
			</nav>

			<?php // ───────── Tab 1：前台與接管 ───────── ?>
			<div id="ys-ss-tab-frontend" role="tabpanel" class="ys-ss-tabpanel">
				<div class="ysca-card ysca-section-card">
					<h3><span class="dashicons dashicons-shortcode" aria-hidden="true"></span> <?php esc_html_e( '前台元件', 'ys-cart-smart-search' ); ?></h3>
					<div class="ysca-card__body inside">
						<p class="description"><?php esc_html_e( '短代碼：[ys_ss_search]＝搜尋框、[ys_ss_search_icon]＝搜尋圖示（點擊開啟彈窗）。可放置於頁面、小工具或頁首。Blocksy 主題另有「YS 進階搜尋 Icon／框」頁首元件。', 'ys-cart-smart-search' ); ?></p>
						<?php $switch( 'takeover', __( '接管核心搜尋短代碼', 'ys-cart-smart-search' ), ! empty( $settings['takeover'] ), __( '開啟後 [ys_ec_search] / [ys_ec_search_icon] 與篩選側邊欄改由進階搜尋渲染；關閉即還原核心版本。', 'ys-cart-smart-search' ) ); ?>
					</div>
				</div>

				<div class="ysca-card ysca-section-card">
					<h3><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <?php esc_html_e( '搜尋結果頁', 'ys-cart-smart-search' ); ?></h3>
					<div class="ysca-card__body inside ysca-form-grid">
						<div class="ysca-field ys-ec-form-group">
							<label for="ys-ss-results-mode"><?php esc_html_e( '結果模式', 'ys-cart-smart-search' ); ?></label>
							<select id="ys-ss-results-mode" class="ysca-select" data-ss-key="results_mode">
								<option value="list"<?php selected( (string) $settings['results_mode'], 'list' ); ?>><?php esc_html_e( '商品列表頁（A）— 落到商店頁，只顯示商品', 'ys-cart-smart-search' ); ?></option>
								<option value="page"<?php selected( (string) $settings['results_mode'], 'page' ); ?>><?php esc_html_e( '獨立混合結果頁（B）— 商品＋分類＋文章一頁呈現', 'ys-cart-smart-search' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '使用者按下搜尋（送出）後要落到哪裡。即時下拉預覽兩種模式都相同。', 'ys-cart-smart-search' ); ?></p>
						</div>
						<div class="ysca-field ys-ec-form-group">
							<label for="ys-ss-page-limit"><?php esc_html_e( '結果頁每頁商品數', 'ys-cart-smart-search' ); ?></label>
							<input type="number" id="ys-ss-page-limit" class="ysca-input ysca-field--compact" min="6" max="60" data-ss-key="products.page_limit" value="<?php echo esc_attr( (string) ( $settings['products']['page_limit'] ?? 24 ) ); ?>">
						</div>
						<?php
						if ( 'page' === (string) $settings['results_mode'] ) :
							$ys_rp_id    = (int) ( $settings['results_page_id'] ?? 0 );
							$ys_rp_valid = YSSsResultsPage::valid_page_id( $ys_rp_id );
							?>
							<div class="ysca-field ys-ec-form-group ysca-field--wide">
								<label><?php esc_html_e( '結果頁面', 'ys-cart-smart-search' ); ?></label>
								<p class="description">
									<?php if ( $ys_rp_valid ) : ?>
										<a href="<?php echo esc_url( (string) get_permalink( $ys_rp_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '前台檢視', 'ys-cart-smart-search' ); ?></a>
										<?php $ys_rp_edit = get_edit_post_link( $ys_rp_id ); if ( $ys_rp_edit ) : ?>
											&nbsp;｜&nbsp;<a href="<?php echo esc_url( $ys_rp_edit ); ?>"><?php esc_html_e( '編輯頁面', 'ys-cart-smart-search' ); ?></a>
										<?php endif; ?>
									<?php else : ?>
										<?php esc_html_e( '⚠ 結果頁尚未建立 — 儲存設定後會自動建立一個含 [ys_ss_search_results] 的「搜尋結果」頁。', 'ys-cart-smart-search' ); ?>
									<?php endif; ?>
								</p>
							</div>
						<?php else : ?>
							<div class="ysca-field ys-ec-form-group ysca-field--wide">
								<p class="description"><?php esc_html_e( '選「獨立混合結果頁」並儲存後，會自動建立含 [ys_ss_search_results] 的頁面；搜尋送出即落到該頁，文章／分類也會一併呈現。', 'ys-cart-smart-search' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php // ───────── Tab 2：搜尋內容 ───────── ?>
			<div id="ys-ss-tab-content" role="tabpanel" class="ys-ss-tabpanel" hidden>
				<div class="ysca-card ysca-section-card">
					<h3><span class="dashicons dashicons-products" aria-hidden="true"></span> <?php esc_html_e( '商品（必開）', 'ys-cart-smart-search' ); ?></h3>
					<div class="ysca-card__body inside ysca-form-grid">
						<div class="ysca-field ys-ec-form-group">
							<label for="ys-ss-products-limit"><?php esc_html_e( '下拉顯示筆數', 'ys-cart-smart-search' ); ?></label>
							<input type="number" id="ys-ss-products-limit" class="ysca-input ysca-field--compact" min="1" max="12" data-ss-key="products.limit" value="<?php echo esc_attr( (string) $settings['products']['limit'] ); ?>">
						</div>
						<div class="ysca-field ys-ec-form-group">
							<label><?php esc_html_e( '搜尋比對欄位', 'ys-cart-smart-search' ); ?></label>
							<?php $ys_fields = (array) ( $settings['products']['fields'] ?? [ 'name', 'sku', 'slug' ] ); ?>
							<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-product-field="name"<?php echo in_array( 'name', $ys_fields, true ) ? ' checked' : ''; ?>> <?php esc_html_e( '商品名稱', 'ys-cart-smart-search' ); ?></label>
							<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-product-field="sku"<?php echo in_array( 'sku', $ys_fields, true ) ? ' checked' : ''; ?>> SKU</label>
							<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-product-field="slug"<?php echo in_array( 'slug', $ys_fields, true ) ? ' checked' : ''; ?>> <?php esc_html_e( '網址代稱（slug）', 'ys-cart-smart-search' ); ?></label>
						</div>
						<div class="ysca-field ys-ec-form-group">
							<label><?php esc_html_e( '結果呈現', 'ys-cart-smart-search' ); ?></label>
							<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-key="products.show_image"<?php echo ! empty( $settings['products']['show_image'] ) ? ' checked' : ''; ?>> <?php esc_html_e( '縮圖', 'ys-cart-smart-search' ); ?></label>
							<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-key="products.show_price"<?php echo ! empty( $settings['products']['show_price'] ) ? ' checked' : ''; ?>> <?php esc_html_e( '價格', 'ys-cart-smart-search' ); ?></label>
							<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-key="products.show_sku"<?php echo ! empty( $settings['products']['show_sku'] ) ? ' checked' : ''; ?>> SKU</label>
						</div>
						<div class="ysca-field ys-ec-form-group ysca-field--wide">
							<label for="ys-ss-products-exclude"><?php esc_html_e( '額外排除商品（ID 或 slug，空白／逗號分隔）', 'ys-cart-smart-search' ); ?></label>
							<textarea id="ys-ss-products-exclude" class="ysca-textarea" rows="2" data-ss-key="products.exclude" placeholder="123, summer-tee, 456"><?php echo esc_textarea( (string) ( $settings['products']['exclude'] ?? '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( '排除清單會與核心「商店設定 → 搜尋」的排除 ID／slug 合併套用。', 'ys-cart-smart-search' ); ?></p>
						</div>
					</div>
				</div>

				<div class="ysca-card ysca-section-card">
					<h3><span class="dashicons dashicons-category" aria-hidden="true"></span> <?php esc_html_e( '分類', 'ys-cart-smart-search' ); ?></h3>
					<div class="ysca-card__body inside">
						<?php $switch( 'categories.enabled', __( '啟用分類搜尋', 'ys-cart-smart-search' ), ! empty( $settings['categories']['enabled'] ) ); ?>
						<div class="ysca-form-grid">
							<div class="ysca-field ys-ec-form-group">
								<label for="ys-ss-cat-limit"><?php esc_html_e( '筆數', 'ys-cart-smart-search' ); ?></label>
								<input type="number" id="ys-ss-cat-limit" class="ysca-input ysca-field--compact" min="1" max="10" data-ss-key="categories.limit" value="<?php echo esc_attr( (string) $settings['categories']['limit'] ); ?>">
							</div>
							<div class="ysca-field ys-ec-form-group">
								<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-key="categories.show_count"<?php echo ! empty( $settings['categories']['show_count'] ) ? ' checked' : ''; ?>> <?php esc_html_e( '顯示商品數', 'ys-cart-smart-search' ); ?></label>
							</div>
						</div>
					</div>
				</div>

				<div class="ysca-card ysca-section-card">
					<h3><span class="dashicons dashicons-admin-post" aria-hidden="true"></span> <?php esc_html_e( '文章／頁面（混搜）', 'ys-cart-smart-search' ); ?></h3>
					<div class="ysca-card__body inside">
						<?php $switch( 'posts.enabled', __( '啟用文章／頁面混搜', 'ys-cart-smart-search' ), ! empty( $settings['posts']['enabled'] ) ); ?>
						<div class="ysca-form-grid">
							<div class="ysca-field ys-ec-form-group">
								<label for="ys-ss-posts-limit"><?php esc_html_e( '筆數', 'ys-cart-smart-search' ); ?></label>
								<input type="number" id="ys-ss-posts-limit" class="ysca-input ysca-field--compact" min="1" max="10" data-ss-key="posts.limit" value="<?php echo esc_attr( (string) $settings['posts']['limit'] ); ?>">
							</div>
							<div class="ysca-field ys-ec-form-group">
								<label for="ys-ss-posts-excerpt"><?php esc_html_e( '摘要字數', 'ys-cart-smart-search' ); ?></label>
								<input type="number" id="ys-ss-posts-excerpt" class="ysca-input ysca-field--compact" min="0" max="200" data-ss-key="posts.excerpt_len" value="<?php echo esc_attr( (string) $settings['posts']['excerpt_len'] ); ?>">
							</div>
							<div class="ysca-field ys-ec-form-group">
								<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-key="posts.show_thumb"<?php echo ! empty( $settings['posts']['show_thumb'] ) ? ' checked' : ''; ?>> <?php esc_html_e( '縮圖', 'ys-cart-smart-search' ); ?></label>
							</div>
						</div>
						<div class="ysca-field ys-ec-form-group">
							<label><?php esc_html_e( '納入的文章類型', 'ys-cart-smart-search' ); ?></label>
							<?php
							$public_pts = get_post_types( [ 'public' => true ], 'objects' );
							unset( $public_pts['attachment'] );
							foreach ( $public_pts as $pt ) :
								$on = in_array( $pt->name, (array) $settings['posts']['post_types'], true );
								?>
								<label class="ysca-field ysca-field--inline"><input type="checkbox" data-ss-posttype="<?php echo esc_attr( $pt->name ); ?>"<?php echo $on ? ' checked' : ''; ?>> <?php echo esc_html( $pt->labels->singular_name . '（' . $pt->name . '）' ); ?></label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>

			<?php // ───────── Tab 3：熱門建議 ───────── ?>
			<div id="ys-ss-tab-suggest" role="tabpanel" class="ys-ss-tabpanel" hidden>
				<div class="ysca-card ysca-section-card">
					<h3><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> <?php esc_html_e( '熱門搜尋建議', 'ys-cart-smart-search' ); ?></h3>
					<div class="ysca-card__body inside ysca-form-grid">
						<div class="ysca-field ys-ec-form-group">
							<label for="ys-ss-suggest-count"><?php esc_html_e( '顯示數量（0＝關閉）', 'ys-cart-smart-search' ); ?></label>
							<input type="number" id="ys-ss-suggest-count" class="ysca-input ysca-field--compact" min="0" max="20" data-ss-key="suggest_count" value="<?php echo esc_attr( (string) $settings['suggest_count'] ); ?>">
						</div>
						<div class="ysca-field ys-ec-form-group">
							<label for="ys-ss-suggest-window"><?php esc_html_e( '自動統計取樣窗', 'ys-cart-smart-search' ); ?></label>
							<select id="ys-ss-suggest-window" class="ysca-select" data-ss-key="suggest_window_days">
								<?php foreach ( [ 7, 30, 90 ] as $w ) : ?>
									<option value="<?php echo esc_attr( (string) $w ); ?>"<?php selected( (int) $settings['suggest_window_days'], $w ); ?>><?php /* translators: %d days */ echo esc_html( sprintf( __( '最近 %d 天', 'ys-cart-smart-search' ), $w ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="ysca-field ys-ec-form-group ysca-field--wide">
							<?php $switch( 'recent_enabled', __( '顯示「最近搜尋」', 'ys-cart-smart-search' ), ! empty( $settings['recent_enabled'] ), __( '僅存訪客瀏覽器（localStorage），不上傳伺服器。', 'ys-cart-smart-search' ) ); ?>
						</div>
					</div>
					<div class="ysca-card__body inside">
						<p class="description"><?php esc_html_e( '混合規則：手動關鍵字（依排序）優先，不足數量由自動統計補滿；自動統計只採有結果事件並排除高零結果詞。', 'ys-cart-smart-search' ); ?></p>
					</div>
				</div>

				<div class="ysca-card ysca-section-card">
					<h3><span class="dashicons dashicons-tag" aria-hidden="true"></span> <?php esc_html_e( '手動關鍵字', 'ys-cart-smart-search' ); ?></h3>
					<div class="ysca-card__body inside">
						<div class="ys-ss-kw-add">
							<input type="text" id="ys-ss-kw-input" class="ysca-input" maxlength="100" placeholder="<?php esc_attr_e( '輸入關鍵字…', 'ys-cart-smart-search' ); ?>">
							<button type="button" class="ysca-btn ysca-btn--primary" id="ys-ss-kw-add"><?php esc_html_e( '新增', 'ys-cart-smart-search' ); ?></button>
						</div>
						<table class="ysca-table ys-ss-table" id="ys-ss-kw-table">
							<thead>
								<tr>
									<th><?php esc_html_e( '關鍵字', 'ys-cart-smart-search' ); ?></th>
									<th class="ys-ss-w90"><?php esc_html_e( '排序', 'ys-cart-smart-search' ); ?></th>
									<th class="ys-ss-w90"><?php esc_html_e( '啟用', 'ys-cart-smart-search' ); ?></th>
									<th class="ys-ss-w90"></th>
								</tr>
							</thead>
							<tbody><!-- JS render --></tbody>
						</table>
					</div>
				</div>
			</div>

			<?php // ───────── Tab 4：資料與保留 ───────── ?>
			<div id="ys-ss-tab-data" role="tabpanel" class="ys-ss-tabpanel" hidden>
				<div class="ysca-card ysca-section-card">
					<h3><span class="dashicons dashicons-database" aria-hidden="true"></span> <?php esc_html_e( '資料保留', 'ys-cart-smart-search' ); ?></h3>
					<div class="ysca-card__body inside ysca-form-grid">
						<div class="ysca-field ys-ec-form-group">
							<label for="ys-ss-retention"><?php esc_html_e( '保留天數（0＝永久，不建議）', 'ys-cart-smart-search' ); ?></label>
							<input type="number" id="ys-ss-retention" class="ysca-input ysca-field--compact" min="0" max="3650" data-ss-key="retention_days" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>">
						</div>
						<div class="ysca-field ys-ec-form-group">
							<label><?php esc_html_e( '目前資料量', 'ys-cart-smart-search' ); ?></label>
							<strong id="ys-ss-counts"><?php echo esc_html( number_format( $counts['queries'] ) . ' 筆原始 / ' . number_format( $counts['daily'] ) . ' 筆彙總' ); ?></strong>
						</div>
					</div>
					<div class="ysca-card__footer">
						<button type="button" class="ysca-btn ysca-btn--outline" id="ys-ss-purge-expired"><?php esc_html_e( '立即清理逾期資料', 'ys-cart-smart-search' ); ?></button>
						<button type="button" class="ysca-btn ysca-btn--ghost ys-ss-danger" id="ys-ss-purge-all"><?php esc_html_e( '清除全部分析資料…', 'ys-cart-smart-search' ); ?></button>
					</div>
				</div>
				<div class="ysca-card ysca-section-card ysca-card--soft">
					<div class="ysca-card__body inside">
						<p class="description"><?php esc_html_e( '搜尋分析報表已整合進「報表分析 → 搜尋分析」分頁（KPI／趨勢／熱門排行／零結果商機／CSV 匯出）。', 'ys-cart-smart-search' ); ?>
						&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=ys-ec-reports&tab=search' ) ); ?>"><?php esc_html_e( '前往搜尋分析 →', 'ys-cart-smart-search' ); ?></a></p>
					</div>
				</div>
			</div>

			<div class="ysca-form-actions ys-ss-savebar">
				<button type="button" class="ysca-btn ysca-btn--primary" id="ys-ss-save"><?php esc_html_e( '儲存設定', 'ys-cart-smart-search' ); ?></button>
				<span class="ys-ss-save-msg" id="ys-ss-save-msg" role="status" aria-live="polite"></span>
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

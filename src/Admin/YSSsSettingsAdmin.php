<?php
/**
 * 搜尋設定頁（YSAdminApp chrome + ysca primitives；存讀全走 REST）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Admin;

use YangSheep\SmartSearch\Database\YSSsKeywordRepository;
use YangSheep\SmartSearch\Database\YSSsQueryRepository;
use YangSheep\SmartSearch\Database\YSSsSettings;

defined( 'ABSPATH' ) || exit;

final class YSSsSettingsAdmin {

	public static function render(): void {
		$settings = YSSsSettings::all();
		$keywords = YSSsKeywordRepository::all();
		$counts   = YSSsQueryRepository::counts();
		$has_app  = class_exists( '\YangSheep\Ecommerce\Admin\YSAdminApp' );

		if ( $has_app ) {
			\YangSheep\Ecommerce\Admin\YSAdminApp::open(
				__( '智慧搜尋', 'ys-cart-smart-search' ),
				__( 'YS CART / 智慧搜尋', 'ys-cart-smart-search' )
			);
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( '智慧搜尋', 'ys-cart-smart-search' ) . '</h1>';
		}

		$check = static function ( bool $on ): string {
			return $on ? ' checked' : '';
		};
		?>
		<div class="ys-ss-admin" id="ys-ss-settings-app"
			data-settings="<?php echo esc_attr( (string) wp_json_encode( $settings ) ); ?>"
			data-keywords="<?php echo esc_attr( (string) wp_json_encode( $keywords ) ); ?>">

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title"><?php esc_html_e( '前台元件', 'ys-cart-smart-search' ); ?></h2>
				<p class="ys-ss-muted">
					<?php esc_html_e( '短代碼：[ys_ss_search]＝搜尋框、[ys_ss_search_icon]＝搜尋圖示（點擊開啟彈窗）。可放置於頁面、小工具或頁首。', 'ys-cart-smart-search' ); ?>
				</p>
				<label class="ys-ss-row">
					<input type="checkbox" data-ss-key="takeover"<?php echo $check( ! empty( $settings['takeover'] ) ); ?>>
					<span><?php esc_html_e( '接管核心搜尋短代碼（[ys_ec_search] / [ys_ec_search_icon] 改由智慧搜尋渲染；關閉即還原）', 'ys-cart-smart-search' ); ?></span>
				</label>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title"><?php esc_html_e( '熱門搜尋建議', 'ys-cart-smart-search' ); ?></h2>
				<div class="ys-ss-grid">
					<label class="ys-ss-field">
						<span><?php esc_html_e( '顯示數量（0＝關閉）', 'ys-cart-smart-search' ); ?></span>
						<input type="number" min="0" max="20" data-ss-key="suggest_count" value="<?php echo esc_attr( (string) $settings['suggest_count'] ); ?>">
					</label>
					<label class="ys-ss-field">
						<span><?php esc_html_e( '自動統計取樣窗', 'ys-cart-smart-search' ); ?></span>
						<select data-ss-key="suggest_window_days">
							<?php foreach ( [ 7, 30, 90 ] as $w ) : ?>
								<option value="<?php echo esc_attr( (string) $w ); ?>"<?php selected( (int) $settings['suggest_window_days'], $w ); ?>>
									<?php
									/* translators: %d = days */
									echo esc_html( sprintf( __( '最近 %d 天', 'ys-cart-smart-search' ), $w ) );
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="ys-ss-row">
						<input type="checkbox" data-ss-key="recent_enabled"<?php echo $check( ! empty( $settings['recent_enabled'] ) ); ?>>
						<span><?php esc_html_e( '顯示「最近搜尋」（僅存訪客瀏覽器，不上傳）', 'ys-cart-smart-search' ); ?></span>
					</label>
				</div>
				<p class="ys-ss-muted"><?php esc_html_e( '混合規則：手動關鍵字（依排序）優先，不足數量由自動統計補滿；自動統計排除高零結果詞。', 'ys-cart-smart-search' ); ?></p>

				<h3 class="ys-ss-subtitle"><?php esc_html_e( '手動關鍵字', 'ys-cart-smart-search' ); ?></h3>
				<div class="ys-ss-kw-add">
					<input type="text" id="ys-ss-kw-input" maxlength="100" placeholder="<?php esc_attr_e( '輸入關鍵字…', 'ys-cart-smart-search' ); ?>">
					<button type="button" class="ysca-btn ysca-btn--primary" id="ys-ss-kw-add"><?php esc_html_e( '新增', 'ys-cart-smart-search' ); ?></button>
				</div>
				<table class="ys-ss-table" id="ys-ss-kw-table">
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

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title"><?php esc_html_e( '搜尋內容與呈現', 'ys-cart-smart-search' ); ?></h2>

				<h3 class="ys-ss-subtitle"><?php esc_html_e( '商品（必開）', 'ys-cart-smart-search' ); ?></h3>
				<div class="ys-ss-grid">
					<label class="ys-ss-field"><span><?php esc_html_e( '筆數', 'ys-cart-smart-search' ); ?></span>
						<input type="number" min="1" max="12" data-ss-key="products.limit" value="<?php echo esc_attr( (string) $settings['products']['limit'] ); ?>"></label>
					<label class="ys-ss-row"><input type="checkbox" data-ss-key="products.show_image"<?php echo $check( ! empty( $settings['products']['show_image'] ) ); ?>><span><?php esc_html_e( '縮圖', 'ys-cart-smart-search' ); ?></span></label>
					<label class="ys-ss-row"><input type="checkbox" data-ss-key="products.show_price"<?php echo $check( ! empty( $settings['products']['show_price'] ) ); ?>><span><?php esc_html_e( '價格', 'ys-cart-smart-search' ); ?></span></label>
					<label class="ys-ss-row"><input type="checkbox" data-ss-key="products.show_sku"<?php echo $check( ! empty( $settings['products']['show_sku'] ) ); ?>><span>SKU</span></label>
				</div>

				<h3 class="ys-ss-subtitle"><?php esc_html_e( '分類', 'ys-cart-smart-search' ); ?></h3>
				<div class="ys-ss-grid">
					<label class="ys-ss-row"><input type="checkbox" data-ss-key="categories.enabled"<?php echo $check( ! empty( $settings['categories']['enabled'] ) ); ?>><span><?php esc_html_e( '啟用', 'ys-cart-smart-search' ); ?></span></label>
					<label class="ys-ss-field"><span><?php esc_html_e( '筆數', 'ys-cart-smart-search' ); ?></span>
						<input type="number" min="1" max="10" data-ss-key="categories.limit" value="<?php echo esc_attr( (string) $settings['categories']['limit'] ); ?>"></label>
					<label class="ys-ss-row"><input type="checkbox" data-ss-key="categories.show_count"<?php echo $check( ! empty( $settings['categories']['show_count'] ) ); ?>><span><?php esc_html_e( '顯示商品數', 'ys-cart-smart-search' ); ?></span></label>
				</div>

				<h3 class="ys-ss-subtitle"><?php esc_html_e( '文章／頁面（混搜）', 'ys-cart-smart-search' ); ?></h3>
				<div class="ys-ss-grid">
					<label class="ys-ss-row"><input type="checkbox" data-ss-key="posts.enabled"<?php echo $check( ! empty( $settings['posts']['enabled'] ) ); ?>><span><?php esc_html_e( '啟用', 'ys-cart-smart-search' ); ?></span></label>
					<label class="ys-ss-field"><span><?php esc_html_e( '筆數', 'ys-cart-smart-search' ); ?></span>
						<input type="number" min="1" max="10" data-ss-key="posts.limit" value="<?php echo esc_attr( (string) $settings['posts']['limit'] ); ?>"></label>
					<label class="ys-ss-row"><input type="checkbox" data-ss-key="posts.show_thumb"<?php echo $check( ! empty( $settings['posts']['show_thumb'] ) ); ?>><span><?php esc_html_e( '縮圖', 'ys-cart-smart-search' ); ?></span></label>
					<label class="ys-ss-field"><span><?php esc_html_e( '摘要字數', 'ys-cart-smart-search' ); ?></span>
						<input type="number" min="0" max="200" data-ss-key="posts.excerpt_len" value="<?php echo esc_attr( (string) $settings['posts']['excerpt_len'] ); ?>"></label>
				</div>
				<div class="ys-ss-grid" id="ys-ss-post-types">
					<?php
					$public_pts = get_post_types( [ 'public' => true ], 'objects' );
					unset( $public_pts['attachment'] );
					foreach ( $public_pts as $pt ) :
						$on = in_array( $pt->name, (array) $settings['posts']['post_types'], true );
						?>
						<label class="ys-ss-row">
							<input type="checkbox" data-ss-posttype="<?php echo esc_attr( $pt->name ); ?>"<?php echo $check( $on ); ?>>
							<span><?php echo esc_html( $pt->labels->singular_name . '（' . $pt->name . '）' ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="ysca-card ysca-card--soft ys-ss-card">
				<h2 class="ys-ss-card__title"><?php esc_html_e( '資料保留', 'ys-cart-smart-search' ); ?></h2>
				<div class="ys-ss-grid">
					<label class="ys-ss-field">
						<span><?php esc_html_e( '保留天數（0＝永久，不建議）', 'ys-cart-smart-search' ); ?></span>
						<input type="number" min="0" max="3650" data-ss-key="retention_days" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>">
					</label>
					<div class="ys-ss-field">
						<span><?php esc_html_e( '目前資料量', 'ys-cart-smart-search' ); ?></span>
						<strong id="ys-ss-counts"><?php echo esc_html( number_format( $counts['queries'] ) . ' 筆原始 / ' . number_format( $counts['daily'] ) . ' 筆彙總' ); ?></strong>
					</div>
				</div>
				<div class="ys-ss-actions">
					<button type="button" class="ysca-btn ysca-btn--outline" id="ys-ss-purge-expired"><?php esc_html_e( '立即清理逾期資料', 'ys-cart-smart-search' ); ?></button>
					<button type="button" class="ysca-btn ysca-btn--ghost ys-ss-danger" id="ys-ss-purge-all"><?php esc_html_e( '清除全部分析資料…', 'ys-cart-smart-search' ); ?></button>
				</div>
			</div>

			<div class="ys-ss-savebar">
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

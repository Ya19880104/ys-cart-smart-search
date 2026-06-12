<?php
/**
 * Plugin Name: YS CART 智慧搜尋
 * Plugin URI: https://yangsheep.com.tw
 * Description: YS CART 站內智慧搜尋：搜尋 Bar／Icon Popup、混合式熱門關鍵字建議、商品為主可混搜文章頁面、完整搜尋分析報表（ADR-058）。
 * Version: 1.0.0
 * Author: YANGSHEEP DESIGN
 * Author URI: https://yangsheep.com.tw
 * Text Domain: ys-cart-smart-search
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 *
 * @package YangSheep\SmartSearch
 */

defined( 'ABSPATH' ) || exit;

define( 'YS_SMART_SEARCH_VERSION', '1.0.0' );
define( 'YS_SMART_SEARCH_FILE', __FILE__ );
define( 'YS_SMART_SEARCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'YS_SMART_SEARCH_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'YS CART 智慧搜尋需要 PHP 8.1 以上版本。', 'ys-cart-smart-search' );
		echo '</p></div>';
	} );
	return;
}

// PSR-4 Autoloader
spl_autoload_register( function ( $class ) {
	$prefix   = 'YangSheep\\SmartSearch\\';
	$base_dir = YS_SMART_SEARCH_PATH . 'src/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$file = $base_dir . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// 自有資料表（獨立於核心，缺 YS CART 也可先建好）
register_activation_hook( __FILE__, [ \YangSheep\SmartSearch\Database\YSSsSchema::class, 'install' ] );

add_action( 'plugins_loaded', [ \YangSheep\SmartSearch\YSSmartSearchPlugin::class, 'boot' ] );

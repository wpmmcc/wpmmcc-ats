<?php
/**
 * WP EasyCart 数据填充验证脚本
 *
 * WP EasyCart 使用自定义表 ec_product，不使用 WordPress posts
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WP EasyCart 验证                                            ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! class_exists( 'ec_db' ) && ! function_exists( 'wpeasycart_admin' ) ) {
    echo "❌ WP EasyCart 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

global $wpdb;
$results = array();

// =============================================================
// 1. 内容数量（自定义表）
// =============================================================
echo "--- 1. 内容数量 ---\n";

// EasyCart 表没有 wp_ 前缀
$product_count = $wpdb->get_var( "SELECT COUNT(*) FROM ec_product" );
$product_count = (int) $product_count;
$pass = $product_count >= 1;
$icon = $pass ? '✓' : '✗';
echo "  $icon 产品 (ec_product): $product_count 条" . ( $pass ? '' : ' (期望 >= 1)' ) . "\n";
$results['count_products'] = array( 'success' => $pass, 'count' => $product_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 商店页面
$store_page_id = get_option( 'ec_option_storepage' );
if ( $store_page_id ) {
    $store_url = get_permalink( $store_page_id );
    if ( $store_url ) {
        $store_path = str_replace( home_url(), '', $store_url );
        $result = wptsall_verify_url( $store_path );
        wptsall_verify_url_output( "商店页面 ($store_path)", $result );
        $results['url_store'] = $result;
    }
} else {
    echo "  ⚠ 商店页面未配置\n";
    $results['url_store'] = array( 'success' => false, 'status' => 'not_configured' );
}

// 购物车页面
$cart_page_id = get_option( 'ec_option_cartpage' );
if ( $cart_page_id ) {
    $cart_url = get_permalink( $cart_page_id );
    if ( $cart_url ) {
        $cart_path = str_replace( home_url(), '', $cart_url );
        $result = wptsall_verify_url( $cart_path );
        wptsall_verify_url_output( "购物车 ($cart_path)", $result );
        $results['url_cart'] = $result;
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'admin.php?page=wp-easycart-products' => '产品管理',
    'admin.php?page=wp-easycart-orders'   => '订单管理',
);

foreach ( $admin_pages as $path => $label ) {
    $result = wptsall_verify_admin_url( $path );
    wptsall_verify_url_output( $label, $result );
    $results[ 'admin_' . sanitize_key( $label ) ] = $result;
}

// =============================================================
// 汇总
// =============================================================
echo "\n";
$summary = wptsall_verify_summary( $results );
echo "╔══════════════════════════════════════════════════════════════╗\n";
printf( "║  验证完成: %d/%d 通过 (%.1f%%)                               ║\n",
    $summary['passed'], $summary['total'], $summary['rate'] );
echo "╚══════════════════════════════════════════════════════════════╝\n";

return array(
    'plugin'  => 'wp-easycart',
    'results' => $results,
    'summary' => $summary,
);

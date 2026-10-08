<?php
/**
 * WooCommerce 数据填充验证脚本
 *
 * 验证内容：
 *   1. 必要页面配置（Shop/Cart/Checkout/My Account）
 *   2. 产品数量统计
 *   3. 前台 URL 访问
 *   4. 后台页面访问
 *
 * 执行方式:
 *   wp eval-file woocommerce-verify.php
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 加载辅助函数
require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WooCommerce 验证                                            ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! class_exists( 'WooCommerce' ) ) {
    echo "❌ WooCommerce 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 页面配置验证
// =============================================================
echo "--- 1. 页面配置 ---\n";

$pages = array(
    'shop'       => array( 'option' => 'woocommerce_shop_page_id', 'label' => 'Shop 页面' ),
    'cart'       => array( 'option' => 'woocommerce_cart_page_id', 'label' => 'Cart 页面' ),
    'checkout'   => array( 'option' => 'woocommerce_checkout_page_id', 'label' => 'Checkout 页面' ),
    'myaccount'  => array( 'option' => 'woocommerce_myaccount_page_id', 'label' => 'My Account 页面' ),
);

foreach ( $pages as $key => $config ) {
    $page_id = get_option( $config['option'] );
    $page = $page_id ? get_post( $page_id ) : null;
    $success = $page && $page->post_status === 'publish';

    wptsall_verify_output( $config['label'], $success, $success ? "ID $page_id" : '未配置' );

    $results[ "page_$key" ] = array(
        'success' => $success,
        'page_id' => $page_id,
    );
}

// =============================================================
// 2. 内容数量
// =============================================================
echo "\n--- 2. 内容数量 ---\n";

$results['count_product'] = wptsall_verify_count_output( 'product', '产品 (product)', 10 );

// 产品分类
$cat_count = wptsall_get_term_count( 'product_cat' );
wptsall_verify_output( '产品分类 (product_cat)', $cat_count > 0, "$cat_count 个" );
$results['count_product_cat'] = array( 'success' => $cat_count > 0, 'count' => $cat_count );

// =============================================================
// 3. 前台 URL 验证
// =============================================================
echo "\n--- 3. 前台访问 ---\n";

// Shop 页面
$shop_url = wc_get_page_permalink( 'shop' );
if ( $shop_url ) {
    $shop_path = str_replace( home_url(), '', $shop_url );
    $result = wptsall_verify_url( $shop_path );
    wptsall_verify_url_output( "Shop 页面 ($shop_path)", $result );
    $results['url_shop'] = $result;
}

// Cart 页面
$cart_url = wc_get_cart_url();
if ( $cart_url ) {
    $cart_path = str_replace( home_url(), '', $cart_url );
    $result = wptsall_verify_url( $cart_path );
    wptsall_verify_url_output( "Cart 页面 ($cart_path)", $result );
    $results['url_cart'] = $result;
}

// 单个产品
$sample_url = wptsall_get_sample_url( 'product' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "产品单页 ($sample_path)", $result );
    $results['url_product_single'] = $result;
}

// 产品归档
$archive_url = wptsall_get_archive_url( 'product' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "产品归档 ($archive_path)", $result );
    $results['url_product_archive'] = $result;
}

// =============================================================
// 4. 后台验证
// =============================================================
echo "\n--- 4. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=product' => '产品列表',
    'post-new.php?post_type=product' => '新建产品',
    'admin.php?page=wc-admin' => 'WooCommerce 管理',
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
    'plugin'  => 'woocommerce',
    'results' => $results,
    'summary' => $summary,
);

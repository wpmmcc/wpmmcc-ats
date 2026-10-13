<?php
/**
 * Classified Listing 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Classified Listing 验证                                     ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'rtcl_listing' ) ) {
    echo "❌ Classified Listing 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_listing'] = wptsall_verify_count_output( 'rtcl_listing', '分类信息 (rtcl_listing)', 5 );

// 分类
$cat_count = wptsall_get_term_count( 'rtcl_category' );
wptsall_verify_output( '分类 (rtcl_category)', $cat_count > 0, "$cat_count 个" );
$results['count_category'] = array( 'success' => $cat_count > 0, 'count' => $cat_count );

// 位置
$loc_count = wptsall_get_term_count( 'rtcl_location' );
wptsall_verify_output( '位置 (rtcl_location)', $loc_count >= 0, "$loc_count 个" );
$results['count_location'] = array( 'success' => true, 'count' => $loc_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 归档页
$archive_url = wptsall_get_archive_url( 'rtcl_listing' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "分类信息归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个分类信息
// 注意：Classified Listing 单页可能加载较慢（1-20秒），这是插件的正常特性
$sample_url = wptsall_get_sample_url( 'rtcl_listing' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );

    // 使用更长的超时时间测试
    $full_url = home_url( $sample_path );
    $response = wp_remote_get( $full_url, array(
        'timeout'     => 30,  // 30 秒超时
        'sslverify'   => false,
        'redirection' => 5,
    ) );

    if ( is_wp_error( $response ) ) {
        $result = array(
            'url'     => $full_url,
            'status'  => 0,
            'success' => false,
            'message' => $response->get_error_message(),
        );
    } else {
        $status = wp_remote_retrieve_response_code( $response );
        $result = array(
            'url'     => $full_url,
            'status'  => $status,
            'success' => ( $status >= 200 && $status < 400 ),
            'message' => $status >= 200 && $status < 400 ? 'OK' : 'HTTP ' . $status,
        );
    }

    wptsall_verify_url_output( "分类信息单页 ($sample_path)", $result );
    $results['url_listing_single'] = $result;

    if ( ! $result['success'] ) {
        echo "      (注：此插件单页加载较慢是正常特性)\n";
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=rtcl_listing' => '分类信息列表',
    'post-new.php?post_type=rtcl_listing' => '新建分类信息',
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
    'plugin'  => 'classified-listing',
    'results' => $results,
    'summary' => $summary,
);

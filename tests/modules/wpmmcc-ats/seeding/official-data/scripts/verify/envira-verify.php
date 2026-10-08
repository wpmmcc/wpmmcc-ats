<?php
/**
 * Envira Gallery 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Envira Gallery 验证                                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'envira' ) ) {
    echo "❌ Envira Gallery 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_gallery'] = wptsall_verify_count_output( 'envira', '图库 (envira)', 1 );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 注意：Envira Gallery 设计上 publicly_queryable=false
// 图库通过 shortcode [envira-gallery id="xxx"] 嵌入页面使用
// 不支持直接 URL 访问，这是正常的设计

$pt = get_post_type_object( 'envira' );
if ( $pt && ! $pt->publicly_queryable ) {
    echo "  (i) Envira 图库通过 shortcode 使用，不支持直接 URL 访问（设计如此）\n";
    $results['url_note'] = array( 'success' => true, 'message' => 'shortcode_only' );
} else {
    // 如果支持直接访问，则测试
    $sample_url = wptsall_get_sample_url( 'envira' );
    if ( $sample_url ) {
        $sample_path = str_replace( home_url(), '', $sample_url );
        $result = wptsall_verify_url( $sample_path );
        wptsall_verify_url_output( "图库单页 ($sample_path)", $result );
        $results['url_gallery_single'] = $result;
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=envira' => '图库列表',
    'post-new.php?post_type=envira' => '新建图库',
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
    'plugin'  => 'envira-gallery-lite',
    'results' => $results,
    'summary' => $summary,
);

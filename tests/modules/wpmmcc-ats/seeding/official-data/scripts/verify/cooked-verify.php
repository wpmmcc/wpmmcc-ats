<?php
/**
 * Cooked 食谱插件数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Cooked 验证                                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'cp_recipe' ) ) {
    echo "❌ Cooked 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 页面配置验证
// =============================================================
echo "--- 1. 页面配置 ---\n";

$browse_page_id = get_option( 'cooked_browse_page' );
$browse_page = $browse_page_id ? get_post( $browse_page_id ) : null;
$success = $browse_page && $browse_page->post_status === 'publish';
wptsall_verify_output( '食谱浏览页面', $success, $success ? "ID $browse_page_id" : '未配置' );
$results['page_browse'] = array( 'success' => $success, 'page_id' => $browse_page_id );

// =============================================================
// 2. 内容数量
// =============================================================
echo "\n--- 2. 内容数量 ---\n";

$results['count_recipe'] = wptsall_verify_count_output( 'cp_recipe', '食谱 (cp_recipe)', 5 );

// 食谱分类
$cat_count = wptsall_get_term_count( 'cp_recipe_category' );
wptsall_verify_output( '食谱分类 (cp_recipe_category)', $cat_count > 0, "$cat_count 个" );
$results['count_category'] = array( 'success' => $cat_count > 0, 'count' => $cat_count );

// =============================================================
// 3. 前台 URL 验证
// =============================================================
echo "\n--- 3. 前台访问 ---\n";

// 浏览页面
if ( $browse_page_id ) {
    $browse_url = get_permalink( $browse_page_id );
    $browse_path = str_replace( home_url(), '', $browse_url );
    $result = wptsall_verify_url( $browse_path );
    wptsall_verify_url_output( "浏览页面 ($browse_path)", $result );
    $results['url_browse'] = $result;
}

// 单个食谱
$sample_url = wptsall_get_sample_url( 'cp_recipe' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "食谱单页 ($sample_path)", $result );
    $results['url_recipe_single'] = $result;
}

// 食谱归档（如果有）
$archive_url = wptsall_get_archive_url( 'cp_recipe' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "食谱归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// =============================================================
// 4. 后台验证
// =============================================================
echo "\n--- 4. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=cp_recipe' => '食谱列表',
    'post-new.php?post_type=cp_recipe' => '新建食谱',
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
    'plugin'  => 'cooked',
    'results' => $results,
    'summary' => $summary,
);

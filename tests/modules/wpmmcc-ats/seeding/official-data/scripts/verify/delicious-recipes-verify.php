<?php
/**
 * Delicious Recipes 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Delicious Recipes 验证                                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'recipe' ) ) {
    echo "❌ Delicious Recipes 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 0. 页面配置
// =============================================================
echo "--- 0. 页面配置 ---\n";

$search_page_id = get_option( 'delicious_recipes_recipe-search_page_id', 0 );
$page_exists = $search_page_id > 0 && get_post_status( $search_page_id ) === 'publish';
wptsall_verify_output( 'Recipe Search 页面', $page_exists, $page_exists ? "ID: $search_page_id" : '未配置' );
$results['page_search'] = array( 'success' => $page_exists, 'page_id' => "$search_page_id" );

// =============================================================
// 1. 内容数量
// =============================================================
echo "\n--- 1. 内容数量 ---\n";

$results['count_recipes'] = wptsall_verify_count_output( 'recipe', '食谱 (recipe)', 3 );

// 分类
$term_count = wptsall_get_term_count( 'recipe-course' );
wptsall_verify_output( '菜品分类 (recipe-course)', $term_count >= 0, "$term_count 个" );
$results['count_course'] = array( 'success' => true, 'count' => $term_count );

$term_count = wptsall_get_term_count( 'recipe-cuisine' );
wptsall_verify_output( '菜系 (recipe-cuisine)', $term_count >= 0, "$term_count 个" );
$results['count_cuisine'] = array( 'success' => true, 'count' => $term_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 食谱归档
$archive_url = wptsall_get_archive_url( 'recipe' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "食谱归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个食谱
$sample_url = wptsall_get_sample_url( 'recipe' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "食谱单页 ($sample_path)", $result );
    $results['url_recipe_single'] = $result;
}

// Search 页面
if ( $search_page_id > 0 ) {
    $search_url = get_permalink( $search_page_id );
    if ( $search_url ) {
        $search_path = str_replace( home_url(), '', $search_url );
        $result = wptsall_verify_url( $search_path );
        wptsall_verify_url_output( "食谱搜索 ($search_path)", $result );
        $results['url_search'] = $result;
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=recipe'     => '食谱列表',
    'post-new.php?post_type=recipe' => '新建食谱',
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
    'plugin'  => 'delicious-recipes',
    'results' => $results,
    'summary' => $summary,
);

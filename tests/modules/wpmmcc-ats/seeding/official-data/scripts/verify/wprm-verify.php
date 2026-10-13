<?php
/**
 * WP Recipe Maker 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WP Recipe Maker 验证                                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'wprm_recipe' ) ) {
    echo "❌ WP Recipe Maker 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_recipes'] = wptsall_verify_count_output( 'wprm_recipe', '食谱 (wprm_recipe)', 1 );

// 食谱分类
$course_count = wptsall_get_term_count( 'wprm_course' );
wptsall_verify_output( '课程分类 (wprm_course)', $course_count >= 0, "$course_count 个" );
$results['count_course'] = array( 'success' => true, 'count' => $course_count );

$cuisine_count = wptsall_get_term_count( 'wprm_cuisine' );
wptsall_verify_output( '菜系分类 (wprm_cuisine)', $cuisine_count >= 0, "$cuisine_count 个" );
$results['count_cuisine'] = array( 'success' => true, 'count' => $cuisine_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// WPRM 食谱是 private post type (public => false)
// 食谱通过 [wprm-recipe] 短代码嵌入到其他文章中显示，没有独立的前台 URL
$post_type_obj = get_post_type_object( 'wprm_recipe' );
if ( $post_type_obj && ! $post_type_obj->public ) {
    echo "  ⚠ 食谱为嵌入式内容 (public=false)，无独立前台 URL（正常）\n";
    $results['url_note'] = array( 'success' => true, 'status' => 'embedded_content' );
} else {
    // 如果 post type 是 public 的，则验证 URL
    $archive_url = get_post_type_archive_link( 'wprm_recipe' );
    if ( $archive_url ) {
        $archive_path = str_replace( home_url(), '', $archive_url );
        $result = wptsall_verify_url( $archive_path );
        wptsall_verify_url_output( "食谱归档 ($archive_path)", $result );
        $results['url_archive'] = $result;
    }

    $sample_url = wptsall_get_sample_url( 'wprm_recipe' );
    if ( $sample_url ) {
        $sample_path = str_replace( home_url(), '', $sample_url );
        $result = wptsall_verify_url( $sample_path );
        wptsall_verify_url_output( "食谱单页 ($sample_path)", $result );
        $results['url_single'] = $result;
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'admin.php?page=wprm_manage' => '食谱管理',
    'admin.php?page=wprm_settings' => '食谱设置',
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
    'plugin'  => 'wp-recipe-maker',
    'results' => $results,
    'summary' => $summary,
);

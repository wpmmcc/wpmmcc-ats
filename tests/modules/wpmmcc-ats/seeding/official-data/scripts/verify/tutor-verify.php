<?php
/**
 * Tutor LMS 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Tutor LMS 验证                                              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! function_exists( 'tutor' ) && ! post_type_exists( 'courses' ) ) {
    echo "❌ Tutor LMS 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 0. 页面配置
// =============================================================
echo "--- 0. 页面配置 ---\n";

$tutor_option = get_option( 'tutor_option', array() );

// Dashboard 页面
$dashboard_page_id = isset( $tutor_option['tutor_dashboard_page_id'] ) ? intval( $tutor_option['tutor_dashboard_page_id'] ) : 0;
$page_exists = $dashboard_page_id > 0 && get_post_status( $dashboard_page_id ) === 'publish';
wptsall_verify_output( 'Dashboard 页面', $page_exists, $page_exists ? "ID: $dashboard_page_id" : '未配置' );
$results['page_dashboard'] = array( 'success' => $page_exists, 'page_id' => $dashboard_page_id );

// =============================================================
// 1. 内容数量
// =============================================================
echo "\n--- 1. 内容数量 ---\n";

$results['count_courses'] = wptsall_verify_count_output( 'courses', '课程 (courses)', 3 );
$results['count_lessons'] = wptsall_verify_count_output( 'lesson', '课时 (lesson)', 5 );
$results['count_topics'] = wptsall_verify_count_output( 'topics', '章节 (topics)', 3 );

// 分类
$term_count = wptsall_get_term_count( 'course-category' );
wptsall_verify_output( '课程分类', $term_count >= 0, "$term_count 个" );
$results['count_category'] = array( 'success' => true, 'count' => $term_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 课程归档
$archive_url = wptsall_get_archive_url( 'courses' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "课程归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个课程
$sample_url = wptsall_get_sample_url( 'courses' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "课程单页 ($sample_path)", $result );
    $results['url_course_single'] = $result;
}

// Dashboard 页面
if ( $dashboard_page_id > 0 ) {
    $dashboard_url = get_permalink( $dashboard_page_id );
    if ( $dashboard_url ) {
        $dashboard_path = str_replace( home_url(), '', $dashboard_url );
        $result = wptsall_verify_url( $dashboard_path );
        wptsall_verify_url_output( "仪表盘 ($dashboard_path)", $result );
        $results['url_dashboard'] = $result;
    }
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'admin.php?page=tutor'               => 'Tutor 仪表盘',
    'edit.php?post_type=courses'         => '课程列表',
    'post-new.php?post_type=courses'     => '新建课程',
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
    'plugin'  => 'tutor',
    'results' => $results,
    'summary' => $summary,
);

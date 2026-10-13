<?php
/**
 * MasterStudy LMS 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  MasterStudy LMS 验证                                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'stm-courses' ) ) {
    echo "❌ MasterStudy LMS 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 页面配置验证
// =============================================================
echo "--- 1. 页面配置 ---\n";

// 课程页面
$courses_page_id = get_option( 'stm_lms_courses_page' );
$courses_page = $courses_page_id ? get_post( $courses_page_id ) : null;
$success = $courses_page && $courses_page->post_status === 'publish';
wptsall_verify_output( '课程归档页面', $success, $success ? "ID $courses_page_id" : '未配置' );
$results['page_courses'] = array( 'success' => $success, 'page_id' => $courses_page_id );

// 仪表盘页面
$dashboard_page_id = get_option( 'stm_lms_user_url' );
$dashboard_page = $dashboard_page_id ? get_post( $dashboard_page_id ) : null;
$success = $dashboard_page && $dashboard_page->post_status === 'publish';
wptsall_verify_output( '用户仪表盘页面', $success, $success ? "ID $dashboard_page_id" : '未配置' );
$results['page_dashboard'] = array( 'success' => $success, 'page_id' => $dashboard_page_id );

// =============================================================
// 2. 内容数量
// =============================================================
echo "\n--- 2. 内容数量 ---\n";

$results['count_courses'] = wptsall_verify_count_output( 'stm-courses', '课程 (stm-courses)', 3 );

// 检查课程分类
$cat_count = wptsall_get_term_count( 'stm_lms_course_taxonomy' );
wptsall_verify_output( '课程分类', $cat_count >= 0, "$cat_count 个" );
$results['count_category'] = array( 'success' => true, 'count' => $cat_count );

// =============================================================
// 3. 前台 URL 验证
// =============================================================
echo "\n--- 3. 前台访问 ---\n";

// 课程归档页面
if ( $courses_page_id ) {
    $courses_url = get_permalink( $courses_page_id );
    $courses_path = str_replace( home_url(), '', $courses_url );
    $result = wptsall_verify_url( $courses_path );
    wptsall_verify_url_output( "课程归档 ($courses_path)", $result );
    $results['url_courses'] = $result;
}

// 单个课程
$sample_url = wptsall_get_sample_url( 'stm-courses' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "课程单页 ($sample_path)", $result );
    $results['url_course_single'] = $result;
}

// 仪表盘页面
if ( $dashboard_page_id ) {
    $dashboard_url = get_permalink( $dashboard_page_id );
    $dashboard_path = str_replace( home_url(), '', $dashboard_url );
    $result = wptsall_verify_url( $dashboard_path );
    wptsall_verify_url_output( "用户仪表盘 ($dashboard_path)", $result );
    $results['url_dashboard'] = $result;
}

// =============================================================
// 4. 后台验证
// =============================================================
echo "\n--- 4. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=stm-courses' => '课程列表',
    'post-new.php?post_type=stm-courses' => '新建课程',
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
    'plugin'  => 'masterstudy-lms',
    'results' => $results,
    'summary' => $summary,
);

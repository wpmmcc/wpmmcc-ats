<?php
/**
 * Sensei LMS 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Sensei LMS 验证                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'course' ) || ! class_exists( 'Sensei_Main' ) ) {
    echo "❌ Sensei LMS 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 1. 内容数量
// =============================================================
echo "--- 1. 内容数量 ---\n";

$results['count_courses'] = wptsall_verify_count_output( 'course', '课程 (course)', 1 );
$results['count_lessons'] = wptsall_verify_count_output( 'lesson', '课时 (lesson)', 1 );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 课程归档
$archive_url = get_post_type_archive_link( 'course' );
if ( $archive_url ) {
    $archive_path = str_replace( home_url(), '', $archive_url );
    $result = wptsall_verify_url( $archive_path );
    wptsall_verify_url_output( "课程归档 ($archive_path)", $result );
    $results['url_archive'] = $result;
}

// 单个课程
$sample_url = wptsall_get_sample_url( 'course' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "课程单页 ($sample_path)", $result );
    $results['url_course_single'] = $result;
}

// 单个课时
$lesson_url = wptsall_get_sample_url( 'lesson' );
if ( $lesson_url ) {
    $lesson_path = str_replace( home_url(), '', $lesson_url );
    $result = wptsall_verify_url( $lesson_path );
    wptsall_verify_url_output( "课时单页 ($lesson_path)", $result );
    $results['url_lesson_single'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=course' => '课程列表',
    'edit.php?post_type=lesson' => '课时列表',
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
    'plugin'  => 'sensei-lms',
    'results' => $results,
    'summary' => $summary,
);

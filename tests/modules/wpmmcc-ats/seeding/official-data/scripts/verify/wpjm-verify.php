<?php
/**
 * WP Job Manager 数据填充验证脚本
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  WP Job Manager 验证                                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! post_type_exists( 'job_listing' ) ) {
    echo "❌ WP Job Manager 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

$results = array();

// =============================================================
// 0. 页面配置
// =============================================================
echo "--- 0. 页面配置 ---\n";

$jobs_page_id = get_option( 'job_manager_jobs_page_id', 0 );
$page_exists = $jobs_page_id > 0 && get_post_status( $jobs_page_id ) === 'publish';
wptsall_verify_output( 'Jobs 页面', $page_exists, $page_exists ? "ID: $jobs_page_id" : '未配置' );
$results['page_jobs'] = array( 'success' => $page_exists, 'page_id' => $jobs_page_id );

$submit_page_id = get_option( 'job_manager_submit_job_form_page_id', 0 );
$page_exists = $submit_page_id > 0 && get_post_status( $submit_page_id ) === 'publish';
wptsall_verify_output( 'Submit Job 页面', $page_exists, $page_exists ? "ID: $submit_page_id" : '未配置' );
$results['page_submit'] = array( 'success' => $page_exists, 'page_id' => $submit_page_id );

// =============================================================
// 1. 内容数量
// =============================================================
echo "\n--- 1. 内容数量 ---\n";

$results['count_jobs'] = wptsall_verify_count_output( 'job_listing', '招聘信息 (job_listing)', 3 );

// 分类
$term_count = wptsall_get_term_count( 'job_listing_type' );
wptsall_verify_output( '工作类型', $term_count >= 0, "$term_count 个" );
$results['count_type'] = array( 'success' => true, 'count' => $term_count );

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// Jobs 列表页
if ( $jobs_page_id > 0 ) {
    $jobs_url = get_permalink( $jobs_page_id );
    if ( $jobs_url ) {
        $jobs_path = str_replace( home_url(), '', $jobs_url );
        $result = wptsall_verify_url( $jobs_path );
        wptsall_verify_url_output( "招聘列表 ($jobs_path)", $result );
        $results['url_jobs'] = $result;
    }
}

// 单个招聘信息
$sample_url = wptsall_get_sample_url( 'job_listing' );
if ( $sample_url ) {
    $sample_path = str_replace( home_url(), '', $sample_url );
    $result = wptsall_verify_url( $sample_path );
    wptsall_verify_url_output( "招聘单页 ($sample_path)", $result );
    $results['url_job_single'] = $result;
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'edit.php?post_type=job_listing'     => '招聘列表',
    'post-new.php?post_type=job_listing' => '新建招聘',
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
    'plugin'  => 'wp-job-manager',
    'results' => $results,
    'summary' => $summary,
);

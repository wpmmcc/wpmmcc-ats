<?php
/**
 * WP Job Manager 数据填充后置配置脚本
 *
 * 配置 WP Job Manager 所需的页面：
 * - Jobs 列表页面
 * - Submit Job 表单页面
 * - Job Dashboard 页面
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 检查 WP Job Manager 是否激活
if ( ! post_type_exists( 'job_listing' ) ) {
    echo "  跳过: WP Job Manager 未激活\n";
    return;
}

echo "  配置 WP Job Manager 页面...\n";

$changes = array();

// 1. Jobs 列表页面
$jobs_page_id = get_option( 'job_manager_jobs_page_id', 0 );
if ( empty( $jobs_page_id ) || get_post_status( $jobs_page_id ) !== 'publish' ) {
    // 查找现有页面
    $existing = get_page_by_path( 'jobs' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $jobs_page_id = $existing->ID;
    } else {
        // 创建页面
        $jobs_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Jobs',
                'post_name'    => 'jobs',
                'post_content' => '[jobs]',
                'post_status'  => 'publish',
            )
        );
        if ( $jobs_page_id ) {
            echo "  ✓ 创建 Jobs 页面 (ID: $jobs_page_id)\n";
            $changes[] = 'jobs_page';
        }
    }
    update_option( 'job_manager_jobs_page_id', $jobs_page_id );
} else {
    echo "  已存在 Jobs 页面 (ID: $jobs_page_id)\n";
}

// 2. Submit Job 表单页面
$submit_page_id = get_option( 'job_manager_submit_job_form_page_id', 0 );
if ( empty( $submit_page_id ) || get_post_status( $submit_page_id ) !== 'publish' ) {
    $existing = get_page_by_path( 'post-a-job' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $submit_page_id = $existing->ID;
    } else {
        $submit_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Post a Job',
                'post_name'    => 'post-a-job',
                'post_content' => '[submit_job_form]',
                'post_status'  => 'publish',
            )
        );
        if ( $submit_page_id ) {
            echo "  ✓ 创建 Post a Job 页面 (ID: $submit_page_id)\n";
            $changes[] = 'submit_page';
        }
    }
    update_option( 'job_manager_submit_job_form_page_id', $submit_page_id );
} else {
    echo "  已存在 Post a Job 页面 (ID: $submit_page_id)\n";
}

// 3. Job Dashboard 页面
$dashboard_page_id = get_option( 'job_manager_job_dashboard_page_id', 0 );
if ( empty( $dashboard_page_id ) || get_post_status( $dashboard_page_id ) !== 'publish' ) {
    $existing = get_page_by_path( 'job-dashboard' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $dashboard_page_id = $existing->ID;
    } else {
        $dashboard_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Job Dashboard',
                'post_name'    => 'job-dashboard',
                'post_content' => '[job_dashboard]',
                'post_status'  => 'publish',
            )
        );
        if ( $dashboard_page_id ) {
            echo "  ✓ 创建 Job Dashboard 页面 (ID: $dashboard_page_id)\n";
            $changes[] = 'dashboard_page';
        }
    }
    update_option( 'job_manager_job_dashboard_page_id', $dashboard_page_id );
} else {
    echo "  已存在 Job Dashboard 页面 (ID: $dashboard_page_id)\n";
}

// 刷新永久链接
if ( ! empty( $changes ) ) {
    flush_rewrite_rules();
    echo "  ✓ 永久链接已刷新\n";
}

echo "  WP Job Manager 配置完成\n";

return array(
    'success' => true,
    'changes' => $changes,
);

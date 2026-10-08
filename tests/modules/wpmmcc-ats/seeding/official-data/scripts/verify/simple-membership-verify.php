<?php
/**
 * Simple Membership 数据填充验证脚本
 *
 * Simple Membership 使用自定义表存储会员数据
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Simple Membership 验证                                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! class_exists( 'SwpmSettings' ) && ! defined( 'SIMPLE_WP_MEMBERSHIP_VER' ) ) {
    echo "❌ Simple Membership 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

global $wpdb;
$results = array();

// =============================================================
// 1. 内容数量（自定义表）
// =============================================================
echo "--- 1. 内容数量 ---\n";

// 会员级别
$levels_table = $wpdb->prefix . 'swpm_membership_tbl';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$levels_table'" ) === $levels_table ) {
    $level_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $levels_table" );
    $pass = $level_count >= 1;
    $icon = $pass ? '✓' : '✗';
    echo "  $icon 会员级别: $level_count 个" . ( $pass ? '' : ' (期望 >= 1)' ) . "\n";
    $results['count_levels'] = array( 'success' => $pass, 'count' => $level_count );
} else {
    echo "  ⚠ 会员级别表不存在\n";
    $results['count_levels'] = array( 'success' => false, 'count' => 0 );
}

// 会员用户
$members_table = $wpdb->prefix . 'swpm_members_tbl';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$members_table'" ) === $members_table ) {
    $member_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $members_table" );
    $pass = $member_count >= 0;
    $icon = $pass ? '✓' : '✗';
    echo "  $icon 会员用户: $member_count 个\n";
    $results['count_members'] = array( 'success' => $pass, 'count' => $member_count );
}

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// 登录页
$login_page_id = get_option( 'swpm_settings', array() );
$login_page_id = isset( $login_page_id['login-page-url'] ) ? $login_page_id['login-page-url'] : '';
if ( $login_page_id && is_numeric( $login_page_id ) ) {
    $login_url = get_permalink( $login_page_id );
    if ( $login_url ) {
        $login_path = str_replace( home_url(), '', $login_url );
        $result = wptsall_verify_url( $login_path );
        wptsall_verify_url_output( "会员登录 ($login_path)", $result );
        $results['url_login'] = $result;
    }
} else {
    echo "  ⚠ 会员登录页未配置（正常，可手动配置）\n";
    $results['url_login'] = array( 'success' => true, 'status' => 'not_configured' );
}

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'admin.php?page=simple_wp_membership' => '会员管理',
    'admin.php?page=simple_wp_membership_levels' => '会员级别',
    'admin.php?page=simple_wp_membership_settings' => '会员设置',
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
    'plugin'  => 'simple-membership',
    'results' => $results,
    'summary' => $summary,
);

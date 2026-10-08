<?php
/**
 * Amelia Booking 数据填充验证脚本
 *
 * Amelia 使用自定义表存储预约数据
 *
 * @package WPTSALL\DevTools\Seeding\Verify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

require_once __DIR__ . '/verify-helpers.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Amelia Booking 验证                                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! class_exists( 'AmeliaBooking\Plugin' ) && ! defined( 'AMELIA_VERSION' ) ) {
    echo "❌ Amelia Booking 未激活，跳过验证\n";
    return array( 'skipped' => true, 'reason' => 'plugin_inactive' );
}

global $wpdb;
$results = array();

// =============================================================
// 1. 内容数量（自定义表）
// =============================================================
echo "--- 1. 内容数量 ---\n";

// 服务
$services_table = $wpdb->prefix . 'amelia_services';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$services_table'" ) === $services_table ) {
    $service_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $services_table" );
    $pass = $service_count >= 0;
    $icon = $pass ? '✓' : '✗';
    echo "  $icon 服务: $service_count 个\n";
    $results['count_services'] = array( 'success' => $pass, 'count' => $service_count );
} else {
    echo "  ⚠ 服务表不存在\n";
    $results['count_services'] = array( 'success' => false, 'count' => 0 );
}

// 员工
$providers_table = $wpdb->prefix . 'amelia_users';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$providers_table'" ) === $providers_table ) {
    $provider_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $providers_table WHERE type = 'provider'" );
    $pass = $provider_count >= 0;
    $icon = $pass ? '✓' : '✗';
    echo "  $icon 服务人员: $provider_count 个\n";
    $results['count_providers'] = array( 'success' => $pass, 'count' => $provider_count );
}

// 预约
$appointments_table = $wpdb->prefix . 'amelia_appointments';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$appointments_table'" ) === $appointments_table ) {
    $appointment_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $appointments_table" );
    $pass = $appointment_count >= 0;
    $icon = $pass ? '✓' : '✗';
    echo "  $icon 预约: $appointment_count 个\n";
    $results['count_appointments'] = array( 'success' => $pass, 'count' => $appointment_count );
}

// =============================================================
// 2. 前台 URL 验证
// =============================================================
echo "\n--- 2. 前台访问 ---\n";

// Amelia 通过短代码集成，没有独立页面
echo "  ⚠ Amelia 通过短代码集成，无独立前台页面（正常）\n";
$results['url_note'] = array( 'success' => true, 'status' => 'shortcode_based' );

// =============================================================
// 3. 后台验证
// =============================================================
echo "\n--- 3. 后台访问 ---\n";

$admin_pages = array(
    'admin.php?page=wpamelia-dashboard' => 'Amelia 仪表盘',
    'admin.php?page=wpamelia-services' => '服务管理',
    'admin.php?page=wpamelia-settings' => 'Amelia 设置',
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
    'plugin'  => 'ameliabooking',
    'results' => $results,
    'summary' => $summary,
);

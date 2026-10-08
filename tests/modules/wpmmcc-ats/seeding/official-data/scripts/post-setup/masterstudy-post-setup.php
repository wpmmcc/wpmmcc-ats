<?php
/**
 * MasterStudy LMS 数据填充后置配置脚本
 *
 * 在填充 MasterStudy 数据后执行，配置必要的页面和设置
 *
 * 执行方式:
 *   wp eval-file masterstudy-post-setup.php
 *
 * 配置内容:
 *   1. 创建/配置课程归档页面
 *   2. 配置 post_type 的 rewrite 规则
 *   3. 配置用户仪表盘页面
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  MasterStudy LMS 后置配置                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查 MasterStudy 是否激活
if ( ! defined( 'STM_LMS_PATH' ) && ! class_exists( 'STM_LMS' ) ) {
    // 尝试检查 post_type 是否存在
    if ( ! post_type_exists( 'stm-courses' ) ) {
        echo "❌ MasterStudy LMS 未激活，跳过配置\n";
        return;
    }
}

$changes = array();

// =============================================================
// 1. 课程归档页面
// =============================================================
echo "--- 1. 课程归档页面 ---\n";

// MasterStudy 通常使用 option 'stm_lms_courses_page' 存储归档页面 ID
$courses_page_id = get_option( 'stm_lms_courses_page' );
$courses_page = $courses_page_id ? get_post( $courses_page_id ) : null;

if ( ! $courses_page || $courses_page->post_status !== 'publish' ) {
    // 查找是否已存在 courses 页面
    $existing = get_page_by_path( 'stm-courses' );
    if ( ! $existing ) {
        $existing = get_page_by_path( 'courses' );
    }

    if ( $existing ) {
        $courses_page_id = $existing->ID;
        echo "  找到已存在的课程页面: ID $courses_page_id\n";
    } else {
        // 创建新页面
        $courses_page_id = wp_insert_post( array(
            'post_type'    => 'page',
            'post_title'   => 'All Courses',
            'post_name'    => 'stm-courses',
            'post_status'  => 'publish',
            'post_content' => '<!-- MasterStudy LMS Courses Archive -->
[stm_lms_courses_carousel]',
        ) );
        echo "  ✓ 创建课程归档页面: ID $courses_page_id\n";
        $changes[] = 'created_courses_page';
    }

    update_option( 'stm_lms_courses_page', $courses_page_id );
    echo "  ✓ 配置 stm_lms_courses_page = $courses_page_id\n";
    $changes[] = 'configured_courses_page';
} else {
    echo "  课程页面已配置: ID $courses_page_id\n";
}

// =============================================================
// 2. 用户仪表盘页面
// =============================================================
echo "\n--- 2. 用户仪表盘页面 ---\n";

$dashboard_page_id = get_option( 'stm_lms_user_url' );
$dashboard_page = $dashboard_page_id ? get_post( $dashboard_page_id ) : null;

if ( ! $dashboard_page || $dashboard_page->post_status !== 'publish' ) {
    $existing = get_page_by_path( 'dashboard' );
    if ( ! $existing ) {
        $existing = get_page_by_path( 'lms-dashboard' );
    }

    if ( $existing ) {
        $dashboard_page_id = $existing->ID;
        echo "  找到已存在的仪表盘页面: ID $dashboard_page_id\n";
    } else {
        $dashboard_page_id = wp_insert_post( array(
            'post_type'    => 'page',
            'post_title'   => 'Dashboard',
            'post_name'    => 'lms-dashboard',
            'post_status'  => 'publish',
            'post_content' => '[stm_lms_dashboard]',
        ) );
        echo "  ✓ 创建仪表盘页面: ID $dashboard_page_id\n";
        $changes[] = 'created_dashboard_page';
    }

    update_option( 'stm_lms_user_url', $dashboard_page_id );
    echo "  ✓ 配置 stm_lms_user_url = $dashboard_page_id\n";
    $changes[] = 'configured_dashboard_page';
} else {
    echo "  仪表盘页面已配置: ID $dashboard_page_id\n";
}

// =============================================================
// 3. 配置 post_type rewrite（如果需要）
// =============================================================
echo "\n--- 3. Rewrite 规则 ---\n";

// MasterStudy 使用 'stm-courses' 作为 post_type，默认 rewrite 为 /stm-courses/
// 如果需要自定义 URL，可以在这里配置
$rewrite_slug = get_option( 'stm_lms_courses_slug', 'stm-courses' );
echo "  当前课程 URL 前缀: /$rewrite_slug/\n";

// 检查是否需要更新 rewrite 规则
$pt_object = get_post_type_object( 'stm-courses' );
if ( $pt_object ) {
    echo "  post_type 'stm-courses' 已注册\n";
    if ( isset( $pt_object->rewrite['slug'] ) ) {
        echo "  rewrite slug: " . $pt_object->rewrite['slug'] . "\n";
    }
}

// =============================================================
// 4. 刷新永久链接
// =============================================================
echo "\n--- 4. 刷新永久链接 ---\n";
flush_rewrite_rules();
echo "  ✓ 永久链接已刷新\n";

// =============================================================
// 完成
// =============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  配置完成                                                    ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";

if ( empty( $changes ) ) {
    echo "║  无需更改，所有配置已就绪                                    ║\n";
} else {
    echo "║  执行了 " . count( $changes ) . " 项更改                                            ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 验证
echo "--- 验证 ---\n";
$courses_page_id = get_option( 'stm_lms_courses_page' );
if ( $courses_page_id ) {
    echo "Courses: " . get_permalink( $courses_page_id ) . "\n";
}
$dashboard_page_id = get_option( 'stm_lms_user_url' );
if ( $dashboard_page_id ) {
    echo "Dashboard: " . get_permalink( $dashboard_page_id ) . "\n";
}

// 列出一个课程的 URL
$sample_course = get_posts( array(
    'post_type'      => 'stm-courses',
    'post_status'    => 'publish',
    'posts_per_page' => 1,
) );
if ( ! empty( $sample_course ) ) {
    echo "Sample course: " . get_permalink( $sample_course[0]->ID ) . "\n";
}

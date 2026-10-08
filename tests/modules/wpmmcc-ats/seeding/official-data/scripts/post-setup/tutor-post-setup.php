<?php
/**
 * Tutor LMS 数据填充后置配置脚本
 *
 * 配置 Tutor LMS 所需的页面：
 * - Dashboard 页面（学员仪表盘）
 * - 学生注册页面
 * - 讲师注册页面
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 检查 Tutor LMS 是否激活
if ( ! function_exists( 'tutor' ) && ! post_type_exists( 'courses' ) ) {
    echo "  跳过: Tutor LMS 未激活\n";
    return;
}

echo "  配置 Tutor LMS 页面...\n";

$changes = array();

// 获取当前设置
$tutor_option = get_option( 'tutor_option', array() );

// 1. Dashboard 页面
$dashboard_page_id = isset( $tutor_option['tutor_dashboard_page_id'] ) ? intval( $tutor_option['tutor_dashboard_page_id'] ) : 0;
if ( $dashboard_page_id <= 0 || get_post_status( $dashboard_page_id ) !== 'publish' ) {
    // 查找现有页面
    $existing = get_page_by_path( 'dashboard' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $dashboard_page_id = $existing->ID;
    } else {
        // 创建页面
        $dashboard_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Dashboard',
                'post_name'    => 'dashboard',
                'post_content' => '[tutor_dashboard]',
                'post_status'  => 'publish',
            )
        );
        if ( $dashboard_page_id ) {
            echo "  ✓ 创建 Dashboard 页面 (ID: $dashboard_page_id)\n";
            $changes[] = 'dashboard_page';
        }
    }
    $tutor_option['tutor_dashboard_page_id'] = $dashboard_page_id;
} else {
    echo "  已存在 Dashboard 页面 (ID: $dashboard_page_id)\n";
}

// 2. 学生注册页面
$student_register_page_id = isset( $tutor_option['student_register_page'] ) ? intval( $tutor_option['student_register_page'] ) : 0;
if ( $student_register_page_id <= 0 || get_post_status( $student_register_page_id ) !== 'publish' ) {
    $existing = get_page_by_path( 'student-registration' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $student_register_page_id = $existing->ID;
    } else {
        $student_register_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Student Registration',
                'post_name'    => 'student-registration',
                'post_content' => '[tutor_student_registration_form]',
                'post_status'  => 'publish',
            )
        );
        if ( $student_register_page_id ) {
            echo "  ✓ 创建 Student Registration 页面 (ID: $student_register_page_id)\n";
            $changes[] = 'student_register_page';
        }
    }
    $tutor_option['student_register_page'] = $student_register_page_id;
} else {
    echo "  已存在 Student Registration 页面 (ID: $student_register_page_id)\n";
}

// 3. 讲师注册页面
$instructor_register_page_id = isset( $tutor_option['instructor_register_page'] ) ? intval( $tutor_option['instructor_register_page'] ) : 0;
if ( $instructor_register_page_id <= 0 || get_post_status( $instructor_register_page_id ) !== 'publish' ) {
    $existing = get_page_by_path( 'instructor-registration' );
    if ( $existing && $existing->post_status === 'publish' ) {
        $instructor_register_page_id = $existing->ID;
    } else {
        $instructor_register_page_id = wp_insert_post(
            array(
                'post_type'    => 'page',
                'post_title'   => 'Instructor Registration',
                'post_name'    => 'instructor-registration',
                'post_content' => '[tutor_instructor_registration_form]',
                'post_status'  => 'publish',
            )
        );
        if ( $instructor_register_page_id ) {
            echo "  ✓ 创建 Instructor Registration 页面 (ID: $instructor_register_page_id)\n";
            $changes[] = 'instructor_register_page';
        }
    }
    $tutor_option['instructor_register_page'] = $instructor_register_page_id;
} else {
    echo "  已存在 Instructor Registration 页面 (ID: $instructor_register_page_id)\n";
}

// 保存设置
if ( ! empty( $changes ) ) {
    update_option( 'tutor_option', $tutor_option );
    echo "  ✓ 设置已保存\n";

    // 刷新永久链接
    flush_rewrite_rules();
    echo "  ✓ 永久链接已刷新\n";
}

echo "  Tutor LMS 配置完成\n";

return array(
    'success' => true,
    'changes' => $changes,
);

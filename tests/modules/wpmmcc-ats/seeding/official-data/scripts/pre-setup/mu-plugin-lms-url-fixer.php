<?php
/**
 * LMS URL 冲突修复器 - mu-plugin 版本
 *
 * 此文件需要复制到 wp-content/mu-plugins/ 目录
 * mu-plugin 在所有其他插件之前加载，可以修改 post_type 注册参数
 *
 * @package WPTSALL\DevTools\Seeding\PreSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 修改 LMS 插件的 post_type rewrite 参数
 *
 * 为避免多个 LMS 插件使用相同的 URL slug，强制使用唯一前缀：
 * - MasterStudy: /stm-course/
 * - Academy: /academy-course/
 * - LearnPress: /lp-course/
 * - LifterLMS: /llms-course/
 * - Tutor: /tutor-course/
 */
add_filter( 'register_post_type_args', 'wptsall_fix_lms_post_type_rewrite', 10, 2 );

function wptsall_fix_lms_post_type_rewrite( $args, $post_type ) {
    // MasterStudy LMS
    if ( $post_type === 'stm-courses' ) {
        $args['rewrite'] = array(
            'slug'       => 'stm-course',
            'with_front' => false,
            'pages'      => true,
            'feeds'      => false,
        );
        $args['has_archive'] = 'stm-courses';
    }

    // Academy LMS
    if ( $post_type === 'academy_courses' ) {
        $args['rewrite'] = array(
            'slug'       => 'academy-course',
            'with_front' => false,
        );
        // 保持 has_archive 为 courses，因为 Academy 的归档页是专用的
    }

    // LearnPress
    if ( $post_type === 'lp_course' ) {
        $args['rewrite'] = array(
            'slug'       => 'lp-course',
            'with_front' => false,
        );
        $args['has_archive'] = 'lp-courses';
    }

    // Tutor LMS (post_type 是 'courses')
    if ( $post_type === 'courses' && class_exists( 'TUTOR\Tutor' ) ) {
        $args['rewrite'] = array(
            'slug'       => 'tutor-course',
            'with_front' => false,
        );
        $args['has_archive'] = 'tutor-courses';
    }

    // LifterLMS (post_type 是 'course')
    if ( $post_type === 'course' && class_exists( 'LifterLMS' ) ) {
        $args['rewrite'] = array(
            'slug'       => 'llms-course',
            'with_front' => false,
        );
        $args['has_archive'] = 'llms-courses';
    }

    // Sensei LMS (post_type 也是 'course')
    if ( $post_type === 'course' && class_exists( 'Sensei_Main' ) ) {
        $args['rewrite'] = array(
            'slug'       => 'sensei-course',
            'with_front' => false,
        );
        $args['has_archive'] = 'sensei-courses';
    }

    return $args;
}

/**
 * 在插件激活后刷新永久链接
 * 通过设置一个 transient 标记，在下次页面加载时刷新
 */
add_action( 'activated_plugin', 'wptsall_schedule_rewrite_flush' );

function wptsall_schedule_rewrite_flush() {
    set_transient( 'wptsall_flush_rewrite_rules', true, 60 );
}

add_action( 'init', 'wptsall_maybe_flush_rewrite_rules', 999 );

function wptsall_maybe_flush_rewrite_rules() {
    if ( get_transient( 'wptsall_flush_rewrite_rules' ) ) {
        flush_rewrite_rules();
        delete_transient( 'wptsall_flush_rewrite_rules' );
    }
}

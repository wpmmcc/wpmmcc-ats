<?php
/**
 * LMS 插件 URL 冲突解决脚本（填充前执行）
 *
 * 问题：多个 LMS 插件使用相同的 URL slug（courses）导致冲突
 * - LearnPress: /courses/
 * - Academy LMS: /courses/ (archive), /course/ (single)
 * - MasterStudy: /courses/
 *
 * 解决方案：安装 mu-plugin 强制修改 post_type 的 rewrite slug
 *
 * 执行方式:
 *   wp eval-file lms-url-conflict-resolver.php
 *
 * 执行时机: 激活插件后、填充数据前
 *
 * @package WPTSALL\DevTools\Seeding\PreSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  LMS URL 冲突解决器（填充前）                                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

$changes = array();

// =============================================================
// 0. 安装 mu-plugin（最可靠的方式）
// =============================================================
echo "--- 0. 安装 mu-plugin ---\n";

$mu_plugins_dir = WP_CONTENT_DIR . '/mu-plugins';
$mu_plugin_source = __DIR__ . '/mu-plugin-lms-url-fixer.php';
$mu_plugin_target = $mu_plugins_dir . '/lms-url-fixer.php';

// 确保 mu-plugins 目录存在
if ( ! is_dir( $mu_plugins_dir ) ) {
    mkdir( $mu_plugins_dir, 0755, true );
    echo "  ✓ 创建 mu-plugins 目录\n";
}

// 复制 mu-plugin
if ( file_exists( $mu_plugin_source ) ) {
    if ( ! file_exists( $mu_plugin_target ) || md5_file( $mu_plugin_source ) !== md5_file( $mu_plugin_target ) ) {
        copy( $mu_plugin_source, $mu_plugin_target );
        echo "  ✓ 安装 mu-plugin: lms-url-fixer.php\n";
        $changes[] = 'installed_mu_plugin';

        // 设置 transient 以便在下次请求时刷新 rewrite rules
        set_transient( 'wptsall_flush_rewrite_rules', true, 60 );
    } else {
        echo "  mu-plugin 已是最新版本\n";
    }
} else {
    echo "  ⚠️ 未找到 mu-plugin 源文件\n";
}

// =============================================================
// URL Slug 配置方案
// =============================================================
// 为避免冲突，每个 LMS 使用独特的 slug：
// - Academy LMS:    /academy-course/xxx/  (单页), /academy-courses/ (归档)
// - MasterStudy:    /stm-course/xxx/      (单页), /stm-courses/     (归档)
// - LearnPress:     /lp-course/xxx/       (单页), /lp-courses/      (归档)
// - LifterLMS:      /llms-course/xxx/     (单页), /llms-courses/    (归档)
// - Tutor LMS:      /tutor-course/xxx/    (单页), /tutor-courses/   (归档)
// - Sensei:         /sensei-course/xxx/   (单页), /sensei-courses/  (归档)

// =============================================================
// 1. MasterStudy LMS
// =============================================================
echo "--- 1. MasterStudy LMS ---\n";

if ( post_type_exists( 'stm-courses' ) || class_exists( 'STM_LMS' ) || defined( 'STM_LMS_PATH' ) ) {
    // MasterStudy 使用 stm_lms_courses_slug option
    $current_slug = get_option( 'stm_lms_courses_slug', 'courses' );

    if ( $current_slug === 'courses' ) {
        update_option( 'stm_lms_courses_slug', 'stm-course' );
        echo "  ✓ 修改 stm_lms_courses_slug: courses -> stm-course\n";
        $changes[] = 'masterstudy_slug';
    } else {
        echo "  已配置为: $current_slug\n";
    }
} else {
    echo "  插件未激活，跳过\n";
}

// =============================================================
// 2. Academy LMS
// =============================================================
echo "\n--- 2. Academy LMS ---\n";

if ( post_type_exists( 'academy_courses' ) || function_exists( 'academy' ) ) {
    // Academy 使用 settings option (JSON 格式)
    // 注意: update_option 会触发 Academy 的钩子导致脚本挂起，使用直接 SQL 更新
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'academy_settings'
        )
    );

    if ( $row ) {
        $academy_settings = json_decode( $row->option_value, true );
        $current_slug = isset( $academy_settings['course_permalink_base'] )
            ? $academy_settings['course_permalink_base']
            : 'course';

        if ( $current_slug === 'course' ) {
            $academy_settings['course_permalink_base'] = 'academy-course';
            $new_value = wp_json_encode( $academy_settings );

            // 直接 SQL 更新，绕过 update_option 的钩子
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                    $new_value,
                    'academy_settings'
                )
            );

            // 清除缓存
            wp_cache_delete( 'academy_settings', 'options' );
            wp_cache_delete( 'alloptions', 'options' );

            echo "  ✓ 修改 course_permalink_base: course -> academy-course\n";
            $changes[] = 'academy_slug';
        } else {
            echo "  已配置为: $current_slug\n";
        }
    } else {
        echo "  设置不存在，跳过\n";
    }
} else {
    echo "  插件未激活，跳过\n";
}

// =============================================================
// 3. LearnPress
// =============================================================
echo "\n--- 3. LearnPress ---\n";

if ( post_type_exists( 'lp_course' ) || class_exists( 'LearnPress' ) ) {
    // LearnPress 使用 learn_press_course_base_slug option
    $current_slug = get_option( 'learn_press_course_base_slug', 'courses' );

    if ( $current_slug === 'courses' ) {
        update_option( 'learn_press_course_base_slug', 'lp-course' );
        echo "  ✓ 修改 learn_press_course_base_slug: courses -> lp-course\n";
        $changes[] = 'learnpress_slug';
    } else {
        echo "  已配置为: $current_slug\n";
    }
} else {
    echo "  插件未激活，跳过\n";
}

// =============================================================
// 4. LifterLMS
// =============================================================
echo "\n--- 4. LifterLMS ---\n";

if ( post_type_exists( 'course' ) && class_exists( 'LifterLMS' ) ) {
    // LifterLMS 使用 lifterlms_course_slug option
    $current_slug = get_option( 'lifterlms_course_slug', 'course' );

    if ( $current_slug === 'course' ) {
        update_option( 'lifterlms_course_slug', 'llms-course' );
        echo "  ✓ 修改 lifterlms_course_slug: course -> llms-course\n";
        $changes[] = 'lifterlms_slug';
    } else {
        echo "  已配置为: $current_slug\n";
    }
} else {
    echo "  插件未激活，跳过\n";
}

// =============================================================
// 5. Tutor LMS
// =============================================================
echo "\n--- 5. Tutor LMS ---\n";

if ( post_type_exists( 'courses' ) && function_exists( 'tutor' ) ) {
    // Tutor 使用 tutor_option 中的 course_permalink_base
    $tutor_settings = get_option( 'tutor_option', array() );
    $current_slug = isset( $tutor_settings['course_permalink_base'] )
        ? $tutor_settings['course_permalink_base']
        : 'courses';

    if ( $current_slug === 'courses' ) {
        $tutor_settings['course_permalink_base'] = 'tutor-course';
        update_option( 'tutor_option', $tutor_settings );
        echo "  ✓ 修改 course_permalink_base: courses -> tutor-course\n";
        $changes[] = 'tutor_slug';
    } else {
        echo "  已配置为: $current_slug\n";
    }
} else {
    echo "  插件未激活，跳过\n";
}

// =============================================================
// 6. Sensei LMS
// =============================================================
echo "\n--- 6. Sensei LMS ---\n";

if ( post_type_exists( 'course' ) && class_exists( 'Sensei_Main' ) ) {
    // Sensei 使用 sensei_settings 中的 course_slug
    $sensei_settings = get_option( 'sensei-settings', array() );
    $current_slug = isset( $sensei_settings['course_slug'] )
        ? $sensei_settings['course_slug']
        : 'course';

    if ( $current_slug === 'course' ) {
        $sensei_settings['course_slug'] = 'sensei-course';
        update_option( 'sensei-settings', $sensei_settings );
        echo "  ✓ 修改 course_slug: course -> sensei-course\n";
        $changes[] = 'sensei_slug';
    } else {
        echo "  已配置为: $current_slug\n";
    }
} else {
    echo "  插件未激活，跳过\n";
}

// =============================================================
// 7. 刷新永久链接
// =============================================================
echo "\n--- 7. 刷新永久链接 ---\n";

if ( ! empty( $changes ) ) {
    flush_rewrite_rules( true );
    echo "  ✓ 永久链接已刷新（强制重建）\n";
} else {
    echo "  无需刷新\n";
}

// =============================================================
// 完成
// =============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  冲突解决完成                                                ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";

if ( empty( $changes ) ) {
    echo "║  无需更改，所有 URL slug 已配置为唯一值                      ║\n";
} else {
    printf( "║  修改了 %d 个插件的 URL slug                                 ║\n", count( $changes ) );
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 验证当前配置
echo "--- 当前 URL 配置 ---\n";

$lms_plugins = array(
    'stm-courses'     => 'MasterStudy',
    'academy_courses' => 'Academy',
    'lp_course'       => 'LearnPress',
    'course'          => 'LifterLMS/Sensei',
    'courses'         => 'Tutor',
);

foreach ( $lms_plugins as $post_type => $name ) {
    $pt = get_post_type_object( $post_type );
    if ( $pt ) {
        $slug = isset( $pt->rewrite['slug'] ) ? $pt->rewrite['slug'] : '(none)';
        $archive = $pt->has_archive ? ( is_string( $pt->has_archive ) ? $pt->has_archive : 'true' ) : 'false';
        echo "  $name ($post_type): single=/$slug/xxx/, archive=$archive\n";
    }
}

echo "\n";
echo "注意：修改 URL slug 后，已有内容的 URL 会变化。\n";
echo "请在填充新数据前执行此脚本。\n";

return array(
    'changes' => $changes,
    'success' => true,
);

<?php
/**
 * Post-Setup 调度器
 *
 * 根据计划 ID 执行所有相关的后置配置脚本
 *
 * 执行方式:
 *   wp eval-file dispatcher.php A
 *
 * @package WPTSALL\DevTools\Seeding\PostSetup
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// wp eval-file 通过 $args 数组传递参数
$args = $args ?? array();

// 解析计划 ID（默认 A）
$plan_id = 'A';
foreach ( $args as $arg ) {
    if ( preg_match( '/^[A-E]$/i', $arg ) ) {
        $plan_id = strtoupper( $arg );
        break;
    }
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Post-Setup 调度器                                           ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  计划: $plan_id                                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 后置配置脚本目录
$post_setup_dir = __DIR__;

// 计划与插件的映射关系
// 基于 seeding-config.json 中的 official_data 和 custom_seeding 配置
$plan_plugins = array(
    'A' => array(
        // Official data plugins
        'woocommerce',
        'bbpress',
        'easy-digital-downloads',
        'buddypress',
        // Custom seeding plugins
        'academy',
        'masterstudy-lms-learning-management-system',
        'classified-listing',
        'easy-property-listings',
        'cooked',
        'envira-gallery-lite',
        'site-reviews',
        'testimonial-free',
    ),
    'B' => array(
        'tutor',
        'directorist',
        'the-events-calendar',
        'wp-job-manager',
        'essential-real-estate',
        'asgaros-forum',
        'delicious-recipes',
        'foogallery',
        'portfolio-post-type',
        'seriously-simple-podcasting',
    ),
    'C' => array(
        'sensei-lms',
        'wp-easycart',
        'wpforo',
        'estatik',
        'wp-recipe-maker',
        'simple-job-board',
        'ultimate-faqs',
        'wp-customer-reviews',
    ),
    'D' => array(
        'learnpress',
        'lifterlms',
        'storeengine',
        'hivepress',
        'geodirectory',
        'forumwp',
        'propertyhive',
        'events-manager',
        'wp-job-openings',
        'give',
        'podlove-podcasting-plugin-for-wordpress',
    ),
    'E' => array(
        'wordpress-core',  // WordPress 核心无需后置配置
    ),
);

if ( ! isset( $plan_plugins[ $plan_id ] ) ) {
    echo "❌ 未知的计划 ID: $plan_id\n";
    echo "可用计划: A, B, C, D\n";
    return;
}

$plugins = $plan_plugins[ $plan_id ];
$executed = array();
$skipped = array();

echo "检测到 " . count( $plugins ) . " 个插件需要检查后置配置...\n\n";

foreach ( $plugins as $plugin_slug ) {
    // 尝试多种命名格式查找脚本
    $script_names = array(
        $plugin_slug . '-post-setup.php',
        // 处理带有长名称的插件
        preg_replace( '/-lms-.*$/', '', $plugin_slug ) . '-post-setup.php',
    );

    // 特殊映射
    $slug_mappings = array(
        'masterstudy-lms-learning-management-system' => 'masterstudy-post-setup.php',
        'easy-digital-downloads'                      => 'edd-post-setup.php',
        'classified-listing'                          => 'classified-listing-post-setup.php',
        'easy-property-listings'                      => 'epl-post-setup.php',
        'envira-gallery-lite'                         => 'envira-post-setup.php',
        'site-reviews'                                => 'site-reviews-post-setup.php',
        'testimonial-free'                            => 'testimonial-post-setup.php',
        'the-events-calendar'                         => 'events-calendar-post-setup.php',
        'wp-job-manager'                              => 'wpjm-post-setup.php',
        'essential-real-estate'                       => 'ere-post-setup.php',
        'asgaros-forum'                               => 'asgaros-post-setup.php',
        'delicious-recipes'                           => 'delicious-recipes-post-setup.php',
        'portfolio-post-type'                         => 'portfolio-post-setup.php',
        'seriously-simple-podcasting'                 => 'ssp-post-setup.php',
        'wp-recipe-maker'                             => 'wprm-post-setup.php',
        'simple-job-board'                            => 'sjb-post-setup.php',
        'ultimate-faqs'                               => 'ufaq-post-setup.php',
        'wp-customer-reviews'                         => 'wpcr-post-setup.php',
        'events-manager'                              => 'events-manager-post-setup.php',
        'wp-job-openings'                             => 'wpjo-post-setup.php',
        'podlove-podcasting-plugin-for-wordpress'     => 'podlove-post-setup.php',
    );

    if ( isset( $slug_mappings[ $plugin_slug ] ) ) {
        array_unshift( $script_names, $slug_mappings[ $plugin_slug ] );
    }

    $script_found = false;

    foreach ( $script_names as $script_name ) {
        $script_path = $post_setup_dir . '/' . $script_name;

        if ( file_exists( $script_path ) ) {
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "执行: $script_name\n";
            echo "═══════════════════════════════════════════════════════════════\n";

            include $script_path;

            $executed[] = $plugin_slug;
            $script_found = true;
            echo "\n";
            break;
        }
    }

    if ( ! $script_found ) {
        $skipped[] = $plugin_slug;
    }
}

// 总结
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  调度完成                                                    ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf( "║  执行了 %d 个后置配置脚本                                     ║\n", count( $executed ) );
printf( "║  跳过了 %d 个插件（无对应脚本）                              ║\n", count( $skipped ) );
echo "╚══════════════════════════════════════════════════════════════╝\n";

if ( ! empty( $executed ) ) {
    echo "\n已执行:\n";
    foreach ( $executed as $slug ) {
        echo "  ✓ $slug\n";
    }
}

if ( ! empty( $skipped ) ) {
    echo "\n已跳过（无后置配置脚本或不需要配置）:\n";
    foreach ( $skipped as $slug ) {
        echo "  - $slug\n";
    }
}

echo "\n";

return array(
    'success'  => true,
    'executed' => count( $executed ),
    'skipped'  => count( $skipped ),
    'plugins'  => $executed,
);

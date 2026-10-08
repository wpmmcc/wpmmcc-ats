<?php
/**
 * 验证脚本调度器
 *
 * 根据计划 ID 执行所有相关的验证脚本
 *
 * 执行方式:
 *   wp eval-file dispatcher.php A
 *
 * @package WPTSALL\DevTools\Seeding\Verify
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
echo "║  数据填充验证调度器                                          ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  计划: $plan_id                                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 验证脚本目录
$verify_dir = __DIR__;

// 计划与插件的映射关系（与 post-setup dispatcher 保持一致）
$plan_plugins = array(
    'A' => array(
        'woocommerce',
        'bbpress',
        'easy-digital-downloads',
        'academy',
        'masterstudy-lms-learning-management-system',
        'classified-listing',
        'easy-property-listings',
        'cooked',
        'envira-gallery-lite',
        'site-reviews',
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
        'wordpress-core',
    ),
);

if ( ! isset( $plan_plugins[ $plan_id ] ) ) {
    echo "未知的计划 ID: $plan_id\n";
    echo "可用计划: A, B, C, D\n";
    return;
}

// 插件 slug 到验证脚本的映射
$slug_to_script = array(
    'woocommerce'                              => 'woocommerce-verify.php',
    'bbpress'                                  => 'bbpress-verify.php',
    'easy-digital-downloads'                   => 'edd-verify.php',
    'academy'                                  => 'academy-verify.php',
    'masterstudy-lms-learning-management-system' => 'masterstudy-verify.php',
    'classified-listing'                       => 'classified-listing-verify.php',
    'easy-property-listings'                   => 'easy-property-listings-verify.php',
    'cooked'                                   => 'cooked-verify.php',
    'envira-gallery-lite'                      => 'envira-verify.php',
    'site-reviews'                             => 'site-reviews-verify.php',
    // Plan B
    'tutor'                                    => 'tutor-verify.php',
    'directorist'                              => 'directorist-verify.php',
    'the-events-calendar'                      => 'events-calendar-verify.php',
    'wp-job-manager'                           => 'wpjm-verify.php',
    'essential-real-estate'                    => 'ere-verify.php',
    'asgaros-forum'                            => 'asgaros-verify.php',
    'delicious-recipes'                        => 'delicious-recipes-verify.php',
    'foogallery'                               => 'foogallery-verify.php',
    'portfolio-post-type'                      => 'portfolio-verify.php',
    'seriously-simple-podcasting'              => 'ssp-verify.php',
    // Plan C
    'sensei-lms'                               => 'sensei-verify.php',
    'wp-easycart'                              => 'easycart-verify.php',
    'wpforo'                                   => 'wpforo-verify.php',
    'estatik'                                  => 'estatik-verify.php',
    'wp-recipe-maker'                          => 'wprm-verify.php',
    'simple-job-board'                         => 'sjb-verify.php',
    'ultimate-faqs'                            => 'ufaq-verify.php',
    'wp-customer-reviews'                      => 'wpcr-verify.php',
    // Plan D
    'learnpress'                               => 'learnpress-verify.php',
    'lifterlms'                                => 'lifterlms-verify.php',
    'storeengine'                              => 'storeengine-verify.php',
    'hivepress'                                => 'hivepress-verify.php',
    'geodirectory'                             => 'geodirectory-verify.php',
    'forumwp'                                  => 'forumwp-verify.php',
    'propertyhive'                             => 'propertyhive-verify.php',
    'events-manager'                           => 'events-manager-verify.php',
    'wp-job-openings'                          => 'wpjo-verify.php',
    'give'                                     => 'give-verify.php',
    'podlove-podcasting-plugin-for-wordpress'  => 'podlove-verify.php',
    // Plan E
    'wordpress-core'                           => 'wordpress-core-verify.php',
);

$plugins = $plan_plugins[ $plan_id ];
$all_results = array();
$executed = 0;
$skipped = 0;
$total_passed = 0;
$total_tests = 0;

echo "检测到 " . count( $plugins ) . " 个插件需要验证...\n\n";

foreach ( $plugins as $plugin_slug ) {
    $script_name = isset( $slug_to_script[ $plugin_slug ] )
        ? $slug_to_script[ $plugin_slug ]
        : $plugin_slug . '-verify.php';

    $script_path = $verify_dir . '/' . $script_name;

    if ( file_exists( $script_path ) ) {
        echo "═══════════════════════════════════════════════════════════════\n";

        $result = include $script_path;

        if ( is_array( $result ) ) {
            $all_results[ $plugin_slug ] = $result;

            if ( isset( $result['skipped'] ) && $result['skipped'] ) {
                $skipped++;
            } else {
                $executed++;
                if ( isset( $result['summary'] ) ) {
                    $total_passed += $result['summary']['passed'];
                    $total_tests += $result['summary']['total'];
                }
            }
        }
        echo "\n";
    } else {
        echo "⚠️ 未找到验证脚本: $script_name ($plugin_slug)\n";
        $skipped++;
    }
}

// =============================================================
// 总体汇总
// =============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  验证调度完成                                                ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf( "║  已执行: %d 个插件                                           ║\n", $executed );
printf( "║  已跳过: %d 个插件                                           ║\n", $skipped );
echo "╠══════════════════════════════════════════════════════════════╣\n";

if ( $total_tests > 0 ) {
    $overall_rate = round( $total_passed / $total_tests * 100, 1 );
    printf( "║  总体通过率: %d/%d (%.1f%%)                                  ║\n",
        $total_passed, $total_tests, $overall_rate );
} else {
    echo "║  无测试结果                                                  ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n";

// 详细统计
echo "\n--- 各插件验证结果 ---\n";
foreach ( $all_results as $slug => $result ) {
    if ( isset( $result['skipped'] ) && $result['skipped'] ) {
        echo "  - $slug: 跳过 ({$result['reason']})\n";
    } elseif ( isset( $result['summary'] ) ) {
        $s = $result['summary'];
        $icon = $s['rate'] >= 80 ? '✓' : ( $s['rate'] >= 50 ? '⚠️' : '✗' );
        printf( "  %s %s: %d/%d (%.1f%%)\n", $icon, $slug, $s['passed'], $s['total'], $s['rate'] );
    }
}

echo "\n";

// 保存结果到文件
$results_file = dirname( dirname( dirname( __DIR__ ) ) ) . '/plans/' . $plan_id . '/results/verify-result.json';
$results_dir = dirname( $results_file );
if ( ! is_dir( $results_dir ) ) {
    mkdir( $results_dir, 0755, true );
}

file_put_contents( $results_file, json_encode( array(
    'plan_id'      => $plan_id,
    'timestamp'    => date( 'Y-m-d H:i:s' ),
    'executed'     => $executed,
    'skipped'      => $skipped,
    'total_passed' => $total_passed,
    'total_tests'  => $total_tests,
    'overall_rate' => $total_tests > 0 ? round( $total_passed / $total_tests * 100, 1 ) : 0,
    'plugins'      => $all_results,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

echo "结果已保存到: $results_file\n";

return array(
    'plan_id'      => $plan_id,
    'timestamp'    => date( 'Y-m-d H:i:s' ),
    'executed'     => $executed,
    'skipped'      => $skipped,
    'total_passed' => $total_passed,
    'total_tests'  => $total_tests,
    'overall_rate' => $total_tests > 0 ? round( $total_passed / $total_tests * 100, 1 ) : 0,
    'plugins'      => $all_results,
);

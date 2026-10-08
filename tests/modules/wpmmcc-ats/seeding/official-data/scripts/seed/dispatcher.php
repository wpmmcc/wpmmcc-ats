<?php
/**
 * Seed 数据填充调度器
 *
 * 根据计划 ID 执行所有相关的数据填充脚本
 *
 * 执行方式:
 *   wp eval-file dispatcher.php E    # 执行 Plan E
 *
 * 支持的计划:
 *   A-D: 通过 seeding-config.json 配置的插件
 *   E:   WordPress 核心内容（Theme Unit Test Data）
 *
 * @package WPTSALL\DevTools\Seeding\Seed
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
echo "║  Seed 数据填充调度器                                          ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  计划: $plan_id                                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

$scripts_dir = dirname( __DIR__ );
$results = array();

// =============================================================
// Plan E: WordPress 核心内容（企业官网模式）
// =============================================================
if ( $plan_id === 'E' ) {
    echo "执行 Plan E: WordPress 核心内容填充（企业官网）\n\n";

    $wp_core_dir = dirname( dirname( __DIR__ ) ) . '/wordpress-core';

    // Step 1: 清理已有数据
    $cleanup_script = $wp_core_dir . '/cleanup-wp-core.php';
    if ( file_exists( $cleanup_script ) ) {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "Step 1: 清理已有数据\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        include $cleanup_script;
        echo "\n";
    }

    // Step 2: 填充企业官网数据
    $seed_script = $wp_core_dir . '/corporate-site-seed.php';
    if ( file_exists( $seed_script ) ) {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "Step 2: 填充企业官网数据\n";
        echo "═══════════════════════════════════════════════════════════════\n";

        $result = include $seed_script;
        $results['wordpress-core'] = $result;
        echo "\n";
    } else {
        echo "❌ 错误: 找不到 corporate-site-seed.php\n";
        echo "   路径: $seed_script\n";
        return array(
            'success' => false,
            'error'   => 'Seed script not found',
        );
    }

    // 汇总
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║  Plan E 填充完成                                             ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n";

    return array(
        'success' => true,
        'plan_id' => $plan_id,
        'results' => $results,
    );
}

// =============================================================
// Plan A-D: 使用 seeding-config.json 配置
// =============================================================

$config_file = dirname( dirname( __DIR__ ) ) . '/seeding-config.json';

if ( ! file_exists( $config_file ) ) {
    echo "❌ 错误: 找不到 seeding-config.json\n";
    return array(
        'success' => false,
        'error'   => 'Config file not found',
    );
}

$config = json_decode( file_get_contents( $config_file ), true );

if ( ! isset( $config['plans'][ $plan_id ] ) ) {
    echo "❌ 未知的计划 ID: $plan_id\n";
    echo "可用计划: A, B, C, D, E\n";
    return array(
        'success' => false,
        'error'   => 'Unknown plan ID',
    );
}

$plan = $config['plans'][ $plan_id ];
$executed = 0;
$skipped = 0;

echo "计划名称: {$plan['name']}\n";
echo "描述: {$plan['description']}\n\n";

// 处理 official_data 插件
if ( ! empty( $plan['content_plugins']['official_data'] ) ) {
    echo "--- 官方数据插件 ---\n";

    foreach ( $plan['content_plugins']['official_data'] as $plugin ) {
        $slug = $plugin['slug'];

        // 查找对应的 seed 脚本
        $seed_scripts = array(
            $scripts_dir . '/' . $slug . '-seed.php',
            $scripts_dir . '/' . str_replace( '-', '_', $slug ) . '-seed.php',
        );

        $script_found = false;
        foreach ( $seed_scripts as $script_path ) {
            if ( file_exists( $script_path ) ) {
                echo "═══════════════════════════════════════════════════════════════\n";
                echo "执行: " . basename( $script_path ) . "\n";
                echo "═══════════════════════════════════════════════════════════════\n";

                $result = include $script_path;
                $results[ $slug ] = $result;
                $executed++;
                $script_found = true;
                echo "\n";
                break;
            }
        }

        if ( ! $script_found ) {
            echo "  - $slug: 无 seed 脚本（跳过）\n";
            $skipped++;
        }
    }
}

// 处理 custom_seeding 插件
if ( ! empty( $plan['content_plugins']['custom_seeding'] ) ) {
    echo "\n--- 自定义填充插件 ---\n";

    foreach ( $plan['content_plugins']['custom_seeding'] as $plugin ) {
        $slug = $plugin['slug'];

        // 查找对应的 seed 脚本
        $seed_scripts = array(
            $scripts_dir . '/' . $slug . '-seed.php',
            $scripts_dir . '/' . str_replace( '-', '_', $slug ) . '-seed.php',
        );

        $script_found = false;
        foreach ( $seed_scripts as $script_path ) {
            if ( file_exists( $script_path ) ) {
                echo "═══════════════════════════════════════════════════════════════\n";
                echo "执行: " . basename( $script_path ) . "\n";
                echo "═══════════════════════════════════════════════════════════════\n";

                $result = include $script_path;
                $results[ $slug ] = $result;
                $executed++;
                $script_found = true;
                echo "\n";
                break;
            }
        }

        if ( ! $script_found ) {
            echo "  - $slug: 无 seed 脚本（跳过）\n";
            $skipped++;
        }
    }
}

// 汇总
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Seed 填充完成                                               ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf( "║  执行了 %d 个填充脚本                                         ║\n", $executed );
printf( "║  跳过了 %d 个插件                                            ║\n", $skipped );
echo "╚══════════════════════════════════════════════════════════════╝\n";

echo "\n下一步: 运行验证脚本\n";
echo "  wp eval-file scripts/verify/dispatcher.php $plan_id\n";

return array(
    'success'  => true,
    'plan_id'  => $plan_id,
    'executed' => $executed,
    'skipped'  => $skipped,
    'results'  => $results,
);

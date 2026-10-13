<?php
/**
 * Phase 1: 官方数据导入调度器
 *
 * 根据 seeding-config.json 中的 official_data_sources 配置
 * 自动选择正确的导入方式执行
 *
 * 支持的导入方式：
 * - wp_import: WordPress Importer (XML)
 * - php_script: PHP 脚本执行
 * - wp_cli: WP-CLI 命令
 * - playwright: Playwright 自动化
 *
 * @package WPTSALL\DevTools\Seeding\Phase1
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 加载各 method 处理器
require_once __DIR__ . '/method-wp-import.php';
require_once __DIR__ . '/method-php-script.php';
require_once __DIR__ . '/method-wp-cli.php';
require_once __DIR__ . '/method-playwright.php';

/**
 * 执行 Phase 1: 官方数据导入
 *
 * @param string $plan_id 计划 ID (A/B/C/D)
 * @return array 执行结果统计
 */
function seed_phase1_execute( $plan_id ) {
    seed_log_phase( "Phase 1: 官方数据导入 (Plan $plan_id)" );

    $config = seed_load_config();
    $official_plugins = seed_get_official_plugins( $plan_id );

    if ( empty( $official_plugins ) ) {
        seed_log( '此计划没有官方数据插件', 'warning' );
        return array(
            'total'   => 0,
            'success' => 0,
            'failed'  => 0,
            'skipped' => 0,
        );
    }

    $stats = array(
        'total'   => count( $official_plugins ),
        'success' => 0,
        'failed'  => 0,
        'skipped' => 0,
    );

    foreach ( $official_plugins as $plugin ) {
        $slug = $plugin['slug'];
        $name = $plugin['name'] ?? $slug;
        $data_source = $plugin['data_source'] ?? $slug;

        seed_log( "\n--- $name ---" );

        // 检查插件是否激活
        if ( ! seed_is_plugin_active( $slug ) ) {
            if ( seed_try_activate_plugin( $slug ) ) {
                seed_log( "检测到未激活，已自动激活", 'warning' );
            } else {
                seed_log( "跳过: 插件未激活", 'warning' );
                $stats['skipped']++;
                continue;
            }
        }

        // 获取数据源配置
        $source = seed_get_official_source( $data_source );

        if ( ! $source ) {
            seed_log( "跳过: 无官方数据源配置 ($data_source)", 'warning' );
            $stats['skipped']++;
            continue;
        }

        // 根据 method 分发执行
        $result = seed_phase1_dispatch( $slug, $source, $config );

        if ( $result ) {
            $stats['success']++;
            seed_log( "导入完成", 'success' );
        } else {
            $stats['failed']++;
            seed_log( "导入失败", 'error' );
        }
    }

    // 输出统计
    seed_log( "\n--- Phase 1 统计 ---" );
    seed_log( "总计: {$stats['total']}, 成功: {$stats['success']}, 失败: {$stats['failed']}, 跳过: {$stats['skipped']}" );

    return $stats;
}

/**
 * 根据 method 类型分发执行
 *
 * @param string $plugin_slug 插件 slug
 * @param array  $source      数据源配置
 * @param array  $config      全局配置
 * @return bool 是否成功
 */
function seed_phase1_dispatch( $plugin_slug, $source, $config ) {
    $method = $source['method'] ?? '';
    $configured_path = $config['official_data_sources']['base_path'] ?? '';
    $base_path = ( empty( $configured_path ) || strpos( $configured_path, '__' ) === 0 )
        ? SEED_OFFICIAL_DIR
        : $configured_path;

    seed_log( "导入方式: $method" );

    switch ( $method ) {
        case 'wp_import':
            return seed_phase1_method_wp_import( $source, $base_path );

        case 'php_script':
            return seed_phase1_method_php_script( $source, $base_path );

        case 'wp_cli':
            return seed_phase1_method_wp_cli( $source );

        case 'playwright':
            return seed_phase1_method_playwright( $source, $base_path );

        case 'skip':
            $note = $source['note'] ?? '已配置跳过';
            seed_log( "跳过: $note" );
            return true; // 返回 true 表示"成功跳过"

        default:
            seed_log( "未知导入方式: $method", 'error' );
            return false;
    }
}

/**
 * 检查官方数据文件是否存在
 *
 * @param string $plan_id 计划 ID
 * @return array 检查结果
 */
function seed_phase1_check_files( $plan_id ) {
    $config = seed_load_config();
    $official_plugins = seed_get_official_plugins( $plan_id );
    $configured_path = $config['official_data_sources']['base_path'] ?? '';
    $base_path = ( empty( $configured_path ) || strpos( $configured_path, '__' ) === 0 )
        ? SEED_OFFICIAL_DIR
        : $configured_path;

    $results = array();

    foreach ( $official_plugins as $plugin ) {
        $slug = $plugin['slug'];
        $data_source = $plugin['data_source'] ?? $slug;
        $source = seed_get_official_source( $data_source );

        if ( ! $source ) {
            $results[ $slug ] = array(
                'status' => 'no_config',
                'message' => '无数据源配置',
            );
            continue;
        }

        $method = $source['method'];

        // 检查文件
        if ( in_array( $method, array( 'wp_import', 'php_script' ), true ) ) {
            $file = $base_path . '/' . ( $source['file'] ?? '' );

            if ( file_exists( $file ) ) {
                $results[ $slug ] = array(
                    'status' => 'ready',
                    'file'   => $file,
                    'method' => $method,
                );
            } else {
                $results[ $slug ] = array(
                    'status' => 'missing',
                    'file'   => $file,
                    'message' => '文件不存在',
                );
            }
        } elseif ( $method === 'playwright' ) {
            $script = $base_path . '/' . ( $source['script'] ?? '' );

            if ( file_exists( $script ) ) {
                $results[ $slug ] = array(
                    'status' => 'ready',
                    'script' => $script,
                    'method' => $method,
                );
            } else {
                $results[ $slug ] = array(
                    'status' => 'missing',
                    'script' => $script,
                    'message' => 'Playwright 脚本不存在',
                );
            }
        } elseif ( $method === 'wp_cli' ) {
            $results[ $slug ] = array(
                'status' => 'ready',
                'method' => $method,
                'commands' => $source['commands'] ?? array(),
            );
        }
    }

    return $results;
}

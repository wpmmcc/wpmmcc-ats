<?php
/**
 * Phase 3: 工具插件关联调度器
 *
 * 从 plans/{plan}/utility/*.json 读取配置，创建工具插件实体并关联内容
 *
 * 执行流程：
 *   1. 读取 plans/{plan}/utility/{plugin}.json 配置（由 analyze 命令生成）
 *   2. 创建工具插件实体（表单、表格、字段组等）
 *   3. 为内容添加关联数据（SEO meta、字段值、嵌入短代码）
 *
 * @package WPTSALL\DevTools\Seeding\Phase3
 * @version 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

// 加载填充模块
require_once __DIR__ . '/fill-seo.php';
require_once __DIR__ . '/fill-acf.php';
require_once __DIR__ . '/fill-forms.php';
require_once __DIR__ . '/fill-tables.php';
require_once __DIR__ . '/fill-booking.php';
require_once __DIR__ . '/fill-membership.php';
require_once __DIR__ . '/fill-podcast.php';

/**
 * 执行 Phase 3: 工具插件关联
 *
 * @param string $plan_id 计划 ID (A/B/C/D)
 * @param array  $options 选项
 *               - dry_run: 仅分析，不执行
 * @return array 执行结果
 */
function seed_phase3_execute( $plan_id, $options = array() ) {
    seed_log_phase( "Phase 3: 工具插件关联 (Plan $plan_id)" );

    $dry_run = $options['dry_run'] ?? false;

    if ( $dry_run ) {
        seed_log( '** Dry Run 模式 - 不执行实际操作 **' );
    }

    $result = array(
        'success'  => 0,
        'failed'   => 0,
        'skipped'  => 0,
        'details'  => array(),
    );

    // 获取计划配置
    $config = seed_load_config();
    $plan = $config['plans'][ $plan_id ] ?? null;

    if ( ! $plan ) {
        seed_log( "计划 $plan_id 不存在", 'error' );
        return $result;
    }

    $utility_plugins = seed_filter_plugins_by_e2e_allowlist( $plan['utility_plugins'] ?? array() );

    if ( empty( $utility_plugins ) ) {
        seed_log( '没有配置工具插件' );
        return $result;
    }

    // 遍历工具插件
    foreach ( $utility_plugins as $plugin ) {
        $slug = is_array( $plugin ) ? ( $plugin['slug'] ?? '' ) : $plugin;
        $type = is_array( $plugin ) ? ( $plugin['type'] ?? 'unknown' ) : 'unknown';

        if ( empty( $slug ) ) {
            continue;
        }

        seed_log( "\n--- $slug ---" );

        // 检查插件是否激活
        if ( ! seed_is_plugin_active( $slug ) ) {
            seed_log( "  跳过: 插件未激活", 'warning' );
            $result['skipped']++;
            continue;
        }

        // 加载 JSON 配置（从计划专属目录）
        $utility_dir = defined( 'SEED_UTILITY_DIR' )
            ? SEED_UTILITY_DIR
            : SEEDING_BASE_DIR . '/plans/' . $plan_id . '/utility';
        $json_file = $utility_dir . '/' . $slug . '.json';

        if ( ! file_exists( $json_file ) ) {
            seed_log( "  跳过: 配置文件不存在 ($json_file)", 'warning' );
            seed_log( "  提示: 请先运行 'analyze' 命令生成配置", 'warning' );
            $result['skipped']++;
            continue;
        }

        $utility_config = json_decode( file_get_contents( $json_file ), true );
        if ( ! $utility_config ) {
            seed_log( "  跳过: JSON 解析失败", 'error' );
            $result['failed']++;
            continue;
        }

        // 根据类型调用对应的填充函数
        $plugin_type = $utility_config['_meta']['type'] ?? $type;
        $fill_result = false;

        switch ( $plugin_type ) {
            case 'seo':
                $fill_result = seed_phase3_fill_seo( $utility_config, $dry_run );
                break;

            case 'custom_fields':
                $fill_result = seed_phase3_fill_acf( $utility_config, $dry_run );
                break;

            case 'forms':
                $fill_result = seed_phase3_fill_forms( $utility_config, $dry_run );
                break;

            case 'tables':
                $fill_result = seed_phase3_fill_tables( $utility_config, $dry_run );
                break;

            case 'booking':
                $fill_result = seed_phase3_fill_booking( $utility_config, $dry_run );
                break;

            case 'membership':
                $fill_result = seed_phase3_fill_membership( $utility_config, $dry_run );
                break;

            case 'podcast':
                $fill_result = seed_phase3_fill_podcast( $utility_config, $dry_run );
                break;

            default:
                seed_log( "  跳过: 不支持的类型 ($plugin_type)", 'warning' );
                $result['skipped']++;
                continue 2;
        }

        if ( $fill_result && ! empty( $fill_result['success'] ) ) {
            $result['success']++;
            $result['details'][] = array(
                'plugin' => $slug,
                'type'   => $plugin_type,
                'items'  => $fill_result['items'] ?? 0,
            );
            seed_log( "  完成: {$fill_result['items']} 项", 'success' );
        } else {
            $result['failed']++;
            seed_log( "  失败", 'error' );
        }
    }

    seed_log( "\n--- Phase 3 完成 ---" );

    return $result;
}

/**
 * 检查 Phase 3 状态
 *
 * @param string $plan_id 计划 ID
 * @return array 状态信息
 */
function seed_phase3_check_status( $plan_id ) {
    $config = seed_load_config();
    $plan = $config['plans'][ $plan_id ] ?? null;

    $result = array(
        'plan_exists'     => false,
        'utilities_ready' => array(),
        'config_files'    => array(),
    );

    if ( ! $plan ) {
        return $result;
    }

    // 使用计划专属的 utility 目录
    $utility_dir = defined( 'SEED_UTILITY_DIR' )
        ? SEED_UTILITY_DIR
        : SEEDING_BASE_DIR . '/plans/' . $plan_id . '/utility';

    $utility_plugins = seed_filter_plugins_by_e2e_allowlist( $plan['utility_plugins'] ?? array() );

    foreach ( $utility_plugins as $plugin ) {
        $slug = is_array( $plugin ) ? ( $plugin['slug'] ?? '' ) : $plugin;

        if ( empty( $slug ) ) {
            continue;
        }

        // 检查插件激活状态
        $result['utilities_ready'][ $slug ] = seed_is_plugin_active( $slug );

        // 检查配置文件
        $json_file = $utility_dir . '/' . $slug . '.json';
        $result['config_files'][ $slug ] = file_exists( $json_file );
    }

    // 检查是否有任何配置文件存在
    $result['plan_exists'] = in_array( true, $result['config_files'], true );

    return $result;
}

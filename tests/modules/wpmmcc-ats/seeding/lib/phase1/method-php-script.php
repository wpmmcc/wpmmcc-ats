<?php
/**
 * Phase 1 Method: PHP Script
 *
 * 使用 wp eval-file 执行 PHP 脚本导入数据
 *
 * @package WPTSALL\DevTools\Seeding\Phase1
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 执行 php_script 方式的数据导入
 *
 * @param array  $source    数据源配置
 * @param string $base_path 基础路径
 * @return bool 是否成功
 */
function seed_phase1_method_php_script( $source, $base_path ) {
    $file = $base_path . '/' . ( $source['file'] ?? '' );

    if ( ! file_exists( $file ) ) {
        seed_log( "脚本不存在: $file", 'error' );
        return false;
    }

    seed_log( "执行脚本: $file" );

    // 构建命令
    $command_template = $source['command'] ?? 'wp eval-file {file}';
    $command = str_replace( '{file}', escapeshellarg( $file ), $command_template );

    // 移除 "wp " 前缀
    $command = preg_replace( '/^wp\s+/', '', $command );

    seed_log( "执行: wp $command" );

    // 检查是否在 WP-CLI 环境中
    if ( ! class_exists( 'WP_CLI' ) ) {
        seed_log( '需要在 WP-CLI 环境中运行', 'error' );
        return false;
    }

    try {
        // 输出重定向到临时文件，避免嵌套 wp-cli 的管道死锁
        // （详见 seed_phase1_run_nested 的说明）。
        $result = seed_phase1_run_nested( $command );

        if ( $result->return_code === 0 ) {
            seed_log( "脚本执行成功" );
            // 输出脚本输出的关键信息
            if ( ! empty( $result->stdout ) ) {
                $lines = explode( "\n", trim( $result->stdout ) );
                // 只显示关键统计行（通常包含"成功"、"完成"等）
                foreach ( $lines as $line ) {
                    if ( preg_match( '/(成功|完成|导入|created|imported|success)/i', $line ) ) {
                        seed_log( "  $line" );
                    }
                }
            }
            return true;
        } else {
            seed_log( "脚本执行失败: " . $result->stderr, 'error' );
            return false;
        }
    } catch ( Exception $e ) {
        seed_log( "执行异常: " . $e->getMessage(), 'error' );
        return false;
    }
}

/**
 * 直接包含并执行 PHP 脚本（在当前进程中）
 *
 * 适用于需要共享上下文的场景
 *
 * @param array  $source    数据源配置
 * @param string $base_path 基础路径
 * @return bool 是否成功
 */
function seed_phase1_method_php_script_include( $source, $base_path ) {
    $file = $base_path . '/' . ( $source['file'] ?? '' );

    if ( ! file_exists( $file ) ) {
        seed_log( "脚本不存在: $file", 'error' );
        return false;
    }

    seed_log( "包含执行: $file" );

    try {
        // 直接包含脚本
        include $file;
        seed_log( "脚本执行完成" );
        return true;
    } catch ( Exception $e ) {
        seed_log( "脚本异常: " . $e->getMessage(), 'error' );
        return false;
    }
}

<?php
/**
 * Phase 1 Method: WP-CLI Commands
 *
 * 执行 WP-CLI 命令生成数据（如 BuddyPress）
 *
 * @package WPTSALL\DevTools\Seeding\Phase1
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 执行 wp_cli 方式的数据生成
 *
 * 支持两种配置格式：
 * 1. commands 数组：["wp cmd1", "wp cmd2"]
 * 2. files + command：{ files: {key: path}, command: "wp xxx {key}" }
 *
 * @param array $source 数据源配置
 * @return bool 是否成功
 */
function seed_phase1_method_wp_cli( $source ) {
    $commands = $source['commands'] ?? array();

    // 支持 files + command 格式（如 Sensei LMS）
    if ( empty( $commands ) && ! empty( $source['files'] ) && ! empty( $source['command'] ) ) {
        $command = $source['command'];
        $base_path = defined( 'SEED_OFFICIAL_DIR' ) ? SEED_OFFICIAL_DIR : '';

        // 替换文件占位符
        foreach ( $source['files'] as $key => $relative_path ) {
            $full_path = $base_path . '/' . $relative_path;

            // 检查文件是否存在
            if ( ! file_exists( $full_path ) ) {
                seed_log( "文件不存在: $relative_path", 'error' );
                return false;
            }

            $command = str_replace( '{' . $key . '}', $full_path, $command );
        }

        $commands = array( $command );
        seed_log( "使用文件模板命令" );
    }

    if ( empty( $commands ) ) {
        seed_log( '无命令配置', 'warning' );
        return false;
    }

    // 检查是否在 WP-CLI 环境中
    if ( ! class_exists( 'WP_CLI' ) ) {
        seed_log( '需要在 WP-CLI 环境中运行', 'error' );
        return false;
    }

    $success = 0;
    $failed  = 0;

    foreach ( $commands as $command ) {
        // 移除 "wp " 前缀（WP_CLI::runcommand 不需要）
        $cmd = preg_replace( '/^wp\s+/', '', $command );

        seed_log( "执行: wp $cmd" );

        try {
            // 输出重定向到临时文件，避免嵌套 wp-cli 的管道死锁
            // （详见 seed_phase1_run_nested 的说明）。
            $result = seed_phase1_run_nested( $cmd );

            $stderr = seed_phase1_wp_cli_clean_stderr( (string) $result->stderr );

            if ( $result->return_code === 0 || seed_phase1_wp_cli_is_benign_error( $stderr ) ) {
                $success++;
                // 输出命令结果摘要
                if ( ! empty( $result->stdout ) ) {
                    $lines = explode( "\n", trim( $result->stdout ) );
                    $last_line = end( $lines );
                    if ( ! empty( trim( $last_line ) ) ) {
                        seed_log( "  → $last_line" );
                    }
                }
                if ( $result->return_code !== 0 && ! empty( $stderr ) ) {
                    seed_log( "  命令可忽略告警: " . $stderr, 'warning' );
                }
            } else {
                $failed++;
                seed_log( "  命令失败: " . $stderr, 'warning' );
            }
        } catch ( Exception $e ) {
            $failed++;
            seed_log( "  执行异常: " . $e->getMessage(), 'error' );
        }
    }

    seed_log( "命令执行完成: 成功 $success, 失败 $failed" );

    return $failed === 0;
}

/**
 * 验证 WP-CLI 命令是否可用
 *
 * @param string $command 命令（如 "bp member generate"）
 * @return bool 是否可用
 */
function seed_phase1_wp_cli_command_exists( $command ) {
    // 提取命令组（如 "bp" 从 "bp member generate"）
    $parts = explode( ' ', trim( $command ) );
    $cmd_group = $parts[0] ?? '';

    if ( empty( $cmd_group ) ) {
        return false;
    }

    // 检查命令是否已注册
    // 注意：这需要对应的插件已激活并注册了 WP-CLI 命令

    return true; // 简化实现，实际执行时会报错
}

/**
 * 清理 WP-CLI stderr，移除已知的 Deprecated 噪音
 *
 * @param string $stderr 原始 stderr
 * @return string 清理后的 stderr
 */
function seed_phase1_wp_cli_clean_stderr( $stderr ) {
    $stderr = preg_replace( '/^PHP Deprecated:.*$/mi', '', $stderr );
    $stderr = preg_replace( '/^Deprecated:.*$/mi', '', $stderr );
    return trim( $stderr );
}

/**
 * 判断 WP-CLI 命令失败是否为可忽略的幂等错误
 *
 * @param string $stderr 清理后的 stderr
 * @return bool 是否可忽略
 */
function seed_phase1_wp_cli_is_benign_error( $stderr ) {
    if ( '' === $stderr ) {
        return false;
    }

    $normalized = strtolower( $stderr );
    $patterns = array(
        'already active',
        'is already active',
    );

    foreach ( $patterns as $pattern ) {
        if ( false !== strpos( $normalized, $pattern ) ) {
            return true;
        }
    }

    return false;
}

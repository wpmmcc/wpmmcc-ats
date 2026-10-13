<?php
/**
 * Phase 1 Method: WordPress Importer (XML)
 *
 * 使用 wp import 命令导入 XML 文件
 *
 * @package WPTSALL\DevTools\Seeding\Phase1
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Access denied.' );
}

/**
 * 执行 wp_import 方式的数据导入
 *
 * @param array  $source    数据源配置
 * @param string $base_path 基础路径
 * @return bool 是否成功
 */
function seed_phase1_method_wp_import( $source, $base_path ) {
    $file = $base_path . '/' . ( $source['file'] ?? '' );

    if ( ! file_exists( $file ) ) {
        seed_log( "文件不存在: $file", 'error' );
        return false;
    }

    seed_log( "导入文件: $file" );

    // 构建命令（从配置模板或默认）
    $command_template = $source['command'] ?? 'wp import {file} --authors=create';
    $command = str_replace( '{file}', escapeshellarg( $file ), $command_template );

    // 移除 "wp " 前缀（WP_CLI::runcommand 不需要）
    $command = preg_replace( '/^wp\s+/', '', $command );

    seed_log( "执行: wp $command" );

    // 检查是否在 WP-CLI 环境中
    if ( ! class_exists( 'WP_CLI' ) ) {
        seed_log( '需要在 WP-CLI 环境中运行', 'error' );
        return false;
    }

    // 确保 WordPress Importer 插件可用
    if ( ! seed_phase1_ensure_importer() ) {
        seed_log( 'WordPress Importer 不可用', 'error' );
        return false;
    }

    try {
        // 输出重定向到临时文件，避免嵌套 wp-cli 的管道死锁
        // （详见 seed_phase1_run_nested 的说明）。
        $result = seed_phase1_run_nested( $command );

        if ( $result->return_code === 0 ) {
            seed_log( "导入成功" );
            if ( ! empty( $result->stdout ) ) {
                // 输出导入摘要（最后几行）
                $lines = explode( "\n", trim( $result->stdout ) );
                $summary = array_slice( $lines, -5 );
                foreach ( $summary as $line ) {
                    if ( ! empty( trim( $line ) ) ) {
                        seed_log( "  $line" );
                    }
                }
            }
            return true;
        } else {
            seed_log( "导入失败: " . $result->stderr, 'error' );
            return false;
        }
    } catch ( Exception $e ) {
        seed_log( "执行异常: " . $e->getMessage(), 'error' );
        return false;
    }
}

/**
 * 确保 WordPress Importer 可用
 *
 * @return bool 是否可用
 */
function seed_phase1_ensure_importer() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$importer_plugin = 'wordpress-importer/wordpress-importer.php';
	$importer_file   = WP_PLUGIN_DIR . '/wordpress-importer/wordpress-importer.php';

	$importer_is_active = static function () use ( $importer_plugin ) {
		if ( is_plugin_active( $importer_plugin ) ) {
			return true;
		}
		if ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $importer_plugin ) ) {
			return true;
		}
		return false;
	};

	// 检查 wp import 命令是否可用
	// WordPress Importer 需要安装 wordpress-importer 插件

	// 方式 1：检查插件是否激活
	if ( $importer_is_active() ) {
        return true;
    }

    // 方式 2：尝试加载 WP_Import 类
	if ( file_exists( $importer_file ) ) {
		if ( class_exists( 'WP_CLI' ) ) {
			seed_log( 'WordPress Importer 已安装但未激活，尝试自动激活...' );
			$activate = WP_CLI::runcommand(
				'plugin activate wordpress-importer',
				array(
					'return'     => 'all',
					'exit_error' => false,
				)
			);
			if ( 0 === (int) $activate->return_code ) {
				// runcommand() executes in a child process; current option cache may be stale.
				// Force class availability as the readiness signal for this process.
				include_once $importer_file;
				if ( class_exists( 'WP_Import' ) || class_exists( 'WP_Import_Extended' ) || $importer_is_active() ) {
					return true;
				}
			}
		}

		// 兜底：插件存在但未激活，尝试临时加载。
		include_once $importer_file;
		return class_exists( 'WP_Import' ) || class_exists( 'WP_Import_Extended' );
	}

	// 方式 3：自动安装并激活 importer（在 WP-CLI 环境中）。
	if ( class_exists( 'WP_CLI' ) ) {
		seed_log( 'WordPress Importer 未安装，尝试自动安装并激活...' );
		$install = WP_CLI::runcommand(
			'plugin install wordpress-importer --activate',
			array(
				'return'     => 'all',
				'exit_error' => false,
			)
		);

		if ( 0 === (int) $install->return_code ) {
			if ( file_exists( $importer_file ) ) {
				include_once $importer_file;
			}
			if ( class_exists( 'WP_Import' ) || class_exists( 'WP_Import_Extended' ) || $importer_is_active() ) {
				seed_log( 'WordPress Importer 自动安装成功。' );
				return true;
			}
		}

		$stderr = trim( (string) ( $install->stderr ?? '' ) );
		if ( '' !== $stderr ) {
			seed_log( '自动安装失败: ' . $stderr, 'warning' );
		}
	}

	// 方式 4：提示手动处理
	seed_log( '提示: 请安装并激活 WordPress Importer 插件', 'warning' );
	seed_log( '  wp plugin install wordpress-importer --activate', 'warning' );

	return false;
}

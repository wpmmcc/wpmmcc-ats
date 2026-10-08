<?php
/**
 * EDD Extensions API 兼容补丁安装器（pre-setup 阶段）
 *
 * 复制 mu-plugin-edd-extensions-compat.php 到 <WP_CONTENT>/mu-plugins/，
 * 幂等（内容一致则跳过）。与 lms-url-conflict-resolver.php 的 mu-plugin
 * 安装方式保持一致。
 *
 * 执行方式:
 *   wp eval-file install-edd-extensions-compat.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

$mu_plugins_dir = WP_CONTENT_DIR . '/mu-plugins';
$source         = __DIR__ . '/mu-plugin-edd-extensions-compat.php';
$target         = $mu_plugins_dir . '/edd-extensions-compat.php';

if ( ! is_dir( $mu_plugins_dir ) ) {
	mkdir( $mu_plugins_dir, 0755, true );
	echo "  ✓ 创建 mu-plugins 目录\n";
}

if ( file_exists( $source ) ) {
	if ( ! file_exists( $target ) || md5_file( $source ) !== md5_file( $target ) ) {
		copy( $source, $target );
		echo "  ✓ 安装 mu-plugin: edd-extensions-compat.php\n";
	} else {
		echo "  mu-plugin 已是最新版本: edd-extensions-compat.php\n";
	}
} else {
	echo "  ⚠️ 未找到 mu-plugin 源文件: " . basename( $source ) . "\n";
}

return array(
	'success' => file_exists( $target ),
	'installed' => 'edd-extensions-compat.php',
);

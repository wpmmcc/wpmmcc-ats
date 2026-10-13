<?php
/**
 * EDD Extensions API 兼容补丁（WPTSALL 测试 Lab 专用 mu-plugin）
 *
 * 背景（2026-09-02 诊断）：
 * EDD 3.7.0（当时 latest）中 EDD\Admin\Settings\{Reviews,Invoices,Recurring}::
 * is_activated() 把 get_product_data() 的返回值原样传给
 * EDD\Admin\Extensions\Extension_Manager::is_plugin_active()，后者再传给
 * WordPress is_plugin_active()。当 get_product_data() 返回裸数组时（EDD
 * Products API 缓存格式），PHP 8 下 is_plugin_active( array ) 在
 * wp-admin/includes/plugin.php:586 抛
 * "Illegal offset type in isset or empty" TypeError —— 任何 download 编辑页
 * 返回 HTTP 500 "There has been a critical error on this website"。
 * 上游 3.7.0 未修复（wordpress.org 上即 latest）。
 *
 * 实测（Lab 容器 9083）：
 * - 容器 WP 为 multisite，EDD 用 get_site_option()（wp_sitemeta）。
 * - 一次出网成功时 EDD 把远端数据以键 0（而非 item_id 37976）写入
 *   sitemeta；此后 get_product_data() 命中"fresh + 无 item 键 + timeout 键"
 *   分支，返回 filter_paypal_commerce_pro() 的裸数组 → is_plugin_active
 *   TypeError。
 * - 单站 pre_option_{$name} filter 对 get_site_option 无效（multisite）；
 *   且网络成功写入的 option autoload 进 alloptions 会绕过 pre_filter。
 *
 * 做法（对三个已知产品 option：Reviews=37976、Invoices=375153、
 * Recurring=28530）：
 * 1) pre_site_option_{$name}   —— 返回"永不失效、basename 为空"的稳定缓存，
 *    EDD 走 ProductData::fromArray() 对象路径 -> is_plugin_active 判 false；
 * 2) pre_update_site_option_{$name} / pre_add_site_option_{$name} —— 拒绝
 *    写入，保证 sitemeta 恒无该 option，读取始终命中 filter；
 * 3) pre_http_request         —— 拦截 EDD extension data 出网请求（Lab
 *    出网隔离，也避免远端数据再次落库）；
 * 4) admin_init               —— 清理历史残留 network option。
 *
 * 注意：EDD 升级若改变 item_id 或修复该路径，本补丁可整体移除；
 * 三个 item_id 均来自 EDD 3.7.0 源码 protected $item_id。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wptsall_lab_edd_item_ids = array( 37976, 375153, 28530 ); // Reviews, Invoices, Recurring.

foreach ( $wptsall_lab_edd_item_ids as $item_id ) {
	$option_name = 'edd_extension_product_' . $item_id . '_data';

	// 拦截读取（network/ multisite 路径）：返回"永不失效、指向未安装扩展"的
	// 稳定缓存。注意 basename 必须非空（指向未安装的扩展插件），否则
	// Extension_Manager::is_plugin_active() 会退而把 ProductData 对象本身
	// 传给 is_plugin_active()（PHP 8 同款 TypeError）；非空 + 未激活 →
	// is_plugin_active() 安全返回 false。
	add_filter(
		'pre_site_option_' . $option_name,
		function ( $pre, $option, $default ) use ( $item_id ) {
			$basename_by_item = array(
				37976  => 'edd-reviews/edd-reviews.php',
				375153 => 'edd-invoices/edd-invoices.php',
				28530  => 'edd-recurring/edd-recurring.php',
			);
			return array(
				'timeout' => time() + DAY_IN_SECONDS,
				$item_id  => array(
					'title'    => 'EDD Extension',
					'slug'     => '',
					'basename' => $basename_by_item[ $item_id ],
				),
			);
		},
		10,
		3
	);

	// 拒绝写入：阻止 option 进入 sitemeta。
	add_filter(
		'pre_update_site_option_' . $option_name,
		function ( $value, $old_value, $option ) {
			return $old_value;
		},
		10,
		3
	);
	add_filter(
		'pre_add_site_option_' . $option_name,
		function ( $value, $option ) {
			return false;
		},
		10,
		2
	);

	add_filter(
		'pre_update_option_' . $option_name,
		function ( $value, $old_value, $option ) {
			return $old_value;
		},
		10,
		3
	);
	add_filter(
		'pre_add_option_' . $option_name,
		function ( $value, $option ) {
			return false;
		},
		10,
		2
	);
}

// 拦截 EDD extension data 出网请求（Lab 隔离）。
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( is_string( $url ) && strpos( $url, 'edd_action=extension_data' ) !== false ) {
			return new WP_Error( 'wptsall_lab_blocked', 'EDD extension data API blocked in WPTSALL lab' );
		}
		return $pre;
	},
	10,
	3
);

// 清理历史残留 network/site option（旧运行曾把真实/超时数据写入）。
add_action(
	'admin_init',
	function () use ( $wptsall_lab_edd_item_ids ) {
		foreach ( $wptsall_lab_edd_item_ids as $item_id ) {
			delete_site_option( 'edd_extension_product_' . $item_id . '_data' );
			delete_option( 'edd_extension_product_' . $item_id . '_data' );
		}
		delete_site_option( 'edd_all_extension_data' );
		delete_option( 'edd_all_extension_data' );
	},
	1
);

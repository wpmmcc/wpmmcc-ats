<?php
/**
 * WPTSALL Site Integration Test
 *
 * 在开发目录运行，通过 wp-load.php 测试站点功能
 * 不会在站点根目录创建任何文件
 *
 * Usage: php tests/site-integration-test.php
 *
 * @package WPTSALL
 * @since 0.2.0
 */

// WordPress 站点路径
define( 'WP_SITE_PATH', '/usr/local/var/www/' );

// 检查 WordPress 是否存在
if ( ! file_exists( WP_SITE_PATH . 'wp-load.php' ) ) {
	die( "❌ WordPress 未找到: " . WP_SITE_PATH . "\n" );
}

// 加载 WordPress
define( 'WP_USE_THEMES', false );
require_once WP_SITE_PATH . 'wp-load.php';

echo "=================================================\n";
echo "WPTSALL 站点集成测试\n";
echo "=================================================\n";
echo "站点路径: " . WP_SITE_PATH . "\n";
echo "WordPress 版本: " . get_bloginfo( 'version' ) . "\n";
echo "PHP 版本: " . PHP_VERSION . "\n";
echo "\n";

// ============================================================================
// Test 1: Plugin Status
// ============================================================================
echo "测试 1: 插件状态\n";
echo "-----------------------------------\n";

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$plugin_file = 'wptsall/wptsall.php';
$is_active   = is_plugin_active( $plugin_file );

if ( $is_active ) {
	$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
	echo "✅ 插件已激活\n";
	echo "   名称: {$plugin_data['Name']}\n";
	echo "   版本: {$plugin_data['Version']}\n";
	echo "   作者: {$plugin_data['Author']}\n";
} else {
	echo "❌ 插件未激活\n";
	die( "请在 WordPress 后台激活 WPTSALL 插件\n" );
}

echo "\n";

// ============================================================================
// Test 2: Architecture v0.2.0
// ============================================================================
echo "测试 2: 新架构 (v0.2.0)\n";
echo "-----------------------------------\n";

// Main controller
if ( class_exists( 'WPTSALL\WPTSALL' ) ) {
	echo "✅ 主控制器: WPTSALL\\WPTSALL\n";

	$wptsall = wptsall();
	echo "   版本: " . $wptsall->get_version() . "\n";
	echo "   Legacy 模式: " . ( $wptsall->is_legacy_mode() ? '启用' : '禁用' ) . "\n";
} else {
	echo "❌ 主控制器类未加载\n";
}

// Autoloader
if ( class_exists( 'WPTSALL\Autoloader' ) ) {
	echo "✅ 自动加载器: WPTSALL\\Autoloader\n";
} else {
	echo "❌ 自动加载器未加载\n";
}

echo "\n";

// ============================================================================
// Test 3: Modular Structure
// ============================================================================
echo "测试 3: 模块化结构\n";
echo "-----------------------------------\n";

$module_dirs = array(
	'validators'  => '验证器',
	'core'        => '核心',
	'models'      => '模型',
	'services'    => '服务',
	'admin'       => '管理',
	'api'         => 'API',
	'database'    => '数据库',
	'frontend'    => '前端',
	'utils'       => '工具',
);

$plugin_includes = WP_PLUGIN_DIR . '/wptsall/includes/';
$all_exist = true;

foreach ( $module_dirs as $dir => $name ) {
	$exists = is_dir( $plugin_includes . $dir );
	echo ( $exists ? '✅' : '❌' ) . " {$name}: {$dir}/\n";
	if ( ! $exists ) {
		$all_exist = false;
	}
}

if ( $all_exist ) {
	echo "\n✅ 所有模块目录已创建\n";
}

echo "\n";

// ============================================================================
// Test 4: Migrated Modules
// ============================================================================
echo "测试 4: 已迁移模块\n";
echo "-----------------------------------\n";

// Template Validator (new)
if ( class_exists( 'WPTSALL\Models\Validators\Template_Validator' ) ) {
	echo "✅ 新验证器: WPTSALL\\Validators\\Template_Validator\n";

	// Test validation
	$test_template = array(
		'plugin_slug' => 'test',
		'plugin_name' => 'Test Plugin',
		'url_types'   => array(),
	);

	$result = WPTSALL\Models\Validators\Template_Validator::validate_template( $test_template );
	echo "   测试调用: " . ( isset( $result['valid'] ) ? '✅ 正常' : '❌ 失败' ) . "\n";
} else {
	echo "❌ 新验证器未加载\n";
}

// Compatibility layer
if ( class_exists( 'WPTSALL_Template_Validator' ) ) {
	echo "✅ 兼容层: WPTSALL_Template_Validator\n";

	$test_template = array(
		'plugin_slug' => 'test',
		'plugin_name' => 'Test',
		'url_types'   => array(),
	);

	$result = WPTSALL_Template_Validator::validate_template( $test_template );
	echo "   旧类调用新类: " . ( isset( $result['valid'] ) ? '✅ 正常' : '❌ 失败' ) . "\n";
} else {
	echo "❌ 兼容层未加载\n";
}

echo "\n";

// ============================================================================
// Test 5: Service Container
// ============================================================================
echo "测试 5: 服务容器\n";
echo "-----------------------------------\n";

if ( function_exists( 'wptsall' ) ) {
	$wptsall = wptsall();

	// Test template_validator service
	if ( $wptsall->has( 'template_validator' ) ) {
		echo "✅ template_validator 服务已注册\n";

		$validator = $wptsall->get( 'template_validator' );
		if ( $validator instanceof WPTSALL\Models\Validators\Template_Validator ) {
			echo "   实例类型: " . get_class( $validator ) . " ✅\n";
		} else {
			echo "   ❌ 实例类型错误\n";
		}
	} else {
		echo "❌ template_validator 服务未注册\n";
	}

	// Test set/get
	$wptsall->set( 'test_service', 'test_value' );
	$value = $wptsall->get( 'test_service' );

	if ( $value === 'test_value' ) {
		echo "✅ 服务容器 set/get 正常\n";
	} else {
		echo "❌ 服务容器 set/get 失败\n";
	}
} else {
	echo "❌ wptsall() 函数不存在\n";
}

echo "\n";

// ============================================================================
// Test 6: Legacy Functions (Backward Compatibility)
// ============================================================================
echo "测试 6: 遗留功能兼容性\n";
echo "-----------------------------------\n";

$legacy_checks = array(
	'wptsall_saved_templates'       => '模板管理',
	'wptsall_content_plugins'       => '内容插件',
	'wptsall_ensure_task_table'     => '数据库表',
	'WPTSALL_Scanner'               => '扫描器类',
	'WPTSALL_Template_Validator'    => '验证器类',
);

foreach ( $legacy_checks as $item => $name ) {
	if ( function_exists( $item ) || class_exists( $item ) ) {
		echo "✅ {$name}: {$item}\n";
	} else {
		echo "❌ {$name}: {$item} - 未找到\n";
	}
}

echo "\n";

// ============================================================================
// Test 7: Scanner Improvements
// ============================================================================
echo "测试 7: 扫描器改进 (v0.1.0)\n";
echo "-----------------------------------\n";

if ( class_exists( 'WPTSALL_Scanner' ) ) {
	echo "✅ WPTSALL_Scanner 类已加载\n";

	// Check for improvements
	$improvements = array(
		'is_auto_increment_field' => '自增字段智能识别',
		'generate_plugin_template' => '模板生成',
		'discover_cross_table_fields' => '跨表关系检测',
	);

	foreach ( $improvements as $method => $desc ) {
		if ( method_exists( 'WPTSALL_Scanner', $method ) ) {
			echo "   ✅ {$desc}: {$method}()\n";
		} else {
			echo "   ❌ {$desc}: {$method}()\n";
		}
	}
} else {
	echo "❌ Scanner 类未加载\n";
}

echo "\n";

// ============================================================================
// Test 8: Database Tables
// ============================================================================
echo "测试 8: 数据库表\n";
echo "-----------------------------------\n";

global $wpdb;

$tables = array(
	$wpdb->prefix . 'wptsall_tasks'              => '任务',
	$wpdb->prefix . 'wptsall_mapping'            => '映射',
	$wpdb->prefix . 'wptsall_virtual_content'    => '虚拟内容',
	$wpdb->prefix . 'wptsall_sync_meta'          => '同步元',
	$wpdb->prefix . 'wptsall_conflicts'          => '冲突',
	$wpdb->prefix . 'wptsall_snapshots'          => '快照',
	$wpdb->prefix . 'wptsall_translation_memory' => '翻译记忆',
	$wpdb->prefix . 'wptsall_terminology'        => '术语',
);

$table_count = 0;
foreach ( $tables as $table => $name ) {
	$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
	if ( $exists ) {
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		echo "✅ {$name}: {$count} 条记录\n";
		$table_count++;
	} else {
		echo "❌ {$name}: 表不存在\n";
	}
}

echo "\n共 {$table_count}/" . count( $tables ) . " 个表已创建\n";

echo "\n";

// ============================================================================
// Test 9: Template Generation Test
// ============================================================================
echo "测试 9: 模板生成功能\n";
echo "-----------------------------------\n";

if ( class_exists( 'WPTSALL_Scanner' ) && function_exists( 'wptsall_content_plugins' ) ) {
	$plugins = wptsall_content_plugins();

	if ( ! empty( $plugins ) ) {
		echo "✅ 检测到 " . count( $plugins ) . " 个内容插件\n";

		$first_plugin = array_key_first( $plugins );
		echo "   测试插件: {$plugins[$first_plugin]}\n";

		try {
			// Generate template with 1 sample
			$template = WPTSALL_Scanner::generate_plugin_template( $first_plugin, 1 );

			if ( is_array( $template ) && isset( $template['plugin_slug'] ) ) {
				echo "   ✅ 模板生成成功\n";
				echo "      URL 类型: " . ( isset( $template['url_types'] ) ? count( $template['url_types'] ) : 0 ) . " 个\n";

				// Check field count (should be unlimited now)
				if ( isset( $template['url_types'][0]['fields_map'] ) ) {
					$total_fields = 0;
					foreach ( $template['url_types'][0]['fields_map'] as $table => $fields ) {
						if ( is_array( $fields ) ) {
							$count = count( $fields );
							$total_fields += $count;
							echo "      {$table}: {$count} 个字段\n";
						}
					}

					if ( $total_fields > 20 ) {
						echo "   ✅ 字段扫描无限制（检测到 {$total_fields} 个字段）\n";
					} else {
						echo "   ⚠️  字段数量: {$total_fields}（如果插件字段很多，应该 >20）\n";
					}
				}
			} else {
				echo "   ❌ 模板格式错误\n";
			}
		} catch ( Exception $e ) {
			echo "   ❌ 生成失败: " . $e->getMessage() . "\n";
		}
	} else {
		echo "⚠️  未检测到内容插件\n";
	}
} else {
	echo "❌ Scanner 或辅助函数不可用\n";
}

echo "\n";

// ============================================================================
// Summary
// ============================================================================
echo "=================================================\n";
echo "测试总结\n";
echo "=================================================\n\n";

$summary = array(
	'插件激活' => $is_active,
	'新架构 v0.2.0' => class_exists( 'WPTSALL\WPTSALL' ),
	'模块目录' => $all_exist,
	'验证器迁移' => class_exists( 'WPTSALL\Models\Validators\Template_Validator' ),
	'服务容器' => function_exists( 'wptsall' ),
	'向后兼容' => class_exists( 'WPTSALL_Template_Validator' ),
	'扫描器改进' => method_exists( 'WPTSALL_Scanner', 'is_auto_increment_field' ),
	'数据库表' => $table_count === count( $tables ),
);

$passed = array_filter( $summary );
$total  = count( $summary );
$pass_count = count( $passed );

echo "通过: {$pass_count}/{$total}\n\n";

foreach ( $summary as $item => $status ) {
	echo ( $status ? '✅' : '❌' ) . " {$item}\n";
}

echo "\n";

if ( $pass_count === $total ) {
	echo "🎉 所有测试通过！插件工作正常。\n";
} else {
	echo "⚠️  部分测试失败，请检查上述输出。\n";
}

echo "\n";
echo "站点地址: " . get_site_url() . "\n";
echo "管理地址: " . admin_url() . "\n";
echo "\n";

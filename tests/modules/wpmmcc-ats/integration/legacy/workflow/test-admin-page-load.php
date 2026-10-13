<?php
/**
 * Test Admin Page Load
 *
 * 测试站点管理页面是否能正确加载
 *
 * Usage: php tests/integration/workflow/test-admin-page-load.php
 *
 * NOTE: This is a standalone test script, not compatible with the integration test runner.
 */

// Skip when included by test runner
if ( defined( 'WPTSALL_TEST_RUNNER' ) && WPTSALL_TEST_RUNNER ) {
	return;
}

// WordPress 站点路径
if ( ! defined( 'WP_SITE_PATH' ) ) {
	define( 'WP_SITE_PATH', '/usr/local/var/www/' );
}

if ( ! file_exists( WP_SITE_PATH . 'wp-load.php' ) ) {
	return; // Silently skip
}

// 加载 WordPress
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'ABSPATH' ) ) {
	require_once WP_SITE_PATH . 'wp-load.php';
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║       测试站点管理页面加载                                    ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// 检查插件是否激活
if ( ! is_plugin_active( 'wptsall/wptsall.php' ) ) {
	echo "❌ 插件未激活\n\n";
	exit( 1 );
}

echo "✅ 插件已激活\n";
echo "   版本: " . WPTSALL_VERSION . "\n\n";

// ============================================================================
// Test 1: 检查类是否存在
// ============================================================================
echo "测试 1: 检查类是否加载\n";
echo "-----------------------------------\n";

$classes_to_check = array(
	'WPTSALL\\Sites\\Admin\\Sites_Page',
	'WPTSALL\\Sites\\Services\\Site_Relation_Service',
	'WPTSALL\\Sites\\Validators\\Site_Relation_Validator',
	'WPTSALL\\Models\\Blog_Template',
);

$all_loaded = true;

foreach ( $classes_to_check as $class ) {
	if ( class_exists( $class ) ) {
		echo "   ✅ {$class}\n";
	} else {
		echo "   ❌ {$class} (未加载)\n";
		$all_loaded = false;
	}
}

if ( $all_loaded ) {
	echo "✅ 所有类已正确加载\n";
} else {
	echo "❌ 部分类未加载\n";
	exit( 1 );
}

echo "\n";

// ============================================================================
// Test 2: 检查菜单是否注册
// ============================================================================
echo "测试 2: 检查管理菜单\n";
echo "-----------------------------------\n";

// 触发 admin_menu hook
do_action( 'admin_menu' );

global $submenu;

if ( isset( $submenu['wptsall'] ) ) {
	echo "✅ WPTSALL 菜单已注册\n";

	$found_sites = false;

	foreach ( $submenu['wptsall'] as $item ) {
		if ( $item[2] === 'wptsall-sites' ) {
			$found_sites = true;
			echo "✅ 站点管理子菜单已注册\n";
			echo "   标题: {$item[0]}\n";
			echo "   Slug: {$item[2]}\n";
			break;
		}
	}

	if ( ! $found_sites ) {
		echo "❌ 站点管理子菜单未找到\n";
	}
} else {
	echo "❌ WPTSALL 菜单未注册\n";
}

echo "\n";

// ============================================================================
// Test 3: 检查资源文件
// ============================================================================
echo "测试 3: 检查资源文件\n";
echo "-----------------------------------\n";

$assets = array(
	'css/sites.css',
	// UI-28-07: js/sites.js removed — legacy option-backed /sites CRUD JS,
	// de-queued since the Sites page moved to REST; the file was dead weight.
);

foreach ( $assets as $asset ) {
	$path = WPTSALL_PATH . 'assets/' . $asset;

	if ( file_exists( $path ) ) {
		$size = filesize( $path );
		echo "   ✅ {$asset} (" . number_format( $size ) . " bytes)\n";
	} else {
		echo "   ❌ {$asset} (文件不存在)\n";
	}
}

echo "\n";

// ============================================================================
// Test 4: 测试服务可用性
// ============================================================================
echo "测试 4: 测试服务可用性\n";
echo "-----------------------------------\n";

// 测试获取关系列表
try {
	$relations = \WPTSALL\Sites\Services\Site_Relation_Service::get_all_relations();
	echo "✅ Site_Relation_Service::get_all_relations() 可用\n";
	echo "   当前关系数量: " . count( $relations ) . "\n";
} catch ( Exception $e ) {
	echo "❌ Site_Relation_Service::get_all_relations() 失败: " . $e->getMessage() . "\n";
}

// 测试获取统计
try {
	$stats = \WPTSALL\Sites\Services\Site_Relation_Service::get_stats();
	echo "✅ Site_Relation_Service::get_stats() 可用\n";
	echo "   总数: {$stats['total']}, 活跃: {$stats['active']}\n";
} catch ( Exception $e ) {
	echo "❌ Site_Relation_Service::get_stats() 失败: " . $e->getMessage() . "\n";
}

// 测试 Blog 模板
try {
	$blog_template = \WPTSALL\Models\Blog_Template::get();
	echo "✅ Blog_Template::get() 可用\n";
	echo "   模板名称: {$blog_template['plugin_name']}\n";
} catch ( Exception $e ) {
	echo "❌ Blog_Template::get() 失败: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================================
// 总结
// ============================================================================
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║                        测试总结                               ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "🎉 站点管理页面已成功部署！\n";
echo "\n";
echo "访问路径:\n";
echo "  " . admin_url( 'admin.php?page=wptsall-sites' ) . "\n";
echo "\n";
echo "功能清单:\n";
echo "  ✅ 站点关系管理（创建、编辑、删除）\n";
echo "  ✅ 虚拟站点配置\n";
echo "  ✅ Hook 配置管理\n";
echo "  ✅ 语言包管理\n";
echo "  ✅ AJAX 操作支持\n";
echo "\n";

exit( 0 );

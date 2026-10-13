<?php
/**
 * WPTSALL Unit Tests Runner
 *
 * 通过 wp-load.php 加载 WordPress 环境来运行单元测试
 * 不需要安装 WordPress Test Library
 *
 * Usage: php tests/scripts/run-unit-tests.php
 *
 * @package WPTSALL
 * @since 0.3.0
 */

/**
 * Detect the active WordPress site path for local macOS and Linux test hosts.
 *
 * @return string|null
 */
function wptsall_detect_wp_site_path() {
	$candidates = array(
		getenv( 'WPTSALL_WP_ROOT' ),
		getenv( 'WP_SITE_PATH' ),
		getenv( 'WP_ROOT' ),
		'/var/www/wordpress',
		'/usr/local/var/www',
	);

	foreach ( $candidates as $candidate ) {
		if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
			continue;
		}

		$normalized = rtrim( trim( $candidate ), '/\\' ) . '/';
		if ( file_exists( $normalized . 'wp-load.php' ) ) {
			return $normalized;
		}
	}

	return null;
}

$wp_site_path = wptsall_detect_wp_site_path();
if ( null === $wp_site_path ) {
	die( "❌ WordPress 未找到。请设置 WPTSALL_WP_ROOT、WP_SITE_PATH 或 WP_ROOT。\n" );
}

define( 'WP_SITE_PATH', $wp_site_path );

// ============================================================
// 在加载 WordPress 之前管理插件
// ============================================================

// 加载 wp-config.php 获取数据库配置（不加载 WordPress）
$wp_config = file_get_contents( WP_SITE_PATH . 'wp-config.php' );
preg_match( "/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $wp_config, $db_name );
preg_match( "/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $wp_config, $db_user );
preg_match( "/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]*?)['\"]\s*\)/", $wp_config, $db_pass );
preg_match( "/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $wp_config, $db_host );
preg_match( "/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]\s*;/", $wp_config, $table_prefix );

// Docker official image uses getenv_docker('WORDPRESS_DB_*') — prefer env when present.
$db_name = getenv( 'WORDPRESS_DB_NAME' ) ?: ( $db_name[1] ?? 'wordpress' );
$db_user = getenv( 'WORDPRESS_DB_USER' ) ?: ( $db_user[1] ?? 'root' );
$db_pass = ( false !== getenv( 'WORDPRESS_DB_PASSWORD' ) ) ? (string) getenv( 'WORDPRESS_DB_PASSWORD' ) : ( $db_pass[1] ?? '' );
$db_host = getenv( 'WORDPRESS_DB_HOST' ) ?: ( $db_host[1] ?? 'localhost' );
$table_prefix = getenv( 'WORDPRESS_TABLE_PREFIX' ) ?: ( $table_prefix[1] ?? 'wp_' );

// 检测多站点环境（通过 wp-config.php 中的 MULTISITE 常量）
$is_multisite = preg_match( "/define\s*\(\s*['\"]MULTISITE['\"]\s*,\s*true\s*\)/i", $wp_config );

// 连接数据库
$mysqli = new mysqli( $db_host, $db_user, $db_pass, $db_name );
if ( $mysqli->connect_error ) {
	die( "❌ 数据库连接失败: " . $mysqli->connect_error . "\n" );
}
$mysqli->set_charset( 'utf8mb4' );

// ============================================================
// 崩溃修复：插件状态快照（2026-09-21）
// ============================================================
// 本 runner 会在运行时激活模块所需插件，并在 register_shutdown_function
// 里恢复原始 active_plugins。被 timeout/SIGKILL 杀死的跑不会执行
// shutdown 恢复 → 死跑的插件集泄漏进数据库，下一跑带着污染状态启动
// （已确认的污染类：测试框架隔离）。修复方式：每跑启动时写
// {marker, snapshot} 对；残留的 marker 意味着上一跑死于非命 → 先按
// 快照恢复，再捕获/覆盖快照。shutdown 正常执行时清掉 marker。
$runner_state_dir = sys_get_temp_dir() . '/wptsall-unit-runner-state';
$runner_state_key = md5( $db_host . '|' . $db_name . '|' . $table_prefix );
$runner_marker    = $runner_state_dir . '/' . $runner_state_key . '.running';
$runner_snapshot = $runner_state_dir . '/' . $runner_state_key . '.json';
if ( file_exists( $runner_marker ) && file_exists( $runner_snapshot ) ) {
	$snap = json_decode( (string) file_get_contents( $runner_snapshot ), true );
	if ( is_array( $snap ) && isset( $snap['active_plugins'] ) && is_array( $snap['active_plugins'] ) ) {
		$mysqli->query( "UPDATE {$table_prefix}options SET option_value = '" . $mysqli->real_escape_string( serialize( $snap['active_plugins'] ) ) . "' WHERE option_name = 'active_plugins'" );
		if ( $is_multisite && isset( $snap['active_sitewide_plugins'] ) && is_array( $snap['active_sitewide_plugins'] ) && ! empty( $snap['active_sitewide_plugins'] ) ) {
			$mysqli->query( "UPDATE {$table_prefix}sitemeta SET meta_value = '" . $mysqli->real_escape_string( serialize( $snap['active_sitewide_plugins'] ) ) . "' WHERE meta_key = 'active_sitewide_plugins'" );
		}
		echo "⚠️  检测到上次运行未正常退出（插件状态泄漏），已按快照恢复\n";
	}
	// marker 由下方新快照覆盖重写；shutdown 正常执行时清除。
}

// 获取当前激活的插件（普通站点插件）
$result = $mysqli->query( "SELECT option_value FROM {$table_prefix}options WHERE option_name = 'active_plugins'" );
$row = $result->fetch_assoc();
$original_active_plugins = $row ? unserialize( $row['option_value'] ) : array();
if ( ! is_array( $original_active_plugins ) ) {
	$original_active_plugins = array();
}

// 获取网络激活的插件（多站点环境）
$original_sitewide_plugins = array();
if ( $is_multisite ) {
	$result = $mysqli->query( "SELECT meta_value FROM {$table_prefix}sitemeta WHERE meta_key = 'active_sitewide_plugins'" );
	$row = $result->fetch_assoc();
	if ( $row ) {
		$original_sitewide_plugins = unserialize( $row['meta_value'] );
		if ( ! is_array( $original_sitewide_plugins ) ) {
			$original_sitewide_plugins = array();
		}
	}
}

// 崩溃修复快照：此时捕获的正是 shutdown 要写回的状态。marker 由
// shutdown 清除；下一跑若发现 marker 残留，即按此快照恢复。
if ( ! is_dir( $runner_state_dir ) ) {
	@mkdir( $runner_state_dir, 0777, true );
}
file_put_contents( $runner_snapshot, json_encode( array(
	'active_plugins'          => $original_active_plugins,
	'active_sitewide_plugins' => $original_sitewide_plugins,
	'is_multisite'            => $is_multisite,
) ) );
file_put_contents( $runner_marker, (string) time() );

// ============================================================
// 加载测试环境配置
// ============================================================

$config_dir = dirname( __FILE__ ) . '/config';
$global_config = file_exists( $config_dir . '/global.php' ) ? require( $config_dir . '/global.php' ) : array();

// 干扰测试的插件列表（从配置文件加载）
$problematic_plugins = $global_config['disabled_plugins'] ?? array(
	'masterstudy-lms-learning-management-system/masterstudy-lms-learning-management-system.php',
);

// 测试需要的插件列表（基础插件 + 模块插件）
// Canonical Lab mount: wpmmcc-ats/wpmmcc-ats.php
$plugin_candidates = array(
	'wpmmcc-ats/wpmmcc-ats.php',
);
$detected_plugin = $plugin_candidates[0];
foreach ( $plugin_candidates as $candidate ) {
	if ( file_exists( WP_SITE_PATH . 'wp-content/plugins/' . $candidate ) ) {
		$detected_plugin = $candidate;
		break;
	}
}

$required_plugins = $global_config['base_plugins'] ?? array(
	$detected_plugin,
);

// 加载各模块配置，合并需要的插件
$module_configs = array( 'models', 'sites', 'tasks', 'hooks', 'templates', 'core', 'client-pairing' );
foreach ( $module_configs as $module ) {
	$module_config_file = $config_dir . '/' . $module . '.php';
	if ( file_exists( $module_config_file ) ) {
		$module_config = require( $module_config_file );
		if ( ! empty( $module_config['required_plugins'] ) ) {
			$required_plugins = array_merge( $required_plugins, $module_config['required_plugins'] );
		}
	}
}
$required_plugins = array_unique( $required_plugins );

// 过滤掉干扰插件（普通站点插件）
$test_plugins = array_filter( $original_active_plugins, function( $plugin ) use ( $problematic_plugins ) {
	return ! in_array( $plugin, $problematic_plugins );
} );
$test_plugins = array_values( $test_plugins ); // 重新索引

// 确保必需插件在列表中——仅当插件文件真实存在。
// 干净安装环境（module-ci runner、fresh slot）没有 woocommerce/bbpress 等
// 第三方插件；无条件写入 active_plugins 会造成“假激活”（is_plugin_active
// 只查选项，不查文件），扫描器随后正确返回 plugin_not_found 而相关用例
// 误报失败。文件不存在时不写入，依赖插件的用例经 requirePlugin() 以
// [SKIPPED] 优雅跳过（与数据依赖用例的既有惯例一致）；lab 有插件文件，
// 行为不变。（2026-09-09 module-ci shard 1/3 失败根因。）
foreach ( $required_plugins as $plugin ) {
	if ( ! in_array( $plugin, $test_plugins )
		&& file_exists( WP_SITE_PATH . 'wp-content/plugins/' . $plugin ) ) {
		$test_plugins[] = $plugin;
	}
}

// 过滤掉干扰插件（网络激活插件）
$test_sitewide_plugins = array();
$disabled_sitewide_plugins = array();
if ( $is_multisite && ! empty( $original_sitewide_plugins ) ) {
	foreach ( $original_sitewide_plugins as $plugin => $timestamp ) {
		if ( in_array( $plugin, $problematic_plugins ) ) {
			$disabled_sitewide_plugins[ $plugin ] = $timestamp;
		} else {
			$test_sitewide_plugins[ $plugin ] = $timestamp;
		}
	}
}

// 临时更新 active_plugins
$test_plugins_serialized = serialize( $test_plugins );
$mysqli->query( "UPDATE {$table_prefix}options SET option_value = '" . $mysqli->real_escape_string( $test_plugins_serialized ) . "' WHERE option_name = 'active_plugins'" );

// 临时更新网络激活插件（如果是多站点）
if ( $is_multisite && ! empty( $disabled_sitewide_plugins ) ) {
	$test_sitewide_serialized = serialize( $test_sitewide_plugins );
	$mysqli->query( "UPDATE {$table_prefix}sitemeta SET meta_value = '" . $mysqli->real_escape_string( $test_sitewide_serialized ) . "' WHERE meta_key = 'active_sitewide_plugins'" );
}

// 记录被禁用的插件
$disabled_plugins = array_diff( $original_active_plugins, $test_plugins );

// 关闭数据库连接（WordPress 会重新连接）
$mysqli->close();

// ============================================================
// 解析命令行参数（在 WordPress 加载前）
// ============================================================
$file_filter = '';
$scope = 'all';
$shard_index = 0;
$shard_total = 0;
foreach ( $argv as $arg ) {
	if ( strpos( $arg, '--file=' ) === 0 ) {
		$file_filter = substr( $arg, 7 );
	}
	if ( strpos( $arg, '--scope=' ) === 0 ) {
		$scope = substr( $arg, 8 );
	}
	// --shard=i/N：按 (文件序号 % N) 取第 i 片，用于多容器并行分片。
	if ( strpos( $arg, '--shard=' ) === 0 ) {
		$shard_parts = explode( '/', substr( $arg, 8 ) );
		$shard_index = max( 1, (int) $shard_parts[0] );
		$shard_total = max( 1, (int) ( $shard_parts[1] ?? 0 ) );
	}
}
if ( ! in_array( $scope, array( 'free', 'pro', 'all' ), true ) ) {
	die( "❌ Invalid --scope value: {$scope}. Use: free, pro, all\n" );
}

// 定义 Free/Pro 模块列表
$free_modules = array( 'core', 'models', 'sites', 'hooks', 'templates', 'log', 'tasks', 'sync', 'translation-memory' );
$pro_modules  = array();
// client-pairing is mixed — files self-skip if Pro not available

// 注册脚本结束时恢复原始插件
register_shutdown_function( function() use ( $original_active_plugins, $original_sitewide_plugins, $is_multisite, $db_host, $db_user, $db_pass, $db_name, $table_prefix, $runner_marker ) {
	$mysqli = new mysqli( $db_host, $db_user, $db_pass, $db_name );
	if ( ! $mysqli->connect_error ) {
		// 恢复普通站点插件
		$original_serialized = serialize( $original_active_plugins );
		$mysqli->query( "UPDATE {$table_prefix}options SET option_value = '" . $mysqli->real_escape_string( $original_serialized ) . "' WHERE option_name = 'active_plugins'" );

		// 恢复网络激活插件（如果是多站点）
		if ( $is_multisite && ! empty( $original_sitewide_plugins ) ) {
			$original_sitewide_serialized = serialize( $original_sitewide_plugins );
			$mysqli->query( "UPDATE {$table_prefix}sitemeta SET meta_value = '" . $mysqli->real_escape_string( $original_sitewide_serialized ) . "' WHERE meta_key = 'active_sitewide_plugins'" );
		}

		$mysqli->close();
		echo "\n⚙️  已恢复原始插件配置\n";
	}
	// 正常退出：清除崩溃修复 marker（下一跑据此判定本跑已自行恢复）。
	@unlink( $runner_marker );
} );

// 加载 WordPress
define( 'WP_USE_THEMES', false );
require_once WP_SITE_PATH . 'wp-load.php';

// 定义测试根目录
// Phase 4A three-layer refactor: tests live directly under tests/modules/wpmmcc-ats/.
// Layout resolution (2026-09-09): prefer the directory that actually contains
// this unit/ tree — that is dirname(__DIR__) in BOTH supported layouts:
//   repo checkout: tests/modules/wpmmcc-ats/unit -> tests/modules/wpmmcc-ats
//   copied runner: /tmp/tests/unit               -> /tmp/tests
// The old math (dirname(__DIR__, 2) . '/tests') only resolved the copied
// layout; from a repo checkout it pointed at a nonexistent directory and
// die()'d — and die() with a message string exits 0, which the shard runner
// then misread as "0 passed, 0 failed" green (caught on module-ci 2026-09-09).
// The pre-refactor path ($repo_root . '/wptsall/tests') stays as a fallback.
$repo_root = dirname( __DIR__, 2 );
$canonical_tests_dir = dirname( __DIR__ );
if ( ! is_dir( $canonical_tests_dir . '/unit' ) ) {
	$canonical_tests_dir = $repo_root . '/wptsall/tests';
}
if ( ! is_dir( $canonical_tests_dir . '/unit' ) ) {
	die( "❌ WPTSALL tests directory not found near " . __DIR__ . "\n" );
}
define( 'WPTSALL_TESTS_DIR', $canonical_tests_dir );
define( 'WPTSALL_DEV_DIR', $repo_root );

// 挂账销案（批 Q 定责 / 2026-09-24 收口）：批 O6 将站点验证加密腿从
// 「死默认 RSA-2048 PEM」（私钥在任何仓/部署都不存在，服务器 X25519
// 永远解不开——加密虚构）改为必配 X25519 sealed box 常量，产品修复正确。
// 但单测槽从未供给该常量：09-22 前的 test_signed_payload_carries_contract_
// identity 绿骑的是已删除的死默认，批 O6 落地后该测试以
// 「期望数组」红（consume_nonce_and_sign 因 encryption_failed 返回 WP_Error）。
// 此处为单测环境确定性供给一把固定测试公钥——对齐 journey 车道
// run-playwright-journey-three-system.sh 的 wp-cli config set
// WPTSALL_SERVER_PUBLIC_KEY 先例；仅公钥半（单测断言签名/规范化消息不变
// 性，不解密），私钥半刻意不留。生产环境仍须从服务器
// GET /api/v1/domains/verification-public-key 取 live public_key_hex 写入
// wp-config.php（见 class-site-verification.php 批 O6 注释）。
if ( ! defined( 'WPTSALL_SERVER_PUBLIC_KEY' ) ) {
	define(
		'WPTSALL_SERVER_PUBLIC_KEY',
		'1ab8b76aa2e789172d0851ab6623238bf61f60332d4b7ccc5f5cf0d94f80ce24'
	);
}

echo "=================================================\n";
echo "WPTSALL Unit Tests Runner\n";
echo "=================================================\n";
echo "WordPress 版本: " . get_bloginfo( 'version' ) . "\n";
echo "PHP 版本: " . PHP_VERSION . "\n";
echo "测试目录: " . WPTSALL_TESTS_DIR . "/unit/\n";
echo "测试范围: {$scope}\n";
echo "\n";

// 检查插件是否激活
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// 检查被测插件是否激活。
// 必须检查 $detected_plugin（wpmmcc-ats/wpmmcc-ats.php），而不是
// $required_plugins[0]——后者是模块 config 合并出的第三方依赖（如
// woocommerce），在无该插件文件的干净环境（module-ci runner）下会把
// “未安装 woocommerce”误报成“WPTSALL 插件未激活”并 die（die 带消息
// 退出码为 0，靠 runner 的 no-verdict 守卫兜底变红，但语义一直是错的：
// 旧横幅会打印“WPTSALL 插件已激活 (woocommerce/woocommerce.php)”）。
$plugin_file = $detected_plugin;
if ( ! is_plugin_active( $plugin_file ) ) {
	die( "❌ WPTSALL 插件未激活，请先激活插件 ({$plugin_file})\n" );
}

echo "✅ WPTSALL 插件已激活 ({$plugin_file})\n";

// Pro 插件激活检查
$pro_active = is_plugin_active( 'wptsall-pro/wptsall-pro.php' );
if ( in_array( $scope, array( 'pro', 'all' ), true ) ) {
	if ( $pro_active ) {
		echo "✅ WPTSALL Pro 插件已激活\n";
	} else {
		if ( $scope === 'pro' ) {
			die( "❌ WPTSALL Pro 插件未激活，无法运行 Pro 测试\n" );
		}
		echo "⚠️  WPTSALL Pro 未激活，Pro 测试将被跳过\n";
	}
}

// 显示禁用的插件信息
if ( ! empty( $disabled_plugins ) ) {
	echo "⚙️  已禁用干扰测试的插件（站点级）: " . count( $disabled_plugins ) . " 个\n";
}
if ( ! empty( $disabled_sitewide_plugins ) ) {
	echo "⚙️  已禁用干扰测试的插件（网络级）: " . count( $disabled_sitewide_plugins ) . " 个\n";
	foreach ( $disabled_sitewide_plugins as $plugin => $timestamp ) {
		echo "   - " . basename( dirname( $plugin ) ) . "\n";
	}
}
echo "\n";

// Ensure schema is upgraded before running tests.
// Remote test hosts may keep older tables between runs.
$schema_bootstrap_functions = array(
	'wptsall_create_model_tables',
	'wptsall_create_field_mapping_tables',
	'wptsall_create_site_relations_table',
	'wptsall_create_relation_models_table',
	'wptsall_create_virtual_sites_table',
	'wptsall_create_relation_post_type_configs_table',
	'wptsall_create_user_mappings_table',
	'wptsall_create_templates_table',
	'wptsall_create_template_entries_table',
	'wptsall_create_languages_table',
	'wptsall_init_tasks_tables',
	'wptsall_create_tasks_table',
	'wptsall_create_task_logs_table',
	'wptsall_create_task_items_table',
	'wptsall_create_task_jobs_table',
	'wptsall_create_translation_results_table',
	'wptsall_ensure_relation_scoped_mapping_tables',
);
foreach ( $schema_bootstrap_functions as $schema_function ) {
	if ( function_exists( $schema_function ) ) {
		call_user_func( $schema_function );
	}
}

if ( function_exists( 'wptsall_run_migrations' ) ) {
	wptsall_run_migrations();
}

echo "✅ 数据库表已就绪\n\n";

// 注册错误处理器以捕获致命错误
register_shutdown_function( function() {
	$error = error_get_last();
	if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ) ) ) {
		echo "\n\n❌ 致命错误:\n";
		echo "   类型: " . $error['type'] . "\n";
		echo "   消息: " . $error['message'] . "\n";
		echo "   文件: " . $error['file'] . "\n";
		echo "   行号: " . $error['line'] . "\n";
	}
} );

/**
 * 简易测试框架
 */
class SimpleTestRunner {
	private $passed = 0;
	private $failed = 0;
	private $skipped = 0;
	private $errors = array();
	private $current_test = '';

	public function run_test_file( $file ) {
		$this->reset_environment();
		$class_name = $this->get_class_name_from_file( $file );
		if ( ! $class_name ) {
			$this->record_load_failure( $file, '无法解析测试类（无 class 声明可提取）' );
			return;
		}

		try {
			require_once $file;
		} catch ( \Throwable $e ) {
			$this->record_load_failure( $file, '加载测试文件失败: ' . $e->getMessage() );
			return;
		}

		if ( ! class_exists( $class_name ) ) {
			$this->record_load_failure( $file, "测试类不存在: {$class_name}" );
			return;
		}

		echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
		echo "📂 {$class_name}\n";
		echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
		flush();

		try {
			$test_obj = new $class_name();
		} catch ( \Throwable $e ) {
			$this->record_load_failure( $class_name, '创建测试对象失败: ' . $e->getMessage() );
			return;
		}

		$methods = get_class_methods( $test_obj );

		foreach ( $methods as $method ) {
			if ( strpos( $method, 'test_' ) === 0 ) {
				$this->run_single_test( $test_obj, $method );
			}
		}

		echo "\n";
		flush();
	}

	/**
	 * Reset environment between test files.
	 *
	 * Every test file runs in the same PHP process, so state simulated by
	 * one file would otherwise leak into all later files. Two concrete
	 * leaky states observed in this suite:
	 *
	 * - set_current_screen() leaves $GLOBALS['current_screen'] set, and
	 *   WP 6.8+ is_admin() returns that screen's in_admin() → every later
	 *   is_admin()-guarded code path (archive meta-query filtering,
	 *   router parse handling, SEO output) silently switches to admin mode.
	 * - AJAX-style tests set $_SERVER['REQUEST_METHOD'] = 'POST' without
	 *   restoring it; admin-user simulations leak the current user.
	 *
	 * Constants (REST_REQUEST, WP_ADMIN, DOING_AJAX) cannot be undefined
	 * in PHP, so tests must not define them; this reset keeps the mutable
	 * global state neutral at the start of every file.
	 */
	/**
	 * T-04 (doc 02): a test file that cannot be parsed/loaded/instantiated is
	 * a FAILED file, never a warning-and-continue. The old echo+return paths
	 * let the summary report "0 failed" and exit 0 while nothing in the file
	 * ran — a false-green hole in the lane.
	 */
	private function record_load_failure( $test, $message ) {
		$this->failed++;
		$this->errors[] = array(
			'test'    => $test,
			'message' => $message,
		);
		echo "❌ 加载/实例化失败: {$test}\n";
		echo "   → {$message}\n";
		flush();
	}

	private function reset_environment() {
		unset( $GLOBALS['current_screen'] );
		unset( $GLOBALS['screen'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                     = array();
		$_POST                    = array();
		$_REQUEST                 = array();
		wp_set_current_user( 0 );
	}

	private function run_single_test( $test_obj, $method ) {
		$this->current_test = $method;
		$display_name = str_replace( 'test_', '', $method );
		$display_name = str_replace( '_', ' ', $display_name );

		$started_at = microtime( true );

		try {
			// Call setUp if exists
			if ( method_exists( $test_obj, 'setUp' ) ) {
				$test_obj->setUp();
			}

			// Run test
			$test_obj->$method();

			// Call tearDown if exists
			if ( method_exists( $test_obj, 'tearDown' ) ) {
				$test_obj->tearDown();
			}

			$this->passed++;
			// Surface slow tests (>1s) so full-run stalls can be attributed
			// to a single case without re-running the whole file.
			$elapsed = microtime( true ) - $started_at;
			if ( $elapsed >= 1.0 ) {
				echo "  ✅ {$display_name}  [" . number_format( $elapsed, 1 ) . "s]\n";
			} else {
				echo "  ✅ {$display_name}\n";
			}
		} catch ( \Throwable $e ) {
			if ( strpos( $e->getMessage(), '[SKIPPED]' ) === 0 ) {
				$this->skipped++;
				echo "  ⏭️ {$display_name}\n";
				if ( strlen( $e->getMessage() ) > 11 ) {
					echo "     → " . trim( substr( $e->getMessage(), 11 ) ) . "\n";
				}
				return;
			}

			$this->failed++;
			$this->errors[] = array(
				'test'    => $method,
				'message' => $e->getMessage(),
			);
			echo "  ❌ {$display_name}\n";
			echo "     → " . $e->getMessage() . "\n";
		}
	}

	private function get_class_name_from_file( $file ) {
		$content = file_get_contents( $file );

		// 移除注释以避免匹配注释中的 class 关键字
		// 移除多行注释 /* ... */
		$content_no_comments = preg_replace( '/\/\*.*?\*\//s', '', $content );
		// 移除单行注释 // ...
		$content_no_comments = preg_replace( '/\/\/.*$/m', '', $content_no_comments );

		// 检查命名空间 - 只匹配真正的 namespace 声明
		$namespace = '';
		if ( preg_match( '/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $content_no_comments, $ns_match ) ) {
			$namespace = $ns_match[1];
		}

		// 匹配类声明 - 必须是行首（可有空白）+ class + 类名
		if ( preg_match( '/^\s*class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content_no_comments, $class_match ) ) {
			$class_name = $class_match[1];
			if ( $namespace ) {
				return $namespace . '\\' . $class_name;
			}
			return $class_name;
		}

		return null;
	}

	public function get_results() {
		return array(
			'passed'  => $this->passed,
			'failed'  => $this->failed,
			'skipped' => $this->skipped,
			'errors'  => $this->errors,
		);
	}
}

/**
 * 简易 Factory 类 - 模拟 PHPUnit WordPress Test Library
 */
class SimpleFactory {
	public $user;
	public $post;
	public $term;
	public $comment;

	private $_created_users = array();
	private $_created_posts = array();
	private $_created_terms = array();
	private $_created_comments = array();

	public function __construct() {
		$this->user    = new SimpleUserFactory( $this );
		$this->post    = new SimplePostFactory( $this );
		$this->term    = new SimpleTermFactory( $this );
		$this->comment = new SimpleCommentFactory( $this );
	}

	public function track_user( $id ) {
		$this->_created_users[] = $id;
	}

	public function track_post( $id ) {
		$this->_created_posts[] = $id;
	}

	public function track_term( $id ) {
		$this->_created_terms[] = $id;
	}

	public function track_comment( $id ) {
		$this->_created_comments[] = $id;
	}

	public function cleanup() {
		// Clean up created comments
		foreach ( $this->_created_comments as $id ) {
			wp_delete_comment( $id, true );
		}
		// Clean up created posts
		foreach ( $this->_created_posts as $id ) {
			wp_delete_post( $id, true );
		}
		// Clean up created terms
		foreach ( $this->_created_terms as $term_data ) {
			wp_delete_term( $term_data['id'], $term_data['taxonomy'] );
		}
		// Clean up created users
		foreach ( $this->_created_users as $id ) {
			if ( function_exists( 'wpmu_delete_user' ) ) {
				wpmu_delete_user( $id );
			} else {
				wp_delete_user( $id );
			}
		}
		$this->_created_users    = array();
		$this->_created_posts    = array();
		$this->_created_terms    = array();
		$this->_created_comments = array();
	}
}

class SimpleUserFactory {
	private $factory;

	public function __construct( $factory ) {
		$this->factory = $factory;
	}

	public function create( $args = array() ) {
		$defaults = array(
			'user_login' => 'test_user_' . uniqid(),
			'user_pass'  => 'password123',
			'user_email' => 'test_' . uniqid() . '@example.com',
			'role'       => 'subscriber',
		);
		$args = wp_parse_args( $args, $defaults );

		$user_id = wp_insert_user( $args );
		if ( is_wp_error( $user_id ) ) {
			throw new Exception( 'Failed to create user: ' . $user_id->get_error_message() );
		}

		$this->factory->track_user( $user_id );
		return $user_id;
	}

	public function create_and_get( $args = array() ) {
		$id = $this->create( $args );
		return get_user_by( 'id', $id );
	}
}

class SimplePostFactory {
	private $factory;

	public function __construct( $factory ) {
		$this->factory = $factory;
	}

	public function create( $args = array() ) {
		$defaults = array(
			'post_title'   => 'Test Post ' . uniqid(),
			'post_content' => 'Test content.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);
		$args = wp_parse_args( $args, $defaults );

		$post_id = wp_insert_post( $args );
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( 'Failed to create post: ' . $post_id->get_error_message() );
		}

		$this->factory->track_post( $post_id );
		return $post_id;
	}

	public function create_and_get( $args = array() ) {
		$id = $this->create( $args );
		return get_post( $id );
	}
}

class SimpleTermFactory {
	private $factory;

	public function __construct( $factory ) {
		$this->factory = $factory;
	}

	public function create( $args = array() ) {
		$taxonomy = $args['taxonomy'] ?? 'category';
		$name     = $args['name'] ?? 'Test Term ' . uniqid();
		unset( $args['taxonomy'], $args['name'] );

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			throw new Exception( 'Failed to create term: ' . $result->get_error_message() );
		}

		$this->factory->track_term( array(
			'id'       => $result['term_id'],
			'taxonomy' => $taxonomy,
		) );
		return $result['term_id'];
	}
}

class SimpleCommentFactory {
	private $factory;

	public function __construct( $factory ) {
		$this->factory = $factory;
	}

	public function create( $args = array() ) {
		$defaults = array(
			'comment_content' => 'Test comment ' . uniqid(),
			'comment_author'  => 'Test Author',
		);
		$args = wp_parse_args( $args, $defaults );

		$comment_id = wp_insert_comment( $args );
		if ( ! $comment_id ) {
			throw new Exception( 'Failed to create comment' );
		}

		$this->factory->track_comment( $comment_id );
		return $comment_id;
	}
}

/**
 * 简易断言基类（模拟 PHPUnit）
 */
class SimpleTestCase {
	/**
	 * Factory for creating test data (instance + WP PHPUnit-compat static accessor).
	 * @var SimpleFactory|null
	 */
	protected $factory;

	/**
	 * Shared factory so both `$this->factory` and `self::factory()` work.
	 * @var SimpleFactory|null
	 */
	private static $shared_factory = null;

	/**
	 * Track created users for cleanup (legacy compatibility)
	 * @var array
	 */
	protected $_created_users = array();

	/**
	 * WP_UnitTestCase-compatible static factory accessor.
	 *
	 * @return SimpleFactory
	 */
	public static function factory() {
		if ( null === self::$shared_factory ) {
			self::$shared_factory = new SimpleFactory();
		}
		return self::$shared_factory;
	}

	public function setUp(): void {
		$this->factory = self::factory();
	}

	public function tearDown(): void {
		// Clean up factory-created data
		if ( self::$shared_factory ) {
			self::$shared_factory->cleanup();
		}
		self::$shared_factory = null;
		$this->factory        = null;
		// Clean up legacy tracked users
		foreach ( $this->_created_users as $user_id ) {
			if ( function_exists( 'wpmu_delete_user' ) ) {
				wpmu_delete_user( $user_id );
			} else {
				wp_delete_user( $user_id );
			}
		}
		$this->_created_users = array();
	}

	protected function assertEquals( $expected, $actual, $message = '' ) {
		if ( $expected !== $actual ) {
			throw new Exception( $message ?: "断言失败: 期望 " . var_export( $expected, true ) . ", 实际 " . var_export( $actual, true ) );
		}
	}

	protected function assertNotEquals( $expected, $actual, $message = '' ) {
		if ( $expected === $actual ) {
			throw new Exception( $message ?: "断言失败: 不期望 " . var_export( $expected, true ) );
		}
	}

	protected function assertSame( $expected, $actual, $message = '' ) {
		if ( $expected !== $actual ) {
			throw new Exception( $message ?: "断言失败: 期望严格相同 " . var_export( $expected, true ) . ", 实际 " . var_export( $actual, true ) );
		}
	}

	protected function assertNotSame( $expected, $actual, $message = '' ) {
		if ( $expected === $actual ) {
			throw new Exception( $message ?: "断言失败: 不期望严格相同 " . var_export( $expected, true ) );
		}
	}

	protected function assertTrue( $value, $message = '' ) {
		if ( $value !== true ) {
			throw new Exception( $message ?: "断言失败: 期望 true" );
		}
	}

	protected function assertFalse( $value, $message = '' ) {
		if ( $value !== false ) {
			throw new Exception( $message ?: "断言失败: 期望 false" );
		}
	}

	protected function assertNull( $value, $message = '' ) {
		if ( $value !== null ) {
			throw new Exception( $message ?: "断言失败: 期望 null" );
		}
	}

	protected function assertNotNull( $value, $message = '' ) {
		if ( $value === null ) {
			throw new Exception( $message ?: "断言失败: 不期望 null" );
		}
	}

	protected function assertIsArray( $value, $message = '' ) {
		if ( ! is_array( $value ) ) {
			throw new Exception( $message ?: "断言失败: 期望数组" );
		}
	}

	protected function assertIsInt( $value, $message = '' ) {
		if ( ! is_int( $value ) ) {
			throw new Exception( $message ?: "断言失败: 期望整数" );
		}
	}

	protected function assertIsString( $value, $message = '' ) {
		if ( ! is_string( $value ) ) {
			throw new Exception( $message ?: "断言失败: 期望字符串" );
		}
	}

	protected function assertArrayHasKey( $key, $array, $message = '' ) {
		if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
			throw new Exception( $message ?: "断言失败: 数组应包含键 '{$key}'" );
		}
	}

	protected function assertArrayNotHasKey( $key, $array, $message = '' ) {
		if ( is_array( $array ) && array_key_exists( $key, $array ) ) {
			throw new Exception( $message ?: "断言失败: 数组不应包含键 '{$key}'" );
		}
	}

	protected function assertContains( $needle, $haystack, $message = '' ) {
		if ( is_array( $haystack ) ) {
			if ( ! in_array( $needle, $haystack, true ) ) {
				throw new Exception( $message ?: "断言失败: 数组应包含 " . var_export( $needle, true ) );
			}
		} elseif ( is_string( $haystack ) ) {
			if ( strpos( $haystack, $needle ) === false ) {
				throw new Exception( $message ?: "断言失败: 字符串应包含 '{$needle}'" );
			}
		}
	}

	protected function assertNotContains( $needle, $haystack, $message = '' ) {
		if ( is_array( $haystack ) ) {
			if ( in_array( $needle, $haystack, true ) ) {
				throw new Exception( $message ?: "断言失败: 数组不应包含 " . var_export( $needle, true ) );
			}
		} elseif ( is_string( $haystack ) ) {
			if ( strpos( $haystack, $needle ) !== false ) {
				throw new Exception( $message ?: "断言失败: 字符串不应包含 '{$needle}'" );
			}
		}
	}

	protected function assertNotEmpty( $value, $message = '' ) {
		if ( empty( $value ) ) {
			throw new Exception( $message ?: "断言失败: 期望非空值" );
		}
	}

	protected function assertEmpty( $value, $message = '' ) {
		if ( ! empty( $value ) ) {
			throw new Exception( $message ?: "断言失败: 期望空值" );
		}
	}

	protected function assertGreaterThan( $expected, $actual, $message = '' ) {
		if ( $actual <= $expected ) {
			throw new Exception( $message ?: "断言失败: {$actual} 应大于 {$expected}" );
		}
	}

	protected function assertGreaterThanOrEqual( $expected, $actual, $message = '' ) {
		if ( $actual < $expected ) {
			throw new Exception( $message ?: "断言失败: {$actual} 应大于或等于 {$expected}" );
		}
	}

	protected function assertLessThanOrEqual( $expected, $actual, $message = '' ) {
		if ( $actual > $expected ) {
			throw new Exception( $message ?: "断言失败: {$actual} 应小于或等于 {$expected}" );
		}
	}

	protected function assertCount( $expected, $array, $message = '' ) {
		$actual = is_array( $array ) ? count( $array ) : 0;
		if ( $actual !== $expected ) {
			throw new Exception( $message ?: "断言失败: 期望 {$expected} 个元素，实际 {$actual} 个" );
		}
	}

	protected function assertInstanceOf( $expected, $actual, $message = '' ) {
		if ( ! ( $actual instanceof $expected ) ) {
			$actual_type = is_object( $actual ) ? get_class( $actual ) : gettype( $actual );
			throw new Exception( $message ?: "断言失败: 期望 {$expected} 实例，实际 {$actual_type}" );
		}
	}

	protected function assertStringContainsString( $needle, $haystack, $message = '' ) {
		if ( strpos( $haystack, $needle ) === false ) {
			throw new Exception( $message ?: "断言失败: 字符串应包含 '{$needle}'" );
		}
	}

	protected function assertStringNotContainsString( $needle, $haystack, $message = '' ) {
		if ( strpos( $haystack, $needle ) !== false ) {
			throw new Exception( $message ?: "断言失败: 字符串不应包含 '{$needle}'" );
		}
	}

	protected function assertStringStartsWith( $prefix, $string, $message = '' ) {
		if ( strpos( $string, $prefix ) !== 0 ) {
			throw new Exception( $message ?: "断言失败: 字符串应以 '{$prefix}' 开头" );
		}
	}

	protected function assertStringStartsNotWith( $prefix, $string, $message = '' ) {
		if ( strpos( $string, $prefix ) === 0 ) {
			throw new Exception( $message ?: "断言失败: 字符串不应以 '{$prefix}' 开头" );
		}
	}

	protected function assertNotFalse( $value, $message = '' ) {
		if ( $value === false ) {
			throw new Exception( $message ?: "断言失败: 不期望 false" );
		}
	}

	protected function assertIsObject( $value, $message = '' ) {
		if ( ! is_object( $value ) ) {
			throw new Exception( $message ?: "断言失败: 期望对象" );
		}
	}

	protected function assertIsBool( $value, $message = '' ) {
		if ( ! is_bool( $value ) ) {
			throw new Exception( $message ?: "断言失败: 期望布尔值" );
		}
	}

	protected function assertFileExists( $path, $message = '' ) {
		if ( ! file_exists( $path ) ) {
			throw new Exception( $message ?: "断言失败: 文件应存在 '{$path}'" );
		}
	}

	protected function assertMatchesRegularExpression( $pattern, $string, $message = '' ) {
		if ( ! preg_match( $pattern, $string ) ) {
			throw new Exception( $message ?: "断言失败: 字符串应匹配正则 '{$pattern}'" );
		}
	}

	protected function markTestSkipped( $message = '' ) {
		throw new Exception( "[SKIPPED] " . ( $message ?: "测试被跳过" ) );
	}

	protected function requirePlugin( $plugin_slug ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_file = "{$plugin_slug}/{$plugin_slug}.php";
		if ( ! is_plugin_active( $plugin_file ) ) {
			throw new Exception( "[SKIPPED] 插件未激活: {$plugin_slug}" );
		}
	}
}

// 为了兼容性，创建 WP_UnitTestCase 别名
class_alias( 'SimpleTestCase', 'WP_UnitTestCase' );

// 运行测试
$runner = new SimpleTestRunner();

// 收集测试文件 - 支持根目录和子目录
$test_files = array();

// 根目录测试文件（属于 Free 范围）
if ( in_array( $scope, array( 'free', 'all' ), true ) ) {
	$root_files = glob( WPTSALL_TESTS_DIR . '/unit/test-*.php' );
	if ( $root_files ) {
		$test_files = array_merge( $test_files, $root_files );
	}
}

// 根据 scope 确定子目录列表
switch ( $scope ) {
	case 'free':
		$subdirs = array_merge( $free_modules, array( 'client-pairing' ) );
		break;
	case 'pro':
		$subdirs = array_merge( $pro_modules, array( 'client-pairing' ) );
		break;
	case 'all':
	default:
		$subdirs = array_merge( $free_modules, $pro_modules, array( 'client-pairing' ) );
		break;
}

// 如果 Pro 未激活且 scope 为 all，移除 pro 模块
if ( $scope === 'all' && ! $pro_active ) {
	$subdirs = array_diff( $subdirs, $pro_modules );
}

// 子目录测试文件
foreach ( $subdirs as $subdir ) {
	$subdir_files = glob( WPTSALL_TESTS_DIR . "/unit/{$subdir}/test-*.php" );
	if ( $subdir_files ) {
		$test_files = array_merge( $test_files, $subdir_files );
	}
}

// 如果指定了 --file 参数，只运行匹配的文件
if ( $file_filter ) {
	$filtered_files = array();
	$file_filter    = ltrim( str_replace( '\\', '/', $file_filter ), '/' );
	foreach ( $test_files as $file ) {
		$normalized_file = str_replace( '\\', '/', $file );
		$relative_file   = ltrim( str_replace( str_replace( '\\', '/', WPTSALL_TESTS_DIR . '/unit/' ), '', $normalized_file ), '/' );
		if (
			strpos( $normalized_file, $file_filter ) !== false ||
			strpos( $relative_file, $file_filter ) !== false ||
			basename( $normalized_file ) === basename( $file_filter )
		) {
			$filtered_files[] = $file;
		}
	}
	$test_files = $filtered_files;
}

// 过滤掉需要 PHPUnit REST 环境的测试（从配置文件加载）
$skip_patterns = $global_config['skip_rest_tests'] ?? array(
	'test-sites-rest-controller.php',
	'test-model-rest-controller.php',
);

// 分片过滤（与 --file 组合使用）：文件列表在各分片进程中确定性一致
if ( $shard_total > 1 ) {
	$shard_files = array();
	$position    = 0;
	foreach ( $test_files as $file ) {
		if ( ( $position % $shard_total ) === ( $shard_index - 1 ) ) {
			$shard_files[] = $file;
		}
		$position++;
	}
	echo "分片: {$shard_index}/{$shard_total}，本片 " . count( $shard_files ) . '/' . count( $test_files ) . " 个文件\n";
	$test_files = $shard_files;
}

echo "发现 " . count( $test_files ) . " 个测试文件\n";
if ( $file_filter ) {
	echo "过滤器: {$file_filter}\n";
}
echo "(跳过 REST API 测试，这些需要完整的 PHPUnit 环境)\n\n";

foreach ( $test_files as $file ) {
	$filename = basename( $file );
	$skip = false;
	foreach ( $skip_patterns as $pattern ) {
		if ( $filename === $pattern ) {
			$skip = true;
			break;
		}
	}
	if ( $skip ) {
		echo "⏭️  跳过: {$filename} (需要 PHPUnit REST 环境)\n";
		continue;
	}
	$runner->run_test_file( $file );
}

// 输出结果
$results = $runner->get_results();
echo "=================================================\n";
echo "测试结果\n";
echo "=================================================\n";
echo "✅ 通过: {$results['passed']}\n";
echo "❌ 失败: {$results['failed']}\n";
echo "⏭️ 跳过: {$results['skipped']}\n";
echo "总计: " . ( $results['passed'] + $results['failed'] + $results['skipped'] ) . "\n";

if ( ! empty( $results['errors'] ) ) {
	echo "\n失败详情:\n";
	foreach ( $results['errors'] as $error ) {
		echo "  - {$error['test']}: {$error['message']}\n";
	}
}

echo "\n";
exit( $results['failed'] > 0 ? 1 : 0 );

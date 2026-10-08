<?php
/**
 * WPTSALL Integration Test Runner
 *
 * 运行集成测试，适用于单站点环境
 *
 * 目录结构:
 *   chains/      - 按 8 条链路组织的测试（主要）
 *   base/        - 测试基类
 *   legacy/      - 旧测试（已归档）
 *   helpers/     - 测试辅助类
 *   bootstrap/   - 测试前置脚本（环境准备，非测试）
 *   contracts/   - 合约测试（接口/API 验证）
 *   flows/       - 流程测试（端到端业务流程）
 *
 * 使用方法:
 *   php tests/integration/run.php                                    # 运行所有链路测试（chains suite，按顺序）
 *   php tests/integration/run.php --file=test-chain-1-model-scanning.php
 *   php tests/integration/run.php --legacy                           # 包含 legacy 测试
 *   php tests/integration/run.php --clean                            # 先清理数据再测试
 *   php tests/integration/run.php --level=L2                        # 运行特定层级
 *   php tests/integration/run.php --random                           # 随机顺序（不推荐）
 *   php tests/integration/run.php                                  # 默认 --suite=main（contracts+flows+chains）
 *   php tests/integration/run.php --suite=chains                    # 仅运行链路测试
 *   php tests/integration/run.php --suite=bootstrap                 # 运行引导脚本
 *   php tests/integration/run.php --suite=contracts                 # 运行合约测试
 *   php tests/integration/run.php --suite=flows                     # 运行流程测试
 *   php tests/integration/run.php --suite=all                       # 运行所有套件
 *   php tests/integration/run.php --suite=legacy                    # 运行 legacy 测试
 *
 * @package WPTSALL
 * @since 0.9.0
 */

// Colors for terminal output.
define( 'COLOR_RED', "\033[0;31m" );
define( 'COLOR_GREEN', "\033[0;32m" );
define( 'COLOR_YELLOW', "\033[1;33m" );
define( 'COLOR_BLUE', "\033[0;34m" );
define( 'COLOR_RESET', "\033[0m" );

// Global variable to track current file being loaded.
$GLOBALS['wptsall_current_test_file'] = '';

// Register shutdown function to catch fatal errors.
register_shutdown_function(
	function() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ), true ) ) {
			$file = $GLOBALS['wptsall_current_test_file'] ?? 'unknown';
			echo "\n" . COLOR_RED . "FATAL ERROR while loading: {$file}" . COLOR_RESET . "\n";
			echo COLOR_RED . "Error: {$error['message']}" . COLOR_RESET . "\n";
			echo COLOR_RED . "File: {$error['file']}:{$error['line']}" . COLOR_RESET . "\n";
		}
	}
);

// Parse arguments.
$args = array(
	'clean'  => false,
	'file'   => null,
	'legacy' => false,
	'level'  => null,
	'random' => false,
	'suite'  => 'main',
);

foreach ( $argv as $arg ) {
	if ( '--clean' === $arg ) {
		$args['clean'] = true;
	} elseif ( '--legacy' === $arg ) {
		$args['legacy'] = true;
	} elseif ( '--random' === $arg ) {
		$args['random'] = true;
	} elseif ( strpos( $arg, '--file=' ) === 0 ) {
		$args['file'] = substr( $arg, 7 );
	} elseif ( strpos( $arg, '--level=' ) === 0 ) {
		$args['level'] = strtoupper( substr( $arg, 8 ) );
	} elseif ( strpos( $arg, '--suite=' ) === 0 ) {
		$args['suite'] = strtolower( substr( $arg, 8 ) );
	}
}

// Validate suite value.
$valid_suites = array( 'main', 'chains', 'bootstrap', 'contracts', 'flows', 'all', 'legacy' );
if ( ! in_array( $args['suite'], $valid_suites, true ) ) {
	echo COLOR_RED . "Error: Invalid suite '{$args['suite']}'. Valid values: " . implode( ', ', $valid_suites ) . COLOR_RESET . "\n";
	exit( 1 );
}

// --legacy flag maps to suite=legacy for backwards compatibility.
if ( $args['legacy'] ) {
	$args['suite'] = 'legacy';
}

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
		'/var/www/html',
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
	echo COLOR_RED . "Error: WordPress root not found. Set WPTSALL_WP_ROOT, WP_SITE_PATH, or WP_ROOT." . COLOR_RESET . "\n";
	exit( 1 );
}
define( 'WP_SITE_PATH', $wp_site_path );

echo "\n";
echo COLOR_BLUE . "==========================================" . COLOR_RESET . "\n";
echo COLOR_BLUE . "WPTSALL Integration Test Runner" . COLOR_RESET . "\n";
echo COLOR_BLUE . "==========================================" . COLOR_RESET . "\n";
echo "\n";

// Clean data if requested.
if ( $args['clean'] ) {
	echo COLOR_YELLOW . "Cleaning test data..." . COLOR_RESET . "\n\n";
	$cleanup_script = __DIR__ . '/cleanup-test-data.php';
	if ( file_exists( $cleanup_script ) ) {
		passthru( 'php ' . escapeshellarg( $cleanup_script ) );
	} else {
		echo COLOR_RED . "Cleanup script not found!" . COLOR_RESET . "\n";
	}
	echo "\n";
}

// Mark that we're running in test runner context
// This allows standalone test scripts to skip auto-execution
define( 'WPTSALL_TEST_RUNNER', true );

// Define dev license constant before WordPress loads only when wp-config.php
// does not already declare it, otherwise wp-config.php will emit a redefinition
// warning during bootstrap.
$wp_config_path = WP_SITE_PATH . 'wp-config.php';
$wp_config_raw  = file_exists( $wp_config_path ) ? file_get_contents( $wp_config_path ) : '';
$wp_config_declares_dev_license = is_string( $wp_config_raw )
	&& preg_match( "/define\s*\(\s*['\"]WPTSALL_DEV_LICENSE['\"]\s*,/i", $wp_config_raw );

// Allows pro/client-pairing features to be exercised during integration tests.
if ( ! defined( 'WPTSALL_DEV_LICENSE' ) && ! $wp_config_declares_dev_license ) {
	define( 'WPTSALL_DEV_LICENSE', true );
}

// Keep integration runner deterministic: disable WP cron spawning to avoid
// ALTERNATE_WP_CRON redirect/exit side effects during REST test execution.
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'ALTERNATE_WP_CRON' ) ) {
	define( 'ALTERNATE_WP_CRON', false );
}
if ( ! defined( 'WPTSALL_INTEGRATION_RUNNER' ) ) {
	define( 'WPTSALL_INTEGRATION_RUNNER', true );
}

// Legacy-shaped flow fixtures may omit source_revision; production Path B
// still requires it. Opt in only for this in-process integration harness
// (must be defined before wp-load / plugin bootstrap).
if ( ! defined( 'WPTSALL_TEST_ONLY_SOURCE_REVISION_FALLBACK' ) ) {
	define( 'WPTSALL_TEST_ONLY_SOURCE_REVISION_FALLBACK', true );
}

// This harness exercises business logic in-process via rest_do_request and
// does not replay the Rust client's Protocol v2 signing path (timestamp /
// nonce / HMAC). Before 2026-09-09 the shared-lab site only passed because
// an unrelated third-party rest_pre_dispatch filter swallowed the
// middleware's WP_Error; clean installs (module-ci runner) correctly
// rejected the unsigned requests. Declare the exemption explicitly so the
// suite is honest about what it covers — signature enforcement itself is
// verified by the protocol-v2 security unit tests and the signed e2e lanes.
if ( ! defined( 'WPTSALL_TEST_ONLY_TRANSPORT_SIGNATURE_FALLBACK' ) ) {
	define( 'WPTSALL_TEST_ONLY_TRANSPORT_SIGNATURE_FALLBACK', true );
}

// Load WordPress.
$wp_load_path = WP_SITE_PATH . 'wp-load.php';
if ( ! file_exists( $wp_load_path ) ) {
	echo COLOR_RED . "Error: WordPress not found at {$wp_load_path}" . COLOR_RESET . "\n";
	exit( 1 );
}

require_once $wp_load_path;

// In-process REST tests run over plain HTTP; disable client transport encryption
// so contracts/flows can assert business status codes (401/400/200) instead of
// transport_encryption_required. Production HTTPS policy is unchanged.
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'wptsall_client_transport_encryption_required', '__return_false', 1 );
}

// Convert wp_die() exits into exceptions so one test cannot silently terminate
// the whole runner process (for example callbacks that still call wp_send_json()).
if ( ! function_exists( 'wptsall_integration_wp_die_handler' ) ) {
	/**
	 * Test-mode wp_die handler.
	 *
	 * @param mixed $message Message.
	 * @param mixed $title   Title.
	 * @param mixed $args    Args.
	 * @throws Exception Always throws to keep control in test runner.
	 */
	function wptsall_integration_wp_die_handler( $message, $title = '', $args = array() ) {
		$msg = is_scalar( $message ) ? (string) $message : wp_json_encode( $message );
		throw new Exception( 'wp_die intercepted during integration test: ' . $msg );
	}
}
if ( ! function_exists( 'wptsall_integration_get_wp_die_handler' ) ) {
	/**
	 * Return unified wp_die handler callable for integration tests.
	 *
	 * @return string
	 */
	function wptsall_integration_get_wp_die_handler() {
		return 'wptsall_integration_wp_die_handler';
	}
}
$wp_die_filters = array(
	'wp_die_handler',
	'wp_die_ajax_handler',
	'wp_die_json_handler',
	'wp_die_jsonp_handler',
	'wp_die_xml_handler',
	'wp_die_xmlrpc_handler',
);
foreach ( $wp_die_filters as $wp_die_filter ) {
	add_filter( $wp_die_filter, 'wptsall_integration_get_wp_die_handler', PHP_INT_MAX );
}

// Load simulators and register offline HTTP interceptor.
// Must be done AFTER wp-load.php so add_filter() is available.
$simulator_license = dirname( __FILE__ ) . '/simulators/client-token-simulator.php';
if ( file_exists( $simulator_license ) ) {
	require_once $simulator_license;
	// Register the HTTP interceptor (blocks external network; allows 127.0.0.1/localhost).
	if ( function_exists( 'wptsall_test_register_http_interceptor' ) ) {
		wptsall_test_register_http_interceptor();
	}
}

// Check plugin is active.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$wptsall_plugin_candidates = array(
	'wpmmcc-ats/wpmmcc-ats.php',
	
	
	
);
$wptsall_active_plugin = '';
foreach ( $wptsall_plugin_candidates as $candidate ) {
	if ( is_plugin_active( $candidate ) ) {
		$wptsall_active_plugin = $candidate;
		break;
	}
}

if ( '' === $wptsall_active_plugin ) {
	echo COLOR_RED . "Error: WPTSALL plugin is not active" . COLOR_RESET . "\n";
	echo "Run: wp plugin activate wpmmcc-ats/wpmmcc-ats.php\n";
	exit( 1 );
}

echo COLOR_GREEN . "✓ WordPress loaded" . COLOR_RESET . "\n";
echo COLOR_GREEN . "✓ WPTSALL plugin active ({$wptsall_active_plugin})" . COLOR_RESET . "\n";
echo COLOR_GREEN . "✓ WP site path: " . WP_SITE_PATH . COLOR_RESET . "\n";
echo "\n";

if ( ! function_exists( 'wptsall_integration_active_plugin_basename' ) ) {
	/**
	 * Get the active WPTSALL plugin basename.
	 *
	 * @return string
	 */
	function wptsall_integration_active_plugin_basename() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array( 'wpmmcc-ats/wpmmcc-ats.php',   'wpmmcc-ats/wpmmcc-ats.php' ) as $candidate ) {
			if ( is_plugin_active( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'wptsall_integration_is_plugin_active' ) ) {
	/**
	 * Whether the WPTSALL plugin is active under a supported basename.
	 *
	 * @return bool
	 */
	function wptsall_integration_is_plugin_active() {
		return '' !== wptsall_integration_active_plugin_basename();
	}
}

/**
 * Resolve callback source file path.
 *
 * @param mixed $callback Hook callback.
 * @return string Empty string for internal callbacks or unknown source.
 */
function wptsall_integration_get_callback_file( $callback ) {
	try {
		if ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			return (string) $reflection->getFileName();
		}
		if ( is_string( $callback ) ) {
			if ( ! function_exists( $callback ) ) {
				return '';
			}
			$reflection = new ReflectionFunction( $callback );
			return (string) $reflection->getFileName();
		}
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$target = $callback[0];
			$method = (string) $callback[1];
			if ( is_object( $target ) ) {
				if ( ! method_exists( $target, $method ) ) {
					return '';
				}
				$reflection = new ReflectionMethod( $target, $method );
				return (string) $reflection->getFileName();
			}
			if ( is_string( $target ) && class_exists( $target ) && method_exists( $target, $method ) ) {
				$reflection = new ReflectionMethod( $target, $method );
				return (string) $reflection->getFileName();
			}
		}
	} catch ( Throwable $e ) {
		return '';
	}

	return '';
}

/**
 * Determine whether a post lifecycle callback should be kept in integration runs.
 *
 * Keep: Core, WPTSALL plugin, mu-plugins, and test-local callbacks.
 * Drop: Other active plugins to avoid host-specific callback side-effects.
 *
 * @param mixed $callback Hook callback.
 * @return bool
 */
function wptsall_integration_allow_post_hook_callback( $callback ) {
	$file = wptsall_integration_get_callback_file( $callback );
	if ( '' === $file ) {
		return true;
	}

	$file    = str_replace( '\\', '/', $file );
	$wp_root = rtrim( str_replace( '\\', '/', WP_SITE_PATH ), '/' );

	$plugin_dirs = array(
		$wp_root . '/wp-content/plugins/wpmmcc-ats/',
		$wp_root . '/wp-content/plugins/wpmmcc-ats/',
	);
	foreach ( $plugin_dirs as $plugin_dir ) {
		if ( strpos( $file, $plugin_dir ) !== false ) {
			return true;
		}
	}
	if ( strpos( $file, $wp_root . '/wp-content/mu-plugins/' ) !== false ) {
		return true;
	}
	if ( strpos( $file, $wp_root . '/wp-includes/' ) !== false ) {
		return true;
	}
	if ( strpos( $file, $wp_root . '/wp-admin/' ) !== false ) {
		return true;
	}
	if ( strpos( $file, $wp_root . '/wp-content/plugins/' ) !== false ) {
		return false;
	}

	return true;
}

/**
 * Remove non-WPTSALL external plugin callbacks from post lifecycle hooks.
 *
 * @return void
 */
function wptsall_integration_isolate_post_hooks() {
	global $wp_filter;

	if ( ! is_array( $wp_filter ) ) {
		return;
	}

	$target_hooks = array(
		'pre_post_update',
		'wp_insert_post_data',
		'wp_insert_post',
		'wp_after_insert_post',
		'transition_post_status',
		'post_updated',
		'edit_post',
		'save_post',
		'save_post_post',
	);

	$removed = 0;
	foreach ( $target_hooks as $hook_name ) {
		if ( empty( $wp_filter[ $hook_name ] ) || ! is_object( $wp_filter[ $hook_name ] ) ) {
			continue;
		}
		if ( ! isset( $wp_filter[ $hook_name ]->callbacks ) || ! is_array( $wp_filter[ $hook_name ]->callbacks ) ) {
			continue;
		}

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $handlers ) {
			if ( ! is_array( $handlers ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$callback = $handler['function'] ?? null;
				if ( null === $callback ) {
					continue;
				}
				if ( wptsall_integration_allow_post_hook_callback( $callback ) ) {
					continue;
				}
				remove_filter( $hook_name, $callback, (int) $priority );
				++$removed;
			}
		}
	}

	if ( $removed > 0 ) {
		echo COLOR_YELLOW . "⚠ Isolated integration post hooks: removed {$removed} external callbacks" . COLOR_RESET . "\n";
	}
}

wptsall_integration_isolate_post_hooks();

/**
 * Simple Test Case base class - compatible with unit tests
 *
 * Provides assertion methods similar to PHPUnit/WP_UnitTestCase
 * but doesn't require WordPress test library
 */
class SimpleTestCase {
	public function setUp(): void {}
	public function tearDown(): void {}

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

	protected function assertNotFalse( $value, $message = '' ) {
		if ( $value === false ) {
			throw new Exception( $message ?: "断言失败: 不期望 false" );
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

	protected function assertIsBool( $value, $message = '' ) {
		if ( ! is_bool( $value ) ) {
			throw new Exception( $message ?: "断言失败: 期望布尔值" );
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

	protected function assertLessThan( $expected, $actual, $message = '' ) {
		if ( $actual >= $expected ) {
			throw new Exception( $message ?: "断言失败: {$actual} 应小于 {$expected}" );
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

	protected function assertMatchesRegularExpression( $pattern, $string, $message = '' ) {
		if ( ! preg_match( $pattern, $string ) ) {
			throw new Exception( $message ?: "断言失败: 字符串不匹配正则表达式 '{$pattern}'" );
		}
	}

	protected function assertFileExists( $filename, $message = '' ) {
		if ( ! file_exists( $filename ) ) {
			throw new Exception( $message ?: "断言失败: 文件不存在 '{$filename}'" );
		}
	}

	protected function markTestSkipped( $message = '' ) {
		throw new Exception( 'SKIPPED: ' . $message );
	}
}

/**
 * Simple Test Case base class for integration tests.
 *
 * Extends the lightweight assertion set so REST suites and flat integration
 * suites share the same assertion surface.
 */
class Integration_Test_Case extends SimpleTestCase {
	/**
	 * Test name for reporting.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Number of assertions.
	 *
	 * @var int
	 */
	protected $assertions = 0;

	/**
	 * Test results.
	 *
	 * @var array
	 */
	protected $results = array();

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {}

	/**
	 * Assert that a condition is true.
	 *
	 * @param bool   $condition The condition.
	 * @param string $message   Failure message.
	 */
	public function assertTrue( $condition, $message = '' ) {
		++$this->assertions;
		if ( ! $condition ) {
			throw new Exception( $message ?: 'Expected true but got false' );
		}
	}

	/**
	 * Assert that a condition is false.
	 *
	 * @param bool   $condition The condition.
	 * @param string $message   Failure message.
	 */
	public function assertFalse( $condition, $message = '' ) {
		++$this->assertions;
		if ( $condition ) {
			throw new Exception( $message ?: 'Expected false but got true' );
		}
	}

	/**
	 * Assert that two values are equal.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 */
	public function assertEquals( $expected, $actual, $message = '' ) {
		++$this->assertions;
		if ( $expected != $actual ) {
			throw new Exception(
				$message ?: "Expected " . var_export( $expected, true ) . " but got " . var_export( $actual, true )
			);
		}
	}

	/**
	 * Assert that a value is not null.
	 *
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertNotNull( $value, $message = '' ) {
		++$this->assertions;
		if ( null === $value ) {
			throw new Exception( $message ?: 'Expected not null but got null' );
		}
	}

	/**
	 * Assert that a value is null.
	 *
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertNull( $value, $message = '' ) {
		++$this->assertions;
		if ( null !== $value ) {
			throw new Exception( $message ?: 'Expected null but got ' . var_export( $value, true ) );
		}
	}

	/**
	 * Assert that a value is an array.
	 *
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertIsArray( $value, $message = '' ) {
		++$this->assertions;
		if ( ! is_array( $value ) ) {
			throw new Exception( $message ?: 'Expected array but got ' . gettype( $value ) );
		}
	}

	/**
	 * Assert that an array is not empty.
	 *
	 * @param array  $array   The array.
	 * @param string $message Failure message.
	 */
	public function assertNotEmpty( $array, $message = '' ) {
		++$this->assertions;
		if ( empty( $array ) ) {
			throw new Exception( $message ?: 'Expected not empty but array is empty' );
		}
	}

	/**
	 * Assert that an array is empty.
	 *
	 * @param array  $array   The array.
	 * @param string $message Failure message.
	 */
	public function assertEmpty( $array, $message = '' ) {
		++$this->assertions;
		if ( ! empty( $array ) ) {
			throw new Exception( $message ?: 'Expected empty but array is not empty' );
		}
	}

	/**
	 * Assert that an array has a key.
	 *
	 * @param string $key     The key.
	 * @param array  $array   The array.
	 * @param string $message Failure message.
	 */
	public function assertArrayHasKey( $key, $array, $message = '' ) {
		++$this->assertions;
		if ( ! array_key_exists( $key, $array ) ) {
			throw new Exception( $message ?: "Expected array to have key '{$key}'" );
		}
	}

	/**
	 * Assert that an array does not have a key.
	 *
	 * @param string $key     The key.
	 * @param array  $array   The array.
	 * @param string $message Failure message.
	 */
	public function assertArrayNotHasKey( $key, $array, $message = '' ) {
		++$this->assertions;
		if ( array_key_exists( $key, $array ) ) {
			throw new Exception( $message ?: "Expected array to not have key '{$key}'" );
		}
	}

	/**
	 * Assert that a value is greater than another.
	 *
	 * @param mixed  $expected Expected minimum.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 */
	public function assertGreaterThan( $expected, $actual, $message = '' ) {
		++$this->assertions;
		if ( $actual <= $expected ) {
			throw new Exception(
				$message ?: "Expected value greater than {$expected} but got {$actual}"
			);
		}
	}

	/**
	 * Assert that a string contains another string.
	 *
	 * @param string $needle   String to search for.
	 * @param string $haystack String to search in.
	 * @param string $message  Failure message.
	 */
	public function assertStringContainsString( $needle, $haystack, $message = '' ) {
		++$this->assertions;
		if ( strpos( $haystack, $needle ) === false ) {
			throw new Exception(
				$message ?: "Expected string to contain '{$needle}' but it does not. Actual: '{$haystack}'"
			);
		}
	}

	/**
	 * Assert that a value is an instance of a class.
	 *
	 * @param string $class   Expected class name.
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertInstanceOf( $class, $value, $message = '' ) {
		++$this->assertions;
		if ( ! ( $value instanceof $class ) ) {
			throw new Exception(
				$message ?: 'Expected instance of ' . $class . ' but got ' . ( is_object( $value ) ? get_class( $value ) : gettype( $value ) )
			);
		}
	}

	/**
	 * Assert that two values are the same (===).
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 */
	public function assertSame( $expected, $actual, $message = '' ) {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new Exception(
				$message ?: 'Expected same value ' . var_export( $expected, true ) . ' but got ' . var_export( $actual, true )
			);
		}
	}

	/**
	 * Skip this test.
	 *
	 * @param string $message Reason for skipping.
	 * @throws Exception Skip exception.
	 */
	public function markTestSkipped( $message = '' ) {
		throw new Exception( 'SKIP: ' . $message );
	}

	/**
	 * Mark test as incomplete.
	 *
	 * @param string $message Reason for being incomplete.
	 * @throws Exception Incomplete exception.
	 */
	public function markTestIncomplete( $message = '' ) {
		throw new Exception( 'SKIP: INCOMPLETE - ' . $message );
	}

	/**
	 * Assert that an array contains a value.
	 *
	 * @param mixed  $needle   The value to search for.
	 * @param array  $haystack The array.
	 * @param string $message  Failure message.
	 */
	public function assertContains( $needle, $haystack, $message = '' ) {
		++$this->assertions;
		if ( ! in_array( $needle, $haystack, true ) ) {
			throw new Exception(
				$message ?: "Expected array to contain " . var_export( $needle, true )
			);
		}
	}

	/**
	 * Assert that an array has a specific count.
	 *
	 * @param int    $expected Expected count.
	 * @param array  $array    The array.
	 * @param string $message  Failure message.
	 */
	public function assertCount( $expected, $array, $message = '' ) {
		++$this->assertions;
		$actual = count( $array );
		if ( $expected !== $actual ) {
			throw new Exception(
				$message ?: "Expected count {$expected} but got {$actual}"
			);
		}
	}

	/**
	 * Assert that a value is greater than or equal to another.
	 *
	 * @param mixed  $expected Expected minimum.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 */
	public function assertGreaterThanOrEqual( $expected, $actual, $message = '' ) {
		++$this->assertions;
		if ( $actual < $expected ) {
			throw new Exception(
				$message ?: "Expected value greater than or equal to {$expected} but got {$actual}"
			);
		}
	}

	/**
	 * Assert that a value is a string.
	 *
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertIsString( $value, $message = '' ) {
		++$this->assertions;
		if ( ! is_string( $value ) ) {
			throw new Exception( $message ?: 'Expected string but got ' . gettype( $value ) );
		}
	}

	/**
	 * Assert that a value is an integer.
	 *
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertIsInt( $value, $message = '' ) {
		++$this->assertions;
		if ( ! is_int( $value ) ) {
			throw new Exception( $message ?: 'Expected integer but got ' . gettype( $value ) );
		}
	}

	/**
	 * Assert that a value is a boolean.
	 *
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertIsBool( $value, $message = '' ) {
		++$this->assertions;
		if ( ! is_bool( $value ) ) {
			throw new Exception( $message ?: 'Expected boolean but got ' . gettype( $value ) );
		}
	}

	/**
	 * Assert that a value is less than another.
	 *
	 * @param mixed  $expected Expected maximum.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 */
	public function assertLessThan( $expected, $actual, $message = '' ) {
		++$this->assertions;
		if ( $actual >= $expected ) {
			throw new Exception(
				$message ?: "Expected value less than {$expected} but got {$actual}"
			);
		}
	}

	/**
	 * Assert that a value is not false.
	 *
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertNotFalse( $value, $message = '' ) {
		++$this->assertions;
		if ( false === $value ) {
			throw new Exception( $message ?: 'Expected not false but got false' );
		}
	}

	/**
	 * Assert that a value is numeric.
	 *
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertIsNumeric( $value, $message = '' ) {
		++$this->assertions;
		if ( ! is_numeric( $value ) ) {
			throw new Exception( $message ?: 'Expected numeric value but got ' . gettype( $value ) );
		}
	}

	/**
	 * Assert that a string matches a regular expression.
	 *
	 * @param string $pattern Regex pattern.
	 * @param string $string  String to test.
	 * @param string $message Failure message.
	 */
	public function assertMatchesRegularExpression( $pattern, $string, $message = '' ) {
		++$this->assertions;
		if ( ! preg_match( $pattern, $string ) ) {
			throw new Exception( $message ?: "Expected string to match pattern '{$pattern}'" );
		}
	}

	/**
	 * Assert that an object has a property.
	 *
	 * @param string $property Property name.
	 * @param object $object   The object.
	 * @param string $message  Failure message.
	 */
	public function assertObjectHasProperty( $property, $object, $message = '' ) {
		++$this->assertions;
		if ( ! property_exists( $object, $property ) ) {
			throw new Exception( $message ?: "Expected object to have property '{$property}'" );
		}
	}

	/**
	 * Assert that a value is not an instance of WP_Error.
	 *
	 * @param string $class   The class name.
	 * @param mixed  $value   The value.
	 * @param string $message Failure message.
	 */
	public function assertNotInstanceOf( $class, $value, $message = '' ) {
		++$this->assertions;
		if ( $value instanceof $class ) {
			throw new Exception( $message ?: 'Expected not an instance of ' . $class );
		}
	}
}

/**
 * Test Runner
 */
class Integration_Test_Runner {
	/**
	 * Test directory.
	 *
	 * @var string
	 */
	private $test_dir;

	/**
	 * Results.
	 *
	 * @var array
	 */
	private $results = array(
		'passed'  => 0,
		'failed'  => 0,
		'skipped' => 0,
		'errors'  => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param string $test_dir Test directory.
	 */
	public function __construct( $test_dir ) {
		$this->test_dir = $test_dir;
	}

	/**
	 * Recursively find test files in a directory.
	 *
	 * @param string $dir Directory to search.
	 * @return array List of test file paths.
	 */
	private function find_test_files( $dir ) {
		$files = array();

		// Get direct test files
		$direct_files = glob( $dir . '/test-*.php' );
		if ( $direct_files ) {
			$files = array_merge( $files, $direct_files );
		}

		// Search subdirectories
		$subdirs = glob( $dir . '/*', GLOB_ONLYDIR );
		if ( $subdirs ) {
			foreach ( $subdirs as $subdir ) {
				$files = array_merge( $files, $this->find_test_files( $subdir ) );
			}
		}

		return $files;
	}

	/**
	 * Include legacy tests.
	 *
	 * @var bool
	 */
	private $include_legacy = false;

	/**
	 * Use random order (not recommended).
	 *
	 * @var bool
	 */
	private $random_order = false;

	/**
	 * Filter by specific level.
	 *
	 * @var string|null
	 */
	private $level_filter = null;

	/**
	 * Active suite name.
	 *
	 * @var string
	 */
	private $suite = 'chains';

	/**
	 * Set whether to include legacy tests.
	 *
	 * @param bool $include Whether to include legacy tests.
	 */
	public function set_include_legacy( $include ) {
		$this->include_legacy = $include;
	}

	/**
	 * Set whether to use random order.
	 *
	 * @param bool $random Whether to use random order.
	 */
	public function set_random_order( $random ) {
		$this->random_order = $random;
	}

	/**
	 * Set level filter.
	 *
	 * @param string|null $level Level to filter by (e.g., 'L1', 'L2').
	 */
	public function set_level_filter( $level ) {
		$this->level_filter = $level;
	}

	/**
	 * Set active suite.
	 *
	 * @param string $suite Suite name: chains, bootstrap, contracts, flows, all, legacy.
	 */
	public function set_suite( $suite ) {
		$this->suite = $suite;
	}

	/**
	 * Get test files for a given suite (contracts, flows, or legacy).
	 *
	 * Returns a flat array of absolute file paths matching test-*.php.
	 *
	 * @param string $suite Suite identifier: 'contracts', 'flows', or 'legacy'.
	 * @return array Absolute paths to test files.
	 */
	private function get_suite_test_files( $suite ) {
		$integration_dir = $this->test_dir . '/integration';

		switch ( $suite ) {
			case 'contracts':
				$dir = $integration_dir . '/contracts';
				return $this->find_test_files( $dir );

			case 'flows':
				$dir = $integration_dir . '/flows';
				return $this->find_test_files( $dir );

			case 'legacy':
				$dir = $integration_dir . '/legacy';
				return $this->find_test_files( $dir );

			default:
				return array();
		}
	}

	/**
	 * Run bootstrap suite scripts.
	 *
	 * Bootstrap scripts are plain PHP files (not test classes).
	 * They are required directly rather than run through the test runner.
	 */
	private function run_bootstrap_suite() {
		$bootstrap_dir = $this->test_dir . '/integration/bootstrap';

		echo COLOR_BLUE . "==========================================\n";
		echo "Suite: bootstrap\n";
		echo "==========================================" . COLOR_RESET . "\n\n";

		if ( ! is_dir( $bootstrap_dir ) ) {
			echo COLOR_YELLOW . "  Bootstrap directory not found: {$bootstrap_dir}" . COLOR_RESET . "\n\n";
			return;
		}

		$files = glob( $bootstrap_dir . '/*.php' );
		if ( empty( $files ) ) {
			echo COLOR_YELLOW . "  No bootstrap scripts found in {$bootstrap_dir}" . COLOR_RESET . "\n\n";
			return;
		}

		// Execute bootstrap scripts in a fixed lifecycle order first, then run
		// any additional scripts alphabetically.
		$preferred_order = array(
			'reset-wordpress.php',
			'run-seeding.php',
			'install-wptsall.php',
			'verify-rule-alignment.php',
		);
		$ordered_files = array();
		foreach ( $preferred_order as $preferred_file ) {
			$path = $bootstrap_dir . '/' . $preferred_file;
			if ( file_exists( $path ) ) {
				$ordered_files[] = $path;
			}
		}
		$remaining_files = array_values( array_diff( $files, $ordered_files ) );
		sort( $remaining_files );
		$files = array_merge( $ordered_files, $remaining_files );

		echo "Found " . count( $files ) . " bootstrap script(s)\n\n";

		foreach ( $files as $file ) {
			$basename = basename( $file );
			echo COLOR_BLUE . "Running bootstrap: {$basename}" . COLOR_RESET . "\n";
			echo str_repeat( '-', 40 ) . "\n";

			$GLOBALS['wptsall_current_test_file'] = $file;
			try {
				require $file;
				echo COLOR_GREEN . "  Done: {$basename}" . COLOR_RESET . "\n";
			} catch ( Throwable $e ) {
				echo COLOR_RED . "  Error in {$basename}: " . $e->getMessage() . COLOR_RESET . "\n";
				++$this->results['failed'];
				$this->results['errors'][] = array(
					'test'    => "bootstrap::{$basename}",
					'message' => $e->getMessage(),
				);
			}
			$GLOBALS['wptsall_current_test_file'] = '';

			echo "\n";
		}
	}

	/**
	 * Run a flat list of test files under a named suite banner.
	 *
	 * @param string $suite_label Human-readable suite label for output.
	 * @param array  $files       Absolute paths to test files.
	 */
	private function run_flat_suite( $suite_label, array $files ) {
		echo COLOR_BLUE . "==========================================\n";
		echo "Suite: {$suite_label} (" . count( $files ) . " file(s))\n";
		echo "==========================================" . COLOR_RESET . "\n\n";

		if ( empty( $files ) ) {
			echo COLOR_YELLOW . "  No test files found." . COLOR_RESET . "\n\n";
			return;
		}

		sort( $files );

		foreach ( $files as $file ) {
			if ( ! file_exists( $file ) ) {
				echo COLOR_RED . "File not found: {$file}" . COLOR_RESET . "\n";
				continue;
			}
			$this->run_test_file( $file );
		}

		echo "\n";
	}

	/**
	 * Load chain order configuration.
	 *
	 * @return array Chain order configuration.
	 */
	private function load_chain_order() {
		$order_file = $this->test_dir . '/integration/chain-order.php';
		if ( file_exists( $order_file ) ) {
			return require $order_file;
		}
		return array();
	}

	/**
	 * Get test files in ordered sequence.
	 *
	 * @param string $chains_dir Chains directory path.
	 * @return array Ordered list of test file paths with level info.
	 */
	private function get_ordered_test_files( $chains_dir ) {
		$chain_order = $this->load_chain_order();

		if ( empty( $chain_order ) ) {
			// Fallback to alphabetical order.
			$files = $this->find_test_files( $chains_dir );
			sort( $files );
			return array(
				array(
					'level' => 'ALL',
					'name'  => '全部测试',
					'files' => $files,
				),
			);
		}

		$ordered_levels = array();

		foreach ( $chain_order as $level_config ) {
			$level = $level_config['level'];

			// Skip if filtering by level and this isn't the one.
			if ( $this->level_filter && $this->level_filter !== $level ) {
				continue;
			}

			$files = array();
			foreach ( $level_config['chains'] as $chain_file ) {
				$path = $chains_dir . '/' . $chain_file;
				if ( file_exists( $path ) ) {
					$files[] = $path;
				}
			}

			if ( ! empty( $files ) ) {
				$ordered_levels[] = array(
					'level'       => $level,
					'name'        => $level_config['name'],
					'description' => $level_config['description'] ?? '',
					'files'       => $files,
				);
			}
		}

		return $ordered_levels;
	}

	/**
	 * Run all tests or a specific file.
	 *
	 * When a specific file is provided the suite setting is ignored and the file
	 * is located by searching chains/, integration root, and legacy/ directories.
	 *
	 * When no file is provided the active suite determines which tests are run:
	 * - chains    : current ordered-chain execution (L1-L8, skipping chain-9/11/19/20 per order config)
	 * - bootstrap : require each PHP file in bootstrap/ directly (non-test scripts)
	 * - contracts : run test-*.php files in contracts/ as test classes
	 * - flows     : run test-*.php files in flows/ as test classes
	 * - legacy    : run test-*.php files in legacy/ recursively as test classes
	 * - all       : bootstrap + contracts + flows + chains (in that order)
	 *
	 * @param string|null $specific_file Specific file to run (overrides suite).
	 */
	public function run( $specific_file = null ) {
		$integration_dir = $this->test_dir . '/integration';

		// Load base class first.
		$base_class = $integration_dir . '/base/class-rest-integration-test-case.php';
		if ( file_exists( $base_class ) ) {
			require_once $base_class;
			echo COLOR_GREEN . "✓ Loaded REST_Integration_Test_Case base class" . COLOR_RESET . "\n\n";
		}

		// --file= overrides suite selection: locate and run a single file.
		if ( $specific_file ) {
			// Try chains/ first, then integration/ root, then legacy/
			$possible_paths = array(
				$integration_dir . '/chains/' . $specific_file,
				$integration_dir . '/contracts/' . $specific_file,
				$integration_dir . '/flows/' . $specific_file,
				$integration_dir . '/' . $specific_file,
				$integration_dir . '/legacy/' . $specific_file,
			);

			$files = array();
			foreach ( $possible_paths as $path ) {
				if ( file_exists( $path ) ) {
					$files[] = $path;
					break;
				}
			}

			// Also check in legacy subdirectories.
			if ( empty( $files ) ) {
				$legacy_dirs = array( 'models', 'sites', 'tasks', 'hooks', 'templates', 'workflow' );
				foreach ( $legacy_dirs as $dir ) {
					$path = $integration_dir . '/legacy/' . $dir . '/' . $specific_file;
					if ( file_exists( $path ) ) {
						$files[] = $path;
						break;
					}
				}
			}

			echo "Found " . count( $files ) . " test file(s)\n\n";

			foreach ( $files as $file ) {
				if ( ! file_exists( $file ) ) {
					echo COLOR_RED . "File not found: {$file}" . COLOR_RESET . "\n";
					continue;
				}
				$this->run_test_file( $file );
			}

			$this->print_summary();
			return;
		}

		// Dispatch by suite.
		switch ( $this->suite ) {
			case 'bootstrap':
				$this->run_bootstrap_suite();
				// Bootstrap does not run test classes; print a minimal summary.
				echo COLOR_BLUE . "Bootstrap suite complete." . COLOR_RESET . "\n";
				return;

			case 'contracts':
				$files = $this->get_suite_test_files( 'contracts' );
				$this->run_flat_suite( 'contracts', $files );
				break;

			case 'flows':
				$files = $this->get_suite_test_files( 'flows' );
				$this->run_flat_suite( 'flows', $files );
				break;

			case 'legacy':
				$files = $this->get_suite_test_files( 'legacy' );
				$this->run_flat_suite( 'legacy', $files );
				break;

			case 'main':
				// 主门禁：contracts + flows + chains（不含 bootstrap，适合日常 CI）
				$contracts_files = $this->get_suite_test_files( 'contracts' );
				if ( ! empty( $contracts_files ) ) {
					$this->run_flat_suite( 'contracts', $contracts_files );
				}
				$flows_files = $this->get_suite_test_files( 'flows' );
				if ( ! empty( $flows_files ) ) {
					$this->run_flat_suite( 'flows', $flows_files );
				}
				$this->run_chains_suite( $integration_dir );
				break;

			case 'all':
				// 完整套件：bootstrap + contracts + flows + chains
				$this->run_bootstrap_suite();
				$contracts_files = $this->get_suite_test_files( 'contracts' );
				if ( ! empty( $contracts_files ) ) {
					$this->run_flat_suite( 'contracts', $contracts_files );
				}
				$flows_files = $this->get_suite_test_files( 'flows' );
				if ( ! empty( $flows_files ) ) {
					$this->run_flat_suite( 'flows', $flows_files );
				}
				$this->run_chains_suite( $integration_dir );
				break;

			case 'chains':
			default:
				$this->run_chains_suite( $integration_dir );
				break;
		}

		$this->print_summary();
	}

	/**
	 * Run the chains suite (ordered L1-L8 execution).
	 *
	 * This is the original behavior preserved unchanged.
	 *
	 * @param string $integration_dir Absolute path to integration directory.
	 */
	private function run_chains_suite( $integration_dir ) {
		$ordered_levels = $this->get_ordered_test_files( $integration_dir . '/chains' );

		if ( $this->random_order ) {
			// Flatten and shuffle (not recommended).
			$files = array();
			foreach ( $ordered_levels as $level ) {
				$files = array_merge( $files, $level['files'] );
			}
			shuffle( $files );

			echo COLOR_YELLOW . "⚠ Running in random order (not recommended)" . COLOR_RESET . "\n";
			echo "Found " . count( $files ) . " test file(s)\n\n";

			foreach ( $files as $file ) {
				$this->run_test_file( $file );
			}
		} else {
			// Run in ordered sequence by level.
			$total_files = 0;
			foreach ( $ordered_levels as $level ) {
				$total_files += count( $level['files'] );
			}

			echo "Found " . $total_files . " test file(s) in " . count( $ordered_levels ) . " level(s)";
			if ( $this->level_filter ) {
				echo " (filtered by {$this->level_filter})";
			}
			if ( 'chains' === $this->suite ) {
				echo " (use --suite=legacy to run legacy tests)";
			}
			echo "\n\n";

			foreach ( $ordered_levels as $level ) {
				echo COLOR_BLUE . "[Runner] Starting level: {$level['level']}" . COLOR_RESET . "\n";
				flush();
				$this->run_level( $level );
				echo COLOR_BLUE . "[Runner] Completed level: {$level['level']}" . COLOR_RESET . "\n";
				flush();
			}
		}
	}

	/**
	 * Run all tests in a level.
	 *
	 * @param array $level Level configuration with files.
	 */
	private function run_level( $level ) {
		echo COLOR_BLUE . "==========================================\n";
		echo "{$level['level']}: {$level['name']}";
		if ( ! empty( $level['description'] ) ) {
			echo " - {$level['description']}";
		}
		echo "\n";
		echo "==========================================" . COLOR_RESET . "\n\n";

		foreach ( $level['files'] as $file ) {
			if ( ! file_exists( $file ) ) {
				echo COLOR_RED . "File not found: {$file}" . COLOR_RESET . "\n";
				continue;
			}
			$this->run_test_file( $file );
		}

		echo "\n";
	}

	/**
	 * Current file being loaded (for error tracking).
	 *
	 * @var string
	 */
	private $current_file = '';

	/**
	 * Run tests in a file.
	 *
	 * @param string $file Test file path.
	 */
	private function run_test_file( $file ) {
		$basename = basename( $file );
		echo COLOR_BLUE . "Running: {$basename}" . COLOR_RESET . "\n";
		echo str_repeat( '-', 40 ) . "\n";

		// Check if file requires WP-CLI.
		$content = file_get_contents( $file );
		if ( strpos( $content, "defined( 'WP_CLI' )" ) !== false || strpos( $content, "defined('WP_CLI')" ) !== false ) {
			echo COLOR_YELLOW . "  ⏭ Skipped (requires WP-CLI)" . COLOR_RESET . "\n";
			++$this->results['skipped'];
			return;
		}

		// Check for @requires function annotation in docblock.
		if ( preg_match_all( '/@requires\s+function\s+(\w+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $required_function ) {
				if ( ! function_exists( $required_function ) ) {
					echo COLOR_YELLOW . "  ⏭ Skipped (requires function {$required_function})" . COLOR_RESET . "\n";
					++$this->results['skipped'];
					return;
				}
			}
		}

		// Procedural flow scripts declare check() globally; run each in a subprocess.
		if ( strpos( $content, 'function check(' ) !== false ) {
			$this->run_flow_script_subprocess( $file, $basename );
			$GLOBALS['wptsall_current_test_file'] = '';
			echo "\n";
			return;
		}

		// Track current file for error handling (both class property and global).
		$this->current_file                    = $file;
		$GLOBALS['wptsall_current_test_file'] = $file;

		// Get classes before including file.
		$classes_before = get_declared_classes();

		// Include test file with error handling.
		try {
			require_once $file;
		} catch ( Throwable $e ) {
			echo COLOR_RED . "  ✗ Error loading file: " . $e->getMessage() . COLOR_RESET . "\n";
			++$this->results['failed'];
			$this->results['errors'][] = array(
				'test'    => $basename,
				'message' => 'Load error: ' . $e->getMessage(),
			);
			return;
		}

		// Clear global tracking after successful load.
		$GLOBALS['wptsall_current_test_file'] = '';

		// Find new test classes.
		$classes_after = get_declared_classes();
		$new_classes   = array_diff( $classes_after, $classes_before );

		foreach ( $new_classes as $class ) {
			// Skip base classes.
			if ( 'WP_UnitTestCase' === $class || 'Integration_Test_Case' === $class ) {
				continue;
			}

			// Check if it's a test class.
			if ( strpos( $class, 'Test_' ) !== 0 ) {
				continue;
			}

			$this->run_test_class( $class );
		}

		echo "\n";
	}

	/**
	 * Run a procedural flow script in an isolated PHP subprocess.
	 *
	 * @param string $file     Absolute path to the flow script.
	 * @param string $basename Basename for error reporting.
	 */
	private function run_flow_script_subprocess( $file, $basename ) {
		$php = defined( 'PHP_BINARY' ) ? PHP_BINARY : 'php';
		$cmd = escapeshellarg( $php ) . ' ' . escapeshellarg( $file ) . ' 2>&1';

		$output    = array();
		$exit_code = 1;
		exec( $cmd, $output, $exit_code );

		$passed_checks = 0;
		$failed_checks = 0;

		foreach ( $output as $line ) {
			echo $line . "\n";
			if ( str_starts_with( $line, 'PASS:' ) ) {
				++$passed_checks;
			} elseif ( str_starts_with( $line, 'FAIL:' ) ) {
				++$failed_checks;
			}
		}

		if ( 0 !== $exit_code || $failed_checks > 0 ) {
			echo COLOR_RED . "  ✗ Flow failed (exit={$exit_code}, fails={$failed_checks})" . COLOR_RESET . "\n";
			$this->results['failed'] += max( 1, $failed_checks );
			$this->results['errors'][] = array(
				'test'    => $basename,
				'message' => "Flow script failed with exit code {$exit_code}",
			);
			return;
		}

		$this->results['passed'] += max( 1, $passed_checks );
		echo COLOR_GREEN . "  ✓ Flow checks passed: {$passed_checks}" . COLOR_RESET . "\n";
	}

	/**
	 * Run tests in a class.
	 *
	 * @param string $class_name Test class name.
	 */
	private function run_test_class( $class_name ) {
		$reflection = new ReflectionClass( $class_name );
		$methods    = $reflection->getMethods( ReflectionMethod::IS_PUBLIC );

		// Debug: count test methods.
		$test_methods = array();
		foreach ( $methods as $method ) {
			$name = $method->getName();
			if ( strpos( $name, 'test_' ) === 0 ) {
				$test_methods[] = $name;
			}
		}

		// Debug output.
		if ( getenv( 'WPTSALL_DEBUG' ) ) {
			echo COLOR_BLUE . "  [DEBUG] Found " . count( $test_methods ) . " test methods" . COLOR_RESET . "\n";
		}

		// Call setUpBeforeClass if it exists.
		if ( $reflection->hasMethod( 'setUpBeforeClass' ) ) {
			try {
				$class_name::setUpBeforeClass();
			} catch ( Exception $e ) {
				echo COLOR_RED . "  ✗ setUpBeforeClass failed: " . $e->getMessage() . COLOR_RESET . "\n";
				return;
			}
		}

		$method_index = 0;
		foreach ( $test_methods as $method_name ) {
			++$method_index;
			if ( getenv( 'WPTSALL_DEBUG' ) ) {
				echo COLOR_BLUE . "  [DEBUG] Running test {$method_index}/" . count( $test_methods ) . ": {$method_name}" . COLOR_RESET . "\n";
				flush();
			}
			$this->run_single_test( $class_name, $method_name );
		}

		// Call tearDownAfterClass if it exists.
		if ( $reflection->hasMethod( 'tearDownAfterClass' ) ) {
			try {
				$class_name::tearDownAfterClass();
			} catch ( Exception $e ) {
				// Ignore tearDown errors.
			}
		}
	}

	/**
	 * Run a single test method.
	 *
	 * @param string $class_name  Test class name.
	 * @param string $method_name Test method name.
	 */
	private function run_single_test( $class_name, $method_name ) {
		$test_name = "{$class_name}::{$method_name}";

		try {
			$instance = new $class_name();

			// Run setUp.
			if ( method_exists( $instance, 'setUp' ) ) {
				$instance->setUp();
			}

			// Run test.
			$instance->$method_name();

			// Run tearDown.
			if ( method_exists( $instance, 'tearDown' ) ) {
				$instance->tearDown();
			}

			echo COLOR_GREEN . "  ✓ {$method_name}" . COLOR_RESET . "\n";
			++$this->results['passed'];

			// Flush output to ensure immediate display.
			if ( function_exists( 'ob_flush' ) ) {
				@ob_flush();
			}
			flush();

		} catch ( Exception $e ) {
			$message = $e->getMessage();

			// Check if skipped.
			if ( strpos( $message, 'SKIP:' ) === 0 ) {
				echo COLOR_YELLOW . "  ⏭ {$method_name} - " . substr( $message, 6 ) . COLOR_RESET . "\n";
				++$this->results['skipped'];
			} else {
				echo COLOR_RED . "  ✗ {$method_name}" . COLOR_RESET . "\n";
				echo COLOR_RED . "    Error: {$message}" . COLOR_RESET . "\n";
				++$this->results['failed'];
				$this->results['errors'][] = array(
					'test'    => $test_name,
					'message' => $message,
				);
			}

			// Flush output.
			if ( function_exists( 'ob_flush' ) ) {
				@ob_flush();
			}
			flush();

			// Try to run tearDown even on failure.
			if ( isset( $instance ) && method_exists( $instance, 'tearDown' ) ) {
				try {
					$instance->tearDown();
				} catch ( Exception $e ) {
					// Ignore tearDown errors.
				}
			}
		}
	}

	/**
	 * Print test summary.
	 */
	private function print_summary() {
		echo "\n";
		echo COLOR_BLUE . "==========================================" . COLOR_RESET . "\n";
		echo COLOR_BLUE . "Test Summary" . COLOR_RESET . "\n";
		echo COLOR_BLUE . "==========================================" . COLOR_RESET . "\n";
		echo "\n";

		$total = $this->results['passed'] + $this->results['failed'] + $this->results['skipped'];

		echo "Total:   {$total}\n";
		echo COLOR_GREEN . "Passed:  {$this->results['passed']}" . COLOR_RESET . "\n";
		echo COLOR_RED . "Failed:  {$this->results['failed']}" . COLOR_RESET . "\n";
		echo COLOR_YELLOW . "Skipped: {$this->results['skipped']}" . COLOR_RESET . "\n";
		echo "\n";

		if ( ! empty( $this->results['errors'] ) ) {
			echo COLOR_RED . "Failures:" . COLOR_RESET . "\n";
			foreach ( $this->results['errors'] as $error ) {
				echo "  - {$error['test']}\n";
				echo "    {$error['message']}\n";
			}
			echo "\n";
		}

		if ( $this->results['failed'] === 0 ) {
			echo COLOR_GREEN . "✅ All tests passed!" . COLOR_RESET . "\n";
			exit( 0 );
		} else {
			echo COLOR_RED . "❌ Some tests failed!" . COLOR_RESET . "\n";
			exit( 1 );
		}
	}
}

// Define WP_UnitTestCase as alias for Integration_Test_Case.
if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	class WP_UnitTestCase extends Integration_Test_Case {}
}

// Run tests.
$test_dir = dirname( __DIR__ );
$runner   = new Integration_Test_Runner( $test_dir );
$runner->set_include_legacy( $args['legacy'] );
$runner->set_random_order( $args['random'] );
$runner->set_level_filter( $args['level'] );
$runner->set_suite( $args['suite'] );
$runner->run( $args['file'] );

<?php
/**
 * Test environment isolation between test files
 *
 * SimpleTestRunner 在同一个 PHP 进程里顺序加载所有测试文件。
 * 任何模拟后台屏幕（set_current_screen()）、AJAX 请求（REQUEST_METHOD=POST）
 * 或 REST 上下文的测试文件，泄漏的全局状态都会污染后续文件：
 * WP 6.8 的 is_admin() 在 $GLOBALS['current_screen'] 存在时直接返回
 * in_admin()，导致像 should_filter_archive_query() 这类带
 * "is_admin() && ! wp_doing_ajax() 则跳过" 守卫的代码在后续测试里被误伤。
 *
 * 本文件作为金丝雀：如果 runner 没有在文件间重置环境，它就会红。
 *
 * @package WPTSALL
 */

class Test_Env_Isolation extends WP_UnitTestCase {

	/**
	 * is_admin() 必须在文件加载时是 false —— 若此前文件模拟了后台屏幕
	 * （set_current_screen 未清理），这里会泄漏成 true。
	 */
	public function test_runner_resets_admin_screen_between_files() {
		$this->assertFalse(
			function_exists( 'is_admin' ) ? is_admin() : false,
			'is_admin() must be false at file start: a previous file simulated an admin screen (set_current_screen) and it leaked'
		);
	}

	/**
	 * 请求方法必须在文件间复位为 GET（AJAX 风格测试不得外泄 POST）。
	 */
	public function test_runner_resets_request_method_between_files() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->assertEquals( 'GET', $method, 'REQUEST_METHOD must be GET at file start' );
	}

	/**
	 * 当前用户必须在文件间复位（管理员模拟不得泄漏到后续文件）。
	 */
	public function test_runner_resets_current_user_between_files() {
		$this->assertEquals( 0, get_current_user_id(), 'current user must be 0 at file start' );
	}

	/**
	 * REST_REQUEST 尚未被定义（本进程里任何文件都不应定义它）。
	 */
	public function test_no_rest_request_constant_leak() {
		$this->assertFalse(
			defined( 'REST_REQUEST' ) && REST_REQUEST,
			'REST_REQUEST must not leak into non-REST test files'
		);
	}
}

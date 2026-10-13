<?php
/**
 * Admin Logs REST Controller tests.
 *
 * @package WPTSALL
 */

class Test_Logs_REST_Controller extends WP_UnitTestCase {

	/**
	 * @var \WPTSALL\Log\Logs_REST_Controller
	 */
	private $controller;

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		require_once WPTSALL_PATH . 'includes/log/class-logs-rest-controller.php';
		$this->controller = new \WPTSALL\Log\Logs_REST_Controller();
		$this->admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		// Grant plugin caps used by the controller.
		$user = new WP_User( $this->admin_id );
		$user->add_cap( 'manage_wptsall_settings' );
	}

	public function test_list_ops_logs_returns_success() {
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/admin/ops-logs' );
		$response = $this->controller->list_ops_logs( $request );
		$data     = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'items', $data['data'] );
	}

	public function test_read_ops_log_rejects_path_traversal() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/admin/ops-logs/read' );
		$request->set_param( 'file', '../wp-config.php' );
		$response = $this->controller->read_ops_log( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_read_ops_log_rejects_non_wptsall_prefix() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/admin/ops-logs/read' );
		$request->set_param( 'file', 'other-app.log' );
		$response = $this->controller->read_ops_log( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_read_ops_log_returns_tail_lines() {
		$log_dir = \WPTSALL\Log\get_log_dir();
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}
		$name = 'wptsall-rest-read-test.log';
		$path = trailingslashit( $log_dir ) . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, "line-one\nline-two\nline-three\n" );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/admin/ops-logs/read' );
		$request->set_param( 'file', $name );
		$request->set_param( 'lines', 10 );
		$response = $this->controller->read_ops_log( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $name, $data['data']['file'] );
		$this->assertContains( 'line-one', $data['data']['lines'] );
		$this->assertContains( 'line-three', $data['data']['lines'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $path );
	}

	public function test_audit_logs_list_and_clear() {
		if ( ! class_exists( '\\WPTSALL\\CLI\\Audit_Log' ) ) {
			$this->markTestSkipped( 'Audit_Log missing' );
		}
		\WPTSALL\CLI\Audit_Log::clear();
		\WPTSALL\CLI\Audit_Log::record( 'unit_test_action', array( 'k' => 1 ) );

		$list_req = new WP_REST_Request( 'GET', '/wptsall/v2/admin/audit-logs' );
		$list_req->set_param( 'limit', 10 );
		$list_res = $this->controller->list_audit_logs( $list_req );
		$list     = $list_res->get_data();
		$this->assertTrue( $list['success'] );
		$this->assertGreaterThanOrEqual( 1, $list['data']['count'] );

		$clear_req = new WP_REST_Request( 'POST', '/wptsall/v2/admin/audit-logs/clear' );
		$clear_res = $this->controller->clear_audit_logs( $clear_req );
		$clear     = $clear_res->get_data();
		$this->assertTrue( $clear['success'] );

		$list2 = $this->controller->list_audit_logs( $list_req )->get_data();
		$this->assertSame( 0, $list2['data']['count'] );
	}

	public function test_permission_denied_for_subscriber() {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$err = $this->controller->check_permission( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $err );
	}
}

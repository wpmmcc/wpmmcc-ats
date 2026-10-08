<?php
/**
 * Tasks REST API Tests
 *
 * Tests for Tasks REST endpoints in includes/tasks/api/class-tasks-rest-controller.php
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Sites\Services\Virtual_Site_Service;
use WPTSALL\Models\Services\Plugin_Mapping_Service;

class Test_Tasks_REST_Controller extends WP_UnitTestCase {

	/**
	 * REST server instance
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test relation ID
	 *
	 * @var int
	 */
	protected $test_relation_id = 0;

	/**
	 * Virtual site ID for /virtual content endpoints
	 *
	 * @var string
	 */
	protected $test_virtual_site_id = '';

	/**
	 * Test post ID
	 *
	 * @var int
	 */
	protected $test_post_id = 0;

	/**
	 * Tracked task IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_task_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up REST server
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create admin user
		$this->admin_id = $this->factory->user->create( array(
			'role' => 'administrator',
		) );

		// Ensure task table exists
		wptsall_ensure_task_table();

		// Create a real virtual site so /virtual permission checks resolve (not site_id=1).
		$vs_result = Virtual_Site_Service::create(
			array(
				'name'        => 'Tasks REST VS ' . uniqid(),
				'path_prefix' => 'tasks-rest-' . substr( uniqid(), -6 ),
				'lang'        => 'en_US',
			)
		);
		if ( ! empty( $vs_result['success'] ) && isset( $vs_result['site_id'] ) ) {
			$this->test_virtual_site_id = (string) $vs_result['site_id'];
		} elseif ( ! empty( $vs_result['success'] ) && ! empty( $vs_result['site']['id'] ) ) {
			$this->test_virtual_site_id = (string) $vs_result['site']['id'];
		} elseif ( ! empty( $vs_result['id'] ) ) {
			$this->test_virtual_site_id = (string) $vs_result['id'];
		}

		// Create test relation directly in database with unique template name
		global $wpdb;
		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );

		// Use unique template name to avoid duplicate key error
		$unique_template = 'test-tasks-rest-' . uniqid();
		$target_site_id  = $this->test_virtual_site_id !== '' ? $this->test_virtual_site_id : ( 'v_test_' . uniqid() );

		$wpdb->insert(
			$table,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => $unique_template,
				'target_site_id'   => $target_site_id,
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->test_relation_id = $wpdb->insert_id;

		// Create test post
		$this->test_post_id = $this->factory->post->create( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Test Post for Tasks',
		) );
	}

	/**
	 * Clean up test data
	 *
	 * Deletes only test-created data:
	 * 1. Tracked task IDs captured during test execution
	 * 2. Safety net: template LIKE 'test%' pattern for tasks inserted via wptsall_insert_tasks()
	 * 3. The test relation and test post created in setUp
	 */
	public function tearDown(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;

		// Clean up test relation
		if ( $this->test_relation_id ) {
			$table = wptsall_table( 'site_relations' );
			$wpdb->delete( $table, array( 'id' => $this->test_relation_id ), array( '%d' ) );
		}

		if ( '' !== $this->test_virtual_site_id ) {
			Virtual_Site_Service::delete( $this->test_virtual_site_id );
			$this->test_virtual_site_id = '';
		}

		// Clean up test post
		if ( $this->test_post_id ) {
			wp_delete_post( $this->test_post_id, true );
		}

		// Clean up tracked task IDs first (precise cleanup).
		$tasks_table = wptsall_table( 'tasks' );
		if ( ! empty( $this->test_task_ids ) ) {
			$ids = implode( ',', array_map( 'intval', $this->test_task_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$tasks_table} WHERE id IN ({$ids})" );
		}

		// Safety net: clean up test tasks by template naming pattern.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$tasks_table} WHERE template LIKE 'test%'" );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test REST routes are registered
	 */
	public function test_routes_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wptsall/v2/tasks', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/tasks/stats', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/tasks/language', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/virtual', $routes );
	}

	/**
	 * Test get tasks requires authentication
	 */
	public function test_get_tasks_requires_auth() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test get tasks as admin
	 */
	public function test_get_tasks_as_admin() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test get tasks with status filter
	 *
	 * Note: v0.6.0 API uses paginated response format {items, total, pages, page}
	 * and new status values: active, paused, completed, error
	 */
	public function test_get_tasks_filter_status() {
		wp_set_current_user( $this->admin_id );

		// Insert some test tasks directly with specific statuses
		global $wpdb;
		$table = wptsall_table( 'tasks' );
		$now   = current_time( 'mysql' );

		// Insert active task (new status value in v0.6.0)
		$wpdb->insert(
			$table,
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'site_id'     => $this->test_relation_id,
				'template'    => 'test_filter_status',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 100,
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( $wpdb->insert_id ) {
			$this->test_task_ids[] = $wpdb->insert_id;
		}

		// Insert completed task
		$wpdb->insert(
			$table,
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'site_id'     => $this->test_relation_id,
				'template'    => 'test_filter_status',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 101,
				'status'      => 'completed',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( $wpdb->insert_id ) {
			$this->test_task_ids[] = $wpdb->insert_id;
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$request->set_param( 'status', 'active' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		// v0.6.0 returns paginated format
		$this->assertArrayHasKey( 'items', $data, 'Response should have items key' );
		$items = $data['items'];

		$this->assertNotEmpty( $items, 'Should return at least one task' );
		foreach ( $items as $task ) {
			$this->assertEquals( 'active', $task['status'] );
		}
	}

	/**
	 * Test get tasks with relation_id filter
	 *
	 * Note: v0.6.0 API filters by relation_id instead of blog_id
	 */
	public function test_get_tasks_filter_blog_id() {
		wp_set_current_user( $this->admin_id );

		// Insert a task with specific relation_id
		global $wpdb;
		$table = wptsall_table( 'tasks' );
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'site_id'     => $this->test_relation_id,
				'relation_id' => $this->test_relation_id,
				'template'    => 'test_relation_filter',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 200,
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( $wpdb->insert_id ) {
			$this->test_task_ids[] = $wpdb->insert_id;
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$request->set_param( 'relation_id', $this->test_relation_id );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		// v0.6.0 returns paginated format
		$this->assertArrayHasKey( 'items', $data, 'Response should have items key' );
		$items = $data['items'];

		$this->assertNotEmpty( $items, 'Should return at least one task' );
		foreach ( $items as $task ) {
			$this->assertEquals( $this->test_relation_id, (int) $task['relation_id'] );
		}
	}

	/**
	 * Test create task requires parameters
	 */
	public function test_create_task_requires_params() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/wptsall/v2/tasks' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test create task with invalid template returns error
	 */
	public function test_create_task_invalid_template() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks' );
		$request->set_param( 'template', 'non-existent-plugin-xyz' );
		$request->set_param( 'site_id', $this->test_relation_id );
		$request->set_param( 'object_type', 'post_type' );
		$request->set_param( 'subtype', 'post' );
		$request->set_param( 'object_id', $this->test_post_id );

		$response = $this->server->dispatch( $request );

		// API returns 404 when template (plugin mapping) not found
		$this->assertEquals( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'not_found', $data['code'] );
	}

	/**
	 * Test create task with invalid site_id returns error
	 */
	public function test_create_task_invalid_site() {
		wp_set_current_user( $this->admin_id );

		// First ensure wordpress-blog mapping exists
		Plugin_Mapping_Service::save( array(
			'plugin_slug'       => 'wordpress-blog',
			'plugin_name'       => 'WordPress Blog',
			'is_content_plugin' => 1,
			'post_types'        => array( 'post', 'page' ),
			'taxonomies'        => array( 'category', 'post_tag' ),
		) );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks' );
		$request->set_param( 'template', 'wordpress-blog' );
		$request->set_param( 'site_id', 99999 );
		$request->set_param( 'object_type', 'post_type' );
		$request->set_param( 'subtype', 'post' );
		$request->set_param( 'object_id', $this->test_post_id );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test update task status
	 */
	public function test_update_task_status() {
		wp_set_current_user( $this->admin_id );

		// Insert a task first
		global $wpdb;
		$table = wptsall_table( 'tasks' );
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'site_id'     => $this->test_relation_id,
				'template'    => 'test_update',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 200,
				'status'      => 'pending',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$task_id = $wpdb->insert_id;
		if ( $task_id ) {
			$this->test_task_ids[] = $task_id;
		}

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks/' . $task_id );
		$request->set_param( 'status', 'completed' );
		$request->set_param( 'note', 'Test note' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $task_id, (int) $data['id'] );
		$this->assertEquals( 'completed', $data['status'] );
	}

	/**
	 * Test get task stats
	 */
	public function test_get_task_stats() {
		wp_set_current_user( $this->admin_id );

		// Insert some test tasks
		global $wpdb;
		$tasks_table = wptsall_table( 'tasks' );

		$tasks = array(
			array(
				'blog_id'     => 1,
				'site_id'     => 1,
				'template'    => 'test_stats',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 300,
				'status'      => 'pending',
			),
			array(
				'blog_id'     => 1,
				'site_id'     => 1,
				'template'    => 'test_stats',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 301,
				'status'      => 'completed',
			),
		);

		wptsall_insert_tasks( $tasks );

		// Track inserted task IDs for cleanup.
		$inserted_ids = $wpdb->get_col(
			"SELECT id FROM {$tasks_table} WHERE template = 'test_stats' AND object_id IN (300, 301)"
		);
		foreach ( $inserted_ids as $id ) {
			$this->test_task_ids[] = (int) $id;
		}

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks/stats' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'overview', $data );
		// Check status counts are directly in overview (not nested under by_status)
		$this->assertArrayHasKey( 'total', $data['overview'] );
		$this->assertArrayHasKey( 'pending', $data['overview'] );
		$this->assertArrayHasKey( 'completed', $data['overview'] );
	}

	/**
	 * Test create language task requires parameters
	 */
	public function test_create_language_task_requires_params() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/wptsall/v2/tasks/language' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test create language task without template returns error
	 *
	 * API requires template to exist before creating language task.
	 * Without pre-scanned template, it returns 400 with 'template_not_found'.
	 */
	public function test_create_language_task() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks/language' );
		$request->set_param( 'textdomain', 'test-plugin-nonexistent' );
		$request->set_param( 'component_type', 'plugin' );
		$request->set_param( 'target_lang', 'en_US' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// API returns 400 when template not found (need to scan first)
		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'template_not_found', $data['code'] );
	}

	/**
	 * Test create language task with invalid component type
	 */
	public function test_create_language_task_invalid_type() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/tasks/language' );
		$request->set_param( 'textdomain', 'test-plugin' );
		$request->set_param( 'component_type', 'invalid' );
		$request->set_param( 'target_lang', 'en_US' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test get virtual content is public
	 */
	public function test_get_virtual_is_public() {
		// /virtual now requires auth (admin); endpoint comment "public, no auth"
		// is stale — permission_callback routes through check_permission().
		if ( '' === $this->test_virtual_site_id ) {
			$this->markTestSkipped( 'Virtual site fixture missing' );
		}
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/virtual' );
		$request->set_param( 'site_id', $this->test_virtual_site_id );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test get virtual content requires site_id
	 */
	public function test_get_virtual_requires_site_id() {
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/virtual' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test get virtual content with filters
	 */
	public function test_get_virtual_with_filters() {
		if ( '' === $this->test_virtual_site_id ) {
			$this->markTestSkipped( 'Virtual site fixture missing' );
		}
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/virtual' );
		$request->set_param( 'site_id', $this->test_virtual_site_id );
		$request->set_param( 'template', 'woocommerce' );
		$request->set_param( 'object_type', 'post_type' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test task response format
	 *
	 * Note: v0.6.0 returns paginated response {items, total, pages, page}
	 */
	public function test_task_response_format() {
		wp_set_current_user( $this->admin_id );

		// Insert a task directly via SQL to ensure it exists
		global $wpdb;
		$table = wptsall_table( 'tasks' );
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'target_type' => 'virtual',
				'site_id'     => $this->test_relation_id,
				'relation_id' => $this->test_relation_id,
				'template'    => 'test_response_format',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 400,
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( $wpdb->insert_id ) {
			$this->test_task_ids[] = $wpdb->insert_id;
		}

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		// v0.6.0 returns paginated format
		$this->assertArrayHasKey( 'items', $data, 'Response should have items key' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key' );
		$this->assertArrayHasKey( 'pages', $data, 'Response should have pages key' );
		$this->assertArrayHasKey( 'page', $data, 'Response should have page key' );

		$items = $data['items'];
		$this->assertNotEmpty( $items, 'Should return at least one task' );

		// Check response format - find the task we just inserted
		$task = null;
		foreach ( $items as $t ) {
			if ( isset( $t['template'] ) && $t['template'] === 'test_response_format' ) {
				$task = $t;
				break;
			}
		}

		$this->assertNotNull( $task, 'Should find the test task' );

		$expected_fields = array(
			'id',
			'blog_id',
			'target_blog',
			'target_type',
			'site_id',
			'template',
			'object_type',
			'subtype',
			'object_id',
			'status',
			'created_at',
			'updated_at',
		);

		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $task, "Task should have '{$field}' field" );
		}
	}

	// ========================================
	// Task Logs API Tests (v0.8.0)
	// ========================================

	/**
	 * Test task logs routes are registered
	 */
	public function test_task_logs_routes_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wptsall/v2/task-logs', $routes );
		// Note: /tasks/{id}/logs is a regex pattern, check differently
	}

	/**
	 * Test get task logs requires authentication
	 */
	public function test_get_task_logs_requires_auth() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/task-logs' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test get all task logs as admin
	 */
	public function test_get_all_task_logs_as_admin() {
		wp_set_current_user( $this->admin_id );

		// Ensure task_logs table exists
		wptsall_create_task_logs_table();

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/task-logs' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'pages', $data );
		$this->assertArrayHasKey( 'page', $data );
	}

	/**
	 * Test get task logs with filters
	 */
	public function test_get_task_logs_with_filters() {
		wp_set_current_user( $this->admin_id );

		// Ensure tables exist
		wptsall_create_tasks_table();
		wptsall_create_task_logs_table();

		// Create a test task first
		global $wpdb;
		$tasks_table = wptsall_table( 'tasks' );
		$logs_table  = wptsall_table( 'task_logs' );
		$now         = current_time( 'mysql' );

		$wpdb->insert(
			$tasks_table,
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'site_id'     => $this->test_relation_id,
				'template'    => 'test_logs_filter',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 500,
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$task_id = $wpdb->insert_id;

		// Create test log entries
		$wpdb->insert(
			$logs_table,
			array(
				'task_id'     => $task_id,
				'status_from' => 'pending',
				'status_to'   => 'active',
				'note'        => 'Test log entry',
				'context'     => '{}',
				'created_at'  => $now,
			)
		);

		// Test filter by task_id
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/task-logs' );
		$request->set_param( 'task_id', $task_id );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'items', $data );

		// Test filter by status
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/task-logs' );
		$request->set_param( 'status', 'active' );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		// Clean up
		$wpdb->delete( $logs_table, array( 'task_id' => $task_id ), array( '%d' ) );
		$wpdb->delete( $tasks_table, array( 'id' => $task_id ), array( '%d' ) );
	}

	/**
	 * Test get specific task logs
	 */
	public function test_get_specific_task_logs() {
		wp_set_current_user( $this->admin_id );

		// Ensure tables exist
		wptsall_create_tasks_table();
		wptsall_create_task_logs_table();

		// Create a test task
		global $wpdb;
		$tasks_table = wptsall_table( 'tasks' );
		$logs_table  = wptsall_table( 'task_logs' );
		$now         = current_time( 'mysql' );

		$wpdb->insert(
			$tasks_table,
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'site_id'     => $this->test_relation_id,
				'template'    => 'test_specific_logs',
				'type'        => 'monitoring',
				'object_type' => 'monitoring',
				'subtype'     => 'monitoring',
				'object_id'   => 0,
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$task_id = $wpdb->insert_id;

		// Create log entries
		$wpdb->insert(
			$logs_table,
			array(
				'task_id'     => $task_id,
				'status_from' => 'pending',
				'status_to'   => 'active',
				'note'        => 'Task started',
				'context'     => '{"trigger": "manual"}',
				'created_at'  => $now,
			)
		);

		// Test get logs for specific task
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks/' . $task_id . '/logs' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'task_id', $data );
		$this->assertEquals( $task_id, $data['task_id'] );

		if ( ! empty( $data['items'] ) ) {
			$log = $data['items'][0];
			$this->assertArrayHasKey( 'id', $log );
			$this->assertArrayHasKey( 'task_id', $log );
			$this->assertArrayHasKey( 'status_from', $log );
			$this->assertArrayHasKey( 'status_to', $log );
			$this->assertArrayHasKey( 'note', $log );
			$this->assertArrayHasKey( 'created_at', $log );
		}

		// Clean up
		$wpdb->delete( $logs_table, array( 'task_id' => $task_id ), array( '%d' ) );
		$wpdb->delete( $tasks_table, array( 'id' => $task_id ), array( '%d' ) );
	}

	/**
	 * Test get task logs for non-existent task
	 */
	public function test_get_task_logs_not_found() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/tasks/999999/logs' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
		// P1-TEST-02 (2026-09-02): object-not-found errors on the v2 REST
		// surface are standardized on rest_object_not_found via the
		// controller helper; the bare 'not_found' code is legacy.
		$this->assertEquals( 'rest_object_not_found', $response->get_data()['code'] );
	}

	/**
	 * Test task logs pagination
	 */
	public function test_task_logs_pagination() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/task-logs' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 5 );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, $data['page'] );
		$this->assertIsArray( $data['items'] );
		$this->assertLessThanOrEqual( 5, count( $data['items'] ) );
	}
}

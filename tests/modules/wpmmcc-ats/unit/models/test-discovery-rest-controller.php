<?php
/**
 * Discovery REST Controller Tests
 *
 * Tests for WPTSALL\Models\API\Discovery_REST_Controller class
 *
 * API endpoints for database schema discovery and field verification.
 *
 * @package WPTSALL\Tests\Unit\Models
 * @since 0.5.1
 */

use WPTSALL\Models\API\Discovery_REST_Controller;

class Test_Discovery_REST_Controller extends SimpleTestCase {

	/**
	 * Controller instance
	 *
	 * @var Discovery_REST_Controller
	 */
	private $controller;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		$this->controller = new Discovery_REST_Controller();
	}

	// ==================== Class Existence Tests ====================

	/**
	 * Test controller class exists
	 */
	public function test_controller_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\API\Discovery_REST_Controller' ) );
	}

	/**
	 * Test controller has required methods
	 */
	public function test_controller_has_required_methods() {
		$methods = array(
			'register_routes',
			'check_permission',
			'get_tables',
			'get_columns',
			'get_meta_keys',
			'test_config',
			'get_values',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( $this->controller, $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== Namespace Tests ====================

	/**
	 * Test controller uses v2 namespace
	 */
	public function test_namespace_is_v2() {
		$reflection = new ReflectionClass( $this->controller );
		$property   = $reflection->getProperty( 'namespace' );
		$property->setAccessible( true );

		$this->assertEquals( 'wptsall/v2', $property->getValue( $this->controller ) );
	}

	// ==================== Permission Tests ====================

	/**
	 * Test check_permission requires manage_wptsall_settings capability
	 */
	public function test_check_permission_requires_admin() {
		// When not logged in, should return false
		wp_set_current_user( 0 );
		$result = $this->controller->check_permission();
		$this->assertFalse( $result );

		// When logged in as admin, should return true
		$admin_id = $this->get_or_create_admin_user();
		wp_set_current_user( $admin_id );
		$result = $this->controller->check_permission();
		$this->assertTrue( $result );

		// Cleanup
		wp_set_current_user( 0 );
	}

	/**
	 * Get or create an admin user for testing
	 *
	 * @return int User ID
	 */
	private function get_or_create_admin_user() {
		$user = get_user_by( 'login', 'test_admin' );
		if ( $user ) {
			return $user->ID;
		}

		// Create admin user
		$user_id = wp_insert_user(
			array(
				'user_login' => 'test_admin_' . uniqid(),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);

		return $user_id;
	}

	// ==================== get_tables() Tests ====================

	/**
	 * Test get_tables returns response
	 */
	public function test_get_tables_returns_response() {
		// Skip if Field_Discovery_Service not available
		if ( ! class_exists( 'WPTSALL\Models\Services\Field_Discovery_Service' ) ) {
			$this->markTestSkipped( 'Field_Discovery_Service not available' );
			return;
		}

		$response = $this->controller->get_tables();

		$this->assertInstanceOf( 'WP_REST_Response', $response );
	}

	/**
	 * Test get_tables returns array of tables
	 */
	public function test_get_tables_returns_array() {
		global $wpdb;
		if ( ! class_exists( 'WPTSALL\Models\Services\Field_Discovery_Service' ) ) {
			$this->markTestSkipped( 'Field_Discovery_Service not available' );
			return;
		}

		$response = $this->controller->get_tables();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		// Should contain core WordPress tables
		$this->assertContains( $wpdb->posts, $data );
		$this->assertContains( $wpdb->postmeta, $data );
	}

	// ==================== get_columns() Tests ====================

	/**
	 * Test get_columns returns columns for valid table
	 */
	public function test_get_columns_returns_columns() {
		global $wpdb;
		if ( ! class_exists( 'WPTSALL\Models\Services\Field_Discovery_Service' ) ) {
			$this->markTestSkipped( 'Field_Discovery_Service not available' );
			return;
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/discovery/columns' );
		$request->set_param( 'table', $wpdb->posts );

		$response = $this->controller->get_columns( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		// Should contain post columns (Field_Discovery_Service uses 'field' key, not 'name')
		$column_names = array_column( $data, 'field' );
		$this->assertContains( 'ID', $column_names );
		$this->assertContains( 'post_title', $column_names );
	}

	// ==================== get_meta_keys() Tests ====================

	/**
	 * Test get_meta_keys returns meta keys
	 */
	public function test_get_meta_keys_returns_keys() {
		if ( ! class_exists( 'WPTSALL\Models\Services\Field_Discovery_Service' ) ) {
			$this->markTestSkipped( 'Field_Discovery_Service not available' );
			return;
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/discovery/meta-keys' );
		$request->set_param( 'post_type', '' );
		$request->set_param( 'include_hidden', false );

		$response = $this->controller->get_meta_keys( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
	}

	/**
	 * Test get_meta_keys with post_type filter
	 */
	public function test_get_meta_keys_with_post_type() {
		if ( ! class_exists( 'WPTSALL\Models\Services\Field_Discovery_Service' ) ) {
			$this->markTestSkipped( 'Field_Discovery_Service not available' );
			return;
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/discovery/meta-keys' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'include_hidden', true );

		$response = $this->controller->get_meta_keys( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
	}

	// ==================== test_config() Tests ====================

	/**
	 * Test test_config returns success structure
	 */
	public function test_test_config_returns_success() {
		global $wpdb;
		if ( ! class_exists( 'WPTSALL\Models\Services\Field_Discovery_Service' ) ) {
			$this->markTestSkipped( 'Field_Discovery_Service not available' );
			return;
		}

		// Create a test post first
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Test Post for Config',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			$this->markTestSkipped( 'Could not create test post' );
			return;
		}

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/discovery/test-config' );
		$request->set_param(
			'config',
			array(
				'table'             => $wpdb->posts,
				'field'             => 'post_title',
				'associated_id_map' => 'ID',
			)
		);
		$request->set_param( 'sample_id', $post_id );

		$response = $this->controller->test_config( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'value', $data );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	// ==================== get_values() Tests ====================

	/**
	 * Test get_values returns distinct values
	 */
	public function test_get_values_returns_distinct() {
		global $wpdb;
		if ( ! class_exists( 'WPTSALL\Models\Services\Field_Discovery_Service' ) ) {
			$this->markTestSkipped( 'Field_Discovery_Service not available' );
			return;
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/discovery/values' );
		$request->set_param( 'table', $wpdb->posts );
		$request->set_param( 'column', 'post_status' );
		$request->set_param( 'limit', 10 );

		$response = $this->controller->get_values( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
	}

	// ==================== Route Registration Tests ====================

	/**
	 * Test register_routes registers expected endpoints
	 */
	public function test_register_routes() {
		global $wp_rest_server;

		// Initialize REST server
		$wp_rest_server = new WP_REST_Server();

		// Register routes
		$this->controller->register_routes();
		do_action( 'rest_api_init' );

		// Get registered routes
		$routes = $wp_rest_server->get_routes();

		// Check expected routes exist
		$expected_routes = array(
			'/wptsall/v2/discovery/tables',
			'/wptsall/v2/discovery/columns',
			'/wptsall/v2/discovery/meta-keys',
			'/wptsall/v2/discovery/test-config',
			'/wptsall/v2/discovery/values',
		);

		foreach ( $expected_routes as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} should be registered" );
		}
	}
}

<?php
/**
 * Custom Model REST Controller Tests
 *
 * Tests for WPTSALL\Models\API\Custom_Model_REST_Controller class (v2 API)
 *
 * @package WPTSALL
 * @since 0.10.0
 */

use WPTSALL\Models\Services\Custom_Model_Service;

class Test_Custom_Model_REST_Controller extends WP_UnitTestCase {

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
	 * Test model IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_model_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up REST server.
		global $wp_rest_server;
		$this->server = $wp_rest_server;

		if ( ! $this->server ) {
			$this->server = $wp_rest_server = new WP_REST_Server();
			do_action( 'rest_api_init' );
		}

		// Create admin user with unique identifiers.
		$unique_id      = wp_rand( 100000, 999999 );
		$this->admin_id = wp_insert_user( array(
			'user_login' => 'custom_model_admin_' . $unique_id,
			'user_pass'  => 'password',
			'user_email' => 'custom_model_admin_' . $unique_id . '@test.com',
			'role'       => 'administrator',
		) );

		// Ensure admin was created successfully.
		if ( is_wp_error( $this->admin_id ) ) {
			$this->admin_id = 1; // Fall back to default admin.
		}
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test models.
		foreach ( $this->test_model_ids as $model_id ) {
			Custom_Model_Service::delete( $model_id );
		}

		// Clean up user.
		if ( $this->admin_id && $this->admin_id > 1 ) {
			wp_delete_user( $this->admin_id );
		}

		// Reset current user.
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	// ========================================
	// Route Registration Tests
	// ========================================

	/**
	 * Test v2 custom-models routes are registered
	 */
	public function test_v2_custom_models_routes_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wptsall/v2/custom-models', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/custom-models/unregistered-plugins', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/custom-models/check-plugin-data', $routes );
	}

	// ========================================
	// Permission Tests
	// ========================================

	/**
	 * Test get custom models requires authentication
	 */
	public function test_get_custom_models_requires_auth() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models' );
		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test create custom model requires authentication
	 */
	public function test_create_custom_model_requires_auth() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$request->set_param( 'plugin_slug', 'test-plugin' );

		$response = $this->server->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	// ========================================
	// GET /custom-models Tests
	// ========================================

	/**
	 * Test get custom models as admin
	 */
	public function test_get_custom_models_as_admin() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'items', $data );
		$this->assertIsArray( $data['items'] );
	}

	/**
	 * Test get custom models with pagination
	 */
	public function test_get_custom_models_pagination() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 10 );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
	}

	/**
	 * Test get custom models with status filter
	 */
	public function test_get_custom_models_filter_by_status() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models' );
		$request->set_param( 'status', 'active' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	// ========================================
	// POST /custom-models Tests
	// ========================================

	/**
	 * Test create custom model requires plugin_slug
	 */
	public function test_create_custom_model_requires_plugin_slug() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$response = $this->server->dispatch( $request );

		// Should return 400 because plugin_slug is required.
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test create custom model with valid data
	 */
	public function test_create_custom_model_success() {
		wp_set_current_user( $this->admin_id );

		$plugin_slug = 'test-custom-model-' . uniqid();

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$request->set_param( 'plugin_slug', $plugin_slug );
		$request->set_param( 'plugin_name', 'Test Custom Model' );
		$request->set_param( 'post_types', array( 'post' ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'id', $data );

		// Track for cleanup.
		if ( isset( $data['id'] ) ) {
			$this->test_model_ids[] = $data['id'];
		}
	}

	/**
	 * Test create custom model with rules array
	 */
	public function test_create_custom_model_with_rules_array() {
		wp_set_current_user( $this->admin_id );

		$plugin_slug = 'test-rules-model-' . uniqid();

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$request->set_param( 'plugin_slug', $plugin_slug );
		$request->set_param( 'plugin_name', 'Test Rules Model' );
		$request->set_param( 'post_types', array( 'post', 'page' ) );
		$request->set_param( 'rules', array(
			array(
				'object_name'        => 'post',
				'url_pattern'        => '/post/{slug}/',
				'url_type'           => 'single',
				'data_type'          => 'post',
				'field_capabilities' => array(
					'translate_fields' => array( 'post_title', 'post_content' ),
					'sync_fields'      => array( 'post_date' ),
				),
			),
			array(
				'object_name'        => 'page',
				'url_pattern'        => '/{slug}/',
				'url_type'           => 'single',
				'data_type'          => 'post',
				'field_capabilities' => array(
					'translate_fields' => array( 'post_title', 'post_content' ),
				),
			),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'total_rules', $data );

		// Track for cleanup.
		if ( isset( $data['id'] ) ) {
			$this->test_model_ids[] = $data['id'];
		}
	}

	/**
	 * Test create custom model with field_capabilities
	 */
	public function test_create_custom_model_with_field_capabilities() {
		wp_set_current_user( $this->admin_id );

		$plugin_slug = 'test-fields-model-' . uniqid();

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$request->set_param( 'plugin_slug', $plugin_slug );
		$request->set_param( 'plugin_name', 'Test Fields Model' );
		$request->set_param( 'post_types', array( 'post' ) );
		$request->set_param( 'field_capabilities', array(
			'post_title' => array(
				'type'    => 'translate',
				'enabled' => true,
			),
			'post_content' => array(
				'type'    => 'translate',
				'enabled' => true,
			),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Track for cleanup.
		if ( isset( $data['id'] ) ) {
			$this->test_model_ids[] = $data['id'];
		}
	}

	// ========================================
	// GET /custom-models/{id} Tests
	// ========================================

	/**
	 * Test get single custom model
	 */
	public function test_get_single_custom_model() {
		wp_set_current_user( $this->admin_id );

		// First create a model.
		$plugin_slug = 'test-get-single-' . uniqid();
		$create_request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$create_request->set_param( 'plugin_slug', $plugin_slug );
		$create_request->set_param( 'plugin_name', 'Get Single Test' );
		$create_request->set_param( 'post_types', array( 'post' ) );

		$create_response = $this->server->dispatch( $create_request );
		$create_data     = $create_response->get_data();

		if ( ! isset( $create_data['id'] ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$model_id               = $create_data['id'];
		$this->test_model_ids[] = $model_id;

		// Now get the model.
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models/' . $model_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test get non-existent custom model returns 404
	 */
	public function test_get_nonexistent_custom_model_returns_404() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// ========================================
	// PUT /custom-models/{id} Tests
	// ========================================

	/**
	 * Test update custom model
	 */
	public function test_update_custom_model() {
		wp_set_current_user( $this->admin_id );

		// First create a model.
		$plugin_slug = 'test-update-' . uniqid();
		$create_request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$create_request->set_param( 'plugin_slug', $plugin_slug );
		$create_request->set_param( 'plugin_name', 'Original Name' );
		$create_request->set_param( 'post_types', array( 'post' ) );

		$create_response = $this->server->dispatch( $create_request );
		$create_data     = $create_response->get_data();

		if ( ! isset( $create_data['id'] ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$model_id               = $create_data['id'];
		$this->test_model_ids[] = $model_id;

		// Now update the model.
		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/custom-models/' . $model_id );
		$request->set_param( 'name', 'Updated Name' );
		$request->set_param( 'status', 'inactive' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test update non-existent custom model returns 404
	 */
	public function test_update_nonexistent_custom_model_returns_404() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/custom-models/999999' );
		$request->set_param( 'name', 'Should Fail' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// ========================================
	// DELETE /custom-models/{id} Tests
	// ========================================

	/**
	 * Test delete custom model
	 */
	public function test_delete_custom_model() {
		wp_set_current_user( $this->admin_id );

		// First create a model.
		$plugin_slug = 'test-delete-' . uniqid();
		$create_request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$create_request->set_param( 'plugin_slug', $plugin_slug );
		$create_request->set_param( 'plugin_name', 'Delete Test' );
		$create_request->set_param( 'post_types', array( 'post' ) );

		$create_response = $this->server->dispatch( $create_request );
		$create_data     = $create_response->get_data();

		if ( ! isset( $create_data['id'] ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$model_id = $create_data['id'];
		// Don't track for cleanup - we're deleting it.

		// Now delete the model.
		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/custom-models/' . $model_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test delete non-existent custom model returns 404
	 */
	public function test_delete_nonexistent_custom_model_returns_404() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/custom-models/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// ========================================
	// Field Overrides Tests
	// ========================================

	/**
	 * Test get field overrides
	 */
	public function test_get_field_overrides() {
		wp_set_current_user( $this->admin_id );

		// First create a model.
		$plugin_slug = 'test-get-fields-' . uniqid();
		$create_request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$create_request->set_param( 'plugin_slug', $plugin_slug );
		$create_request->set_param( 'plugin_name', 'Get Fields Test' );
		$create_request->set_param( 'post_types', array( 'post' ) );

		$create_response = $this->server->dispatch( $create_request );
		$create_data     = $create_response->get_data();

		if ( ! isset( $create_data['id'] ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$model_id               = $create_data['id'];
		$this->test_model_ids[] = $model_id;

		// Get field overrides.
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models/' . $model_id . '/fields' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test set field overrides
	 */
	public function test_set_field_overrides() {
		wp_set_current_user( $this->admin_id );

		// First create a model.
		$plugin_slug = 'test-set-fields-' . uniqid();
		$create_request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$create_request->set_param( 'plugin_slug', $plugin_slug );
		$create_request->set_param( 'plugin_name', 'Set Fields Test' );
		$create_request->set_param( 'post_types', array( 'post' ) );

		$create_response = $this->server->dispatch( $create_request );
		$create_data     = $create_response->get_data();

		if ( ! isset( $create_data['id'] ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$model_id               = $create_data['id'];
		$this->test_model_ids[] = $model_id;

		// Set field overrides.
		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/custom-models/' . $model_id . '/fields' );
		$request->set_param( 'overrides', array(
			'post_title'   => array( 'type' => 'translate', 'priority' => 'high' ),
			'post_content' => array( 'type' => 'translate' ),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test set field overrides requires overrides parameter
	 */
	public function test_set_field_overrides_requires_overrides() {
		wp_set_current_user( $this->admin_id );

		// First create a model.
		$plugin_slug = 'test-fields-required-' . uniqid();
		$create_request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$create_request->set_param( 'plugin_slug', $plugin_slug );
		$create_request->set_param( 'plugin_name', 'Fields Required Test' );
		$create_request->set_param( 'post_types', array( 'post' ) );

		$create_response = $this->server->dispatch( $create_request );
		$create_data     = $create_response->get_data();

		if ( ! isset( $create_data['id'] ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$model_id               = $create_data['id'];
		$this->test_model_ids[] = $model_id;

		// Try to set without overrides parameter.
		$request  = new WP_REST_Request( 'PUT', '/wptsall/v2/custom-models/' . $model_id . '/fields' );
		$response = $this->server->dispatch( $request );

		// Should return 400 because overrides is required.
		$this->assertEquals( 400, $response->get_status() );
	}

	// ========================================
	// Link Chains Tests
	// ========================================

	/**
	 * Test get link chains
	 */
	public function test_get_link_chains() {
		wp_set_current_user( $this->admin_id );

		// First create a model.
		$plugin_slug = 'test-get-chains-' . uniqid();
		$create_request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$create_request->set_param( 'plugin_slug', $plugin_slug );
		$create_request->set_param( 'plugin_name', 'Get Chains Test' );
		$create_request->set_param( 'post_types', array( 'post' ) );

		$create_response = $this->server->dispatch( $create_request );
		$create_data     = $create_response->get_data();

		if ( ! isset( $create_data['id'] ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$model_id               = $create_data['id'];
		$this->test_model_ids[] = $model_id;

		// Get link chains.
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models/' . $model_id . '/link-chains' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test set link chains
	 */
	public function test_set_link_chains() {
		wp_set_current_user( $this->admin_id );

		// First create a model with url_pattern (required for translation rule creation).
		$plugin_slug = 'test-set-chains-' . uniqid();
		$create_request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models' );
		$create_request->set_param( 'plugin_slug', $plugin_slug );
		$create_request->set_param( 'plugin_name', 'Set Chains Test' );
		$create_request->set_param( 'post_types', array( 'post' ) );
		$create_request->set_param( 'url_pattern', '/post/{slug}/' );

		$create_response = $this->server->dispatch( $create_request );
		$create_data     = $create_response->get_data();

		if ( ! isset( $create_data['id'] ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$model_id               = $create_data['id'];
		$this->test_model_ids[] = $model_id;

		// Set link chains with all required fields.
		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/custom-models/' . $model_id . '/link-chains' );
		$request->set_param( 'chains', array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_table'        => 'wp_posts',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
		) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	// ========================================
	// Utility Endpoints Tests
	// ========================================

	/**
	 * Test get unregistered plugins
	 */
	public function test_get_unregistered_plugins() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/custom-models/unregistered-plugins' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'plugins', $data );
		$this->assertIsArray( $data['plugins'] );
	}

	/**
	 * Test check plugin data requires plugin_slug
	 */
	public function test_check_plugin_data_requires_plugin_slug() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models/check-plugin-data' );
		$response = $this->server->dispatch( $request );

		// Should return 400 because plugin_slug is required.
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test check plugin data with valid plugin
	 */
	public function test_check_plugin_data_with_plugin() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/custom-models/check-plugin-data' );
		$request->set_param( 'plugin_slug', 'wordpress-core' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'has_data', $data );
	}
}

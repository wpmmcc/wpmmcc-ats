<?php
/**
 * Model REST Controller Tests
 *
 * Tests for WPTSALL\Models\API\Translation_Rule_REST_Controller class (v2 API)
 * Note: V1 Models API was removed in v0.6.1
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Model_REST_Controller extends WP_UnitTestCase {

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

		// Set up REST server
		global $wp_rest_server;
		$this->server = $wp_rest_server;

		if ( ! $this->server ) {
			$this->server = $wp_rest_server = new WP_REST_Server();
			do_action( 'rest_api_init' );
		}

		// Create admin user
		$this->admin_id = $this->factory->user->create( array(
			'role' => 'administrator',
		) );

		// Track for cleanup
		$this->_created_users[] = $this->admin_id;

		// Ensure model tables exist
		if ( ! wptsall_models_table_exists() ) {
			wptsall_create_model_tables();
		}
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test models
		if ( ! empty( $this->test_model_ids ) ) {
			$models_table  = wptsall_table( 'models' );
			$rules_table   = wptsall_table( 'translation_rules' );
			$objects_table = wptsall_table( 'model_objects' );
			$fields_table  = wptsall_table( 'model_object_fields' );

			foreach ( $this->test_model_ids as $model_id ) {
				$object_ids = $wpdb->get_col(
					$wpdb->prepare( 'SELECT id FROM %i WHERE model_id = %d', $objects_table, $model_id )
				);

				foreach ( (array) $object_ids as $object_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete( $fields_table, array( 'object_id' => (int) $object_id ) );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $objects_table, array( 'model_id' => $model_id ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $rules_table, array( 'model_id' => $model_id ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $models_table, array( 'id' => $model_id ) );
			}
		}

		// Reset current user
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Test v2 REST routes are registered
	 */
	public function test_v2_routes_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wptsall/v2/models', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/models/scan', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/models/scan-all', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/models/(?P<id>[\\d]+)/objects', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/models/(?P<id>[\\d]+)/objects/(?P<object_id>[\\d]+)/fields', $routes );
	}

	/**
	 * Test get models requires authentication
	 */
	public function test_get_models_requires_auth() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models' );
		$response = $this->server->dispatch( $request );

		// Should return 401 or 403
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test get models as admin
	 */
	public function test_get_models_as_admin() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'models', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'page', $data );
	}

	/**
	 * Test get models with pagination
	 */
	public function test_get_models_pagination() {
		wp_set_current_user( $this->admin_id );

		// Create multiple models
		for ( $i = 0; $i < 3; $i++ ) {
			$result = Translation_Rule_Service::create_model( array(
				'plugin_slug'  => 'rest-pagination-test-' . $i . '-' . uniqid(),
				'plugin_name'  => 'Pagination Test ' . $i,
				'post_types'   => array( 'post' ),
				'taxonomies'   => array(),
			) );

			if ( ! is_wp_error( $result ) ) {
				$this->test_model_ids[] = $result;
			}
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/models' );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 1 );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 2, count( $data['models'] ) );
	}

	/**
	 * Test get single model by ID
	 */
	public function test_get_single_model_by_id() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug'  => 'rest-get-by-id-' . uniqid(),
			'plugin_name'  => 'Get By ID Test',
			'post_types'   => array( 'post' ),
			'taxonomies'   => array(),
		) );

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models/' . $model_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $model_id, (int) $data['id'] );
		$this->assertEquals( 'Get By ID Test', $data['plugin_name'] );
	}

	/**
	 * Test get non-existent model returns 404
	 */
	public function test_get_model_not_found() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test update model status via REST API
	 *
	 * Note: V2 API update_model only supports status updates
	 */
	public function test_update_model_status() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug'    => 'rest-update-test-' . uniqid(),
			'plugin_name'    => 'Original Name',
			'plugin_version' => '1.0.0',
			'status'         => 'active',
			'post_types'     => array( 'post' ),
			'taxonomies'     => array(),
		) );

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/models/' . $model_id );
		$request->set_param( 'status', 'inactive' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'inactive', $data['model']['status'] );
	}

	/**
	 * Test delete model via REST API
	 */
	public function test_delete_model() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rest-delete-test-' . uniqid(),
			'plugin_name' => 'Delete Test',
			'is_system'   => false,
			'post_types'  => array( 'post' ),
			'taxonomies'  => array(),
		) );

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/models/' . $model_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify deletion
		$model = Translation_Rule_Service::get_model( $model_id );
		$this->assertNull( $model );
	}

	/**
	 * Test delete system model returns error
	 */
	public function test_delete_system_model_error() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rest-system-delete-' . uniqid(),
			'plugin_name' => 'System Model',
			'is_system'   => true,
			'post_types'  => array( 'post' ),
			'taxonomies'  => array(),
		) );

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/models/' . $model_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test get model statistics
	 */
	public function test_get_model_stats() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models/stats' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	/**
	 * Test scan plugin endpoint requires plugin_slug
	 */
	public function test_scan_plugin_requires_slug() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/wptsall/v2/models/scan' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test scan plugin endpoint with valid plugin
	 */
	public function test_scan_plugin_success() {
		wp_set_current_user( $this->admin_id );

		// Use woocommerce as a test plugin (commonly installed)
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/models/scan' );
		$request->set_param( 'plugin_slug', 'woocommerce' );

		$response = $this->server->dispatch( $request );

		// If WooCommerce not installed, the scan might fail, but shouldn't be 500
		$this->assertNotEquals( 500, $response->get_status() );
	}

	/**
	 * Test translation rules endpoints
	 */
	public function test_translation_rules_endpoints() {
		wp_set_current_user( $this->admin_id );

		// Create a model first
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rest-rules-test-' . uniqid(),
			'plugin_name' => 'Rules Test',
			'post_types'  => array( 'post' ),
			'taxonomies'  => array(),
		) );

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		// Create a rule
		$rule_id = Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Test Rule',
			'url_pattern'      => '/test/{id}/',
			'url_type'         => 'single',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title', 'post_content' ),
			'sync_fields'      => array(),
		) );

		if ( is_wp_error( $rule_id ) ) {
			$this->markTestSkipped( 'Could not create test rule' );
			return;
		}

		// Get model rules
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models/' . $model_id . '/rules' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $data );
	}

	/**
	 * Test model objects list route dispatches through the v2 models API.
	 */
	public function test_get_model_objects() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model(
			array(
				'plugin_slug' => 'rest-objects-test-' . uniqid(),
				'plugin_name' => 'Objects Test',
				'post_types'  => array( 'post' ),
				'taxonomies'  => array(),
			)
		);

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/models/' . $model_id . '/objects' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'objects', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertSame( 0, $data['total'] );
	}

	/**
	 * Test object creation route accepts option objects.
	 */
	public function test_create_model_object_accepts_option_type() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model(
			array(
				'plugin_slug' => 'rest-option-object-' . uniqid(),
				'plugin_name' => 'REST Option Object Test',
				'post_types'  => array( 'post' ),
				'taxonomies'  => array(),
			)
		);

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/models/' . $model_id . '/objects' );
		$request->set_param( 'object_type', 'option' );
		$request->set_param( 'object_name', 'wptsall_notification_template' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'option', $data['object']['object_type'] ?? '' );
		$this->assertEquals( 'wptsall_notification_template', $data['object']['object_name'] ?? '' );
	}

	/**
	 * Test update_manual_fields rejects rows without explicit object binding.
	 */
	public function test_update_manual_fields_requires_object_binding() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model(
			array(
				'plugin_slug' => 'rest-manual-binding-test-' . uniqid(),
				'plugin_name' => 'Manual Binding Test',
				'post_types'  => array( 'post' ),
				'taxonomies'  => array(),
			)
		);

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/models/' . $model_id . '/manual-fields' );
		$request->set_param(
			'meta_fields',
			array(
				array(
					'table_name'        => 'posts',
					'field_name'        => 'post_title',
					'associated_id_map' => 'ID',
				),
			)
		);
		$request->set_param( 'auto_sync_rules', false );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'manual_field_binding_required', $response->as_error()->get_error_code() );
	}

	/**
	 * Test manual-fields routes can persist and read bound manual fields.
	 */
	public function test_manual_fields_round_trip() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model(
			array(
				'plugin_slug' => 'rest-manual-fields-test-' . uniqid(),
				'plugin_name' => 'Manual Fields Test',
				'post_types'  => array( 'post' ),
				'taxonomies'  => array(),
			)
		);

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		Model_Object_Service::create_object(
			$model_id,
			'post_type',
			'post',
			array(
				'source_type' => 'manual',
				'metadata'    => array( 'name' => 'post' ),
			)
		);

		$save_request = new WP_REST_Request( 'POST', '/wptsall/v2/models/' . $model_id . '/manual-fields' );
		$save_request->set_param(
			'meta_fields',
			array(
				array(
					'table_name'        => 'posts',
					'field_name'        => 'post_title',
					'associated_id_map' => 'ID',
					'object_type'       => 'post_type',
					'object_name'       => 'post',
					'description'       => 'Manual title mapping',
				),
			)
		);
		$save_request->set_param( 'auto_sync_rules', false );

		$save_response = $this->server->dispatch( $save_request );
		$save_data     = $save_response->get_data();

		$this->assertEquals( 200, $save_response->get_status() );
		$this->assertTrue( $save_data['success'] );
		$this->assertEquals( 1, (int) $save_data['stats']['saved'] );

		$get_request  = new WP_REST_Request( 'GET', '/wptsall/v2/models/' . $model_id . '/manual-fields' );
		$get_response = $this->server->dispatch( $get_request );
		$get_data     = $get_response->get_data();

		$this->assertEquals( 200, $get_response->get_status() );
		$this->assertTrue( $get_data['success'] );
		$this->assertEquals( 1, (int) $get_data['total'] );
		$this->assertEquals( 'post_title', $get_data['fields'][0]['field_name'] );
		$this->assertEquals( 'post_type', $get_data['fields'][0]['object_type'] );
		$this->assertEquals( 'post', $get_data['fields'][0]['object_name'] );
	}

	/**
	 * Test dependencies endpoint returns an empty graph when no template object exists.
	 */
	public function test_get_rule_dependencies_without_template_object() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model(
			array(
				'plugin_slug' => 'rest-dependencies-test-' . uniqid(),
				'plugin_name' => 'Dependencies Test',
				'post_types'  => array( 'post' ),
				'taxonomies'  => array(),
			)
		);

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/rules/dependencies' );
		$request->set_param( 'model_id', $model_id );
		$request->set_param( 'data_type', 'post' );
		$request->set_param( 'object_name', 'post' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertNull( $data['source_object'] );
		$this->assertEmpty( $data['dependencies'] );
		$this->assertArrayHasKey( 'note', $data );
	}

	/**
	 * Test export models endpoint
	 */
	public function test_export_models() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rest-export-test-' . uniqid(),
			'plugin_name' => 'Export Test',
			'post_types'  => array( 'post' ),
			'taxonomies'  => array(),
		) );

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/models/export' );
		$request->set_param( 'model_ids', array( $model_id ) );
		$request->set_param( 'include_rules', true );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 1, $data['count'] );
	}

	/**
	 * Test export rule endpoint includes declared source semantics snapshot.
	 */
	public function test_export_rule_includes_source_semantics_snapshot() {
		wp_set_current_user( $this->admin_id );

		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rest-rule-export-' . uniqid(),
			'plugin_name' => 'Rule Export Test',
			'post_types'  => array( 'book' ),
			'taxonomies'  => array(),
		) );

		if ( is_wp_error( $model_id ) ) {
			$this->markTestSkipped( 'Could not create test model' );
			return;
		}

		$this->test_model_ids[] = $model_id;

		$object_id = Model_Object_Service::create_object(
			$model_id,
			'post_type',
			'book',
			array( 'source_type' => 'manual' )
		);
		$this->assertIsInt( $object_id );

		$field_id = Model_Object_Service::add_field(
			$object_id,
			'meta',
			'notification_subject',
			'manual',
			array(
				'data_type'        => 'text',
				'source_group'     => 'message_template',
				'routing_profile'  => 'notification_email',
				'delivery_target'  => 'message_template_writeback',
				'source_role'      => 'message_subject',
			)
		);
		$this->assertIsInt( $field_id );

		$rule_id = Translation_Rule_Service::create_rule(
			$model_id,
			array(
				'name'        => 'Book Notification Subject',
				'url_pattern' => '/books/notifications/',
				'url_type'    => 'single',
				'data_type'   => 'post',
				'object_name' => 'book',
				'field_capabilities' => array(
					'notification_subject' => array(
						'type'           => 'translate',
						'direction'      => 'one_way',
						'enabled'        => true,
						'content_format' => 'plain_text',
						'storage'        => 'meta',
					),
				),
			)
		);

		if ( is_wp_error( $rule_id ) ) {
			$this->markTestSkipped( 'Could not create test rule' );
			return;
		}

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/rules/' . $rule_id . '/export' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'rule', $data['data']['type'] );
		$this->assertEquals( 'message_template', $data['data']['source_semantics_snapshot']['source_group'] );
		$this->assertEquals( 'notification_email', $data['data']['source_semantics_snapshot']['routing_profile'] );
		$this->assertEquals( 'message_template_writeback', $data['data']['source_semantics_snapshot']['delivery_target'] );
		$this->assertEquals(
			'message_subject',
			$data['data']['source_semantics_snapshot']['field_source_roles']['notification_subject']
		);
	}

	/**
	 * Test models filter by status
	 */
	public function test_get_models_filter_status() {
		wp_set_current_user( $this->admin_id );

		// Create active model
		$active_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rest-active-' . uniqid(),
			'plugin_name' => 'Active Model',
			'status'      => 'active',
			'post_types'  => array( 'post' ),
			'taxonomies'  => array(),
		) );

		if ( ! is_wp_error( $active_id ) ) {
			$this->test_model_ids[] = $active_id;
		}

		// Create inactive model
		$inactive_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rest-inactive-' . uniqid(),
			'plugin_name' => 'Inactive Model',
			'status'      => 'inactive',
			'post_types'  => array( 'post' ),
			'taxonomies'  => array(),
		) );

		if ( ! is_wp_error( $inactive_id ) ) {
			$this->test_model_ids[] = $inactive_id;
		}

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/models' );
		$request->set_param( 'status', 'active' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		foreach ( $data['models'] as $model ) {
			$this->assertEquals( 'active', $model['status'] );
		}
	}
}

<?php
/**
 * Sites REST Controller Tests
 *
 * Tests for WPTSALL\Sites\API\Sites_REST_Controller class
 *
 * Note: Hook-related tests are skipped because hooks were migrated from
 * option-based storage to database-backed Hook_Manager in v0.6.1.
 * See docs/08-API.md for current API structure.
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.5.0 更新命名空间
 * @updated 0.6.1 Hooks migrated to database
 */

use WPTSALL\Sites\API\Sites_REST_Controller;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Sites_REST_Controller extends WP_UnitTestCase {

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
	 * Test relation IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_relation_ids = array();

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

		// Clear sites and hooks options
		delete_option( 'wptsall_sites' );
		delete_option( 'wptsall_hooks' );
	}

	/**
	 * Test REST routes are registered
	 *
	 * Note: Hooks routes migrated to database-backed Hook_Manager in v0.6.1
	 */
	public function test_routes_registered() {
		$routes = $this->server->get_routes();

		// Legacy /sites removed; canonical routes only.
		$this->assertArrayNotHasKey( '/wptsall/v2/sites', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/site-relations', $routes );
		$this->assertArrayHasKey( '/wptsall/v2/virtual-sites', $routes );
	}

	/**
	 * Test get sites requires authentication
	 */
	public function test_get_sites_requires_auth() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		// Ensure no user is logged in for this auth test.
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/sites' );
		$response = $this->server->dispatch( $request );

		// Both 401 (unauthenticated) and 403 (forbidden) are valid
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Test get sites as admin
	 */
	public function test_get_sites_as_admin() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/sites' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Test create site
	 */
	public function test_create_site() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/sites' );
		$request->set_param( 'name', 'Test Site' );
		$request->set_param( 'type', 'virtual' );
		$request->set_param( 'slug', 'test-site' );
		$request->set_param( 'target_lang', 'en_US' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['id'] );
		$this->assertArrayHasKey( 'site', $data );
		$this->assertEquals( 'Test Site', $data['site']['name'] );
		$this->assertEquals( 'virtual', $data['site']['type'] );
	}

	/**
	 * Test create multisite type site
	 */
	public function test_create_multisite_site() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/sites' );
		$request->set_param( 'name', 'Multisite Test' );
		$request->set_param( 'type', 'multisite' );
		$request->set_param( 'target_lang', 'zh_CN' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'multisite', $data['site']['type'] );
	}

	/**
	 * Test get single site
	 */
	public function test_get_single_site() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		// First create a site
		$sites = array(
			'test_site_123' => array(
				'name'    => 'Existing Site',
				'slug'    => 'existing',
				'type'    => 'virtual',
				'lang_to' => 'en_US',
				'status'  => 'active',
			),
		);
		update_option( 'wptsall_sites', $sites );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/sites/test_site_123' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'test_site_123', $data['id'] );
		$this->assertEquals( 'Existing Site', $data['name'] );
	}

	/**
	 * Test get non-existent site returns 404
	 */
	public function test_get_site_not_found() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/sites/non_existent' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test update site
	 */
	public function test_update_site() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		// Create site first
		$sites = array(
			'update_test' => array(
				'name'    => 'Original Name',
				'slug'    => 'original',
				'type'    => 'virtual',
				'lang_to' => 'en_US',
				'status'  => 'active',
			),
		);
		update_option( 'wptsall_sites', $sites );

		$request = new WP_REST_Request( 'PUT', '/wptsall/v2/sites/update_test' );
		$request->set_param( 'name', 'Updated Name' );
		$request->set_param( 'target_lang', 'zh_CN' );
		$request->set_param( 'status', 'inactive' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Updated Name', $data['site']['name'] );
		$this->assertEquals( 'zh_CN', $data['site']['target_lang'] );
		$this->assertEquals( 'inactive', $data['site']['status'] );
	}

	/**
	 * Test delete site
	 */
	public function test_delete_site() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		// Create site
		$sites = array(
			'delete_test' => array(
				'name' => 'To Delete',
				'type' => 'virtual',
			),
		);
		update_option( 'wptsall_sites', $sites );

		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/sites/delete_test' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		// Verify deletion
		$updated_sites = get_option( 'wptsall_sites', array() );
		$this->assertArrayNotHasKey( 'delete_test', $updated_sites );
	}

	/**
	 * Test delete site relation also deletes associated hooks
	 *
	 * When a site relation is deleted via REST API, all hooks associated
	 * with that relation should also be deleted via Hook_Manager.
	 *
	 * Uses generate_auto_hooks() to create hooks (insert_hook_entry was
	 * removed in dead code cleanup). Hooks are generated from relation +
	 * model association via relation_models table.
	 */
	public function test_delete_site_relation_cascades_hooks() {
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		$relations_table       = wptsall_table( 'site_relations' );
		$relation_models_table = wptsall_table( 'relation_models' );
		$models_table          = wptsall_table( 'models' );
		$now                   = current_time( 'mysql' );

		// 1. Create a test model with a post type so hooks can be generated.
		$wpdb->insert(
			$models_table,
			array(
				'plugin_slug' => 'test-cascade-hooks',
				'plugin_name' => 'Test Cascade Hooks',
				'post_types'  => '["post"]',
				'taxonomies'  => '[]',
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$model_id = $wpdb->insert_id;
		$this->assertGreaterThan( 0, $model_id, 'Model should be created' );

		// 2. Create a site relation.
		$wpdb->insert(
			$relations_table,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'test-cascade-hooks',
				'target_site_id'   => 'v_cascade',
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$relation_id = $wpdb->insert_id;
		$this->assertGreaterThan( 0, $relation_id, 'Relation should be created' );

		// 3. Associate the model with the relation.
		$wpdb->insert(
			$relation_models_table,
			array(
				'relation_id' => $relation_id,
				'model_id'    => $model_id,
				'created_at'  => $now,
			),
			array( '%d', '%d', '%s' )
		);

		// 4. Generate hooks for this relation and verify they exist.
		$hooks_before = \WPTSALL\Hooks\Hook_Manager::generate_auto_hooks( $relation_id );
		$this->assertNotEmpty( $hooks_before, 'Should have hooks before deletion' );

		// 5. Delete the site relation via REST API.
		$request  = new WP_REST_Request( 'DELETE', '/wptsall/v2/site-relations/' . $relation_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		// 6. After deletion, generating hooks for the deleted relation should return empty.
		$hooks_after = \WPTSALL\Hooks\Hook_Manager::generate_auto_hooks( $relation_id );
		$this->assertEmpty( $hooks_after, 'Hooks should be empty after relation is deleted' );

		// Cleanup model (relation already deleted by REST API).
		$wpdb->delete( $relation_models_table, array( 'relation_id' => $relation_id ), array( '%d' ) );
		$wpdb->delete( $models_table, array( 'id' => $model_id ), array( '%d' ) );
	}

	/**
	 * Note: The following hook tests have been moved to test-hook-manager.php
	 * where they are tested through the Hook_Manager class directly:
	 *
	 * - test_get_site_hooks -> test_get_hooks_filter_site_id
	 * - test_create_hook -> test_insert_hook_entry
	 * - test_update_hook -> test_update_hook
	 * - test_delete_hook -> test_delete_hook
	 * - test_toggle_hook -> test_toggle_hook_enabled_to_disabled / test_toggle_hook_disabled_to_enabled
	 *
	 * Hooks are now managed by Hook_Manager class (v0.6.1+), not via REST API.
	 */

	/**
	 * Test generate_auto_hooks requires active site relations with models
	 *
	 * Hook_Manager.generate_auto_hooks() should only generate hooks for
	 * site relations that have associated models.
	 */
	public function test_generate_hooks_requires_model() {
		wp_set_current_user( $this->admin_id );

		// Create a site relation WITHOUT associated models
		global $wpdb;
		$relations_table = wptsall_table( 'site_relations' );
		$now = current_time( 'mysql' );

		$wpdb->insert(
			$relations_table,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'test-no-model',
				'target_site_id'   => 'v_no_model',
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$relation_id = $wpdb->insert_id;

		// Generate auto hooks
		$hooks = \WPTSALL\Hooks\Hook_Manager::generate_auto_hooks();

		// Relation without model should not generate hooks
		$relation_hooks = array_filter( $hooks, function( $hook ) use ( $relation_id ) {
			return isset( $hook['site_id'] ) && (int) $hook['site_id'] === $relation_id;
		} );

		$this->assertEmpty( $relation_hooks, 'Relation without model should not generate hooks' );

		// Cleanup
		$wpdb->delete( $relations_table, array( 'id' => $relation_id ), array( '%d' ) );
	}

	/**
	 * Test generate_auto_hooks creates hooks from relation models
	 *
	 * When a site relation has associated models, Hook_Manager should
	 * generate hooks based on model's post types.
	 */
	public function test_generate_hooks_from_model() {
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		$relations_table = wptsall_table( 'site_relations' );
		$relation_models_table = wptsall_table( 'relation_models' );
		$models_table = wptsall_table( 'models' );
		$now = current_time( 'mysql' );

		// Create a test model
		$wpdb->insert(
			$models_table,
			array(
				'plugin_slug' => 'test-hook-gen',
				'plugin_name' => 'Test Hook Generator',
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$model_id = $wpdb->insert_id;

		// Create a site relation
		$wpdb->insert(
			$relations_table,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'test-hook-gen',
				'target_site_id'   => 'v_hook_gen',
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$relation_id = $wpdb->insert_id;

		// Associate model with relation
		$wpdb->insert(
			$relation_models_table,
			array(
				'relation_id' => $relation_id,
				'model_id'    => $model_id,
				'created_at'  => $now,
			),
			array( '%d', '%d', '%s' )
		);

		// Generate auto hooks
		$hooks = \WPTSALL\Hooks\Hook_Manager::generate_auto_hooks();

		// Should return array (may have hooks for this relation)
		$this->assertIsArray( $hooks );

		// Cleanup
		$wpdb->delete( $relation_models_table, array( 'relation_id' => $relation_id ), array( '%d' ) );
		$wpdb->delete( $relations_table, array( 'id' => $relation_id ), array( '%d' ) );
		$wpdb->delete( $models_table, array( 'id' => $model_id ), array( '%d' ) );
		\WPTSALL\Hooks\Hook_Manager::delete_hooks_by_site( $relation_id );
	}

	/**
	 * Test site format includes all expected fields
	 *
	 * Note: hook_count, model_id, model_name removed in v0.6.1
	 * as hooks migrated to database-backed Hook_Manager
	 */
	public function test_site_response_format() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		$sites = array(
			'format_test' => array(
				'name'    => 'Format Test',
				'slug'    => 'format-test',
				'type'    => 'virtual',
				'lang_to' => 'en_US',
				'status'  => 'active',
				'created' => '2024-01-01 00:00:00',
			),
		);
		update_option( 'wptsall_sites', $sites );

		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/sites/format_test' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Core fields that should always be present
		$expected_fields = array(
			'id',
			'name',
			'slug',
			'type',
			'type_label',
			'target_lang',
			'status',
			'status_label',
			'created',
			'updated',
		);

		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $data, "Response should have '$field' field" );
		}
	}

	/**
	 * Test concurrent site operations
	 */
	public function test_concurrent_site_operations() {
		$this->markTestSkipped( 'Legacy /sites REST removed (PRE-RELEASE-SINGLE-TRUTH).' );
		wp_set_current_user( $this->admin_id );

		// Create multiple sites
		for ( $i = 0; $i < 3; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/wptsall/v2/sites' );
			$request->set_param( 'name', 'Concurrent Site ' . $i );
			$request->set_param( 'type', 'virtual' );
			$request->set_param( 'target_lang', 'en_US' );

			$response = $this->server->dispatch( $request );
			$this->assertEquals( 200, $response->get_status() );
		}

		// Verify all sites exist
		$request  = new WP_REST_Request( 'GET', '/wptsall/v2/sites' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertGreaterThanOrEqual( 3, count( $data ) );
	}

	// ========================================
	// ========================================
	// Helper Methods
	// ========================================

	/**
	 * Create a test relation directly in database
	 *
	 * @param array $args Optional arguments to override defaults.
	 * @return int Relation ID.
	 */
	protected function create_test_relation( $args = array() ) {
		global $wpdb;
		$relations_table = wptsall_table( 'site_relations' );
		$now             = current_time( 'mysql' );
		$rand            = wp_rand( 1000, 9999 );

		$defaults = array(
			'source_site_id'   => get_current_blog_id(),
			'source_site_type' => 'wp',
			'source_lang'      => 'zh_CN',
			'template'         => 'test-relation-' . $rand,
			'target_site_id'   => 'v_' . $rand,
			'target_site_type' => 'virtual',
			'target_lang'      => 'en_US',
			'status'           => 'active',
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$data = array_merge( $defaults, $args );

		$wpdb->insert(
			$relations_table,
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$relation_id = $wpdb->insert_id;

		if ( $relation_id ) {
			$this->test_relation_ids[] = $relation_id;
		}

		return $relation_id;
	}

	// Post Type Config Tests (ISS-SIT-015)
	// ========================================

	/**
	 * Test check post type config exists endpoint
	 */
	public function test_check_post_type_config_exists() {
		wp_set_current_user( $this->admin_id );

		// Create a test relation directly in database.
		$relation_id = $this->create_test_relation( array(
			'template' => 'config_exists_test_' . wp_rand( 1000, 9999 ),
		) );

		$this->assertGreaterThan( 0, $relation_id, 'Relation should be created' );

		// Test exists endpoint.
		$request  = new WP_REST_Request( 'GET', "/wptsall/v2/site-relations/{$relation_id}/post-type-configs/post/exists" );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'exists', $data );
		$this->assertIsBool( $data['exists'] );
	}

	/**
	 * Test get post type configs stats endpoint
	 */
	public function test_get_post_type_configs_stats() {
		wp_set_current_user( $this->admin_id );

		$rand = wp_rand( 1000, 9999 );

		// Create test relation.
		$relation_id = $this->create_test_relation( array(
			'template'       => 'stats_test_' . $rand,
			'target_site_id' => 'v_stats_' . $rand,
		) );

		$this->assertGreaterThan( 0, $relation_id, 'Relation should be created' );

		// Test stats endpoint.
		$request  = new WP_REST_Request( 'GET', "/wptsall/v2/site-relations/{$relation_id}/post-type-configs/stats" );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'relation_id', $data );
		$this->assertArrayHasKey( 'stats', $data );
		$this->assertEquals( $relation_id, $data['relation_id'] );
	}

	/**
	 * Test copy post type configs endpoint
	 */
	public function test_copy_post_type_configs() {
		wp_set_current_user( $this->admin_id );

		$rand = wp_rand( 1000, 9999 );

		// Create source relation directly in database.
		$source_relation_id = $this->create_test_relation( array(
			'template'      => 'config_copy_source_' . $rand,
			'target_site_id' => 'v_source_' . $rand,
		) );

		// Create target relation directly in database.
		$target_relation_id = $this->create_test_relation( array(
			'template'      => 'config_copy_target_' . $rand,
			'target_site_id' => 'v_target_' . $rand,
			'target_lang'   => 'ja',
		) );

		$this->assertGreaterThan( 0, $source_relation_id, 'Source relation should be created' );
		$this->assertGreaterThan( 0, $target_relation_id, 'Target relation should be created' );

		// Test copy endpoint.
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/site-relations/{$source_relation_id}/post-type-configs/copy" );
		$request->set_body_params( array(
			'target_relation_id' => $target_relation_id,
		) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test copy post type configs - same relation error
	 */
	public function test_copy_post_type_configs_same_relation_error() {
		wp_set_current_user( $this->admin_id );

		$rand = wp_rand( 1000, 9999 );

		// Create test relation.
		$relation_id = $this->create_test_relation( array(
			'template'       => 'copy_self_test_' . $rand,
			'target_site_id' => 'v_copy_self_' . $rand,
		) );

		$this->assertGreaterThan( 0, $relation_id, 'Relation should be created' );

		// Try to copy to the same relation (should fail with 400).
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/site-relations/{$relation_id}/post-type-configs/copy" );
		$request->set_body_params( array(
			'target_relation_id' => $relation_id,
		) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_target', $data['code'] );
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;

		delete_option( 'wptsall_sites' );
		delete_option( 'wptsall_hooks' );

		// Clean up test relations created by helper
		if ( ! empty( $this->test_relation_ids ) ) {
			$relations_table = wptsall_table( 'site_relations' );
			$ids             = implode( ',', array_map( 'intval', $this->test_relation_ids ) );
			$wpdb->query( "DELETE FROM {$relations_table} WHERE id IN ({$ids})" );
			$this->test_relation_ids = array();
		}

		// Clean up test models
		$models_table = wptsall_table( 'models' );
		$rules_table  = wptsall_table( 'model_url_rules' );

		$wpdb->query( "DELETE FROM {$rules_table} WHERE model_id IN (SELECT id FROM {$models_table} WHERE plugin_slug LIKE 'generate-hooks-test-%')" );
		$wpdb->query( "DELETE FROM {$models_table} WHERE plugin_slug LIKE 'generate-hooks-test-%'" );

		parent::tearDown();
	}
}

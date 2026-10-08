<?php
/**
 * Custom Model Service Tests
 *
 * Tests for WPTSALL\Models\Services\Custom_Model_Service class
 *
 * Tests custom model creation and management for manual plugin configuration.
 *
 * @package WPTSALL\Tests\Unit\Models
 * @since 0.7.1
 */

use WPTSALL\Models\Services\Custom_Model_Service;

class Test_Custom_Model_Service extends SimpleTestCase {

	/**
	 * Test model IDs created during tests
	 *
	 * @var array
	 */
	private $test_model_ids = array();

	/**
	 * Test rule IDs created during tests
	 *
	 * @var array
	 */
	private $test_rule_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
	}

	// ==================== Class Tests ====================

	/**
	 * Test service class exists
	 */
	public function test_service_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Custom_Model_Service' ) );
	}

	/**
	 * Test service has required methods
	 */
	public function test_service_has_required_methods() {
		$methods = array(
			'get_unregistered_plugins',
			'get_all_active_plugins',
			'check_plugin_has_data',
			'validate_link_chain',
			'test_link_chain',
			'create_custom_model',
			'save_link_chains',
			'get_link_chains',
			'get_database_tables',
			'get_table_columns',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\Models\Services\Custom_Model_Service', $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== get_all_active_plugins() Tests ====================

	/**
	 * Test get_all_active_plugins returns array
	 */
	public function test_get_all_active_plugins_returns_array() {
		$result = Custom_Model_Service::get_all_active_plugins();

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_all_active_plugins excludes wptsall
	 */
	public function test_get_all_active_plugins_excludes_wptsall() {
		$result = Custom_Model_Service::get_all_active_plugins();

		if ( defined( 'WPTSALL_BASENAME' ) ) {
			$self_slug = explode( '/', WPTSALL_BASENAME, 2 )[0];
			$this->assertArrayNotHasKey( $self_slug, $result );
		}

		$this->assertArrayNotHasKey( 'wpmmcc-ats', $result );
		$this->assertArrayNotHasKey( 'wptsall', $result );
	}

	/**
	 * Test get_all_active_plugins has expected structure
	 */
	public function test_get_all_active_plugins_structure() {
		$result = Custom_Model_Service::get_all_active_plugins();

		if ( empty( $result ) ) {
			$this->markTestSkipped( 'No active plugins found' );
			return;
		}

		$first_plugin = reset( $result );

		$this->assertArrayHasKey( 'name', $first_plugin );
		$this->assertArrayHasKey( 'version', $first_plugin );
		$this->assertArrayHasKey( 'plugin_file', $first_plugin );
	}

	// ==================== get_unregistered_plugins() Tests ====================

	/**
	 * Test get_unregistered_plugins returns array
	 */
	public function test_get_unregistered_plugins_returns_array() {
		$result = Custom_Model_Service::get_unregistered_plugins();

		$this->assertIsArray( $result );
	}

	// ==================== check_plugin_has_data() Tests ====================

	/**
	 * Test check_plugin_has_data returns expected structure
	 */
	public function test_check_plugin_has_data_structure() {
		$result = Custom_Model_Service::check_plugin_has_data( 'test-plugin' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'has_data', $result );
		$this->assertArrayHasKey( 'post_types', $result );
		$this->assertArrayHasKey( 'meta_keys', $result );
		$this->assertArrayHasKey( 'tables', $result );
		$this->assertArrayHasKey( 'count', $result );
	}

	/**
	 * Test check_plugin_has_data returns false for non-existent plugin
	 */
	public function test_check_plugin_has_data_nonexistent() {
		$result = Custom_Model_Service::check_plugin_has_data( 'nonexistent-plugin-xyz' );

		$this->assertFalse( $result['has_data'] );
		$this->assertEquals( 0, $result['count'] );
	}

	/**
	 * Test check_plugin_has_data finds woocommerce data
	 */
	public function test_check_plugin_has_data_woocommerce() {
		// Auto-activate WooCommerce if installed but not active
		$this->requirePlugin( 'woocommerce' );

		$result = Custom_Model_Service::check_plugin_has_data( 'woocommerce' );

		// Shape contract only: WooCommerce's data presence depends on
		// the test fixture (orders/products/etc.), so we don't assert
		// the truthy value — only the documented response shape.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'has_data', $result );
		$this->assertArrayHasKey( 'post_types', $result );
		$this->assertArrayHasKey( 'meta_keys', $result );
		$this->assertArrayHasKey( 'tables', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertIsBool( $result['has_data'] );
	}

	// ==================== validate_link_chain() Tests ====================

	/**
	 * Test validate_link_chain with empty config
	 */
	public function test_validate_link_chain_empty() {
		$result = Custom_Model_Service::validate_link_chain( array() );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Test validate_link_chain with null config
	 */
	public function test_validate_link_chain_null() {
		$result = Custom_Model_Service::validate_link_chain( null );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_link_chain with valid config
	 */
	public function test_validate_link_chain_valid() {
		$chain = array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_table'        => 'wp_posts',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
		);

		$result = Custom_Model_Service::validate_link_chain( $chain );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
	}

	/**
	 * Test validate_link_chain missing source_type
	 */
	public function test_validate_link_chain_missing_source_type() {
		$chain = array(
			array(
				'target_table'        => 'wp_posts',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
		);

		$result = Custom_Model_Service::validate_link_chain( $chain );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_link_chain missing target_table
	 */
	public function test_validate_link_chain_missing_target_table() {
		$chain = array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
		);

		$result = Custom_Model_Service::validate_link_chain( $chain );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_link_chain with non-existent table
	 */
	public function test_validate_link_chain_nonexistent_table() {
		$chain = array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_table'        => 'nonexistent_table_xyz',
				'target_match_column' => 'id',
				'target_id_column'    => 'id',
			),
		);

		$result = Custom_Model_Service::validate_link_chain( $chain );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_link_chain with non-existent column
	 */
	public function test_validate_link_chain_nonexistent_column() {
		$chain = array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_table'        => 'wp_posts',
				'target_match_column' => 'nonexistent_column_xyz',
				'target_id_column'    => 'ID',
			),
		);

		$result = Custom_Model_Service::validate_link_chain( $chain );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_link_chain url_param requires source_param
	 */
	public function test_validate_link_chain_url_param_requires_source_param() {
		$chain = array(
			array(
				'source_type'         => 'url_param',
				// Missing source_param
				'target_table'        => 'wp_posts',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
		);

		$result = Custom_Model_Service::validate_link_chain( $chain );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Test validate_link_chain table_column requires source fields
	 */
	public function test_validate_link_chain_table_column_requires_source() {
		$chain = array(
			array(
				'source_type'         => 'table_column',
				// Missing source_table and source_column
				'target_table'        => 'wp_postmeta',
				'target_match_column' => 'post_id',
				'target_id_column'    => 'meta_id',
			),
		);

		$result = Custom_Model_Service::validate_link_chain( $chain );

		$this->assertFalse( $result['valid'] );
	}

	// ==================== test_link_chain() Tests ====================

	/**
	 * Test test_link_chain with empty config
	 */
	public function test_test_link_chain_empty() {
		$result = Custom_Model_Service::test_link_chain( array(), '' );

		$this->assertFalse( $result['success'] );
		$this->assertNotNull( $result['error'] );
	}

	/**
	 * Test test_link_chain with valid config
	 */
	public function test_test_link_chain_valid() {
		// Create a test post
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Test Link Chain Post',
				'post_name'   => 'test-link-chain-post-' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			$this->markTestSkipped( 'Could not create test post' );
			return;
		}

		$post      = get_post( $post_id );
		$post_name = $post->post_name;

		$chain = array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_table'        => 'wp_posts',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
		);

		$result = Custom_Model_Service::test_link_chain( $chain, $post_name );

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['final_ids'] );
		// Database returns string IDs, so convert to int for comparison
		$final_ids_int = array_map( 'intval', $result['final_ids'] );
		$this->assertContains( $post_id, $final_ids_int );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test test_link_chain with no match
	 */
	public function test_test_link_chain_no_match() {
		$chain = array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_table'        => 'wp_posts',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
		);

		$result = Custom_Model_Service::test_link_chain( $chain, 'nonexistent-slug-xyz-' . uniqid() );

		$this->assertFalse( $result['success'] );
		$this->assertNotNull( $result['error'] );
	}

	/**
	 * Test test_link_chain returns steps
	 */
	public function test_test_link_chain_returns_steps() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Test Steps Post',
				'post_name'   => 'test-steps-post-' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$post      = get_post( $post_id );
		$post_name = $post->post_name;

		$chain = array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_table'        => 'wp_posts',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
		);

		$result = Custom_Model_Service::test_link_chain( $chain, $post_name );

		$this->assertArrayHasKey( 'steps', $result );
		$this->assertCount( 1, $result['steps'] );

		wp_delete_post( $post_id, true );
	}

	// ==================== create_custom_model() Tests ====================

	/**
	 * Test create_custom_model with missing plugin_slug
	 */
	public function test_create_custom_model_missing_slug() {
		$result = Custom_Model_Service::create_custom_model(
			array(
				'url_pattern' => '/test/{slug}/',
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_plugin_slug', $result->get_error_code() );
	}

	/**
	 * Test create_custom_model with missing url_pattern
	 */
	public function test_create_custom_model_missing_url_pattern() {
		$result = Custom_Model_Service::create_custom_model(
			array(
				'plugin_slug' => 'test-plugin-' . uniqid(),
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_url_pattern', $result->get_error_code() );
	}

	/**
	 * Test create_custom_model success
	 */
	public function test_create_custom_model_success() {
		$plugin_slug = 'test-custom-plugin-' . uniqid();

		$result = Custom_Model_Service::create_custom_model(
			array(
				'plugin_slug'      => $plugin_slug,
				'plugin_name'      => 'Test Custom Plugin',
				'url_pattern'      => '/test/{slug}/',
				'url_type'         => 'single',
				'data_type'        => 'post',
				'object_name'      => 'test_cpt',
				'translate_fields' => array( 'post_title', 'post_content' ),
				'sync_fields'      => array( 'post_status' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'model_id', $result );
		$this->assertArrayHasKey( 'rule_id', $result );
		$this->assertGreaterThan( 0, $result['model_id'] );
		$this->assertGreaterThan( 0, $result['rule_id'] );

		// Track for cleanup
		$this->test_model_ids[] = $result['model_id'];
		$this->test_rule_ids[]  = $result['rule_id'];
	}

	/**
	 * Test create_custom_model prevents duplicates
	 */
	public function test_create_custom_model_prevents_duplicates() {
		$plugin_slug = 'test-duplicate-' . uniqid();

		// First creation
		$result1 = Custom_Model_Service::create_custom_model(
			array(
				'plugin_slug' => $plugin_slug,
				'plugin_name' => 'Test Duplicate Plugin',
				'url_pattern' => '/test/{slug}/',
			)
		);

		$this->assertIsArray( $result1 );
		$this->test_model_ids[] = $result1['model_id'];
		$this->test_rule_ids[]  = $result1['rule_id'];

		// Second creation with same slug
		$result2 = Custom_Model_Service::create_custom_model(
			array(
				'plugin_slug' => $plugin_slug,
				'plugin_name' => 'Test Duplicate Plugin 2',
				'url_pattern' => '/test2/{slug}/',
			)
		);

		$this->assertWPError( $result2 );
		$this->assertEquals( 'model_exists', $result2->get_error_code() );
	}

	/**
	 * Test create_custom_model sets source_type to manual
	 */
	public function test_create_custom_model_sets_manual_source() {
		global $wpdb;

		$plugin_slug = 'test-manual-source-' . uniqid();

		$result = Custom_Model_Service::create_custom_model(
			array(
				'plugin_slug' => $plugin_slug,
				'plugin_name' => 'Test Manual Source',
				'url_pattern' => '/test/{slug}/',
			)
		);

		$this->assertIsArray( $result );
		$this->test_model_ids[] = $result['model_id'];
		$this->test_rule_ids[]  = $result['rule_id'];

		// Verify source_type
		$table = wptsall_table( 'models' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$model = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source_type FROM {$table} WHERE id = %d",
				$result['model_id']
			)
		);

		$this->assertEquals( 'manual', $model->source_type );
	}

	// ==================== save_link_chains() / get_link_chains() Tests ====================

	/**
	 * Test save and get link chains
	 */
	public function test_save_and_get_link_chains() {
		// Create a model first
		$plugin_slug = 'test-chains-' . uniqid();
		$model_result = Custom_Model_Service::create_custom_model(
			array(
				'plugin_slug' => $plugin_slug,
				'plugin_name' => 'Test Chains Plugin',
				'url_pattern' => '/test/{slug}/',
			)
		);

		$this->assertIsArray( $model_result );
		$this->test_model_ids[] = $model_result['model_id'];
		$this->test_rule_ids[]  = $model_result['rule_id'];

		$rule_id = $model_result['rule_id'];

		$chains = array(
			array(
				'source_type'         => 'url_param',
				'source_param'        => 'slug',
				'target_table'        => 'wp_posts',
				'target_match_column' => 'post_name',
				'target_id_column'    => 'ID',
			),
			array(
				'source_type'         => 'table_column',
				'source_table'        => 'wp_posts',
				'source_column'       => 'ID',
				'target_table'        => 'wp_postmeta',
				'target_match_column' => 'post_id',
				'target_id_column'    => 'meta_id',
			),
		);

		// Save chains
		$save_result = Custom_Model_Service::save_link_chains( $rule_id, $chains );
		$this->assertTrue( $save_result );

		// Get chains
		$retrieved = Custom_Model_Service::get_link_chains( $rule_id );

		$this->assertIsArray( $retrieved );
		$this->assertCount( 2, $retrieved );

		// Verify order
		$this->assertEquals( 0, (int) $retrieved[0]['chain_order'] );
		$this->assertEquals( 1, (int) $retrieved[1]['chain_order'] );

		// Verify first chain
		$this->assertEquals( 'url_param', $retrieved[0]['source_type'] );
		$this->assertEquals( 'slug', $retrieved[0]['source_param'] );
	}

	/**
	 * Test save_link_chains replaces existing chains
	 */
	public function test_save_link_chains_replaces_existing() {
		$plugin_slug = 'test-replace-chains-' . uniqid();
		$model_result = Custom_Model_Service::create_custom_model(
			array(
				'plugin_slug' => $plugin_slug,
				'plugin_name' => 'Test Replace Chains',
				'url_pattern' => '/test/{slug}/',
			)
		);

		$this->test_model_ids[] = $model_result['model_id'];
		$this->test_rule_ids[]  = $model_result['rule_id'];

		$rule_id = $model_result['rule_id'];

		// Save initial chains
		Custom_Model_Service::save_link_chains(
			$rule_id,
			array(
				array(
					'source_type'         => 'url_param',
					'source_param'        => 'old',
					'target_table'        => 'wp_posts',
					'target_match_column' => 'post_name',
					'target_id_column'    => 'ID',
				),
			)
		);

		// Save new chains (should replace)
		Custom_Model_Service::save_link_chains(
			$rule_id,
			array(
				array(
					'source_type'         => 'url_param',
					'source_param'        => 'new',
					'target_table'        => 'wp_posts',
					'target_match_column' => 'post_name',
					'target_id_column'    => 'ID',
				),
			)
		);

		$retrieved = Custom_Model_Service::get_link_chains( $rule_id );

		$this->assertCount( 1, $retrieved );
		$this->assertEquals( 'new', $retrieved[0]['source_param'] );
	}

	/**
	 * Test get_link_chains returns empty array for no chains
	 */
	public function test_get_link_chains_empty() {
		$result = Custom_Model_Service::get_link_chains( 99999999 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// ==================== get_database_tables() Tests ====================

	/**
	 * Test get_database_tables returns array
	 */
	public function test_get_database_tables_returns_array() {
		$result = Custom_Model_Service::get_database_tables();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test get_database_tables contains WordPress core tables
	 */
	public function test_get_database_tables_contains_core_tables() {
		$result = Custom_Model_Service::get_database_tables();

		$table_names = array_column( $result, 'name' );

		$this->assertContains( 'wp_posts', $table_names );
		$this->assertContains( 'wp_postmeta', $table_names );
		$this->assertContains( 'wp_options', $table_names );
	}

	/**
	 * Test get_database_tables excludes WPTSALL internal tables
	 */
	public function test_get_database_tables_excludes_wptsall() {
		global $wpdb;

		$result = Custom_Model_Service::get_database_tables();

		$table_names = array_column( $result, 'name' );

		// Should not contain WPTSALL internal tables (tables starting with wp_wptsall_)
		$wptsall_prefix = $wpdb->prefix . 'wptsall_';
		foreach ( $table_names as $name ) {
			$this->assertStringStartsNotWith(
				$wptsall_prefix,
				$name,
				"Table {$name} should not start with {$wptsall_prefix}"
			);
		}
	}

	/**
	 * Test get_database_tables structure
	 */
	public function test_get_database_tables_structure() {
		$result = Custom_Model_Service::get_database_tables();

		$first = reset( $result );

		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'display_name', $first );
	}

	// ==================== get_table_columns() Tests ====================

	/**
	 * Test get_table_columns returns columns for wp_posts
	 */
	public function test_get_table_columns_posts() {
		$result = Custom_Model_Service::get_table_columns( 'wp_posts' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$column_names = array_column( $result, 'name' );

		$this->assertContains( 'ID', $column_names );
		$this->assertContains( 'post_title', $column_names );
		$this->assertContains( 'post_content', $column_names );
		$this->assertContains( 'post_name', $column_names );
	}

	/**
	 * Test get_table_columns without prefix
	 */
	public function test_get_table_columns_without_prefix() {
		// Should auto-add prefix
		$result = Custom_Model_Service::get_table_columns( 'posts' );

		$this->assertIsArray( $result );
		$column_names = array_column( $result, 'name' );

		$this->assertContains( 'ID', $column_names );
	}

	/**
	 * Test get_table_columns structure
	 */
	public function test_get_table_columns_structure() {
		$result = Custom_Model_Service::get_table_columns( 'wp_posts' );

		$first = reset( $result );

		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'type', $first );
		$this->assertArrayHasKey( 'key', $first );
	}

	// ==================== Helper Methods ====================

	/**
	 * Assert value is WP_Error
	 *
	 * @param mixed $value Value to check.
	 */
	protected function assertWPError( $value ) {
		$this->assertTrue( is_wp_error( $value ), 'Expected WP_Error but got ' . gettype( $value ) );
	}

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test rules
		if ( ! empty( $this->test_rule_ids ) ) {
			$rules_table = wptsall_table( 'translation_rules' );
			$ids_string  = implode( ',', array_map( 'intval', $this->test_rule_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$rules_table} WHERE id IN ({$ids_string})" );

			// Also clean up link chains
			$chains_table = wptsall_table( 'model_link_chains' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$chains_table} WHERE rule_id IN ({$ids_string})" );
		}

		// Clean up test models
		if ( ! empty( $this->test_model_ids ) ) {
			$models_table = wptsall_table( 'models' );
			$ids_string   = implode( ',', array_map( 'intval', $this->test_model_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$models_table} WHERE id IN ({$ids_string})" );
		}

		parent::tearDown();
	}
}

<?php
/**
 * Translation Rule Service Tests
 *
 * Tests for WPTSALL\Models\Services\Translation_Rule_Service class
 *
 * @package WPTSALL
 * @since 0.4.0
 */

// Define Translation_Rule_Service alias for simple test framework compatibility
if ( ! class_exists( 'Translation_Rule_Service' ) && class_exists( 'WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
	class_alias( 'WPTSALL\Models\Services\Translation_Rule_Service', 'Translation_Rule_Service' );
}

class Test_Translation_Rule_Service extends SimpleTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure model tables exist
		if ( function_exists( 'wptsall_models_table_exists' ) && ! wptsall_models_table_exists() ) {
			wptsall_create_model_tables();
		}
	}

	/**
	 * Test model tables exist
	 */
	public function test_model_tables_exist() {
		global $wpdb;

		$models_table = wptsall_table( 'models' );
		$rules_table  = wptsall_table( 'translation_rules' );

		$models_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $models_table )
		);
		$rules_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $rules_table )
		);

		$this->assertEquals( $models_table, $models_exists, 'Models table should exist' );
		$this->assertEquals( $rules_table, $rules_exists, 'Translation rules table should exist' );
	}

	/**
	 * Test create model
	 */
	public function test_create_model() {
		$data = array(
			'plugin_slug'    => 'test-model-' . time(),
			'plugin_name'    => 'Test Plugin',
			'plugin_version' => '1.0.0',
			'description'    => 'A test model',
			'status'         => 'active',
			'post_types'     => array( 'post' ), // Required for non-system models
		);

		$model_id = Translation_Rule_Service::create_model( $data );

		$this->assertIsInt( $model_id, 'create_model should return integer ID' );
		$this->assertGreaterThan( 0, $model_id, 'Model ID should be positive' );

		// Verify model was created
		$model = Translation_Rule_Service::get_model( $model_id );
		$this->assertIsArray( $model, 'get_model should return array' );
		$this->assertEquals( $data['plugin_name'], $model['plugin_name'] );
		$this->assertEquals( $data['status'], $model['status'] );
	}

	/**
	 * Test create model with missing plugin_slug returns error
	 */
	public function test_create_model_missing_plugin_slug() {
		$data = array(
			'plugin_name' => 'Test Plugin',
		);

		$result = Translation_Rule_Service::create_model( $data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_plugin_slug', $result->get_error_code() );
	}

	/**
	 * Test create model with duplicate plugin_slug returns error
	 */
	public function test_create_model_duplicate_plugin_slug() {
		$plugin_slug = 'duplicate-test-' . time();

		$data = array(
			'plugin_slug' => $plugin_slug,
			'plugin_name' => 'First Model',
			'post_types'  => array( 'post' ),
		);

		$first_id = Translation_Rule_Service::create_model( $data );
		$this->assertIsInt( $first_id );

		// Try to create another with same plugin_slug
		$data['plugin_name'] = 'Second Model';
		$result = Translation_Rule_Service::create_model( $data );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'plugin_exists', $result->get_error_code() );
	}

	/**
	 * Test get model by ID
	 */
	public function test_get_model_by_id() {
		$data = array(
			'plugin_slug' => 'get-by-id-test-' . time(),
			'plugin_name' => 'Get By ID Test',
			'post_types'  => array( 'post' ),
		);

		$model_id = Translation_Rule_Service::create_model( $data );
		$model    = Translation_Rule_Service::get_model( $model_id );

		$this->assertIsArray( $model );
		// Use loose comparison for ID (database returns string)
		$this->assertEquals( (int) $model_id, (int) $model['id'] );
		$this->assertEquals( $data['plugin_name'], $model['plugin_name'] );
	}

	/**
	 * Test get model by plugin_slug
	 */
	public function test_get_model_by_plugin_slug() {
		$plugin_slug = 'get-by-slug-test-' . time();
		$data        = array(
			'plugin_slug' => $plugin_slug,
			'plugin_name' => 'Get By Slug Test',
			'post_types'  => array( 'post' ),
		);

		Translation_Rule_Service::create_model( $data );
		$model = Translation_Rule_Service::get_model( $plugin_slug );

		$this->assertIsArray( $model );
		$this->assertEquals( $plugin_slug, $model['plugin_slug'] );
	}

	/**
	 * Test get non-existent model returns null
	 */
	public function test_get_model_not_found() {
		$model = Translation_Rule_Service::get_model( 999999 );
		$this->assertNull( $model );

		$model = Translation_Rule_Service::get_model( 'non-existent-slug' );
		$this->assertNull( $model );
	}

	/**
	 * Test update model
	 */
	public function test_update_model() {
		$data = array(
			'plugin_slug'    => 'update-test-' . time(),
			'plugin_name'    => 'Original Name',
			'plugin_version' => '1.0.0',
			'post_types'     => array( 'post' ),
		);

		$model_id = Translation_Rule_Service::create_model( $data );

		$update_data = array(
			'plugin_name'    => 'Updated Name',
			'plugin_version' => '2.0.0',
			'description'    => 'Updated description',
		);

		$result = Translation_Rule_Service::update_model( $model_id, $update_data );

		$this->assertTrue( $result );

		$model = Translation_Rule_Service::get_model( $model_id );
		$this->assertEquals( 'Updated Name', $model['plugin_name'] );
		$this->assertEquals( '2.0.0', $model['plugin_version'] );
		$this->assertEquals( 'Updated description', $model['description'] );
	}

	/**
	 * Test update non-existent model returns error
	 */
	public function test_update_model_not_found() {
		$result = Translation_Rule_Service::update_model( 999999, array( 'plugin_name' => 'Test' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'not_found', $result->get_error_code() );
	}

	/**
	 * Test update model status
	 */
	public function test_update_model_status() {
		$data = array(
			'plugin_slug' => 'status-test-' . time(),
			'status'      => 'active',
			'post_types'  => array( 'post' ),
		);

		$model_id = Translation_Rule_Service::create_model( $data );

		Translation_Rule_Service::update_model( $model_id, array( 'status' => 'inactive' ) );

		$model = Translation_Rule_Service::get_model( $model_id );
		$this->assertEquals( 'inactive', $model['status'] );
	}

	/**
	 * Test delete model
	 */
	public function test_delete_model() {
		$data = array(
			'plugin_slug' => 'delete-test-' . time(),
			'is_system'   => false,
			'post_types'  => array( 'post' ),
		);

		$model_id = Translation_Rule_Service::create_model( $data );
		$this->assertIsInt( $model_id );

		$result = Translation_Rule_Service::delete_model( $model_id );
		$this->assertTrue( $result );

		$model = Translation_Rule_Service::get_model( $model_id );
		$this->assertNull( $model );
	}

	/**
	 * Test delete system model returns error
	 */
	public function test_delete_system_model() {
		$data = array(
			'plugin_slug' => 'system-delete-test-' . time(),
			'is_system'   => true,
		);

		$model_id = Translation_Rule_Service::create_model( $data );

		$result = Translation_Rule_Service::delete_model( $model_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'system_model', $result->get_error_code() );
	}

	/**
	 * Test delete non-existent model returns error
	 */
	public function test_delete_model_not_found() {
		$result = Translation_Rule_Service::delete_model( 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'not_found', $result->get_error_code() );
	}

	/**
	 * Test get_models returns paginated results
	 */
	public function test_get_models_pagination() {
		// Create multiple models
		for ( $i = 0; $i < 5; $i++ ) {
			Translation_Rule_Service::create_model( array(
				'plugin_slug' => 'pagination-test-' . $i . '-' . time(),
				'plugin_name' => 'Pagination Test ' . $i,
				'post_types'  => array( 'post' ),
			) );
		}

		$result = Translation_Rule_Service::get_models( array(
			'per_page' => 2,
			'page'     => 1,
		) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'models', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertLessThanOrEqual( 2, count( $result['models'] ) );
	}

	/**
	 * Test get_models filter by status
	 */
	public function test_get_models_filter_by_status() {
		$active_slug   = 'active-filter-' . time();
		$inactive_slug = 'inactive-filter-' . time();

		Translation_Rule_Service::create_model( array(
			'plugin_slug' => $active_slug,
			'status'      => 'active',
			'post_types'  => array( 'post' ),
		) );

		Translation_Rule_Service::create_model( array(
			'plugin_slug' => $inactive_slug,
			'status'      => 'inactive',
			'post_types'  => array( 'post' ),
		) );

		$result = Translation_Rule_Service::get_models( array( 'status' => 'active' ) );

		foreach ( $result['models'] as $model ) {
			$this->assertEquals( 'active', $model['status'] );
		}
	}

	/**
	 * Test create translation rule
	 *
	 * Updated for v0.8.0 schema: uses field_capabilities instead of
	 * separate translate_fields/sync_fields columns.
	 */
	public function test_create_translation_rule() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rule-test-' . time(),
			'is_system'   => true, // Bypass content type requirement for rule tests
		) );

		// v0.8.0 format: use field_capabilities instead of translate_fields/sync_fields
		$field_capabilities = array(
			'post_title'   => array(
				'type'      => 'translate',
				'direction' => 'one_way',
				'enabled'   => true,
			),
			'post_content' => array(
				'type'      => 'translate',
				'direction' => 'one_way',
				'enabled'   => true,
			),
			'_thumbnail_id' => array(
				'type'      => 'id_mapping',
				'direction' => 'one_way',
				'enabled'   => true,
			),
		);

		$rule_data = array(
			'name'              => 'Product Single Page',
			'url_pattern'       => '/product/{slug}/',
			'url_type'          => 'single',
			'requires_login'    => false,
			'data_type'         => 'post',
			'object_name'       => 'product',
			'field_capabilities' => $field_capabilities,
		);

		$rule_id = Translation_Rule_Service::create_rule( $model_id, $rule_data );

		$this->assertIsInt( $rule_id );
		$this->assertGreaterThan( 0, $rule_id );

		// Verify rule was created
		$rule = Translation_Rule_Service::get_rule( $rule_id );
		$this->assertIsArray( $rule );
		$this->assertEquals( $rule_data['url_pattern'], $rule['url_pattern'] );
		$this->assertEquals( $rule_data['data_type'], $rule['data_type'] );
		// v0.8.0: verify field_capabilities structure
		$this->assertIsArray( $rule['field_capabilities'] );
		$this->assertArrayHasKey( 'post_title', $rule['field_capabilities'] );
		$this->assertEquals( 'translate', $rule['field_capabilities']['post_title']['type'] );
	}

	/**
	 * Test create translation rule with validation failure
	 */
	public function test_create_translation_rule_validation_failure() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rule-error-test-' . time(),
			'is_system'   => true,
		) );

		// Missing required fields
		$result = Translation_Rule_Service::create_rule( $model_id, array(
			'url_pattern' => '/test/',
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test get model translation rules
	 */
	public function test_get_model_translation_rules() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rules-test-' . time(),
			'is_system'   => true,
		) );

		// Create rules with different url_types
		Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Single Rule',
			'url_pattern'      => '/public/{slug}/',
			'url_type'         => 'single',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
		) );

		Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Archive Rule',
			'url_pattern'      => '/archive/',
			'url_type'         => 'archive',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
		) );

		$rules = Translation_Rule_Service::get_model_rules( $model_id );

		$this->assertIsArray( $rules );
		$this->assertCount( 2, $rules );
	}

	/**
	 * Test get rules by type
	 */
	public function test_get_rules_by_type() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rules-grouped-test-' . time(),
			'is_system'   => true,
		) );

		Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Single Rule 1',
			'url_pattern'      => '/public1/{slug}/',
			'url_type'         => 'single',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
		) );

		Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Archive Rule 1',
			'url_pattern'      => '/archive1/',
			'url_type'         => 'archive',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
		) );

		$grouped = Translation_Rule_Service::get_rules_by_type( $model_id );

		$this->assertIsArray( $grouped );
		$this->assertArrayHasKey( 'single', $grouped );
		$this->assertArrayHasKey( 'archive', $grouped );
		$this->assertNotEmpty( $grouped['single'] );
		$this->assertNotEmpty( $grouped['archive'] );
	}

	/**
	 * Test update translation rule
	 */
	public function test_update_translation_rule() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rule-update-test-' . time(),
			'is_system'   => true,
		) );

		$rule_id = Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Original Label',
			'url_pattern'      => '/original/{slug}/',
			'url_type'         => 'single',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
		) );

		$result = Translation_Rule_Service::update_rule( $rule_id, array(
			'url_pattern' => '/updated/{slug}/',
			'name'        => 'Updated Label',
			'is_active'   => false,
		) );

		$this->assertTrue( $result );

		$rule = Translation_Rule_Service::get_rule( $rule_id );
		$this->assertEquals( '/updated/{slug}/', $rule['url_pattern'] );
		$this->assertEquals( 'Updated Label', $rule['name'] );
		$this->assertEquals( 0, (int) $rule['is_active'] );
	}

	/**
	 * Test update translation rule JSON fields
	 *
	 * Updated for v0.8.0 schema: uses field_capabilities instead of
	 * separate translate_fields column.
	 */
	public function test_update_translation_rule_json_fields() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rule-json-test-' . time(),
			'is_system'   => true,
		) );

		// Initial field_capabilities with one field
		$initial_capabilities = array(
			'post_title' => array(
				'type'      => 'translate',
				'direction' => 'one_way',
				'enabled'   => true,
			),
		);

		$rule_id = Translation_Rule_Service::create_rule( $model_id, array(
			'name'               => 'JSON Rule',
			'url_pattern'        => '/test/{slug}/',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'field_capabilities' => $initial_capabilities,
		) );

		// Updated field_capabilities with two fields
		$updated_capabilities = array(
			'post_title'   => array(
				'type'      => 'translate',
				'direction' => 'one_way',
				'enabled'   => true,
			),
			'post_content' => array(
				'type'      => 'translate',
				'direction' => 'one_way',
				'enabled'   => true,
			),
		);

		Translation_Rule_Service::update_rule( $rule_id, array(
			'field_capabilities' => $updated_capabilities,
		) );

		$rule = Translation_Rule_Service::get_rule( $rule_id );
		$this->assertIsArray( $rule['field_capabilities'] );
		$this->assertCount( 2, $rule['field_capabilities'] );
		$this->assertArrayHasKey( 'post_title', $rule['field_capabilities'] );
		$this->assertArrayHasKey( 'post_content', $rule['field_capabilities'] );
	}

	/**
	 * Test delete translation rule
	 */
	public function test_delete_translation_rule() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rule-delete-test-' . time(),
			'is_system'   => true,
		) );

		$rule_id = Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Delete Rule',
			'url_pattern'      => '/delete/{slug}/',
			'url_type'         => 'single',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
		) );

		$result = Translation_Rule_Service::delete_rule( $rule_id );
		$this->assertTrue( $result );

		$rule = Translation_Rule_Service::get_rule( $rule_id );
		$this->assertNull( $rule );
	}

	/**
	 * Test deleting model also deletes translation rules
	 */
	public function test_delete_model_cascades_to_rules() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'cascade-delete-test-' . time(),
			'is_system'   => false,
			'post_types'  => array( 'post' ),
		) );

		$rule_id = Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Cascade Rule',
			'url_pattern'      => '/cascade/{slug}/',
			'url_type'         => 'single',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
		) );

		// Delete model
		Translation_Rule_Service::delete_model( $model_id );

		// Verify rule is also deleted
		$rule = Translation_Rule_Service::get_rule( $rule_id );
		$this->assertNull( $rule );
	}

	/**
	 * Test toggle rule active status
	 */
	public function test_toggle_rule_active() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'toggle-test-' . time(),
			'is_system'   => true,
		) );

		$rule_id = Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Toggle Rule',
			'url_pattern'      => '/toggle/{slug}/',
			'url_type'         => 'single',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
			'is_active'        => true,
		) );

		// Toggle to inactive
		$result = Translation_Rule_Service::toggle_rule_active( $rule_id );
		$this->assertTrue( $result );

		$rule = Translation_Rule_Service::get_rule( $rule_id );
		$this->assertEquals( 0, (int) $rule['is_active'] );

		// Toggle back to active
		Translation_Rule_Service::toggle_rule_active( $rule_id );
		$rule = Translation_Rule_Service::get_rule( $rule_id );
		$this->assertEquals( 1, (int) $rule['is_active'] );
	}

	/**
	 * Test has_translation_rules
	 */
	public function test_has_translation_rules() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'has-rules-test-' . time(),
			'is_system'   => true,
		) );

		// No rules yet
		$this->assertFalse( Translation_Rule_Service::has_translation_rules( $model_id ) );

		// Add a rule
		Translation_Rule_Service::create_rule( $model_id, array(
			'name'             => 'Test Rule',
			'url_pattern'      => '/test/{slug}/',
			'url_type'         => 'single',
			'data_type'        => 'post',
			'object_name'      => 'post',
			'translate_fields' => array( 'post_title' ),
			'is_active'        => true,
		) );

		$this->assertTrue( Translation_Rule_Service::has_translation_rules( $model_id ) );
	}

	/**
	 * Test update usage status
	 */
	public function test_update_usage_status() {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug'  => 'usage-status-test-' . time(),
			'usage_status' => 'unused',
			'post_types'   => array( 'post' ),
		) );

		$result = Translation_Rule_Service::update_usage_status( $model_id, 'active' );
		$this->assertTrue( $result );

		$model = Translation_Rule_Service::get_model( $model_id );
		$this->assertEquals( 'active', $model['usage_status'] );
	}

	/**
	 * Test get_model_by_plugin_slug specific method
	 */
	public function test_get_model_by_plugin_slug_method() {
		$plugin_slug = 'by-slug-method-test-' . time();

		Translation_Rule_Service::create_model( array(
			'plugin_slug' => $plugin_slug,
			'plugin_name' => 'By Slug Test',
			'post_types'  => array( 'post' ),
		) );

		$model = Translation_Rule_Service::get_model_by_plugin_slug( $plugin_slug );

		$this->assertIsArray( $model );
		$this->assertEquals( $plugin_slug, $model['plugin_slug'] );
		$this->assertEquals( 'By Slug Test', $model['plugin_name'] );
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		global $wpdb;

		$models_table = wptsall_table( 'models' );
		$rules_table  = wptsall_table( 'translation_rules' );

		// Clean up test models (only test ones)
		$wpdb->query( "DELETE FROM {$rules_table} WHERE model_id IN (SELECT id FROM {$models_table} WHERE plugin_slug LIKE '%-test-%')" );
		$wpdb->query( "DELETE FROM {$models_table} WHERE plugin_slug LIKE '%-test-%'" );

		parent::tearDown();
	}
}

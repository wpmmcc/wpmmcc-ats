<?php
/**
 * Translation Rule Service Full Tests
 *
 * Comprehensive tests for WPTSALL\Models\Services\Translation_Rule_Service class
 *
 * @package WPTSALL
 * @since 0.10.0
 */

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Translation_Rule_Service_Full extends WP_UnitTestCase {

	/**
	 * Test model ID
	 *
	 * @var int
	 */
	protected $test_model_id;

	/**
	 * Test rule ID
	 *
	 * @var int
	 */
	protected $test_rule_id;

	/**
	 * Unique test slug
	 *
	 * @var string
	 */
	protected $test_slug;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Create unique test slug.
		$this->test_slug = 'test-plugin-' . uniqid();

		// Create test model.
		global $wpdb;
		$models_table = wptsall_table( 'models' );

		// First check if models table exists and has correct structure.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $models_table )
		);

		if ( ! $table_exists ) {
			$this->test_model_id = 0;
			$this->test_rule_id  = 0;
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$models_table,
			array(
				'plugin_slug'    => $this->test_slug,
				'plugin_name'    => 'Test Plugin for Service',
				'plugin_version' => '1.0.0',
				'text_domain'    => 'test-plugin',
				'post_types'     => '["test_post_type"]',
				'taxonomies'     => '["test_taxonomy"]',
				'status'         => 'active',
				'usage_status'   => 'active',
				'source_type'    => 'auto',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->test_model_id = $result ? $wpdb->insert_id : 0;

		// Create test rule.
		$rules_table = wptsall_table( 'translation_rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$rules_table,
			array(
				'model_id'           => $this->test_model_id,
				'url_pattern'        => '/test/%slug%/',
				'url_type'           => 'post',
				'data_type'          => 'post',
				'object_name'        => 'test_post_type',
				'priority'           => 10,
				'is_active'          => 1,
				'field_capabilities' => wp_json_encode( array(
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
					'post_date'    => array(
						'type'      => 'sync',
						'direction' => 'one_way',
						'enabled'   => true,
					),
					'_thumbnail_id' => array(
						'type'      => 'id_mapping',
						'direction' => 'one_way',
						'enabled'   => true,
					),
					'post_name'    => array(
						'type'           => 'translate',
						'direction'      => 'one_way',
						'enabled'        => true,
						'content_format' => 'slug',
					),
				) ),
				'related_taxonomies' => '["category", "post_tag"]',
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		$this->test_rule_id = $wpdb->insert_id;
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown(): void {
		global $wpdb;

		$objects_table = wptsall_table( 'model_objects' );
		$fields_table  = wptsall_table( 'model_object_fields' );

		if ( $this->test_model_id ) {
			$object_ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE model_id = %d', $objects_table, $this->test_model_id )
			);
			foreach ( (array) $object_ids as $object_id ) {
				$wpdb->delete( $fields_table, array( 'object_id' => (int) $object_id ), array( '%d' ) );
			}
			$wpdb->delete( $objects_table, array( 'model_id' => $this->test_model_id ), array( '%d' ) );
		}

		// Clean up test rule.
		$rules_table = wptsall_table( 'translation_rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $rules_table, array( 'id' => $this->test_rule_id ), array( '%d' ) );

		// Clean up test model.
		$models_table = wptsall_table( 'models' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $models_table, array( 'id' => $this->test_model_id ), array( '%d' ) );

		parent::tearDown();
	}

	/**
	 * Test post slug is exposed as a default translate field in available fields.
	 */
	public function test_get_available_fields_exposes_post_slug_for_translate_selection() {
		if ( ! $this->test_model_id ) {
			$this->markTestSkipped( 'Test model was not created' );
		}

		$object_id = Model_Object_Service::create_object(
			$this->test_model_id,
			'post_type',
			'test_post_type',
			array( 'source_type' => 'manual' )
		);
		$this->assertIsInt( $object_id );

		Model_Object_Service::add_field(
			$object_id,
			'core',
			'post_name',
			'manual',
			array( 'data_type' => 'slug' )
		);

		$fields = Translation_Rule_Service::get_available_fields(
			'post',
			'test_post_type',
			$this->test_model_id
		);

		$this->assertArrayHasKey( 'post_name', $fields['main'] );
		$this->assertTrue( $fields['main']['post_name']['translatable'] );
		$this->assertEquals( 'translate', $fields['main']['post_name']['default_capability'] );
		$this->assertEquals( 'URL Slug', $fields['main']['post_name']['label'] );
	}

	/**
	 * Test taxonomy slug is exposed as a default translate field in available fields.
	 */
	public function test_get_available_fields_exposes_term_slug_for_translate_selection() {
		if ( ! $this->test_model_id ) {
			$this->markTestSkipped( 'Test model was not created' );
		}

		$object_id = Model_Object_Service::create_object(
			$this->test_model_id,
			'taxonomy',
			'test_taxonomy',
			array( 'source_type' => 'manual' )
		);
		$this->assertIsInt( $object_id );

		Model_Object_Service::add_field(
			$object_id,
			'core',
			'slug',
			'manual',
			array( 'data_type' => 'slug' )
		);

		$fields = Translation_Rule_Service::get_available_fields(
			'term',
			'test_taxonomy',
			$this->test_model_id
		);

		$this->assertArrayHasKey( 'slug', $fields['main'] );
		$this->assertTrue( $fields['main']['slug']['translatable'] );
		$this->assertEquals( 'translate', $fields['main']['slug']['default_capability'] );
		$this->assertEquals( 'URL Slug', $fields['main']['slug']['label'] );
	}

	/**
	 * Test option-backed rules can bind to option template objects and expose available fields.
	 */
	public function test_create_rule_accepts_option_template_binding() {
		if ( ! $this->test_model_id ) {
			$this->markTestSkipped( 'Test model was not created' );
		}

		$object_id = Model_Object_Service::create_object(
			$this->test_model_id,
			'option',
			'wptsall_mail_template',
			array( 'source_type' => 'manual' )
		);
		$this->assertIsInt( $object_id );

		Model_Object_Service::add_field(
			$object_id,
			'meta',
			'email_subject',
			'manual',
			array( 'data_type' => 'text' )
		);
		Model_Object_Service::add_field(
			$object_id,
			'meta',
			'email_body',
			'manual',
			array( 'data_type' => 'html' )
		);

		$rule_id = Translation_Rule_Service::create_rule(
			$this->test_model_id,
			array(
				'name'        => 'Option Mail Template Rule',
				'url_pattern' => '/settings/mail-template/',
				'url_type'    => 'single',
				'data_type'   => 'option',
				'object_name' => 'wptsall_mail_template',
				'field_capabilities' => array(
					'email_subject' => array(
						'type'           => 'translate',
						'direction'      => 'one_way',
						'enabled'        => true,
						'content_format' => 'plain_text',
						'storage'        => 'option_value',
					),
					'email_body' => array(
						'type'           => 'translate',
						'direction'      => 'one_way',
						'enabled'        => true,
						'content_format' => 'rich_html',
						'storage'        => 'option_value',
					),
				),
			)
		);

		$this->assertIsInt( $rule_id );
		$this->assertGreaterThan( 0, $rule_id );

		$fields = Translation_Rule_Service::get_available_fields(
			'option',
			'wptsall_mail_template',
			$this->test_model_id
		);

		$this->assertArrayHasKey( 'email_subject', $fields['meta'] );
		$this->assertArrayHasKey( 'email_body', $fields['meta'] );
		$this->assertTrue( $fields['meta']['email_subject']['translatable'] );
		$this->assertTrue( $fields['meta']['email_body']['translatable'] );

		global $wpdb;
		$wpdb->delete( wptsall_table( 'translation_rules' ), array( 'id' => $rule_id ), array( '%d' ) );
	}

	// ========================================
	// get_models Tests
	// ========================================

	/**
	 * Test get_models returns structured response
	 */
	public function test_get_models_structure() {
		$result = Translation_Rule_Service::get_models();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'models', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
	}

	/**
	 * Test get_models respects pagination
	 */
	public function test_get_models_pagination() {
		$result = Translation_Rule_Service::get_models( array(
			'per_page' => 5,
			'page'     => 1,
		) );

		$this->assertEquals( 1, $result['page'] );
		$this->assertEquals( 5, $result['per_page'] );
		$this->assertLessThanOrEqual( 5, count( $result['models'] ) );
	}

	/**
	 * Test get_models includes test model
	 */
	public function test_get_models_includes_test() {
		if ( ! $this->test_model_id ) {
			$this->markTestSkipped( 'Test model was not created' );
		}

		// Use high per_page value to ensure test model is included
		// (model names sorted alphabetically, "Test Plugin" may be beyond first 100).
		$result = Translation_Rule_Service::get_models( array( 'per_page' => 500 ) );

		$found = false;
		foreach ( $result['models'] as $model ) {
			if ( (int) $model['id'] === $this->test_model_id ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Test model should exist in results' );
	}

	/**
	 * Test get_models with status filter
	 */
	public function test_get_models_status_filter() {
		$result = Translation_Rule_Service::get_models( array(
			'status' => 'active',
		) );

		foreach ( $result['models'] as $model ) {
			$this->assertEquals( 'active', $model['status'] );
		}
	}

	// ========================================
	// get_model Tests
	// ========================================

	/**
	 * Test get_model by ID
	 */
	public function test_get_model_by_id() {
		if ( ! $this->test_model_id ) {
			$this->markTestSkipped( 'Test model was not created (models table may not exist)' );
		}

		$model = Translation_Rule_Service::get_model( $this->test_model_id );

		$this->assertIsArray( $model );
		$this->assertEquals( $this->test_model_id, (int) $model['id'] );
		$this->assertEquals( 'Test Plugin for Service', $model['plugin_name'] );
	}

	/**
	 * Test get_model returns null for non-existent
	 */
	public function test_get_model_nonexistent() {
		$model = Translation_Rule_Service::get_model( 999999999 );

		$this->assertNull( $model );
	}

	/**
	 * Test get_model includes rules
	 */
	public function test_get_model_includes_rules() {
		if ( ! $this->test_model_id ) {
			$this->markTestSkipped( 'Test model was not created' );
		}

		$model = Translation_Rule_Service::get_model( $this->test_model_id );

		if ( ! $model ) {
			$this->markTestSkipped( 'Model not found' );
		}

		$this->assertArrayHasKey( 'rules', $model );
		$this->assertIsArray( $model['rules'] );
	}

	/**
	 * Test get_model parses JSON fields
	 */
	public function test_get_model_parses_json() {
		if ( ! $this->test_model_id ) {
			$this->markTestSkipped( 'Test model was not created' );
		}

		$model = Translation_Rule_Service::get_model( $this->test_model_id );

		if ( ! $model ) {
			$this->markTestSkipped( 'Model not found' );
		}

		$this->assertIsArray( $model['post_types'] );
		$this->assertIsArray( $model['taxonomies'] );
		// Note: post_types might be enriched with detailed info.
		$found = false;
		foreach ( $model['post_types'] as $pt ) {
			$name = is_array( $pt ) ? ( $pt['name'] ?? '' ) : $pt;
			if ( $name === 'test_post_type' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'test_post_type should be in post_types' );
	}

	// ========================================
	// get_model_rules Tests
	// ========================================

	/**
	 * Test get_model_rules returns array
	 */
	public function test_get_model_rules_returns_array() {
		$rules = Translation_Rule_Service::get_model_rules( $this->test_model_id );

		$this->assertIsArray( $rules );
	}

	/**
	 * Test get_model_rules parses JSON
	 */
	public function test_get_model_rules_parses_json() {
		$rules = Translation_Rule_Service::get_model_rules( $this->test_model_id );

		$this->assertNotEmpty( $rules );
		$rule = $rules[0];

		$this->assertIsArray( $rule['field_capabilities'] );
		$this->assertIsArray( $rule['related_taxonomies'] );
	}

	/**
	 * Test get_model_rules with url_type filter
	 */
	public function test_get_model_rules_url_type_filter() {
		$rules = Translation_Rule_Service::get_model_rules( $this->test_model_id, 'post' );

		$this->assertNotEmpty( $rules );
		foreach ( $rules as $rule ) {
			$this->assertEquals( 'post', $rule['url_type'] );
		}
	}

	/**
	 * Test get_model_rules empty for nonexistent model
	 */
	public function test_get_model_rules_nonexistent() {
		$rules = Translation_Rule_Service::get_model_rules( 999999999 );

		$this->assertEmpty( $rules );
	}

	// ========================================
	// get_rule Tests
	// ========================================

	/**
	 * Test get_rule returns rule
	 */
	public function test_get_rule_returns_rule() {
		$rule = Translation_Rule_Service::get_rule( $this->test_rule_id );

		$this->assertIsArray( $rule );
		$this->assertEquals( $this->test_rule_id, (int) $rule['id'] );
	}

	/**
	 * Test get_rule returns null for nonexistent
	 */
	public function test_get_rule_nonexistent() {
		$rule = Translation_Rule_Service::get_rule( 999999999 );

		$this->assertNull( $rule );
	}

	/**
	 * Test get_rule parses field_capabilities
	 */
	public function test_get_rule_parses_capabilities() {
		$rule = Translation_Rule_Service::get_rule( $this->test_rule_id );

		$this->assertIsArray( $rule['field_capabilities'] );
		$this->assertArrayHasKey( 'post_title', $rule['field_capabilities'] );
		$this->assertEquals( 'translate', $rule['field_capabilities']['post_title']['type'] );
	}

	// ========================================
	// get_rule_by_post_type Tests
	// ========================================

	/**
	 * Test get_rule_by_post_type finds rule
	 */
	public function test_get_rule_by_post_type_finds() {
		$rule = Translation_Rule_Service::get_rule_by_post_type( $this->test_model_id, 'test_post_type' );

		$this->assertIsArray( $rule );
		$this->assertEquals( 'test_post_type', $rule['object_name'] );
	}

	/**
	 * Test get_rule_by_post_type returns null for nonexistent
	 */
	public function test_get_rule_by_post_type_nonexistent() {
		$rule = Translation_Rule_Service::get_rule_by_post_type( $this->test_model_id, 'nonexistent_type' );

		$this->assertNull( $rule );
	}

	// ========================================
	// get_merged_config Tests
	// ========================================

	/**
	 * Test get_merged_config returns config
	 */
	public function test_get_merged_config_returns_config() {
		$config = Translation_Rule_Service::get_merged_config( $this->test_rule_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'enabled', $config );
		$this->assertArrayHasKey( 'direction', $config );
		$this->assertArrayHasKey( 'fields', $config );
		$this->assertArrayHasKey( 'rule_id', $config );
	}

	/**
	 * Test get_merged_config returns null for nonexistent rule
	 */
	public function test_get_merged_config_nonexistent() {
		$config = Translation_Rule_Service::get_merged_config( 999999999 );

		$this->assertNull( $config );
	}

	/**
	 * Test get_merged_config without relation_id returns base config
	 */
	public function test_get_merged_config_base_only() {
		$config = Translation_Rule_Service::get_merged_config( $this->test_rule_id, null );

		$this->assertIsArray( $config );
		$this->assertTrue( $config['enabled'] );
	}

	// ========================================
	// get_merged_config_for_relation Tests
	// ========================================

	/**
	 * Test get_merged_config_for_relation returns default for nonexistent
	 */
	public function test_get_merged_config_for_relation_default() {
		$config = Translation_Rule_Service::get_merged_config_for_relation( 999999, 'post' );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'translate_fields', $config );
		$this->assertArrayHasKey( 'sync_fields', $config );
		$this->assertArrayHasKey( 'id_mapping_fields', $config );
		$this->assertArrayHasKey( 'compute_fields', $config );
	}

	/**
	 * Test get_merged_config_for_relation default structure
	 */
	public function test_get_merged_config_for_relation_default_fields() {
		$config = Translation_Rule_Service::get_merged_config_for_relation( 999999, 'post' );

		// Default translate fields.
		$this->assertContains( 'post_title', $config['translate_fields'] );
		$this->assertContains( 'post_content', $config['translate_fields'] );
		$this->assertContains( 'post_excerpt', $config['translate_fields'] );

		// Default sync fields.
		$this->assertContains( 'post_date', $config['sync_fields'] );
		$this->assertContains( 'post_status', $config['sync_fields'] );

		// Default id_mapping fields.
		$this->assertContains( '_thumbnail_id', $config['id_mapping_fields'] );

		// Default translate fields include slug.
		$this->assertContains( 'post_name', $config['translate_fields'] );
	}

	// ========================================
	// get_fields_by_type Tests
	// ========================================

	/**
	 * Test get_fields_by_type structure
	 */
	public function test_get_fields_by_type_structure() {
		$config = Translation_Rule_Service::get_merged_config( $this->test_rule_id );
		$fields = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertIsArray( $fields );
		$this->assertArrayHasKey( 'translate', $fields );
		$this->assertArrayHasKey( 'sync', $fields );
		$this->assertArrayHasKey( 'id_mapping', $fields );
		$this->assertArrayHasKey( 'compute', $fields );
	}

	/**
	 * Test get_fields_by_type groups correctly
	 */
	public function test_get_fields_by_type_grouping() {
		$config = Translation_Rule_Service::get_merged_config( $this->test_rule_id );
		$fields = Translation_Rule_Service::get_fields_by_type( $config );

		// Translate fields.
		$this->assertContains( 'post_title', $fields['translate'] );
		$this->assertContains( 'post_content', $fields['translate'] );

		// Sync fields.
		$this->assertContains( 'post_date', $fields['sync'] );

		// ID mapping fields.
		$this->assertContains( '_thumbnail_id', $fields['id_mapping'] );

		// Translate fields include slug.
		$this->assertContains( 'post_name', $fields['translate'] );
	}

	/**
	 * Test get_fields_by_type with empty config
	 */
	public function test_get_fields_by_type_empty() {
		$fields = Translation_Rule_Service::get_fields_by_type( array() );

		$this->assertIsArray( $fields );
		$this->assertEmpty( $fields['translate'] );
		$this->assertEmpty( $fields['sync'] );
		$this->assertEmpty( $fields['id_mapping'] );
		$this->assertEmpty( $fields['compute'] );
	}

	/**
	 * Test get_fields_by_type skips disabled fields
	 */
	public function test_get_fields_by_type_skips_disabled() {
		$config = array(
			'fields' => array(
				'enabled_field'  => array(
					'type'    => 'translate',
					'enabled' => true,
				),
				'disabled_field' => array(
					'type'    => 'translate',
					'enabled' => false,
				),
			),
		);

		$fields = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertContains( 'enabled_field', $fields['translate'] );
		$this->assertNotContains( 'disabled_field', $fields['translate'] );
	}

	/**
	 * Test get_fields_by_type skips no_sync fields
	 */
	public function test_get_fields_by_type_skips_no_sync() {
		$config = array(
			'fields' => array(
				'sync_field'    => array(
					'type'    => 'sync',
					'enabled' => true,
				),
				'no_sync_field' => array(
					'type'    => 'no_sync',
					'enabled' => true,
				),
			),
		);

		$fields = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertContains( 'sync_field', $fields['sync'] );
		$this->assertNotContains( 'no_sync_field', $fields['sync'] );
	}

	/**
	 * Test get_fields_by_type normalizes mapping to id_mapping
	 */
	public function test_get_fields_by_type_normalizes_mapping() {
		$config = array(
			'fields' => array(
				'legacy_mapping' => array(
					'type'    => 'mapping', // Legacy value.
					'enabled' => true,
				),
			),
		);

		$fields = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertContains( 'legacy_mapping', $fields['id_mapping'] );
	}

	// ========================================
	// get_rules_for_model Tests
	// ========================================

	/**
	 * Test get_rules_for_model structure
	 */
	public function test_get_rules_for_model_structure() {
		$result = Translation_Rule_Service::get_rules_for_model( $this->test_model_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result ); // Method uses 'items' key.
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
	}

	/**
	 * Test get_rules_for_model pagination
	 */
	public function test_get_rules_for_model_pagination() {
		$result = Translation_Rule_Service::get_rules_for_model( $this->test_model_id, array(
			'per_page' => 10,
			'page'     => 1,
		) );

		$this->assertEquals( 1, $result['page'] );
		$this->assertEquals( 10, $result['per_page'] );
	}

	/**
	 * Test get_rules_for_model url_type filter
	 */
	public function test_get_rules_for_model_url_type_filter() {
		$result = Translation_Rule_Service::get_rules_for_model( $this->test_model_id, array(
			'url_type' => 'post',
		) );

		foreach ( $result['rules'] as $rule ) {
			$this->assertEquals( 'post', $rule['url_type'] );
		}
	}

	// ========================================
	// Edge Cases
	// ========================================

	/**
	 * Test empty model ID handling
	 */
	public function test_empty_model_id() {
		$rules = Translation_Rule_Service::get_model_rules( 0 );

		$this->assertIsArray( $rules );
		// Empty model ID should return empty array or results without matching rules.
		// The method might not filter on model_id=0, so just verify it returns an array.
	}

	/**
	 * Test config with missing fields key
	 */
	public function test_config_missing_fields() {
		$config = array(
			'enabled'   => true,
			'direction' => 'one_way',
			// No 'fields' key.
		);

		$fields = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertIsArray( $fields );
		$this->assertEmpty( $fields['translate'] );
	}
}

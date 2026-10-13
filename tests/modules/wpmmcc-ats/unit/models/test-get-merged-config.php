<?php
/**
 * Test get_merged_config in Translation_Rule_Service
 *
 * Tests the configuration merging functionality for translation rules.
 * Updated for v0.8.0+ schema that uses field_capabilities instead of
 * separate translate_fields/sync_fields/field_mappings/compute_fields.
 *
 * @package WPTSALL
 * @since 0.8.0
 */

use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Get_Merged_Config extends SimpleTestCase {

	/**
	 * Test model and rule IDs
	 *
	 * @var array
	 */
	private $test_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Helper to create a test model
	 *
	 * @return int Model ID.
	 */
	private function create_test_model( $overrides = array() ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$data = array_merge(
			array(
				'plugin_slug' => 'test-plugin-' . uniqid(),
				'plugin_name' => 'Test Plugin',
				'post_types'  => '["post"]',
				'taxonomies'  => '[]',
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			$overrides
		);

		$result = $wpdb->insert(
			wptsall_table( 'models' ),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return 0;
		}

		$model_id                   = $wpdb->insert_id;
		$this->test_ids['models'][] = $model_id;

		return $model_id;
	}

	/**
	 * Helper to create a test rule using v0.8.0 schema
	 *
	 * @param int   $model_id Model ID.
	 * @param array $overrides Override fields.
	 * @return int Rule ID.
	 */
	private function create_test_rule( $model_id, $overrides = array() ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		// Default field_capabilities (v0.8.0 format)
		$default_capabilities = array(
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
			'post_status'  => array(
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
		);

		$defaults = array(
			'model_id'           => $model_id,
			'name'               => 'Test Rule',
			'url_pattern'        => '/post/{id}',
			'url_type'           => 'single',
			'data_type'          => 'post',
			'object_name'        => 'post',
			'field_capabilities' => wp_json_encode( $default_capabilities ),
			'direction'          => 'source_to_target',
			'sync_mode'          => 'new_only',
			'is_active'          => 1,
			'created_at'         => $now,
			'updated_at'         => $now,
		);

		$data = array_merge( $defaults, $overrides );

		$result = $wpdb->insert(
			wptsall_table( 'translation_rules' ),
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return 0;
		}

		$rule_id                   = $wpdb->insert_id;
		$this->test_ids['rules'][] = $rule_id;

		return $rule_id;
	}

	/**
	 * Helper to create a relation config override
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $post_type   Post type.
	 * @param array  $config      Config data.
	 */
	private function create_relation_config( $relation_id, $post_type, $config ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$result = $wpdb->insert(
			wptsall_table( 'relation_post_type_configs' ),
			array(
				'relation_id'     => $relation_id,
				'post_type'       => $post_type,
				'enabled'         => isset( $config['enabled'] ) ? ( $config['enabled'] ? 1 : 0 ) : null,
				'direction'       => $config['direction'] ?? null,
				'sync_mode'       => $config['sync_mode'] ?? null,
				'field_overrides' => isset( $config['field_overrides'] ) ? wp_json_encode( $config['field_overrides'] ) : null,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false !== $result ) {
			$this->test_ids['configs'][] = $wpdb->insert_id;
		}
	}

	// ==================== get_merged_config() Tests ====================

	/**
	 * Test get_merged_config returns null for non-existent rule
	 */
	public function test_get_merged_config_null_for_invalid_rule() {
		$config = Translation_Rule_Service::get_merged_config( 99999 );

		$this->assertNull( $config );
	}

	/**
	 * Test get_merged_config returns base config without relation_id
	 */
	public function test_get_merged_config_base_config_only() {
		$model_id = $this->create_test_model();
		$this->assertGreaterThan( 0, $model_id, 'Model creation failed' );

		$rule_id = $this->create_test_rule( $model_id );
		$this->assertGreaterThan( 0, $rule_id, 'Rule creation failed' );

		$config = Translation_Rule_Service::get_merged_config( $rule_id );

		$this->assertIsArray( $config );
		$this->assertTrue( $config['enabled'] );
		$this->assertEquals( 'source_to_target', $config['direction'] );
		$this->assertEquals( 'new_only', $config['sync_mode'] );
		$this->assertArrayHasKey( 'fields', $config );
		$this->assertEquals( $rule_id, $config['rule_id'] );
		$this->assertEquals( $model_id, $config['model_id'] );
	}

	/**
	 * Test get_merged_config builds fields from field_capabilities
	 */
	public function test_get_merged_config_fields_structure() {
		$model_id = $this->create_test_model();
		$rule_id  = $this->create_test_rule( $model_id );

		$config = Translation_Rule_Service::get_merged_config( $rule_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'fields', $config );

		// Check translate fields
		$this->assertArrayHasKey( 'post_title', $config['fields'] );
		$this->assertEquals( 'translate', $config['fields']['post_title']['type'] );
		$this->assertTrue( $config['fields']['post_title']['enabled'] );

		// Check sync fields
		$this->assertArrayHasKey( 'post_date', $config['fields'] );
		$this->assertEquals( 'sync', $config['fields']['post_date']['type'] );

		// Check mapping fields
		$this->assertArrayHasKey( '_thumbnail_id', $config['fields'] );
		$this->assertEquals( 'id_mapping', $config['fields']['_thumbnail_id']['type'] );

		// Check default slug translation fields
		$this->assertArrayHasKey( 'post_name', $config['fields'] );
		$this->assertEquals( 'translate', $config['fields']['post_name']['type'] );
		$this->assertEquals( 'slug', $config['fields']['post_name']['content_format'] );
	}

	/**
	 * Test get_merged_config with custom field_capabilities
	 */
	public function test_get_merged_config_with_custom_capabilities() {
		$model_id = $this->create_test_model();

		$capabilities = array(
			'post_title'   => array( 'type' => 'translate', 'direction' => 'one_way', 'enabled' => true ),
			'post_content' => array( 'type' => 'translate', 'direction' => 'two_way', 'enabled' => true ),
			'_price'       => array( 'type' => 'no_sync', 'enabled' => false ),
		);

		$rule_id = $this->create_test_rule(
			$model_id,
			array( 'field_capabilities' => wp_json_encode( $capabilities ) )
		);

		$config = Translation_Rule_Service::get_merged_config( $rule_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'post_title', $config['fields'] );
		$this->assertEquals( 'translate', $config['fields']['post_title']['type'] );
		$this->assertEquals( 'one_way', $config['fields']['post_title']['direction'] );

		$this->assertArrayHasKey( '_price', $config['fields'] );
		$this->assertEquals( 'no_sync', $config['fields']['_price']['type'] );
		$this->assertFalse( $config['fields']['_price']['enabled'] );
	}

	/**
	 * Test get_merged_config applies relation override - enabled
	 */
	public function test_get_merged_config_override_enabled() {
		$model_id    = $this->create_test_model();
		$rule_id     = $this->create_test_rule( $model_id );
		$relation_id = 123; // Fake relation ID

		// Create override that disables the rule
		$this->create_relation_config(
			$relation_id,
			'post',
			array( 'enabled' => false )
		);

		$config = Translation_Rule_Service::get_merged_config( $rule_id, $relation_id );

		$this->assertIsArray( $config );
		$this->assertFalse( $config['enabled'] );
	}

	/**
	 * Test get_merged_config applies relation override - sync_mode
	 */
	public function test_get_merged_config_override_sync_mode() {
		$model_id    = $this->create_test_model();
		$rule_id     = $this->create_test_rule( $model_id, array( 'sync_mode' => 'new_only' ) );
		$relation_id = 125;

		// Create override — sync_mode is now fixed to new_only (ISS-SIT-037).
		$this->create_relation_config(
			$relation_id,
			'post',
			array( 'sync_mode' => 'new_only' )
		);

		$config = Translation_Rule_Service::get_merged_config( $rule_id, $relation_id );

		$this->assertIsArray( $config );
		$this->assertEquals( 'new_only', $config['sync_mode'] );
	}

	/**
	 * Test get_merged_config adds relation_id to config when provided
	 */
	public function test_get_merged_config_includes_relation_id() {
		$model_id    = $this->create_test_model();
		$rule_id     = $this->create_test_rule( $model_id );
		$relation_id = 127;

		// Create any override to trigger relation merge
		$this->create_relation_config(
			$relation_id,
			'post',
			array( 'sync_mode' => 'new_only' )
		);

		$config = Translation_Rule_Service::get_merged_config( $rule_id, $relation_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'relation_id', $config );
		$this->assertEquals( $relation_id, $config['relation_id'] );
	}

	/**
	 * Test get_merged_config returns base when no override exists
	 */
	public function test_get_merged_config_no_override() {
		$model_id    = $this->create_test_model();
		$rule_id     = $this->create_test_rule( $model_id );
		$relation_id = 999; // No override for this relation

		$config = Translation_Rule_Service::get_merged_config( $rule_id, $relation_id );

		$this->assertIsArray( $config );
		// Should return base config with relation_id (production code always adds
		// relation_id to final config when relation_id argument is provided).
		$this->assertEquals( 'source_to_target', $config['direction'] );
		$this->assertEquals( 'new_only', $config['sync_mode'] );
		$this->assertArrayHasKey( 'relation_id', $config );
	}

	// ============ Field Discovery overlay Tests (opus5 M-03 / D-2a) ============

	/**
	 * M-03 (D-2a): the wptsall_field_translations option is consumed by
	 * get_merged_config_for_relation as a per-post-type overlay — discovered
	 * translatable meta keys join translate_fields, already-classified and
	 * code-like keys stay out, and the overlay is scoped to post data.
	 */
	public function test_merged_config_for_relation_applies_field_discovery_overlay() {
		$option = \WPTSALL\ManualTranslation\Services\Field_Translation_Service::OPTION_KEY;
		$prev   = get_option( $option, null );

		try {
			delete_option( $option );

			$before = Translation_Rule_Service::get_merged_config_for_relation( 987654, 'post' );
			$this->assertIsArray( $before );
			$this->assertNotContains( 'wptm_overlay_new_field', $before['translate_fields'], 'baseline: empty option must not add fields' );

			\WPTSALL\ManualTranslation\Services\Field_Translation_Service::save_for_post_type( 'post', array(
				'wptm_overlay_new_field' => array( 'translatable' => true, 'sync' => false ),
				'post_title'             => array( 'translatable' => true, 'sync' => false ),
				'wptm_overlay_css'       => array( 'translatable' => true, 'sync' => false ),
				'wptm_overlay_off'       => array( 'translatable' => false, 'sync' => false ),
			) );

			$after = Translation_Rule_Service::get_merged_config_for_relation( 987654, 'post' );
			$this->assertContains( 'wptm_overlay_new_field', $after['translate_fields'], 'discovered translatable key must join translate_fields' );
			$this->assertEquals( 1, substr_count( wp_json_encode( $after['translate_fields'] ), 'wptm_overlay_new_field' ), 'field must be added exactly once' );
			$this->assertEquals( 1, substr_count( wp_json_encode( $after['translate_fields'] ), '"post_title"' ), 'already-classified key must not be duplicated' );
			$this->assertNotContains( 'wptm_overlay_css', $after['translate_fields'], 'code-like keys are never admitted' );
			$this->assertNotContains( 'wptm_overlay_off', $after['translate_fields'], 'non-translatable keys stay out' );

			$term_config = Translation_Rule_Service::get_merged_config_for_relation( 987654, 'category', 'term' );
			$this->assertNotContains( 'wptm_overlay_new_field', $term_config['translate_fields'], 'overlay is scoped to post data' );
		} finally {
			if ( null === $prev ) {
				delete_option( $option );
			} else {
				update_option( $option, $prev, false );
			}
		}
	}

	// ==================== get_fields_by_type() Tests ====================

	/**
	 * Test get_fields_by_type returns grouped fields
	 */
	public function test_get_fields_by_type() {
		$model_id = $this->create_test_model();
		$rule_id  = $this->create_test_rule( $model_id );

		$config  = Translation_Rule_Service::get_merged_config( $rule_id );
		$grouped = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertArrayHasKey( 'translate', $grouped );
		$this->assertArrayHasKey( 'sync', $grouped );
		$this->assertArrayHasKey( 'id_mapping', $grouped );
		$this->assertArrayHasKey( 'compute', $grouped );

		$this->assertContains( 'post_title', $grouped['translate'] );
		$this->assertContains( 'post_date', $grouped['sync'] );
		$this->assertContains( '_thumbnail_id', $grouped['id_mapping'] );
		$this->assertContains( 'post_name', $grouped['translate'] );
	}

	/**
	 * Test get_fields_by_type excludes disabled fields
	 */
	public function test_get_fields_by_type_excludes_disabled() {
		$model_id = $this->create_test_model();

		$capabilities = array(
			'post_title'   => array( 'type' => 'translate', 'enabled' => true ),
			'post_content' => array( 'type' => 'translate', 'enabled' => false ),
		);

		$rule_id = $this->create_test_rule(
			$model_id,
			array( 'field_capabilities' => wp_json_encode( $capabilities ) )
		);

		$config  = Translation_Rule_Service::get_merged_config( $rule_id );
		$grouped = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertContains( 'post_title', $grouped['translate'] );
		$this->assertNotContains( 'post_content', $grouped['translate'] );
	}

	/**
	 * Test get_fields_by_type excludes no_sync fields
	 */
	public function test_get_fields_by_type_excludes_no_sync() {
		$model_id = $this->create_test_model();

		$capabilities = array(
			'post_title' => array( 'type' => 'translate', 'enabled' => true ),
			'_price'     => array( 'type' => 'no_sync', 'enabled' => true ),
		);

		$rule_id = $this->create_test_rule(
			$model_id,
			array( 'field_capabilities' => wp_json_encode( $capabilities ) )
		);

		$config  = Translation_Rule_Service::get_merged_config( $rule_id );
		$grouped = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertContains( 'post_title', $grouped['translate'] );
		// no_sync should not appear in any group
		$this->assertNotContains( '_price', $grouped['translate'] );
		$this->assertNotContains( '_price', $grouped['sync'] );
		$this->assertNotContains( '_price', $grouped['id_mapping'] );
		$this->assertNotContains( '_price', $grouped['compute'] );
	}

	/**
	 * Test post slug translation is enabled by default.
	 */
	public function test_get_merged_config_enables_post_slug_translation_by_default() {
		$model_id = $this->create_test_model();

		$capabilities = array(
			'post_title' => array(
				'type'           => 'translate',
				'direction'      => 'one_way',
				'enabled'        => true,
				'content_format' => 'plain_text',
			),
			'post_name'  => array(
				'type'           => 'translate',
				'direction'      => 'one_way',
				'enabled'        => true,
				'content_format' => 'slug',
			),
		);

		$rule_id = $this->create_test_rule(
			$model_id,
			array( 'field_capabilities' => wp_json_encode( $capabilities ) )
		);

		$config = Translation_Rule_Service::get_merged_config( $rule_id );
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'post_name', $config['fields'] );
		$this->assertEquals( 'translate', $config['fields']['post_name']['type'] );
		$this->assertEquals( 'slug', $config['fields']['post_name']['content_format'] );
		$this->assertEquals( 'post_column', $config['fields']['post_name']['storage'] );

		$grouped = Translation_Rule_Service::get_fields_by_type( $config );
		$this->assertContains( 'post_name', $grouped['translate'] );
		$this->assertNotContains( 'post_name', $grouped['compute'] );
	}

	/**
	 * Test taxonomy slug translation is enabled by default.
	 */
	public function test_get_merged_config_enables_term_slug_translation_by_default() {
		$model_id = $this->create_test_model(
			array(
				'post_types' => '[]',
				'taxonomies' => '["category"]',
			)
		);

		$capabilities = array(
			'name' => array(
				'type'           => 'translate',
				'direction'      => 'one_way',
				'enabled'        => true,
				'content_format' => 'plain_text',
			),
			'slug' => array(
				'type'           => 'translate',
				'direction'      => 'one_way',
				'enabled'        => true,
				'content_format' => 'slug',
			),
		);

		$rule_id = $this->create_test_rule(
			$model_id,
			array(
				'data_type'          => 'term',
				'object_name'        => 'category',
				'url_pattern'        => '/category/{id}',
				'field_capabilities' => wp_json_encode( $capabilities ),
			)
		);

		$config = Translation_Rule_Service::get_merged_config( $rule_id );
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'slug', $config['fields'] );
		$this->assertEquals( 'translate', $config['fields']['slug']['type'] );
		$this->assertEquals( 'slug', $config['fields']['slug']['content_format'] );
		$this->assertEquals( 'term_column', $config['fields']['slug']['storage'] );

		$grouped = Translation_Rule_Service::get_fields_by_type( $config );
		$this->assertContains( 'slug', $grouped['translate'] );
		$this->assertNotContains( 'slug', $grouped['sync'] );
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test data
		if ( ! empty( $this->test_ids['configs'] ) ) {
			$ids = implode( ',', array_map( 'intval', $this->test_ids['configs'] ) );
			$wpdb->query( "DELETE FROM " . wptsall_table( 'relation_post_type_configs' ) . " WHERE id IN ({$ids})" );
		}

		if ( ! empty( $this->test_ids['rules'] ) ) {
			$ids = implode( ',', array_map( 'intval', $this->test_ids['rules'] ) );
			$wpdb->query( "DELETE FROM " . wptsall_table( 'translation_rules' ) . " WHERE id IN ({$ids})" );
		}

		if ( ! empty( $this->test_ids['models'] ) ) {
			$ids = implode( ',', array_map( 'intval', $this->test_ids['models'] ) );
			$wpdb->query( "DELETE FROM " . wptsall_table( 'models' ) . " WHERE id IN ({$ids})" );
		}

		parent::tearDown();
	}
}

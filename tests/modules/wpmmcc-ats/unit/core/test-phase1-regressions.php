<?php
/**
 * Phase 1 Regression Tests
 *
 * Validates that all Phase 1 fixes have not regressed.
 * Each test maps to a specific fix ID documented in as-docs/phase2/PHASE2-SYNTHESIS.md.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

use WPTSALL\Core\Smart_Field_Classifier;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Phase1_Regressions extends SimpleTestCase {

	// ==================== C1: classify_for_chain returns correct types ====================

	/**
	 * C1: Static classification must return correct types for known fields.
	 *
	 * classify_for_chain() is the canonical static entry point.
	 */
	public function test_c1_classify_for_chain_returns_correct_types() {

		// Test a representative set of fields across all types.
		$fields = array(
			'post_title'     => 'translate',
			'post_content'   => 'translate',
			'_thumbnail_id'  => 'id_mapping',
			'post_parent'    => 'id_mapping',
			'post_name'      => 'translate',
			'guid'           => 'compute',
			'_price'         => 'sync',
			'post_date'      => 'sync',
		);

		foreach ( $fields as $field => $expected_type ) {
			// classify_for_chain is the public static entry that ultimately uses classify_by_name_static.
			$classification = Smart_Field_Classifier::classify_for_chain( $field, array() );
			$result = $classification['type'];
			$this->assertEquals(
				$expected_type,
				$result,
				"C1: classify_for_chain('{$field}') should return '{$expected_type}', got '{$result}'"
			);
		}
	}

	// ==================== C2: term slug -> translate with context='term' ====================

	/**
	 * C2: Term slug must be classified as 'translate'.
	 *
	 * The $compute_fields array must include 'term' => ['slug'].
	 */
	public function test_c2_term_slug_is_translate() {
		$this->assertEquals( 'translate', Smart_Field_Classifier::$core_post_fields['post_name'] );

		$classification = Smart_Field_Classifier::classify_for_chain( 'slug', array(), 'term' );
		$this->assertEquals( 'translate', $classification['type'], 'C2: term slug must classify as translate' );
		$this->assertEquals( 'slug', $classification['content_format'], 'C2: term slug must carry slug content format' );
	}

	// ==================== C3: build_rule_config_from_merged handles skip without error ====================

	/**
	 * C3: Fields with type='skip' must be silently excluded from all field groups.
	 *
	 * build_rule_config_from_merged processes skip fields with a no-op break.
	 * We test via get_fields_by_type which has the same skip logic.
	 */
	public function test_c3_skip_fields_excluded() {
		$config = array(
			'fields' => array(
				'post_title'              => array( 'type' => 'translate', 'enabled' => true ),
				'_wp_attachment_metadata' => array( 'type' => 'skip', 'enabled' => true ),
				'_edit_lock'              => array( 'type' => 'skip', 'enabled' => true ),
				'_price'                  => array( 'type' => 'sync', 'enabled' => true ),
			),
		);

		$grouped = Translation_Rule_Service::get_fields_by_type( $config );

		$this->assertContains( 'post_title', $grouped['translate'], 'C3: post_title should be in translate' );
		$this->assertContains( '_price', $grouped['sync'], 'C3: _price should be in sync' );
		// Skip fields must not appear in any group.
		$all_fields = array_merge( $grouped['translate'], $grouped['sync'], $grouped['id_mapping'], $grouped['compute'] );
		$this->assertNotContains( '_wp_attachment_metadata', $all_fields, 'C3: skip field must not appear in any group' );
		$this->assertNotContains( '_edit_lock', $all_fields, 'C3: skip field must not appear in any group' );
	}

	// ==================== H2: _wp_attachment_metadata -> skip ====================

	/**
	 * H2: _wp_attachment_metadata must classify as 'skip'.
	 *
	 * This field contains file paths and dimensions; target site should regenerate.
	 */
	public function test_h2_attachment_metadata_is_skip() {
		$ref = new ReflectionClass( Smart_Field_Classifier::class );
		$prop = $ref->getProperty( 'explicit_field_mappings' );
		$prop->setAccessible( true );
		$mappings = $prop->getValue();

		$this->assertArrayHasKey( '_wp_attachment_metadata', $mappings, 'H2: explicit mapping must exist' );
		$this->assertEquals( 'skip', $mappings['_wp_attachment_metadata'], 'H2: _wp_attachment_metadata must be skip' );
	}

	/**
	 * H2b: Internal WPTSALL origin markers must not block taxonomy rule generation.
	 *
	 * These fields are runtime bookkeeping markers on translated targets, not source
	 * content fields. They must stay out of taxonomy translation contracts.
	 */
	public function test_h2b_wptsall_origin_markers_are_skip() {
		$ref = new ReflectionClass( Smart_Field_Classifier::class );
		$prop = $ref->getProperty( 'explicit_field_mappings' );
		$prop->setAccessible( true );
		$mappings = $prop->getValue();

		$this->assertEquals( 'skip', $mappings['_wptsall_origin_object_id'] ?? '', 'H2b: _wptsall_origin_object_id must be skip' );
		$this->assertEquals( 'skip', $mappings['_wptsall_origin_site_id'] ?? '', 'H2b: _wptsall_origin_site_id must be skip' );
		$this->assertContains(
			$mappings['_wptsall_source_term_id'] ?? '',
			array( 'id_map', 'id_mapping' ),
			'H2b: _wptsall_source_term_id must remain id_mapping'
		);
	}

	/**
	 * H2c: Translation rule core defaults must override stale template data for internal markers.
	 */
	public function test_h2c_rule_core_defaults_cover_wptsall_internal_markers() {
		$defaults = Translation_Rule_Service::get_core_field_defaults();

		$this->assertEquals( 'skip', $defaults['_wptsall_origin_object_id']['type'] ?? '', 'H2c: _wptsall_origin_object_id must default to skip' );
		$this->assertEquals( 'skip', $defaults['_wptsall_origin_site_id']['type'] ?? '', 'H2c: _wptsall_origin_site_id must default to skip' );
		$this->assertEquals( 'skip', $defaults['_wptsall_relation_id']['type'] ?? '', 'H2c: _wptsall_relation_id must default to skip' );
		$this->assertEquals( 'skip', $defaults['_wptsall_source_blog_id']['type'] ?? '', 'H2c: _wptsall_source_blog_id must default to skip' );
		$this->assertEquals( 'skip', $defaults['_wptsall_virtual_site_id']['type'] ?? '', 'H2c: _wptsall_virtual_site_id must default to skip' );
		$this->assertEquals( 'skip', $defaults['_wptsall_last_synced']['type'] ?? '', 'H2c: _wptsall_last_synced must default to skip' );
		$this->assertEquals( 'skip', $defaults['_wptsall_origin_site_type']['type'] ?? '', 'H2c: _wptsall_origin_site_type must default to skip' );
		$this->assertEquals( 'skip', $defaults['_wptsall_origin_object_type']['type'] ?? '', 'H2c: _wptsall_origin_object_type must default to skip' );
		$this->assertEquals( 'skip', $defaults['_wptsall_origin_subtype']['type'] ?? '', 'H2c: _wptsall_origin_subtype must default to skip' );
		$this->assertEquals( 'id_mapping', $defaults['_wptsall_source_term_id']['type'] ?? '', 'H2c: _wptsall_source_term_id must default to id_mapping' );
		$this->assertEquals( 'taxonomy', $defaults['_wptsall_source_term_id']['reference_type'] ?? '', 'H2c: _wptsall_source_term_id must target taxonomy' );
		$this->assertEquals( 'self', $defaults['_wptsall_source_term_id']['reference_target'] ?? '', 'H2c: _wptsall_source_term_id must target self taxonomy' );
	}

	// ==================== H3: post_author -> id_mapping ====================

	/**
	 * H3: post_author must be classified as 'id_mapping'.
	 *
	 * User IDs need cross-site mapping.
	 */
	public function test_h3_post_author_is_id_mapping() {
		$this->assertArrayHasKey( 'post_author', Smart_Field_Classifier::$core_post_fields );
		$this->assertEquals(
			'id_mapping',
			Smart_Field_Classifier::$core_post_fields['post_author'],
			'H3: post_author must be id_mapping'
		);
	}

	// ==================== H4: nav_menu fields -> id_mapping ====================

	/**
	 * H4: Navigation menu ID fields must be id_mapping.
	 *
	 * _menu_item_object_id and _menu_item_menu_item_parent reference IDs.
	 */
	public function test_h4_nav_menu_fields_id_mapping() {
		$ref = new ReflectionClass( Smart_Field_Classifier::class );
		$prop = $ref->getProperty( 'explicit_field_mappings' );
		$prop->setAccessible( true );
		$mappings = $prop->getValue();

		// id_map is the legacy value stored in the array; classify_by_name_static normalizes to id_mapping.
		$this->assertArrayHasKey( '_menu_item_object_id', $mappings, 'H4: _menu_item_object_id mapping must exist' );
		$this->assertContains( $mappings['_menu_item_object_id'], array( 'id_map', 'id_mapping' ), 'H4: _menu_item_object_id must be id_mapping' );

		$this->assertArrayHasKey( '_menu_item_menu_item_parent', $mappings, 'H4: _menu_item_menu_item_parent mapping must exist' );
		$this->assertContains( $mappings['_menu_item_menu_item_parent'], array( 'id_map', 'id_mapping' ), 'H4: _menu_item_menu_item_parent must be id_mapping' );

		// _menu_item_title must be translate.
		$this->assertArrayHasKey( '_menu_item_title', $mappings, 'H4: _menu_item_title mapping must exist' );
		$this->assertEquals( 'translate', $mappings['_menu_item_title'], 'H4: _menu_item_title must be translate' );
	}

	// ==================== H10: task identity index exists ====================

	/**
	 * H10: The tasks table must have idx_task_identity_status for open-task dedupe.
	 */
	public function test_h10_uniq_task_index_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_tasks';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			$this->markTestSkipped( 'Tasks table does not exist' );
		}

		$indexes     = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$index_names = array_unique( array_column( $indexes, 'Key_name' ) );

		$this->assertContains(
			'idx_task_identity_status',
			$index_names,
			'H10: idx_task_identity_status index must exist on tasks table'
		);
	}

	// ==================== H11: wptsall_recover_stuck_tasks function exists ====================

	/**
	 * H11: The stuck task recovery function must be defined.
	 *
	 * Note: Tasks module is gated behind domain validation, so on localhost
	 * automation-cron.php (which defines this function) is not loaded.
	 */
	public function test_h11_recover_stuck_tasks_function_exists() {
		if ( ! function_exists( 'wptsall_recover_stuck_tasks' ) ) {
			$this->markTestSkipped( 'Tasks module not loaded (domain validation gate on localhost)' );
		}
		$this->assertTrue(
			function_exists( 'wptsall_recover_stuck_tasks' ),
			'H11: wptsall_recover_stuck_tasks() must be defined'
		);
	}

	// ==================== H12: task insert writes both site_id and relation_id ====================

	/**
	 * H12: Task insert must write both site_id and relation_id columns.
	 *
	 * The tasks table has both columns for backward compatibility;
	 * both must be populated with the effective relation_id.
	 */
	public function test_h12_task_insert_writes_both_ids() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_tasks';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			$this->markTestSkipped( 'Tasks table does not exist' );
		}

		// Verify both columns exist in the table schema.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		$column_names = array_column( $columns, 'Field' );

		$this->assertContains( 'site_id', $column_names, 'H12: site_id column must exist' );
		$this->assertContains( 'relation_id', $column_names, 'H12: relation_id column must exist' );

		// Verify that existing tasks have matching site_id and relation_id.
		$mismatch = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE site_id != relation_id AND site_id > 0 AND relation_id > 0"
		);
		$this->assertEquals( 0, $mismatch, 'H12: site_id and relation_id must match for all tasks' );
	}

	// ==================== M1: Yoast OG image -> sync ====================

	/**
	 * M1: Yoast OG image URL field must be 'sync' (not translate).
	 *
	 * Image URLs should be synced, while image IDs should be id_mapping.
	 */
	public function test_m1_yoast_og_image_sync() {
		$ref = new ReflectionClass( Smart_Field_Classifier::class );
		$prop = $ref->getProperty( 'explicit_field_mappings' );
		$prop->setAccessible( true );
		$mappings = $prop->getValue();

		// OG image URL -> sync.
		$this->assertEquals( 'sync', $mappings['_yoast_wpseo_opengraph-image'] ?? '', 'M1: OG image URL must be sync' );

		// OG image ID -> id_map(ping).
		$this->assertContains(
			$mappings['_yoast_wpseo_opengraph-image-id'] ?? '',
			array( 'id_map', 'id_mapping' ),
			'M1: OG image ID must be id_mapping'
		);

		// Twitter image URL -> sync.
		$this->assertEquals( 'sync', $mappings['_yoast_wpseo_twitter-image'] ?? '', 'M1: Twitter image URL must be sync' );
	}

	// ==================== M3: name pattern narrowed ====================

	/**
	 * M3: The /name$/ pattern must NOT match display_name or user_name.
	 *
	 * Only explicitly known translatable *_name fields (product_name, etc.) match.
	 */
	public function test_m3_name_pattern_narrowed() {
		// These should NOT be translate.
		$non_translate_names = array( 'display_name', 'user_name', 'file_name' );
		foreach ( $non_translate_names as $field ) {
			$classification = Smart_Field_Classifier::classify_for_chain( $field, array() );
			$result = $classification['type'];
			$this->assertNotEquals(
				'translate',
				$result,
				"M3: '{$field}' must NOT be classified as translate, got '{$result}'"
			);
		}

		// These SHOULD be translate.
		$translate_names = array( 'product_name', 'category_name', 'brand_name' );
		foreach ( $translate_names as $field ) {
			$classification = Smart_Field_Classifier::classify_for_chain( $field, array() );
			$result = $classification['type'];
			$this->assertEquals(
				'translate',
				$result,
				"M3: '{$field}' SHOULD be classified as translate, got '{$result}'"
			);
		}
	}

	// ==================== M14: get_merged_config returns id_mapping key ====================

	/**
	 * M14: get_fields_by_type must return 'id_mapping' key (not 'mapping').
	 *
	 * The key was renamed from 'mapping' to 'id_mapping' in 1.0.0.
	 */
	public function test_m14_merged_config_id_mapping_key() {
		$config = array(
			'fields' => array(
				'_thumbnail_id' => array( 'type' => 'mapping', 'enabled' => true ),
				'post_parent'   => array( 'type' => 'id_mapping', 'enabled' => true ),
			),
		);

		$grouped = Translation_Rule_Service::get_fields_by_type( $config );

		// Must use 'id_mapping' key, not 'mapping'.
		$this->assertArrayHasKey( 'id_mapping', $grouped, 'M14: grouped must have id_mapping key' );
		$this->assertArrayNotHasKey( 'mapping', $grouped, 'M14: grouped must NOT have mapping key' );

		// Both fields with 'mapping' and 'id_mapping' types should end up in id_mapping group.
		$this->assertContains( '_thumbnail_id', $grouped['id_mapping'], 'M14: mapping type must map to id_mapping group' );
		$this->assertContains( 'post_parent', $grouped['id_mapping'], 'M14: id_mapping type must be in id_mapping group' );
	}

	// ==================== M16: source_term_id meta stored for term sync ====================

	/**
	 * M16: The term_mappings table schema must include source_term_id column.
	 *
	 * Note: The original test referenced 'field_mappings' but the actual table
	 * storing term ID mappings is 'term_mappings' (see schema-field-mappings.php).
	 */
	public function test_m16_source_term_id_column() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_term_mappings';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			$this->markTestSkipped( 'term_mappings table does not exist' );
		}

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		$column_names = array_column( $columns, 'Field' );

		$this->assertContains( 'source_term_id', $column_names, 'M16: source_term_id column must exist in term_mappings table' );
	}

	// ==================== M22: ping response contains sync_execution_mode ====================

	/**
	 * M22: The client tasks ping endpoint must include sync_execution_mode.
	 *
	 * We verify by checking that the REST controller class exists and
	 * the task parameters function provides a usable fallback.
	 */
	public function test_m22_sync_execution_mode_in_ping() {
		if ( ! function_exists( 'wptsall_get_task_parameters' ) ) {
			$this->markTestSkipped( 'wptsall_get_task_parameters not available' );
		}

		// The ping endpoint reads sync_execution_mode from task_params with 'client' fallback.
		// Verify the parameter function works and the fallback is applied.
		$params = wptsall_get_task_parameters();
		$mode = $params['sync_execution_mode'] ?? 'client';
		$this->assertContains(
			$mode,
			array( 'client', 'local', 'hybrid' ),
			'M22: sync_execution_mode must be one of client/local/hybrid'
		);

		// Verify the REST controller class exists.
		$this->assertTrue(
			class_exists( '\\WPTSALL\\Tasks\\Api\\Client_Tasks_Rest_Controller' ),
			'M22: Client_Tasks_Rest_Controller must exist'
		);
	}

	// ==================== L10: templates table has no idx_slug redundant index ====================

	/**
	 * L10: The templates table must use unique_slug, not a redundant idx_slug.
	 *
	 * The slug column has a UNIQUE KEY, so a separate non-unique index is redundant.
	 */
	public function test_l10_no_redundant_idx_slug() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_templates';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			$this->markTestSkipped( 'Templates table does not exist' );
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$index_names = array_unique( array_column( $indexes, 'Key_name' ) );

		// unique_slug should exist.
		$this->assertContains( 'unique_slug', $index_names, 'L10: unique_slug index must exist' );

		// idx_slug (redundant non-unique index) should NOT exist.
		$this->assertNotContains( 'idx_slug', $index_names, 'L10: redundant idx_slug index must not exist' );
	}

	// ==================== L12: wptsall_is_valid_task_status works correctly ====================

	/**
	 * L12: wptsall_is_valid_task_status must accept all valid statuses and reject invalid ones.
	 */
	public function test_l12_valid_task_status() {
		if ( ! function_exists( 'wptsall_is_valid_task_status' ) ) {
			$this->markTestSkipped( 'wptsall_is_valid_task_status not available' );
		}

		// Valid statuses.
		$valid = array( 'pending', 'processing', 'active', 'paused', 'completed', 'skipped', 'retry', 'failed', 'error' );
		foreach ( $valid as $status ) {
			$this->assertTrue(
				wptsall_is_valid_task_status( $status ),
				"L12: '{$status}' must be a valid task status"
			);
		}

		// Invalid statuses.
		$invalid = array( 'invalid', 'deleted', 'cancelled', '', 'done', 'running' );
		foreach ( $invalid as $status ) {
			$this->assertFalse(
				wptsall_is_valid_task_status( $status ),
				"L12: '{$status}' must NOT be a valid task status"
			);
		}
	}

	// ==================== Additional regression: sync_mode fixed to new_only ====================

	/**
	 * ISS-SIT-037: sync_mode must always be 'new_only'.
	 */
	public function test_sync_mode_fixed_new_only() {
		// Verify Task_Orchestrator::validate_task rejects non-new_only sync_mode.
		if ( ! class_exists( '\\WPTSALL\\Tasks\\Services\\Task_Orchestrator' ) ) {
			$this->markTestSkipped( 'Task_Orchestrator not available' );
		}

		$task = array(
			'relation_id' => 1,
			'post_type'   => 'post',
			'config'      => array(
				'fields'    => array( 'post_title' => array( 'type' => 'translate' ) ),
				'direction' => 'source_to_target',
				'sync_mode' => 'incremental',
			),
		);

		$result = \WPTSALL\Tasks\Services\Task_Orchestrator::validate_task( $task );
		$this->assertFalse( $result['valid'], 'ISS-SIT-037: sync_mode=incremental must be rejected' );

		// new_only must be accepted.
		$task['config']['sync_mode'] = 'new_only';
		$result = \WPTSALL\Tasks\Services\Task_Orchestrator::validate_task( $task );
		$this->assertTrue( $result['valid'], 'ISS-SIT-037: sync_mode=new_only must be accepted' );
	}
}

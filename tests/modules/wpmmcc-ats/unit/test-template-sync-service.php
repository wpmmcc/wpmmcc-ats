<?php
/**
 * Template Sync Service Tests
 *
 * L1/L2 for WPTSALL\Models\Services\Template_Sync_Service:
 * - pre-validation gate: invalid model id / empty scan result is blocked with
 *   errors and persists NOTHING
 * - sync_scan_to_objects(): a valid scan (post_type + rules_summary caps)
 *   upserts the object and persists fields with status='new'; a second scan
 *   removing the field orphans it (status='orphan', not deleted); a scan
 *   changing data_type records the change
 * - detect_field_changes(): direct diff matrix (added / unchanged /
 *   type_changed / orphan; manual fields are never orphaned)
 *
 * catalog: WP-CLASS-Template_Sync_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Template_Sync_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Template_Sync_Service extends SimpleTestCase {

	/**
	 * @var Template_Sync_Service
	 */
	private $service;

	/**
	 * @var array<int, int>
	 */
	private $model_ids = array();

	public function setUp(): void {
		parent::setUp();
		$this->service = new Template_Sync_Service();
	}

	public function tearDown(): void {
		foreach ( $this->model_ids as $model_id ) {
			Translation_Rule_Service::delete_model( $model_id );
		}
		$this->model_ids = array();
		parent::tearDown();
	}

	private function create_model(): int {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'tpl-sync-' . uniqid(),
			'plugin_name' => 'Template Sync Test',
			'is_system'   => true,
			'post_types'  => array(),
			'taxonomies'  => array(),
		) );
		$this->assertGreaterThan( 0, (int) $model_id );
		$this->model_ids[] = (int) $model_id;
		return (int) $model_id;
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Template_Sync_Service' ) );
	}

	public function test_prevalidation_gate_blocks_invalid_scans() {
		$model_id = $this->create_model();

		// Invalid model id.
		$diff = $this->service->sync_scan_to_objects( 0, array( 'post_types' => array( 'zz' ) ) );
		$this->assertArrayHasKey( 'validation', $diff );
		$this->assertFalse( $diff['validation']['valid'] );
		$this->assertTrue( $diff['validation']['blocked'] );
		$this->assertNotEmpty( $diff['validation']['errors'] );

		// Empty scan result: nothing discoverable.
		$diff = $this->service->sync_scan_to_objects( $model_id, array() );
		$this->assertTrue( $diff['validation']['blocked'] );
		$this->assertNotEmpty( $diff['validation']['errors'] );

		// rules_summary with a non-array shape is rejected too.
		$diff = $this->service->sync_scan_to_objects( $model_id, array(
			'post_types'    => array( 'zz' ),
			'rules_summary' => 'not-an-array',
		) );
		$this->assertTrue( $diff['validation']['blocked'] );

		// Nothing persisted by the blocked syncs.
		$this->assertSame( array(), Model_Object_Service::get_objects_for_model( $model_id ) );
	}

	public function test_sync_scan_to_objects_persists_and_orphans() {
		$model_id  = $this->create_model();
		$pt        = 'zz_tss_pt_' . strtolower( uniqid() );

		$scan = array(
			'post_types'    => array( $pt ),
			'taxonomies'    => array(),
			'custom_tables' => array(),
			'rules_summary' => array(
				array(
					'object_name'         => $pt,
					'field_capabilities' => array(
						'_tss_field_a' => array( 'type' => 'translate', 'data_type' => 'text' ),
						'_tss_field_b' => array( 'type' => 'sync', 'data_type' => 'numeric' ),
					),
				),
			),
		);

		$diff = $this->service->sync_scan_to_objects( $model_id, $scan );
		$this->assertIsArray( $diff );
		$this->assertArrayHasKey( 'validation', $diff, 'sync result always carries a validation block' );
		$this->assertTrue( $diff['validation']['valid'], 'valid scan must not be blocked: ' . wp_json_encode( $diff ) );
		$this->assertSame( array(), $diff['validation']['errors'] );
		$this->assertArrayNotHasKey( 'blocked', $diff['validation'], 'blocked flag only exists on the gate-rejected path' );
		$this->assertNotEmpty( $diff['added'], 'first sync must add fields' );

		$objects = Model_Object_Service::get_objects_for_model( $model_id );
		$this->assertCount( 1, $objects );
		$this->assertEquals( $pt, $objects[0]['object_name'] );

		$object_id = (int) $objects[0]['id'];
		$fields    = Model_Object_Service::get_fields_for_object( $object_id );
		$keys      = wp_list_pluck( $fields, 'field_key' );
		$this->assertContains( '_tss_field_a', $keys );
		$this->assertContains( '_tss_field_b', $keys );
		foreach ( $fields as $field ) {
			$this->assertSame( 'new', $field['status'], 'scan-persisted fields start as new' );
		}

		// Second scan: field_b disappears (orphan), field_a changes type.
		$scan2 = array(
			'post_types'    => array( $pt ),
			'taxonomies'    => array(),
			'custom_tables' => array(),
			'rules_summary' => array(
				array(
					'object_name'         => $pt,
					'field_capabilities' => array(
						'_tss_field_a' => array( 'type' => 'translate', 'data_type' => 'html' ),
					),
				),
			),
		);
		$diff2 = $this->service->sync_scan_to_objects( $model_id, $scan2 );
		$this->assertNotEmpty( $diff2['orphan'], 'removed scan field must be orphaned' );
		$this->assertNotEmpty( $diff2['type_changed'], 'data_type change must be detected' );

		$fields_after = Model_Object_Service::get_fields_for_object( $object_id );
		$by_key       = array();
		foreach ( $fields_after as $f ) {
			$by_key[ $f['field_key'] ] = $f;
		}
		$this->assertSame( 'orphan', $by_key['_tss_field_b']['status'], 'orphaned field is marked, not deleted' );
		$this->assertArrayHasKey( '_tss_field_b', $by_key, 'orphaned field must remain present' );
		$this->assertSame( 'html', $by_key['_tss_field_a']['data_type'], 'type change must be persisted' );
	}

	public function test_detect_field_changes_diff_matrix() {
		$model_id  = $this->create_model();
		$object_id = Model_Object_Service::create_object(
			$model_id,
			'post_type',
			'zz_tss_diff_' . uniqid(),
			array( 'source_type' => 'manual', 'metadata' => array() )
		);
		$this->assertGreaterThan( 0, (int) $object_id );

		// Seed: two scan-sourced fields + one manual field.
		Model_Object_Service::insert_field( $object_id, array(
			'field_kind' => 'meta', 'field_key' => '_scan_keep', 'source' => 'scan', 'data_type' => 'text',
		) );
		$gone_id = Model_Object_Service::insert_field( $object_id, array(
			'field_kind' => 'meta', 'field_key' => '_scan_gone', 'source' => 'scan', 'data_type' => 'text',
		) );
		Model_Object_Service::insert_field( $object_id, array(
			'field_kind' => 'meta', 'field_key' => '_manual_keep', 'source' => 'manual', 'data_type' => 'text',
		) );
		$this->assertGreaterThan( 0, (int) $gone_id );

		$diff = $this->service->detect_field_changes( $object_id, array(
			array( 'field_kind' => 'meta', 'field_key' => '_scan_keep', 'data_type' => 'html' ),
			array( 'field_kind' => 'meta', 'field_key' => '_brand_new', 'data_type' => 'text' ),
		) );

		$this->assertContains( '_brand_new', wp_list_pluck( $diff['added'], 'field_key' ) );
		$this->assertContains( '_scan_keep', wp_list_pluck( $diff['type_changed'], 'field_key' ) );
		// Orphan: only scan-sourced fields missing from the new set.
		$orphan_keys = wp_list_pluck( $diff['orphan'], 'field_key' );
		$this->assertContains( '_scan_gone', $orphan_keys );
		$this->assertNotContains( '_manual_keep', $orphan_keys, 'manual fields are never orphaned by scans' );
		// Unchanged stats include the type-changed field (full-mode refresh).
		$this->assertContains( '_scan_keep', wp_list_pluck( $diff['unchanged'], 'field_key' ) );
	}
}

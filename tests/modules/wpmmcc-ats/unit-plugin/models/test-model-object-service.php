<?php
/**
 * Model Object Service Tests
 *
 * Tests for WPTSALL\Models\Services\Model_Object_Service class.
 *
 * @package WPTSALL
 * @since 0.6.0
 */

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Model_Object_Service extends SimpleTestCase {

	/**
	 * Test model IDs for cleanup.
	 *
	 * @var array
	 */
	protected $test_model_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wptsall_models_table_exists' ) && ! wptsall_models_table_exists() ) {
			wptsall_create_model_tables();
		}

		if ( function_exists( 'wptsall_run_migrations' ) ) {
			wptsall_run_migrations();
		}
	}

	/**
	 * Create a test model and track it for cleanup.
	 *
	 * @param string $suffix Unique suffix.
	 * @return int
	 */
	private function create_test_model( string $suffix ): int {
		$model_id = Translation_Rule_Service::create_model(
			array(
				'plugin_slug' => 'model-object-test-' . $suffix . '-' . uniqid(),
				'plugin_name' => 'Model Object Test ' . $suffix,
				'post_types'  => array( 'post' ),
				'taxonomies'  => array(),
			)
		);

		$this->assertIsInt( $model_id, 'Test model should be created' );
		$this->test_model_ids[] = $model_id;

		return $model_id;
	}

	/**
	 * Clean up test data.
	 */
	public function tearDown(): void {
		global $wpdb;

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
					$wpdb->delete( $fields_table, array( 'object_id' => (int) $object_id ) );
				}

				$wpdb->delete( $objects_table, array( 'model_id' => $model_id ) );
				$wpdb->delete( $rules_table, array( 'model_id' => $model_id ) );
				$wpdb->delete( $models_table, array( 'id' => $model_id ) );
			}
		}

		parent::tearDown();
	}

	/**
	 * Test creating an object and looking it up again.
	 */
	public function test_create_object_and_lookup() {
		$model_id  = $this->create_test_model( 'lookup' );
		$object_id = Model_Object_Service::create_object(
			$model_id,
			'post_type',
			'post',
			array(
				'url_signature' => '/post/{id}',
				'metadata'      => array( 'label' => 'Post Object' ),
				'source_type'   => 'manual',
			)
		);

		$this->assertIsInt( $object_id, 'Object should be created' );
		$this->assertGreaterThan( 0, $object_id, 'Object ID should be positive' );

		$object = Model_Object_Service::get_object( $object_id );
		$this->assertIsArray( $object );
		$this->assertEquals( $model_id, (int) $object['model_id'] );
		$this->assertEquals( 'post_type', $object['object_type'] );
		$this->assertEquals( 'post', $object['object_name'] );
		$this->assertEquals( '/post/{id}', $object['url_signature'] );
		$this->assertIsArray( $object['metadata'] );
		$this->assertEquals( 'Post Object', $object['metadata']['label'] );

		$object_by_type = Model_Object_Service::get_object_by_type( $model_id, 'post_type', 'post' );
		$this->assertIsArray( $object_by_type );
		$this->assertEquals( $object_id, (int) $object_by_type['id'] );
	}

	/**
	 * Test creating an option object and looking it up again.
	 */
	public function test_create_option_object_and_lookup() {
		$model_id  = $this->create_test_model( 'option-lookup' );
		$object_id = Model_Object_Service::create_object(
			$model_id,
			'option',
			'wptsall_notification_template',
			array(
				'metadata'    => array( 'label' => 'Notification Template' ),
				'source_type' => 'manual',
			)
		);

		$this->assertIsInt( $object_id, 'Option object should be created' );
		$this->assertGreaterThan( 0, $object_id, 'Option object ID should be positive' );

		$object = Model_Object_Service::get_object( $object_id );
		$this->assertIsArray( $object );
		$this->assertEquals( 'option', $object['object_type'] );
		$this->assertEquals( 'wptsall_notification_template', $object['object_name'] );
		$this->assertEquals( 'Notification Template', $object['metadata']['label'] );

		$object_by_type = Model_Object_Service::get_object_by_type( $model_id, 'option', 'wptsall_notification_template' );
		$this->assertIsArray( $object_by_type );
		$this->assertEquals( $object_id, (int) $object_by_type['id'] );
	}

	/**
	 * Test create_object upserts an existing object binding.
	 */
	public function test_create_object_is_idempotent_for_same_binding() {
		$model_id = $this->create_test_model( 'upsert' );

		$first_id = Model_Object_Service::create_object(
			$model_id,
			'post_type',
			'post',
			array(
				'url_signature' => '/first/{id}',
				'metadata'      => array( 'version' => 1 ),
				'source_type'   => 'manual',
			)
		);
		$second_id = Model_Object_Service::create_object(
			$model_id,
			'post_type',
			'post',
			array(
				'url_signature' => '/second/{id}',
				'metadata'      => array( 'version' => 2 ),
				'source_type'   => 'manual',
			)
		);

		$this->assertIsInt( $first_id );
		$this->assertEquals( $first_id, $second_id, 'Existing object binding should be updated, not duplicated' );

		$object = Model_Object_Service::get_object( $first_id );
		$this->assertEquals( '/second/{id}', $object['url_signature'] );
		$this->assertEquals( 2, (int) $object['metadata']['version'] );
	}

	/**
	 * Test get_objects_for_model returns decoded metadata in stable order.
	 */
	public function test_get_objects_for_model_returns_sorted_objects() {
		$model_id = $this->create_test_model( 'list' );

		Model_Object_Service::create_object( $model_id, 'post_type', 'zeta_post', array( 'metadata' => array( 'label' => 'Zeta' ) ) );
		Model_Object_Service::create_object( $model_id, 'post_type', 'alpha_post', array( 'metadata' => array( 'label' => 'Alpha' ) ) );
		Model_Object_Service::create_object( $model_id, 'taxonomy', 'category', array( 'metadata' => array( 'label' => 'Category' ) ) );

		$objects = Model_Object_Service::get_objects_for_model( $model_id );

		$this->assertCount( 3, $objects );
		$this->assertEquals( 'alpha_post', $objects[0]['object_name'] );
		$this->assertEquals( 'zeta_post', $objects[1]['object_name'] );
		$this->assertEquals( 'category', $objects[2]['object_name'] );
		$this->assertEquals( 'Alpha', $objects[0]['metadata']['label'] );
		$this->assertEquals( 'Category', $objects[2]['metadata']['label'] );
	}

	/**
	 * Test field lifecycle and manual-source precedence.
	 */
	public function test_field_lifecycle_and_manual_source_precedence() {
		$model_id  = $this->create_test_model( 'field' );
		$object_id = Model_Object_Service::create_object( $model_id, 'post_type', 'post' );

		$field_id = Model_Object_Service::add_field(
			$object_id,
			'meta',
			'rating',
			'manual',
			array( 'foo' => 'bar' )
		);
		$this->assertIsInt( $field_id );

		$scan_attempt_id = Model_Object_Service::add_field(
			$object_id,
			'meta',
			'rating',
			'scan',
			array( 'foo' => 'should_not_override' )
		);
		$this->assertEquals( $field_id, $scan_attempt_id, 'Manual field should not be overwritten by scan field' );

		$field = Model_Object_Service::get_field( $field_id );
		$this->assertEquals( 'manual', $field['source'] );
		$this->assertEquals( 'bar', $field['extra']['foo'] );

		$updated = Model_Object_Service::update_field(
			$field_id,
			array(
				'source'           => 'manual',
				'data_type'        => 'id_ref',
				'reference_type'   => 'post',
				'reference_target' => 'post',
				'usage_count'      => 5,
				'sample_value'     => '123',
				'source_origin'      => 'manual',
				'source_detail'      => 'added in unit test',
				'confidence_score'   => 88,
				'confidence_reason'  => 'admin override',
				'is_manual_override' => true,
				'is_admin_approved'  => true,
				'extra'            => array(
					'foo' => 'baz',
					'bar' => 'qux',
				),
			)
		);
		$this->assertTrue( $updated );

		$field = Model_Object_Service::get_field( $field_id );
		$this->assertEquals( 'id_ref', $field['data_type'] );
		$this->assertEquals( 'post', $field['reference_type'] );
		$this->assertEquals( 'post', $field['reference_target'] );
		$this->assertEquals( 5, (int) $field['usage_count'] );
		$this->assertEquals( '123', $field['sample_value'] );
		$this->assertEquals( 'manual', $field['source_origin'] );
		$this->assertEquals( 'added in unit test', $field['source_detail'] );
		$this->assertEquals( 88, (int) $field['confidence_score'] );
		$this->assertEquals( 'admin override', $field['confidence_reason'] );
		$this->assertEquals( 1, (int) $field['is_manual_override'] );
		$this->assertEquals( 1, (int) $field['is_admin_approved'] );
		$this->assertNotEmpty( $field['approved_at'] );
		$this->assertEquals( 'approved_semantic', $field['approval_state'] );
		$this->assertEquals( 'baz', $field['extra']['foo'] );
		$this->assertEquals( 'qux', $field['extra']['bar'] );

		$fields = Model_Object_Service::get_fields_for_object( $object_id );
		$this->assertCount( 1, $fields );
		$this->assertEquals( $field_id, (int) $fields[0]['id'] );

		$deleted = Model_Object_Service::delete_field( $field_id );
		$this->assertTrue( $deleted );
		$this->assertNull( Model_Object_Service::get_field( $field_id ) );
		$this->assertEmpty( Model_Object_Service::get_fields_for_object( $object_id ) );
	}

	/**
	 * Test model diagnostics summarizes field metadata.
	 */
	public function test_get_model_diagnostics_reports_field_metadata() {
		$model_id  = $this->create_test_model( 'diagnostics' );
		$object_id = Model_Object_Service::create_object( $model_id, 'post_type', 'post' );
		$field_id  = Model_Object_Service::add_field( $object_id, 'meta', 'headline', 'manual', array() );

		$this->assertIsInt( $field_id );
		$this->assertTrue( Model_Object_Service::update_field( $field_id, array(
			'source_origin'      => 'manual',
			'confidence_score'   => 45,
			'confidence_reason'  => 'needs review',
			'is_manual_override' => true,
			'is_admin_approved'  => false,
		) ) );

		$diagnostics = Model_Object_Service::get_model_diagnostics( $model_id );
		$this->assertEquals( 1, (int) $diagnostics['summary']['total_fields'] );
		$this->assertEquals( 1, (int) $diagnostics['summary']['low_confidence'] );
		$this->assertEquals( 1, (int) $diagnostics['summary']['unapproved'] );
		$this->assertEquals( 1, (int) $diagnostics['summary']['manual_override'] );
		$this->assertEquals( 1, (int) ( $diagnostics['by_approval_state']['manual_supplement'] ?? 0 ) );
		$this->assertEquals( 1, (int) ( $diagnostics['by_source_origin']['manual'] ?? 0 ) );
	}

	/**
	 * Test approval_state aliases normalize to existing metadata columns.
	 */
	public function test_update_field_accepts_approval_state_alias() {
		$model_id  = $this->create_test_model( 'approval-state' );
		$object_id = Model_Object_Service::create_object( $model_id, 'post_type', 'post' );
		$field_id  = Model_Object_Service::add_field( $object_id, 'meta', 'subtitle', 'scan', array() );

		$this->assertTrue( Model_Object_Service::update_field( $field_id, array(
			'approval_state' => 'imported_declaration',
		) ) );

		$field = Model_Object_Service::get_field( $field_id );
		$this->assertEquals( 'wpml_config_xml', $field['source_origin'] );
		$this->assertEquals( 'imported_declaration', $field['approval_state'] );

		$this->assertTrue( Model_Object_Service::update_field( $field_id, array(
			'approval_state' => 'approved_semantic',
		) ) );

		$field = Model_Object_Service::get_field( $field_id );
		$this->assertEquals( 1, (int) $field['is_admin_approved'] );
		$this->assertEquals( 'approved_semantic', $field['approval_state'] );
	}

	/**
	 * Test deleting an object also removes its fields.
	 */
	public function test_delete_object_removes_associated_fields() {
		$model_id  = $this->create_test_model( 'delete-object' );
		$object_id = Model_Object_Service::create_object( $model_id, 'post_type', 'post' );
		$field_id  = Model_Object_Service::add_field( $object_id, 'meta', 'headline', 'manual', array() );

		$this->assertIsInt( $field_id );
		$this->assertCount( 1, Model_Object_Service::get_fields_for_object( $object_id ) );

		$deleted = Model_Object_Service::delete_object( $object_id );
		$this->assertTrue( $deleted );
		$this->assertNull( Model_Object_Service::get_object( $object_id ) );
		$this->assertEmpty( Model_Object_Service::get_fields_for_object( $object_id ) );
	}

	/**
	 * Test invalid object input is rejected.
	 */
	public function test_create_object_rejects_invalid_input() {
		$model_id = $this->create_test_model( 'invalid' );

		$this->assertFalse( Model_Object_Service::create_object( 0, 'post_type', 'post' ) );
		$this->assertFalse( Model_Object_Service::create_object( $model_id, 'invalid_type', 'post' ) );
		$this->assertFalse( Model_Object_Service::create_object( $model_id, 'post_type', '' ) );
	}
}

<?php
/**
 * Template Validation Service Tests
 *
 * L1/L2 for WPTSALL\Models\Services\Template_Validation_Service:
 * - validate_object_fields(): direct-param matrix for every documented error
 *   code (no_objects, empty_field_key, field_key_too_long, unknown_data_type,
 *   missing_reference_type, field_key_special_chars, reserved_prefix)
 * - validate_relationships(): no_fields, unresolved_reference_target
 *   (media/user exempt), taxonomy_not_in_model for real post_type objects
 * - validate_discovery_coverage(): undiscovered_taxonomy and
 *   undiscovered_post_type warnings
 * - field_exists_in_database(): post meta presence
 * - validate_all(): unified shape with a real seeded model
 *
 * catalog: WP-CLASS-Template_Validation_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Template_Validation_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Template_Validation_Service extends SimpleTestCase {

	/**
	 * @var Template_Validation_Service
	 */
	private $service;

	/**
	 * Model ids created by this run (deleted in tearDown).
	 *
	 * @var array<int, int>
	 */
	private $model_ids = array();

	/**
	 * @var array<int, int> Post ids created by this run.
	 */
	private $post_ids = array();

	public function setUp(): void {
		parent::setUp();
		$this->service = new Template_Validation_Service();
	}

	public function tearDown(): void {
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = array();
		foreach ( $this->model_ids as $model_id ) {
			Translation_Rule_Service::delete_model( $model_id );
		}
		$this->model_ids = array();
		parent::tearDown();
	}

	private function create_model(): int {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'tpl-validation-' . uniqid(),
			'plugin_name' => 'Template Validation Test',
			'is_system'   => true,
			'post_types'  => array(),
			'taxonomies'  => array(),
		) );
		$this->assertGreaterThan( 0, (int) $model_id );
		$this->model_ids[] = (int) $model_id;
		return (int) $model_id;
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Template_Validation_Service' ) );
	}

	public function test_validate_object_fields_error_matrix() {
		$tag    = uniqid();
		$object = array( 'id' => 1, 'object_name' => 'zzobj_' . $tag, 'object_type' => 'post_type' );

		$cases = array(
			'no objects' => array(
				'objects' => array(),
				'fields'  => array(),
				'expect'  => 'no_objects',
			),
			'empty field key' => array(
				'objects' => array( $object ),
				'fields'  => array( 1 => array( array( 'field_key' => '', 'data_type' => 'text' ) ) ),
				'expect'  => 'empty_field_key',
			),
			'field key too long' => array(
				'objects' => array( $object ),
				'fields'  => array( 1 => array( array( 'field_key' => str_repeat( 'k', 256 ), 'data_type' => 'text' ) ) ),
				'expect'  => 'field_key_too_long',
			),
			'unknown data type' => array(
				'objects' => array( $object ),
				'fields'  => array( 1 => array( array( 'field_key' => '_ok_key', 'data_type' => 'wibble' ) ) ),
				'expect'  => 'unknown_data_type',
			),
			'id_ref without reference' => array(
				'objects' => array( $object ),
				'fields'  => array( 1 => array( array( 'field_key' => '_ok_key', 'data_type' => 'id_ref', 'reference_type' => null ) ) ),
				'expect'  => 'missing_reference_type',
			),
			'id_list without reference' => array(
				'objects' => array( $object ),
				'fields'  => array( 1 => array( array( 'field_key' => '_ok_key', 'data_type' => 'id_list', 'reference_type' => '' ) ) ),
				'expect'  => 'missing_reference_type',
			),
			'special characters' => array(
				'objects' => array( $object ),
				'fields'  => array( 1 => array( array( 'field_key' => 'has space!', 'data_type' => 'text' ) ) ),
				'expect'  => 'field_key_special_chars',
			),
			'reserved wp_ prefix' => array(
				'objects' => array( $object ),
				'fields'  => array( 1 => array( array( 'field_key' => 'wp_reserved_key', 'data_type' => 'text' ) ) ),
				'expect'  => 'reserved_prefix',
			),
		);

		foreach ( $cases as $label => $case ) {
			$errors = $this->service->validate_object_fields( 1, $case['objects'], $case['fields'] );
			$codes  = wp_list_pluck( $errors, 'code' );
			$this->assertContains( $case['expect'], $codes, "case '{$label}' must produce {$case['expect']}: " . wp_json_encode( $errors ) );
		}

		// Clean object with valid fields produces no errors.
		$errors = $this->service->validate_object_fields(
			1,
			array( $object ),
			array( 1 => array( array( 'field_key' => '_good_key', 'data_type' => 'text' ) ) )
		);
		$this->assertSame( array(), $errors );
	}

	public function test_validate_relationships_warning_matrix() {
		$tag = uniqid();

		// Object with no fields -> no_fields warning.
		$warnings = $this->service->validate_relationships(
			1,
			array( array( 'id' => 1, 'object_name' => 'zzrel_' . $tag, 'object_type' => 'post_type' ) ),
			array( 1 => array() )
		);
		$this->assertContains( 'no_fields', wp_list_pluck( $warnings, 'code' ) );

		// Unresolved reference target -> warning; media/user refs exempt.
		$warnings = $this->service->validate_relationships(
			1,
			array(
				array( 'id' => 1, 'object_name' => 'zzrel_a_' . $tag, 'object_type' => 'post_type' ),
				array( 'id' => 2, 'object_name' => 'zzrel_b_' . $tag, 'object_type' => 'post_type' ),
			),
			array(
				1 => array(
					array( 'field_key' => '_ref_missing', 'reference_type' => 'post_type', 'reference_target' => 'not_in_model_' . $tag ),
					array( 'field_key' => '_ref_media', 'reference_type' => 'media', 'reference_target' => 'media_is_external' ),
					array( 'field_key' => '_ref_user', 'reference_type' => 'user', 'reference_target' => 'user_is_external' ),
					array( 'field_key' => '_ref_ok', 'reference_type' => 'post_type', 'reference_target' => 'zzrel_b_' . $tag ),
				),
				2 => array(),
			)
		);
		$codes = wp_list_pluck( $warnings, 'code' );
		$this->assertContains( 'unresolved_reference_target', $codes );
		// Exactly one unresolved warning: media/user/valid refs must not warn.
		$unresolved = array_values( array_filter( $warnings, static function ( $w ) {
			return 'unresolved_reference_target' === $w['code'];
		} ) );
		$this->assertCount( 1, $unresolved );

		// Real post_type object with an unmodeled public taxonomy (post has
		// 'category') -> taxonomy_not_in_model.
		$warnings = $this->service->validate_relationships(
			1,
			array( array( 'id' => 3, 'object_name' => 'post', 'object_type' => 'post_type' ) ),
			array( 3 => array( array( 'field_key' => '_content', 'data_type' => 'html' ) ) )
		);
		$codes = wp_list_pluck( $warnings, 'code' );
		$this->assertContains( 'taxonomy_not_in_model', $codes );
	}

	public function test_validate_discovery_coverage_warnings() {
		$tag = uniqid();

		// Modeled post_type 'post' but NOT its public taxonomy 'category'
		// -> undiscovered_taxonomy.
		$warnings = $this->service->validate_discovery_coverage( 1, array(
			array( 'object_name' => 'post', 'object_type' => 'post_type' ),
		) );
		$this->assertContains( 'undiscovered_taxonomy', wp_list_pluck( $warnings, 'code' ) );

		// Modeled taxonomy 'category' but post_type 'post' NOT modeled
		// -> undiscovered_post_type (post shares the modeled taxonomy).
		$warnings = $this->service->validate_discovery_coverage( 1, array(
			array( 'object_name' => 'category', 'object_type' => 'taxonomy' ),
		) );
		$this->assertContains( 'undiscovered_post_type', wp_list_pluck( $warnings, 'code' ) );
	}

	public function test_field_exists_in_database() {
		$meta_key = '_zz_tvs_meta_' . uniqid();
		$this->assertFalse( $this->service->field_exists_in_database( $meta_key ) );

		$post_id = wp_insert_post( array(
			'post_title'   => 'TVS ' . uniqid(),
			'post_content' => 'x',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );
		$this->assertGreaterThan( 0, (int) $post_id );
		$post_id     = (int) $post_id;
		$this->post_ids[] = $post_id;
		update_post_meta( $post_id, $meta_key, 'x' );

		$this->assertTrue( $this->service->field_exists_in_database( $meta_key ) );
	}

	public function test_validate_all_unified_shape_with_real_model() {
		$model_id = $this->create_model();

		$object_id = Model_Object_Service::create_object(
			$model_id,
			'post_type',
			'zz_tvs_pt_' . uniqid(),
			array( 'source_type' => 'manual', 'metadata' => array() )
		);
		$this->assertGreaterThan( 0, (int) $object_id );
		// One valid field and one invalid (id_ref without reference_type).
		Model_Object_Service::add_field( $object_id, 'meta', '_valid_text', 'manual', array() );
		$field_id = Model_Object_Service::insert_field( $object_id, array(
			'field_kind' => 'meta',
			'field_key'  => '_broken_ref',
			'source'     => 'manual',
			'data_type'  => 'id_ref',
		) );
		$this->assertGreaterThan( 0, (int) $field_id );

		$result = $this->service->validate_all( $model_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'valid', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertFalse( $result['valid'] );
		$this->assertContains( 'missing_reference_type', wp_list_pluck( $result['errors'], 'code' ) );
	}
}

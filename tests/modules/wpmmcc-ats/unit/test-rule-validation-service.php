<?php
/**
 * Rule Validation Service Tests
 *
 * L1/L2 for WPTSALL\Models\Services\Rule_Validation_Service:
 * - constants pinned (capability types, non-translatable data types)
 * - validate_rule_structure(): direct-rule matrix for every documented error
 *   code (empty_field_capabilities, unknown_capability_type,
 *   id_mapping_missing_reference, invalid_url_pattern, unknown_rule_data_type)
 *   plus rule_not_found via id lookup
 * - validate_field_coverage(): template fields missing from
 *   field_capabilities are reported; full coverage reports none
 * - validate_all(): unified valid/errors/warnings shape on a seeded rule
 *
 * catalog: WP-CLASS-Rule_Validation_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Services\Model_Object_Service;
use WPTSALL\Models\Services\Rule_Validation_Service;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Rule_Validation_Service extends SimpleTestCase {

	/**
	 * @var Rule_Validation_Service
	 */
	private $service;

	/**
	 * @var array<int, int>
	 */
	private $model_ids = array();

	/**
	 * @var array<int, int>
	 */
	private $rule_ids = array();

	public function setUp(): void {
		parent::setUp();
		$this->service = new Rule_Validation_Service();
	}

	public function tearDown(): void {
		global $wpdb;
		foreach ( $this->rule_ids as $rule_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( wptsall_table( 'translation_rules' ), array( 'id' => $rule_id ), array( '%d' ) );
		}
		$this->rule_ids = array();
		foreach ( $this->model_ids as $model_id ) {
			Translation_Rule_Service::delete_model( $model_id );
		}
		$this->model_ids = array();
		parent::tearDown();
	}

	private function create_model(): int {
		$model_id = Translation_Rule_Service::create_model( array(
			'plugin_slug' => 'rule-validation-' . uniqid(),
			'plugin_name' => 'Rule Validation Test',
			'is_system'   => true,
			'post_types'  => array(),
			'taxonomies'  => array(),
		) );
		$this->assertGreaterThan( 0, (int) $model_id );
		$this->model_ids[] = (int) $model_id;
		return (int) $model_id;
	}

	private function insert_rule_row( int $model_id, string $object_name, array $field_caps, string $url_type = 'single' ): int {
		global $wpdb;
		$wpdb->insert(
			wptsall_table( 'translation_rules' ),
			array(
				'model_id'           => $model_id,
				'name'               => 'RVS Rule ' . uniqid(),
				'url_pattern'        => '/zz-rvs/' . $object_name . '/',
				'url_type'           => $url_type,
				'data_type'          => 'post',
				'object_name'        => $object_name,
				'field_capabilities' => wp_json_encode( $field_caps ),
				'related_taxonomies' => wp_json_encode( array() ),
				'is_active'          => 1,
				'priority'           => 10,
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		$this->assertNotEmpty( $wpdb->insert_id );
		$this->rule_ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	public function test_class_exists_and_constants_pinned() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Rule_Validation_Service' ) );
		$this->assertSame(
			array( 'translate', 'sync', 'id_mapping', 'compute', 'skip' ),
			Rule_Validation_Service::VALID_CAP_TYPES
		);
		$this->assertContains( 'numeric', Rule_Validation_Service::NON_TRANSLATABLE_TYPES );
		$this->assertContains( 'id_ref', Rule_Validation_Service::NON_TRANSLATABLE_TYPES );
	}

	public function test_validate_rule_structure_error_matrix() {
		$base = array(
			'object_name'         => 'zz_pt',
			'url_pattern'         => '/zz/',
			'data_type'           => 'post',
			'field_capabilities' => array( '_ok' => array( 'type' => 'translate' ) ),
		);

		$cases = array(
			'empty capabilities' => array(
				array_merge( $base, array( 'field_capabilities' => array() ) ),
				'empty_field_capabilities',
			),
			'unknown capability' => array(
				array_merge( $base, array( 'field_capabilities' => array( '_bad' => array( 'type' => 'explode' ) ) ) ),
				'unknown_capability_type',
			),
			'id_mapping without reference' => array(
				array_merge( $base, array( 'field_capabilities' => array( '_idm' => array( 'type' => 'id_mapping' ) ) ) ),
				'id_mapping_missing_reference',
			),
			'invalid url pattern' => array(
				array_merge( $base, array( 'url_pattern' => 'no-leading-slash' ) ),
				'invalid_url_pattern',
			),
			'unknown rule data type' => array(
				array_merge( $base, array( 'data_type' => 'wibble' ) ),
				'unknown_rule_data_type',
			),
		);

		foreach ( $cases as $label => $case ) {
			$errors = $this->service->validate_rule_structure( 1, $case[0] );
			$codes  = wp_list_pluck( $errors, 'code' );
			$this->assertContains( $case[1], $codes, "case '{$label}' must produce {$case[1]}: " . wp_json_encode( $errors ) );
		}

		// Fully valid rule produces no errors.
		$this->assertSame( array(), $this->service->validate_rule_structure( 1, $base ) );
	}

	public function test_validate_rule_structure_rule_not_found() {
		$errors = $this->service->validate_rule_structure( 999999999 );
		$this->assertContains( 'rule_not_found', wp_list_pluck( $errors, 'code' ) );
	}

	public function test_validate_field_coverage_reports_gaps() {
		$model_id    = $this->create_model();
		$object_name = 'zz_rvs_pt_' . uniqid();

		$object_id = Model_Object_Service::create_object(
			$model_id,
			'post_type',
			$object_name,
			array( 'source_type' => 'manual', 'metadata' => array() )
		);
		$this->assertGreaterThan( 0, (int) $object_id );
		Model_Object_Service::add_field( $object_id, 'meta', '_covered', 'manual', array() );
		Model_Object_Service::add_field( $object_id, 'meta', '_uncovered', 'manual', array() );

		// Rule covers only _covered: the gap must be reported.
		$rule_id = $this->insert_rule_row( $model_id, $object_name, array(
			'_covered' => array( 'type' => 'translate' ),
		) );
		$errors  = $this->service->validate_field_coverage( $rule_id );
		$codes   = wp_list_pluck( $errors, 'code' );
		$this->assertContains( 'field_not_in_capabilities', $codes );
		$gap = array_values( array_filter( $errors, static function ( $e ) {
			return str_contains( (string) ( $e['message'] ?? '' ), '_uncovered' );
		} ) );
		$this->assertCount( 1, $gap, 'exactly the uncovered field is reported' );

		// Full coverage: no errors. (Second rule for the same object needs a
		// different url_type: unique_rule = model_id+data_type+object_name+url_type.)
		$full_rule_id = $this->insert_rule_row( $model_id, $object_name, array(
			'_covered'   => array( 'type' => 'translate' ),
			'_uncovered' => array( 'type' => 'sync' ),
		), 'archive' );
		$this->assertSame( array(), $this->service->validate_field_coverage( $full_rule_id ) );
	}

	public function test_validate_all_unified_shape() {
		$model_id    = $this->create_model();
		$object_name = 'zz_rvs_all_' . uniqid();
		$rule_id     = $this->insert_rule_row( $model_id, $object_name, array(
			'_f' => array( 'type' => 'explode' ),
		) );

		$result = $this->service->validate_all( $rule_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'valid', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertFalse( $result['valid'] );
		$this->assertContains( 'unknown_capability_type', wp_list_pluck( $result['errors'], 'code' ) );
	}
}

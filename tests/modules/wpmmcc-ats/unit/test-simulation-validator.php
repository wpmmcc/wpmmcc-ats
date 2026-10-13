<?php
/**
 * Simulation Validator Tests
 *
 * Tests for WPTSALL\Models\Services\Simulation_Validator:
 * - probe_real_data(): empty result for unsluggable post types and for
 *   post types with no published posts; against a real published post it
 *   reports the actual fields (columns + meta, WP internals filtered),
 *   computes template/rule coverage and type-reasonableness warnings
 * - validate_template()/validate_full(): delegated validation reports the
 *   documented result shape; missing models produce a non-valid summary
 *
 * catalog: WP-CLASS-Simulation_Validator
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Models\Services\Simulation_Validator;
use WPTSALL\Models\Services\Model_Object_Service;

class Test_Simulation_Validator extends SimpleTestCase {

	/**
	 * @var Simulation_Validator
	 */
	private $validator;

	/**
	 * Object ids created by this run (cleaned in tearDown).
	 *
	 * @var array<int, int>
	 */
	private $created_object_ids = array();

	/**
	 * Translation rule ids created by this run.
	 *
	 * @var array<int, int>
	 */
	private $created_rule_ids = array();

	/**
	 * Test post type registered for this run.
	 *
	 * @var string
	 */
	private $post_type = '';

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_model_tables' ) ) {
			wptsall_create_model_tables();
		}
		if ( function_exists( 'wptsall_create_translation_rules_table' ) ) {
			wptsall_create_translation_rules_table();
		}
		$this->validator = new Simulation_Validator();
	}

	public function tearDown(): void {
		global $wpdb;
		foreach ( $this->created_object_ids as $object_id ) {
			Model_Object_Service::delete_object( $object_id );
		}
		if ( ! empty( $this->created_rule_ids ) ) {
			$rules_table = wptsall_table( 'translation_rules' );
			foreach ( $this->created_rule_ids as $rule_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $rules_table, array( 'id' => $rule_id ), array( '%d' ) );
			}
		}
		$this->created_object_ids = array();
		$this->created_rule_ids   = array();
		parent::tearDown();
	}

	/**
	 * Fresh random-ish model id that must not collide with real models.
	 *
	 * @return int
	 */
	private function fake_model_id(): int {
		return (int) ( '9' . uniqid() );
	}

	/**
	 * Register a per-run post type and create one published post with the
	 * given meta, plus a model_object with template fields and a matching
	 * translation rule row.
	 *
	 * @param array<string,string> $meta          Meta to attach (key => value).
	 * @param array<string,array>  $rule_caps     Rule field_capabilities (key => {type:...}).
	 * @return array{model_id:int, post_id:int, object_id:int}
	 */
	private function build_probe_fixture( array $meta, array $rule_caps ): array {
		$this->post_type = 'zz_sv_' . strtolower( substr( uniqid(), -6 ) );
		register_post_type( $this->post_type, array( 'public' => true, 'label' => 'SV Probe' ) );

		$post_id = self::factory()->post->create( array(
			'post_type'   => $this->post_type,
			'post_status' => 'publish',
			'post_title'  => 'Probe Title ' . uniqid(),
		) );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		// Internal meta that must be excluded from actual_fields.
		update_post_meta( $post_id, '_edit_lock', '1:1' );

		$model_id  = $this->fake_model_id();
		$object_id = Model_Object_Service::create_object( $model_id, 'post_type', $this->post_type, array() );
		$this->created_object_ids[] = (int) $object_id;

		Model_Object_Service::add_field( $object_id, 'core', 'post_title', 'manual', array( 'data_type' => 'text' ) );
		foreach ( array_keys( $meta ) as $meta_key ) {
			Model_Object_Service::add_field( $object_id, 'meta', $meta_key, 'manual', array( 'data_type' => 'text' ) );
		}

		global $wpdb;
		$rules_table = wptsall_table( 'translation_rules' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$rules_table,
			array(
				'model_id'           => $model_id,
				'name'               => 'SV probe rule',
				'url_pattern'        => '/' . $this->post_type . '/{slug}/',
				'url_type'           => 'single',
				'data_type'          => 'post',
				'object_name'        => $this->post_type,
				'field_capabilities' => wp_json_encode( $rule_caps ),
				'related_taxonomies' => '[]',
				'is_active'          => 1,
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			)
		);
		$this->created_rule_ids[] = (int) $wpdb->insert_id;

		return array(
			'model_id'  => $model_id,
			'post_id'   => $post_id,
			'object_id' => (int) $object_id,
		);
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Simulation_Validator' ) );
	}

	public function test_probe_real_data_rejects_unsluggable_post_type() {
		$result = $this->validator->probe_real_data( $this->fake_model_id(), '!!!' );

		$this->assertFalse( $result['has_data'] );
		$this->assertSame( 0, $result['sample_post_id'] );
		$this->assertSame( array(), $result['actual_fields'] );
		$this->assertSame( 0.0, $result['coverage']['template_rate'] );
		$this->assertSame( array(), $result['type_warnings'] );
	}

	public function test_probe_real_data_without_published_posts_is_empty() {
		$result = $this->validator->probe_real_data( $this->fake_model_id(), 'zz_sv_no_such_type_' . strtolower( substr( uniqid(), -4 ) ) );

		$this->assertFalse( $result['has_data'], 'post type with no published posts must report has_data=false' );
		$this->assertSame( 0, $result['sample_post_id'] );
		$this->assertSame( array(), $result['actual_fields'] );
		$this->assertSame( array(), $result['type_warnings'] );
	}

	public function test_probe_real_data_coverage_and_type_warnings() {
		$fixture = $this->build_probe_fixture(
			array(
				'my_meta_num'  => '123',
				'my_meta_long' => str_repeat( 'x', 250 ),
				'my_meta_txt'  => 'plain words',
			),
			array(
				'my_meta_num'  => array( 'type' => 'translate' ),
				'my_meta_long' => array( 'type' => 'sync' ),
			)
		);

		$result = $this->validator->probe_real_data( $fixture['model_id'], $this->post_type );

		$this->assertTrue( $result['has_data'] );
		$this->assertEquals( $fixture['post_id'], $result['sample_post_id'] );

		// Actual fields: post columns + our meta; WP internal meta filtered out.
		$this->assertContains( 'post_title', $result['actual_fields'] );
		$this->assertContains( 'my_meta_num', $result['actual_fields'] );
		$this->assertContains( 'my_meta_txt', $result['actual_fields'] );
		$this->assertNotContains( '_edit_lock', $result['actual_fields'] );

		// Template coverage: all 4 template fields (post_title + 3 metas) are present in actual data.
		$coverage = $result['coverage'];
		$this->assertEquals( 4, $coverage['template_matched'], 'post_title + 3 metas => 4 template keys matched' );
		$this->assertEquals( count( $result['actual_fields'] ), $coverage['template_total'] );
		$this->assertEquals( round( 4 / $coverage['template_total'], 4 ), $coverage['template_rate'] );

		// Rule coverage: 2 of 4 template fields have rule entries (post_title, my_meta_txt have none).
		$this->assertEquals( 2, $coverage['rule_matched'] );
		$this->assertEquals( 4, $coverage['rule_total'] );
		$this->assertEquals( round( 2 / 4, 4 ), $coverage['rule_rate'] );

		// Type reasonableness: numeric translate + long sync warn; plain text does not.
		$warnings = implode( "\n", $result['type_warnings'] );
		$this->assertStringContainsString( 'my_meta_num', $warnings );
		$this->assertStringContainsString( 'my_meta_long', $warnings );
		$this->assertStringNotContainsString( 'my_meta_txt', $warnings );
	}

	public function test_probe_real_data_without_rule_still_reports_template_coverage() {
		$fixture = $this->build_probe_fixture(
			array( 'solo_meta' => 'value' ),
			array()
		);

		$result = $this->validator->probe_real_data( $fixture['model_id'], $this->post_type );

		$this->assertTrue( $result['has_data'] );
		$this->assertGreaterThan( 0, $result['coverage']['template_matched'] );
		$this->assertEquals( 0, $result['coverage']['rule_matched'], 'no rule entries => rule coverage 0' );
		$this->assertEquals( 0.0, $result['coverage']['rule_rate'] );
		$this->assertSame( array(), $result['type_warnings'] );
	}

	public function test_validate_full_on_missing_model_reports_invalid_summary() {
		$result = $this->validator->validate_full( $this->fake_model_id() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'template', $result );
		$this->assertArrayHasKey( 'rules', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertNotEmpty( $result['template']['errors'], 'a non-existent model must surface template errors' );
		$this->assertSame( array(), $result['rules'], 'no rules exist for the fake model' );

		$summary = $result['summary'];
		$this->assertFalse( $summary['valid'] );
		$this->assertGreaterThan( 0, $summary['total_errors'] );
		$this->assertEquals( 0, $summary['rules_checked'] );
		// No objects => no real-data probe attached.
		$this->assertArrayNotHasKey( 'real_data_probe', $result );
	}

	public function test_validate_template_reports_mock_data_shape() {
		$result = $this->validator->validate_template( $this->fake_model_id() );

		$this->assertArrayHasKey( 'valid', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertArrayHasKey( 'simulated_data', $result );
		$this->assertIsArray( $result['simulated_data'] );
		$this->assertFalse( $result['valid'], 'missing model must not validate' );
	}
}

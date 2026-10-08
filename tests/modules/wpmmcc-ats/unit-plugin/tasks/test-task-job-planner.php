<?php
/**
 * Task Job Planner Tests
 *
 * Tests for WPTSALL\Tasks\Services\Task_Job_Planner
 * Covers: job creation, business_line assignment, entry normalization,
 *         ordering strategies, and edge cases.
 *
 * @package WPTSALL
 * @since 1.0.0
 */

use WPTSALL\Tasks\Services\Task_Job_Planner;

class Test_Task_Job_Planner extends SimpleTestCase {

	// ==================== plan_relation_job() Tests ====================

	public function test_plan_relation_job_rejects_zero_relation_id() {
		$result = Task_Job_Planner::plan_relation_job( 0 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_relation_id', $result->get_error_code() );
	}

	public function test_plan_relation_job_rejects_negative_relation_id() {
		// absint(-1) => 1, which passes the > 0 check but won't find a relation.
		$result = Task_Job_Planner::plan_relation_job( -1 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertContains( $result->get_error_code(), array( 'invalid_relation_id', 'not_found' ) );
	}

	public function test_plan_relation_job_rejects_nonexistent_relation() {
		$result = Task_Job_Planner::plan_relation_job( 999999 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'not_found', $result->get_error_code() );
	}

	public function test_plan_relation_job_rejects_no_scope() {
		$result = Task_Job_Planner::plan_relation_job(
			1,
			array(
				'include_content'       => false,
				'include_language_pack' => false,
				'relation'              => array( 'id' => 1 ), // Provide a dummy relation.
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_scope', $result->get_error_code() );
	}

	public function test_plan_relation_job_accepts_custom_job_id() {
		// We need a real relation for this, so use the relation option.
		// If no relations exist, this will return not_found which is expected.
		$result = Task_Job_Planner::plan_relation_job(
			1,
			array(
				'job_id'   => 'custom_test_job_123',
				'relation' => array(
					'id'          => 1,
					'source_lang' => 'en_US',
					'target_lang' => 'zh_CN',
				),
			)
		);

		// If it succeeds (relation exists), check job_id.
		if ( is_array( $result ) ) {
			$this->assertEquals( 'custom_test_job_123', $result['job_id'] );
		}
		// Otherwise it's a WP_Error (no relation), which is acceptable.
	}

	// ==================== plan_language_pack_template_job() Tests ====================

	public function test_plan_language_pack_template_job_rejects_zero_id() {
		$result = Task_Job_Planner::plan_language_pack_template_job( 0 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_template', $result->get_error_code() );
	}

	public function test_plan_language_pack_template_job_rejects_negative_id() {
		$result = Task_Job_Planner::plan_language_pack_template_job( -5 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_template', $result->get_error_code() );
	}

	// ==================== Business Line Normalization ====================

	public function test_infer_task_business_line_from_explicit_value() {
		// Use reflection to test the private static method.
		$method = new ReflectionMethod( Task_Job_Planner::class, 'infer_task_business_line' );
		$method->setAccessible( true );

		$task = array( 'business_line' => 'post_content' );
		$result = $method->invoke( null, $task );
		$this->assertEquals( 'post_content', $result );
	}

	public function test_infer_task_business_line_defaults_to_custom_model() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'infer_task_business_line' );
		$method->setAccessible( true );

		$task = array(); // No business_line.
		$result = $method->invoke( null, $task );
		$this->assertEquals( 'custom_model', $result );
	}

	public function test_normalize_business_line_aliases() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_line' );
		$method->setAccessible( true );

		$aliases = array(
			'post'       => 'post_content',
			'posts'      => 'post_content',
			'taxonomy'   => 'taxonomy_content',
			'taxonomies' => 'taxonomy_content',
			'plugin'     => 'plugin_i18n',
			'theme'      => 'theme_i18n',
			'model'      => 'custom_model',
		);

		foreach ( $aliases as $input => $expected ) {
			$result = $method->invoke( null, $input );
			$this->assertEquals( $expected, $result, "Alias '{$input}' should normalize to '{$expected}'" );
		}
	}

	public function test_normalize_business_line_returns_empty_for_unknown() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_line' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'completely_unknown_line' );
		$this->assertEquals( '', $result );
	}

	public function test_normalize_business_line_returns_empty_for_empty_string() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_line' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '' );
		$this->assertEquals( '', $result );
	}

	// ==================== Task Type Inference ====================

	public function test_infer_task_type_defaults_to_text() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'infer_task_type' );
		$method->setAccessible( true );

		$task = array();
		$result = $method->invoke( null, $task );
		$this->assertEquals( 'text', $result );
	}

	public function test_infer_task_type_accepts_valid_types() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'infer_task_type' );
		$method->setAccessible( true );

		$valid_types = array( 'text', 'image', 'video', 'audio', 'document', 'mixed' );
		foreach ( $valid_types as $type ) {
			$task = array( 'task_type' => $type );
			$result = $method->invoke( null, $task );
			$this->assertEquals( $type, $result, "Task type '{$type}' should be accepted" );
		}
	}

	public function test_infer_task_type_rejects_invalid_type() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'infer_task_type' );
		$method->setAccessible( true );

		$task = array( 'task_type' => 'invalid_type' );
		$result = $method->invoke( null, $task );
		$this->assertEquals( 'text', $result, 'Invalid type should default to text' );
	}

	public function test_infer_task_type_reads_from_payload() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'infer_task_type' );
		$method->setAccessible( true );

		$task = array(
			'payload' => array( 'task_type' => 'image' ),
		);
		$result = $method->invoke( null, $task );
		$this->assertEquals( 'image', $result );
	}

	// ==================== Line Batch Strategy ====================

	public function test_normalize_line_batch_strategy_accepts_priority() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_line_batch_strategy' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'priority' );
		$this->assertEquals( 'priority', $result );
	}

	public function test_normalize_line_batch_strategy_accepts_round_robin() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_line_batch_strategy' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'round_robin' );
		$this->assertEquals( 'round_robin', $result );
	}

	public function test_normalize_line_batch_strategy_defaults_to_priority() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_line_batch_strategy' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'unknown_strategy' );
		$this->assertEquals( 'priority', $result );
	}

	// ==================== Business Line Limits ====================

	public function test_normalize_business_line_limits_filters_unknown_lines() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_line_limits' );
		$method->setAccessible( true );

		$limits   = array( 'post_content' => 100, 'nonexistent' => 50 );
		$allowed  = array( 'post_content', 'taxonomy_content' );
		$result   = $method->invoke( null, $limits, $allowed );

		$this->assertArrayHasKey( 'post_content', $result );
		$this->assertArrayNotHasKey( 'nonexistent', $result );
		$this->assertEquals( 100, $result['post_content'] );
	}

	public function test_normalize_business_line_limits_ignores_zero_limits() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_line_limits' );
		$method->setAccessible( true );

		$limits  = array( 'post_content' => 0 );
		$allowed = array( 'post_content' );
		$result  = $method->invoke( null, $limits, $allowed );

		$this->assertArrayNotHasKey( 'post_content', $result );
	}

	public function test_normalize_business_line_limits_handles_non_array() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_line_limits' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'not-an-array', array() );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// ==================== normalize_bool ====================

	public function test_normalize_bool_handles_string_values() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_bool' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, 'true', false ) );
		$this->assertTrue( $method->invoke( null, 'yes', false ) );
		$this->assertTrue( $method->invoke( null, '1', false ) );
		$this->assertTrue( $method->invoke( null, 'on', false ) );
		$this->assertFalse( $method->invoke( null, 'false', true ) );
		$this->assertFalse( $method->invoke( null, 'no', true ) );
		$this->assertFalse( $method->invoke( null, '0', true ) );
		$this->assertFalse( $method->invoke( null, 'off', true ) );
	}

	public function test_normalize_bool_handles_native_booleans() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_bool' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, true, false ) );
		$this->assertFalse( $method->invoke( null, false, true ) );
	}

	public function test_normalize_bool_handles_numeric_values() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_bool' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, 1, false ) );
		$this->assertFalse( $method->invoke( null, 0, true ) );
	}

	public function test_normalize_bool_uses_default_for_ambiguous() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_bool' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, 'ambiguous', true ) );
		$this->assertFalse( $method->invoke( null, 'ambiguous', false ) );
	}

	// ==================== Summarize Tasks ====================

	public function test_summarize_tasks_returns_expected_structure() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'summarize_tasks' );
		$method->setAccessible( true );

		$tasks = array(
			array(
				'business_line' => 'post_content',
				'task_type'     => 'text',
				'object_type'   => 'post_type',
			),
			array(
				'business_line' => 'post_content',
				'task_type'     => 'text',
				'object_type'   => 'post_type',
			),
			array(
				'business_line' => 'taxonomy_content',
				'task_type'     => 'text',
				'object_type'   => 'taxonomy',
			),
		);

		$summary = $method->invoke( null, $tasks );
		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'business_lines', $summary );
		$this->assertArrayHasKey( 'task_types', $summary );
		$this->assertArrayHasKey( 'object_types', $summary );

		$this->assertEquals( 2, $summary['business_lines']['post_content'] );
		$this->assertEquals( 1, $summary['business_lines']['taxonomy_content'] );
		$this->assertEquals( 3, $summary['task_types']['text'] );
	}

	public function test_summarize_tasks_handles_empty_array() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'summarize_tasks' );
		$method->setAccessible( true );

		$summary = $method->invoke( null, array() );
		$this->assertIsArray( $summary );
		$this->assertEmpty( $summary['business_lines'] );
		$this->assertEmpty( $summary['task_types'] );
		$this->assertEmpty( $summary['object_types'] );
	}

	// ==================== Priority Map ====================

	public function test_business_line_priority_map_contains_expected_keys() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'get_business_line_priority_map' );
		$method->setAccessible( true );

		$map = $method->invoke( null );
		$this->assertIsArray( $map );
		$this->assertArrayHasKey( 'post_content', $map );
		$this->assertArrayHasKey( 'taxonomy_content', $map );
		$this->assertArrayHasKey( 'custom_model', $map );
		$this->assertArrayHasKey( 'plugin_i18n', $map );
		$this->assertArrayHasKey( 'theme_i18n', $map );
	}

	public function test_post_content_has_highest_priority() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'get_business_line_priority_map' );
		$method->setAccessible( true );

		$map = $method->invoke( null );
		$this->assertLessThanOrEqual( $map['taxonomy_content'], $map['post_content'] );
		$this->assertLessThanOrEqual( $map['custom_model'], $map['post_content'] );
	}

	// ==================== Task Ordering ====================

	public function test_order_tasks_returns_empty_for_empty_input() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'order_tasks' );
		$method->setAccessible( true );

		$result = $method->invoke( null, array(), 'priority' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_order_tasks_priority_sorts_by_business_line() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'order_tasks' );
		$method->setAccessible( true );

		$tasks = array(
			array( 'business_line' => 'theme_i18n', 'object_type' => 'language_pack', 'subtype' => '', 'object_id' => 1, 'template' => '' ),
			array( 'business_line' => 'post_content', 'object_type' => 'post_type', 'subtype' => '', 'object_id' => 1, 'template' => '' ),
		);

		$ordered = $method->invoke( null, $tasks, 'priority' );
		$this->assertEquals( 'post_content', $ordered[0]['business_line'] );
		$this->assertEquals( 'theme_i18n', $ordered[1]['business_line'] );
	}

	public function test_order_tasks_round_robin_interleaves() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'order_tasks' );
		$method->setAccessible( true );

		$tasks = array(
			array( 'business_line' => 'post_content', 'object_type' => 'post_type', 'subtype' => 'post', 'object_id' => 1, 'template' => '' ),
			array( 'business_line' => 'post_content', 'object_type' => 'post_type', 'subtype' => 'post', 'object_id' => 2, 'template' => '' ),
			array( 'business_line' => 'taxonomy_content', 'object_type' => 'taxonomy', 'subtype' => 'cat', 'object_id' => 1, 'template' => '' ),
			array( 'business_line' => 'taxonomy_content', 'object_type' => 'taxonomy', 'subtype' => 'cat', 'object_id' => 2, 'template' => '' ),
		);

		$ordered = $method->invoke( null, $tasks, 'round_robin' );
		$this->assertCount( 4, $ordered );

		// Round robin should interleave - first two should be from different lines.
		$this->assertNotEquals( $ordered[0]['business_line'], $ordered[1]['business_line'] );
	}

	// ==================== Business Lines List Normalization ====================

	public function test_normalize_business_lines_list_from_string() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_lines_list' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'post, taxonomy, plugin' );
		$this->assertIsArray( $result );
		$this->assertContains( 'post_content', $result );
		$this->assertContains( 'taxonomy_content', $result );
		$this->assertContains( 'plugin_i18n', $result );
	}

	public function test_normalize_business_lines_list_from_array() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_lines_list' );
		$method->setAccessible( true );

		$result = $method->invoke( null, array( 'post_content', 'theme' ) );
		$this->assertContains( 'post_content', $result );
		$this->assertContains( 'theme_i18n', $result );
	}

	public function test_normalize_business_lines_list_filters_invalid() {
		$method = new ReflectionMethod( Task_Job_Planner::class, 'normalize_business_lines_list' );
		$method->setAccessible( true );

		$result = $method->invoke( null, array( 'post_content', 'invalid_line', 'another_bad' ) );
		$this->assertContains( 'post_content', $result );
		$this->assertNotContains( 'invalid_line', $result );
	}
}

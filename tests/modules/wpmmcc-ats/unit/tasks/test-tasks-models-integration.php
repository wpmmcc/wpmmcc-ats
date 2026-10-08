<?php
/**
 * Tasks-Models Integration Tests
 *
 * Tests for the integration between Tasks module and Models module,
 * specifically focusing on Model_Config_Provider API usage.
 *
 * @package WPTSALL\Tests\Unit\Tasks
 * @since 0.9.2
 */

use WPTSALL\Tasks\Services\Task_Orchestrator;
use WPTSALL\Models\Services\Model_Config_Provider;
use WPTSALL\Models\Services\Translation_Rule_Service;

class Test_Tasks_Models_Integration extends SimpleTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any filter hooks
		remove_all_filters( 'wptsall_task_type_priority' );
		remove_all_filters( 'wptsall_field_needs_translation' );
	}

	// ==================== Module Dependency Tests ====================

	/**
	 * Test Models module classes are available
	 */
	public function test_models_module_classes_available() {
		$classes = array(
			'WPTSALL\Models\Services\Model_Config_Provider',
			'WPTSALL\Models\Services\Translation_Rule_Service',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Models class {$class} should be available"
			);
		}
	}

	/**
	 * Test Tasks module classes are available
	 */
	public function test_tasks_module_classes_available() {
		$classes = array(
			'WPTSALL\Tasks\Services\Task_Orchestrator',
			'WPTSALL\Tasks\Sync\Sync_Executor',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Tasks class {$class} should be available"
			);
		}
	}

	// ==================== API Interface Tests ====================

	/**
	 * Test Translation_Rule_Service has get_merged_config method
	 */
	public function test_translation_rule_service_has_get_merged_config() {
		$this->assertTrue(
			method_exists( Translation_Rule_Service::class, 'get_merged_config' ),
			"Translation_Rule_Service should have get_merged_config method"
		);
	}

	// ==================== Task_Orchestrator Priority Tests ====================

	/**
	 * Test Task_Orchestrator type priority filter
	 */
	public function test_task_orchestrator_priority_filter() {
		// Add custom priority via filter
		add_filter(
			'wptsall_task_type_priority',
			function ( $priorities ) {
				$priorities['event']   = 5;
				$priorities['listing'] = 8;
				return $priorities;
			}
		);

		// Create mock tasks to test sorting
		$tasks = array(
			array(
				'post_type' => 'event',
				'data_type' => 'post',
			),
			array(
				'post_type' => 'post',
				'data_type' => 'post',
			),
			array(
				'post_type' => 'page',
				'data_type' => 'post',
			),
		);

		// Orchestrate should sort by priority
		$sorted = Task_Orchestrator::orchestrate( $tasks );

		// Verify order: post (1) < page (2) < event (5)
		$this->assertEquals( 'post', $sorted[0]['post_type'], "post should be first (priority 1)" );
		$this->assertEquals( 'page', $sorted[1]['post_type'], "page should be second (priority 2)" );
		$this->assertEquals( 'event', $sorted[2]['post_type'], "event should be third (priority 5)" );

		// Clean up
		remove_all_filters( 'wptsall_task_type_priority' );
	}

	/**
	 * Test default type priorities
	 */
	public function test_default_type_priorities() {
		$tasks = array(
			array(
				'post_type' => 'custom_type',
				'data_type' => 'post',
			),
			array(
				'post_type' => 'post',
				'data_type' => 'post',
			),
			array(
				'post_type'   => '',
				'data_type'   => 'term',
				'object_type' => 'taxonomy',
			),
		);

		$sorted = Task_Orchestrator::orchestrate( $tasks );

		// post (1) < custom_type (10 default) < taxonomy (20)
		$this->assertEquals( 'post', $sorted[0]['post_type'], "post should be first" );
		$this->assertEquals( 'custom_type', $sorted[1]['post_type'], "custom_type should be second" );
		$this->assertEquals( 'term', $sorted[2]['data_type'], "taxonomy should be last" );
	}

	// ==================== Config-Driven Sync Tests ====================

	/**
	 * Test Translation_Rule_Service get_merged_config is callable
	 */
	public function test_get_merged_config_is_callable() {
		// Test that method is callable without errors
		// Will return empty/default for non-existent rules
		$result = Translation_Rule_Service::get_merged_config( 0 );

		// Should return an array (possibly empty)
		$this->assertTrue(
			is_array( $result ) || is_null( $result ),
			"get_merged_config should return array or null"
		);
	}

}

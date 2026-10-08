<?php
/**
 * Flow: Full E2E Pipeline via Client Simulator
 *
 * Tests the complete Path B pipeline:
 *   seeded source post → create relation → monitor/start → client pull tasks
 *   → mock translation callback → verify target content written
 *
 * Uses WPTSALL_Client_Simulator for offline, no-network task processing.
 * Requires seeding to have run (products, bbpress topics available).
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';
require_once dirname( __DIR__ ) . '/simulators/client-simulator.php';

/**
 * Test_Flow_E2E_Client_Simulator
 *
 * Verifies the full translation pipeline from source content to target site.
 */
class Test_Flow_E2E_Client_Simulator extends REST_Integration_Test_Case {

	/** @var bool Whether this suite can run. */
	private static $chain_runnable = true;

	/** @var string Reason for skipping. */
	private static $skip_reason = '';

	/** @var int Created virtual site ID */
	private int $vs_id = 0;

	/** @var int Created site relation ID */
	private int $relation_id = 0;

	/** @var int Source post ID (created in test) */
	private int $source_post_id = 0;

	/** @var WPTSALL_Client_Simulator|null */
	private ?WPTSALL_Client_Simulator $simulator = null;

	// =========================================================================
	// Setup / Teardown
	// =========================================================================

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace not registered';
			return;
		}

		if ( ! function_exists( 'wptsall_table' ) || ! function_exists( 'wptsall_get_client_api_token' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'Required wptsall functions not available';
			return;
		}

		if ( ! class_exists( 'WPTSALL_Client_Simulator' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'WPTSALL_Client_Simulator class not found';
		}
	}

	public function setUp(): void {
		parent::setUp();

		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}

		// Create virtual site (zh_CN target).
		$this->vs_id = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$this->assertGreaterThan( 0, $this->vs_id, 'Virtual site must be created' );

		// Create relation using wordpress-blog model (covers post/page).
		$relation_data     = $this->create_test_relation( $this->vs_id, 'wordpress-blog' );
		$this->relation_id = (int) ( $relation_data['relation_ids'][0] ?? 0 );
		$this->assertGreaterThan( 0, $this->relation_id, 'Site relation must be created' );

		// Re-register dynamic hooks so new relation is wired up.
		if ( class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			\WPTSALL\Hooks\Hook_Manager::register_dynamic_hooks();
		}

		// Instantiate the offline client simulator.
		$this->simulator = new WPTSALL_Client_Simulator();
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Full text translation pipeline:
	 * create post → monitor/start → pull tasks → callback → verify translation_results
	 */
	public function test_text_translation_full_pipeline() {
		global $wpdb;

		// 1. Create a source post.
		$this->source_post_id = $this->create_test_post( array(
			'post_title'   => 'E2E Pipeline Test Post ' . wp_rand(),
			'post_content' => 'Full pipeline content for E2E verification.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );
		$this->assertGreaterThan( 0, $this->source_post_id, 'Source post must be created' );

		// 2. Create an explicit sync task for the simulator to claim.
		$sync_task_id = \WPTSALL\Tasks\Services\Monitoring_Task_Service::create_sync_task(
			$this->source_post_id,
			$this->relation_id
		);
		$this->assertGreaterThan( 0, (int) $sync_task_id, 'A sync task must be created for client simulator flow' );

		// DB assertion: created task is visible and claimable by /client/tasks.
		$tasks_table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tasks_table} WHERE id = %d AND type = 'sync' AND status IN ('pending','retry','processing','active')",
				$sync_task_id
			)
		);
		$this->assertGreaterThan(
			0,
			$pending_count,
			'At least one sync task must exist in claimable status before simulator pull'
		);

		// 3. Run the offline client simulator: pull tasks + submit callbacks.
		$results = $this->simulator->pull_and_process_tasks( 100 );

		$this->assertIsArray( $results, 'Simulator must return an array of results' );
		$this->assertNotEmpty( $results, 'Simulator must have processed at least one task' );

		$target_result = $this->find_result_for_task( $results, (int) $sync_task_id );
		if ( null === $target_result ) {
			$target_result = $this->simulator->process_task_by_id( (int) $sync_task_id );
			$results[]     = $target_result;
		}
		$this->assertNotNull(
			$target_result,
			'Simulator must process the expected sync task. Results: ' . wp_json_encode( $results )
		);
		$this->assertEquals(
			200,
			$target_result['http_status'] ?? 0,
			'Expected task callback must return HTTP 200. Result: ' . wp_json_encode( $target_result )
		);

		// 4. DB assertion: translation_results must have a record with 【zh_CN】 marker.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tr_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$results_table}
				 WHERE relation_id = %d
				 ORDER BY id DESC LIMIT 1",
				$this->relation_id
			),
			ARRAY_A
		);

		$this->assertNotNull(
			$tr_row,
			'translation_results must contain a row for this relation after callback. relation_id='
			. $this->relation_id
			. ', task_id=' . $sync_task_id
			. ', callback=' . wp_json_encode( $target_result )
		);

		$translated_fields_json = (string) ( $tr_row['translated_fields'] ?? ( $tr_row['stored_fields'] ?? '{}' ) );
		$stored                 = json_decode( $translated_fields_json, true );
		$this->assertIsArray( $stored, 'translated_fields/stored_fields must be valid JSON' );

		// Find at least one translated value with the mock marker.
		$has_marker = $this->array_contains_translation_marker( $stored );
		$this->assertTrue(
			$has_marker,
			'At least one value in translated_fields/stored_fields must contain the 【lang】 translation marker. stored='
			. wp_json_encode( $stored )
		);

		// 5. Functional field assertion: post_name must NOT have marker.
		if ( isset( $stored['post_name'] ) ) {
			$this->assertStringNotContainsString(
				'【',
				(string) $stored['post_name'],
				'Functional field post_name must not contain translation markers'
			);
		}
	}

	/**
	 * End-to-end idempotency: running simulator twice yields same translation_results count.
	 */
	public function test_double_run_idempotency() {
		global $wpdb;

		// Create a post and a task.
		$this->source_post_id = $this->create_test_post( array(
			'post_title'  => 'Idempotency E2E Test ' . wp_rand(),
			'post_status' => 'publish',
		) );

		$sync_task_id = \WPTSALL\Tasks\Services\Monitoring_Task_Service::create_sync_task(
			$this->source_post_id,
			$this->relation_id
		);
		$this->assertGreaterThan( 0, (int) $sync_task_id, 'Idempotency test requires a created sync task' );

		// First simulator run.
		$results1 = $this->simulator->pull_and_process_tasks();
		$target_result1 = $this->find_result_for_task( $results1, (int) $sync_task_id );
		if ( null === $target_result1 ) {
			$target_result1 = $this->simulator->process_task_by_id( (int) $sync_task_id );
			$results1[]     = $target_result1;
		}
		$this->assertNotNull(
			$target_result1,
			'First run must process expected task. Results: ' . wp_json_encode( $results1 )
		);
		$this->assertEquals(
			200,
			$target_result1['http_status'] ?? 0,
			'First run callback must return HTTP 200. Result: ' . wp_json_encode( $target_result1 )
		);

		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after_first = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$results_table} WHERE relation_id = %d",
				$this->relation_id
			)
		);
		$this->assertGreaterThan( 0, $count_after_first, 'First run must write to translation_results' );

		// Second simulator run (same tasks, should be idempotent via client_task_id).
		$results2 = $this->simulator->pull_and_process_tasks();
		$this->assertIsArray( $results2, 'Second run must return an array result' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after_second = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$results_table} WHERE relation_id = %d",
				$this->relation_id
			)
		);

		// DB assertion: count must not have increased.
		$this->assertEquals(
			$count_after_first,
			$count_after_second,
			'Duplicate simulator run must not add new rows to translation_results'
		);
	}

	/**
	 * Cross-module: WooCommerce product translation (if WooCommerce is active).
	 * Scan model → create relation → create product post → monitor → simulate → verify.
	 */
	public function test_woocommerce_product_translation_pipeline() {
		if ( ! function_exists( 'wc_create_product' ) && ! class_exists( 'WC_Product' ) ) {
			$this->markTestSkipped( 'WooCommerce not active; skipping product pipeline test' );
		}

		global $wpdb;

		// Check if WooCommerce model exists, scan if not.
		$models_table = wptsall_table( 'models' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wc_model_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$models_table} WHERE plugin_slug = %s LIMIT 1",
				'woocommerce'
			)
		);

		if ( ! $wc_model_id ) {
			// Scan WooCommerce model.
			$scan_response = $this->rest_post( 'models/scan', array( 'plugin_slug' => 'woocommerce' ) );
			$this->assertRestSuccess( $scan_response, 200 );
			$scan_data  = $this->get_response_data( $scan_response );
			$wc_model_id = (int) ( $scan_data['model']['id'] ?? $scan_data['id'] ?? 0 );
		}

		$this->assertGreaterThan( 0, $wc_model_id, 'WooCommerce model must exist after scan' );

		// DB assertion: model_objects populated for WooCommerce.
		$objects_table = wptsall_table( 'model_objects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$obj_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$objects_table} WHERE model_id = %d",
				$wc_model_id
			)
		);
		$this->assertGreaterThan( 0, $obj_count, 'WooCommerce model must have model_objects after scan' );

		// Create a WooCommerce product post.
		$product_id = wp_insert_post( array(
			'post_title'   => 'E2E Test Product ' . wp_rand(),
			'post_content' => 'Product description for pipeline test.',
			'post_status'  => 'publish',
			'post_type'    => 'product',
			'post_author'  => $this->admin_user_id,
		) );
		$this->assertGreaterThan( 0, $product_id, 'Product post must be created' );
		$this->track_resource( 'posts', $product_id );

		// Create a WooCommerce-specific relation.
		$wc_vs_id = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$wc_relation_data = $this->create_test_relation( $wc_vs_id, 'woocommerce' );
		$wc_relation_id   = (int) ( $wc_relation_data['relation_ids'][0] ?? 0 );
		$this->assertGreaterThan( 0, $wc_relation_id, 'WooCommerce relation must be created' );

		$wc_sync_task_id = \WPTSALL\Tasks\Services\Monitoring_Task_Service::create_sync_task(
			$product_id,
			$wc_relation_id
		);
		$this->assertGreaterThan( 0, (int) $wc_sync_task_id, 'WooCommerce relation must produce a sync task' );

		// Simulate translation.
		$results = $this->simulator->pull_and_process_tasks( 100 );
		$this->assertNotEmpty( $results, 'Simulator must process WooCommerce product tasks' );

		$wc_target_result = $this->find_result_for_task( $results, (int) $wc_sync_task_id );
		if ( null === $wc_target_result ) {
			$wc_target_result = $this->simulator->process_task_by_id( (int) $wc_sync_task_id );
			$results[]        = $wc_target_result;
		}
		$this->assertNotNull(
			$wc_target_result,
			'Simulator must process WooCommerce target task. Results: ' . wp_json_encode( $results )
		);
		$this->assertEquals(
			200,
			$wc_target_result['http_status'] ?? 0,
			'WooCommerce task callback must return HTTP 200. Result: ' . wp_json_encode( $wc_target_result )
		);

		// DB assertion: translation_results has a row for the WooCommerce relation.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wc_result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$results_table}
				 WHERE relation_id = %d ORDER BY id DESC LIMIT 1",
				$wc_relation_id
			),
			ARRAY_A
		);
		$this->assertNotNull(
			$wc_result,
			'WooCommerce product translation_results must have a row. relation_id='
			. $wc_relation_id
			. ', task_id=' . $wc_sync_task_id
			. ', callback=' . wp_json_encode( $wc_target_result )
		);
	}

	/**
	 * Find one processing result by task_id.
	 *
	 * @param array $results Result rows returned by simulator.
	 * @param int   $task_id Expected task id.
	 * @return array|null
	 */
	private function find_result_for_task( array $results, int $task_id ): ?array {
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			if ( $task_id === (int) ( $result['task_id'] ?? 0 ) ) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * Recursively detect translation markers in nested arrays.
	 *
	 * @param mixed $value Value or array.
	 * @return bool
	 */
	private function array_contains_translation_marker( $value ): bool {
		if ( is_string( $value ) ) {
			return strpos( $value, '【' ) !== false;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( $this->array_contains_translation_marker( $item ) ) {
				return true;
			}
		}

		return false;
	}
}

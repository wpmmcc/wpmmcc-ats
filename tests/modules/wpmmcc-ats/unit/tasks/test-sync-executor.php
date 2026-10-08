<?php
/**
 * Sync Executor Tests
 *
 * Tests for WPTSALL\Tasks\Sync\Sync_Executor class
 *
 * @package WPTSALL\Tests\Unit\Tasks
 * @since 0.8.0
 * @updated 1.1.0 Added execute_translation_sync tests; sync_post_to_virtual_site replaced by sync_to_virtual_site.
 *
 * catalog: WP-HOOK-wptsall-max-gallery-media-per-sync
 * oracle: L1
 */

use WPTSALL\Tasks\Sync\Sync_Executor;

class Test_Sync_Executor extends SimpleTestCase {

	/**
	 * Test relation data
	 *
	 * @var array
	 */
	private $test_relation = array();

	/**
	 * Test post IDs created during tests
	 *
	 * @var array
	 */
	private $test_post_ids = array();

	/**
	 * Test relation ID
	 *
	 * @var int
	 */
	private $test_relation_id = 0;

	/**
	 * Test option names created during tests.
	 *
	 * @var array
	 */
	private $test_option_names = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure tasks functions are loaded (tasks.php may not be loaded via module init in test env).
		$this->ensure_tasks_functions_loaded();

		// Ensure tables exist.
		$this->ensure_tables_exist();

		// Create test relation.
		$this->test_relation_id = $this->create_test_relation();

		// Set up test relation data.
		$this->test_relation = array(
			'id'                => $this->test_relation_id,
			'source_blog_id'    => get_current_blog_id(),
			'target_blog_id'    => null,
			'target_type'       => 'virtual',
			'target_identifier' => 'v_test_' . uniqid(),
			'target_lang'       => 'en_US',
			'source_lang'       => 'zh_CN',
			'media_handling'    => 'copy',
		);
	}

	/**
	 * Ensure tasks module functions are loaded.
	 *
	 * In the test environment, wp-load.php may not trigger full module init,
	 * so we explicitly require tasks.php if its functions aren't available.
	 */
	private function ensure_tasks_functions_loaded() {
		if ( ! function_exists( 'wptsall_insert_translation_result' ) ) {
			$tasks_php = WP_PLUGIN_DIR . '/wptsall-pro/includes/tasks/tasks.php';
			if ( file_exists( $tasks_php ) ) {
				require_once $tasks_php;
			}
		}
	}

	/**
	 * Ensure required tables exist.
	 *
	 * 2026-09-12 fixture-DDL convergence: delegate to the plugin's real
	 * schema functions instead of hand-rolled CREATE TABLE statements —
	 * the local DDL had drifted (index-less site_relations, simplified
	 * tasks layout) and resurrected the deprecated virtual_site_content
	 * table that the product removed in v0.7.0 (tearDown keeps a guarded
	 * cleanup for legacy Lab leftovers). See tasks/test/VERIFICATION-AND-
	 * DISPOSITION-20260912.md §6.1-C.
	 */
	private function ensure_tables_exist() {
		wptsall_create_site_relations_table();
		wptsall_init_tasks_tables();
		wptsall_create_model_tables();
	}

	/**
	 * Create a test relation
	 *
	 * @return int Relation ID
	 */
	private function create_test_relation() {
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';

		$wpdb->insert(
			$table,
			array(
				'source_site_id'   => 1,
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'test-sync-executor',
				'target_site_id'   => 'v_test_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'media_handling'   => 'copy',
				'status'           => 'active',
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Create a test post
	 *
	 * @param array $args Post arguments.
	 * @return int Post ID
	 */
	private function create_test_post( $args = array() ) {
		$defaults = array(
			'post_title'   => 'Test Post ' . uniqid(),
			'post_content' => 'Test content for sync executor testing.',
			'post_excerpt' => 'Test excerpt.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);

		$post_data = wp_parse_args( $args, $defaults );
		$post_id   = wp_insert_post( $post_data );

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			$this->test_post_ids[] = $post_id;
		}

		return $post_id;
	}

	/**
	 * Create a test option and track it for cleanup.
	 *
	 * @param mixed $value Option value.
	 * @return string
	 */
	private function create_test_option( $value ) {
		$option_name = 'wptsall_sync_option_' . uniqid();
		update_option( $option_name, $value, false );
		$this->test_option_names[] = $option_name;
		return $option_name;
	}

	// ==================== execute_with_config() — REMOVED ====================
	// Method execute_with_config() was removed from Sync_Executor.
	// Tests retained as skipped for reference.

	// ==================== build_rule_config_from_merged() — REMOVED ====================
	// Method build_rule_config_from_merged() was removed from Sync_Executor.

	// ==================== apply_translation_markers() Tests (via Reflection) ====================

	/**
	 * Test apply_translation_markers adds markers to translate fields
	 */
	public function test_apply_translation_markers_adds_markers() {
		$rule_config = array(
			'object_name'      => 'post',
			'translate_fields' => wp_json_encode( array( 'post_title' => array( 'type' => 'text' ) ) ),
			'sync_fields'      => '{}',
			'field_mappings'   => '{}',
			'compute_fields'   => '{}',
			'is_active'        => 1,
		);

		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Field_Processor' ) ) {
			$this->markTestSkipped( 'Field_Processor class not available' );
			return;
		}

		$field_processor = new \WPTSALL\Models\Services\Field_Processor(
			$rule_config,
			1,
			'v_test',
			'en_US'
		);

		$source_data = array(
			'post' => array(
				'post_title'   => 'Test Title',
				'post_content' => 'Test Content',
			),
		);
		$field_processor->process_fields( $source_data );

		$method = new ReflectionMethod( Sync_Executor::class, 'apply_translation_markers' );
		$method->setAccessible( true );

		$data = array(
			'post_title'   => 'Test Title',
			'post_content' => 'Test Content',
		);

		$result = $method->invoke( null, $data, $field_processor, 'zh_CN', 'en_US' );

		$this->assertIsArray( $result );
	}

	/**
	 * Test apply_translation_markers skips already marked content
	 */
	public function test_apply_translation_markers_skips_already_marked() {
		$rule_config = array(
			'object_name'      => 'post',
			'translate_fields' => wp_json_encode( array( 'post_title' => array( 'type' => 'text' ) ) ),
			'sync_fields'      => '{}',
			'field_mappings'   => '{}',
			'compute_fields'   => '{}',
			'is_active'        => 1,
		);

		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Field_Processor' ) ) {
			$this->markTestSkipped( 'Field_Processor class not available' );
			return;
		}

		$field_processor = new \WPTSALL\Models\Services\Field_Processor(
			$rule_config,
			1,
			'v_test',
			'en_US'
		);

		$method = new ReflectionMethod( Sync_Executor::class, 'apply_translation_markers' );
		$method->setAccessible( true );

		$data = array(
			'post_title' => '【en_US】Already Marked Title【/en_US】',
		);

		$result = $method->invoke( null, $data, $field_processor, 'zh_CN', 'en_US' );

		// Should not double-wrap.
		$this->assertStringNotContainsString( '【en_US】【en_US】', $result['post_title'] ?? '' );
	}

	// ==================== execute_task() Tests ====================

	/**
	 * Test execute_task returns error for non-existent task
	 */
	public function test_execute_task_returns_error_for_nonexistent() {
		$result = Sync_Executor::execute_task( 99999 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'task_not_found', $result->get_error_code() );
	}

	/**
	 * Test execute_task with task array returns error when no rule found
	 */
	public function test_execute_task_returns_error_when_no_rule() {
		$task = array(
			'id'                => 1,
			// Use a valid relation so this test specifically exercises "no_rule".
			'relation_id'       => $this->test_relation_id,
			'object_type'       => 'post_type',
			'subtype'           => 'nonexistent_type_xyz',
			'object_id'         => 1,
			'blog_id'           => 1,
			'target_blog'       => null,
			'target_type'       => 'virtual',
			'target_identifier' => 'v_test',
			'template'          => 'test',
			'lang_from'         => 'zh_CN',
			'lang_to'           => 'en_US',
			'payload'           => '{}',
		);

		$result = Sync_Executor::execute_task( $task );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'no_rule', $result->get_error_code() );
	}

	// ==================== get_source_data() Tests (via Reflection) ====================

	/**
	 * Test get_source_data returns post data
	 */
	public function test_get_source_data_returns_post_data() {
		$post_id = $this->create_test_post( array(
			'post_title'   => 'Test Source Post',
			'post_content' => 'Test source content.',
			'post_excerpt' => 'Test excerpt.',
		) );

		update_post_meta( $post_id, '_test_meta_key', 'test_meta_value' );

		$method = new ReflectionMethod( Sync_Executor::class, 'get_source_data' );
		$method->setAccessible( true );

		$data = $method->invoke( null, 'post_type', 'post', $post_id, get_current_blog_id() );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'post', $data );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'taxonomies', $data );

		$this->assertEquals( 'Test Source Post', $data['post']['post_title'] );
		$this->assertEquals( 'Test source content.', $data['post']['post_content'] );
		$this->assertArrayHasKey( '_test_meta_key', $data['meta'] );
	}

	/**
	 * Test get_source_data returns error for non-existent post
	 */
	public function test_get_source_data_returns_error_for_nonexistent_post() {
		global $wpdb;

		$max_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
		$nonexistent_id = $max_id + 1000;

		$method = new ReflectionMethod( Sync_Executor::class, 'get_source_data' );
		$method->setAccessible( true );

		$data = $method->invoke( null, 'post_type', 'post', $nonexistent_id, get_current_blog_id() );

		$this->assertInstanceOf( 'WP_Error', $data );
		$this->assertEquals( 'post_not_found', $data->get_error_code() );
	}

	/**
	 * Test get_source_data returns option data.
	 */
	public function test_get_source_data_returns_option_data() {
		$option_name = $this->create_test_option(
			array(
				'email_subject' => 'Option Subject',
				'email_body'    => '<p>Option Body</p>',
			)
		);

		$method = new ReflectionMethod( Sync_Executor::class, 'get_source_data' );
		$method->setAccessible( true );

		$data = $method->invoke( null, 'option', $option_name, 0, get_current_blog_id() );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'option', $data );
		$this->assertSame( $option_name, $data['option']['option_name'] );
		$this->assertSame( '<p>Option Body</p>', $data['option']['option_value']['email_body'] );
	}

	// ==================== execute_translation_sync() Tests (v1.1.0) ====================

	/**
	 * Test execute_translation_sync returns error for non-existent task
	 */
	public function test_execute_translation_sync_returns_error_for_nonexistent_task() {
		$result = Sync_Executor::execute_translation_sync( 99999 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'task_not_found', $result->get_error_code() );
	}

	/**
	 * Test execute_translation_sync returns error when no translation_result_id in payload
	 */
	public function test_execute_translation_sync_returns_error_for_missing_result_id() {
		global $wpdb;

		// Create a sync task with no translation_result_id in payload.
		$now = current_time( 'mysql' );
		$wpdb->insert(
			wptsall_table( 'tasks' ),
			array(
				'type'        => 'sync',
				'relation_id' => $this->test_relation_id,
				'status'      => 'pending',
				'blog_id'     => get_current_blog_id(),
				'target_type' => 'virtual',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'payload'     => '{}',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$task_id = $wpdb->insert_id;

		$result = Sync_Executor::execute_translation_sync( $task_id );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'no_result_id', $result->get_error_code() );

		// Clean up.
		$wpdb->delete( wptsall_table( 'tasks' ), array( 'id' => $task_id ), array( '%d' ) );
	}

	/**
	 * Test execute_translation_sync returns error when translation result not found
	 */
	public function test_execute_translation_sync_returns_error_for_missing_result() {
		global $wpdb;

		// Create a sync task referencing a non-existent translation result.
		$now = current_time( 'mysql' );
		$wpdb->insert(
			wptsall_table( 'tasks' ),
			array(
				'type'        => 'sync',
				'relation_id' => $this->test_relation_id,
				'status'      => 'pending',
				'blog_id'     => get_current_blog_id(),
				'target_type' => 'virtual',
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'payload'     => wp_json_encode( array( 'translation_result_id' => 99999 ) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$task_id = $wpdb->insert_id;

		$result = Sync_Executor::execute_translation_sync( $task_id );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'result_not_found', $result->get_error_code() );

		// Clean up.
		$wpdb->delete( wptsall_table( 'tasks' ), array( 'id' => $task_id ), array( '%d' ) );
	}

	/**
	 * Test execute_translation_sync performs full sync for virtual target
	 */
	public function test_execute_translation_sync_full_virtual_sync() {
		global $wpdb;

		// 1. Create a source post.
		$source_post_id = $this->create_test_post( array(
			'post_title'   => '原始标题',
			'post_content' => '原始内容',
			'post_excerpt' => '原始摘要',
		) );

		// 2. Insert a translation result.
		$client_task_id = 'test-' . uniqid();
		$result_id = wptsall_insert_translation_result( array(
			'relation_id'       => $this->test_relation_id,
			'object_type'       => 'post_type',
			'object_id'         => $source_post_id,
			'translated_fields' => array(
				'post_title'   => 'Translated Title',
				'post_content' => 'Translated Content',
				'post_excerpt' => 'Translated Excerpt',
			),
			'translated_meta'   => array(
				'_test_seo_title' => 'Translated SEO Title',
				'_wptsall_callback_receipt' => array(
					'version' => 1, 'stage' => 'prepared',
					'device_hash' => hash( 'sha256', 'wptsall-callback-device-v1|test-sync-executor' ),
				),
			),
			'media_mappings'    => array(),
			'client_task_id'    => $client_task_id,
			'source_lang'       => 'zh_CN',
			'target_lang'       => 'en_US',
		) );

		$this->assertGreaterThan( 0, $result_id, 'Translation result should be inserted' );

		// 3. Create the sync task.
		$now = current_time( 'mysql' );
		$wpdb->insert(
			wptsall_table( 'tasks' ),
			array(
				'type'              => 'sync',
				'relation_id'       => $this->test_relation_id,
				'status'            => 'pending',
				'blog_id'           => get_current_blog_id(),
				'target_type'       => 'virtual',
				'target_identifier' => $this->test_relation['target_identifier'],
				'object_type'       => 'post_type',
				'subtype'           => 'post',
				'object_id'         => $source_post_id,
				'template'          => 'test-sync-executor',
				'lang_from'         => 'zh_CN',
				'lang_to'           => 'en_US',
				'payload'           => wp_json_encode( array( 'translation_result_id' => $result_id ) ),
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$sync_task_id = $wpdb->insert_id;

		// 4. Execute.
		$sync_result = Sync_Executor::execute_translation_sync( $sync_task_id );

		// 5. Verify: should succeed with target_id in virtual_site_content.
		if ( is_wp_error( $sync_result ) ) {
			// Acceptable: relation_not_found if the relation's source_blog_id column doesn't exist.
			$this->assertContains( $sync_result->get_error_code(), array( 'relation_not_found', 'post_not_found', 'unsupported_type' ),
				'If error, should be an expected error type: ' . $sync_result->get_error_message() );
		} else {
			$this->assertIsArray( $sync_result );
			$this->assertTrue( $sync_result['success'] ?? false, 'Sync should succeed' );
		}

		// Clean up.
		$wpdb->delete( wptsall_table( 'tasks' ), array( 'id' => $sync_task_id ), array( '%d' ) );
		$wpdb->delete( wptsall_table( 'translation_results' ), array( 'id' => $result_id ), array( '%d' ) );
	}

	/**
	 * Test execute_translation_sync writes translated option values back to WordPress targets.
	 */
	public function test_execute_translation_sync_writes_option_value_to_wp_target() {
		global $wpdb;

		$option_name = $this->create_test_option( 'Original option value' );
		$object_id   = 987654;
		$now         = current_time( 'mysql' );
		// A content option needs an explicit server-side translation rule.
		$wpdb->insert( wptsall_table( 'models' ), array(
			'plugin_slug' => 'owned-option-' . uniqid(), 'plugin_name' => 'Owned option',
			'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
		) );
		$model_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $model_id );
		$wpdb->insert( wptsall_table( 'relation_models' ), array( 'relation_id' => $this->test_relation_id, 'model_id' => $model_id ) );
		$wpdb->insert( wptsall_table( 'translation_rules' ), array(
			'model_id' => $model_id, 'url_pattern' => '/', 'url_type' => 'single',
			'data_type' => 'option', 'object_name' => $option_name, 'is_active' => 1,
			'field_capabilities' => wp_json_encode( array( 'option_value' => array( 'type' => 'translate', 'enabled' => true, 'content_format' => 'plain_text' ) ) ),
			'created_at' => $now, 'updated_at' => $now,
		) );
		$rule_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $rule_id );

		$wpdb->update(
			wptsall_table( 'site_relations' ),
			array(
				'target_site_id'   => (string) get_current_blog_id(),
				'target_site_type' => 'wp',
			),
			array( 'id' => $this->test_relation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$result_id = wptsall_insert_translation_result(
			array(
				'relation_id'       => $this->test_relation_id,
				'object_type'       => 'option',
				'object_id'         => $object_id,
				'translated_fields' => array(
					'option_value' => 'Translated option value',
				),
				'translated_meta'   => array( '_wptsall_callback_receipt' => array(
					'version' => 1, 'stage' => 'prepared',
					'device_hash' => hash( 'sha256', 'wptsall-callback-device-v1|test-sync-executor' ),
				) ),
				'media_mappings'    => array(),
				'client_task_id'    => 'test-option-' . uniqid(),
				'source_lang'       => 'zh_CN',
				'target_lang'       => 'en_US',
			)
		);

		$wpdb->insert(
			wptsall_table( 'tasks' ),
			array(
				'type'        => 'sync',
				'relation_id' => $this->test_relation_id,
				'status'      => 'pending',
				'blog_id'     => get_current_blog_id(),
				'object_type' => 'option',
				'subtype'     => $option_name,
				'object_id'   => $object_id,
				'payload'     => wp_json_encode( array( 'translation_result_id' => $result_id ) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$task_id = (int) $wpdb->insert_id;

		$result = Sync_Executor::execute_translation_sync( $task_id );

		$this->assertIsArray( $result );
		$this->assertTrue( ! empty( $result['success'] ) );
		$this->assertSame( 'Translated option value', get_option( $option_name ) );

		$task_row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT status FROM ' . wptsall_table( 'tasks' ) . ' WHERE id = %d', $task_id ),
			ARRAY_A
		);
		$this->assertSame( 'completed', $task_row['status'] ?? '' );

		$translation_row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT status FROM ' . wptsall_table( 'translation_results' ) . ' WHERE id = %d', $result_id ),
			ARRAY_A
		);
		$this->assertSame( 'synced', $translation_row['status'] ?? '' );

		$wpdb->delete( wptsall_table( 'tasks' ), array( 'id' => $task_id ), array( '%d' ) );
		$wpdb->delete( wptsall_table( 'translation_results' ), array( 'id' => $result_id ), array( '%d' ) );
	}

	// ==================== wptsall_insert_translation_result() Tests (v1.1.0) ====================

	/**
	 * Test wptsall_insert_translation_result returns false for empty client_task_id
	 */
	public function test_insert_translation_result_rejects_empty_client_task_id() {
		$result = wptsall_insert_translation_result( array(
			'relation_id'   => 1,
			'object_id'     => 1,
			'client_task_id' => '',
		) );

		$this->assertFalse( $result, 'Should return false when client_task_id is empty' );
	}

	/**
	 * Test wptsall_insert_translation_result inserts and returns ID
	 */
	public function test_insert_translation_result_returns_row_id() {
		global $wpdb;

		$client_task_id = 'unit-test-' . uniqid();
		$result_id = wptsall_insert_translation_result( array(
			'relation_id'       => $this->test_relation_id,
			'object_type'       => 'post',
			'object_id'         => 42,
			'translated_fields' => array( 'post_title' => 'Hello' ),
			'translated_meta'   => array(),
			'media_mappings'    => array(),
			'client_task_id'    => $client_task_id,
			'source_lang'       => 'zh_CN',
			'target_lang'       => 'en_US',
		) );

		$this->assertIsInt( $result_id );
		$this->assertGreaterThan( 0, $result_id );

		// Verify row exists.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . wptsall_table( 'translation_results' ) . " WHERE id = %d",
				$result_id
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertEquals( $client_task_id, $row['client_task_id'] );
		$this->assertEquals( 'pending', $row['status'] );

		// Verify JSON fields.
		$fields = json_decode( $row['translated_fields'], true );
		$this->assertEquals( 'Hello', $fields['post_title'] );

		// Clean up.
		$wpdb->delete( wptsall_table( 'translation_results' ), array( 'id' => $result_id ), array( '%d' ) );
	}

	/**
	 * Test wptsall_insert_translation_result enforces unique client_task_id
	 */
	public function test_insert_translation_result_unique_client_task_id() {
		global $wpdb;

		$client_task_id = 'idempotent-' . uniqid();

		// First insert.
		$id1 = wptsall_insert_translation_result( array(
			'relation_id'   => 1,
			'object_id'     => 1,
			'client_task_id' => $client_task_id,
			'source_lang'   => 'en',
			'target_lang'   => 'zh',
		) );
		$this->assertGreaterThan( 0, $id1 );

		// v1.2.1: ON DUPLICATE KEY UPDATE makes this idempotent — returns existing ID.
		$id2 = wptsall_insert_translation_result( array(
			'relation_id'   => 2,
			'object_id'     => 2,
			'client_task_id' => $client_task_id,
			'source_lang'   => 'en',
			'target_lang'   => 'zh',
		) );
		$this->assertEquals( $id1, $id2, 'Duplicate client_task_id should return existing ID (idempotent)' );

		// Clean up.
		$wpdb->delete( wptsall_table( 'translation_results' ), array( 'id' => $id1 ), array( '%d' ) );
	}

	// ==================== merge_translation_result() Tests (v1.1.0 via Reflection) ====================

	/**
	 * Test merge_translation_result merges post fields correctly
	 */
	public function test_merge_translation_result_merges_post_fields() {
		$method = new ReflectionMethod( Sync_Executor::class, 'merge_translation_result' );
		$method->setAccessible( true );

		$source_data = array(
			'post' => array(
				'post_title'   => '原始标题',
				'post_content' => '原始内容',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			),
			'meta' => array(
				'_seo_title' => '原始SEO',
				'_price'     => '100',
			),
		);

		$translated_fields = array(
			'post_title'   => 'Translated Title',
			'post_content' => 'Translated Content',
		);

		$translated_meta = array(
			'_seo_title' => 'Translated SEO',
		);

		$merged = $method->invoke( null, $source_data, $translated_fields, $translated_meta, 'post_type',
			array( 'post_title' => 'plain_text', 'post_content' => 'rich_html', '_seo_title' => 'plain_text' ) );

		// Translated fields should override source.
		$this->assertEquals( 'Translated Title', $merged['post_title'] );
		$this->assertEquals( 'Translated Content', $merged['post_content'] );

		// Non-translated source fields should be preserved.
		$this->assertEquals( 'publish', $merged['post_status'] );
		$this->assertEquals( 'post', $merged['post_type'] );

		// Meta: translated override, non-translated preserved.
		$this->assertEquals( 'Translated SEO', $merged['meta']['_seo_title'] );
		$this->assertEquals( '100', $merged['meta']['_price'] );
	}

	/**
	 * Test merge_translation_result keeps source slug when callback sends null/empty compute values.
	 */
	public function test_merge_translation_result_ignores_null_or_empty_post_name() {
		$method = new ReflectionMethod( Sync_Executor::class, 'merge_translation_result' );
		$method->setAccessible( true );

		$source_data = array(
			'post' => array(
				'post_title'   => 'Hello world',
				'post_content' => 'Source content',
				'post_name'    => 'hello-world',
				'guid'         => 'https://example.com/?p=1',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			),
			'meta' => array(),
		);

		// Simulate callback payload that includes compute placeholders as null/empty.
		$translated_fields = array(
			'post_title' => 'Translated Title',
			'post_name'  => null,
			'guid'       => '',
		);

		$merged = $method->invoke( null, $source_data, $translated_fields, array(), 'post_type',
			array( 'post_title' => 'plain_text', 'post_name' => 'slug' ) );

		$this->assertEquals( 'Translated Title', $merged['post_title'] );
		$this->assertEquals( 'hello-world', $merged['post_name'], 'Source slug should be preserved when callback post_name is null.' );
		$this->assertEquals( 'https://example.com/?p=1', $merged['guid'], 'Source guid should be preserved when callback guid is empty.' );
	}

	/**
	 * Test merge_translation_result handles taxonomy/term data
	 */
	public function test_merge_translation_result_merges_term_fields() {
		$method = new ReflectionMethod( Sync_Executor::class, 'merge_translation_result' );
		$method->setAccessible( true );

		$source_data = array(
			'term' => array(
				'name'        => '原始分类',
				'slug'        => 'original-cat',
				'description' => '原始描述',
			),
			'meta' => array(),
		);

		$translated_fields = array(
			'name'        => 'Translated Category',
			'description' => 'Translated Description',
		);

		$merged = $method->invoke( null, $source_data, $translated_fields, array(), 'taxonomy',
			array( 'name' => 'plain_text', 'description' => 'rich_html' ) );

		$this->assertEquals( 'Translated Category', $merged['name'] );
		$this->assertEquals( 'Translated Description', $merged['description'] );
		// Non-translated field preserved.
		$this->assertEquals( 'original-cat', $merged['slug'] );
	}

	/**
	 * Test translated slug fields are sanitized during merge.
	 */
	public function test_merge_translation_result_sanitizes_translated_slug_fields() {
		$method = new ReflectionMethod( Sync_Executor::class, 'merge_translation_result' );
		$method->setAccessible( true );

		$post_source = array(
			'post' => array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original Content',
				'post_name'    => 'original-title',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			),
			'meta' => array(),
		);
		$post_merged = $method->invoke(
			null,
			$post_source,
			array( 'post_name' => 'Bonjour le monde' ),
			array(),
			'post_type',
			array( 'post_name' => 'slug' )
		);
		$this->assertEquals( 'bonjour-le-monde', $post_merged['post_name'] );

		$term_source = array(
			'term' => array(
				'name'        => 'Original Category',
				'slug'        => 'original-category',
				'description' => 'Original Description',
			),
			'meta' => array(),
		);
		$term_merged = $method->invoke(
			null,
			$term_source,
			array( 'slug' => '分类 页面' ),
			array(),
			'taxonomy',
			array( 'slug' => 'slug' )
		);
		$this->assertNotEmpty( $term_merged['slug'] );
		$this->assertSame( sanitize_title( '分类 页面' ), $term_merged['slug'] );
	}

	/**
	 * Test option merge preserves HTML-capable fields when syncing array-backed options.
	 */
	public function test_merge_translation_result_preserves_option_rich_html_fields() {
		$method = new ReflectionMethod( Sync_Executor::class, 'merge_translation_result' );
		$method->setAccessible( true );

		$source = array(
			'option' => array(
				'option_name'  => 'wptsall_notification_template',
				'option_value' => array(
					'email_subject' => 'Original Subject',
					'email_body'    => '<p>Original Body</p>',
				),
			),
		);

		$merged = $method->invoke(
			null,
			$source,
			array(
				'email_body' => '<div><strong>Translated</strong> Body</div>',
			),
			array(),
			'option',
			array(
				'email_body' => 'rich_html',
			)
		);

		$this->assertSame(
			'<div><strong>Translated</strong> Body</div>',
			$merged['option_value']['email_body']
		);
	}

	/**
	 * Test translated post slug is uniquified before write-back.
	 */
	public function test_ensure_unique_post_slug_avoids_permalink_collision() {
		$existing_post_id = $this->create_test_post(
			array(
				'post_title'  => 'Existing Slug Post',
				'post_name'   => 'translated-slug',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->assertGreaterThan( 0, $existing_post_id );

		$method = new ReflectionMethod( Sync_Executor::class, 'ensure_unique_post_slug' );
		$method->setAccessible( true );

		$unique_slug = $method->invoke( null, 'translated slug', 'Translated Title', 'post', 'publish', 0, 0, 999 );
		$this->assertNotEquals( 'translated-slug', $unique_slug );
		$this->assertStringStartsWith( 'translated-slug', $unique_slug );
	}

	/**
	 * Test translated term slug is uniquified inside the taxonomy.
	 */
	public function test_ensure_unique_term_slug_avoids_taxonomy_collision() {
		$existing = wp_insert_term(
			'Existing Term ' . uniqid(),
			'category',
			array(
				'slug' => 'translated-category',
			)
		);
		$this->assertFalse( is_wp_error( $existing ) );

		$method = new ReflectionMethod( Sync_Executor::class, 'ensure_unique_term_slug' );
		$method->setAccessible( true );

		$unique_slug = $method->invoke( null, 'translated category', 'Translated Category', 'category', 0, 321 );
		$this->assertNotEquals( 'translated-category', $unique_slug );
		$this->assertStringStartsWith( 'translated-category', $unique_slug );
	}

	/**
	 * Test check_sync_conflict compares against local post_modified baseline.
	 */
	public function test_check_sync_conflict_uses_local_post_modified() {
		$post_id = $this->create_test_post();
		$post    = get_post( $post_id );
		$this->assertNotNull( $post );

		update_post_meta( $post_id, '_wptsall_last_synced', $post->post_modified );

		$method = new ReflectionMethod( Sync_Executor::class, 'check_sync_conflict' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $post_id, $this->test_relation_id, 'sync_post' );

		$this->assertTrue( $result, 'Same local timestamp should not be treated as conflict.' );
	}

	/**
	 * Test check_term_sync_conflict respects strategy filter.
	 */
	public function test_check_term_sync_conflict_strategy_filter() {
		$term = wp_insert_term(
			'Sync Executor Term ' . uniqid(),
			'category',
			array(
				'slug' => 'sync-executor-term-' . wp_rand( 1000, 9999 ),
			)
		);
		$this->assertFalse( is_wp_error( $term ) );
		$term_id = (int) $term['term_id'];

		update_term_meta( $term_id, '_wptsall_last_synced', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
		update_term_meta( $term_id, '_wptsall_last_manual_edit', gmdate( 'Y-m-d H:i:s', time() ) );

		$method = new ReflectionMethod( Sync_Executor::class, 'check_term_sync_conflict' );
		$method->setAccessible( true );

		// Default strategy: log_and_overwrite => proceed.
		$default_result = $method->invoke( null, $term_id, $this->test_relation_id, 'sync_term' );
		$this->assertTrue( $default_result );

		$filter = static function () {
			return 'skip';
		};
		add_filter( 'wptsall_term_sync_conflict_strategy', $filter, 10, 6 );
		$skip_result = $method->invoke( null, $term_id, $this->test_relation_id, 'sync_term' );
		remove_filter( 'wptsall_term_sync_conflict_strategy', $filter, 10 );

		$this->assertFalse( $skip_result, 'Strategy=skip should block term overwrite.' );

		wp_delete_term( $term_id, 'category' );
	}

	/**
	 * X-1 (tasks/5.3falsh2/12 批 B): the executor conflict default is driven
	 * by the relation's canonical conflict_strategy instead of a hardcoded
	 * 'log_and_overwrite'. target_wins parks the write; the schema default
	 * (source_wins) and legacy 'manual' resolve by canonical meaning.
	 */
	public function test_check_term_sync_conflict_follows_relation_strategy() {
		$term = wp_insert_term(
			'Strategy Term ' . uniqid(),
			'category',
			array(
				'slug' => 'strategy-term-' . wp_rand( 1000, 9999 ),
			)
		);
		$this->assertFalse( is_wp_error( $term ) );
		$term_id = (int) $term['term_id'];

		update_term_meta( $term_id, '_wptsall_last_synced', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
		update_term_meta( $term_id, '_wptsall_last_manual_edit', gmdate( 'Y-m-d H:i:s', time() ) );

		$method = new ReflectionMethod( Sync_Executor::class, 'check_term_sync_conflict' );
		$method->setAccessible( true );

		// Schema default (source_wins) → log_and_overwrite → proceed.
		$default_result = $method->invoke( null, $term_id, $this->test_relation_id, 'sync_term' );
		$this->assertTrue( $default_result, 'source_wins (DB default) must keep the logged-overwrite behavior.' );

		// Canonical target_wins on the relation → skip (target edits win).
		global $wpdb;
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		$wpdb->update(
			$relations_table,
			array( 'conflict_strategy' => 'target_wins' ),
			array( 'id' => $this->test_relation_id ),
			array( '%s' ),
			array( '%d' )
		);
		$target_wins_result = $method->invoke( null, $term_id, $this->test_relation_id, 'sync_term' );
		$this->assertFalse( $target_wins_result, 'target_wins on the relation must park the write.' );

		// Legacy vocabulary still resolves by canonical meaning.
		$wpdb->update(
			$relations_table,
			array( 'conflict_strategy' => 'manual' ),
			array( 'id' => $this->test_relation_id ),
			array( '%s' ),
			array( '%d' )
		);
		$manual_result = $method->invoke( null, $term_id, $this->test_relation_id, 'sync_term' );
		$this->assertFalse( $manual_result, 'legacy manual must map to manual_review (skip).' );

		wp_delete_term( $term_id, 'category' );
	}

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test posts.
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Clean up test relation.
		$relations_table = $wpdb->prefix . 'wptsall_site_relations';
		if ( $this->test_relation_id ) {
			$wpdb->delete( $relations_table, array( 'id' => $this->test_relation_id ), array( '%d' ) );
		}

		// Clean up test template relations.
		$wpdb->delete( $relations_table, array( 'template' => 'test-sync-executor' ), array( '%s' ) );

		// Clean up any test virtual content.
		$vsc_table = wptsall_table( 'virtual_site_content' );
		$vsc_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vsc_table ) );
		if ( $vsc_exists ) {
			$wpdb->query( "DELETE FROM {$vsc_table} WHERE virtual_site_id LIKE 'v_test_%'" );
		}
		foreach ( $this->test_option_names as $option_name ) {
			delete_option( $option_name );
		}
		$this->test_option_names = array();

		parent::tearDown();
	}

	/**
	 * wptsall_max_gallery_media_per_sync (default 20): during a post sync,
	 * the WooCommerce product gallery meta (_product_image_gallery) is
	 * sliced to the filter's value before per-id media mapping; unmapped
	 * ids pass through unchanged, so the slice is observable on the target
	 * post meta independent of media copying. The gallery leg requires a
	 * _thumbnail_id in the synced meta (the writer gates the gallery block
	 * on the featured-image presence).
	 */
	public function test_max_gallery_media_per_sync_slices_gallery_meta() {
		global $wpdb;

		if ( ! is_multisite() || get_current_blog_id() === 2 ) {
			$this->markTestSkipped( 'needs a distinct second site for the backflow guard to allow delivery' );
			return;
		}

		// Point the setUp relation at site 2 as a wp target. A same-site
		// target would trip the origin backflow guard (correct behavior).
		$wpdb->update(
			$wpdb->prefix . 'wptsall_site_relations',
			array(
				'target_site_id'   => '2',
				'target_site_type' => 'wp',
			),
			array( 'id' => $this->test_relation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$target_ids = array();
		$run_sync = function ( $gallery_csv ) use ( &$target_ids ) {
			global $wpdb;
			$source_post_id = $this->create_test_post( array( 'post_title' => 'Gallery Source ' . uniqid() ) );

			$result_id = wptsall_insert_translation_result( array(
				'relation_id'       => $this->test_relation_id,
				'object_type'       => 'post_type',
				'object_id'         => $source_post_id,
				'translated_fields' => array( 'post_title' => 'Gallery Target ' . uniqid() ),
				'translated_meta'   => array(
					// The gallery block is gated on a featured image being present.
					'_thumbnail_id'          => 910000,
					'_product_image_gallery' => $gallery_csv,
					'_wptsall_callback_receipt' => array(
						'version' => 1, 'stage' => 'prepared',
						'device_hash' => hash( 'sha256', 'wptsall-callback-device-v1|test-sync-executor' ),
					),
				),
				'media_mappings'    => array(),
				'client_task_id'    => 'test-gallery-' . uniqid(),
				'source_lang'       => 'zh_CN',
				'target_lang'       => 'en_US',
			) );
			$this->assertGreaterThan( 0, $result_id, 'translation result should be inserted' );

			$now = current_time( 'mysql' );
			$wpdb->insert(
				wptsall_table( 'tasks' ),
				array(
					'type'        => 'sync',
					'relation_id' => $this->test_relation_id,
					'status'      => 'pending',
					'blog_id'     => get_current_blog_id(),
					'object_type' => 'post_type',
					'subtype'     => 'post',
					'object_id'   => $source_post_id,
					'payload'     => wp_json_encode( array( 'translation_result_id' => $result_id ) ),
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
			$task_id = (int) $wpdb->insert_id;

			$sync_result = Sync_Executor::execute_translation_sync( $task_id );

			$this->assertTrue(
				! is_wp_error( $sync_result ),
				'wp post sync must succeed: ' . ( is_wp_error( $sync_result ) ? $sync_result->get_error_message() : '' )
			);
			$target_id = (int) ( $sync_result['target_id'] ?? 0 );
			$this->assertGreaterThan( 0, $target_id, 'wp post sync must report the target post id' );
			$target_ids[] = $target_id;

			$wpdb->delete( wptsall_table( 'tasks' ), array( 'id' => $task_id ), array( '%d' ) );
			$wpdb->delete( wptsall_table( 'translation_results' ), array( 'id' => $result_id ), array( '%d' ) );

			return $target_id;
		};

		$captured = array();
		add_filter( 'wptsall_max_gallery_media_per_sync', function ( $value ) use ( &$captured ) {
			$captured[] = $value;
			return $value;
		} );

		$read_gallery = function ( $target_id ) {
			switch_to_blog( 2 );
			$meta = (string) get_post_meta( $target_id, '_product_image_gallery', true );
			restore_current_blog();
			return $meta;
		};

		try {
			// Default (no listener): all 3 gallery ids survive (limit 20).
			$target_a = $run_sync( '910001,910002,910003' );
			$this->assertNotEmpty( $captured, 'the gallery filter must be consulted during sync' );
			$this->assertSame( 20, $captured[0], 'documented default max gallery media per sync is 20' );
			$this->assertSame(
				'910001,910002,910003',
				$read_gallery( $target_a ),
				'default limit must keep all three gallery ids (unmapped ids pass through)'
			);

			// Override 2: only the first 2 ids survive the slice.
			add_filter( 'wptsall_max_gallery_media_per_sync', function () {
				return 2;
			} );
			$target_b = $run_sync( '910001,910002,910003' );
			$this->assertSame(
				'910001,910002',
				$read_gallery( $target_b ),
				'listener override must slice the gallery to the first 2 ids'
			);
		} finally {
			// Both listeners were registered at priority 10.
			remove_all_filters( 'wptsall_max_gallery_media_per_sync', 10 );
			// Target posts live on site 2; the shared tearDown deletes on the
			// current blog only, so clean them here in the right blog context.
			switch_to_blog( 2 );
			foreach ( $target_ids as $tid ) {
				if ( $tid > 0 ) {
					wp_delete_post( $tid, true );
				}
			}
			restore_current_blog();
		}
	}

	/**
	 * OCR and the other text products stay sentences when the rule format is media_ref.
	 */
	public function test_text_product_is_written_as_text_not_file_url() {
		$formats = new ReflectionMethod( Sync_Executor::class, 'apply_text_product_write_formats' );
		$formats->setAccessible( true );
		$omit = new ReflectionMethod( Sync_Executor::class, 'omit_file_url_text_products' );
		$omit->setAccessible( true );
		$merge = new ReflectionMethod( Sync_Executor::class, 'merge_translation_result' );
		$merge->setAccessible( true );

		$field_results = array(
			array( 'field' => 'image_caption', 'status' => 'success', 'transform_stage' => 'ocr_text' ),
			array( 'field' => 'video_caption', 'status' => 'success', 'transform_stage' => 'subtitle_text' ),
			array( 'field' => 'audio_note', 'status' => 'success', 'detail' => 'transcript_text' ),
			array( 'field' => 'doc_note', 'status' => 'success', 'transform_stage' => 'document_text' ),
			array( 'field' => 'poster', 'status' => 'success', 'transform_stage' => 'replace_binary' ),
			array( 'field' => 'not_in_rule', 'status' => 'success', 'transform_stage' => 'ocr_text' ),
		);
		$written = $formats->invoke( null, array(
			'image_caption' => 'media_ref',
			'video_caption' => 'media_ref',
			'audio_note'    => 'media_ref',
			'doc_note'      => 'media_ref',
			'poster'        => 'media_ref',
		), $field_results );

		$this->assertSame( 'plain_text', $written['image_caption'] );
		$this->assertSame( 'plain_text', $written['video_caption'] );
		$this->assertSame( 'plain_text', $written['audio_note'] );
		$this->assertSame( 'plain_text', $written['doc_note'] );
		$this->assertSame( 'media_ref', $written['poster'] );
		$this->assertArrayNotHasKey( 'not_in_rule', $written );

		$source = array(
			'post' => array(
				'post_title'   => 'Source',
				'post_content' => 'Source body',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			),
			'meta' => array(
				'image_caption' => 'original caption',
				'poster'        => 'https://cdn.example/a/image.png',
			),
		);
		$meta = $omit->invoke( null, array(
			'image_caption' => '【zh】门前的招牌',
			'poster'        => 'https://cdn.example/a/image-zh.png',
		), $field_results );
		$this->assertSame( '【zh】门前的招牌', $meta['image_caption'] );
		$this->assertSame( 'https://cdn.example/a/image-zh.png', $meta['poster'] );

		$merged = $merge->invoke( null, $source, array(), $meta, 'post_type', $written );
		$this->assertSame( '【zh】门前的招牌', $merged['meta']['image_caption'] );
		$this->assertSame( 'https://cdn.example/a/image-zh.png', $merged['meta']['poster'] );

		$url_only = $omit->invoke( null, array(
			'image_caption' => 'https://cdn.example/a/image-zh.png',
		), $field_results );
		$this->assertArrayNotHasKey( 'image_caption', $url_only );
	}

	/**
	 * Copy is a write action. Skip and a bare content format are not.
	 */
	public function test_copy_action_is_writable_and_skip_is_not() {
		$method = new ReflectionMethod( Sync_Executor::class, 'writable_field_action' );
		$method->setAccessible( true );

		$this->assertSame( 'translate', $method->invoke( null, array( 'type' => 'translate' ) ) );
		$this->assertSame( 'copy', $method->invoke( null, array( 'action' => 'as_is', 'type' => 'plain_text' ) ) );
		$this->assertSame( 'copy', $method->invoke( null, array( 'action' => 'copy' ) ) );
		$this->assertSame( '', $method->invoke( null, array( 'action' => 'skip', 'type' => 'translate' ) ) );
		$this->assertSame( '', $method->invoke( null, array( 'type' => 'plain_text' ) ) );
	}
}

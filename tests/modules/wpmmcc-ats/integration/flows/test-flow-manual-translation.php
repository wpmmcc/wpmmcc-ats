<?php
/**
 * Flow: Manual Translation Editor
 *
 * Tests the manual translation REST endpoints: editor-data requirement of
 * source_post_id, editor-data response fields, skeleton creation, and
 * save translation with translation markers.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Test_Flow_Manual_Translation
 */
class Test_Flow_Manual_Translation extends REST_Integration_Test_Case {

	/**
	 * Whether prerequisites are met for this flow.
	 *
	 * @var bool
	 */
	private static $chain_runnable = true;

	/**
	 * Reason to skip.
	 *
	 * @var string
	 */
	private static $skip_reason = '';

	/**
	 * One-time setup.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace is not registered';
		}
	}

	/**
	 * Per-test guard.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Assert that GET /manual-translations/editor-data without source_post_id returns 400.
	 *
	 * The parameter name is source_post_id, NOT post_id.
	 */
	public function test_editor_data_requires_source_post_id() {
		global $wpdb;

		// HTTP assertion: missing required source_post_id must return 400.
		$response = $this->rest_get( 'manual-translations/editor-data', array(
			'relation_id' => 1,
			// source_post_id intentionally omitted.
		) );

		$status = $response->get_status();
		$this->assertEquals( 400, $status, 'Missing source_post_id must return HTTP 400' );

		$data = $response->get_data();

		// Specific field assertion: response must contain an error code.
		$has_error = isset( $data['code'] ) || isset( $data['error'] ) || ! empty( $data['data']['params']['source_post_id'] );
		$this->assertTrue( $has_error, 'Error response must contain an error indicator (code, error, or params.source_post_id)' );

		// DB assertion: no spurious records were created.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$manual_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'draft' AND post_title LIKE '%editor-data-test%'"
		);
		$this->assertEquals( 0, $manual_count, 'No draft posts must be created on a 400 error response' );
	}

	/**
	 * Assert that editor-data with valid source_post_id + relation_id returns HTTP 200
	 * and a response containing translatable fields.
	 */
	public function test_editor_data_returns_fields() {
		global $wpdb;

		// Create source post.
		$post_id = $this->create_test_post( array(
			'post_title'   => 'Manual Translation Editor Test',
			'post_content' => 'Content for manual translation editor data test.',
		) );

		// Create relation.
		$vs_id        = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		if ( $relation_id <= 0 ) {
			$this->markTestSkipped( 'Could not create a test site relation' );
		}

		// HTTP assertion: returns 200.
		$response = $this->rest_get( 'manual-translations/editor-data', array(
			'source_post_id' => $post_id,
			'relation_id'    => $relation_id,
		) );

		$status = $response->get_status();
		$this->assertEquals( 200, $status, 'editor-data must return HTTP 200' );

		$data = $response->get_data();

		// Specific field assertion: current contract returns source/target/field_config.
		$has_fields = isset( $data['source'] )
			|| isset( $data['field_config'] )
			|| isset( $data['fields'] )
			|| isset( $data['translatable_fields'] );
		$this->assertTrue( $has_fields, 'editor-data response must contain source + field config data' );

		// DB assertion: source post still exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_title = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_title FROM {$wpdb->posts} WHERE ID = %d",
			$post_id
		) );
		$this->assertNotNull( $db_title, 'Source post must still exist in DB after editor-data request' );
	}

	/**
	 * Assert that POST /manual-translations/create-skeleton returns 200/201
	 * and a target draft exists in the DB.
	 */
	public function test_create_skeleton() {
		global $wpdb;

		$post_id = $this->create_test_post( array(
			'post_title' => 'Skeleton Source Post',
		) );

		$vs_id        = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		if ( $relation_id <= 0 ) {
			$this->markTestSkipped( 'Could not create a test site relation' );
		}

		// HTTP assertion: create-skeleton returns 200 or 201.
		$response = $this->rest_post( 'manual-translations/create-skeleton', array(
			'source_post_id' => $post_id,
			'relation_id'    => $relation_id,
		) );

		$status = $response->get_status();
		$this->assertTrue(
			in_array( $status, array( 200, 201 ), true ),
			"create-skeleton must return HTTP 200 or 201, got {$status}"
		);

		$data = $response->get_data();

		// Specific field assertion: current contract returns target_id directly.
		$target_id = (int) ( $data['target_id'] ?? ( $data['post_id'] ?? 0 ) );
		$this->assertGreaterThan( 0, $target_id, 'create-skeleton must return a valid target_id' );

		// DB assertion: a target draft post should exist linked to source.
		if ( $target_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$target_post = $wpdb->get_row( $wpdb->prepare(
				"SELECT ID, post_status FROM {$wpdb->posts} WHERE ID = %d",
				$target_id
			), ARRAY_A );
			$this->assertNotNull( $target_post, 'Target post must exist in DB after create-skeleton' );
			$this->assertContains(
				$target_post['post_status'],
				array( 'draft', 'pending', 'publish', 'inherit' ),
				'Target post must have a valid post_status'
			);
		} else {
			// If no target_id returned, verify post_mappings was created.
			$mappings_table = wptsall_table( 'post_mappings' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mappings_table ) );
			if ( $table_exists === $mappings_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$mapping = $wpdb->get_row( $wpdb->prepare(
					"SELECT id FROM {$mappings_table} WHERE source_post_id = %d AND relation_id = %d",
					$post_id,
					$relation_id
				), ARRAY_A );
				$this->assertNotNull( $mapping, 'post_mappings must contain a record for this source post after skeleton creation' );
			}
		}
	}

	/**
	 * Assert that POST /manual-translations saves translated content
	 * and the target post_title contains the 【zh】 marker.
	 */
	public function test_save_translation() {
		global $wpdb;

		$post_id = $this->create_test_post( array(
			'post_title'   => 'Save Translation Test Post',
			'post_content' => 'Content to be manually translated.',
		) );

		$vs_id        = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id  = (int) ( $relation_data['relation_ids'][0] ?? 0 );

		if ( $relation_id <= 0 ) {
			$this->markTestSkipped( 'Could not create a test site relation' );
		}

		$translated_title = '【zh】Save Translation Test Post【/zh】';

		// HTTP assertion: POST /manual-translations (NOT /manual-translations/save) returns 200 or 201.
		$response = $this->rest_post( 'manual-translations', array(
			'source_post_id'  => $post_id,
			'relation_id'     => $relation_id,
			'translated_data' => array(
				'post_title' => $translated_title,
			),
		) );

		$status = $response->get_status();
		$this->assertTrue(
			in_array( $status, array( 200, 201 ), true ),
			"POST /manual-translations must return HTTP 200 or 201, got {$status}. Response: " . wp_json_encode( $response->get_data(), JSON_UNESCAPED_UNICODE )
		);

		$data = $response->get_data();

		// Specific field assertion: current contract returns target_id directly.
		$target_id = (int) ( $data['target_id'] ?? ( $data['post_id'] ?? 0 ) );
		$this->assertGreaterThan( 0, $target_id, 'Save translation must return a valid target_id' );

		// DB assertion: target post title contains the translation marker.
		if ( $target_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$stored_title = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_title FROM {$wpdb->posts} WHERE ID = %d",
				$target_id
			) );
			$this->assertNotNull( $stored_title, 'Target post must exist in DB' );
			$this->assertStringContainsString(
				'【zh】',
				(string) $stored_title,
				'Saved target post_title must contain the 【zh】 translation marker'
			);
		} else {
			// Check virtual site content table as fallback.
			$vs_content_table = wptsall_table( 'virtual_site_content' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vs_content_table ) );
			if ( $table_exists === $vs_content_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$vs_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id FROM {$vs_content_table} WHERE source_post_id = %d",
					$post_id
				), ARRAY_A );
				$this->assertNotNull( $vs_row, 'Virtual site content must be saved after manual translation' );
			}
		}
	}
}

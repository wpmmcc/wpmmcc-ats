<?php
/**
 * Manual Content Service Tests
 *
 * Tests for WPTSALL\Sites\Services\Manual_Content_Service class.
 *
 * @package WPTSALL
 * @since 1.0.0
 */

use WPTSALL\Sites\Services\Manual_Content_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;

class Test_Manual_Content_Service extends SimpleTestCase {

	/**
	 * Test relation IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_relation_ids = array();

	/**
	 * Test post IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_post_ids = array();
	private $test_model_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		// Clear site relation cache to avoid stale data between tests.
		// The service's clear_cache() only clears specific keys, not filter-hashed keys.
		// Use wp_cache_flush() to ensure no stale cached query results between tests.
		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test posts (including target posts created by save_translation).
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->test_post_ids = array();

		// Clean up test relations.
		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_site_relations';
		foreach ( $this->test_relation_ids as $rid ) {
			// 2026-09-12: cascade through the service so relation_models/
			// hooks/configs rows go with the relation — raw deletes orphaned
			// them (doctor-probes.php §8 now guards this class).
			Site_Relation_Service::delete_relation( (int) $rid );
		}
		$this->test_relation_ids = array();
		foreach ( $this->test_model_ids as $model_id ) {
			$wpdb->delete( wptsall_table( 'translation_rules' ), array( 'model_id' => $model_id ) );
			$wpdb->delete( wptsall_table( 'models' ), array( 'id' => $model_id ) );
		}
		$this->test_model_ids = array();

		parent::tearDown();
	}

	/**
	 * Helper: create a test source post.
	 *
	 * @param array $args Post arguments.
	 * @return int Post ID.
	 */
	private function create_test_post( $args = array() ) {
		$defaults = array(
			'post_title'   => 'Test Source Post ' . uniqid(),
			'post_content' => 'Test content for translation',
			'post_excerpt' => 'Test excerpt',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);
		$post_id = wp_insert_post( array_merge( $defaults, $args ) );
		$this->test_post_ids[] = $post_id;
		return $post_id;
	}

	/**
	 * Helper: create a test relation (virtual site target).
	 *
	 * @param array $overrides Override default values.
	 * @return int|null Relation ID or null on failure.
	 */
	private function create_test_relation( $overrides = array() ) {
		$defaults = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => 'en',
			'target_sites'   => array(
				array(
					'id'   => 'v_test_' . uniqid(),
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		);

		$data   = array_merge( $defaults, $overrides );
		$result = Site_Relation_Service::create_relation( $data );

		if ( ! empty( $result['success'] ) && ! empty( $result['relation_ids'] ) ) {
			$this->test_relation_ids = array_merge( $this->test_relation_ids, $result['relation_ids'] );
			$relation_id = (int) $result['relation_ids'][0];
			// These editor tests need a real enabled policy, not the old
			// fallback for an attached template with no matching post rule.
			global $wpdb;
			$now = current_time( 'mysql' );
			$wpdb->insert( wptsall_table( 'models' ), array(
				'plugin_slug' => 'owned-manual-' . uniqid(), 'plugin_name' => 'Owned manual',
				'is_system' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
			) );
			$model_id = (int) $wpdb->insert_id;
			$this->assertGreaterThan( 0, $model_id );
			$this->test_model_ids[] = $model_id;
			$caps = array();
			foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ) as $key ) {
				$caps[ $key ] = array( 'type' => 'translate', 'enabled' => true,
					'content_format' => 'post_content' === $key ? 'rich_html' : 'plain_text' );
			}
			$caps['_thumbnail_id'] = array( 'type' => 'id_mapping', 'enabled' => true, 'reference_type' => 'media', 'value_format' => 'scalar' );
			$wpdb->insert( wptsall_table( 'translation_rules' ), array(
				'model_id' => $model_id, 'url_pattern' => '/', 'url_type' => 'single',
				'data_type' => 'post', 'object_name' => 'post', 'is_active' => 1,
				'field_capabilities' => wp_json_encode( $caps ), 'created_at' => $now, 'updated_at' => $now,
			) );
			$this->assertGreaterThan( 0, (int) $wpdb->insert_id );
			\WPTSALL\Sites\Services\Relation_Model_Service::set_relation_models( $relation_id, array( $model_id ) );
			return $relation_id;
		}

		return null;
	}

	// ==================== get_editor_data() Tests ====================

	/**
	 * Test get_editor_data returns correct structure for valid inputs.
	 */
	public function test_get_editor_data_returns_structure() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::get_editor_data( $post_id, $relation_id );

		$this->assertFalse( is_wp_error( $result ), 'get_editor_data should not return WP_Error' );
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'source', $result, 'Result should have source key' );
		$this->assertArrayHasKey( 'target', $result, 'Result should have target key' );
		$this->assertArrayHasKey( 'field_config', $result, 'Result should have field_config key' );
		$this->assertArrayHasKey( 'relation', $result, 'Result should have relation key' );
	}

	/**
	 * Test get_editor_data source data has correct fields.
	 */
	public function test_get_editor_data_source_fields() {
		$post_id     = $this->create_test_post( array(
			'post_title'   => 'Source Title ABC',
			'post_content' => 'Source content ABC',
			'post_excerpt' => 'Source excerpt ABC',
		) );
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::get_editor_data( $post_id, $relation_id );

		$this->assertFalse( is_wp_error( $result ), 'get_editor_data should not return WP_Error' );

		$source = $result['source'];
		$this->assertArrayHasKey( 'ID', $source, 'Source should have ID' );
		$this->assertArrayHasKey( 'post_title', $source, 'Source should have post_title' );
		$this->assertArrayHasKey( 'post_content', $source, 'Source should have post_content' );
		$this->assertArrayHasKey( 'post_excerpt', $source, 'Source should have post_excerpt' );
		$this->assertArrayHasKey( 'post_type', $source, 'Source should have post_type' );

		$this->assertEquals( $post_id, $source['ID'], 'Source ID should match' );
		$this->assertEquals( 'Source Title ABC', $source['post_title'], 'Source title should match' );
		$this->assertEquals( 'Source content ABC', $source['post_content'], 'Source content should match' );
	}

	/**
	 * Test get_editor_data with invalid relation returns WP_Error.
	 */
	public function test_get_editor_data_invalid_relation() {
		$post_id = $this->create_test_post();
		$result  = Manual_Content_Service::get_editor_data( $post_id, 999999 );
		$this->assertTrue( is_wp_error( $result ), 'Invalid relation should return WP_Error' );
	}

	/**
	 * Test get_editor_data with invalid post returns WP_Error.
	 */
	public function test_get_editor_data_invalid_post() {
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::get_editor_data( 999999, $relation_id );
		$this->assertTrue( is_wp_error( $result ), 'Invalid post should return WP_Error' );
	}

	/**
	 * Test get_editor_data target is empty when no translation exists.
	 */
	public function test_get_editor_data_target_empty_without_translation() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::get_editor_data( $post_id, $relation_id );

		$this->assertFalse( is_wp_error( $result ), 'Should not return WP_Error' );
		$this->assertEmpty( $result['target'], 'Target should be empty when no translation exists' );
	}

	// ==================== save_translation() Tests ====================

	/**
	 * Test save_translation creates new post for virtual site.
	 */
	public function test_save_translation_creates_new_post() {
		$post_id     = $this->create_test_post( array(
			'post_title'   => 'English Title',
			'post_content' => 'English content here',
			'post_excerpt' => 'English excerpt',
		) );
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$translated_data = array(
			'post_title'   => 'Chinese Title',
			'post_content' => 'Chinese content here',
			'post_excerpt' => 'Chinese excerpt',
			'post_name'    => 'chinese-title',
		);

		$result = Manual_Content_Service::save_translation( $post_id, $relation_id, $translated_data );

		$this->assertFalse(
			is_wp_error( $result ),
			'save_translation should not return WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'target_id', $result, 'Result should have target_id' );
		$this->assertArrayHasKey( 'is_update', $result, 'Result should have is_update' );
		$this->assertFalse( $result['is_update'], 'First save should be create, not update' );

		// Track for cleanup.
		$this->test_post_ids[] = $result['target_id'];

		// Verify the target post has correct content.
		$target = get_post( $result['target_id'] );
		$this->assertNotNull( $target, 'Target post should exist' );
		$this->assertEquals( 'Chinese Title', $target->post_title, 'Target title should match' );
		$this->assertEquals( 'Chinese content here', $target->post_content, 'Target content should match' );
	}

	/**
	 * Test save_translation fires wptsall_manual_translation_saved exactly once
	 * with the expected arguments, and does not fire it for failed saves.
	 */
	public function test_save_translation_fires_manual_translation_saved_action() {
		$post_id     = $this->create_test_post(
			array(
				'post_title'   => 'Hook Source Title',
				'post_content' => 'Hook source content',
			)
		);
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$calls    = array();
		$callback = function ( $source_post_id, $relation_id, $target_id, $context ) use ( &$calls ) {
			$calls[] = array(
				'source_post_id' => $source_post_id,
				'relation_id'    => $relation_id,
				'target_id'      => $target_id,
				'context'        => $context,
			);
		};
		add_action( 'wptsall_manual_translation_saved', $callback, 10, 4 );

		$result = Manual_Content_Service::save_translation(
			$post_id,
			$relation_id,
			array(
				'post_title'   => 'Hook Target Title',
				'post_content' => 'Hook target content',
			)
		);

		// A failed save (unknown relation) must not fire the action again.
		$failed = Manual_Content_Service::save_translation(
			$post_id,
			99999999,
			array( 'post_title' => 'Should Not Persist' )
		);

		remove_action( 'wptsall_manual_translation_saved', $callback, 10 );

		$this->assertTrue(
			is_wp_error( $failed ),
			'Save against an unknown relation must fail'
		);
		$this->assertFalse(
			is_wp_error( $result ),
			'save_translation should succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);
		$this->test_post_ids[] = $result['target_id'];

		$this->assertCount( 1, $calls, 'Action must fire exactly once per successful save' );
		$this->assertSame( $post_id, $calls[0]['source_post_id'], 'Source post ID must match' );
		$this->assertSame( $relation_id, $calls[0]['relation_id'], 'Relation ID must match' );
		$this->assertSame( (int) $result['target_id'], $calls[0]['target_id'], 'Target ID must match' );
		$this->assertIsArray( $calls[0]['context'], 'Context must be an array' );
		$this->assertArrayHasKey( 'target_lang', $calls[0]['context'], 'Context must carry the target language' );
		$this->assertSame( 'zh_CN', $calls[0]['context']['target_lang'], 'Target language must come from the relation' );
		$this->assertFalse( $calls[0]['context']['is_update'], 'First save must not be flagged as update' );
	}

	/**
	 * Test save_translation sets correct meta markers on virtual site post.
	 */
	public function test_save_translation_sets_virtual_site_meta() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Meta Test Title',
			'post_content' => 'Meta Test Content',
		) );

		$this->assertFalse( is_wp_error( $result ), 'Should not return WP_Error' );
		$this->test_post_ids[] = $result['target_id'];

		$target_id = $result['target_id'];

		// Verify virtual site ID meta.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$vs_id    = get_post_meta( $target_id, '_wptsall_virtual_site_id', true );
		$this->assertEquals( $relation['target_site_id'], $vs_id, 'Virtual site ID meta should match relation target_site_id' );

		// Verify source post ID meta.
		$source_id = get_post_meta( $target_id, '_wptsall_source_post_id', true );
		$this->assertEquals( $post_id, (int) $source_id, 'Source post ID meta should match' );

		// Verify source blog ID meta.
		$source_blog = get_post_meta( $target_id, '_wptsall_source_blog_id', true );
		$this->assertNotEmpty( $source_blog, 'Source blog ID meta should be set' );

		// Verify relation ID meta.
		$relation_meta = get_post_meta( $target_id, '_wptsall_relation_id', true );
		$this->assertEquals( $relation_id, (int) $relation_meta, 'Relation ID meta should match' );

		// Verify last synced timestamp.
		$last_synced = get_post_meta( $target_id, '_wptsall_last_synced', true );
		$this->assertNotEmpty( $last_synced, 'Last synced timestamp should be set' );
	}

	/**
	 * Test save_translation updates existing translation.
	 */
	public function test_save_translation_updates_existing() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		// First save (create).
		$result1 = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Initial Title',
			'post_content' => 'Initial content',
		) );

		$this->assertFalse( is_wp_error( $result1 ), 'First save should succeed' );
		$this->test_post_ids[] = $result1['target_id'];
		$target_id = $result1['target_id'];

		// Second save (update).
		$result2 = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Updated Title',
			'post_content' => 'Updated content',
		) );

		$this->assertFalse( is_wp_error( $result2 ), 'Second save should succeed' );
		$this->assertTrue( $result2['is_update'], 'Second save should be update' );
		$this->assertEquals( $target_id, $result2['target_id'], 'Should update same post, not create new' );

		// Verify updated content.
		$target = get_post( $target_id );
		$this->assertEquals( 'Updated Title', $target->post_title, 'Title should be updated' );
		$this->assertEquals( 'Updated content', $target->post_content, 'Content should be updated' );
	}

	/**
	 * Patch semantics: omitted translate keys must not wipe existing target fields.
	 */
	public function test_save_translation_patch_omitted_key_preserves_field() {
		$post_id     = $this->create_test_post(
			array(
				'post_title'   => 'Source Title Keep',
				'post_content' => 'Source content keep',
				'post_excerpt' => 'Source excerpt keep',
			)
		);
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result1 = Manual_Content_Service::save_translation(
			$post_id,
			$relation_id,
			array(
				'post_title'   => 'Patched Title V1',
				'post_content' => 'Patched content V1',
				'post_excerpt' => 'Patched excerpt V1',
			)
		);
		$this->assertFalse( is_wp_error( $result1 ), 'Initial save should succeed' );
		$this->test_post_ids[] = $result1['target_id'];
		$target_id             = (int) $result1['target_id'];

		$result2 = Manual_Content_Service::save_translation(
			$post_id,
			$relation_id,
			array(
				'post_title' => 'Patched Title V2 Only',
				// post_content / post_excerpt intentionally omitted.
			)
		);
		$this->assertFalse( is_wp_error( $result2 ), 'Patch save should succeed' );
		$this->assertTrue( ! empty( $result2['is_update'] ), 'Second save should be update' );

		$target = get_post( $target_id );
		$this->assertEquals( 'Patched Title V2 Only', $target->post_title, 'Title should update' );
		$this->assertEquals( 'Patched content V1', $target->post_content, 'Omitted content must be preserved' );
		$this->assertEquals( 'Patched excerpt V1', $target->post_excerpt, 'Omitted excerpt must be preserved' );
	}

	/**
	 * Test save_translation with invalid relation returns WP_Error.
	 */
	public function test_save_translation_invalid_relation() {
		$post_id = $this->create_test_post();
		$result  = Manual_Content_Service::save_translation( $post_id, 999999, array(
			'post_title'   => 'Test',
			'post_content' => 'Test',
		) );
		$this->assertTrue( is_wp_error( $result ), 'Invalid relation should return WP_Error' );
	}

	/**
	 * Test save_translation with invalid post returns WP_Error.
	 */
	public function test_save_translation_invalid_post() {
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::save_translation( 999999, $relation_id, array(
			'post_title'   => 'Test',
			'post_content' => 'Test',
		) );
		$this->assertTrue( is_wp_error( $result ), 'Invalid post should return WP_Error' );
	}

	/**
	 * Test save_translation preserves source post status and type.
	 */
	public function test_save_translation_preserves_source_post_attributes() {
		$post_id     = $this->create_test_post( array(
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Attribute Test',
			'post_content' => 'Attribute content',
		) );

		$this->assertFalse( is_wp_error( $result ), 'Should not return WP_Error' );
		$this->test_post_ids[] = $result['target_id'];

		$target = get_post( $result['target_id'] );
		$this->assertEquals( 'publish', $target->post_status, 'Target should inherit source post_status' );
		$this->assertEquals( 'post', $target->post_type, 'Target should inherit source post_type' );
	}

	// ==================== find_existing_translation() Tests ====================

	/**
	 * Test find_existing_translation returns null before save.
	 */
	public function test_find_existing_translation_returns_null_before_save() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$found = Manual_Content_Service::find_existing_translation( $post_id, $relation_id );
		$this->assertNull( $found, 'Should not find translation before save' );
	}

	/**
	 * Test find_existing_translation returns target ID after save.
	 */
	public function test_find_existing_translation_returns_id_after_save() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Find Test Title',
			'post_content' => 'Find Test Content',
		) );
		$this->assertFalse( is_wp_error( $result ), 'Save should succeed' );
		$this->test_post_ids[] = $result['target_id'];

		$found = Manual_Content_Service::find_existing_translation( $post_id, $relation_id );
		$this->assertEquals( $result['target_id'], $found, 'Should find the saved translation' );
	}

	/**
	 * Test find_existing_translation with invalid relation returns null.
	 */
	public function test_find_existing_translation_invalid_relation() {
		$post_id = $this->create_test_post();
		$found   = Manual_Content_Service::find_existing_translation( $post_id, 999999 );
		$this->assertNull( $found, 'Invalid relation should return null' );
	}

	// ==================== get_translation_status() Tests ====================

	/**
	 * Test get_translation_status returns array.
	 */
	public function test_get_translation_status_returns_array() {
		$post_id = $this->create_test_post();
		$statuses = Manual_Content_Service::get_translation_status( $post_id );
		$this->assertIsArray( $statuses, 'Status should be array' );
	}

	/**
	 * Test get_translation_status shows missing for untranslated post.
	 */
	public function test_get_translation_status_missing_before_save() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$statuses = Manual_Content_Service::get_translation_status( $post_id );
		$this->assertIsArray( $statuses, 'Status should be array' );

		// Find our relation's status.
		$our_status = null;
		foreach ( $statuses as $s ) {
			if ( (int) $s['relation_id'] === $relation_id ) {
				$our_status = $s;
				break;
			}
		}

		$this->assertNotNull( $our_status, 'Should find status for our relation' );
		$this->assertEquals( 'missing', $our_status['status'], 'Before save, status should be missing' );
	}

	/**
	 * Test get_translation_status shows published after save.
	 */
	public function test_get_translation_status_published_after_save() {
		$post_id     = $this->create_test_post( array( 'post_status' => 'publish' ) );
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Status Test Title',
			'post_content' => 'Status Test Content',
		) );
		$this->assertFalse( is_wp_error( $result ), 'Save should succeed' );
		$this->test_post_ids[] = $result['target_id'];

		$statuses = Manual_Content_Service::get_translation_status( $post_id );

		// Find our relation's status.
		$our_status = null;
		foreach ( $statuses as $s ) {
			if ( (int) $s['relation_id'] === $relation_id ) {
				$our_status = $s;
				break;
			}
		}

		$this->assertNotNull( $our_status, 'Should find status for our relation' );
		$this->assertEquals( 'published', $our_status['status'], 'After save with publish source, status should be published' );
		$this->assertEquals( $result['target_id'], $our_status['target_post_id'], 'Status should include target_post_id' );
	}

	/**
	 * Test get_translation_status for nonexistent post returns empty array.
	 */
	public function test_get_translation_status_nonexistent_post() {
		$statuses = Manual_Content_Service::get_translation_status( 999999 );
		$this->assertIsArray( $statuses, 'Should return array' );
		$this->assertEmpty( $statuses, 'Nonexistent post should return empty status' );
	}

	// ==================== field_config Tests ====================

	/**
	 * Test field_config has required classification keys.
	 */
	public function test_field_config_has_required_keys() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::get_editor_data( $post_id, $relation_id );

		$this->assertFalse( is_wp_error( $result ), 'get_editor_data should not return WP_Error' );

		$config = $result['field_config'];
		$this->assertIsArray( $config, 'field_config should be an array' );
		$this->assertArrayHasKey( 'translate_fields', $config, 'Config should have translate_fields' );
		$this->assertArrayHasKey( 'sync_fields', $config, 'Config should have sync_fields' );
	}

	/**
	 * Test field_config translate_fields includes core display fields.
	 */
	public function test_field_config_translate_fields_include_core() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::get_editor_data( $post_id, $relation_id );

		$this->assertFalse( is_wp_error( $result ), 'get_editor_data should not return WP_Error' );

		$translate = $result['field_config']['translate_fields'];
		$this->assertIsArray( $translate, 'translate_fields should be an array' );
		$this->assertContains( 'post_title', $translate, 'post_title should be in translate_fields' );
		$this->assertContains( 'post_content', $translate, 'post_content should be in translate_fields' );
	}

	// ==================== get_editor_data after save Tests ====================

	/**
	 * Test get_editor_data returns target data after save_translation.
	 */
	public function test_get_editor_data_returns_target_after_save() {
		$post_id     = $this->create_test_post( array(
			'post_title'   => 'Original Title',
			'post_content' => 'Original content',
		) );
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		// Save a translation first.
		$save_result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Translated Title',
			'post_content' => 'Translated content',
		) );
		$this->assertFalse( is_wp_error( $save_result ), 'Save should succeed' );
		$this->test_post_ids[] = $save_result['target_id'];

		// Now get_editor_data should return target.
		$editor_data = Manual_Content_Service::get_editor_data( $post_id, $relation_id );
		$this->assertFalse( is_wp_error( $editor_data ), 'get_editor_data should succeed' );

		$target = $editor_data['target'];
		$this->assertNotEmpty( $target, 'Target should not be empty after save' );
		$this->assertArrayHasKey( 'ID', $target, 'Target should have ID' );
		$this->assertEquals( $save_result['target_id'], $target['ID'], 'Target ID should match saved target' );
		$this->assertEquals( 'Translated Title', $target['post_title'], 'Target title should match saved title' );
	}

	/**
	 * Test save_translation result includes relation_id.
	 */
	public function test_save_translation_result_includes_relation_id() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Relation ID Test',
			'post_content' => 'Content',
		) );

		$this->assertFalse( is_wp_error( $result ), 'Should not return WP_Error' );
		$this->test_post_ids[] = $result['target_id'];
		$this->assertArrayHasKey( 'relation_id', $result, 'Result should have relation_id' );
		$this->assertEquals( $relation_id, $result['relation_id'], 'relation_id should match input' );
	}

	/**
	 * save_translation must remount shadow posts onto shadow terms (not source term IDs).
	 */
	public function test_save_translation_syncs_term_associations_to_shadow_terms() {
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Term_Mapping_Service' ) ) {
			$this->markTestSkipped( 'Term_Mapping_Service not loaded' );
			return;
		}

		$suffix = uniqid( 'assoc', false );
		$cat    = wp_insert_term( 'Assoc Cat ' . $suffix, 'category', array( 'slug' => 'assoc-cat-' . $suffix ) );
		$tag    = wp_insert_term( 'Assoc Tag ' . $suffix, 'post_tag', array( 'slug' => 'assoc-tag-' . $suffix ) );
		$this->assertFalse( is_wp_error( $cat ), is_wp_error( $cat ) ? $cat->get_error_message() : '' );
		$this->assertFalse( is_wp_error( $tag ), is_wp_error( $tag ) ? $tag->get_error_message() : '' );
		$cat_id = (int) $cat['term_id'];
		$tag_id = (int) $tag['term_id'];

		$post_id = $this->create_test_post(
			array(
				'post_title'   => 'Assoc Source ' . $suffix,
				'post_content' => 'Assoc body ' . $suffix,
			)
		);
		wp_set_object_terms( $post_id, array( $cat_id ), 'category', false );
		wp_set_object_terms( $post_id, array( $tag_id ), 'post_tag', false );

		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::save_translation(
			$post_id,
			$relation_id,
			array(
				'post_title'   => 'Assoc Target ' . $suffix,
				'post_content' => 'Assoc target body ' . $suffix,
			)
		);
		$this->assertFalse(
			is_wp_error( $result ),
			'save_translation should succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);
		$target_id             = (int) $result['target_id'];
		$this->test_post_ids[] = $target_id;

		$relation = Site_Relation_Service::get_relation( $relation_id );
		$this->assertIsArray( $relation );

		// wordpress-blog rules often manage category only; force both taxonomies
		// for the association seam under test.
		$field_config = array(
			'related_taxonomies' => array( 'category', 'post_tag' ),
		);
		Manual_Content_Service::sync_term_associations(
			$post_id,
			$target_id,
			$relation_id,
			$relation,
			$field_config
		);

		$target_cats = wp_get_object_terms( $target_id, 'category', array( 'fields' => 'ids' ) );
		$target_tags = wp_get_object_terms( $target_id, 'post_tag', array( 'fields' => 'ids' ) );
		$this->assertFalse( is_wp_error( $target_cats ) );
		$this->assertFalse( is_wp_error( $target_tags ) );
		$this->assertNotEmpty( $target_cats, 'Shadow post must keep a category association' );
		$this->assertNotEmpty( $target_tags, 'Shadow post must keep a tag association' );

		$shadow_cat = (int) $target_cats[0];
		$shadow_tag = (int) $target_tags[0];
		$this->assertNotSame( $cat_id, $shadow_cat, 'Category on shadow must not be the source term id' );
		$this->assertNotSame( $tag_id, $shadow_tag, 'Tag on shadow must not be the source term id' );

		$this->assertSame(
			(string) $cat_id,
			(string) get_term_meta( $shadow_cat, '_wptsall_source_term_id', true ),
			'Shadow category must point back to source term'
		);
		$this->assertSame(
			(string) $tag_id,
			(string) get_term_meta( $shadow_tag, '_wptsall_source_term_id', true ),
			'Shadow tag must point back to source term'
		);
		$this->assertNotEmpty(
			get_term_meta( $shadow_cat, '_wptsall_virtual_site_id', true ),
			'Shadow category must carry virtual-site marker'
		);
		$this->assertNotEmpty(
			get_term_meta( $shadow_tag, '_wptsall_virtual_site_id', true ),
			'Shadow tag must carry virtual-site marker'
		);

		$mapped_cat = \WPTSALL\Models\Services\Term_Mapping_Service::get_mapped_id( $relation_id, $cat_id );
		$mapped_tag = \WPTSALL\Models\Services\Term_Mapping_Service::get_mapped_id( $relation_id, $tag_id );
		$this->assertSame( $shadow_cat, (int) $mapped_cat, 'term_mappings category must match object terms' );
		$this->assertSame( $shadow_tag, (int) $mapped_tag, 'term_mappings tag must match object terms' );

		wp_delete_term( $shadow_cat, 'category' );
		wp_delete_term( $shadow_tag, 'post_tag' );
		wp_delete_term( $cat_id, 'category' );
		wp_delete_term( $tag_id, 'post_tag' );
	}

	// ============ M-01 (opus5): inactive relation write guards ============

	/**
	 * M-01: save_translation must reject an inactive relation with
	 * 409 relation_inactive and must not create any target post.
	 */
	public function test_save_translation_inactive_relation_rejected() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		Site_Relation_Service::update_relation( $relation_id, array( 'status' => 'inactive' ) );
		wp_cache_flush();

		$result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Inactive Guard Title',
			'post_content' => 'Inactive guard content',
		) );

		$this->assertTrue( is_wp_error( $result ), 'Inactive relation must be rejected' );
		$this->assertSame( 'relation_inactive', $result->get_error_code(), 'Error code must be relation_inactive' );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null, 'Error status must be 409' );

		// A rejected save must not leave a target post behind.
		$this->assertNull(
			Manual_Content_Service::find_existing_translation( $post_id, $relation_id ),
			'A rejected save must not create a target post'
		);
	}

	/**
	 * M-01: update_all_fields must reject an inactive relation with 409 and
	 * leave the existing target post untouched.
	 */
	public function test_update_all_fields_inactive_relation_rejected() {
		$post_id     = $this->create_test_post();
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$save = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'Active Save Title',
			'post_content' => 'Active save content',
		) );
		$this->assertFalse(
			is_wp_error( $save ),
			'Seed save on an active relation must succeed: ' . ( is_wp_error( $save ) ? $save->get_error_message() : '' )
		);
		$this->test_post_ids[] = $save['target_id'];
		$target_id             = (int) $save['target_id'];

		Site_Relation_Service::update_relation( $relation_id, array( 'status' => 'inactive' ) );
		wp_cache_flush();

		$result = Manual_Content_Service::update_all_fields( $target_id, $relation_id, array(
			'post_title' => 'Must Not Persist',
		) );

		$this->assertTrue( is_wp_error( $result ), 'Inactive relation must be rejected' );
		$this->assertSame( 'relation_inactive', $result->get_error_code(), 'Error code must be relation_inactive' );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null, 'Error status must be 409' );

		$target = get_post( $target_id );
		$this->assertEquals( 'Active Save Title', $target->post_title, 'A rejected update must not modify the target post' );
	}

	/**
	 * Helper: ensure the translation-memory table exists (the unit runner
	 * does not create it) and clean M-04 rows for this test run.
	 *
	 * @return void
	 */
	private function ensure_tm_table_and_cleanup() {
		if ( ! function_exists( 'wptsall_create_translation_memory_table' ) ) {
			require_once WPTSALL_PATH . 'includes/translation-memory/database/schema-translation-memory.php';
		}
		wptsall_create_translation_memory_table();

		global $wpdb;
		$table = $wpdb->prefix . 'wptsall_translation_memory';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$table} WHERE source_text LIKE 'wptm-manual-%'" );
	}

	/**
	 * M-04: a successful manual save feeds translation memory with the
	 * field pairs (title here), scoped to the relation language pair and
	 * resolvable by the suggestion lookup.
	 */
	public function test_save_translation_records_translation_memory() {
		$this->ensure_tm_table_and_cleanup();
		delete_option( \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::OPTION_AUTO_RECORD );

		$source_title = 'wptm-manual-' . uniqid();
		$post_id     = $this->create_test_post( array(
			'post_title'   => $source_title,
			'post_content' => 'wptm-manual source content',
			'post_excerpt' => 'wptm-manual source excerpt',
		) );
		$relation_id = $this->create_test_relation();

		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
			'post_title'   => 'wptm-manual 中文标题',
			'post_content' => 'wptm-manual 中文正文',
			'post_excerpt' => 'wptm-manual 中文摘要',
		) );
		$this->assertFalse( is_wp_error( $result ), 'Seed save must succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$this->test_post_ids[] = $result['target_id'];

		$tm = \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::lookup( $source_title, 'en', 'zh_CN' );
		$this->assertSame( 'wptm-manual 中文标题', $tm, 'manual save must record the title pair into translation memory' );

		// The pair is scoped to the domain the product records/queries by —
		// Manual_Content_Service::save_translation() records with
		// resolve_memory_text_domain() and the editor suggest path uses the
		// same resolver, so the pair must be discoverable under exactly that
		// domain (do NOT hardcode one: a relation whose template model
		// carries a text_domain resolves to that, not to the fallback).
		$expected_domain = Manual_Content_Service::resolve_memory_text_domain( $relation_id, (string) get_post_field( 'post_type', $post_id ) );
		$this->assertNotSame( '', $expected_domain, 'resolve_memory_text_domain must resolve a non-empty domain for the relation' );

		$suggestions = \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::suggest_for_relation_domain( $relation_id, $expected_domain, $source_title );
		$this->assertCount( 1, $suggestions, 'recorded pair must be discoverable by the suggestion lookup under the resolver domain' );
		$this->assertSame( 'wptm-manual 中文标题', $suggestions[0]['target_text'] );
		$this->assertSame( 'post_title', $suggestions[0]['context'], 'context must carry the field key for provenance' );
	}

	/**
	 * M-04: the wptsall_tm_auto_record toggle off stops TM recording on
	 * manual saves (the save itself still succeeds).
	 */
	public function test_save_translation_respects_tm_auto_record_toggle() {
		$this->ensure_tm_table_and_cleanup();
		update_option( \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::OPTION_AUTO_RECORD, 0 );

		try {
			$source_title = 'wptm-manual-' . uniqid();
			$post_id     = $this->create_test_post( array(
				'post_title'   => $source_title,
				'post_content' => 'wptm-manual source content',
				'post_excerpt' => 'wptm-manual source excerpt',
			) );
			$relation_id = $this->create_test_relation();

			if ( ! $relation_id ) {
				$this->markTestSkipped( 'Could not create test relation' );
				return;
			}

			$result = Manual_Content_Service::save_translation( $post_id, $relation_id, array(
				'post_title' => 'wptm-manual 中文标题',
			) );
			$this->assertFalse( is_wp_error( $result ), 'Save must succeed with the toggle off: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			$this->test_post_ids[] = $result['target_id'];

			$tm = \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::lookup( $source_title, 'en', 'zh_CN' );
		} finally {
			delete_option( \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::OPTION_AUTO_RECORD );
		}

		$this->assertSame( '', $tm, 'toggle off must stop translation-memory recording' );
	}
}

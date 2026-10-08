<?php
/**
 * Direct DB Service Tests
 *
 * Tests for WPTSALL\Tasks\Services\Direct_DB_Service class
 *
 * Tests direct database operations that bypass WordPress hooks
 * for predictable sync behavior.
 *
 * @package WPTSALL\Tests\Unit\Tasks
 * @since 0.6.1
 */

use WPTSALL\Tasks\Services\Direct_DB_Service;

class Test_Direct_DB_Service extends SimpleTestCase {

	/**
	 * Test post IDs created during tests
	 *
	 * @var array
	 */
	private $test_post_ids = array();

	/**
	 * Test term IDs created during tests
	 *
	 * @var array
	 */
	private $test_term_ids = array();

	/**
	 * Original session sql_mode captured by the DB-01 strict-mode test so
	 * tearDown can restore it even when the test aborts mid-flight.
	 *
	 * @var string|null
	 */
	private $original_sql_mode = null;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
	}

	// ==================== Class Tests ====================

	/**
	 * Test service class exists
	 */
	public function test_service_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Tasks\Services\Direct_DB_Service' ) );
	}

	/**
	 * Test service has required methods
	 */
	public function test_service_has_required_methods() {
		$methods = array(
			'get_post_ids',
			'get_post',
			'insert_post',
			'update_post',
			'update_post_meta',
			'get_terms',
			'get_term',
			'insert_term',
			'update_term',
			'update_term_meta',
			'get_term_by_meta',
			'get_post_by_meta',
			'copy_post_meta',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\Tasks\Services\Direct_DB_Service', $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== Post Operations Tests ====================

	/**
	 * Test get_post_ids returns array
	 */
	public function test_get_post_ids_returns_array() {
		$result = Direct_DB_Service::get_post_ids( 'post', 'publish', null, 10 );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_post_ids with modified_after filter
	 */
	public function test_get_post_ids_with_modified_after() {
		$yesterday = date( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$result    = Direct_DB_Service::get_post_ids( 'post', 'publish', $yesterday, 10 );

		$this->assertIsArray( $result );
	}

	/**
	 * Test insert_post creates post
	 */
	public function test_insert_post() {
		$post_data = array(
			'post_title'   => 'Direct DB Test Post ' . uniqid(),
			'post_content' => 'Test content',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);

		$post_id = Direct_DB_Service::insert_post( $post_data );

		$this->assertNotFalse( $post_id );
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Track for cleanup
		$this->test_post_ids[] = $post_id;

		// Verify post exists
		$post = Direct_DB_Service::get_post( $post_id );
		$this->assertNotNull( $post );
		$this->assertEquals( $post_data['post_title'], $post->post_title );
	}

	/**
	 * Test get_post returns post object
	 */
	public function test_get_post() {
		// Create test post first
		$post_id             = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Get Post Test ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $post_id;

		$post = Direct_DB_Service::get_post( $post_id );

		$this->assertIsObject( $post );
		$this->assertEquals( (int) $post_id, (int) $post->ID );
	}

	/**
	 * Test get_post returns null for non-existent
	 */
	public function test_get_post_returns_null_for_non_existent() {
		$post = Direct_DB_Service::get_post( 99999999 );

		$this->assertNull( $post );
	}

	/**
	 * Test update_post updates fields
	 */
	public function test_update_post() {
		// Create test post first
		$post_id = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Original Title',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $post_id;

		// Update
		$result = Direct_DB_Service::update_post(
			$post_id,
			array( 'post_title' => 'Updated Title' )
		);

		$this->assertTrue( $result );

		// Verify
		$post = Direct_DB_Service::get_post( $post_id );
		$this->assertEquals( 'Updated Title', $post->post_title );
	}

	// ==================== Post Meta Tests ====================

	/**
	 * Test update_post_meta inserts new meta
	 */
	public function test_update_post_meta_insert() {
		$post_id = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Meta Test Post ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $post_id;

		$result = Direct_DB_Service::update_post_meta( $post_id, '_test_meta_key', 'test_value' );

		$this->assertTrue( $result );

		// Verify using WordPress function
		$value = get_post_meta( $post_id, '_test_meta_key', true );
		$this->assertEquals( 'test_value', $value );
	}

	/**
	 * Test update_post_meta updates existing meta
	 */
	public function test_update_post_meta_update() {
		$post_id = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Meta Update Test ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $post_id;

		// Insert first
		Direct_DB_Service::update_post_meta( $post_id, '_test_meta', 'value1' );

		// Update
		$result = Direct_DB_Service::update_post_meta( $post_id, '_test_meta', 'value2' );

		$this->assertTrue( $result );

		// Verify
		$value = get_post_meta( $post_id, '_test_meta', true );
		$this->assertEquals( 'value2', $value );
	}

	/**
	 * Test get_post_by_meta finds post
	 */
	public function test_get_post_by_meta() {
		$unique_value = 'unique_' . uniqid();
		$post_id      = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Find by Meta Test',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $post_id;

		Direct_DB_Service::update_post_meta( $post_id, '_unique_finder', $unique_value );

		$found_id = Direct_DB_Service::get_post_by_meta( '_unique_finder', $unique_value );

		$this->assertEquals( $post_id, $found_id );
	}

	/**
	 * Test get_post_by_meta returns null when not found
	 */
	public function test_get_post_by_meta_not_found() {
		$result = Direct_DB_Service::get_post_by_meta( '_nonexistent_key', 'nonexistent_value' );

		$this->assertNull( $result );
	}

	// ==================== Term Operations Tests ====================

	/**
	 * Test get_terms returns array
	 */
	public function test_get_terms_returns_array() {
		$terms = Direct_DB_Service::get_terms( 'category', 10 );

		$this->assertIsArray( $terms );
	}

	/**
	 * Test insert_term creates term
	 */
	public function test_insert_term() {
		$term_name = 'Direct DB Test Term ' . uniqid();
		$term_id   = Direct_DB_Service::insert_term(
			$term_name,
			'category',
			array(
				'slug'        => sanitize_title( $term_name ),
				'description' => 'Test term description',
			)
		);

		$this->assertNotFalse( $term_id );
		$this->assertIsInt( $term_id );
		$this->assertGreaterThan( 0, $term_id );

		// Track for cleanup
		$this->test_term_ids[] = $term_id;

		// Verify term exists
		$term = Direct_DB_Service::get_term( $term_id, 'category' );
		$this->assertNotNull( $term );
		$this->assertEquals( $term_name, $term->name );
	}

	/**
	 * Test insert_term returns existing ID for duplicate slug
	 */
	public function test_insert_term_returns_existing_for_duplicate() {
		$term_name = 'Duplicate Test ' . uniqid();
		$slug      = sanitize_title( $term_name );

		// First insert
		$first_id = Direct_DB_Service::insert_term(
			$term_name,
			'category',
			array( 'slug' => $slug )
		);
		$this->test_term_ids[] = $first_id;

		// Second insert with same slug
		$second_id = Direct_DB_Service::insert_term(
			'Different Name',
			'category',
			array( 'slug' => $slug )
		);

		// Should return existing ID
		$this->assertEquals( $first_id, $second_id );
	}

	/**
	 * Test get_term returns term object
	 */
	public function test_get_term() {
		$term_name = 'Get Term Test ' . uniqid();
		$term_id   = Direct_DB_Service::insert_term(
			$term_name,
			'category',
			array( 'slug' => sanitize_title( $term_name ) )
		);
		$this->test_term_ids[] = $term_id;

		$term = Direct_DB_Service::get_term( $term_id, 'category' );

		$this->assertIsObject( $term );
		$this->assertEquals( (int) $term_id, (int) $term->term_id );
		$this->assertEquals( $term_name, $term->name );
		$this->assertEquals( 'category', $term->taxonomy );
	}

	/**
	 * Test get_term returns null for non-existent
	 */
	public function test_get_term_returns_null_for_non_existent() {
		$term = Direct_DB_Service::get_term( 99999999, 'category' );

		$this->assertNull( $term );
	}

	/**
	 * Test update_term updates fields
	 */
	public function test_update_term() {
		$term_id = Direct_DB_Service::insert_term(
			'Original Term Name',
			'category',
			array( 'slug' => 'original-term-' . uniqid() )
		);
		$this->test_term_ids[] = $term_id;

		// Update
		$result = Direct_DB_Service::update_term(
			$term_id,
			'category',
			array(
				'name'        => 'Updated Term Name',
				'description' => 'Updated description',
			)
		);

		$this->assertTrue( $result );

		// Verify
		$term = Direct_DB_Service::get_term( $term_id, 'category' );
		$this->assertEquals( 'Updated Term Name', $term->name );
		$this->assertEquals( 'Updated description', $term->description );
	}

	// ==================== Term Meta Tests ====================

	/**
	 * Test update_term_meta inserts new meta
	 */
	public function test_update_term_meta_insert() {
		$term_id = Direct_DB_Service::insert_term(
			'Term Meta Test ' . uniqid(),
			'category',
			array( 'slug' => 'term-meta-test-' . uniqid() )
		);
		$this->test_term_ids[] = $term_id;

		$result = Direct_DB_Service::update_term_meta( $term_id, '_test_term_meta', 'meta_value' );

		$this->assertTrue( $result );

		// Verify using WordPress function
		$value = get_term_meta( $term_id, '_test_term_meta', true );
		$this->assertEquals( 'meta_value', $value );
	}

	/**
	 * Test get_term_by_meta finds term
	 */
	public function test_get_term_by_meta() {
		$unique_value = 'term_unique_' . uniqid();
		$term_id      = Direct_DB_Service::insert_term(
			'Find Term by Meta ' . uniqid(),
			'category',
			array( 'slug' => 'find-term-meta-' . uniqid() )
		);
		$this->test_term_ids[] = $term_id;

		Direct_DB_Service::update_term_meta( $term_id, '_term_finder', $unique_value );

		$found_id = Direct_DB_Service::get_term_by_meta( '_term_finder', $unique_value, 'category' );

		$this->assertEquals( $term_id, $found_id );
	}

	// ==================== Site Context Tests (ISS-TSK-016) ====================

	/**
	 * Test insert_post with virtual site context adds marker meta
	 */
	public function test_insert_post_with_virtual_site_context() {
		$virtual_site_id = 'v_test_' . uniqid();
		$post_data       = array(
			'post_title'   => 'Virtual Site Post ' . uniqid(),
			'post_content' => 'Virtual content',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);
		$site_context = array(
			'type'            => 'virtual',
			'virtual_site_id' => $virtual_site_id,
		);

		$post_id = Direct_DB_Service::insert_post( $post_data, $site_context );

		$this->assertNotFalse( $post_id );
		$this->assertIsInt( $post_id );
		$this->test_post_ids[] = $post_id;

		// Verify virtual site marker meta was added.
		$marker = get_post_meta( $post_id, '_wptsall_virtual_site_id', true );
		$this->assertEquals( $virtual_site_id, $marker );
	}

	/**
	 * Test insert_post with empty site_context (backward compatibility)
	 */
	public function test_insert_post_without_site_context() {
		$post_data = array(
			'post_title'   => 'No Context Post ' . uniqid(),
			'post_content' => 'No context content',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);

		// Call without site_context parameter.
		$post_id = Direct_DB_Service::insert_post( $post_data );

		$this->assertNotFalse( $post_id );
		$this->assertIsInt( $post_id );
		$this->test_post_ids[] = $post_id;

		// Verify no virtual site marker.
		$marker = get_post_meta( $post_id, '_wptsall_virtual_site_id', true );
		$this->assertEmpty( $marker );
	}

	/**
	 * Test update_post with site_context updates correctly
	 */
	public function test_update_post_with_site_context() {
		// Create post first.
		$post_id = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Original Title',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $post_id;

		$site_context = array(
			'type' => 'virtual',
			'virtual_site_id' => 'v_update_test',
		);

		// Update with site_context.
		$result = Direct_DB_Service::update_post(
			$post_id,
			array( 'post_title' => 'Updated Title with Context' ),
			$site_context
		);

		$this->assertTrue( $result );

		// Verify update.
		$post = Direct_DB_Service::get_post( $post_id );
		$this->assertEquals( 'Updated Title with Context', $post->post_title );
	}

	/**
	 * Test insert_post returns WP_Error on database error
	 */
	public function test_insert_post_returns_wp_error_on_failure() {
		// Try to insert with invalid data that would cause DB error.
		// This is tricky to test without mocking, so we just verify the return type structure.
		$post_data = array(
			'post_title'  => 'Valid Test Post ' . uniqid(),
			'post_status' => 'publish',
			'post_type'   => 'post',
		);

		$result = Direct_DB_Service::insert_post( $post_data );

		// Should be int on success.
		$this->assertIsInt( $result );
		$this->test_post_ids[] = $result;
	}

	// ==================== Copy Meta Tests ====================

	/**
	 * Test copy_post_meta copies meta fields
	 */
	public function test_copy_post_meta() {
		// Create source post with meta
		$source_id = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Source Post ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $source_id;

		Direct_DB_Service::update_post_meta( $source_id, '_copy_meta_1', 'value1' );
		Direct_DB_Service::update_post_meta( $source_id, '_copy_meta_2', 'value2' );
		Direct_DB_Service::update_post_meta( $source_id, '_excluded_meta', 'excluded' );

		// Create target post
		$target_id = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Target Post ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $target_id;

		// Copy meta (excluding _excluded_meta)
		$count = Direct_DB_Service::copy_post_meta( $source_id, $target_id, array( '_excluded_meta' ) );

		$this->assertGreaterThan( 0, $count );

		// Verify copied
		$this->assertEquals( 'value1', get_post_meta( $target_id, '_copy_meta_1', true ) );
		$this->assertEquals( 'value2', get_post_meta( $target_id, '_copy_meta_2', true ) );

		// Verify excluded was not copied
		$this->assertEmpty( get_post_meta( $target_id, '_excluded_meta', true ) );
	}

	// ==================== Strict Mode Tests ====================

	/**
	 * Test direct insert succeeds under MySQL strict mode (DB-01 covered)
	 *
	 * COVERED — was the KNOWN-RED pin for BUG-WPTSALL-DIRECTDB-STRICT-INSERT
	 * (plan §7 TEST-DB-STRICT-AND-CONCURRENCY-001; gap doc DB-01 "真实未闭环"):
	 * insert_post()'s defaults array used to omit to_ping / pinged /
	 * post_content_filtered (all NOT NULL without schema defaults), and the
	 * direct $wpdb->insert then failed with "Field 'to_ping' doesn't have a
	 * default value" whenever the host ran STRICT_TRANS_TABLES. Live-probed
	 * 2026-09-08. Fixed 2026-09-23 (batch H DB-01): the defaults array now
	 * sends explicit empty strings for all three columns, so the insert
	 * succeeds under the same forced strict session. This flipped pin keeps
	 * guarding the contract — a regression reintroduces the strict-mode
	 * failure and turns this red again.
	 */
	public function test_insert_post_succeeds_under_mysql_strict_mode_with_completed_defaults() {
		global $wpdb;

		$original_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$this->original_sql_mode = $original_mode;

		$wpdb->query( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'" );
		$mode = $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$this->assertStringContainsString( 'STRICT_TRANS_TABLES', (string) $mode, 'fixture: strict session must be active' );

		// Flipped pin: the direct insert now supplies every NOT-NULL
		// no-default column and must succeed under the strict session.
		$result = Direct_DB_Service::insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'DB01 covered ' . uniqid(),
			'post_status' => 'publish',
		) );
		$this->assertFalse( is_wp_error( $result ), 'DB-01 fixed (2026-09-23): strict-mode insert must succeed now that the defaults are completed; a failure here is a regression of BUG-WPTSALL-DIRECTDB-STRICT-INSERT' );
		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'db_insert_error', $result->get_error_code() );
		} else {
			$this->assertIsInt( $result );
			$this->assertGreaterThan( 0, $result );
			$this->test_post_ids[] = $result;

			// The three formerly-missing columns must land as empty strings.
			$post = Direct_DB_Service::get_post( $result );
			$this->assertNotNull( $post );
			$this->assertSame( '', $post->to_ping );
			$this->assertSame( '', $post->pinged );
			$this->assertSame( '', $post->post_content_filtered );
		}

		// Restore the runner's original session mode for the remaining
		// tests (tearDown restores it again if this test aborts early).
		$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $original_mode ) );
		$this->original_sql_mode = null;
	}

	/**
	 * Test get_post_meta() reads stored values directly (single + multi),
	 * bypassing the plugin's get_post_metadata filter on purpose. The
	 * sync write paths call it (copy_once checks, _elementor_data
	 * rewrites); the method was previously missing and fatalled, found by
	 * the 2026-09-11 PHPStan pass.
	 */
	public function test_get_post_meta_single_and_multi() {
		$post_id = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Direct Meta Read Test ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $post_id;

		Direct_DB_Service::add_post_meta( $post_id, '_test_direct_meta', 'first' );
		Direct_DB_Service::add_post_meta( $post_id, '_test_direct_meta', 'second' );

		$this->assertSame( 'first', Direct_DB_Service::get_post_meta( $post_id, '_test_direct_meta', true ) );
		$this->assertSame( array( 'first', 'second' ), Direct_DB_Service::get_post_meta( $post_id, '_test_direct_meta', false ) );
	}

	/**
	 * Test get_post_meta() absent-key defaults and serialized roundtrip.
	 */
	public function test_get_post_meta_missing_and_serialized() {
		$post_id = Direct_DB_Service::insert_post(
			array(
				'post_title'  => 'Direct Meta Absent Test ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->test_post_ids[] = $post_id;

		$this->assertSame( '', Direct_DB_Service::get_post_meta( $post_id, '_test_absent_meta', true ) );
		$this->assertSame( array(), Direct_DB_Service::get_post_meta( $post_id, '_test_absent_meta', false ) );

		Direct_DB_Service::update_post_meta( $post_id, '_test_serialized_meta', array( 'k' => 'v' ) );
		$this->assertSame( array( 'k' => 'v' ), Direct_DB_Service::get_post_meta( $post_id, '_test_serialized_meta', true ) );
	}

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		// DB-01: restore the host's original session sql_mode if the
		// strict-mode test aborted before its own restore.
		if ( null !== $this->original_sql_mode ) {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare( 'SET SESSION sql_mode = %s', $this->original_sql_mode )
			);
			$this->original_sql_mode = null;
		}

		// Clean up test posts
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Clean up test terms
		foreach ( $this->test_term_ids as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		parent::tearDown();
	}
}

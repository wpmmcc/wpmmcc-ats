<?php
/**
 * Virtual Site Storage Tests
 *
 * Tests for virtual site content storage using wp_posts/wp_terms + meta (v0.8.0+).
 *
 * In v0.8.0+, virtual site content is stored in WordPress native tables:
 * - Posts: wp_posts with _wptsall_virtual_site_id, _wptsall_source_post_id meta
 * - Terms: wp_terms with _wptsall_virtual_site_id, _wptsall_source_term_id meta
 *
 * @package WPTSALL
 * @since 0.8.0
 */

class Test_Virtual_Site_Storage extends SimpleTestCase {

	/**
	 * Test post IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_post_ids = array();

	/**
	 * Test term IDs for cleanup
	 *
	 * @var array
	 */
	protected $test_term_ids = array();

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Clean up test data
	 */
	public function tearDown(): void {
		// Delete test posts
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Delete test terms
		foreach ( $this->test_term_ids as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		parent::tearDown();
	}

	/**
	 * Helper to create a virtual site post using v0.8.0 native storage
	 *
	 * @param array  $post_data       Post data.
	 * @param string $virtual_site_id Virtual site identifier (e.g., 'v_123').
	 * @param int    $source_post_id  Source post ID.
	 * @param int    $source_blog_id  Source blog ID.
	 * @return int|WP_Error Post ID or error.
	 */
	private function create_virtual_post( $post_data, $virtual_site_id, $source_post_id, $source_blog_id = 1 ) {
		$defaults = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
		);

		$post_data = array_merge( $defaults, $post_data );
		$post_id   = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Add virtual site meta markers
		update_post_meta( $post_id, '_wptsall_virtual_site_id', $virtual_site_id );
		update_post_meta( $post_id, '_wptsall_source_post_id', $source_post_id );
		update_post_meta( $post_id, '_wptsall_source_blog_id', $source_blog_id );
		update_post_meta( $post_id, '_wptsall_last_synced', current_time( 'mysql' ) );

		$this->test_post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Helper to create a virtual site term using v0.8.0 native storage
	 *
	 * @param string $name            Term name.
	 * @param string $taxonomy        Taxonomy name.
	 * @param string $virtual_site_id Virtual site identifier.
	 * @param int    $source_term_id  Source term ID.
	 * @param int    $source_blog_id  Source blog ID.
	 * @return int|WP_Error Term ID or error.
	 */
	private function create_virtual_term( $name, $taxonomy, $virtual_site_id, $source_term_id, $source_blog_id = 1 ) {
		$result = wp_insert_term( $name, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = $result['term_id'];

		// Add virtual site meta markers
		update_term_meta( $term_id, '_wptsall_virtual_site_id', $virtual_site_id );
		update_term_meta( $term_id, '_wptsall_source_term_id', $source_term_id );
		update_term_meta( $term_id, '_wptsall_source_blog_id', $source_blog_id );
		update_term_meta( $term_id, '_wptsall_last_synced', current_time( 'mysql' ) );

		$this->test_term_ids[] = $term_id;

		return $term_id;
	}

	// ==================== Post Storage Tests ====================

	/**
	 * Test: Virtual site post is stored in wp_posts with meta markers
	 */
	public function test_virtual_site_post_stored_in_wp_posts() {
		$virtual_site_id = 'v_' . mt_rand( 1000, 9999 );
		$source_post_id  = mt_rand( 1, 1000 );

		$post_id = $this->create_virtual_post(
			array(
				'post_title'   => 'Virtual Test Post',
				'post_content' => 'Virtual test content',
			),
			$virtual_site_id,
			$source_post_id
		);

		$this->assertIsInt( $post_id, 'Post should be created successfully' );
		$this->assertGreaterThan( 0, $post_id, 'Post ID should be positive' );

		// Verify post exists in wp_posts
		$post = get_post( $post_id );
		$this->assertNotNull( $post, 'Post should exist in wp_posts' );
		$this->assertEquals( 'Virtual Test Post', $post->post_title );
	}

	/**
	 * Test: Virtual site post has correct meta markers
	 */
	public function test_virtual_site_post_has_meta_markers() {
		$virtual_site_id = 'v_' . mt_rand( 1000, 9999 );
		$source_post_id  = mt_rand( 1, 1000 );
		$source_blog_id  = 1;

		$post_id = $this->create_virtual_post(
			array(
				'post_title'   => 'Meta Test Post',
				'post_content' => 'Content for meta test',
			),
			$virtual_site_id,
			$source_post_id,
			$source_blog_id
		);

		// Verify meta markers
		$this->assertEquals(
			$virtual_site_id,
			get_post_meta( $post_id, '_wptsall_virtual_site_id', true ),
			'Should have correct virtual site ID'
		);

		$this->assertEquals(
			$source_post_id,
			(int) get_post_meta( $post_id, '_wptsall_source_post_id', true ),
			'Should have correct source post ID'
		);

		$this->assertEquals(
			$source_blog_id,
			(int) get_post_meta( $post_id, '_wptsall_source_blog_id', true ),
			'Should have correct source blog ID'
		);

		$this->assertNotEmpty(
			get_post_meta( $post_id, '_wptsall_last_synced', true ),
			'Should have last synced timestamp'
		);
	}

	/**
	 * Test: Virtual site post stores additional meta data
	 */
	public function test_virtual_site_post_stores_regular_meta() {
		$virtual_site_id = 'v_' . mt_rand( 1000, 9999 );
		$source_post_id  = mt_rand( 1, 1000 );

		$post_id = $this->create_virtual_post(
			array(
				'post_title'   => 'Regular Meta Test Post',
				'post_content' => 'Content with custom meta',
			),
			$virtual_site_id,
			$source_post_id
		);

		// Add additional meta
		update_post_meta( $post_id, 'custom_field_1', 'value_1' );
		update_post_meta( $post_id, 'custom_field_2', 'value_2' );

		// Verify custom meta is stored
		$this->assertEquals(
			'value_1',
			get_post_meta( $post_id, 'custom_field_1', true ),
			'Should store custom_field_1'
		);

		$this->assertEquals(
			'value_2',
			get_post_meta( $post_id, 'custom_field_2', true ),
			'Should store custom_field_2'
		);

		// Verify virtual site markers are still present
		$this->assertEquals(
			$virtual_site_id,
			get_post_meta( $post_id, '_wptsall_virtual_site_id', true ),
			'Virtual site ID should still be present'
		);
	}

	// ==================== Term Storage Tests ====================

	/**
	 * Test: Virtual site term is stored in wp_terms with meta markers
	 */
	public function test_virtual_site_term_stored_in_wp_terms() {
		$virtual_site_id = 'v_' . mt_rand( 1000, 9999 );
		$source_term_id  = mt_rand( 1, 1000 );
		$term_name       = 'Virtual Category ' . uniqid();

		$term_id = $this->create_virtual_term(
			$term_name,
			'category',
			$virtual_site_id,
			$source_term_id
		);

		if ( is_wp_error( $term_id ) ) {
			$this->markTestSkipped( 'Could not create term: ' . $term_id->get_error_message() );
			return;
		}

		$this->assertIsInt( $term_id, 'Term should be created successfully' );
		$this->assertGreaterThan( 0, $term_id, 'Term ID should be positive' );

		// Verify term exists in wp_terms
		$term = get_term( $term_id, 'category' );
		$this->assertNotNull( $term, 'Term should exist in wp_terms' );
		$this->assertFalse( $term instanceof \WP_Error, 'Term should not be WP_Error' );
		$this->assertEquals( $term_name, $term->name );
	}

	/**
	 * Test: Virtual site term has correct meta markers
	 */
	public function test_virtual_site_term_has_meta_markers() {
		$virtual_site_id = 'v_' . mt_rand( 1000, 9999 );
		$source_term_id  = mt_rand( 1, 1000 );
		$source_blog_id  = 1;
		$term_name       = 'Meta Category ' . uniqid();

		$term_id = $this->create_virtual_term(
			$term_name,
			'category',
			$virtual_site_id,
			$source_term_id,
			$source_blog_id
		);

		if ( is_wp_error( $term_id ) ) {
			$this->markTestSkipped( 'Could not create term: ' . $term_id->get_error_message() );
			return;
		}

		// Verify meta markers
		$this->assertEquals(
			$virtual_site_id,
			get_term_meta( $term_id, '_wptsall_virtual_site_id', true ),
			'Should have correct virtual site ID'
		);

		$this->assertEquals(
			$source_term_id,
			(int) get_term_meta( $term_id, '_wptsall_source_term_id', true ),
			'Should have correct source term ID'
		);

		$this->assertEquals(
			$source_blog_id,
			(int) get_term_meta( $term_id, '_wptsall_source_blog_id', true ),
			'Should have correct source blog ID'
		);

		$this->assertNotEmpty(
			get_term_meta( $term_id, '_wptsall_last_synced', true ),
			'Should have last synced timestamp'
		);
	}

	// ==================== Query Tests ====================

	/**
	 * Test: Can query virtual posts by meta
	 */
	public function test_can_query_virtual_posts_by_meta() {
		$virtual_site_id = 'v_query_' . mt_rand( 1000, 9999 );
		$source_post_id  = mt_rand( 1, 1000 );

		// Create virtual post
		$post_id = $this->create_virtual_post(
			array(
				'post_title'   => 'Query Test Post',
				'post_content' => 'Content for query test',
			),
			$virtual_site_id,
			$source_post_id
		);

		$this->assertIsInt( $post_id, 'Post should be created successfully' );
		$this->assertGreaterThan( 0, $post_id, 'Post ID should be positive' );

		// Verify meta was actually saved
		$saved_meta = get_post_meta( $post_id, '_wptsall_virtual_site_id', true );
		$this->assertEquals( $virtual_site_id, $saved_meta, 'Meta should be saved correctly' );

		// Query by virtual site ID - use direct SQL for reliable results
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND pm.meta_value = %s
			AND p.post_type = 'post' AND p.post_status = 'publish'",
			'_wptsall_virtual_site_id',
			$virtual_site_id
		);
		$found_ids = $wpdb->get_col( $sql );

		$this->assertCount( 1, $found_ids, 'Should find one post by virtual site ID via SQL' );
		$this->assertEquals( $post_id, (int) $found_ids[0], 'Should find the correct post via SQL' );

		// Query by source post ID (as string to match meta value type)
		$query2 = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => '_wptsall_virtual_site_id',
					'value' => $virtual_site_id,
				),
				array(
					'key'   => '_wptsall_source_post_id',
					'value' => (string) $source_post_id,
				),
			),
		) );

		$this->assertEquals( 1, $query2->found_posts, 'Should find one post by combined meta query' );
	}
}

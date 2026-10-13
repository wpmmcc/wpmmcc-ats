<?php
/**
 * Post Mapping Service Tests
 *
 * Tests for WPTSALL\Models\Services\Post_Mapping_Service class
 *
 * Tests post relationship ID mappings between source and target sites.
 *
 * @package WPTSALL\Tests\Unit\Models
 * @since 0.5.0
 */

use WPTSALL\Models\Services\Post_Mapping_Service;

class Test_Post_Mapping_Service extends SimpleTestCase {

	/**
	 * Test mapping IDs created during tests
	 *
	 * @var array
	 */
	private $test_mapping_ids = array();

	/**
	 * Test post IDs created during tests
	 *
	 * @var array
	 */
	private $test_post_ids = array();

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
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Post_Mapping_Service' ) );
	}

	/**
	 * Test service has required methods
	 */
	public function test_service_has_required_methods() {
		$methods = array(
			'get_mapping',
			'get_reverse_mapping',
			'create_mapping',
			'map_post_id',
			'get_all_mappings_for_source',
			'get_mappings_by_type',
			'batch_map_posts',
			'map_post_id_array',
			'map_parent_id',
			'delete_mapping',
			'delete_mappings_for_source',
			'cleanup_orphaned_mappings',
			'get_statistics',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\Models\Services\Post_Mapping_Service', $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== create_mapping() Tests ====================

	/**
	 * Test create_mapping creates new mapping
	 */
	public function test_create_mapping_success() {
		// Create test posts
		$source_post_id = $this->create_test_post( 'Source Post' );
		$target_post_id = $this->create_test_post( 'Target Post' );

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_post_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_en',
				'relationship_type' => 'translation',
			)
		);

		$this->assertNotFalse( $mapping_id );
		$this->assertIsInt( $mapping_id );
		$this->assertGreaterThan( 0, $mapping_id );

		$this->test_mapping_ids[] = $mapping_id;
	}

	public function test_create_mapping_persists_relation_id() {
		$source_post_id = $this->create_test_post( 'Source Relation Mapping' );
		$target_post_id = $this->create_test_post( 'Target Relation Mapping' );
		$relation_id    = 900042;

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_post_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_rel_map',
				'relationship_type' => 'translation',
				'relation_id'       => $relation_id,
			)
		);
		$this->assertGreaterThan( 0, $mapping_id );
		$this->test_mapping_ids[] = $mapping_id;

		$row = Post_Mapping_Service::get_mapping( $source_post_id, 'post', 1, 'v_rel_map' );
		$this->assertIsArray( $row );
		$this->assertEquals( $relation_id, (int) $row['relation_id'] );
	}

	/**
	 * Test create_mapping updates existing mapping
	 */
	public function test_create_mapping_updates_existing() {
		$source_post_id = $this->create_test_post( 'Source Update Test' );
		$target_post_id = $this->create_test_post( 'Target Update Test' );

		// Create first mapping
		$first_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_post_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_zh',
				'relationship_type' => 'translation',
			)
		);
		$this->test_mapping_ids[] = $first_id;

		// Update with same source/target
		$new_target_id = $this->create_test_post( 'New Target' );
		$second_id     = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $new_target_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_zh',
				'relationship_type' => 'translation',
			)
		);

		// Should return same mapping ID (updated)
		$this->assertEquals( $first_id, $second_id );
	}

	// ==================== get_mapping() Tests ====================

	/**
	 * Test get_mapping returns mapping
	 */
	public function test_get_mapping() {
		$source_post_id = $this->create_test_post( 'Get Mapping Source' );
		$target_post_id = $this->create_test_post( 'Get Mapping Target' );

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_post_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_de',
				'relationship_type' => 'translation',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Post_Mapping_Service::get_mapping( $source_post_id, 'post', 1, 'v_de' );

		$this->assertIsArray( $mapping );
		$this->assertEquals( $source_post_id, (int) $mapping['source_post_id'] );
		$this->assertEquals( $target_post_id, (int) $mapping['target_post_id'] );
		$this->assertEquals( 'v_de', $mapping['target_site_id'] );
	}

	/**
	 * Test get_mapping returns null for non-existent
	 */
	public function test_get_mapping_returns_null_for_non_existent() {
		$mapping = Post_Mapping_Service::get_mapping( 99999999, 'post', 1, 'v_none' );
		$this->assertNull( $mapping );
	}

	// ==================== get_reverse_mapping() Tests ====================

	/**
	 * Test get_reverse_mapping returns mapping
	 */
	public function test_get_reverse_mapping() {
		$source_post_id = $this->create_test_post( 'Reverse Source' );
		$target_post_id = $this->create_test_post( 'Reverse Target' );

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_post_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_fr',
				'relationship_type' => 'translation',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Post_Mapping_Service::get_reverse_mapping( $target_post_id, 'post', 'v_fr', 1 );

		$this->assertIsArray( $mapping );
		$this->assertEquals( $source_post_id, (int) $mapping['source_post_id'] );
		$this->assertEquals( $target_post_id, (int) $mapping['target_post_id'] );
	}

	// ==================== map_post_id() Tests ====================

	/**
	 * Test map_post_id returns target ID
	 */
	public function test_map_post_id() {
		$source_post_id = $this->create_test_post( 'Map ID Source' );
		$target_post_id = $this->create_test_post( 'Map ID Target' );

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_post_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_ja',
				'relationship_type' => 'translation',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$result = Post_Mapping_Service::map_post_id( $source_post_id, 'post', 1, 'v_ja', 'translation' );

		$this->assertEquals( $target_post_id, $result );
	}

	/**
	 * Test map_post_id returns false for non-existent
	 */
	public function test_map_post_id_returns_false_for_non_existent() {
		$result = Post_Mapping_Service::map_post_id( 99999999, 'post', 1, 'v_none', 'translation' );
		$this->assertFalse( $result );
	}

	/**
	 * Test map_post_id filters by relationship type
	 */
	public function test_map_post_id_filters_by_relationship() {
		$source_post_id = $this->create_test_post( 'Filter Source' );
		$target_post_id = $this->create_test_post( 'Filter Target' );

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_post_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_post_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_es',
				'relationship_type' => 'translation',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		// Should not find with different relationship type
		$result = Post_Mapping_Service::map_post_id( $source_post_id, 'post', 1, 'v_es', 'reference' );
		$this->assertFalse( $result );

		// Should find with correct relationship type
		$result = Post_Mapping_Service::map_post_id( $source_post_id, 'post', 1, 'v_es', 'translation' );
		$this->assertEquals( $target_post_id, $result );
	}

	// ==================== get_all_mappings_for_source() Tests ====================

	/**
	 * Test get_all_mappings_for_source returns array
	 */
	public function test_get_all_mappings_for_source() {
		$source_post_id = $this->create_test_post( 'Multi Target Source' );

		// Create multiple mappings
		for ( $i = 1; $i <= 3; $i++ ) {
			$target_id  = $this->create_test_post( "Multi Target {$i}" );
			$mapping_id = Post_Mapping_Service::create_mapping(
				array(
					'source_post_id'    => $source_post_id,
					'source_post_type'  => 'post',
					'source_site_id'    => 1,
					'target_post_id'    => $target_id,
					'target_post_type'  => 'post',
					'target_site_id'    => "v_lang{$i}",
					'relationship_type' => 'translation',
				)
			);
			$this->test_mapping_ids[] = $mapping_id;
		}

		$mappings = Post_Mapping_Service::get_all_mappings_for_source( $source_post_id, 'post', 1 );

		$this->assertIsArray( $mappings );
		$this->assertCount( 3, $mappings );
	}

	// ==================== batch_map_posts() Tests ====================

	/**
	 * Test batch_map_posts maps multiple posts
	 */
	public function test_batch_map_posts() {
		$source_ids = array();
		$target_ids = array();

		// Create source and target posts with mappings
		for ( $i = 1; $i <= 3; $i++ ) {
			$source_id  = $this->create_test_post( "Batch Source {$i}" );
			$target_id  = $this->create_test_post( "Batch Target {$i}" );
			$source_ids[] = $source_id;
			$target_ids[] = $target_id;

			$mapping_id = Post_Mapping_Service::create_mapping(
				array(
					'source_post_id'    => $source_id,
					'source_post_type'  => 'post',
					'source_site_id'    => 1,
					'target_post_id'    => $target_id,
					'target_post_type'  => 'post',
					'target_site_id'    => 'v_batch',
					'relationship_type' => 'translation',
				)
			);
			$this->test_mapping_ids[] = $mapping_id;
		}

		$mapped = Post_Mapping_Service::batch_map_posts( $source_ids, 'post', 1, 'v_batch', 'translation' );

		$this->assertIsArray( $mapped );
		$this->assertCount( 3, $mapped );

		// Verify correct mapping
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertEquals( $target_ids[ $i ], $mapped[ $source_ids[ $i ] ] );
		}
	}

	// ==================== map_post_id_array() Tests ====================

	/**
	 * Test map_post_id_array with comma-separated string
	 */
	public function test_map_post_id_array_string() {
		$source_ids = array();
		$target_ids = array();

		// Create mappings
		for ( $i = 1; $i <= 3; $i++ ) {
			$source_id  = $this->create_test_post( "Array Source {$i}" );
			$target_id  = $this->create_test_post( "Array Target {$i}" );
			$source_ids[] = $source_id;
			$target_ids[] = $target_id;

			$mapping_id = Post_Mapping_Service::create_mapping(
				array(
					'source_post_id'    => $source_id,
					'source_post_type'  => 'post',
					'source_site_id'    => 1,
					'target_post_id'    => $target_id,
					'target_post_type'  => 'post',
					'target_site_id'    => 'v_array',
					'relationship_type' => 'translation',
				)
			);
			$this->test_mapping_ids[] = $mapping_id;
		}

		// Test with comma-separated string
		$input  = implode( ',', $source_ids );
		$result = Post_Mapping_Service::map_post_id_array( $input, 'post', 1, 'v_array', 'translation' );

		$this->assertIsString( $result );
		$result_ids = array_map( 'intval', explode( ',', $result ) );
		$this->assertEquals( $target_ids, $result_ids );
	}

	/**
	 * Test map_post_id_array with array input
	 */
	public function test_map_post_id_array_array() {
		$source_id = $this->create_test_post( 'Array Input Source' );
		$target_id = $this->create_test_post( 'Array Input Target' );

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_arr_input',
				'relationship_type' => 'translation',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$result = Post_Mapping_Service::map_post_id_array( array( $source_id ), 'post', 1, 'v_arr_input', 'translation', false );

		$this->assertIsArray( $result );
		$this->assertContains( $target_id, $result );
	}

	/**
	 * Test map_post_id_array with empty input
	 */
	public function test_map_post_id_array_empty() {
		$result_string = Post_Mapping_Service::map_post_id_array( '', 'post', 1, 'v_test', 'translation' );
		$this->assertEquals( '', $result_string );

		$result_array = Post_Mapping_Service::map_post_id_array( array(), 'post', 1, 'v_test', 'translation', false );
		$this->assertEquals( array(), $result_array );
	}

	// ==================== map_parent_id() Tests ====================

	/**
	 * Test map_parent_id returns mapped ID
	 */
	public function test_map_parent_id() {
		$source_parent = $this->create_test_post( 'Parent Source' );
		$target_parent = $this->create_test_post( 'Parent Target' );

		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_parent,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_parent,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_parent',
				'relationship_type' => 'parent_child',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$result = Post_Mapping_Service::map_parent_id( $source_parent, 'post', 1, 'v_parent' );

		$this->assertEquals( $target_parent, $result );
	}

	/**
	 * Test map_parent_id returns 0 for empty parent
	 */
	public function test_map_parent_id_returns_zero_for_empty() {
		$result = Post_Mapping_Service::map_parent_id( 0, 'post', 1, 'v_test' );
		$this->assertEquals( 0, $result );

		$result = Post_Mapping_Service::map_parent_id( '', 'post', 1, 'v_test' );
		$this->assertEquals( 0, $result );
	}

	// ==================== delete_mapping() Tests ====================

	/**
	 * Test delete_mapping removes mapping
	 */
	public function test_delete_mapping() {
		$source_id  = $this->create_test_post( 'Delete Source' );
		$target_id  = $this->create_test_post( 'Delete Target' );
		$mapping_id = Post_Mapping_Service::create_mapping(
			array(
				'source_post_id'    => $source_id,
				'source_post_type'  => 'post',
				'source_site_id'    => 1,
				'target_post_id'    => $target_id,
				'target_post_type'  => 'post',
				'target_site_id'    => 'v_delete',
				'relationship_type' => 'translation',
			)
		);

		$result = Post_Mapping_Service::delete_mapping( $mapping_id );
		$this->assertTrue( $result );

		// Verify deleted
		$mapping = Post_Mapping_Service::get_mapping( $source_id, 'post', 1, 'v_delete' );
		$this->assertNull( $mapping );
	}

	// ==================== delete_mappings_for_source() Tests ====================

	/**
	 * Test delete_mappings_for_source removes all source mappings
	 */
	public function test_delete_mappings_for_source() {
		$source_id = $this->create_test_post( 'Delete All Source' );

		// Create multiple mappings
		for ( $i = 1; $i <= 3; $i++ ) {
			$target_id = $this->create_test_post( "Delete All Target {$i}" );
			Post_Mapping_Service::create_mapping(
				array(
					'source_post_id'    => $source_id,
					'source_post_type'  => 'post',
					'source_site_id'    => 1,
					'target_post_id'    => $target_id,
					'target_post_type'  => 'post',
					'target_site_id'    => "v_del{$i}",
					'relationship_type' => 'translation',
				)
			);
		}

		$deleted = Post_Mapping_Service::delete_mappings_for_source( $source_id, 'post', 1 );
		$this->assertEquals( 3, $deleted );

		// Verify all deleted
		$mappings = Post_Mapping_Service::get_all_mappings_for_source( $source_id, 'post', 1 );
		$this->assertCount( 0, $mappings );
	}

	// ==================== get_statistics() Tests ====================

	/**
	 * Test get_statistics returns expected structure
	 */
	public function test_get_statistics() {
		$stats = Post_Mapping_Service::get_statistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'by_type', $stats );
		$this->assertArrayHasKey( 'by_post_type', $stats );
	}

	// ==================== Helper Methods ====================

	/**
	 * Create a test post
	 *
	 * @param string $title Post title.
	 * @return int Post ID.
	 */
	private function create_test_post( $title ) {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $title . ' ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->test_post_ids[] = $post_id;
		return $post_id;
	}

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test mappings
		if ( ! empty( $this->test_mapping_ids ) ) {
			$table      = wptsall_table( 'post_mappings' );
			$ids_string = implode( ',', array_map( 'intval', $this->test_mapping_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_string})" );
		}

		// Clean up test posts
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		parent::tearDown();
	}
}

<?php
/**
 * Media Mapping Service Tests
 *
 * Tests for WPTSALL\Models\Services\Media_Mapping_Service class
 *
 * Tests media file ID mappings between source and target sites.
 *
 * @package WPTSALL\Tests\Unit\Models
 * @since 0.5.0
 */

use WPTSALL\Models\Services\Media_Mapping_Service;

class Test_Media_Mapping_Service extends SimpleTestCase {

	/**
	 * Test mapping IDs created during tests
	 *
	 * @var array
	 */
	private $test_mapping_ids = array();

	/**
	 * Test attachment IDs created during tests
	 *
	 * @var array
	 */
	private $test_attachment_ids = array();

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
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Media_Mapping_Service' ) );
	}

	/**
	 * Test service has required methods
	 */
	public function test_service_has_required_methods() {
		$methods = array(
			'get_mapping',
			'get_reverse_mapping',
			'create_mapping',
			'map_media_with_copy',
			'batch_map_media',
			'delete_mapping',
			'cleanup_orphaned_mappings',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\Models\Services\Media_Mapping_Service', $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== create_mapping() Tests ====================

	/**
	 * Test create_mapping creates new mapping
	 */
	public function test_create_mapping_success() {
		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 100,
				'source_site_id'   => 1,
				'source_file_path' => '/wp-content/uploads/2025/01/test.jpg',
				'source_file_url'  => 'http://example.com/wp-content/uploads/2025/01/test.jpg',
				'target_media_id'  => 200,
				'target_site_id'   => 'v_en',
				'target_file_path' => '/wp-content/uploads/2025/01/test-en.jpg',
				'target_file_url'  => 'http://en.example.com/wp-content/uploads/2025/01/test-en.jpg',
				'mapping_method'   => 'copy',
				'alt_translated'   => false,
			)
		);

		$this->assertNotFalse( $mapping_id );
		$this->assertIsInt( $mapping_id );
		$this->assertGreaterThan( 0, $mapping_id );

		$this->test_mapping_ids[] = $mapping_id;
	}

	/**
	 * Test create_mapping updates existing mapping
	 */
	public function test_create_mapping_updates_existing() {
		// Create first mapping
		$first_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 101,
				'source_site_id'   => 1,
				'source_file_path' => '/path/original.jpg',
				'source_file_url'  => 'http://example.com/original.jpg',
				'target_media_id'  => 201,
				'target_site_id'   => 'v_update',
				'target_file_path' => '/path/target.jpg',
				'target_file_url'  => 'http://target.com/target.jpg',
				'mapping_method'   => 'copy',
			)
		);
		$this->test_mapping_ids[] = $first_id;

		// Update with same source
		$second_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 101,
				'source_site_id'   => 1,
				'source_file_path' => '/path/original.jpg',
				'source_file_url'  => 'http://example.com/original.jpg',
				'target_media_id'  => 301,
				'target_site_id'   => 'v_update',
				'target_file_path' => '/path/new-target.jpg',
				'target_file_url'  => 'http://target.com/new-target.jpg',
				'mapping_method'   => 'reuse',
			)
		);

		// Should return same mapping ID (updated)
		$this->assertEquals( $first_id, $second_id );
	}

	/**
	 * Test create_mapping with metadata
	 */
	public function test_create_mapping_with_metadata() {
		$metadata = array(
			'width'       => 1920,
			'height'      => 1080,
			'mime_type'   => 'image/jpeg',
			'file_size'   => 102400,
		);

		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 102,
				'source_site_id'   => 1,
				'source_file_path' => '/path/meta.jpg',
				'source_file_url'  => 'http://example.com/meta.jpg',
				'target_media_id'  => 202,
				'target_site_id'   => 'v_meta',
				'target_file_path' => '/path/meta-target.jpg',
				'target_file_url'  => 'http://target.com/meta-target.jpg',
				'mapping_method'   => 'copy',
				'metadata'         => $metadata,
			)
		);

		$this->test_mapping_ids[] = $mapping_id;
		$this->assertGreaterThan( 0, $mapping_id );
	}

	// ==================== get_mapping() Tests ====================

	/**
	 * Test get_mapping returns mapping
	 */
	public function test_get_mapping() {
		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 103,
				'source_site_id'   => 1,
				'source_file_path' => '/path/get.jpg',
				'source_file_url'  => 'http://example.com/get.jpg',
				'target_media_id'  => 203,
				'target_site_id'   => 'v_get',
				'target_file_path' => '/path/get-target.jpg',
				'target_file_url'  => 'http://target.com/get-target.jpg',
				'mapping_method'   => 'copy',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Media_Mapping_Service::get_mapping( 103, 1, 'v_get' );

		$this->assertIsArray( $mapping );
		$this->assertEquals( 103, (int) $mapping['source_media_id'] );
		$this->assertEquals( 203, (int) $mapping['target_media_id'] );
		$this->assertEquals( 'v_get', $mapping['target_site_id'] );
	}

	/**
	 * Test get_mapping returns null for non-existent
	 */
	public function test_get_mapping_returns_null_for_non_existent() {
		$mapping = Media_Mapping_Service::get_mapping( 99999999, 1, 'v_none' );
		$this->assertNull( $mapping );
	}

	// ==================== get_reverse_mapping() Tests ====================

	/**
	 * Test get_reverse_mapping returns mapping
	 */
	public function test_get_reverse_mapping() {
		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 104,
				'source_site_id'   => 1,
				'source_file_path' => '/path/reverse.jpg',
				'source_file_url'  => 'http://example.com/reverse.jpg',
				'target_media_id'  => 204,
				'target_site_id'   => 'v_rev',
				'target_file_path' => '/path/reverse-target.jpg',
				'target_file_url'  => 'http://target.com/reverse-target.jpg',
				'mapping_method'   => 'copy',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Media_Mapping_Service::get_reverse_mapping( 204, 'v_rev', 1 );

		$this->assertIsArray( $mapping );
		$this->assertEquals( 104, (int) $mapping['source_media_id'] );
		$this->assertEquals( 204, (int) $mapping['target_media_id'] );
	}

	// ==================== Mapping Methods Tests ====================

	/**
	 * Test create_mapping with different mapping methods
	 */
	public function test_create_mapping_methods() {
		$methods = array( 'copy', 'reuse', 'external' );

		foreach ( $methods as $index => $method ) {
			$mapping_id = Media_Mapping_Service::create_mapping(
				array(
					'source_media_id'  => 200 + $index,
					'source_site_id'   => 1,
					'source_file_path' => "/path/method-{$method}.jpg",
					'source_file_url'  => "http://example.com/method-{$method}.jpg",
					'target_media_id'  => 300 + $index,
					'target_site_id'   => "v_{$method}",
					'target_file_path' => "/path/method-{$method}-target.jpg",
					'target_file_url'  => "http://target.com/method-{$method}-target.jpg",
					'mapping_method'   => $method,
				)
			);

			$this->test_mapping_ids[] = $mapping_id;

			$mapping = Media_Mapping_Service::get_mapping( 200 + $index, 1, "v_{$method}" );
			$this->assertEquals( $method, $mapping['mapping_method'] );
		}
	}

	/**
	 * Test create_mapping with alt_translated flag
	 */
	public function test_create_mapping_alt_translated() {
		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 105,
				'source_site_id'   => 1,
				'source_file_path' => '/path/alt.jpg',
				'source_file_url'  => 'http://example.com/alt.jpg',
				'target_media_id'  => 205,
				'target_site_id'   => 'v_alt',
				'target_file_path' => '/path/alt-target.jpg',
				'target_file_url'  => 'http://target.com/alt-target.jpg',
				'mapping_method'   => 'copy',
				'alt_translated'   => true,
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Media_Mapping_Service::get_mapping( 105, 1, 'v_alt' );
		$this->assertEquals( 1, (int) $mapping['alt_translated'] );
	}

	// ==================== delete_mapping() Tests ====================

	/**
	 * Test delete_mapping removes mapping
	 */
	public function test_delete_mapping() {
		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 106,
				'source_site_id'   => 1,
				'source_file_path' => '/path/delete.jpg',
				'source_file_url'  => 'http://example.com/delete.jpg',
				'target_media_id'  => 206,
				'target_site_id'   => 'v_del',
				'target_file_path' => '/path/delete-target.jpg',
				'target_file_url'  => 'http://target.com/delete-target.jpg',
				'mapping_method'   => 'copy',
			)
		);

		$result = Media_Mapping_Service::delete_mapping( $mapping_id );
		$this->assertTrue( $result );

		// Verify deleted
		$mapping = Media_Mapping_Service::get_mapping( 106, 1, 'v_del' );
		$this->assertNull( $mapping );
	}

	// ==================== batch_map_media() Tests ====================

	/**
	 * Test batch_map_media returns array
	 */
	public function test_batch_map_media_returns_array() {
		// Note: This test relies on map_media_with_copy which requires actual media files
		// For unit test, we test the method exists and returns array
		$result = Media_Mapping_Service::batch_map_media(
			array(),
			1,
			'v_batch',
			'en_US',
			array()
		);

		$this->assertIsArray( $result );
	}

	// ==================== map_media_with_copy() Tests ====================

	/**
	 * Test map_media_with_copy returns existing mapping
	 */
	public function test_map_media_with_copy_returns_existing() {
		// Create existing mapping.
		// P1-TEST-01 (2026-09-02): map_media_with_copy() is relation-scoped
		// since 2.1.0 — it looks the mapping up with relation_id, so the
		// fixture row must carry the same relation_id (legacy rows with
		// relation_id=0 are no longer matched by the scoped lookup).
		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 107,
				'source_site_id'   => 1,
				'source_file_path' => '/path/existing.jpg',
				'source_file_url'  => 'http://example.com/existing.jpg',
				'target_media_id'  => 207,
				'target_site_id'   => 'v_existing',
				'target_file_path' => '/path/existing-target.jpg',
				'target_file_url'  => 'http://target.com/existing-target.jpg',
				'mapping_method'   => 'copy',
				'relation_id'      => 77,
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$result = Media_Mapping_Service::map_media_with_copy(
			107,
			1,
			'v_existing',
			'en_US',
			array( 'relation_id' => 77 )
		);

		$this->assertEquals( 207, $result );
		$mapping = Media_Mapping_Service::get_mapping( 107, 1, 'v_existing' );
		$this->assertEquals( 77, (int) $mapping['relation_id'] );
	}

	/**
	 * Relation-scoped retry lookups must use the service API rather than an
	 * undeclared Sync_Executor helper.
	 */
	public function test_get_mappings_by_site_pair_is_relation_scoped() {
		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 110,
				'relation_id'      => 88,
				'source_site_id'   => 1,
				'source_file_path' => '/path/source.jpg',
				'source_file_url'  => 'https://source.test/source.jpg',
				'target_media_id'  => 210,
				'target_site_id'   => 'v_pair',
				'target_file_path' => '/path/target.jpg',
				'target_file_url'  => 'https://target.test/target.jpg',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$this->assertCount( 1, Media_Mapping_Service::get_mappings_by_site_pair( 1, 'v_pair', 88 ) );
		$this->assertCount( 0, Media_Mapping_Service::get_mappings_by_site_pair( 1, 'v_pair', 89 ) );
	}

	// ==================== Virtual Site Support Tests ====================

	/**
	 * Test mapping supports virtual site IDs
	 */
	public function test_mapping_supports_virtual_sites() {
		$virtual_sites = array( 'v_en', 'v_zh_CN', 'v_de_DE', 'virtual_fr' );

		foreach ( $virtual_sites as $index => $site_id ) {
			$mapping_id = Media_Mapping_Service::create_mapping(
				array(
					'source_media_id'  => 400 + $index,
					'source_site_id'   => 1,
					'source_file_path' => "/path/virtual-{$index}.jpg",
					'source_file_url'  => "http://example.com/virtual-{$index}.jpg",
					'target_media_id'  => 500 + $index,
					'target_site_id'   => $site_id,
					'target_file_path' => "/path/virtual-{$index}-target.jpg",
					'target_file_url'  => "http://target.com/virtual-{$index}-target.jpg",
					'mapping_method'   => 'copy',
				)
			);

			$this->test_mapping_ids[] = $mapping_id;

			// Verify mapping was created
			$mapping = Media_Mapping_Service::get_mapping( 400 + $index, 1, $site_id );
			$this->assertNotNull( $mapping );
			$this->assertEquals( $site_id, $mapping['target_site_id'] );
		}
	}

	/**
	 * Test mapping supports numeric site IDs
	 */
	public function test_mapping_supports_numeric_sites() {
		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 108,
				'source_site_id'   => 1,
				'source_file_path' => '/path/numeric.jpg',
				'source_file_url'  => 'http://site1.example.com/numeric.jpg',
				'target_media_id'  => 208,
				'target_site_id'   => '2',
				'target_file_path' => '/path/numeric-target.jpg',
				'target_file_url'  => 'http://site2.example.com/numeric-target.jpg',
				'mapping_method'   => 'copy',
			)
		);

		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Media_Mapping_Service::get_mapping( 108, 1, '2' );
		$this->assertNotNull( $mapping );
		$this->assertEquals( '2', $mapping['target_site_id'] );
	}

	// ==================== File Path and URL Tests ====================

	/**
	 * Test mapping stores file paths correctly
	 */
	public function test_mapping_stores_file_paths() {
		$source_path = '/wp-content/uploads/2025/01/test-file.jpg';
		$target_path = '/wp-content/uploads/sites/2/2025/01/test-file.jpg';

		$mapping_id = Media_Mapping_Service::create_mapping(
			array(
				'source_media_id'  => 109,
				'source_site_id'   => 1,
				'source_file_path' => $source_path,
				'source_file_url'  => 'http://example.com' . $source_path,
				'target_media_id'  => 209,
				'target_site_id'   => 'v_path',
				'target_file_path' => $target_path,
				'target_file_url'  => 'http://target.com' . $target_path,
				'mapping_method'   => 'copy',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Media_Mapping_Service::get_mapping( 109, 1, 'v_path' );

		$this->assertEquals( $source_path, $mapping['source_file_path'] );
		$this->assertEquals( $target_path, $mapping['target_file_path'] );
	}

	// ==================== Helper Methods ====================

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test mappings
		if ( ! empty( $this->test_mapping_ids ) ) {
			$table      = wptsall_table( 'media_mappings' );
			$ids_string = implode( ',', array_map( 'intval', $this->test_mapping_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_string})" );
		}

		// Clean up test attachments
		foreach ( $this->test_attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		parent::tearDown();
	}
}

<?php
/**
 * Term Mapping Service Tests
 *
 * Tests for WPTSALL\Models\Services\Term_Mapping_Service class
 *
 * Tests term ID mappings between source and target sites.
 *
 * @package WPTSALL\Tests\Unit\Models
 * @since 0.5.0
 */

use WPTSALL\Models\Services\Term_Mapping_Service;

class Test_Term_Mapping_Service extends SimpleTestCase {

	/**
	 * Test mapping IDs created during tests
	 *
	 * @var array
	 */
	private $test_mapping_ids = array();

	/**
	 * Test term IDs created during tests
	 *
	 * @var array
	 */
	private $test_term_ids = array();

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
		$this->assertTrue( class_exists( 'WPTSALL\Models\Services\Term_Mapping_Service' ) );
	}

	/**
	 * Test service has required methods
	 */
	public function test_service_has_required_methods() {
		$methods = array(
			'get_mapping',
			'get_reverse_mapping',
			'create_mapping',
			'map_term_with_creation',
			'get_all_mappings_for_source',
			'delete_mapping',
			'batch_map_terms',
			'cleanup_orphaned_mappings',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\Models\Services\Term_Mapping_Service', $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== create_mapping() Tests ====================

	/**
	 * Test create_mapping creates new mapping
	 */
	public function test_create_mapping_success() {
		// Create test terms
		$source_term = $this->create_test_term( 'Source Category', 'category' );
		$target_term = $this->create_test_term( 'Target Category', 'category' );

		$mapping_id = Term_Mapping_Service::create_mapping(
			array(
				'source_term_id'     => $source_term,
				'source_taxonomy'    => 'category',
				'source_site_id'     => 1,
				'source_lang'        => 'zh_CN',
				'target_term_id'     => $target_term,
				'target_taxonomy'    => 'category',
				'target_site_id'     => 'v_en',
				'target_lang'        => 'en_US',
				'mapping_method'     => 'manual',
				'translation_method' => null,
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
		$source_term = $this->create_test_term( 'Update Source', 'category' );
		$target_term = $this->create_test_term( 'Update Target', 'category' );

		// Create first mapping
		$first_id = Term_Mapping_Service::create_mapping(
			array(
				'source_term_id'  => $source_term,
				'source_taxonomy' => 'category',
				'source_site_id'  => 1,
				'source_lang'     => 'zh_CN',
				'target_term_id'  => $target_term,
				'target_taxonomy' => 'category',
				'target_site_id'  => 'v_update',
				'target_lang'     => 'de_DE',
				'mapping_method'  => 'manual',
			)
		);
		$this->test_mapping_ids[] = $first_id;

		// Update with same source
		$new_target = $this->create_test_term( 'New Target', 'category' );
		$second_id  = Term_Mapping_Service::create_mapping(
			array(
				'source_term_id'  => $source_term,
				'source_taxonomy' => 'category',
				'source_site_id'  => 1,
				'source_lang'     => 'zh_CN',
				'target_term_id'  => $new_target,
				'target_taxonomy' => 'category',
				'target_site_id'  => 'v_update',
				'target_lang'     => 'de_DE',
				'mapping_method'  => 'auto_create',
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
		$source_term = $this->create_test_term( 'Get Mapping Source', 'category' );
		$target_term = $this->create_test_term( 'Get Mapping Target', 'category' );

		$mapping_id = Term_Mapping_Service::create_mapping(
			array(
				'source_term_id'  => $source_term,
				'source_taxonomy' => 'category',
				'source_site_id'  => 1,
				'source_lang'     => 'zh_CN',
				'target_term_id'  => $target_term,
				'target_taxonomy' => 'category',
				'target_site_id'  => 'v_get',
				'target_lang'     => 'fr_FR',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Term_Mapping_Service::get_mapping( $source_term, 'category', 1, 'v_get', 'fr_FR' );

		$this->assertIsArray( $mapping );
		$this->assertEquals( $source_term, (int) $mapping['source_term_id'] );
		$this->assertEquals( $target_term, (int) $mapping['target_term_id'] );
		$this->assertEquals( 'v_get', $mapping['target_site_id'] );
		$this->assertEquals( 'fr_FR', $mapping['target_lang'] );
	}

	/**
	 * Test get_mapping returns null for non-existent
	 */
	public function test_get_mapping_returns_null_for_non_existent() {
		$mapping = Term_Mapping_Service::get_mapping( 99999999, 'category', 1, 'v_none', 'xx_XX' );
		$this->assertNull( $mapping );
	}

	// ==================== get_reverse_mapping() Tests ====================

	/**
	 * Test get_reverse_mapping returns mapping
	 */
	public function test_get_reverse_mapping() {
		$source_term = $this->create_test_term( 'Reverse Source', 'category' );
		$target_term = $this->create_test_term( 'Reverse Target', 'category' );

		$mapping_id = Term_Mapping_Service::create_mapping(
			array(
				'source_term_id'  => $source_term,
				'source_taxonomy' => 'category',
				'source_site_id'  => 1,
				'source_lang'     => 'zh_CN',
				'target_term_id'  => $target_term,
				'target_taxonomy' => 'category',
				'target_site_id'  => 'v_rev',
				'target_lang'     => 'ja_JP',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$mapping = Term_Mapping_Service::get_reverse_mapping( $target_term, 'category', 'v_rev', 1 );

		$this->assertIsArray( $mapping );
		$this->assertEquals( $source_term, (int) $mapping['source_term_id'] );
		$this->assertEquals( $target_term, (int) $mapping['target_term_id'] );
	}

	// ==================== map_term_with_creation() Tests ====================

	/**
	 * Test map_term_with_creation returns existing mapping
	 */
	public function test_map_term_with_creation_returns_existing() {
		$source_term = $this->create_test_term( 'Map Source', 'category' );
		$target_term = $this->create_test_term( 'Map Target', 'category' );

		$mapping_id = Term_Mapping_Service::create_mapping(
			array(
				'source_term_id'  => $source_term,
				'source_taxonomy' => 'category',
				'source_site_id'  => 1,
				'source_lang'     => 'zh_CN',
				'target_term_id'  => $target_term,
				'target_taxonomy' => 'category',
				'target_site_id'  => 'v_map',
				'target_lang'     => 'ko_KR',
			)
		);
		$this->test_mapping_ids[] = $mapping_id;

		$result = Term_Mapping_Service::map_term_with_creation(
			$source_term,
			'category',
			1,
			'v_map',
			'ko_KR',
			array()
		);

		$this->assertEquals( $target_term, $result );
	}

	/**
	 * Test map_term_with_creation returns false when not found and no creation
	 */
	public function test_map_term_with_creation_returns_false() {
		$source_term = $this->create_test_term( 'No Match Source', 'category' );

		$result = Term_Mapping_Service::map_term_with_creation(
			$source_term,
			'category',
			1,
			'v_no_match',
			'pt_BR',
			array( 'create_if_missing' => false )
		);

		$this->assertFalse( $result );
	}

	// ==================== get_all_mappings_for_source() Tests ====================

	/**
	 * Test get_all_mappings_for_source returns array
	 */
	public function test_get_all_mappings_for_source() {
		$source_term = $this->create_test_term( 'Multi Target Source', 'category' );

		$languages = array( 'en_US', 'de_DE', 'fr_FR' );

		foreach ( $languages as $lang ) {
			$target_term  = $this->create_test_term( "Target {$lang}", 'category' );
			$mapping_id = Term_Mapping_Service::create_mapping(
				array(
					'source_term_id'  => $source_term,
					'source_taxonomy' => 'category',
					'source_site_id'  => 1,
					'source_lang'     => 'zh_CN',
					'target_term_id'  => $target_term,
					'target_taxonomy' => 'category',
					'target_site_id'  => 'v_multi',
					'target_lang'     => $lang,
				)
			);
			$this->test_mapping_ids[] = $mapping_id;
		}

		$mappings = Term_Mapping_Service::get_all_mappings_for_source( $source_term, 'category', 1 );

		$this->assertIsArray( $mappings );
		$this->assertCount( 3, $mappings );
	}

	/**
	 * Test get_all_mappings_for_source returns empty array when none
	 */
	public function test_get_all_mappings_for_source_empty() {
		$mappings = Term_Mapping_Service::get_all_mappings_for_source( 99999999, 'category', 1 );

		$this->assertIsArray( $mappings );
		$this->assertEmpty( $mappings );
	}

	// ==================== batch_map_terms() Tests ====================

	/**
	 * Test batch_map_terms maps multiple terms
	 */
	public function test_batch_map_terms() {
		$source_ids = array();
		$target_ids = array();

		// Create terms with mappings
		for ( $i = 1; $i <= 3; $i++ ) {
			$source_id  = $this->create_test_term( "Batch Source {$i}", 'category' );
			$target_id  = $this->create_test_term( "Batch Target {$i}", 'category' );
			$source_ids[] = $source_id;
			$target_ids[] = $target_id;

			$mapping_id = Term_Mapping_Service::create_mapping(
				array(
					'source_term_id'  => $source_id,
					'source_taxonomy' => 'category',
					'source_site_id'  => 1,
					'source_lang'     => 'zh_CN',
					'target_term_id'  => $target_id,
					'target_taxonomy' => 'category',
					'target_site_id'  => 'v_batch',
					'target_lang'     => 'it_IT',
				)
			);
			$this->test_mapping_ids[] = $mapping_id;
		}

		$mapped = Term_Mapping_Service::batch_map_terms(
			$source_ids,
			'category',
			1,
			'v_batch',
			'it_IT',
			array()
		);

		$this->assertIsArray( $mapped );
		$this->assertCount( 3, $mapped );

		// Verify correct mapping
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertEquals( $target_ids[ $i ], $mapped[ $source_ids[ $i ] ] );
		}
	}

	// ==================== delete_mapping() Tests ====================

	/**
	 * Test delete_mapping removes mapping
	 */
	public function test_delete_mapping() {
		$source_term = $this->create_test_term( 'Delete Source', 'category' );
		$target_term = $this->create_test_term( 'Delete Target', 'category' );

		$mapping_id = Term_Mapping_Service::create_mapping(
			array(
				'source_term_id'  => $source_term,
				'source_taxonomy' => 'category',
				'source_site_id'  => 1,
				'source_lang'     => 'zh_CN',
				'target_term_id'  => $target_term,
				'target_taxonomy' => 'category',
				'target_site_id'  => 'v_del',
				'target_lang'     => 'nl_NL',
			)
		);

		$result = Term_Mapping_Service::delete_mapping( $mapping_id );
		$this->assertTrue( $result );

		// Verify deleted
		$mapping = Term_Mapping_Service::get_mapping( $source_term, 'category', 1, 'v_del', 'nl_NL' );
		$this->assertNull( $mapping );
	}

	// ==================== Mapping Methods Tests ====================

	/**
	 * Test create_mapping with different mapping methods
	 */
	public function test_create_mapping_methods() {
		$methods = array( 'manual', 'auto_create', 'auto_match' );

		foreach ( $methods as $method ) {
			$source_term = $this->create_test_term( "Method {$method} Source", 'category' );
			$target_term = $this->create_test_term( "Method {$method} Target", 'category' );

			$mapping_id = Term_Mapping_Service::create_mapping(
				array(
					'source_term_id'  => $source_term,
					'source_taxonomy' => 'category',
					'source_site_id'  => 1,
					'source_lang'     => 'zh_CN',
					'target_term_id'  => $target_term,
					'target_taxonomy' => 'category',
					'target_site_id'  => "v_{$method}",
					'target_lang'     => 'en_US',
					'mapping_method'  => $method,
				)
			);

			$this->test_mapping_ids[] = $mapping_id;

			$mapping = Term_Mapping_Service::get_mapping( $source_term, 'category', 1, "v_{$method}", 'en_US' );
			$this->assertEquals( $method, $mapping['mapping_method'] );
		}
	}

	// ==================== Helper Methods ====================

	/**
	 * Create a test term
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy name.
	 * @return int Term ID.
	 */
	private function create_test_term( $name, $taxonomy ) {
		$term = wp_insert_term(
			$name . ' ' . uniqid(),
			$taxonomy,
			array( 'slug' => sanitize_title( $name . '-' . uniqid() ) )
		);

		if ( is_wp_error( $term ) ) {
			// Term might already exist, get existing
			$existing = get_term_by( 'name', $name, $taxonomy );
			if ( $existing ) {
				$this->test_term_ids[] = $existing->term_id;
				return $existing->term_id;
			}
			return 0;
		}

		$this->test_term_ids[] = $term['term_id'];
		return $term['term_id'];
	}

	/**
	 * Clean up after all tests
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test mappings
		if ( ! empty( $this->test_mapping_ids ) ) {
			$table      = wptsall_table( 'term_mappings' );
			$ids_string = implode( ',', array_map( 'intval', $this->test_mapping_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_string})" );
		}

		// Clean up test terms
		foreach ( $this->test_term_ids as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		parent::tearDown();
	}
}

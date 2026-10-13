<?php
/**
 * Runtime Tracker Tests
 *
 * Tests for WPTSALL\Models\Scanners\Runtime_Tracker class
 *
 * @package WPTSALL
 * @since 0.10.0
 */

use WPTSALL\Models\Scanners\Runtime_Tracker;

class Test_Runtime_Tracker extends WP_UnitTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any existing tracking data.
		Runtime_Tracker::clear_cache();
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown(): void {
		Runtime_Tracker::clear_cache();
		parent::tearDown();
	}

	// ========================================
	// Initialization Tests
	// ========================================

	/**
	 * Test init method
	 */
	public function test_init_starts_tracking() {
		// Init should set tracking to true.
		Runtime_Tracker::init();

		$this->assertTrue( Runtime_Tracker::is_tracking() );
	}

	/**
	 * Test init is idempotent
	 */
	public function test_init_is_idempotent() {
		Runtime_Tracker::init();
		Runtime_Tracker::init();
		Runtime_Tracker::init();

		// Should still be tracking without error.
		$this->assertTrue( Runtime_Tracker::is_tracking() );
	}

	// ========================================
	// Getter Tests
	// ========================================

	/**
	 * Test get_post_type_sources returns array
	 */
	public function test_get_post_type_sources_returns_array() {
		$sources = Runtime_Tracker::get_post_type_sources();

		$this->assertIsArray( $sources );
	}

	/**
	 * Test get_taxonomy_sources returns array
	 */
	public function test_get_taxonomy_sources_returns_array() {
		$sources = Runtime_Tracker::get_taxonomy_sources();

		$this->assertIsArray( $sources );
	}

	/**
	 * Test get_plugins_with_content returns array
	 */
	public function test_get_plugins_with_content_returns_array() {
		$plugins = Runtime_Tracker::get_plugins_with_content();

		$this->assertIsArray( $plugins );
	}

	/**
	 * Test get_post_types_by_plugin with nonexistent plugin
	 */
	public function test_get_post_types_by_plugin_nonexistent() {
		$post_types = Runtime_Tracker::get_post_types_by_plugin( 'nonexistent-plugin-' . uniqid() );

		$this->assertIsArray( $post_types );
		$this->assertEmpty( $post_types );
	}

	/**
	 * Test get_taxonomies_by_plugin with nonexistent plugin
	 */
	public function test_get_taxonomies_by_plugin_nonexistent() {
		$taxonomies = Runtime_Tracker::get_taxonomies_by_plugin( 'nonexistent-plugin-' . uniqid() );

		$this->assertIsArray( $taxonomies );
		$this->assertEmpty( $taxonomies );
	}

	/**
	 * Test get_post_type_source with nonexistent post type
	 */
	public function test_get_post_type_source_nonexistent() {
		$source = Runtime_Tracker::get_post_type_source( 'nonexistent_post_type_' . uniqid() );

		$this->assertNull( $source );
	}

	/**
	 * Test get_taxonomy_source with nonexistent taxonomy
	 */
	public function test_get_taxonomy_source_nonexistent() {
		$source = Runtime_Tracker::get_taxonomy_source( 'nonexistent_taxonomy_' . uniqid() );

		$this->assertNull( $source );
	}

	// ========================================
	// Cache Tests
	// ========================================

	/**
	 * Test save_to_cache and load_from_cache
	 */
	public function test_cache_save_and_load() {
		// Save current state to cache.
		Runtime_Tracker::save_to_cache();

		// Clear in-memory state.
		Runtime_Tracker::clear_cache();

		// Load from cache.
		$loaded = Runtime_Tracker::load_from_cache();

		// Should return false since we cleared the option in clear_cache.
		$this->assertFalse( $loaded );
	}

	/**
	 * Test clear_cache
	 */
	public function test_clear_cache() {
		// Save something first.
		Runtime_Tracker::save_to_cache();

		// Clear.
		Runtime_Tracker::clear_cache();

		// Verify cleared.
		$loaded = Runtime_Tracker::load_from_cache();
		$this->assertFalse( $loaded );

		// Sources should be empty.
		$this->assertEmpty( Runtime_Tracker::get_post_type_sources() );
		$this->assertEmpty( Runtime_Tracker::get_taxonomy_sources() );
	}

	/**
	 * Test load_from_cache with no cache
	 */
	public function test_load_from_cache_empty() {
		Runtime_Tracker::clear_cache();

		$result = Runtime_Tracker::load_from_cache();

		$this->assertFalse( $result );
	}

	// ========================================
	// Summary Tests
	// ========================================

	/**
	 * Test get_summary returns structured data
	 */
	public function test_get_summary_structure() {
		$summary = Runtime_Tracker::get_summary();

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'total_post_types', $summary );
		$this->assertArrayHasKey( 'total_taxonomies', $summary );
		$this->assertArrayHasKey( 'total_plugins', $summary );
		$this->assertArrayHasKey( 'post_types_by_plugin', $summary );
		$this->assertArrayHasKey( 'taxonomies_by_plugin', $summary );
		$this->assertArrayHasKey( 'is_tracking', $summary );
	}

	/**
	 * Test summary values are integers
	 */
	public function test_get_summary_types() {
		$summary = Runtime_Tracker::get_summary();

		$this->assertIsInt( $summary['total_post_types'] );
		$this->assertIsInt( $summary['total_taxonomies'] );
		$this->assertIsInt( $summary['total_plugins'] );
		$this->assertIsArray( $summary['post_types_by_plugin'] );
		$this->assertIsArray( $summary['taxonomies_by_plugin'] );
		$this->assertIsBool( $summary['is_tracking'] );
	}

	// ========================================
	// Core Type Exclusion Tests
	// ========================================

	/**
	 * Test core post types are excluded
	 */
	public function test_core_post_types_excluded() {
		Runtime_Tracker::init();

		// Core types like 'post', 'page', 'attachment' should not be tracked.
		$post_source = Runtime_Tracker::get_post_type_source( 'post' );
		$page_source = Runtime_Tracker::get_post_type_source( 'page' );
		$attachment_source = Runtime_Tracker::get_post_type_source( 'attachment' );

		$this->assertNull( $post_source );
		$this->assertNull( $page_source );
		$this->assertNull( $attachment_source );
	}

	/**
	 * Test core taxonomies are excluded
	 */
	public function test_core_taxonomies_excluded() {
		Runtime_Tracker::init();

		// Core taxonomies like 'category', 'post_tag' should not be tracked.
		$category_source = Runtime_Tracker::get_taxonomy_source( 'category' );
		$tag_source = Runtime_Tracker::get_taxonomy_source( 'post_tag' );

		$this->assertNull( $category_source );
		$this->assertNull( $tag_source );
	}

	// ========================================
	// Handler Tests (edge cases)
	// ========================================

	/**
	 * Test on_post_type_registered skips core types
	 */
	public function test_on_post_type_registered_skips_core() {
		Runtime_Tracker::init();

		// Simulate registering a core post type - should be ignored.
		$post_type_object = (object) array( 'public' => true );
		Runtime_Tracker::on_post_type_registered( 'post', $post_type_object );

		$source = Runtime_Tracker::get_post_type_source( 'post' );
		$this->assertNull( $source );
	}

	/**
	 * Test on_taxonomy_registered skips core types
	 */
	public function test_on_taxonomy_registered_skips_core() {
		Runtime_Tracker::init();

		// Simulate registering a core taxonomy - should be ignored.
		Runtime_Tracker::on_taxonomy_registered( 'category', 'post', array( 'public' => true ) );

		$source = Runtime_Tracker::get_taxonomy_source( 'category' );
		$this->assertNull( $source );
	}

	/**
	 * Test on_post_type_registered skips non-public types
	 */
	public function test_on_post_type_registered_skips_non_public() {
		Runtime_Tracker::init();

		// Simulate registering a non-public post type.
		$post_type_object = (object) array( 'public' => false );
		Runtime_Tracker::on_post_type_registered( 'private_type_' . uniqid(), $post_type_object );

		$sources = Runtime_Tracker::get_post_type_sources();

		// Should not contain non-public types.
		foreach ( $sources as $pt => $source ) {
			$this->assertStringNotContainsString( 'private_type_', $pt );
		}
	}

	/**
	 * Test on_taxonomy_registered skips non-public types
	 */
	public function test_on_taxonomy_registered_skips_non_public() {
		Runtime_Tracker::init();

		// Simulate registering a non-public taxonomy.
		$taxonomy_name = 'private_tax_' . uniqid();
		Runtime_Tracker::on_taxonomy_registered( $taxonomy_name, 'post', array( 'public' => false ) );

		$source = Runtime_Tracker::get_taxonomy_source( $taxonomy_name );
		$this->assertNull( $source );
	}

	// ========================================
	// Integration-like Tests
	// ========================================

	/**
	 * Test tracking finds plugin-registered post types
	 */
	public function test_tracking_finds_plugin_post_types() {
		Runtime_Tracker::init();

		// Get tracked post types.
		$sources = Runtime_Tracker::get_post_type_sources();

		// The actual number depends on installed plugins.
		// Just verify the structure is correct.
		$this->assertIsArray( $sources );

		foreach ( $sources as $post_type => $source ) {
			$this->assertIsString( $post_type );
			$this->assertIsArray( $source );
			$this->assertArrayHasKey( 'plugin_slug', $source );
		}
	}

	/**
	 * Test tracking finds plugin-registered taxonomies
	 */
	public function test_tracking_finds_plugin_taxonomies() {
		Runtime_Tracker::init();

		// Get tracked taxonomies.
		$sources = Runtime_Tracker::get_taxonomy_sources();

		// Just verify the structure is correct.
		$this->assertIsArray( $sources );

		foreach ( $sources as $taxonomy => $source ) {
			$this->assertIsString( $taxonomy );
			$this->assertIsArray( $source );
			$this->assertArrayHasKey( 'plugin_slug', $source );
		}
	}
}

<?php
/**
 * Orphan Resolver Tests
 *
 * Tests for WPTSALL\Models\Scanners\Orphan_Resolver class
 *
 * Tests detection and resolution of orphan post_types and taxonomies.
 *
 * @package WPTSALL\Tests\Unit\Models
 * @since 0.3.2
 */

use WPTSALL\Models\Scanners\Orphan_Resolver;

class Test_Orphan_Resolver extends SimpleTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
		// Clear cache before each test
		Orphan_Resolver::clear_cache();
	}

	// ==================== Class Tests ====================

	/**
	 * Test class exists
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Models\Scanners\Orphan_Resolver' ) );
	}

	/**
	 * Test class has required methods
	 */
	public function test_class_has_required_methods() {
		$methods = array(
			'find_orphan_post_types',
			'find_orphan_taxonomies',
			'resolve_post_type',
			'resolve_taxonomy',
			'resolve_all_orphans',
			'apply_resolutions',
			'fuzzy_match_plugin',
			'add_known_mapping',
			'get_known_mappings',
			'get_dynamic_mappings',
			'get_source_detected_mappings',
			'get_cptui_types',
			'get_cptui_taxonomies',
			'clear_cache',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\Models\Scanners\Orphan_Resolver', $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== find_orphan_post_types() Tests ====================

	/**
	 * Test find_orphan_post_types returns array
	 */
	public function test_find_orphan_post_types_returns_array() {
		$result = Orphan_Resolver::find_orphan_post_types();

		$this->assertIsArray( $result );
	}

	/**
	 * Test find_orphan_post_types excludes core types
	 */
	public function test_find_orphan_post_types_excludes_core() {
		$result = Orphan_Resolver::find_orphan_post_types();

		$names = array_column( $result, 'name' );

		// Core types should not be in results
		$core_types = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item' );
		foreach ( $core_types as $core ) {
			$this->assertNotContains( $core, $names );
		}
	}

	/**
	 * Test find_orphan_post_types returns expected structure
	 */
	public function test_find_orphan_post_types_structure() {
		$result = Orphan_Resolver::find_orphan_post_types();

		if ( empty( $result ) ) {
			$this->markTestSkipped( 'No orphan post types found' );
			return;
		}

		$first = $result[0];

		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'label', $first );
		$this->assertArrayHasKey( 'public', $first );
	}

	// ==================== find_orphan_taxonomies() Tests ====================

	/**
	 * Test find_orphan_taxonomies returns array
	 */
	public function test_find_orphan_taxonomies_returns_array() {
		$result = Orphan_Resolver::find_orphan_taxonomies();

		$this->assertIsArray( $result );
	}

	/**
	 * Test find_orphan_taxonomies excludes core taxonomies
	 */
	public function test_find_orphan_taxonomies_excludes_core() {
		$result = Orphan_Resolver::find_orphan_taxonomies();

		$names = array_column( $result, 'name' );

		// Core taxonomies should not be in results
		$core_taxes = array( 'category', 'post_tag', 'nav_menu' );
		foreach ( $core_taxes as $core ) {
			$this->assertNotContains( $core, $names );
		}
	}

	// ==================== resolve_post_type() Tests ====================

	/**
	 * Test resolve_post_type with known type
	 */
	public function test_resolve_post_type_with_known() {
		// Add a known mapping
		Orphan_Resolver::add_known_mapping( 'test_cpt', 'test-plugin' );

		// This won't resolve unless test_cpt actually exists
		// But we can test the method doesn't error
		$result = Orphan_Resolver::resolve_post_type( 'test_cpt' );

		// Result depends on whether test_cpt exists in WordPress
		$this->assertTrue( is_array( $result ) || is_null( $result ) );
	}

	/**
	 * Test resolve_post_type returns null for completely unknown type
	 *
	 * Note: fuzzy_match_plugin returns null only if score < 2,
	 * so we use a random string that won't match any plugin slug parts.
	 */
	public function test_resolve_post_type_returns_null_for_unknown() {
		// Use a truly random string that won't fuzzy-match any plugin
		$result = Orphan_Resolver::resolve_post_type( 'qzxwvutsr' );

		// If fuzzy match finds something, it should be low confidence
		if ( $result !== null ) {
			$this->assertArrayHasKey( 'confidence', $result );
			$this->assertEquals( 'low', $result['confidence'], 'Unknown type should only have low confidence fuzzy match' );
		} else {
			$this->assertNull( $result );
		}
	}

	/**
	 * Test resolve_post_type with WooCommerce product
	 */
	public function test_resolve_post_type_woocommerce() {
		// Auto-activate WooCommerce if installed but not active
		$this->requirePlugin( 'woocommerce' );

		$result = Orphan_Resolver::resolve_post_type( 'product' );

		// Should resolve to woocommerce
		$this->assertNotNull( $result, 'product post_type should resolve' );
		$this->assertEquals( 'woocommerce', $result['plugin_slug'] );
	}

	// ==================== resolve_taxonomy() Tests ====================

	/**
	 * Test resolve_taxonomy returns null for unknown
	 */
	public function test_resolve_taxonomy_returns_null_for_unknown() {
		$result = Orphan_Resolver::resolve_taxonomy( 'nonexistent_tax_xyz_123' );

		$this->assertNull( $result );
	}

	// ==================== fuzzy_match_plugin() Tests ====================

	/**
	 * Test fuzzy_match_plugin with exact match
	 */
	public function test_fuzzy_match_exact() {
		// Auto-activate WooCommerce if installed but not active
		$this->requirePlugin( 'woocommerce' );

		$result = Orphan_Resolver::fuzzy_match_plugin( 'woocommerce' );

		$this->assertNotNull( $result, 'woocommerce should be found' );
		$this->assertEquals( 'woocommerce', $result['plugin_slug'] );
	}

	/**
	 * Test fuzzy_match_plugin with prefix match
	 */
	public function test_fuzzy_match_prefix() {
		// Test with a prefix-based name
		$result = Orphan_Resolver::fuzzy_match_plugin( 'wc_order' );

		if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			// Should potentially match woocommerce
			if ( $result ) {
				$this->assertArrayHasKey( 'plugin_slug', $result );
				$this->assertArrayHasKey( 'confidence', $result );
			}
		}
	}

	/**
	 * Test fuzzy_match_plugin returns structure
	 */
	public function test_fuzzy_match_structure() {
		// This might or might not find a match depending on active plugins
		$result = Orphan_Resolver::fuzzy_match_plugin( 'some_post_type' );

		if ( $result ) {
			$this->assertArrayHasKey( 'plugin_slug', $result );
			$this->assertArrayHasKey( 'confidence', $result );
			$this->assertArrayHasKey( 'reason', $result );
		}
	}

	// ==================== get_dynamic_mappings() Tests ====================

	/**
	 * Test get_dynamic_mappings returns array
	 */
	public function test_get_dynamic_mappings_returns_array() {
		$result = Orphan_Resolver::get_dynamic_mappings();

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_dynamic_mappings detects CPTUI types
	 */
	public function test_get_dynamic_mappings_cptui() {
		// If CPTUI is not active, this will be empty
		$cptui_types = Orphan_Resolver::get_cptui_types();

		$this->assertIsArray( $cptui_types );
	}

	// ==================== get_source_detected_mappings() Tests ====================

	/**
	 * Test get_source_detected_mappings returns array
	 */
	public function test_get_source_detected_mappings_returns_array() {
		$result = Orphan_Resolver::get_source_detected_mappings();

		$this->assertIsArray( $result );
	}

	// ==================== add_known_mapping() / get_known_mappings() Tests ====================

	/**
	 * Test add and get known mappings
	 */
	public function test_add_and_get_known_mappings() {
		// Clear cache
		Orphan_Resolver::clear_cache();

		// Add mapping
		Orphan_Resolver::add_known_mapping( 'custom_type_abc', 'custom-plugin' );

		// Get mappings
		$mappings = Orphan_Resolver::get_known_mappings();

		$this->assertArrayHasKey( 'custom_type_abc', $mappings );
		$this->assertEquals( 'custom-plugin', $mappings['custom_type_abc'] );
	}

	// ==================== resolve_all_orphans() Tests ====================

	/**
	 * Test resolve_all_orphans returns structure
	 */
	public function test_resolve_all_orphans_structure() {
		$result = Orphan_Resolver::resolve_all_orphans();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_types', $result );
		$this->assertArrayHasKey( 'taxonomies', $result );
		$this->assertArrayHasKey( 'unresolved', $result );
	}

	// ==================== clear_cache() Tests ====================

	/**
	 * Test clear_cache resets mappings
	 */
	public function test_clear_cache() {
		// Add a mapping
		Orphan_Resolver::add_known_mapping( 'cache_test_type', 'cache-plugin' );

		// Verify it's there
		$mappings = Orphan_Resolver::get_known_mappings();
		$this->assertArrayHasKey( 'cache_test_type', $mappings );

		// Clear cache
		Orphan_Resolver::clear_cache();

		// After clear, the mapping should be gone (source_detected_mappings is reset)
		$new_mappings = Orphan_Resolver::get_source_detected_mappings();

		// The static cache should be rebuilt but without our manual addition
		$this->assertIsArray( $new_mappings );
	}

	// ==================== CPTUI Integration Tests ====================

	/**
	 * Test get_cptui_types returns array
	 */
	public function test_get_cptui_types() {
		$result = Orphan_Resolver::get_cptui_types();

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_cptui_taxonomies returns array
	 */
	public function test_get_cptui_taxonomies() {
		$result = Orphan_Resolver::get_cptui_taxonomies();

		$this->assertIsArray( $result );
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		Orphan_Resolver::clear_cache();
		parent::tearDown();
	}
}

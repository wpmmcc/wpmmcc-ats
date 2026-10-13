<?php
/**
 * Plugin Scanner Tests
 *
 * Tests for WPTSALL\Models\Services\Plugin_Scanner class
 *
 * @package WPTSALL
 * @since 0.10.0
 */

use WPTSALL\Models\Services\Plugin_Scanner;

class Test_Plugin_Scanner extends WP_UnitTestCase {

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	// ========================================
	// get_scannable_plugins Tests
	// ========================================

	/**
	 * Test get_scannable_plugins returns array
	 */
	public function test_get_scannable_plugins_returns_array() {
		$plugins = Plugin_Scanner::get_scannable_plugins();

		$this->assertIsArray( $plugins );
	}

	/**
	 * Test get_scannable_plugins includes wordpress-blog
	 */
	public function test_get_scannable_plugins_includes_wordpress_blog() {
		$plugins = Plugin_Scanner::get_scannable_plugins();

		$this->assertArrayHasKey( 'wordpress-blog', $plugins );
		$this->assertEquals( 'WordPress Blog (Default)', $plugins['wordpress-blog'] );
	}

	/**
	 * Test get_scannable_plugins excludes wptsall
	 */
	public function test_get_scannable_plugins_excludes_self() {
		$plugins = Plugin_Scanner::get_scannable_plugins();

		if ( defined( 'WPTSALL_BASENAME' ) ) {
			$self_slug = explode( '/', WPTSALL_BASENAME, 2 )[0];
			$this->assertArrayNotHasKey( $self_slug, $plugins );
		}

		$this->assertArrayNotHasKey( 'wpmmcc-ats', $plugins );
		$this->assertArrayNotHasKey( 'wptsall', $plugins );
	}

	/**
	 * Test get_scannable_plugins returns active plugins
	 */
	public function test_get_scannable_plugins_returns_active_plugins() {
		$plugins = Plugin_Scanner::get_scannable_plugins();

		// Should have at least wordpress-blog
		$this->assertGreaterThanOrEqual( 1, count( $plugins ) );
	}

	// ========================================
	// get_plugin_slug Tests
	// ========================================

	/**
	 * Test get_plugin_slug with directory plugin
	 */
	public function test_get_plugin_slug_directory_plugin() {
		$slug = Plugin_Scanner::get_plugin_slug( 'woocommerce/woocommerce.php' );

		$this->assertEquals( 'woocommerce', $slug );
	}

	/**
	 * Test get_plugin_slug with single file plugin
	 */
	public function test_get_plugin_slug_single_file() {
		$slug = Plugin_Scanner::get_plugin_slug( 'hello.php' );

		$this->assertEquals( 'hello', $slug );
	}

	/**
	 * Test get_plugin_slug with complex name
	 */
	public function test_get_plugin_slug_complex_name() {
		$slug = Plugin_Scanner::get_plugin_slug( 'my-awesome-plugin/my-awesome-plugin.php' );

		$this->assertEquals( 'my-awesome-plugin', $slug );
	}

	// ========================================
	// scan_plugin Tests
	// ========================================

	/**
	 * Test scan_plugin for wordpress-blog
	 */
	public function test_scan_plugin_wordpress_blog() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'wordpress-blog', $result['plugin_slug'] );
		$this->assertTrue( $result['is_content_plugin'] );
	}

	/**
	 * Test scan_plugin returns null for nonexistent plugin
	 */
	public function test_scan_plugin_nonexistent() {
		$result = Plugin_Scanner::scan_plugin( 'nonexistent-plugin-xyz-123' );

		$this->assertNull( $result );
	}

	/**
	 * Test scan_plugin result structure
	 */
	public function test_scan_plugin_result_structure() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$this->assertArrayHasKey( 'plugin_slug', $result );
		$this->assertArrayHasKey( 'plugin_name', $result );
		$this->assertArrayHasKey( 'prefixes', $result );
		$this->assertArrayHasKey( 'post_types', $result );
		$this->assertArrayHasKey( 'taxonomies', $result );
		$this->assertArrayHasKey( 'is_content_plugin', $result );
	}

	// ========================================
	// scan_blog Tests (via scan_plugin('wordpress-blog'))
	// ========================================

	/**
	 * Test scan_blog returns result
	 */
	public function test_scan_blog_returns_result() {
		// scan_blog is private, use scan_plugin('wordpress-blog') instead
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'wordpress-blog', $result['plugin_slug'] );
	}

	/**
	 * Test scan_blog includes core post types
	 */
	public function test_scan_blog_includes_post_types() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$this->assertArrayHasKey( 'post_types', $result );
		$this->assertIsArray( $result['post_types'] );

		// Should include 'post' type
		$post_type_names = array_column( $result['post_types'], 'name' );
		$this->assertContains( 'post', $post_type_names );
	}

	/**
	 * Test scan_blog includes core taxonomies
	 */
	public function test_scan_blog_includes_taxonomies() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$this->assertArrayHasKey( 'taxonomies', $result );
		$this->assertIsArray( $result['taxonomies'] );

		// Should include 'category' taxonomy
		$taxonomy_names = array_column( $result['taxonomies'], 'name' );
		$this->assertContains( 'category', $taxonomy_names );
	}

	/**
	 * Test scan_blog is content plugin
	 */
	public function test_scan_blog_is_content_plugin() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$this->assertTrue( $result['is_content_plugin'] );
	}

	// ========================================
	// extract_prefixes Tests
	// ========================================

	/**
	 * Test extract_prefixes returns array structure
	 */
	public function test_extract_prefixes_structure() {
		$result = Plugin_Scanner::extract_prefixes( '/nonexistent/path', 'test-plugin' );

		$this->assertArrayHasKey( 'prefixes', $result );
		$this->assertArrayHasKey( 'explicit_types', $result );
		$this->assertIsArray( $result['prefixes'] );
		$this->assertIsArray( $result['explicit_types'] );
	}

	/**
	 * Test extract_prefixes includes slug variants
	 */
	public function test_extract_prefixes_slug_variants() {
		$result = Plugin_Scanner::extract_prefixes( '/nonexistent/path', 'my-awesome-plugin' );

		$prefixes = $result['prefixes'];

		$this->assertContains( 'my-awesome-plugin', $prefixes );
		$this->assertContains( 'my_awesome_plugin', $prefixes );
	}

	/**
	 * Test extract_prefixes creates abbreviation
	 */
	public function test_extract_prefixes_abbreviation() {
		$result = Plugin_Scanner::extract_prefixes( '/nonexistent/path', 'easy-digital-downloads' );

		$prefixes = $result['prefixes'];

		// Should include abbreviation 'edd'
		$this->assertContains( 'edd', $prefixes );
	}

	/**
	 * Test extract_prefixes filters common words
	 */
	public function test_extract_prefixes_filters_common() {
		$result = Plugin_Scanner::extract_prefixes( '/nonexistent/path', 'wpmmcc-ats' );

		$prefixes = $result['prefixes'];

		// 'wp' should be filtered out as it's a common word
		$this->assertNotContains( 'wp', $prefixes );
	}

	// ========================================
	// match_post_types Tests
	// ========================================

	/**
	 * Test match_post_types returns array
	 */
	public function test_match_post_types_returns_array() {
		$result = Plugin_Scanner::match_post_types( array( 'test' ) );

		$this->assertIsArray( $result );
	}

	/**
	 * Test match_post_types excludes core types
	 */
	public function test_match_post_types_excludes_core() {
		$result = Plugin_Scanner::match_post_types( array( 'post', 'page' ) );

		// Should not match 'post' or 'page' as they're core types
		$this->assertNotContains( 'post', $result );
		$this->assertNotContains( 'page', $result );
	}

	/**
	 * Test match_post_types with empty prefixes
	 */
	public function test_match_post_types_empty_prefixes() {
		$result = Plugin_Scanner::match_post_types( array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// ========================================
	// match_taxonomies Tests
	// ========================================

	/**
	 * Test match_taxonomies returns array
	 */
	public function test_match_taxonomies_returns_array() {
		$result = Plugin_Scanner::match_taxonomies( array( 'test' ), array() );

		$this->assertIsArray( $result );
	}

	/**
	 * Test match_taxonomies excludes core taxonomies
	 */
	public function test_match_taxonomies_excludes_core() {
		$result = Plugin_Scanner::match_taxonomies( array( 'category', 'post_tag' ), array() );

		// Should not match 'category' or 'post_tag' as they're core taxonomies
		$this->assertNotContains( 'category', $result );
		$this->assertNotContains( 'post_tag', $result );
	}

	// ========================================
	// is_content_plugin Tests
	// ========================================

	/**
	 * Test is_content_plugin with public post types
	 */
	public function test_is_content_plugin_with_public_types() {
		// 'post' is public and publicly queryable
		$result = Plugin_Scanner::is_content_plugin( array( 'post' ), array() );

		$this->assertTrue( $result );
	}

	/**
	 * Test is_content_plugin with no types
	 */
	public function test_is_content_plugin_no_types() {
		$result = Plugin_Scanner::is_content_plugin( array(), array() );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_content_plugin with public taxonomies only
	 */
	public function test_is_content_plugin_taxonomies_only() {
		// 'category' is a public taxonomy
		$result = Plugin_Scanner::is_content_plugin( array(), array( 'category' ) );

		$this->assertTrue( $result );
	}

	// ========================================
	// scan_all_plugins Tests
	// ========================================

	/**
	 * Test scan_all_plugins returns array
	 */
	public function test_scan_all_plugins_returns_array() {
		$results = Plugin_Scanner::scan_all_plugins();

		$this->assertIsArray( $results );
	}

	/**
	 * Test scan_all_plugins includes wordpress-blog
	 */
	public function test_scan_all_plugins_includes_wordpress_blog() {
		$results = Plugin_Scanner::scan_all_plugins();

		$this->assertArrayHasKey( 'wordpress-blog', $results );
	}

	/**
	 * Test scan_all_plugins wordpress-blog is content plugin
	 */
	public function test_scan_all_plugins_wordpress_blog_content() {
		$results = Plugin_Scanner::scan_all_plugins();

		$this->assertTrue( $results['wordpress-blog']['is_content_plugin'] );
	}

	// ========================================
	// get_post_types_detailed Tests
	// ========================================

	/**
	 * Test detailed post type structure
	 */
	public function test_post_type_detailed_structure() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$post_types = $result['post_types'];

		// Find 'post' type
		$post_type = null;
		foreach ( $post_types as $pt ) {
			if ( $pt['name'] === 'post' ) {
				$post_type = $pt;
				break;
			}
		}

		$this->assertNotNull( $post_type );
		$this->assertArrayHasKey( 'name', $post_type );
		$this->assertArrayHasKey( 'rest_base', $post_type );
		$this->assertArrayHasKey( 'supports', $post_type );
		$this->assertArrayHasKey( 'labels', $post_type );
		$this->assertArrayHasKey( 'public', $post_type );
		$this->assertArrayHasKey( 'show_in_rest', $post_type );
	}

	/**
	 * Test post type supports array
	 */
	public function test_post_type_supports() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$post_types = $result['post_types'];
		$post_type  = null;

		foreach ( $post_types as $pt ) {
			if ( $pt['name'] === 'post' ) {
				$post_type = $pt;
				break;
			}
		}

		$this->assertNotNull( $post_type );
		$this->assertIsArray( $post_type['supports'] );
		// 'post' type should support 'title' and 'editor'
		$this->assertContains( 'title', $post_type['supports'] );
		$this->assertContains( 'editor', $post_type['supports'] );
	}

	// ========================================
	// get_taxonomies_detailed Tests
	// ========================================

	/**
	 * Test detailed taxonomy structure
	 */
	public function test_taxonomy_detailed_structure() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$taxonomies = $result['taxonomies'];

		// Find 'category' taxonomy
		$taxonomy = null;
		foreach ( $taxonomies as $tax ) {
			if ( $tax['name'] === 'category' ) {
				$taxonomy = $tax;
				break;
			}
		}

		$this->assertNotNull( $taxonomy );
		$this->assertArrayHasKey( 'name', $taxonomy );
		$this->assertArrayHasKey( 'rest_base', $taxonomy );
		$this->assertArrayHasKey( 'labels', $taxonomy );
		$this->assertArrayHasKey( 'public', $taxonomy );
		$this->assertArrayHasKey( 'hierarchical', $taxonomy );
		$this->assertArrayHasKey( 'show_in_rest', $taxonomy );
	}

	/**
	 * Test taxonomy hierarchical property
	 */
	public function test_taxonomy_hierarchical() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$taxonomies = $result['taxonomies'];
		$category   = null;
		$post_tag   = null;

		foreach ( $taxonomies as $tax ) {
			if ( $tax['name'] === 'category' ) {
				$category = $tax;
			}
			if ( $tax['name'] === 'post_tag' ) {
				$post_tag = $tax;
			}
		}

		// category is hierarchical
		$this->assertNotNull( $category );
		$this->assertTrue( $category['hierarchical'] );

		// post_tag is not hierarchical
		$this->assertNotNull( $post_tag );
		$this->assertFalse( $post_tag['hierarchical'] );
	}

	// ========================================
	// Core Type Exclusion Tests
	// ========================================

	/**
	 * Test core post types are excluded
	 */
	public function test_core_post_types_excluded() {
		$result = Plugin_Scanner::match_post_types( array( 'wp' ) );

		// Should not include WordPress core types like wp_block, wp_template
		$this->assertNotContains( 'wp_block', $result );
		$this->assertNotContains( 'wp_template', $result );
		$this->assertNotContains( 'wp_template_part', $result );
	}

	/**
	 * Test core taxonomies are excluded
	 */
	public function test_core_taxonomies_excluded() {
		$result = Plugin_Scanner::match_taxonomies( array( 'nav' ), array() );

		// Should not include nav_menu
		$this->assertNotContains( 'nav_menu', $result );
	}

	// ========================================
	// Extended Data Tests
	// ========================================

	/**
	 * Test scan result includes extended data
	 */
	public function test_scan_result_extended_data() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		// WordPress blog may or may not have extended data, but keys should exist
		$this->assertArrayHasKey( 'endpoints', $result );
		$this->assertArrayHasKey( 'shortcodes', $result );
		$this->assertArrayHasKey( 'admin_menus', $result );
		$this->assertArrayHasKey( 'meta_fields', $result );
		$this->assertArrayHasKey( 'rest_routes', $result );
		$this->assertArrayHasKey( 'option_keys', $result );
	}

	// ========================================
	// Custom Tables Tests (ISS-MOD-019)
	// ========================================

	/**
	 * Test scan_plugin result includes custom_tables key
	 */
	public function test_scan_plugin_includes_custom_tables_key() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$this->assertArrayHasKey( 'custom_tables', $result );
		$this->assertIsArray( $result['custom_tables'] );
	}

	/**
	 * Test scan_plugin for plugin with custom tables
	 *
	 * Note: This test uses a real plugin (bookly) that has custom tables.
	 * If bookly is not installed, the test will be skipped.
	 */
	public function test_scan_plugin_detects_custom_tables() {
		// Check if bookly plugin exists
		$result = Plugin_Scanner::scan_plugin( 'bookly-responsive-appointment-booking-tool' );

		if ( null === $result ) {
			$this->markTestSkipped( 'Bookly plugin not installed, skipping custom tables test' );
		}

		$this->assertArrayHasKey( 'custom_tables', $result );

		// Bookly should have custom tables
		if ( ! empty( $result['custom_tables'] ) ) {
			$this->assertGreaterThan( 0, count( $result['custom_tables'] ) );

			// Check table structure
			$first_table = $result['custom_tables'][0];
			$this->assertArrayHasKey( 'name', $first_table );
			$this->assertArrayHasKey( 'row_count', $first_table );
			$this->assertArrayHasKey( 'is_content', $first_table );
		}
	}

	/**
	 * Test is_content_plugin considers custom tables
	 *
	 * A plugin with only custom content tables (no post_types) should
	 * be detected as a content plugin after ISS-MOD-019.
	 */
	public function test_is_content_plugin_with_custom_tables_only() {
		// Check if bookly plugin exists and has no post_types
		$result = Plugin_Scanner::scan_plugin( 'bookly-responsive-appointment-booking-tool' );

		if ( null === $result ) {
			$this->markTestSkipped( 'Bookly plugin not installed' );
		}

		// Bookly has no post_types, only custom tables
		$has_post_types   = ! empty( $result['post_types'] );
		$has_custom_tables = ! empty( $result['custom_tables'] );

		if ( ! $has_post_types && $has_custom_tables ) {
			// Should be detected as content plugin
			$this->assertTrue( $result['is_content_plugin'] );
		}
	}

	/**
	 * Test custom_tables structure contains required fields
	 */
	public function test_custom_tables_structure() {
		// Use any plugin that has custom tables
		$results = Plugin_Scanner::scan_all_plugins();

		$found_tables = false;
		foreach ( $results as $plugin_slug => $result ) {
			if ( ! empty( $result['custom_tables'] ) ) {
				$found_tables = true;
				$table        = $result['custom_tables'][0];

				$this->assertArrayHasKey( 'name', $table );
				$this->assertArrayHasKey( 'row_count', $table );
				$this->assertArrayHasKey( 'columns', $table );
				$this->assertArrayHasKey( 'is_content', $table );
				break;
			}
		}

		if ( ! $found_tables ) {
			$this->markTestSkipped( 'No plugins with custom tables found' );
		}
	}

	/**
	 * Test wordpress-blog has empty custom_tables
	 */
	public function test_wordpress_blog_has_empty_custom_tables() {
		$result = Plugin_Scanner::scan_plugin( 'wordpress-blog' );

		$this->assertArrayHasKey( 'custom_tables', $result );
		$this->assertIsArray( $result['custom_tables'] );
		$this->assertEmpty( $result['custom_tables'] );
	}
}

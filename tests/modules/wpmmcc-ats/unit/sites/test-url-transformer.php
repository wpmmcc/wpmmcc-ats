<?php
/**
 * URL Transformer Tests
 *
 * Tests for WPTSALL\Sites\Services\URL_Transformer class
 *
 * @package WPTSALL
 * @since 0.6.0
 */

use WPTSALL\Sites\Services\URL_Transformer;

class Test_URL_Transformer extends WP_UnitTestCase {

	/**
	 * Test URL_Transformer class exists
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Sites\Services\URL_Transformer' ) );
	}

	// ========================================
	// parse_url() Tests
	// ========================================

	/**
	 * Test parse_url with postname structure
	 */
	public function test_parse_url_postname() {
		$result = URL_Transformer::parse_url(
			'/hello-world/',
			'/%postname%/'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'post', $result['type'] );
		$this->assertEquals( 'hello-world', $result['slug'] );
	}

	/**
	 * Test parse_url with month and name structure
	 */
	public function test_parse_url_month_and_name() {
		$result = URL_Transformer::parse_url(
			'/2025/01/hello-world/',
			'/%year%/%monthnum%/%postname%/'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'post', $result['type'] );
		$this->assertEquals( 'hello-world', $result['slug'] );
		$this->assertEquals( '2025', $result['year'] );
		$this->assertEquals( '01', $result['month'] );
	}

	/**
	 * Test parse_url with day and name structure
	 */
	public function test_parse_url_day_and_name() {
		$result = URL_Transformer::parse_url(
			'/2025/01/06/hello-world/',
			'/%year%/%monthnum%/%day%/%postname%/'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'post', $result['type'] );
		$this->assertEquals( 'hello-world', $result['slug'] );
		$this->assertEquals( '2025', $result['year'] );
		$this->assertEquals( '01', $result['month'] );
		$this->assertEquals( '06', $result['day'] );
	}

	/**
	 * Test parse_url with numeric structure
	 */
	public function test_parse_url_numeric() {
		$result = URL_Transformer::parse_url(
			'/archives/123',
			'/archives/%post_id%'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'post', $result['type'] );
		$this->assertEquals( 123, $result['post_id'] );
	}

	/**
	 * Test parse_url with path prefix
	 */
	public function test_parse_url_with_path_prefix() {
		$result = URL_Transformer::parse_url(
			'/en/hello-world/',
			'/%postname%/',
			'en'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'post', $result['type'] );
		$this->assertEquals( 'hello-world', $result['slug'] );
	}

	/**
	 * Test parse_url returns home for root path
	 */
	public function test_parse_url_home() {
		$result = URL_Transformer::parse_url(
			'/',
			'/%postname%/'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'home', $result['type'] );
	}

	/**
	 * Test parse_url with plain permalink and post ID
	 */
	public function test_parse_url_plain_post() {
		$result = URL_Transformer::parse_url(
			'/?p=123',
			''
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'post', $result['type'] );
		$this->assertEquals( 123, $result['post_id'] );
	}

	/**
	 * Test parse_url with plain permalink and page ID
	 */
	public function test_parse_url_plain_page() {
		$result = URL_Transformer::parse_url(
			'/?page_id=456',
			''
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'page', $result['type'] );
		$this->assertEquals( 456, $result['post_id'] );
	}

	/**
	 * Test parse_url with plain permalink and category
	 */
	public function test_parse_url_plain_category() {
		$result = URL_Transformer::parse_url(
			'/?cat=5',
			''
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'category', $result['type'] );
		$this->assertEquals( 5, $result['term_id'] );
	}

	// ========================================
	// generate_url() Tests
	// ========================================

	/**
	 * Test generate_url with postname structure
	 */
	public function test_generate_url_postname() {
		$content = array(
			'type' => 'post',
			'slug' => 'hello-world',
		);

		$result = URL_Transformer::generate_url( $content, '/%postname%/' );

		$this->assertEquals( '/hello-world/', $result );
	}

	/**
	 * Test generate_url with month and name structure
	 */
	public function test_generate_url_month_and_name() {
		$content = array(
			'type'  => 'post',
			'slug'  => 'hello-world',
			'year'  => '2025',
			'month' => '01',
		);

		$result = URL_Transformer::generate_url( $content, '/%year%/%monthnum%/%postname%/' );

		$this->assertEquals( '/2025/01/hello-world/', $result );
	}

	/**
	 * Test generate_url with day and name structure
	 */
	public function test_generate_url_day_and_name() {
		$content = array(
			'type'  => 'post',
			'slug'  => 'hello-world',
			'year'  => '2025',
			'month' => '01',
			'day'   => '06',
		);

		$result = URL_Transformer::generate_url( $content, '/%year%/%monthnum%/%day%/%postname%/' );

		$this->assertEquals( '/2025/01/06/hello-world/', $result );
	}

	/**
	 * Test generate_url with numeric structure
	 */
	public function test_generate_url_numeric() {
		$content = array(
			'type'    => 'post',
			'post_id' => 123,
		);

		$result = URL_Transformer::generate_url( $content, '/archives/%post_id%' );

		$this->assertEquals( '/archives/123', $result );
	}

	/**
	 * Test generate_url with path prefix
	 */
	public function test_generate_url_with_path_prefix() {
		$content = array(
			'type' => 'post',
			'slug' => 'hello-world',
		);

		$result = URL_Transformer::generate_url( $content, '/%postname%/', 'en' );

		$this->assertEquals( '/en/hello-world/', $result );
	}

	/**
	 * Test generate_url with plain permalink
	 */
	public function test_generate_url_plain() {
		$content = array(
			'type'    => 'post',
			'post_id' => 123,
		);

		$result = URL_Transformer::generate_url( $content, '' );

		$this->assertEquals( '?p=123', $result );
	}

	/**
	 * Test generate_url with empty content returns empty string
	 */
	public function test_generate_url_empty_content() {
		$result = URL_Transformer::generate_url( array(), '/%postname%/' );
		$this->assertEquals( '', $result );

		$result = URL_Transformer::generate_url( null, '/%postname%/' );
		$this->assertEquals( '', $result );
	}

	// ========================================
	// transform() Tests
	// ========================================

	/**
	 * Test transform from postname to month and name
	 */
	public function test_transform_postname_to_month_name() {
		// Create a test post to enable the transformation lookup
		$post_id = wp_insert_post( array(
			'post_title'  => 'Transform Test',
			'post_name'   => 'transform-test',
			'post_status' => 'publish',
			'post_date'   => '2025-01-06 10:00:00',
		) );

		$result = URL_Transformer::transform(
			'/transform-test/',
			'/%postname%/',
			'/%year%/%monthnum%/%postname%/',
			'en'
		);

		// Clean up
		wp_delete_post( $post_id, true );

		// Result should include year, month, and path prefix
		$this->assertStringContainsString( '/en/', $result );
		$this->assertStringContainsString( 'transform-test', $result );
	}

	/**
	 * Test transform with same structure just updates prefix
	 */
	public function test_transform_same_structure_updates_prefix() {
		$result = URL_Transformer::transform(
			'/hello-world/',
			'/%postname%/',
			'/%postname%/',
			'ja',
			''
		);

		$this->assertEquals( '/ja/hello-world/', $result );
	}

	/**
	 * Test transform with source path prefix
	 */
	public function test_transform_with_source_prefix() {
		$result = URL_Transformer::transform(
			'/en/hello-world/',
			'/%postname%/',
			'/%postname%/',
			'ja',
			'en'
		);

		$this->assertEquals( '/ja/hello-world/', $result );
	}

	// ========================================
	// get_effective_* Tests
	// ========================================

	/**
	 * Test get_effective_permalink_structure with explicit structure
	 */
	public function test_get_effective_permalink_structure_explicit() {
		$virtual_site = array(
			'permalink_structure' => '/%year%/%monthnum%/%postname%/',
			'source_blog_id'      => 1,
		);

		$result = URL_Transformer::get_effective_permalink_structure( $virtual_site );

		$this->assertEquals( '/%year%/%monthnum%/%postname%/', $result );
	}

	/**
	 * Test get_effective_permalink_structure inherits from source
	 */
	public function test_get_effective_permalink_structure_inherit() {
		$virtual_site = array(
			'permalink_structure' => '', // Empty = inherit
			'source_blog_id'      => 0,
		);

		$result = URL_Transformer::get_effective_permalink_structure( $virtual_site );

		// Should return current site's permalink_structure
		$expected = get_option( 'permalink_structure', '' );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Test get_effective_category_base with explicit value
	 */
	public function test_get_effective_category_base_explicit() {
		$virtual_site = array(
			'category_base'  => 'topics',
			'source_blog_id' => 0,
		);

		$result = URL_Transformer::get_effective_category_base( $virtual_site );

		$this->assertEquals( 'topics', $result );
	}

	/**
	 * Test get_effective_category_base inherits from source
	 */
	public function test_get_effective_category_base_inherit() {
		$virtual_site = array(
			'category_base'  => '', // Empty = inherit
			'source_blog_id' => 0,
		);

		$result = URL_Transformer::get_effective_category_base( $virtual_site );

		// Should return 'category' as default when no explicit value
		$expected = get_option( 'category_base', '' ) ?: 'category';
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Test get_effective_tag_base with explicit value
	 */
	public function test_get_effective_tag_base_explicit() {
		$virtual_site = array(
			'tag_base'       => 'labels',
			'source_blog_id' => 0,
		);

		$result = URL_Transformer::get_effective_tag_base( $virtual_site );

		$this->assertEquals( 'labels', $result );
	}

	/**
	 * Test get_effective_tag_base inherits from source
	 */
	public function test_get_effective_tag_base_inherit() {
		$virtual_site = array(
			'tag_base'       => '', // Empty = inherit
			'source_blog_id' => 0,
		);

		$result = URL_Transformer::get_effective_tag_base( $virtual_site );

		// Should return 'tag' as default when no explicit value
		$expected = get_option( 'tag_base', '' ) ?: 'tag';
		$this->assertEquals( $expected, $result );
	}

	// ========================================
	// normalize_structure() Tests
	// ========================================

	/**
	 * Test normalize_structure converts 'plain' to empty string
	 */
	public function test_normalize_structure_plain() {
		$result = URL_Transformer::normalize_structure( 'plain' );
		$this->assertEquals( '', $result );
	}

	/**
	 * Test normalize_structure preserves other values
	 */
	public function test_normalize_structure_other() {
		$result = URL_Transformer::normalize_structure( '/%postname%/' );
		$this->assertEquals( '/%postname%/', $result );

		$result = URL_Transformer::normalize_structure( '/%year%/%monthnum%/%postname%/' );
		$this->assertEquals( '/%year%/%monthnum%/%postname%/', $result );
	}

	// ========================================
	// STRUCTURES Constant Tests
	// ========================================

	/**
	 * Test STRUCTURES constant contains expected formats
	 */
	public function test_structures_constant() {
		$this->assertIsArray( URL_Transformer::STRUCTURES );
		$this->assertArrayHasKey( 'plain', URL_Transformer::STRUCTURES );
		$this->assertArrayHasKey( 'day_and_name', URL_Transformer::STRUCTURES );
		$this->assertArrayHasKey( 'month_and_name', URL_Transformer::STRUCTURES );
		$this->assertArrayHasKey( 'numeric', URL_Transformer::STRUCTURES );
		$this->assertArrayHasKey( 'post_name', URL_Transformer::STRUCTURES );
	}
}

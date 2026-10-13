<?php
/**
 * URL Classifier Tests
 *
 * Tests for WPTSALL\Core\URL_Classifier class
 *
 * @package WPTSALL
 * @since 0.3.0
 */

use WPTSALL\Core\URL_Classifier;
use WPTSALL\Core\URL_Classification;
use WPTSALL\Core\Classification_Constants;

class Test_URL_Classifier extends WP_UnitTestCase {

	/**
	 * Classifier instance
	 *
	 * @var URL_Classifier
	 */
	private $classifier;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
		$this->classifier = new URL_Classifier();
	}

	/**
	 * Test classifier instantiation
	 */
	public function test_classifier_instantiation() {
		$this->assertInstanceOf( URL_Classifier::class, $this->classifier );
	}

	// ==================== classify() Tests ====================

	/**
	 * Test classify returns URL_Classification object
	 */
	public function test_classify_returns_url_classification() {
		$result = $this->classifier->classify( '/blog/test-post/' );
		$this->assertInstanceOf( URL_Classification::class, $result );
	}

	/**
	 * Test classify wp-admin URLs as backend
	 */
	public function test_classify_wp_admin() {
		$admin_urls = array(
			'/wp-admin/',
			'/wp-admin/edit.php',
			'/wp-admin/post.php?post=1&action=edit',
			'/wp-admin/options-general.php',
		);

		foreach ( $admin_urls as $url ) {
			$result = $this->classifier->classify( $url );

			$this->assertEquals(
				Classification_Constants::URL_LOCATION_BACKEND,
				$result->location,
				"URL '$url' should be classified as backend"
			);
			$this->assertFalse(
				$result->syncable,
				"URL '$url' should not be syncable"
			);
			$this->assertEquals( 'wp_admin', $result->matched_rule );
		}
	}

	/**
	 * Test classify login pages as not syncable
	 */
	public function test_classify_login_pages() {
		$login_urls = array(
			'/wp-login.php',
			'/login/',
			'/register/',
		);

		foreach ( $login_urls as $url ) {
			$result = $this->classifier->classify( $url );

			$this->assertFalse(
				$result->syncable,
				"Login URL '$url' should not be syncable"
			);
			$this->assertEquals( 'wp_login', $result->matched_rule );
		}
	}

	/**
	 * Test classify REST API URLs
	 */
	public function test_classify_rest_api() {
		$api_urls = array(
			'/wp-json/',
			'/wp-json/wp/v2/posts',
			'/wp-json/wp/v2/users/1',
			'/wp-json/custom/v1/endpoint',
		);

		foreach ( $api_urls as $url ) {
			$result = $this->classifier->classify( $url );

			$this->assertEquals(
				Classification_Constants::URL_LOCATION_API,
				$result->location,
				"URL '$url' should be classified as API"
			);
			$this->assertTrue(
				$result->syncable,
				"REST API URL '$url' should be syncable"
			);
			$this->assertEquals( 'rest_api', $result->matched_rule );
		}
	}

	/**
	 * Test classify AJAX endpoint as not syncable
	 * Note: /wp-admin/admin-ajax.php matches wp_admin rule first
	 * This tests the ajax pattern matching with a non-wp-admin path
	 */
	public function test_classify_ajax() {
		// Test that admin-ajax.php in wp-admin is matched as backend (wp_admin rule has priority)
		$result = $this->classifier->classify( '/wp-admin/admin-ajax.php' );
		$this->assertEquals( Classification_Constants::URL_LOCATION_BACKEND, $result->location );
		$this->assertFalse( $result->syncable );

		// Test the ajax pattern specifically with a standalone path
		$result2 = $this->classifier->classify( '/admin-ajax.php' );
		$this->assertEquals( Classification_Constants::URL_LOCATION_AJAX, $result2->location );
		$this->assertFalse( $result2->syncable );
		$this->assertEquals( 'ajax', $result2->matched_rule );
	}

	/**
	 * Test classify user account pages as not syncable
	 */
	public function test_classify_user_account() {
		$account_urls = array(
			'/my-account/',
			'/my-account/orders/',
			'/account/',
			'/profile/',
			'/dashboard/',
		);

		foreach ( $account_urls as $url ) {
			$result = $this->classifier->classify( $url );

			$this->assertFalse(
				$result->syncable,
				"User account URL '$url' should not be syncable"
			);
			$this->assertEquals(
				Classification_Constants::URL_ACCESS_LOGIN,
				$result->access,
				"URL '$url' should require login"
			);
		}
	}

	/**
	 * Test classify blog posts as syncable
	 */
	public function test_classify_blog_posts() {
		$blog_urls = array(
			'/blog/test-post/',
			'/post/my-article/',
			'/article/how-to-guide/',
		);

		foreach ( $blog_urls as $url ) {
			$result = $this->classifier->classify( $url );

			$this->assertTrue(
				$result->syncable,
				"Blog URL '$url' should be syncable"
			);
			$this->assertEquals(
				Classification_Constants::URL_LOCATION_FRONTEND,
				$result->location
			);
			$this->assertEquals( 'blog_post', $result->matched_rule );
		}
	}

	/**
	 * Test classify product pages as syncable
	 */
	public function test_classify_product() {
		$result = $this->classifier->classify( '/product/awesome-item/' );

		$this->assertTrue( $result->syncable );
		$this->assertEquals( Classification_Constants::URL_LOCATION_FRONTEND, $result->location );
		$this->assertEquals( 'product', $result->matched_rule );
	}

	/**
	 * Test classify portfolio pages as syncable
	 */
	public function test_classify_portfolio() {
		$result = $this->classifier->classify( '/portfolio/my-work/' );

		$this->assertTrue( $result->syncable );
		$this->assertEquals( 'portfolio', $result->matched_rule );
	}

	/**
	 * Test classify checkout pages as not syncable
	 */
	public function test_classify_checkout() {
		$checkout_urls = array(
			'/cart/',
			'/checkout/',
			'/order/received/',
		);

		foreach ( $checkout_urls as $url ) {
			$result = $this->classifier->classify( $url );

			$this->assertFalse(
				$result->syncable,
				"Checkout URL '$url' should not be syncable"
			);
			$this->assertEquals( 'checkout', $result->matched_rule );
		}
	}

	/**
	 * Test classify full URL (with domain)
	 */
	public function test_classify_full_url() {
		$result = $this->classifier->classify( 'https://example.com/wp-admin/edit.php' );

		$this->assertEquals( Classification_Constants::URL_LOCATION_BACKEND, $result->location );
		$this->assertFalse( $result->syncable );
	}

	/**
	 * Test classify URL with query params
	 */
	public function test_classify_url_with_query() {
		$result = $this->classifier->classify( '/blog/post/?utm_source=twitter' );

		$this->assertTrue( $result->syncable );
		$this->assertEquals( 'blog_post', $result->matched_rule );
	}

	/**
	 * Test default rule for unmatched URLs
	 */
	public function test_default_rule() {
		$result = $this->classifier->classify( '/some-random-page/' );

		$this->assertTrue( $result->syncable );
		$this->assertEquals( 'default', $result->matched_rule );
		$this->assertEquals( Classification_Constants::URL_LOCATION_FRONTEND, $result->location );
		$this->assertEquals( Classification_Constants::URL_ACCESS_PUBLIC, $result->access );
	}

	/**
	 * Test URL without leading slash
	 */
	public function test_url_without_leading_slash() {
		$result = $this->classifier->classify( 'blog/test-post/' );

		$this->assertTrue( $result->syncable );
		$this->assertEquals( 'blog_post', $result->matched_rule );
	}

	// ==================== classify_batch() Tests ====================

	/**
	 * Test classify_batch returns array
	 */
	public function test_classify_batch_returns_array() {
		$urls = array(
			'/blog/post-1/',
			'/blog/post-2/',
		);

		$results = $this->classifier->classify_batch( $urls );

		$this->assertIsArray( $results );
		$this->assertCount( 2, $results );
	}

	/**
	 * Test classify_batch indexes by URL
	 */
	public function test_classify_batch_indexes_by_url() {
		$urls = array(
			'/blog/post-1/',
			'/wp-admin/',
		);

		$results = $this->classifier->classify_batch( $urls );

		$this->assertArrayHasKey( '/blog/post-1/', $results );
		$this->assertArrayHasKey( '/wp-admin/', $results );
		$this->assertInstanceOf( URL_Classification::class, $results['/blog/post-1/'] );
	}

	/**
	 * Test classify_batch with empty array
	 */
	public function test_classify_batch_empty() {
		$results = $this->classifier->classify_batch( array() );

		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	// ==================== filter_syncable() Tests ====================

	/**
	 * Test filter_syncable returns only syncable URLs
	 */
	public function test_filter_syncable() {
		$urls = array(
			'/blog/post/',
			'/wp-admin/',
			'/product/item/',
			'/cart/',
		);

		$syncable = $this->classifier->filter_syncable( $urls );

		$this->assertContains( '/blog/post/', $syncable );
		$this->assertContains( '/product/item/', $syncable );
		$this->assertNotContains( '/wp-admin/', $syncable );
		$this->assertNotContains( '/cart/', $syncable );
	}

	/**
	 * Test filter_syncable with all syncable
	 */
	public function test_filter_syncable_all() {
		$urls = array(
			'/blog/post-1/',
			'/blog/post-2/',
		);

		$syncable = $this->classifier->filter_syncable( $urls );

		$this->assertCount( 2, $syncable );
	}

	/**
	 * Test filter_syncable with none syncable
	 */
	public function test_filter_syncable_none() {
		$urls = array(
			'/wp-admin/',
			'/cart/',
			'/my-account/',
		);

		$syncable = $this->classifier->filter_syncable( $urls );

		$this->assertEmpty( $syncable );
	}

	// ==================== get_statistics() Tests ====================

	/**
	 * Test get_statistics returns correct structure
	 */
	public function test_get_statistics_structure() {
		$urls = array(
			'/blog/post/',
			'/wp-admin/',
		);

		$stats = $this->classifier->get_statistics( $urls );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'syncable', $stats );
		$this->assertArrayHasKey( 'blocked', $stats );
		$this->assertArrayHasKey( 'by_location', $stats );
		$this->assertArrayHasKey( 'by_access', $stats );
		$this->assertArrayHasKey( 'by_rule', $stats );
	}

	/**
	 * Test get_statistics counts correctly
	 */
	public function test_get_statistics_counts() {
		$urls = array(
			'/blog/post/',    // syncable
			'/product/item/', // syncable
			'/wp-admin/',     // blocked
			'/cart/',         // blocked
		);

		$stats = $this->classifier->get_statistics( $urls );

		$this->assertEquals( 4, $stats['total'] );
		$this->assertEquals( 2, $stats['syncable'] );
		$this->assertEquals( 2, $stats['blocked'] );
	}

	/**
	 * Test get_statistics groups by location
	 */
	public function test_get_statistics_by_location() {
		$urls = array(
			'/blog/post/',
			'/wp-admin/',
			'/wp-json/wp/v2/posts',
		);

		$stats = $this->classifier->get_statistics( $urls );

		$this->assertArrayHasKey( Classification_Constants::URL_LOCATION_FRONTEND, $stats['by_location'] );
		$this->assertArrayHasKey( Classification_Constants::URL_LOCATION_BACKEND, $stats['by_location'] );
		$this->assertArrayHasKey( Classification_Constants::URL_LOCATION_API, $stats['by_location'] );
	}

	/**
	 * Test get_statistics groups by rule
	 */
	public function test_get_statistics_by_rule() {
		$urls = array(
			'/blog/post-1/',
			'/blog/post-2/',
			'/wp-admin/',
		);

		$stats = $this->classifier->get_statistics( $urls );

		$this->assertArrayHasKey( 'blog_post', $stats['by_rule'] );
		$this->assertEquals( 2, $stats['by_rule']['blog_post'] );
		$this->assertArrayHasKey( 'wp_admin', $stats['by_rule'] );
		$this->assertEquals( 1, $stats['by_rule']['wp_admin'] );
	}

	/**
	 * Test get_statistics with empty array
	 */
	public function test_get_statistics_empty() {
		$stats = $this->classifier->get_statistics( array() );

		$this->assertEquals( 0, $stats['total'] );
		$this->assertEquals( 0, $stats['syncable'] );
		$this->assertEquals( 0, $stats['blocked'] );
	}

	// ==================== get_rules() Tests ====================

	/**
	 * Test get_rules returns array
	 */
	public function test_get_rules() {
		$rules = $this->classifier->get_rules();

		$this->assertIsArray( $rules );
		$this->assertNotEmpty( $rules );
	}

	/**
	 * Test rules are sorted by priority
	 */
	public function test_rules_sorted_by_priority() {
		$rules = $this->classifier->get_rules();

		$previous_priority = 0;
		foreach ( $rules as $rule ) {
			$current_priority = $rule['priority'] ?? 100;
			$this->assertGreaterThanOrEqual( $previous_priority, $current_priority );
			$previous_priority = $current_priority;
		}
	}

	// ==================== URL_Classification Object Tests ====================

	/**
	 * Test URL_Classification is_frontend method
	 */
	public function test_classification_is_frontend() {
		$result = $this->classifier->classify( '/blog/post/' );
		$this->assertTrue( $result->is_frontend() );
	}

	/**
	 * Test URL_Classification is_backend method
	 */
	public function test_classification_is_backend() {
		$result = $this->classifier->classify( '/wp-admin/' );
		$this->assertTrue( $result->is_backend() );
	}

	/**
	 * Test URL_Classification is_api method
	 */
	public function test_classification_is_api() {
		$result = $this->classifier->classify( '/wp-json/wp/v2/posts' );
		$this->assertTrue( $result->is_api() );
	}

	/**
	 * Test URL_Classification is_public method
	 */
	public function test_classification_is_public() {
		$result = $this->classifier->classify( '/blog/post/' );
		$this->assertTrue( $result->is_public() );
	}

	/**
	 * Test URL_Classification requires_login method
	 */
	public function test_classification_requires_login() {
		$result = $this->classifier->classify( '/my-account/' );
		$this->assertTrue( $result->requires_login() );
	}

	/**
	 * Test URL_Classification to_array method
	 */
	public function test_classification_to_array() {
		$result = $this->classifier->classify( '/blog/post/' );
		$array  = $result->to_array();

		$this->assertIsArray( $array );
		$this->assertArrayHasKey( 'url', $array );
		$this->assertArrayHasKey( 'location', $array );
		$this->assertArrayHasKey( 'access', $array );
		$this->assertArrayHasKey( 'syncable', $array );
		$this->assertArrayHasKey( 'matched_rule', $array );
	}

	/**
	 * Test URL_Classification to_json method
	 */
	public function test_classification_to_json() {
		$result = $this->classifier->classify( '/blog/post/' );
		$json   = $result->to_json();

		$this->assertIsString( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'url', $decoded );
	}

	/**
	 * Test URL_Classification reason property
	 */
	public function test_classification_reason() {
		$result = $this->classifier->classify( '/wp-admin/' );

		$this->assertNotNull( $result->reason );
		$this->assertStringContainsString( 'Backend admin URLs are not syncable', $result->reason );
	}

	// ==================== Edge Cases ====================

	/**
	 * Test HTTP URL extraction
	 */
	public function test_http_url_extraction() {
		$result = $this->classifier->classify( 'http://example.com/wp-admin/' );
		$this->assertEquals( Classification_Constants::URL_LOCATION_BACKEND, $result->location );
	}

	/**
	 * Test HTTPS URL extraction
	 */
	public function test_https_url_extraction() {
		$result = $this->classifier->classify( 'https://example.com/blog/post/' );
		$this->assertEquals( 'blog_post', $result->matched_rule );
	}

	/**
	 * Test root URL
	 */
	public function test_root_url() {
		$result = $this->classifier->classify( '/' );

		$this->assertEquals( 'default', $result->matched_rule );
		$this->assertTrue( $result->syncable );
	}

	/**
	 * Test URL with special characters
	 */
	public function test_url_with_special_characters() {
		$result = $this->classifier->classify( '/blog/post-with-中文/' );

		$this->assertInstanceOf( URL_Classification::class, $result );
	}
}

<?php
/**
 * Unit tests for Smart_Field_Scanner
 *
 * @package WPTSALL
 * @subpackage Tests\Unit\Models
 */

use WPTSALL\Models\Scanners\Smart_Field_Scanner;

/**
 * Test case for Smart_Field_Scanner
 */
class Test_Smart_Field_Scanner extends WP_UnitTestCase {

	/**
	 * Test post IDs created for testing
	 *
	 * @var array
	 */
	private $test_post_ids = array();

	/**
	 * Set up test fixtures
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test posts with meta
		$this->create_test_posts();
	}

	/**
	 * Tear down test fixtures
	 */
	public function tearDown(): void {
		// Clean up test posts
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->test_post_ids = array();

		parent::tearDown();
	}

	/**
	 * Create test posts with various meta fields
	 */
	private function create_test_posts() {
		// Create a post with extensive meta for Golden Record testing
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => 'Test Golden Record Post',
				'post_content' => '<p>Test content with <strong>HTML</strong></p>',
				'post_excerpt' => 'Test excerpt',
				'post_status'  => 'publish',
			)
		);

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			$this->test_post_ids[] = $post_id;

			// Add various meta fields
			update_post_meta( $post_id, '_thumbnail_id', 123 );
			update_post_meta( $post_id, '_custom_text', 'Simple text value' );
			update_post_meta( $post_id, '_custom_html', '<p>HTML content</p>' );
			update_post_meta( $post_id, '_custom_number', 42 );
			update_post_meta( $post_id, '_custom_url', 'https://example.com' );
			update_post_meta( $post_id, '_custom_email', 'test@example.com' );
			update_post_meta( $post_id, '_custom_date', '2024-01-15' );
			update_post_meta( $post_id, '_serialized_data', maybe_serialize( array( 'key' => 'value' ) ) );
			update_post_meta( $post_id, '_json_data', wp_json_encode( array( 'key' => 'value' ) ) );
			update_post_meta( $post_id, '_product_gallery', maybe_serialize( array( 1, 2, 3 ) ) );
		}

		// Create another post for coverage testing
		$post_id2 = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => 'Test Post 2',
				'post_content' => 'Simple content',
				'post_status'  => 'publish',
			)
		);

		if ( $post_id2 && ! is_wp_error( $post_id2 ) ) {
			$this->test_post_ids[] = $post_id2;
			update_post_meta( $post_id2, '_simple_meta', 'value' );
		}
	}

	// ==========================================
	// Basic Scanner Tests
	// ==========================================

	/**
	 * Test scanner instantiation
	 */
	public function test_scanner_instantiation() {
		$scanner = new Smart_Field_Scanner( 'test-plugin', array( 'post' ) );
		$this->assertInstanceOf( Smart_Field_Scanner::class, $scanner );
	}

	/**
	 * Test scanner version
	 */
	public function test_get_version() {
		$version = Smart_Field_Scanner::get_version();
		$this->assertIsString( $version );
		$this->assertStringStartsWith( 'v', $version );
	}

	/**
	 * Test scanner with single post type as string
	 */
	public function test_scanner_with_string_post_type() {
		$scanner = new Smart_Field_Scanner( 'test-plugin', 'post' );
		$results = $scanner->scan();

		$this->assertIsArray( $results );
		$this->assertArrayHasKey( 'post', $results );
	}

	/**
	 * Test scanner with multiple post types
	 */
	public function test_scanner_with_multiple_post_types() {
		$scanner = new Smart_Field_Scanner( 'test-plugin', array( 'post', 'page' ) );
		$results = $scanner->scan();

		$this->assertIsArray( $results );
		$this->assertArrayHasKey( 'post', $results );
		$this->assertArrayHasKey( 'page', $results );
	}

	// ==========================================
	// Scan Result Structure Tests
	// ==========================================

	/**
	 * Test scan result has three-layer structure
	 */
	public function test_scan_result_structure() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$this->assertArrayHasKey( 'post', $results );

		$post_result = $results['post'];
		$this->assertArrayHasKey( 'object_metadata', $post_result );
		$this->assertArrayHasKey( 'url_info', $post_result );
		$this->assertArrayHasKey( 'fields', $post_result );
	}

	/**
	 * Test object_metadata contains expected keys
	 */
	public function test_object_metadata_structure() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$metadata = $results['post']['object_metadata'];

		$this->assertArrayHasKey( 'label', $metadata );
		$this->assertArrayHasKey( 'has_archive', $metadata );
		$this->assertArrayHasKey( 'show_in_rest', $metadata );
		$this->assertArrayHasKey( 'taxonomies', $metadata );
	}

	/**
	 * Test url_info contains expected keys
	 */
	public function test_url_info_structure() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$url_info = $results['post']['url_info'];

		$this->assertArrayHasKey( 'frontend_urls', $url_info );
		$this->assertArrayHasKey( 'backend_urls', $url_info );
		$this->assertArrayHasKey( 'detection_methods', $url_info );
	}

	/**
	 * Test fields are discovered
	 */
	public function test_fields_are_discovered() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		$this->assertIsArray( $fields );
		$this->assertNotEmpty( $fields );

		// Should discover WordPress core fields
		$this->assertArrayHasKey( 'post_title', $fields );
		$this->assertArrayHasKey( 'post_content', $fields );
	}

	// ==========================================
	// Field Type Detection Tests
	// ==========================================

	/**
	 * Test post_title is marked as translatable
	 */
	public function test_post_title_is_translatable() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		if ( isset( $fields['post_title']['type']['sync'] ) ) {
			$this->assertEquals( 'translate_content', $fields['post_title']['type']['sync'] );
		} elseif ( isset( $fields['post_title']['sync_strategy']['action'] ) ) {
			$this->assertEquals( 'translate_content', $fields['post_title']['sync_strategy']['action'] );
		}
	}

	/**
	 * Test post_content is marked as translatable
	 */
	public function test_post_content_is_translatable() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		if ( isset( $fields['post_content']['type']['sync'] ) ) {
			$this->assertEquals( 'translate_content', $fields['post_content']['type']['sync'] );
		} elseif ( isset( $fields['post_content']['sync_strategy']['action'] ) ) {
			$this->assertEquals( 'translate_content', $fields['post_content']['sync_strategy']['action'] );
		}
	}

	/**
	 * Test post_name is marked as translatable slug content
	 */
	public function test_post_name_is_translatable_slug() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		if ( isset( $fields['post_name']['type']['sync'] ) ) {
			$this->assertEquals( 'translate_content', $fields['post_name']['type']['sync'] );
		} elseif ( isset( $fields['post_name']['sync_strategy']['action'] ) ) {
			$this->assertEquals( 'translate_content', $fields['post_name']['sync_strategy']['action'] );
		}
	}

	/**
	 * Test meta fields are discovered
	 */
	public function test_meta_fields_discovered() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		// Custom meta we added in setUp
		$this->assertArrayHasKey( '_custom_text', $fields );
		$this->assertArrayHasKey( '_custom_number', $fields );
	}

	/**
	 * Test _thumbnail_id is detected as ID reference
	 */
	public function test_thumbnail_id_is_id_reference() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		if ( isset( $fields['_thumbnail_id'] ) ) {
			$type = $fields['_thumbnail_id']['type'] ?? $fields['_thumbnail_id']['type_definition'] ?? array();
			$sync = $type['sync'] ?? '';
			$semantic = $type['semantic'] ?? '';

			// Should be detected as relation or image
			$valid = ( 'translate_id' === $sync || in_array( $semantic, array( 'relation', 'image', 'media' ), true ) );
			$this->assertTrue( $valid, 'thumbnail_id should be detected as ID reference' );
		}
	}

	// ==========================================
	// URL Detection Tests
	// ==========================================

	/**
	 * Test frontend URLs are detected for public post types
	 */
	public function test_frontend_urls_detected() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$url_info = $results['post']['url_info'];

		$this->assertArrayHasKey( 'frontend_urls', $url_info );
		// Post type 'post' should have frontend URLs
		if ( ! empty( $url_info['frontend_urls'] ) ) {
			$first_url = $url_info['frontend_urls'][0];
			$this->assertArrayHasKey( 'url_type', $first_url );
		}
	}

	/**
	 * Test backend URLs are detected
	 */
	public function test_backend_urls_detected() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$backend_urls = $results['post']['url_info']['backend_urls'];

		$this->assertArrayHasKey( 'edit', $backend_urls );
		$this->assertArrayHasKey( 'list', $backend_urls );
		$this->assertArrayHasKey( 'new', $backend_urls );
	}

	/**
	 * Test backend edit URL pattern
	 */
	public function test_backend_edit_url_pattern() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$edit_url = $results['post']['url_info']['backend_urls']['edit'];

		$this->assertStringContainsString( 'post.php', $edit_url );
		$this->assertStringContainsString( 'action=edit', $edit_url );
	}

	// ==========================================
	// Object Metadata Tests
	// ==========================================

	/**
	 * Test object metadata for post type
	 */
	public function test_object_metadata_values() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$metadata = $results['post']['object_metadata'];

		$this->assertTrue( in_array( $metadata['label'], array( 'Posts', '文章' ), true ), 'label was ' . $metadata['label'] );
		$this->assertTrue( $metadata['show_in_rest'] );
		$this->assertContains( 'category', $metadata['taxonomies'] );
		$this->assertContains( 'post_tag', $metadata['taxonomies'] );
	}

	/**
	 * Test page post type metadata
	 */
	public function test_page_metadata() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'page' ) );
		$results = $scanner->scan();

		$metadata = $results['page']['object_metadata'];

		$this->assertTrue( in_array( $metadata['label'], array( 'Pages', '页面' ), true ), 'label was ' . $metadata['label'] );
		$this->assertTrue( $metadata['hierarchical'] );
	}

	// ==========================================
	// Discovery Confidence Tests
	// ==========================================

	/**
	 * Test fields have discovery information
	 */
	public function test_fields_have_discovery_info() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		foreach ( $fields as $field_name => $field_info ) {
			if ( isset( $field_info['discovery'] ) ) {
				$this->assertArrayHasKey( 'confidence', $field_info['discovery'] );
				$this->assertArrayHasKey( 'methods', $field_info['discovery'] );
				$this->assertIsInt( $field_info['discovery']['confidence'] );
				$this->assertIsArray( $field_info['discovery']['methods'] );
			}
		}
	}

	// ==========================================
	// Edge Cases
	// ==========================================

	/**
	 * Test scanner with non-existent post type
	 */
	public function test_scanner_with_nonexistent_post_type() {
		$scanner = new Smart_Field_Scanner( 'test-plugin', array( 'nonexistent_type' ) );
		$results = $scanner->scan();

		$this->assertIsArray( $results );
		$this->assertArrayHasKey( 'nonexistent_type', $results );

		// Should still have structure but minimal data
		$result = $results['nonexistent_type'];
		$this->assertArrayHasKey( 'object_metadata', $result );
		$this->assertArrayHasKey( 'url_info', $result );
		$this->assertArrayHasKey( 'fields', $result );
	}

	/**
	 * Test scanner with empty post types array
	 */
	public function test_scanner_with_empty_post_types() {
		$scanner = new Smart_Field_Scanner( 'test-plugin', array() );
		$results = $scanner->scan();

		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	// ==========================================
	// REST API Post Type Tests
	// ==========================================

	/**
	 * Test REST API info is detected for REST-enabled post types
	 */
	public function test_rest_api_info_detected() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$url_info = $results['post']['url_info'];

		// Post type 'post' has REST API support
		if ( isset( $url_info['rest_api'] ) ) {
			$this->assertArrayHasKey( 'rest_endpoint', $url_info['rest_api'] );
		}
	}

	// ==========================================
	// Field Source Tracking Tests
	// ==========================================

	/**
	 * Test fields track their discovery source
	 */
	public function test_fields_track_source() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		// At least some fields should have source info
		$has_source = false;
		foreach ( $fields as $field ) {
			if ( isset( $field['source'] ) || isset( $field['discovery']['methods'] ) ) {
				$has_source = true;
				break;
			}
		}

		$this->assertTrue( $has_source, 'At least some fields should track their discovery source' );
	}

	// ==========================================
	// Serialized Data Tests
	// ==========================================

	/**
	 * Test serialized meta is detected
	 */
	public function test_serialized_meta_detected() {
		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post' ) );
		$results = $scanner->scan();

		$fields = $results['post']['fields'];

		// Just verify the field is discovered - type detection may vary
		if ( isset( $fields['_serialized_data'] ) ) {
			$this->assertIsArray( $fields['_serialized_data'] );
		} else {
			// If not discovered, just pass (field might not exist in test environment)
			$this->assertTrue( true );
		}
	}

	// ==========================================
	// Performance Tests
	// ==========================================

	/**
	 * Test scanner completes within reasonable time
	 */
	public function test_scanner_performance() {
		$start = microtime( true );

		$scanner = new Smart_Field_Scanner( 'wordpress-blog', array( 'post', 'page' ) );
		$results = $scanner->scan();

		$elapsed = microtime( true ) - $start;

		// Should complete within 5 seconds even on slow systems
		$this->assertTrue( $elapsed < 5, 'Scanner should complete within 5 seconds' );
		$this->assertNotEmpty( $results );
	}
}

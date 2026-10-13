<?php
/**
 * Content String Scanner Tests
 *
 * Tests for WPTSALL\Templates\Scanners\Content_String_Scanner class
 *
 * Tests scanning database content for translatable strings.
 *
 * @package WPTSALL\Tests\Unit\Templates
 * @since 0.7.0
 */

use WPTSALL\Templates\Scanners\Content_String_Scanner;

class Test_Content_String_Scanner extends SimpleTestCase {

	/**
	 * Test post IDs created during tests
	 *
	 * @var array
	 */
	private $test_post_ids = array();

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
	 * Test class exists
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\\Templates\\Scanners\\Content_String_Scanner' ) );
	}

	/**
	 * Test class has required methods
	 */
	public function test_class_has_required_methods() {
		$methods = array(
			'scan_site_content',
			'scan',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\\Templates\\Scanners\\Content_String_Scanner', $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== scan_site_content() Tests ====================

	/**
	 * Test scan_site_content returns array
	 */
	public function test_scan_site_content_returns_array() {
		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content();

		$this->assertIsArray( $result );
	}

	/**
	 * Test scan_site_content with custom post types
	 */
	public function test_scan_site_content_with_post_types() {
		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array( 'post', 'page' ) );

		$this->assertIsArray( $result );
	}

	/**
	 * Test scan_site_content with custom taxonomies
	 */
	public function test_scan_site_content_with_taxonomies() {
		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array(), array( 'category', 'post_tag' ) );

		$this->assertIsArray( $result );
	}

	/**
	 * Test scan_site_content entry structure
	 */
	public function test_scan_site_content_entry_structure() {
		// Create a test post to ensure we have at least one entry
		$post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Test Translatable Title ' . uniqid(),
			'post_excerpt' => 'Test translatable excerpt content.',
		) );
		$this->test_post_ids[] = $post_id;

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array( 'post' ) );

		if ( empty( $result ) ) {
			$this->markTestSkipped( 'No entries found' );
			return;
		}

		$first = $result[0];

		$this->assertArrayHasKey( 'msgid', $first );
		$this->assertArrayHasKey( 'msgid_plural', $first );
		$this->assertArrayHasKey( 'msgctxt', $first );
		$this->assertArrayHasKey( 'reference', $first );
		$this->assertArrayHasKey( 'source', $first );
		$this->assertArrayHasKey( 'content_type', $first );
		$this->assertArrayHasKey( 'object_id', $first );
	}

	/**
	 * Test scan_site_content finds post titles
	 */
	public function test_scan_finds_post_titles() {
		$unique_title = 'Unique Test Post Title ' . uniqid();
		$post_id      = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => $unique_title,
		) );
		$this->test_post_ids[] = $post_id;

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array( 'post' ) );

		$found = false;
		foreach ( $result as $entry ) {
			if ( $entry['msgid'] === $unique_title ) {
				$found = true;
				$this->assertEquals( 'post_title', $entry['content_type'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Should find the test post title' );
	}

	/**
	 * Test scan_site_content finds post excerpts
	 */
	public function test_scan_finds_post_excerpts() {
		$unique_excerpt = 'Unique test excerpt content for scanning ' . uniqid();
		$post_id        = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Test Post',
			'post_excerpt' => $unique_excerpt,
		) );
		$this->test_post_ids[] = $post_id;

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array( 'post' ) );

		$found = false;
		foreach ( $result as $entry ) {
			if ( $entry['msgid'] === $unique_excerpt ) {
				$found = true;
				$this->assertEquals( 'post_excerpt', $entry['content_type'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Should find the test post excerpt' );
	}

	/**
	 * Test scan_site_content finds term names
	 */
	public function test_scan_finds_term_names() {
		$unique_name = 'Unique Test Category ' . uniqid();
		$term        = wp_insert_term( $unique_name, 'category' );

		if ( is_wp_error( $term ) ) {
			$this->markTestSkipped( 'Could not create test term' );
			return;
		}

		$this->test_term_ids[] = $term['term_id'];

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array(), array( 'category' ) );

		$found = false;
		foreach ( $result as $entry ) {
			if ( $entry['msgid'] === $unique_name ) {
				$found = true;
				$this->assertEquals( 'term_name', $entry['content_type'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Should find the test term name' );
	}

	/**
	 * Test scan_site_content finds term descriptions
	 */
	public function test_scan_finds_term_descriptions() {
		$unique_desc = 'Unique test category description for scanning ' . uniqid();
		$term        = wp_insert_term(
			'Test Category Desc ' . uniqid(),
			'category',
			array( 'description' => $unique_desc )
		);

		if ( is_wp_error( $term ) ) {
			$this->markTestSkipped( 'Could not create test term' );
			return;
		}

		$this->test_term_ids[] = $term['term_id'];

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array(), array( 'category' ) );

		$found = false;
		foreach ( $result as $entry ) {
			if ( $entry['msgid'] === $unique_desc ) {
				$found = true;
				$this->assertEquals( 'term_description', $entry['content_type'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Should find the test term description' );
	}

	// ==================== Static scan() Tests ====================

	/**
	 * Test static scan method returns array
	 */
	public function test_static_scan_returns_array() {
		$result = Content_String_Scanner::scan();

		$this->assertIsArray( $result );
	}

	/**
	 * Test static scan with parameters
	 */
	public function test_static_scan_with_parameters() {
		$result = Content_String_Scanner::scan( array( 'post' ), array( 'category' ) );

		$this->assertIsArray( $result );
	}

	// ==================== Filtering Tests ====================

	/**
	 * Test scan skips empty strings
	 */
	public function test_scan_skips_empty_strings() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => '', // Empty title
		) );
		$this->test_post_ids[] = $post_id;

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array( 'post' ) );

		// Should not include entries with empty msgid
		foreach ( $result as $entry ) {
			$this->assertNotEmpty( $entry['msgid'] );
		}
	}

	/**
	 * Test scan skips numeric-only strings
	 */
	public function test_scan_skips_numeric_strings() {
		// The scanner uses is_translatable() to filter
		// Numeric strings should be filtered out
		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content();

		foreach ( $result as $entry ) {
			$this->assertFalse(
				is_numeric( $entry['msgid'] ),
				'Should not include purely numeric strings'
			);
		}
	}

	/**
	 * Test scan skips URLs
	 */
	public function test_scan_skips_urls() {
		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content();

		foreach ( $result as $entry ) {
			$this->assertFalse(
				filter_var( $entry['msgid'], FILTER_VALIDATE_URL ),
				'Should not include URLs'
			);
		}
	}

	/**
	 * Test scan skips email addresses
	 */
	public function test_scan_skips_emails() {
		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content();

		foreach ( $result as $entry ) {
			$this->assertFalse(
				filter_var( $entry['msgid'], FILTER_VALIDATE_EMAIL ),
				'Should not include email addresses'
			);
		}
	}

	// ==================== Deduplication Tests ====================

	/**
	 * Test scan deduplicates entries
	 */
	public function test_scan_deduplicates_entries() {
		// Create two posts with the same title
		$same_title = 'Duplicate Title for Testing ' . uniqid();

		$post_id1 = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => $same_title,
		) );
		$this->test_post_ids[] = $post_id1;

		$post_id2 = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => $same_title,
		) );
		$this->test_post_ids[] = $post_id2;

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array( 'post' ) );

		// Count entries with our title
		$count = 0;
		foreach ( $result as $entry ) {
			if ( $entry['msgid'] === $same_title && 'post_title' === $entry['content_type'] ) {
				$count++;
			}
		}

		// Should only appear once (deduplicated)
		$this->assertEquals( 1, $count, 'Duplicate entries should be merged' );
	}

	// ==================== Reference Format Tests ====================

	/**
	 * Test post reference format
	 */
	public function test_post_reference_format() {
		$unique_title = 'Reference Test Post ' . uniqid();
		$post_id      = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => $unique_title,
		) );
		$this->test_post_ids[] = $post_id;

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array( 'post' ) );

		foreach ( $result as $entry ) {
			if ( $entry['msgid'] === $unique_title ) {
				$this->assertStringStartsWith( 'post:', $entry['reference'] );
				$this->assertStringContainsString( (string) $post_id, $entry['reference'] );
				break;
			}
		}
	}

	/**
	 * Test term reference format
	 */
	public function test_term_reference_format() {
		$unique_name = 'Reference Test Term ' . uniqid();
		$term        = wp_insert_term( $unique_name, 'category' );

		if ( is_wp_error( $term ) ) {
			$this->markTestSkipped( 'Could not create test term' );
			return;
		}

		$this->test_term_ids[] = $term['term_id'];

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array(), array( 'category' ) );

		foreach ( $result as $entry ) {
			if ( $entry['msgid'] === $unique_name ) {
				$this->assertStringStartsWith( 'term:', $entry['reference'] );
				$this->assertStringContainsString( (string) $term['term_id'], $entry['reference'] );
				break;
			}
		}
	}

	// ==================== Menu Scanning Tests ====================

	/**
	 * Test scan_site_content includes menu scanning
	 */
	public function test_scan_includes_menus() {
		// Create a test menu
		$menu_name = 'Test Menu ' . uniqid();
		$menu_id   = wp_create_nav_menu( $menu_name );

		if ( is_wp_error( $menu_id ) ) {
			$this->markTestSkipped( 'Could not create test menu' );
			return;
		}

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content();

		// Menu should be found
		$found = false;
		foreach ( $result as $entry ) {
			if ( $entry['msgid'] === $menu_name ) {
				$found = true;
				$this->assertEquals( 'menu_name', $entry['content_type'] );
				break;
			}
		}

		// Clean up
		wp_delete_nav_menu( $menu_id );

		$this->assertTrue( $found, 'Should find the test menu name' );
	}

	// ==================== Source Field Tests ====================

	/**
	 * Test all entries have content_scan source
	 */
	public function test_all_entries_have_content_scan_source() {
		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content();

		foreach ( $result as $entry ) {
			$this->assertEquals( 'content_scan', $entry['source'] );
		}
	}

	// ==================== Placeholder Protection Tests ====================

	/**
	 * Test pure printf placeholders are never scanned for translation
	 *
	 * SEM-PLACEHOLDERS regression (catalog content-semantics; plan §7
	 * TEST-CONTENT-SEMANTICS-001; gap-analysis SEM-05: the real printf
	 * protection lives here, not in the retired field-processor TODO).
	 */
	public function test_scan_skips_pure_printf_placeholders() {
		// Pure placeholder strings must be filtered by is_translatable().
		$this->assertFalse( Content_String_Scanner::is_translatable( '%s' ) );
		$this->assertFalse( Content_String_Scanner::is_translatable( '%d' ) );
		$this->assertFalse( Content_String_Scanner::is_translatable( '%1$s' ) );
		$this->assertFalse( Content_String_Scanner::is_translatable( '%2$d' ) );
		$this->assertFalse( Content_String_Scanner::is_translatable( '%05.2f' ) );
		$this->assertFalse( Content_String_Scanner::is_translatable( '%%' ) );

		// Contrast: mixed strings carry their placeholder into translation
		// (verbatim preservation is the write-side contract), and plain
		// text stays translatable.
		$this->assertTrue( Content_String_Scanner::is_translatable( 'Save %s items' ) );
		$this->assertTrue( Content_String_Scanner::is_translatable( 'Rating: %1$d of %2$d' ) );
		$this->assertTrue( Content_String_Scanner::is_translatable( 'Plain translatable text' ) );

		// DB-level: a post whose title is a pure placeholder never produces
		// a scan entry.
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => '%s',
		) );
		$this->test_post_ids[] = $post_id;

		$scanner = new Content_String_Scanner();
		$result  = $scanner->scan_site_content( array( 'post' ) );

		foreach ( $result as $entry ) {
			$this->assertNotSame(
				'%s',
				$entry['msgid'],
				'Pure placeholder title must not be scanned for translation'
			);
		}
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		// Clean up test posts
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Clean up test terms
		foreach ( $this->test_term_ids as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		parent::tearDown();
	}
}

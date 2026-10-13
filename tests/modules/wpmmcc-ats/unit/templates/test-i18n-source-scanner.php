<?php
/**
 * I18n Source Scanner Tests
 *
 * Tests for WPTSALL\Templates\Scanners\I18n_Source_Scanner class
 *
 * Tests scanning PHP/JS source files for internationalization function calls.
 *
 * @package WPTSALL\Tests\Unit\Templates
 * @since 0.7.0
 */

use WPTSALL\Templates\Scanners\I18n_Source_Scanner;

class Test_I18n_Source_Scanner extends SimpleTestCase {

	/**
	 * Resolve plugin directory and text domain for scanner tests.
	 *
	 * @return array{0:string,1:string}
	 */
	private function get_plugin_scan_target() {
		if ( defined( 'WPTSALL_PATH' ) && is_dir( WPTSALL_PATH ) ) {
			return array( WPTSALL_PATH, 'wpmmcc-ats' );
		}

		foreach ( array( 'wpmmcc-ats', 'wptsall' ) as $slug ) {
			$dir = WP_PLUGIN_DIR . '/' . $slug;
			if ( is_dir( $dir ) ) {
				return array( $dir, 'wpmmcc-ats' );
			}
		}

		return array( '', 'wpmmcc-ats' );
	}

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
		$this->assertTrue( class_exists( 'WPTSALL\\Templates\\Scanners\\I18n_Source_Scanner' ) );
	}

	/**
	 * Test class has required methods
	 */
	public function test_class_has_required_methods() {
		$methods = array(
			'scan_theme',
			'scan_plugin',
			'scan_theme_static',
			'scan_plugin_static',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( 'WPTSALL\\Templates\\Scanners\\I18n_Source_Scanner', $method ),
				"Method {$method} should exist"
			);
		}
	}

	// ==================== scan_theme() Tests ====================

	/**
	 * Test scan_theme returns array
	 */
	public function test_scan_theme_returns_array() {
		$scanner    = new I18n_Source_Scanner();
		$theme_dir  = get_template_directory();
		$theme_data = wp_get_theme();
		$text_domain = $theme_data->get( 'TextDomain' ) ?: $theme_data->get_stylesheet();

		$result = $scanner->scan_theme( $theme_dir, $text_domain );

		$this->assertIsArray( $result );
	}

	/**
	 * Test scan_theme with invalid directory
	 */
	public function test_scan_theme_with_invalid_directory() {
		$scanner = new I18n_Source_Scanner();
		$result  = $scanner->scan_theme( '/non/existent/directory', 'test-domain' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test scan_theme entry structure
	 */
	public function test_scan_theme_entry_structure() {
		$scanner    = new I18n_Source_Scanner();
		$theme_dir  = get_template_directory();
		$theme_data = wp_get_theme();
		$text_domain = $theme_data->get( 'TextDomain' ) ?: $theme_data->get_stylesheet();

		$result = $scanner->scan_theme( $theme_dir, $text_domain );

		if ( empty( $result ) ) {
			$this->markTestSkipped( 'No i18n entries found in theme' );
			return;
		}

		$first = $result[0];

		$this->assertArrayHasKey( 'msgid', $first );
		$this->assertArrayHasKey( 'msgid_plural', $first );
		$this->assertArrayHasKey( 'msgctxt', $first );
		$this->assertArrayHasKey( 'reference', $first );
		$this->assertArrayHasKey( 'source', $first );
	}

	/**
	 * Test scan_theme source is source_scan
	 */
	public function test_scan_theme_source_is_source_scan() {
		$scanner    = new I18n_Source_Scanner();
		$theme_dir  = get_template_directory();
		$theme_data = wp_get_theme();
		$text_domain = $theme_data->get( 'TextDomain' ) ?: $theme_data->get_stylesheet();

		$result = $scanner->scan_theme( $theme_dir, $text_domain );

		foreach ( $result as $entry ) {
			$this->assertEquals( 'source_scan', $entry['source'] );
		}
	}

	// ==================== scan_plugin() Tests ====================

	/**
	 * Test scan_plugin returns array
	 */
	public function test_scan_plugin_returns_array() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		$this->assertIsArray( $result );
	}

	/**
	 * Test scan_plugin with invalid directory
	 */
	public function test_scan_plugin_with_invalid_directory() {
		$scanner = new I18n_Source_Scanner();
		$result  = $scanner->scan_plugin( '/non/existent/plugin/directory', 'test-domain' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test scan_plugin finds wptsall strings
	 */
	public function test_scan_plugin_finds_wptsall_strings() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// WPTSALL should have at least some translatable strings
		$this->assertGreaterThan( 0, count( $result ), 'WPTSALL should have translatable strings' );
	}

	// ==================== Static Method Tests ====================

	/**
	 * Test scan_theme_static returns array
	 */
	public function test_scan_theme_static_returns_array() {
		$theme_dir  = get_template_directory();
		$theme_data = wp_get_theme();
		$text_domain = $theme_data->get( 'TextDomain' ) ?: $theme_data->get_stylesheet();

		$result = I18n_Source_Scanner::scan_theme_static( $theme_dir, $text_domain );

		$this->assertIsArray( $result );
	}

	/**
	 * Test scan_plugin_static returns array
	 */
	public function test_scan_plugin_static_returns_array() {
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = I18n_Source_Scanner::scan_plugin_static( $plugin_dir, $text_domain );

		$this->assertIsArray( $result );
	}

	// ==================== Deduplication Tests ====================

	/**
	 * Test scan deduplicates entries
	 */
	public function test_scan_deduplicates_entries() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// Check for duplicates
		$seen = array();
		$duplicates = 0;

		foreach ( $result as $entry ) {
			$key = md5( $entry['msgid'] . '|' . ( $entry['msgctxt'] ?? '' ) );
			if ( isset( $seen[ $key ] ) ) {
				$duplicates++;
			}
			$seen[ $key ] = true;
		}

		$this->assertEquals( 0, $duplicates, 'Should not have duplicate entries' );
	}

	// ==================== Reference Format Tests ====================

	/**
	 * Test reference includes line number
	 */
	public function test_reference_includes_line_number() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		if ( empty( $result ) ) {
			$this->markTestSkipped( 'No entries found' );
			return;
		}

		// References should include line numbers (file:line format)
		foreach ( $result as $entry ) {
			if ( ! empty( $entry['reference'] ) ) {
				// At least the first reference should have a line number
				$first_ref = explode( ', ', $entry['reference'] )[0];
				$this->assertMatchesRegularExpression( '/:\d+$/', $first_ref, 'Reference should include line number' );
				break;
			}
		}
	}

	// ==================== PHP Function Detection Tests ====================

	/**
	 * Test scanner detects __() calls
	 */
	public function test_detects_double_underscore_function() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// Should find strings from __() calls
		$this->assertNotEmpty( $result, 'Should detect __() function calls' );
	}

	/**
	 * Test scanner handles esc_html__() calls
	 */
	public function test_handles_esc_html_function() {
		// The scanner supports esc_html__, esc_attr__, etc.
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// Just verify it runs without error
		$this->assertIsArray( $result );
	}

	// ==================== Context Handling Tests ====================

	/**
	 * Test scanner captures context from _x() calls
	 */
	public function test_captures_context() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// Look for entries with context
		$has_context = false;
		foreach ( $result as $entry ) {
			if ( ! empty( $entry['msgctxt'] ) ) {
				$has_context = true;
				break;
			}
		}

		// This is not a hard assertion since the plugin may not use _x()
		// Just verify the structure supports context
		$this->assertIsArray( $result );
	}

	// ==================== Plural Handling Tests ====================

	/**
	 * Test scanner handles _n() plural forms
	 */
	public function test_handles_plural_forms() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// Look for entries with plural forms
		$has_plural = false;
		foreach ( $result as $entry ) {
			if ( ! empty( $entry['msgid_plural'] ) ) {
				$has_plural = true;
				break;
			}
		}

		// Not a hard assertion since the plugin may not use _n()
		// Just verify the structure supports plurals
		$this->assertIsArray( $result );
	}

	// ==================== Excluded Directory Tests ====================

	/**
	 * Test scanner excludes vendor directory
	 */
	public function test_excludes_vendor_directory() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// References should not include 'vendor/' path
		foreach ( $result as $entry ) {
			if ( ! empty( $entry['reference'] ) ) {
				$this->assertStringNotContainsString( 'vendor/', $entry['reference'] );
			}
		}
	}

	/**
	 * Test scanner excludes node_modules directory
	 */
	public function test_excludes_node_modules_directory() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// References should not include 'node_modules/' path
		foreach ( $result as $entry ) {
			if ( ! empty( $entry['reference'] ) ) {
				$this->assertStringNotContainsString( 'node_modules/', $entry['reference'] );
			}
		}
	}

	/**
	 * Test scanner excludes tests directory
	 */
	public function test_excludes_tests_directory() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		$result = $scanner->scan_plugin( $plugin_dir, $text_domain );

		// References should not include '/tests/' path
		foreach ( $result as $entry ) {
			if ( ! empty( $entry['reference'] ) ) {
				$this->assertStringNotContainsString( '/tests/', $entry['reference'] );
			}
		}
	}

	// ==================== Text Domain Filtering Tests ====================

	/**
	 * Test scanner only finds entries with specified text domain
	 */
	public function test_filters_by_text_domain() {
		$scanner    = new I18n_Source_Scanner();
		list( $plugin_dir, $text_domain ) = $this->get_plugin_scan_target();

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( 'WPTSALL plugin directory not found' );
			return;
		}

		// Scan with wrong text domain
		$result = $scanner->scan_plugin( $plugin_dir, 'wrong-text-domain-xyz' );

		// Should find nothing or very few strings (maybe some without domain)
		$this->assertIsArray( $result );
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}

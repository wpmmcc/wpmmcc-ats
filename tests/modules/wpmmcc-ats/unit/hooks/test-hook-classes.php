<?php
/**
 * Hook Classes Tests
 *
 * Tests for WPTSALL\Hooks module classes
 *
 * @package WPTSALL
 * @since 0.5.0
 */

use WPTSALL\Hooks\Hook_Manager;
use WPTSALL\Hooks\Virtual_Site_Router;
use WPTSALL\Hooks\Gettext_Filter;

class Test_Hook_Classes extends WP_UnitTestCase {

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

	// =========================================================================
	// Hook_Manager Tests
	// =========================================================================

	/**
	 * Test Hook_Manager class exists
	 */
	public function test_hook_manager_class_exists() {
		$this->assertTrue(
			class_exists( '\WPTSALL\Hooks\Hook_Manager' ),
			'Hook_Manager class should exist'
		);
	}

	/**
	 * Test Hook_Manager has required methods
	 */
	public function test_hook_manager_has_required_methods() {
		$methods = array(
			'register_dynamic_hooks',
			'generate_auto_hooks',
			'run_hook_action',
			'collect_hooks',
			'get_site_rel',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( '\WPTSALL\Hooks\Hook_Manager', $method ),
				"Hook_Manager should have method: {$method}"
			);
		}
	}

	// =========================================================================
	// Virtual_Site_Router Tests
	// =========================================================================

	/**
	 * Test Virtual_Site_Router class exists
	 */
	public function test_virtual_site_router_class_exists() {
		$this->assertTrue(
			class_exists( '\WPTSALL\Hooks\Virtual_Site_Router' ),
			'Virtual_Site_Router class should exist'
		);
	}

	/**
	 * Test Virtual_Site_Router has required methods
	 */
	public function test_virtual_site_router_has_required_methods() {
		$methods = array(
			'init',
			'detect_virtual_site',
			'get_current_virtual_site',
			'is_virtual_site',
			'parse_request',
			'template_redirect',
			'filter_title',
			'filter_content',
			'filter_excerpt',
			'rewrite_post_link',
			'rewrite_page_link',
			'rewrite_term_link',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( '\WPTSALL\Hooks\Virtual_Site_Router', $method ),
				"Virtual_Site_Router should have method: {$method}"
			);
		}
		$this->assertTrue(
			method_exists( '\WPTSALL\Hooks\Virtual_Site_Router', 'requires_virtual_router_to_serve' ),
			'Virtual_Site_Router should expose requires_virtual_router_to_serve'
		);
		$this->assertTrue(
			class_exists( '\WPTSALL\Hooks\Virtual_Site_Query_Switch' ),
			'Virtual_Site_Query_Switch class should exist'
		);
	}

	/**
	 * Test Virtual_Site_Router get_current_virtual_site returns null when not on virtual site
	 */
	public function test_virtual_site_router_get_current_returns_null_by_default() {
		// Clear any existing virtual site
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		$result = Virtual_Site_Router::get_current_virtual_site();
		$this->assertNull( $result, 'get_current_virtual_site() should return null when not on a virtual site' );
	}

	/**
	 * Test Virtual_Site_Router is_virtual_site returns false by default
	 */
	public function test_virtual_site_router_is_virtual_site_returns_false_by_default() {
		// Clear any existing virtual site
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		$result = Virtual_Site_Router::is_virtual_site();
		$this->assertFalse( $result, 'is_virtual_site() should return false when not on a virtual site' );
	}

	/**
	 * Test Virtual_Site_Router filter_title passes through when no virtual site
	 */
	public function test_virtual_site_router_filter_title_passthrough() {
		// Clear any existing virtual site
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		$title    = 'Test Title';
		$filtered = Virtual_Site_Router::filter_title( $title, 0 );

		$this->assertEquals( $title, $filtered, 'filter_title() should pass through original title when no virtual site' );
	}

	/**
	 * Test Virtual_Site_Router filter_content passes through when no virtual site
	 */
	public function test_virtual_site_router_filter_content_passthrough() {
		// Clear any existing virtual site
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		$content  = 'Test content here.';
		$filtered = Virtual_Site_Router::filter_content( $content );

		$this->assertEquals( $content, $filtered, 'filter_content() should pass through original content when no virtual site' );
	}

	// =========================================================================
	// Gettext_Filter Tests
	// =========================================================================

	/**
	 * Test Gettext_Filter class exists
	 */
	public function test_gettext_filter_class_exists() {
		$this->assertTrue(
			class_exists( '\WPTSALL\Hooks\Gettext_Filter' ),
			'Gettext_Filter class should exist'
		);
	}

	/**
	 * Test Gettext_Filter has required methods
	 */
	public function test_gettext_filter_has_required_methods() {
		$methods = array(
			'init',
			'maybe_init_filters',
			'preload_translations',
			'filter_gettext',
			'filter_gettext_with_context',
			'filter_ngettext',
			'filter_ngettext_with_context',
			'clear_cache',
			'is_initialized',
			'get_loaded_domains',
			'get_cache_stats',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( '\WPTSALL\Hooks\Gettext_Filter', $method ),
				"Gettext_Filter should have method: {$method}"
			);
		}
	}

	/**
	 * Test Gettext_Filter is_initialized returns bool
	 */
	public function test_gettext_filter_is_initialized_returns_bool() {
		$result = Gettext_Filter::is_initialized();
		$this->assertIsBool( $result, 'is_initialized() should return a boolean' );
	}

	/**
	 * Test Gettext_Filter get_loaded_domains returns array
	 */
	public function test_gettext_filter_get_loaded_domains_returns_array() {
		$result = Gettext_Filter::get_loaded_domains();
		$this->assertIsArray( $result, 'get_loaded_domains() should return an array' );
	}

	/**
	 * Test Gettext_Filter get_cache_stats returns array with expected keys
	 */
	public function test_gettext_filter_get_cache_stats_returns_expected_structure() {
		$stats = Gettext_Filter::get_cache_stats();

		$this->assertIsArray( $stats, 'get_cache_stats() should return an array' );
		$this->assertArrayHasKey( 'entries', $stats, 'Cache stats should have "entries" key' );
		$this->assertArrayHasKey( 'loaded_domains', $stats, 'Cache stats should have "loaded_domains" key' );
	}

	/**
	 * Test Gettext_Filter filter_gettext passes through when not initialized
	 */
	public function test_gettext_filter_passthrough_when_not_on_virtual_site() {
		$translation = 'Hello World';
		$text        = 'Hello World';
		$domain      = 'test-domain';

		$filtered = Gettext_Filter::filter_gettext( $translation, $text, $domain );

		$this->assertEquals( $translation, $filtered, 'filter_gettext() should pass through when no cache match' );
	}

	/**
	 * Test Gettext_Filter clear_cache does not throw errors
	 */
	public function test_gettext_filter_clear_cache_no_errors() {
		// Should not throw any errors
		Gettext_Filter::clear_cache();
		Gettext_Filter::clear_cache( 'specific-domain' );

		$this->assertTrue( true, 'clear_cache() should execute without errors' );
	}

	// =========================================================================
	// Backward Compatibility Tests
	// =========================================================================

	/**
	 * Test backward compatible functions exist
	 */
	public function test_backward_compatible_functions_exist() {
		// Only check functions that are actually implemented as wrappers
		$functions = array(
			'wptsall_hook_recommendations',
		);

		foreach ( $functions as $function ) {
			$this->assertTrue(
				function_exists( $function ),
				"Backward compatible function should exist: {$function}"
			);
		}
	}

	/**
	 * Test wptsall_hook_recommendations returns array
	 */
	public function test_wptsall_hook_recommendations_returns_array() {
		$result = wptsall_hook_recommendations( 'test-plugin' );
		$this->assertIsArray( $result, 'wptsall_hook_recommendations() should return an array' );
	}
}

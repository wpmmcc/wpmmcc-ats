<?php
/**
 * Site String Scanner + Site Identity Tests
 *
 * Tests for WPTSALL\Strings\Services\Site_String_Scanner (Layer B discovery:
 * site identity, menu labels, widget text) and WPTSALL\Strings\Site_Identity_Translation
 * (front-end option filters), plus strings module bootstrap hook wiring.
 *
 * @package WPTSALL\Tests\Unit\Strings
 * @since 2.3.0
 */

use WPTSALL\Strings\Services\String_Translation_Service;
use WPTSALL\Strings\Services\Site_String_Scanner;
use WPTSALL\Strings\Site_Identity_Translation;
use WPTSALL\Core\Language_Context;

class Test_Strings_Scanner extends SimpleTestCase {

	/**
	 * String row ids created by this test (cleaned in tearDown).
	 *
	 * @var int[]
	 */
	private $created_ids = array();

	/**
	 * Nav menu item post ids created manually (not factory-tracked).
	 *
	 * @var int[]
	 */
	private $nav_item_ids = array();

	/**
	 * Saved state of pre-existing rows mutated by tests (restored in tearDown).
	 *
	 * @var array
	 */
	private $saved_rows = array();

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_strings_table' ) ) {
			wptsall_create_strings_table();
		}
	}

	public function tearDown(): void {
		foreach ( $this->nav_item_ids as $post_id ) {
			// Remove every string row registered for this nav item, whether it
			// came from Site_String_Scanner or from other menu-related hooks.
			$prefix = 'item_' . (int) $post_id . '_';
			foreach ( String_Translation_Service::list( array( 'context' => 'menu', 'limit' => 500 ) ) as $r ) {
				if ( 0 === strpos( (string) $r['string_key'], $prefix ) ) {
					String_Translation_Service::delete( (int) $r['id'] );
				}
			}
			wp_delete_post( (int) $post_id, true );
		}
		$this->nav_item_ids = array();
		foreach ( $this->created_ids as $id ) {
			String_Translation_Service::delete( (int) $id );
		}
		$this->created_ids = array();
		foreach ( $this->saved_rows as $id => $state ) {
			// Restore the translation map / status the row had before the test.
			String_Translation_Service::set_translations( (int) $id, $state['translations'] );
		}
		$this->saved_rows = array();
		if ( class_exists( 'WPTSALL\Core\Language_Context' ) ) {
			Language_Context::set_language( '' );
			Language_Context::reset();
		}
		unset( $GLOBALS['wptsall_current_virtual_site'] );
		parent::tearDown();
	}

	/**
	 * Find a strings row by context + string_key.
	 *
	 * $object_id null matches rows with no object id; an int matches rows
	 * bound to that object id.
	 */
	private function find_row( $context, $key, $object_id = null ) {
		$rows = String_Translation_Service::list( array( 'context' => $context, 'limit' => 500 ) );
		foreach ( $rows as $r ) {
			if ( (string) $r['string_key'] !== (string) $key ) {
				continue;
			}
			if ( null === $object_id ) {
				if ( empty( $r['object_id'] ) ) {
					return $r;
				}
			} elseif ( (int) $r['object_id'] === (int) $object_id ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Track every string row registered for a nav menu item (scanner + any
	 * menu-related product hooks) so tearDown can remove them all.
	 */
	private function track_menu_item_rows( $item_id ) {
		$prefix = 'item_' . (int) $item_id . '_';
		foreach ( String_Translation_Service::list( array( 'context' => 'menu', 'limit' => 500 ) ) as $r ) {
			if ( 0 === strpos( (string) $r['string_key'], $prefix ) ) {
				$this->created_ids[] = (int) $r['id'];
			}
		}
		$this->created_ids = array_values( array_unique( $this->created_ids ) );
	}

	// ==================== Scanner ====================

	public function test_scan_all_registers_site_identity() {
		$before = array();
		foreach ( String_Translation_Service::list( array( 'limit' => 1000 ) ) as $r ) {
			$before[ (int) $r['id'] ] = true;
		}

		$result = Site_String_Scanner::scan_all();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'registered', $result );
		$this->assertArrayHasKey( 'contexts', $result );
		$this->assertGreaterThanOrEqual( 1, (int) $result['registered'] );
		$this->assertGreaterThanOrEqual( 1, (int) ( $result['contexts']['site_title'] ?? 0 ) );

		// The current site title must now be registered verbatim.
		$row = $this->find_row( 'site_title', 'blogname' );
		$this->assertNotNull( $row, 'site_title/blogname row should exist after scan_all()' );
		$this->assertSame( (string) get_option( 'blogname' ), $row['source_text'] );

		// Track newly created rows for cleanup (pre-existing rows keep their id).
		foreach ( String_Translation_Service::list( array( 'limit' => 1000 ) ) as $r ) {
			if ( ! isset( $before[ (int) $r['id'] ] ) ) {
				$this->created_ids[] = (int) $r['id'];
			}
		}
	}

	public function test_register_menu_items_creates_menu_context_rows() {
		$menu_id = self::factory()->term->create( array(
			'taxonomy' => 'nav_menu',
			'name'     => 'Unit Menu ' . uniqid(),
		) );

		$item_id = wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'       => 'Unit Menu Home',
			'menu-item-type'        => 'custom',
			'menu-item-url'         => 'https://example.com/unit-menu',
			'menu-item-attr-title'  => 'Unit Attr Title',
			'menu-item-description' => 'Unit Description Text',
			'menu-item-status'      => 'publish',
		) );
		$this->assertNotEmpty( $item_id );
		$this->assertGreaterThan( 0, (int) $item_id );
		$this->nav_item_ids[] = (int) $item_id;

		$count = Site_String_Scanner::register_menu_items( $menu_id );
		// title + attr_title + description are all translatable fields.
		$this->assertSame( 3, $count );

		$title_row = $this->find_row( 'menu', 'item_' . (int) $item_id . '_title', (int) $item_id );
		$this->assertNotNull( $title_row );
		$this->assertSame( 'Unit Menu Home', $title_row['source_text'] );
		$this->assertSame( (int) $item_id, (int) $title_row['object_id'] );

		$this->assertNotNull( $this->find_row( 'menu', 'item_' . (int) $item_id . '_attr_title', (int) $item_id ) );
		$this->assertNotNull( $this->find_row( 'menu', 'item_' . (int) $item_id . '_description', (int) $item_id ) );

		// Rows bound to this item are cleaned in tearDown via nav_item_ids.
		$this->track_menu_item_rows( (int) $item_id );
	}

	public function test_register_menu_items_rejects_invalid_menu_id() {
		$this->assertSame( 0, Site_String_Scanner::register_menu_items( 0 ) );
		$this->assertSame( 0, Site_String_Scanner::register_menu_items( -1 ) );
	}

	// ==================== Site identity translation (option filters) ====================

	public function test_site_identity_filters_pass_through_without_language() {
		Language_Context::reset();
		unset( $GLOBALS['wptsall_current_virtual_site'] );

		// No target language resolved -> value returned untouched.
		$this->assertSame( 'Plain Site', Site_Identity_Translation::filter_blogname( 'Plain Site' ) );
		$this->assertSame( 'Plain Tagline', Site_Identity_Translation::filter_blogdescription( 'Plain Tagline' ) );
	}

	public function test_site_identity_filters_apply_stored_translation() {
		if ( ! class_exists( 'WPTSALL\Core\Language_Context' ) ) {
			$this->markTestSkipped( 'Language_Context not available' );
		}
		// Make sure the option filters are wired (init() is idempotent).
		Site_Identity_Translation::init();

		$original_blogname = (string) get_option( 'blogname' );

		// Seed or reuse the site_title/blogname row, saving prior state.
		$saved = null;
		$row = $this->find_row( 'site_title', 'blogname' );
		$id = null;
		if ( is_array( $row ) ) {
			$id = (int) $row['id'];
			$map = json_decode( (string) ( $row['translations'] ?? '' ), true );
			$saved = is_array( $map ) ? $map : array();
			$this->saved_rows[ $id ] = array( 'translations' => $saved );
		}
		$id = String_Translation_Service::register( 'site_title', 'blogname', $original_blogname );
		$this->assertNotFalse( $id );
		if ( ! isset( $this->saved_rows[ (int) $id ] ) ) {
			$this->created_ids[] = (int) $id;
		}
		$this->assertTrue( String_Translation_Service::set_translations( $id, array( 'en_US' => 'Unit Site EN' ) ) );

		Language_Context::set_language( 'en_US' );

		// Direct filter call.
		$this->assertSame( 'Unit Site EN', Site_Identity_Translation::filter_blogname( $original_blogname ) );
		// The WP option pipeline itself is filtered (Layer B runtime path).
		$this->assertSame( 'Unit Site EN', (string) get_option( 'blogname' ) );

		// Tagline: no site_tagline/blogdescription translation stored -> passes through.
		$this->assertSame( 'Unit tagline', Site_Identity_Translation::filter_blogdescription( 'Unit tagline' ) );

		// Back on the source site the filter is a no-op again.
		Language_Context::set_language( '' );
		$this->assertSame( $original_blogname, Site_Identity_Translation::filter_blogname( $original_blogname ) );
	}

	// ==================== Module bootstrap wiring ====================

	public function test_strings_module_registers_hooks() {
		$this->assertTrue( class_exists( 'WPTSALL\Strings\Module' ) );
		$this->assertTrue( class_exists( 'WPTSALL\Strings\Admin\Strings_Page' ) );
		$this->assertTrue( class_exists( 'WPTSALL\Strings\Site_Identity_Translation' ) );
		$this->assertTrue( class_exists( 'WPTSALL\Strings\Services\Site_String_Scanner' ) );

		// Strings admin page hooks (registered by the module loader at plugin init).
		$this->assertNotFalse( has_action( 'admin_menu', array( '\WPTSALL\Strings\Admin\Strings_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_wptsall_strings_save', array( '\WPTSALL\Strings\Admin\Strings_Page', 'handle_save' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_wptsall_string_delete', array( '\WPTSALL\Strings\Admin\Strings_Page', 'handle_delete' ) ) );
	}
}

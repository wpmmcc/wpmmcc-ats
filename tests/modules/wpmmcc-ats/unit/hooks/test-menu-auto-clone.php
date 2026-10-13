<?php
/**
 * Menu auto-clone on create tests
 *
 * Covers the menu_auto_clone setting (default off) and the
 * wp_create_nav_menu hook that clones new menus for active virtual
 * sites via the existing Menu_Sync_Page::sync_menu() logic.
 *
 * @package WPTSALL
 * @since 2.1.0
 */

use WPTSALL\MenuTranslation\Menu_Mapping_Service;
use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Settings\Services\Settings_Service;

class Test_Menu_Auto_Clone extends WP_UnitTestCase {

	/**
	 * Test relation IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_relation_ids = array();

	/**
	 * Source menu term IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_menu_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		// Ensure the hook is registered and the mapping schema exists even if
		// the plugin bootstrap did not run init() in this test process.
		Menu_Mapping_Service::init();
		Menu_Mapping_Service::ensure_schema();
		// Tests start from the default (off) state.
		Settings_Service::update( array( 'menu_auto_clone' => false ) );
	}

	/**
	 * Clean up test data and restore the default setting.
	 */
	public function tearDown(): void {
		// Restore default settings state first so later tests are unaffected.
		Settings_Service::update( array( 'menu_auto_clone' => false ) );

		// Delete source menus; on_delete_nav_menu removes clones + mappings.
		foreach ( $this->test_menu_ids as $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id > 0 && wp_get_nav_menu_object( $menu_id ) ) {
				wp_delete_nav_menu( $menu_id );
			}
		}
		$this->test_menu_ids = array();

		// Clean up test relations (2026-09-12: cascade through the service so
		// relation_models/hooks/configs rows go with the relation — raw deletes
		// orphaned them; doctor-probes.php §8 now guards this class).
		foreach ( $this->test_relation_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}
		$this->test_relation_ids = array();

		parent::tearDown();
	}

	/**
	 * Helper: create a test relation with one virtual-site target.
	 *
	 * @return int|null Relation ID or null on failure.
	 */
	private function create_test_relation() {
		$suffix = uniqid( '', true );
		$vs_id  = 'v_test_' . $suffix;

		if ( class_exists( '\\WPTSALL\\Sites\\Services\\Virtual_Site_Service' ) ) {
			$created = \WPTSALL\Sites\Services\Virtual_Site_Service::create(
				array(
					'name'        => 'Menu AutoClone VS ' . $suffix,
					'path_prefix' => 'mac_' . substr( md5( $suffix ), 0, 8 ),
					'lang'        => 'zh_CN',
					'status'      => 'active',
				)
			);
			if ( is_array( $created ) ) {
				if ( ! empty( $created['site_id'] ) ) {
					$vs_id = (string) $created['site_id'];
				} elseif ( ! empty( $created['id'] ) ) {
					$vs_id = (string) $created['id'];
				} elseif ( ! empty( $created['site']['id'] ) ) {
					$vs_id = (string) $created['site']['id'];
				}
			}
		}

		$data = array(
			'template'       => 'wordpress-blog',
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => 'en',
			'target_sites'   => array(
				array(
					'id'   => $vs_id,
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		);

		$result = Site_Relation_Service::create_relation( $data );

		if ( ! empty( $result['success'] ) && ! empty( $result['relation_ids'] ) ) {
			$this->test_relation_ids = array_merge( $this->test_relation_ids, $result['relation_ids'] );
			return (int) $result['relation_ids'][0];
		}

		return null;
	}

	/**
	 * Helper: create a nav menu and remember it for cleanup.
	 *
	 * @param string $name Menu name.
	 * @return int|\WP_Error
	 */
	private function create_menu( $name ) {
		$menu_id = wp_create_nav_menu( $name );
		if ( ! is_wp_error( $menu_id ) ) {
			$this->test_menu_ids[] = (int) $menu_id;
		}
		return $menu_id;
	}

	/**
	 * The setting must default to off and stay off for falsy values.
	 */
	public function test_menu_auto_clone_defaults_to_off() {
		$this->assertFalse( Menu_Mapping_Service::menu_auto_clone_enabled(), 'Default must be off' );

		Settings_Service::update( array( 'menu_auto_clone' => 1 ) );
		$this->assertTrue( Menu_Mapping_Service::menu_auto_clone_enabled(), 'Enabled state must read back true' );

		Settings_Service::update( array( 'menu_auto_clone' => 0 ) );
		$this->assertFalse( Menu_Mapping_Service::menu_auto_clone_enabled(), 'Disabled state must read back false' );
	}

	/**
	 * With the toggle off (default), creating a nav menu must not clone it
	 * for the existing virtual site.
	 */
	public function test_create_menu_does_not_clone_when_disabled() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$menu_name = 'AutoClone Off Source ' . uniqid( '', true );
		$menu_id   = $this->create_menu( $menu_name );
		$this->assertFalse( is_wp_error( $menu_id ), 'Menu creation should succeed' );

		// The deterministic clone name must not exist.
		$this->assertFalse(
			wp_get_nav_menu_object( $menu_name . ' [zh_CN]' ),
			'No clone may be created while menu_auto_clone is off'
		);

		// And no mapping may exist for the source menu.
		$relation = Site_Relation_Service::get_relation( $relation_id );
		$vs_id    = (string) ( $relation['target_site_id'] ?? '' );
		$this->assertNotEmpty( $vs_id, 'Relation must carry a virtual target id' );
		$this->assertSame(
			0,
			Menu_Mapping_Service::get_target_menu( (int) $menu_id, $vs_id ),
			'No mapping may be created while menu_auto_clone is off'
		);
	}

	/**
	 * With the toggle on, creating a nav menu must clone it for the active
	 * virtual site via the existing sync logic (clone + mapping + marker).
	 */
	public function test_create_menu_clones_for_virtual_sites_when_enabled() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		Settings_Service::update( array( 'menu_auto_clone' => 1 ) );

		$menu_name = 'AutoClone On Source ' . uniqid( 'mac', false );
		$menu_id   = $this->create_menu( $menu_name );
		$this->assertFalse( is_wp_error( $menu_id ), is_wp_error( $menu_id ) ? $menu_id->get_error_message() : 'Menu creation should succeed' );

		$relation = Site_Relation_Service::get_relation( $relation_id );
		$vs_id    = (string) ( $relation['target_site_id'] ?? '' );
		$this->assertNotEmpty( $vs_id, 'Relation must carry a virtual target id' );

		// Prefer mapping lookup: wp_get_nav_menu_object( name ) is unreliable for
		// names that contain "[" (slug lookup runs first and misses).
		$clone_id = Menu_Mapping_Service::get_target_menu( (int) $menu_id, $vs_id );
		if ( $clone_id <= 0 && 0 === strpos( $vs_id, 'v_' ) ) {
			$clone_id = Menu_Mapping_Service::get_target_menu( (int) $menu_id, substr( $vs_id, 2 ) );
		} elseif ( $clone_id <= 0 ) {
			$clone_id = Menu_Mapping_Service::get_target_menu( (int) $menu_id, 'v_' . $vs_id );
		}
		$this->assertGreaterThan( 0, $clone_id, 'Enabled auto clone must create a mapped language clone' );

		$clone = get_term( (int) $clone_id, 'nav_menu' );
		$this->assertTrue( $clone && ! is_wp_error( $clone ), 'Clone term must exist' );

		// The clone must be marked as a virtual-site clone.
		$clone_vs = get_term_meta( (int) $clone_id, '_wptsall_virtual_site_id', true );
		$this->assertNotEmpty( $clone_vs, 'Clone must carry the virtual site marker' );

		// And the mapping must exist for the source menu.
		$this->assertSame(
			(int) $clone_id,
			Menu_Mapping_Service::get_target_menu( (int) $menu_id, (string) $clone_vs ),
			'Clone must be mapped to its source menu'
		);

		// Re-cloning guard: no grandchild mapping from the clone as source.
		$this->assertSame(
			0,
			Menu_Mapping_Service::get_target_menu( (int) $clone_id, (string) $clone_vs ),
			'Clones must not be re-cloned recursively'
		);
	}
}

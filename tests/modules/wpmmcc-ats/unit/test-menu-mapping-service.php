<?php
/**
 * Menu Mapping Service unit tests
 *
 * Covers the core source→target menu mapping CRUD in
 * WPTSALL\MenuTranslation\Menu_Mapping_Service: upsert/read, update of an
 * existing row, location-scoped lookup, pending marking on source update,
 * deletion cascade and the content hash used for change detection.
 *
 * @package WPTSALL
 * @since 2.1.0
 */

use WPTSALL\MenuTranslation\Menu_Mapping_Service;

class Test_Menu_Mapping_Service extends WP_UnitTestCase {

	/**
	 * Source / target nav menu term IDs created during tests.
	 *
	 * @var array
	 */
	private $test_menu_ids = array();

	/**
	 * Mapping row IDs created directly or via upsert.
	 *
	 * @var array
	 */
	private $test_mapping_ids = array();

	/**
	 * Virtual site ids used in test mappings (distinct per run).
	 *
	 * @var array
	 */
	private $test_vs_ids = array();

	public function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_create_nav_menu' ) ) {
			require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
		}
		// Ensure the schema exists even when the plugin bootstrap did not
		// run ensure_schema() in this process.
		Menu_Mapping_Service::ensure_schema();
	}

	public function tearDown(): void {
		global $wpdb;

		// Remove mapping rows created by tests (upsert tracks the row id
		// indirectly; delete by the vs ids used in this run).
		$table = wptsall_table( 'menu_mappings' );
		if ( ! empty( $this->test_vs_ids ) ) {
			$vs    = "'" . implode( "','", array_map( 'esc_sql', $this->test_vs_ids ) ) . "'";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$table} WHERE virtual_site_id IN ({$vs})" );
		}
		foreach ( $this->test_mapping_ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
		}

		// Delete menus; on_delete_nav_menu (when registered) removes any
		// remaining owned clones + mapping rows as part of the cascade.
		foreach ( $this->test_menu_ids as $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id > 0 && wp_get_nav_menu_object( $menu_id ) ) {
				wp_delete_nav_menu( $menu_id );
			}
		}
		$this->test_menu_ids   = array();
		$this->test_mapping_ids = array();
		$this->test_vs_ids     = array();

		parent::tearDown();
	}

	/**
	 * Helper: create a nav menu tracked for cleanup.
	 *
	 * @param string $name Menu name.
	 * @return int Term ID.
	 */
	private function create_menu( $name ) {
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			throw new Exception( 'Menu creation failed: ' . $menu_id->get_error_message() );
		}
		$this->test_menu_ids[] = (int) $menu_id;
		return (int) $menu_id;
	}

	/**
	 * Helper: unique virtual site id for this run.
	 *
	 * @return string
	 */
	private function new_vs_id() {
		$vs = 'v_mms_' . substr( md5( uniqid( '', true ) ), 0, 10 );
		$this->test_vs_ids[] = $vs;
		return $vs;
	}

	/**
	 * upsert_mapping() inserts a new source→target row that is readable
	 * through get_target_menu() only for the recorded virtual site.
	 */
	public function test_upsert_mapping_creates_and_reads_target() {
		$source = $this->create_menu( 'MMS Source ' . uniqid() );
		$target = $this->create_menu( 'MMS Target ' . uniqid() );
		$vs     = $this->new_vs_id();

		$this->assertTrue( Menu_Mapping_Service::upsert_mapping( $source, $target, $vs ) );

		$this->assertSame( $target, Menu_Mapping_Service::get_target_menu( $source, $vs ), 'Mapped target must be readable' );

		// Unknown virtual site / reverse direction must not resolve.
		$this->assertSame( 0, Menu_Mapping_Service::get_target_menu( $source, $vs . '_other' ), 'Unknown VS must not resolve' );
		$this->assertSame( 0, Menu_Mapping_Service::get_target_menu( $target, $vs ), 'Reverse direction must not resolve' );

		// Persisted row state: a fresh upsert stores sync_status synced.
		global $wpdb;
		$table = wptsall_table( 'menu_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT target_menu_term_id, virtual_site_id, sync_status, source_hash FROM %i WHERE source_menu_term_id = %d AND virtual_site_id = %s',
			$table,
			$source,
			$vs
		), ARRAY_A );
		$this->assertIsArray( $row, 'Mapping row must persist' );
		$this->assertEquals( $target, (int) $row['target_menu_term_id'] );
		$this->assertEquals( $vs, $row['virtual_site_id'] );
		$this->assertEquals( 'synced', $row['sync_status'] );
		$this->assertEquals( Menu_Mapping_Service::menu_content_hash( $source ), $row['source_hash'] );
	}

	/**
	 * A second upsert for the same source+VS must update the existing row,
	 * not create a duplicate.
	 */
	public function test_upsert_mapping_updates_existing_row() {
		$source  = $this->create_menu( 'MMS Upd Source ' . uniqid() );
		$target1 = $this->create_menu( 'MMS Upd T1 ' . uniqid() );
		$target2 = $this->create_menu( 'MMS Upd T2 ' . uniqid() );
		$vs      = $this->new_vs_id();

		$this->assertTrue( Menu_Mapping_Service::upsert_mapping( $source, $target1, $vs ) );
		$this->assertTrue( Menu_Mapping_Service::upsert_mapping( $source, $target2, $vs ) );

		global $wpdb;
		$table = wptsall_table( 'menu_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE source_menu_term_id = %d AND virtual_site_id = %s',
			$table,
			$source,
			$vs
		) );
		$this->assertSame( 1, $count, 'Second upsert must update, not duplicate' );
		$this->assertSame( $target2, Menu_Mapping_Service::get_target_menu( $source, $vs ), 'Read must return the latest target' );
	}

	/**
	 * get_target_menu_for_location() resolves location-scoped mappings and
	 * fails closed for unknown/empty locations.
	 */
	public function test_get_target_menu_for_location() {
		$source = $this->create_menu( 'MMS Loc Source ' . uniqid() );
		$target = $this->create_menu( 'MMS Loc Target ' . uniqid() );
		$vs     = $this->new_vs_id();

		$this->assertTrue( Menu_Mapping_Service::upsert_mapping( $source, $target, $vs, 'primary' ) );

		$this->assertSame( $target, Menu_Mapping_Service::get_target_menu_for_location( 'primary', $vs ) );
		$this->assertSame( 0, Menu_Mapping_Service::get_target_menu_for_location( 'secondary', $vs ), 'Unknown location must return 0' );
		$this->assertSame( 0, Menu_Mapping_Service::get_target_menu_for_location( 'primary', $vs . '_x' ), 'Unknown VS must return 0' );
		$this->assertSame( 0, Menu_Mapping_Service::get_target_menu_for_location( '', $vs ), 'Empty location must return 0' );
	}

	/**
	 * on_update_nav_menu() marks all mapped rows for the source menu as
	 * pending sync and refreshes the stored source hash.
	 */
	public function test_on_update_nav_menu_marks_pending() {
		$source = $this->create_menu( 'MMS Pend Source ' . uniqid() );
		$target = $this->create_menu( 'MMS Pend Target ' . uniqid() );
		$vs     = $this->new_vs_id();

		$this->assertTrue( Menu_Mapping_Service::upsert_mapping( $source, $target, $vs ) );

		// Add one menu item so the content hash actually changes.
		$item = wp_update_nav_menu_item( $source, 0, array(
			'menu-item-title'  => 'Home',
			'menu-item-url'    => home_url( '/' ),
			'menu-item-status' => 'publish',
		) );
		if ( is_wp_error( $item ) ) {
			$this->markTestSkipped( 'Could not create nav menu item: ' . $item->get_error_message() );
			return;
		}

		// Call the service directly (same as the registered hook callback).
		Menu_Mapping_Service::on_update_nav_menu( $source );

		global $wpdb;
		$table = wptsall_table( 'menu_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT sync_status, source_hash FROM %i WHERE source_menu_term_id = %d AND virtual_site_id = %s',
			$table,
			$source,
			$vs
		), ARRAY_A );
		$this->assertIsArray( $row, 'Mapping row must exist' );
		$this->assertEquals( 'pending', $row['sync_status'], 'Source change must mark mapping pending' );
		$this->assertEquals( Menu_Mapping_Service::menu_content_hash( $source ), $row['source_hash'], 'Stored hash must be refreshed' );
	}

	/**
	 * on_delete_nav_menu() cascades: owned target clone menus are deleted
	 * and all mapping rows for source or target are removed.
	 */
	public function test_on_delete_nav_menu_cascades() {
		$source = $this->create_menu( 'MMS Del Source ' . uniqid() );
		$target = $this->create_menu( 'MMS Del Target ' . uniqid() );
		$vs     = $this->new_vs_id();

		$this->assertTrue( Menu_Mapping_Service::upsert_mapping( $source, $target, $vs ) );
		$this->assertSame( $target, Menu_Mapping_Service::get_target_menu( $source, $vs ) );

		Menu_Mapping_Service::on_delete_nav_menu( $source );

		$this->assertFalse( wp_get_nav_menu_object( $target ), 'Owned target clone must be deleted' );
		$this->assertSame( 0, Menu_Mapping_Service::get_target_menu( $source, $vs ), 'Mapping must be removed' );

		// The target is no longer tracked; stop tearDown from double-deleting.
		$this->test_menu_ids = array_values( array_diff( $this->test_menu_ids, array( $target ) ) );

		// Deleting the target side also clears rows referencing it.
		$source2 = $this->create_menu( 'MMS Del S2 ' . uniqid() );
		$target2 = $this->create_menu( 'MMS Del T2 ' . uniqid() );
		$vs2     = $this->new_vs_id();
		$this->assertTrue( Menu_Mapping_Service::upsert_mapping( $source2, $target2, $vs2 ) );

		Menu_Mapping_Service::on_delete_nav_menu( $target2 );
		$this->assertSame( 0, Menu_Mapping_Service::get_target_menu( $source2, $vs2 ), 'Deleting the target must clear its mapping' );
		// Deleting a target must not cascade to its source menu.
		$this->assertNotFalse( wp_get_nav_menu_object( $source2 ), 'Source menu must survive target deletion' );
		$this->test_menu_ids = array_values( array_diff( $this->test_menu_ids, array( $target2 ) ) );
	}

	/**
	 * menu_content_hash() is stable for unchanged menus and changes when a
	 * menu item is added.
	 */
	public function test_menu_content_hash_tracks_content() {
		$source = $this->create_menu( 'MMS Hash ' . uniqid() );

		$before = Menu_Mapping_Service::menu_content_hash( $source );
		$this->assertNotSame( '', $before );
		// Stable across calls.
		$this->assertSame( $before, Menu_Mapping_Service::menu_content_hash( $source ) );

		$item = wp_update_nav_menu_item( $source, 0, array(
			'menu-item-title'  => 'Hash Item',
			'menu-item-url'    => home_url( '/hash' ),
			'menu-item-status' => 'publish',
		) );
		if ( is_wp_error( $item ) ) {
			$this->markTestSkipped( 'Could not create nav menu item: ' . $item->get_error_message() );
			return;
		}
		$after = Menu_Mapping_Service::menu_content_hash( $source );
		$this->assertNotSame( $before, $after, 'Hash must change when content changes' );
	}
}

<?php
/**
 * Sync Service Tests
 *
 * L1/L2 for WPTSALL\Sync\Services\Sync_Service (post meta propagation via
 * post_mappings):
 * - is_full_mode follows the sync_mode setting (new_only default, full opt-in)
 * - find_target_post_mappings_for resolves an active relation + mapping row
 *   (shape: target_post_id/target_site_id/relation_id) and filters by
 *   relation; unknown sources resolve empty
 * - on_meta_changed propagates whitelisted meta to the mapped target and
 *   ignores non-whitelisted / internal _wptsall_* keys
 * - the documented recursion guard suppresses a repeated same-trio write
 * - on_meta_deleted removes the target meta
 * - sync_all_mapped_meta bulk-syncs seeded source meta and reports a count
 *
 * catalog: WP-CLASS-Sync_Service
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Sync\Services\Sync_Service;
use WPTSALL\Settings\Services\Settings_Service;

class Test_Sync_Service extends SimpleTestCase {

	/**
	 * Rows created by this run.
	 *
	 * @var array{relations: int[], mappings: int[], posts: int[]}
	 */
	private $seeded = array( 'relations' => array(), 'mappings' => array(), 'posts' => array() );

	/**
	 * @var array<string, mixed>|null
	 */
	private $orig_settings = null;

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_post_mappings_table' ) ) {
			wptsall_create_post_mappings_table();
		}
		if ( class_exists( 'WPTSALL\Settings\Services\Settings_Service' ) ) {
			$this->orig_settings = Settings_Service::get_all();
		}
	}

	public function tearDown(): void {
		global $wpdb;
		foreach ( $this->seeded['mappings'] as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( wptsall_table( 'post_mappings' ), array( 'id' => (int) $id ), array( '%d' ) );
		}
		foreach ( $this->seeded['relations'] as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => (int) $id ), array( '%d' ) );
		}
		foreach ( $this->seeded['posts'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$this->seeded = array( 'relations' => array(), 'mappings' => array(), 'posts' => array() );
		if ( null !== $this->orig_settings && class_exists( 'WPTSALL\Settings\Services\Settings_Service' ) ) {
			update_option( Settings_Service::OPTION_KEY, $this->orig_settings, false );
		}
		$this->orig_settings = null;
		parent::tearDown();
	}

	/**
	 * Seed one active relation + source/target posts + a translation mapping.
	 *
	 * @return array{0:int,1:int,2:int,3:string} relation_id, source_id, target_id, target_site_id
	 */
	private function seed(): array {
		global $wpdb;
		$tag    = strtolower( uniqid() );
		$v_site = 'v_sync_' . $tag;
		$now    = current_time( 'mysql' );

		$ok = $wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'zz-sync-' . $tag,
				'target_site_id'   => $v_site,
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$this->assertTrue( false !== $ok, 'seed relation insert failed' );
		$relation_id = (int) $wpdb->insert_id;
		$this->seeded['relations'][] = $relation_id;

		$source_id = wp_insert_post(
			array( 'post_title' => 'zz sync src ' . $tag, 'post_status' => 'publish', 'post_type' => 'post' ),
			true
		);
		$this->assertTrue( ! is_wp_error( $source_id ) && $source_id > 0, 'seed source post failed' );
		$target_id = wp_insert_post(
			array( 'post_title' => 'zz sync tgt ' . $tag, 'post_status' => 'publish', 'post_type' => 'post' ),
			true
		);
		$this->assertTrue( ! is_wp_error( $target_id ) && $target_id > 0, 'seed target post failed' );
		$this->seeded['posts'][] = (int) $source_id;
		$this->seeded['posts'][] = (int) $target_id;

		$ok = $wpdb->insert(
			wptsall_table( 'post_mappings' ),
			array(
				'relation_id'       => $relation_id,
				'source_post_id'    => $source_id,
				'source_post_type'  => 'post',
				'source_site_id'    => get_current_blog_id(),
				'target_post_id'    => $target_id,
				'target_post_type'  => 'post',
				'target_site_id'    => $v_site,
				'relationship_type' => 'translation',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$this->assertTrue( false !== $ok, 'seed mapping insert failed' );
		$this->seeded['mappings'][] = (int) $wpdb->insert_id;

		return array( $relation_id, (int) $source_id, (int) $target_id, $v_site );
	}

	public function test_is_full_mode_follows_settings(): void {
		Settings_Service::update( array( 'sync_mode' => 'new_only' ) );
		$this->assertFalse( Sync_Service::is_full_mode(), 'new_only must not enable full sync' );
		Settings_Service::update( array( 'sync_mode' => 'full' ) );
		$this->assertTrue( Sync_Service::is_full_mode(), 'full must enable full sync' );
	}

	public function test_find_target_post_mappings_for_seeded_mapping(): void {
		list( $relation_id, $source_id, $target_id, $v_site ) = $this->seed();

		$rows = Sync_Service::find_target_post_mappings_for( $source_id );
		$mine = array_values( array_filter( $rows, static function ( $r ) use ( $relation_id ) {
			return (int) ( $r['relation_id'] ?? 0 ) === $relation_id;
		} ) );
		$this->assertCount( 1, $mine, 'seeded relation must resolve exactly one mapping' );
		$this->assertSame( $target_id, (int) $mine[0]['target_post_id'], 'mapping target_post_id' );
		$this->assertSame( $v_site, (string) $mine[0]['target_site_id'], 'mapping target_site_id' );

		$scoped = Sync_Service::find_target_post_mappings_for( $source_id, $relation_id );
		$this->assertCount( 1, $scoped, 'relation filter keeps the mapping' );

		$this->assertSame( array(), Sync_Service::find_target_post_mappings_for( $source_id + 999999 ), 'unknown source resolves empty' );
	}

	public function test_on_meta_changed_propagates_whitelisted_meta_only(): void {
		list( , $source_id, $target_id, ) = $this->seed();

		Sync_Service::on_meta_changed( 0, $source_id, '_wp_page_template', 'zz-tpl-a' );
		$this->assertSame( 'zz-tpl-a', get_post_meta( $target_id, '_wp_page_template', true ), 'whitelisted meta must propagate' );

		Sync_Service::on_meta_changed( 0, $source_id, 'zz_no_sync_key', 'zz-x' );
		$this->assertSame( '', get_post_meta( $target_id, 'zz_no_sync_key', true ), 'non-whitelisted meta must not propagate' );

		Sync_Service::on_meta_changed( 0, $source_id, '_wptsall_internal', 'zz-y' );
		$this->assertSame( '', get_post_meta( $target_id, '_wptsall_internal', true ), 'internal _wptsall_* meta must not propagate' );
	}

	public function test_recursion_guard_suppresses_repeated_same_trio(): void {
		list( , $source_id, $target_id, ) = $this->seed();

		Sync_Service::on_meta_changed( 0, $source_id, '_thumbnail_id', '11' );
		$this->assertSame( '11', get_post_meta( $target_id, '_thumbnail_id', true ), 'first propagation applies' );

		// Same source/target/key trio again in the same process: the guard
		// (documented: "skip if we're about to write the same meta back")
		// suppresses the second write.
		Sync_Service::on_meta_changed( 0, $source_id, '_thumbnail_id', '22' );
		$this->assertSame( '11', get_post_meta( $target_id, '_thumbnail_id', true ), 'recursion guard must keep the first value' );
	}

	public function test_on_meta_deleted_removes_target_meta(): void {
		list( , $source_id, $target_id, ) = $this->seed();

		update_post_meta( $source_id, '_menu_item_url', 'https://example.com/zz' );
		update_post_meta( $target_id, '_menu_item_url', 'https://example.com/zz' );
		Sync_Service::on_meta_deleted( 0, $source_id, '_menu_item_url', '' );
		$this->assertSame( '', get_post_meta( $target_id, '_menu_item_url', true ), 'deleted source meta must remove the target meta' );
	}

	public function test_sync_all_mapped_meta_bulk(): void {
		list( , $source_id, $target_id, ) = $this->seed();

		update_post_meta( $source_id, '_wp_page_template', 'zz-bulk-tpl' );
		$count = Sync_Service::sync_all_mapped_meta();
		$this->assertGreaterThanOrEqual( 1, $count, 'bulk sync must report at least one applied write' );
		$this->assertSame( 'zz-bulk-tpl', get_post_meta( $target_id, '_wp_page_template', true ), 'bulk sync must apply the source meta to the target' );
	}
}

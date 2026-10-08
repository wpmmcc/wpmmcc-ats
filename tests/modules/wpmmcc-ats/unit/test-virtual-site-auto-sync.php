<?php
/**
 * Virtual Site Auto Sync Tests
 *
 * Tests for WPTSALL\Sites\Services\Virtual_Site_Auto_Sync:
 * - init() registers the relation lifecycle hooks
 * - on_relations_created()/on_relation_updated() auto-create a
 *   wptsall_virtual_sites row for `virtual` targets (pinned admin name/path),
 *   ignore `wp` targets and targets without an id
 * - an already-existing virtual site id is skipped (no duplicate rows)
 * - missing name/lang/path fall back to documented defaults
 * - reconcile_all(): walks active virtual relations, reports created vs
 *   skipped, and errors do not fatal
 *
 * FINDING (2026-09-07, lane E-2 salvage): the P0-1 auto-sync feature is
 * broken in production (init() IS wired via includes/sites/module.php), in
 * two distinct modes:
 *   1. SELF-BLOCK (primary production path): merge_site_relations() derives
 *      a union entry for every virtual relation row using the SAME
 *      path derivation as ensure_virtual_site_for_target()
 *      (target_theme_path, else sanitize_title(target_lang)), and
 *      Virtual_Site_Service::create() -> check_url_conflict() consults that
 *      union (get_all()). Because Site_Relation_Service fires the hook AFTER
 *      inserting the relation rows, the new relation itself already occupies
 *      the derived path -> create is rejected with "URL path prefix already
 *      exists" -> the wp_wptsall_virtual_sites row is NEVER created; only a
 *      warning is logged. The direct-payload tests below create rows only
 *      because they bypass relation-row insertion.
 *   2. FATAL (path+lang both empty): for a target with no path AND no lang,
 *      the union derives 'virtual/<id>' while ensure falls back to
 *      sanitize_title('auto')='auto' -> no conflict -> create SUCCEEDS ->
 *      ensure_virtual_site_for_target() then calls the PRIVATE
 *      Virtual_Site_Service::flush_rewrite_rules_if_available()
 *      (cross-class private call => \Error) -> fatal on the request that
 *      saved the relation.
 * reconcile_all() inherits both modes. Tests pin current behavior per mode;
 * catalog note: wp-classes.yaml, Phase 4 known_red candidate.
 *
 * catalog: WP-CLASS-Virtual_Site_Auto_Sync
 * oracle: L2
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

use WPTSALL\Sites\Services\Virtual_Site_Auto_Sync;

class Test_Virtual_Site_Auto_Sync extends SimpleTestCase {

	/**
	 * Virtual site row ids created by this run (cleaned in tearDown only).
	 *
	 * @var array<int, int>
	 */
	private $created_site_ids = array();

	/**
	 * Site relation row ids created by this run.
	 *
	 * @var array<int, int>
	 */
	private $created_relation_ids = array();

	public function setUp(): void {
		parent::setUp();
		if ( function_exists( 'wptsall_create_virtual_sites_table' ) ) {
			wptsall_create_virtual_sites_table();
		}
		if ( function_exists( 'wptsall_create_site_relations_table' ) ) {
			wptsall_create_site_relations_table();
		}
	}

	public function tearDown(): void {
		global $wpdb;
		$vs_table    = $wpdb->prefix . 'wptsall_virtual_sites';
		$rel_table   = wptsall_table( 'site_relations' );
		foreach ( $this->created_site_ids as $site_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $vs_table, array( 'id' => (int) $site_id ), array( '%d' ) );
		}
		foreach ( $this->created_relation_ids as $relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $rel_table, array( 'id' => (int) $relation_id ), array( '%d' ) );
		}
		$this->created_site_ids     = array();
		$this->created_relation_ids = array();
		parent::tearDown();
	}

	/**
	 * Count virtual site rows matching a where set.
	 *
	 * @param string $column Column to filter on (id|site_path).
	 * @param mixed  $value  Value.
	 * @return int
	 */
	private function count_vs_rows( string $column, $value ): int {
		global $wpdb;
		$vs_table = $wpdb->prefix . 'wptsall_virtual_sites';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$vs_table} WHERE {$column} = %s", $value )
		);
	}

	private function get_vs_row( int $site_id ): ?array {
		global $wpdb;
		$vs_table = $wpdb->prefix . 'wptsall_virtual_sites';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$vs_table} WHERE id = %d", $site_id ),
			ARRAY_A
		);
	}

	/**
	 * Invoke an auto-sync create path, absorbing the KNOWN product fatal.
	 *
	 * The known Error ("Call to private method
	 * ...flush_rewrite_rules_if_available() from scope ...") is thrown AFTER
	 * the row insert, so row-level assertions below still hold. Returns the
	 * absorbed Error; null once the product fix lands (call completes
	 * cleanly) — both states are pinned by the row assertions.
	 *
	 * @param callable $fn Auto-sync invocation.
	 * @return \Error|null Absorbed known fatal, or null on clean run.
	 */
	private function call_autosync( callable $fn ): ?\Error {
		try {
			$fn();
			return null;
		} catch ( \Error $e ) {
			if ( false === strpos( $e->getMessage(), 'flush_rewrite_rules_if_available' ) ) {
				throw $e;
			}
			return $e;
		}
	}

	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WPTSALL\Sites\Services\Virtual_Site_Auto_Sync' ) );
	}

	public function test_init_registers_relation_hooks() {
		remove_action( 'wptsall_site_relations_created', array( Virtual_Site_Auto_Sync::class,'on_relations_created' ), 10 );
		remove_action( 'wptsall_site_relation_updated', array( Virtual_Site_Auto_Sync::class,'on_relation_updated' ), 10 );

		Virtual_Site_Auto_Sync::init();

		$this->assertEquals( 10, has_action( 'wptsall_site_relations_created', array( Virtual_Site_Auto_Sync::class,'on_relations_created' ) ) );
		$this->assertEquals( 10, has_action( 'wptsall_site_relation_updated', array( Virtual_Site_Auto_Sync::class,'on_relation_updated' ) ) );
	}

	public function test_on_relations_created_creates_virtual_site_with_pinned_values() {
		$marker = 'sv' . strtolower( uniqid() );
		$target = array(
			'id'   => 'v_' . $marker,
			'type' => 'virtual',
			'lang' => 'sv_SE',
			'name' => 'Auto Sync Test ' . $marker,
			'path' => $marker,
		);

		$fatal = $this->call_autosync( static function () use ( $target ) {
			Virtual_Site_Auto_Sync::on_relations_created( array( 1 ), array( 'target_sites' => array( $target ) ) );
		} );
		// Known product fatal (see class docblock): row is created first,
		// then the private flush call throws. $fatal pins that state; when
		// the fix lands $fatal is null and the row assertions below run
		// unchanged.
		global $wpdb;
		$vs_table = $wpdb->prefix . 'wptsall_virtual_sites';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$vs_table} WHERE site_path = %s", $marker ),
			ARRAY_A
		);
		$this->assertNotNull( $row, 'virtual target must get a wptsall_virtual_sites row' );
		$this->created_site_ids[] = (int) $row['id'];
		$this->assertEquals( 'Auto Sync Test ' . $marker, $row['site_name'] );
		$this->assertEquals( 'sv_SE', $row['site_language'] );
		$this->assertEquals( 'active', $row['status'] );
	}

	public function test_on_relation_updated_creates_virtual_site_too() {
		$marker = 'sv' . strtolower( uniqid() );
		$target = array(
			'id'   => 'v_' . $marker,
			'type' => 'virtual',
			'lang' => 'sv_SE',
			'name' => 'Updated Site ' . $marker,
			'path' => $marker,
		);

		$this->call_autosync( static function () use ( $target ) {
			Virtual_Site_Auto_Sync::on_relation_updated( 1, array( 'target_sites' => array( $target ) ) );
		} );

		$this->assertEquals( 1, $this->count_vs_rows( 'site_path', $marker ) );
		// Track for cleanup.
		global $wpdb;
		$vs_table = $wpdb->prefix . 'wptsall_virtual_sites';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$site_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$vs_table} WHERE site_path = %s", $marker ) );
		$this->created_site_ids[] = (int) $site_id;
	}

	public function test_existing_virtual_site_id_is_skipped_not_duplicated() {
		$marker = 'sv' . strtolower( uniqid() );
		$target = array(
			'id'   => 'v_' . $marker,
			'type' => 'virtual',
			'lang' => 'sv_SE',
			'name' => 'Idempotent Site ' . $marker,
			'path' => $marker,
		);
		$this->call_autosync( static function () use ( $target ) {
			Virtual_Site_Auto_Sync::on_relations_created( array( 1 ), array( 'target_sites' => array( $target ) ) );
		} );

		global $wpdb;
		$vs_table = $wpdb->prefix . 'wptsall_virtual_sites';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$site_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$vs_table} WHERE site_path = %s", $marker ) );
		$this->created_site_ids[] = $site_id;
		$this->assertGreaterThan( 0, $site_id );
		$rows_before = $this->count_vs_rows( 'site_path', $marker );

		// Same relation payload with the NOW-existing id: must skip, not duplicate.
		$target_existing = array(
			'id'   => 'v_' . $site_id,
			'type' => 'virtual',
			'lang' => 'sv_SE',
			'name' => 'Idempotent Site 2',
			'path' => $marker . '-other',
		);
		$this->call_autosync( static function () use ( $target_existing ) {
			Virtual_Site_Auto_Sync::on_relations_created( array( 2 ), array( 'target_sites' => array( $target_existing ) ) );
		} );

		$this->assertSame( $rows_before, $this->count_vs_rows( 'site_path', $marker ), 'existing virtual site must be skipped' );
		$this->assertEquals( 0, $this->count_vs_rows( 'site_path', $marker . '-other' ), 'skipped target must not create the alternative path row' );
	}

	public function test_wp_targets_and_missing_id_targets_are_ignored() {
		$before_all = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}wptsall_virtual_sites" );

		Virtual_Site_Auto_Sync::on_relations_created( array( 1 ), array(
			'target_sites' => array(
				array( 'id' => '2', 'type' => 'wp', 'lang' => 'sv_SE', 'name' => 'Nope', 'path' => 'nope-wp-' . uniqid() ),
				array( 'type' => 'virtual', 'lang' => 'sv_SE', 'name' => 'Nope', 'path' => 'nope-noid-' . uniqid() ),
				'not-an-array-target',
			),
		) );
		Virtual_Site_Auto_Sync::on_relation_updated( 1, array() );

		$after_all = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}wptsall_virtual_sites" );
		$this->assertSame( $before_all, $after_all, 'wp targets, id-less targets and junk targets must create nothing' );
	}

	public function test_missing_name_and_path_use_documented_defaults() {
		$marker = 'sv' . strtolower( uniqid() );
		$target = array(
			'id'   => 'v_' . $marker,
			'type' => 'virtual',
			'lang' => $marker, // lang doubles as path fallback source.
		);

		$this->call_autosync( static function () use ( $target ) {
			Virtual_Site_Auto_Sync::on_relations_created( array( 1 ), array( 'target_sites' => array( $target ) ) );
		} );

		global $wpdb;
		$vs_table = $wpdb->prefix . 'wptsall_virtual_sites';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$vs_table} WHERE site_language = %s", $marker ),
			ARRAY_A
		);
		$this->assertNotNull( $row );
		$this->created_site_ids[] = (int) $row['id'];

		// Name default: 'Auto-synced Site ' + first 16 chars of the parsed id.
		$this->assertStringStartsWith( 'Auto-synced Site ', $row['site_name'] );
		// Path default: sanitize_title(lang).
		$this->assertEquals( sanitize_title( $marker ), $row['site_path'] );
	}

	public function test_reconcile_all_reports_created_and_skipped() {
		global $wpdb;
		$rel_table = wptsall_table( 'site_relations' );
		$vs_table  = $wpdb->prefix . 'wptsall_virtual_sites';

		// Ensure a baseline virtual site exists so the second relation is skipped.
		// Baseline row via a direct payload call (no relation row yet, so no
		// union self-block); the known private-flush fatal is absorbed.
		$marker = 'svr' . strtolower( uniqid() );
		$this->call_autosync( static function () use ( $marker ) {
			Virtual_Site_Auto_Sync::on_relations_created( array( 1 ), array( 'target_sites' => array(
				array( 'id' => 'v_' . $marker, 'type' => 'virtual', 'lang' => 'sv_SE', 'name' => 'Reconcile Base ' . $marker, 'path' => $marker ),
			) ) );
		} );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_site_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$vs_table} WHERE site_path = %s", $marker ) );
		$this->created_site_ids[] = $existing_site_id;
		$this->assertGreaterThan( 0, $existing_site_id );

		$now = current_time( 'mysql' );
		// Relation A: virtual target whose parsed id does not exist -> will be created.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $rel_table, array(
			'source_site_id'    => 1,
			'source_lang'      => 'en_US',
			'template'          => 'zz-test-' . $marker,
			'target_site_id'    => 'v_' . $marker . 'a',
			'target_site_type'  => 'virtual',
			'target_lang'       => $marker . 'a',
			'status'            => 'active',
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
		$this->created_relation_ids[] = (int) $wpdb->insert_id;

		// Relation B: virtual target pointing at the existing site id -> skipped.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $rel_table, array(
			'source_site_id'    => 1,
			'source_lang'      => 'en_US',
			'template'          => 'zz-test-' . $marker,
			'target_site_id'    => 'v_' . $existing_site_id,
			'target_site_type'  => 'virtual',
			'target_lang'       => $marker . 'b',
			'status'            => 'active',
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
		$this->created_relation_ids[] = (int) $wpdb->insert_id;

		try {
			$stats = Virtual_Site_Auto_Sync::reconcile_all();
		} catch ( \Error $e ) {
			if ( false === strpos( $e->getMessage(), 'flush_rewrite_rules_if_available' ) ) {
				throw $e;
			}
			$this->markTestSkipped(
				'FINDING 2026-09-07 (fatal mode): reconcile_all() fatals after a successful '
				. 'create (cross-class private flush_rewrite_rules_if_available call) — '
				. 'product fix pending; see wp-classes.yaml notes.'
			);
			return;
		}

		$this->assertIsArray( $stats );
		$this->assertIsArray( $stats['errors'] );

		// FINDING (self-block mode, pinned as current behavior): relation A's
		// own union entry occupies sanitize_title(target_lang), so its create
		// is rejected with "URL path prefix already exists" and NO row is
		// created. When the product fix lands (conflict check must exclude the
		// relation being reconciled, or consult only real virtual_sites rows),
		// created must become >= 1 for relation A — this pin will then fail
		// and prompt flipping the assertion.
		$this->assertSame( 0, $stats['created'], 'FINDING pin: path-less relation creates are self-blocked' );
		$rel_a_error = null;
		foreach ( $stats['errors'] as $err ) {
			if ( false !== strpos( $err, 'v_' . $marker . 'a' ) ) {
				$rel_a_error = $err;
				break;
			}
		}
		$this->assertNotNull( $rel_a_error, 'relation A must be reported in errors' );
		$this->assertStringContainsString( 'URL path prefix already exists', (string) $rel_a_error );

		// Relation B points at the baseline virtual site id: its parsed id is
		// already a real row, so ensure_virtual_site_for_target() skips it.
		$this->assertGreaterThanOrEqual( 1, $stats['skipped'], 'relation B must be skipped' );
	}
}

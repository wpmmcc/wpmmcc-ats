<?php
/**
 * Content Change Dispatcher Tests
 *
 * Guard behavior for WPTSALL\Hooks\Content_Change_Dispatcher::on_option_changed():
 * on a brand-new subsite, WordPress fires option updates during role population
 * (wp_initialize_site -> populate_roles) BEFORE Plugin_Lifecycle::handle_new_site()
 * provisions the subsite's table family. The dispatcher must skip its relations
 * query when the site_relations table does not exist yet, instead of logging a
 * database error for every option write (surfaced by the public compat-matrix
 * multisite lane, 2026-09-11).
 *
 * Fixture strategy (2026-09-12 fixture-DROP convergence): this file used to
 * DROP the SHARED Lab site_relations table and rebuild it in tearDown — any
 * failure path that skipped tearDown left the rest of the suite running
 * without the table (the dbDelta semicolon incident class; see
 * tasks/test/VERIFICATION-AND-DISPOSITION-20260912.md §6.1-C). It now
 * redirects the table NAME via the wptsall_table_names filter to a one-shot
 * scratch table and drops only that; the shared table is never touched, and
 * each test cleans the filter in a finally block so an assertion failure
 * cannot leak the redirect into later files (this runner skips tearDown on
 * assertion throw).
 *
 * catalog: WP-HOOK-wptsall-table-names
 * oracle: L1
 *
 * @package WPTSALL
 * @since 2.1.4
 */

use WPTSALL\Hooks\Content_Change_Dispatcher;

class Test_Content_Change_Dispatcher extends WP_UnitTestCase {

	/**
	 * One-shot scratch suffix for the redirected relations table.
	 */
	const SCRATCH_SUFFIX = 'wptsall_scratch_relations';

	/**
	 * Redirect site_relations to the scratch table (wptsall_table_names
	 * filter callback; also proves the extension contract of that hook).
	 *
	 * @param array $tables Table key => suffix map.
	 * @return array
	 */
	public function scratch_table_names( $tables ) {
		$tables['site_relations'] = self::SCRATCH_SUFFIX;
		return $tables;
	}

	/**
	 * The redirected (scratch) table name; requires the filter active.
	 *
	 * @return string
	 */
	private function scratch_table() {
		return wptsall_table( 'site_relations' );
	}

	/**
	 * Memoized-existence cache key for the scratch table.
	 *
	 * @return string
	 */
	private function relations_cache_key() {
		return 'wptsall_schema_table_exists_' . md5( $this->scratch_table() );
	}

	/**
	 * Drop the scratch table (never the shared one) and un-prime its memo.
	 *
	 * Fail-closed: refuses to drop anything that is not the scratch table —
	 * a miswired filter order must never be able to drop the shared Lab
	 * table (this exact hazard materialized once: tearDown ran after the
	 * finally block had already removed the redirect filter, so the
	 * "belt and braces" drop resolved the REAL table name).
	 */
	private function drop_scratch() {
		global $wpdb;
		$table = $this->scratch_table();
		if ( false === strpos( $table, self::SCRATCH_SUFFIX ) ) {
			throw new Exception( "scratch fixture miswired: refusing to drop non-scratch table {$table}" );
		}
		wp_cache_delete( $this->relations_cache_key(), 'wptsall_schema' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
		wp_cache_delete( $this->relations_cache_key(), 'wptsall_schema' );
	}

	/**
	 * Full teardown of the fixture: scratch table dropped (with the name
	 * redirect guaranteed active), then the filter removed. Runs from
	 * finally inside every test AND from tearDown (belt and braces — this
	 * runner skips tearDown when an assertion throws).
	 */
	private function teardown_scratch_fixture() {
		// The finally-path may run after the filter was already removed
		// (e.g. tearDown after finally); re-arm the redirect for the drop.
		remove_filter( 'wptsall_table_names', array( $this, 'scratch_table_names' ) );
		add_filter( 'wptsall_table_names', array( $this, 'scratch_table_names' ) );
		try {
			$this->drop_scratch();
		} finally {
			remove_filter( 'wptsall_table_names', array( $this, 'scratch_table_names' ) );
		}
	}

	public function setUp(): void {
		parent::setUp();
		remove_filter( 'wptsall_table_names', array( $this, 'scratch_table_names' ) );
		add_filter( 'wptsall_table_names', array( $this, 'scratch_table_names' ) );
		$this->drop_scratch();
	}

	public function tearDown(): void {
		$this->teardown_scratch_fixture();
		parent::tearDown();
	}

	/**
	 * Control pair (proves the test is not vacuous): with the existence
	 * memo claiming the table exists (cache '1') while the physical scratch
	 * table is dropped, the listener DOES query and records a database error.
	 */
	public function test_option_change_queries_when_guard_passes() {
		global $wpdb;

		try {
			$this->drop_scratch();
			wp_cache_set( $this->relations_cache_key(), '1', 'wptsall_schema' );

			$wpdb->last_error = '';
			Content_Change_Dispatcher::on_option_changed( 'blogname', null, 'control' );

			$this->assertNotSame( '', $wpdb->last_error, 'control: the listener must reach its relations query when the guard passes (otherwise this test file proves nothing)' );
		} finally {
			$this->teardown_scratch_fixture();
		}
	}

	/**
	 * The guard: with the existence memo reporting the table as missing
	 * (fresh-subsite state), the listener must skip the query entirely and
	 * leave the database error state clean.
	 */
	public function test_option_change_guard_skips_missing_relations_table() {
		global $wpdb;

		try {
			$this->drop_scratch();
			$this->assertFalse( wptsall_schema_table_exists( $this->scratch_table() ), 'precondition: memoized check must report the dropped scratch table as missing' );

			$wpdb->last_error = '';
			Content_Change_Dispatcher::on_option_changed( 'blogname', null, 'fresh-subsite' );

			$this->assertSame( '', $wpdb->last_error, 'guard: the listener must not query the missing relations table during fresh-subsite role population' );
		} finally {
			$this->teardown_scratch_fixture();
		}
	}

	/**
	 * One-shot scratch suffix for the redirected outbox table (claim FIFO
	 * fixture; same redirect-and-drop-only-the-scratch rule as above).
	 */
	const SCRATCH_OUTBOX_SUFFIX = 'wptsall_scratch_outbox_fifo';

	/**
	 * Redirect content_change_outbox to the scratch table (filter callback).
	 *
	 * @param array $tables Table key => suffix map.
	 * @return array
	 */
	public function scratch_outbox_table_names( $tables ) {
		$tables['content_change_outbox'] = self::SCRATCH_OUTBOX_SUFFIX;
		return $tables;
	}

	/**
	 * Fail-closed drop of the scratch outbox table (never the shared one).
	 */
	private function drop_outbox_scratch() {
		global $wpdb;
		$table = wptsall_table( 'content_change_outbox' );
		if ( false === strpos( $table, self::SCRATCH_OUTBOX_SUFFIX ) ) {
			throw new Exception( "scratch outbox fixture miswired: refusing to drop non-scratch table {$table}" );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
	}

	/**
	 * 批D (X-12①): claim_outbox must lease the FRONT of the queue — the
	 * oldest pending ids first (FIFO). The pre-batch-D ORDER BY id DESC
	 * returned the NEWEST rows, so a backlog larger than the scan window
	 * kept the oldest rows permanently unreachable while fresh events kept
	 * the worker busy claiming newer ones (the observed 423%-CPU/
	 * zero-progress twin of the client-side spin in X-12④).
	 */
	public function test_claim_outbox_is_fifo_oldest_ids_first() {
		global $wpdb;

		add_filter( 'wptsall_table_names', array( $this, 'scratch_outbox_table_names' ) );
		try {
			$this->drop_outbox_scratch();
			// Create the scratch table through the production DDL (the
			// redirected name picks up the real columns and the
			// claim_queue index).
			wptsall_create_content_change_outbox_table();
			$table = wptsall_table( 'content_change_outbox' );
			$this->assertStringContainsString( self::SCRATCH_OUTBOX_SUFFIX, $table, 'fixture must point at the scratch outbox, never the shared Lab table' );

			// Seed three pending rows, all claimable now; auto-increment
			// makes insertion order == id order (oldest first).
			$now = current_time( 'mysql', true );
			foreach ( array( 'old', 'mid', 'new' ) as $i => $tag ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$table,
					array(
						'event_key'    => __CLASS__ . '-fifo-' . $tag,
						'source_type'  => 'post',
						'source_id'    => 100 + $i,
						'event_name'   => 'post_updated',
						'status'       => 'pending',
						'available_at' => $now,
						'created_at'   => $now,
						'updated_at'   => $now,
					)
				);
				$this->assertEmpty( $wpdb->last_error, "seed row {$tag}: {$wpdb->last_error}" );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_ids = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC" ) );
			$this->assertCount( 3, $all_ids, 'precondition: three seeded rows' );

			// Claim a batch of two: the leased rows must be the two oldest
			// ids, in ascending order.
			$claimed = Content_Change_Dispatcher::claim_outbox( 2, 900, 0, 'fifo-test' );
			$this->assertCount( 2, $claimed, 'claim must lease exactly the requested batch' );
			$claimed_ids = array_map( 'intval', wp_list_pluck( $claimed, 'id' ) );
			$this->assertSame(
				array_slice( $all_ids, 0, 2 ),
				$claimed_ids,
				'claim must lease the FRONT of the queue (oldest ids, ascending) — the pre-batch-D id DESC ordering starved historical backlog outside the scan window'
			);

			// The CAS claim marks them processing with attempts bumped.
			foreach ( $claimed as $row ) {
				$this->assertSame( 'processing', $row['status'] );
				$this->assertSame( 1, (int) $row['attempts'] );
			}

			// The third (newest) row stays pending and untouched.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$remaining = $wpdb->get_row( $wpdb->prepare( "SELECT status, attempts FROM {$table} WHERE id = %d", $all_ids[2] ), ARRAY_A );
			$this->assertSame( 'pending', $remaining['status'] );
			$this->assertSame( 0, (int) $remaining['attempts'] );
		} finally {
			remove_filter( 'wptsall_table_names', array( $this, 'scratch_outbox_table_names' ) );
			// Re-arm the redirect for the drop, then remove it (same
			// belt-and-braces ordering as teardown_scratch_fixture).
			add_filter( 'wptsall_table_names', array( $this, 'scratch_outbox_table_names' ) );
			try {
				$this->drop_outbox_scratch();
			} finally {
				remove_filter( 'wptsall_table_names', array( $this, 'scratch_outbox_table_names' ) );
			}
		}
	}
}

<?php
/**
 * Outbox bounded retry and dead-letter (ATS-01)
 *
 * content_change_outbox used to have no terminal failure state. claim_outbox()
 * only bumped `attempts`, fail_outbox() always returned the row to `pending`
 * (available_at pushed out by at most 30 minutes) and nothing ever compared
 * attempts to a limit. A row that can never succeed (unsupported source, a
 * poison payload, a client that crashes on it) was re-leased forever, kept
 * waking the events/wait long-poll, and - because the claim window is FIFO -
 * sat at the front of the queue. The e2e lanes carried hand-written
 * `DELETE FROM ..._content_change_outbox` sweeps to work around exactly this.
 *
 * Contract pinned here (outbox rows of the D3 transition table):
 *
 *   pending    --claim-->                     processing (attempts + 1)
 *   processing --complete-->                  completed
 *   processing --fail, attempts <  cap-->     pending (+ server backoff when the caller gives no hint)
 *   processing --fail, attempts >= cap-->     dead
 *   processing --abandon (permanent error)--> dead, whatever attempts says
 *   processing with an expired lease and attempts >= cap, at the next claim --> dead (poison pill)
 *   dead is absorbing: never claimable, never completed or failed again, and its
 *   task projection is `failed` (so an older task-pull worker cannot run it again).
 *
 * Isolation notes: every test builds its own relation (template prefix below)
 * and claims relation-scoped, so leftovers of other suites in the shared lab
 * outbox cannot enter the FIFO window. The runner skips tearDown when an
 * assertion throws, so setUp also cleans and every test cleans in finally.
 *
 * catalog: WP-HOOK-wptsall-outbox-max-attempts
 * oracle: L1
 *
 * @package WPTSALL
 * @since 2.3.0
 */

use WPTSALL\Hooks\Content_Change_Dispatcher;

class Test_Outbox_Dead_Letter extends WP_UnitTestCase {

	const DEVICE = 'test-dead-letter-device';

	const TEMPLATE_PREFIX = 'test-dead-letter-';

	/**
	 * Scratch outbox name for the unscoped-peek test (redirect-and-drop-only-
	 * the-scratch, same rule as test-content-change-dispatcher.php).
	 */
	const SCRATCH_OUTBOX_SUFFIX = 'wptsall_scratch_outbox_dead_letter';

	/**
	 * Resolve the active plugin root.
	 *
	 * @return string
	 */
	private static function plugin_root() {
		if ( defined( 'WPTSALL_PATH' ) && is_dir( WPTSALL_PATH ) ) {
			return trailingslashit( WPTSALL_PATH );
		}
		if ( defined( 'WPTSALL_FILE' ) && file_exists( WPTSALL_FILE ) ) {
			return trailingslashit( dirname( WPTSALL_FILE ) );
		}
		foreach ( array( 'wpmmcc-ats', 'wptsall-pro' ) as $plugin_dir ) {
			$candidate = trailingslashit( WP_PLUGIN_DIR ) . $plugin_dir . '/';
			if ( is_dir( $candidate . 'includes' ) ) {
				return $candidate;
			}
		}
		return trailingslashit( WP_PLUGIN_DIR ) . 'wpmmcc-ats/';
	}

	/**
	 * Load the modules the fixture touches (localhost does not auto-load the
	 * tasks module) and make sure both tables exist.
	 */
	private static function ensure_modules_loaded() {
		$root = self::plugin_root();
		if ( ! function_exists( 'wptsall_ensure_task_table' ) ) {
			$tasks_path = $root . 'includes/tasks/';
			require_once $tasks_path . 'database/schema-tasks.php';
			require_once $tasks_path . 'database/schema-translation-results.php';
			if ( ! class_exists( '\\WPTSALL\\Tasks\\Services\\Origin_Visit_Service' ) ) {
				require_once $tasks_path . 'services/class-origin-visit-service.php';
			}
			require_once $tasks_path . 'tasks.php';
			require_once $tasks_path . 'tasks-single.php';
		}
		if ( ! class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
			require_once $root . 'includes/hooks/class-content-change-dispatcher.php';
		}
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			require_once $root . 'includes/sites/services/class-site-relation-service.php';
		}
		if ( ! function_exists( 'wptsall_create_content_change_outbox_table' ) ) {
			require_once $root . 'includes/models/database/schema-field-mappings.php';
		}
		wptsall_ensure_task_table();
		wptsall_create_content_change_outbox_table();
	}

	/**
	 * Remove rows left by earlier (possibly aborted) runs of this class.
	 */
	private function clean_own_state() {
		global $wpdb;
		$relations = wptsall_table( 'site_relations' );
		foreach ( array( wptsall_table( 'content_change_outbox' ), wptsall_table( 'tasks' ) ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE relation_id IN ( SELECT id FROM %i WHERE template LIKE %s )',
					$table,
					$relations,
					self::TEMPLATE_PREFIX . '%'
				)
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE template LIKE %s', $relations, self::TEMPLATE_PREFIX . '%' ) );
	}

	public function setUp(): void {
		parent::setUp();
		self::ensure_modules_loaded();
		remove_all_filters( 'wptsall_outbox_max_attempts' );
		$this->clean_own_state();
	}

	public function tearDown(): void {
		remove_all_filters( 'wptsall_outbox_max_attempts' );
		$this->clean_own_state();
		parent::tearDown();
	}

	/**
	 * Create an active relation (its own id space keeps every claim scoped).
	 *
	 * @return int
	 */
	private function make_relation() {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => self::TEMPLATE_PREFIX . uniqid(),
				'target_site_id'   => 'v_dead_letter_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$this->assertSame( '', (string) $wpdb->last_error, 'relation fixture insert: ' . $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Claim-owner hash the dispatcher stamps for the test device.
	 *
	 * @param int $relation_id Relation id.
	 * @return string
	 */
	private function owner_hash( $relation_id ) {
		return wptsall_client_claim_owner_hash( self::DEVICE, (int) $relation_id, '', 'outbox' );
	}

	/**
	 * Seed a task row (the compatibility/audit projection of an outbox row).
	 *
	 * @param int    $relation_id Relation id.
	 * @param string $status      Task status.
	 * @return int
	 */
	private function seed_task( $relation_id, $status = 'processing' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			wptsall_table( 'tasks' ),
			array(
				'blog_id'     => get_current_blog_id(),
				'site_id'     => (int) $relation_id,
				'relation_id' => (int) $relation_id,
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => mt_rand( 100000, 999999 ),
				'status'      => $status,
				'payload'     => '{}',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$this->assertSame( '', (string) $wpdb->last_error, 'task fixture insert: ' . $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Seed an outbox row with full control of its lifecycle columns.
	 *
	 * Options: status, attempts, claimed_at (GMT or null), available_at (GMT),
	 * task_id, owned (stamp the test device's claim-owner hash).
	 *
	 * @param int   $relation_id Relation id.
	 * @param array $options     Overrides.
	 * @return int
	 */
	private function seed_outbox( $relation_id, array $options = array() ) {
		global $wpdb;
		$now     = current_time( 'mysql', true );
		$payload = array(
			'post_type'   => 'post',
			'relation_id' => (int) $relation_id,
		);
		if ( ! empty( $options['task_id'] ) ) {
			$payload['task_id'] = (int) $options['task_id'];
		}
		if ( ! empty( $options['owned'] ) ) {
			$payload['_wptsall_claim_owner_hash'] = $this->owner_hash( $relation_id );
		}
		$status = $options['status'] ?? 'pending';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			wptsall_table( 'content_change_outbox' ),
			array(
				'event_key'      => hash( 'sha256', uniqid( 'dead-letter-', true ) ),
				'source_type'    => 'post',
				'source_id'      => mt_rand( 100000, 999999 ),
				'source_site_id' => get_current_blog_id(),
				'relation_id'    => (int) $relation_id,
				'event_name'     => 'post_updated',
				'payload'        => wp_json_encode( $payload ),
				'status'         => $status,
				'attempts'       => (int) ( $options['attempts'] ?? 0 ),
				'available_at'   => $options['available_at'] ?? gmdate( 'Y-m-d H:i:s', time() - 5 ),
				'claimed_at'     => array_key_exists( 'claimed_at', $options )
					? $options['claimed_at']
					: ( 'processing' === $status ? $now : null ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$this->assertSame( '', (string) $wpdb->last_error, 'outbox fixture insert: ' . $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $id Outbox row id.
	 * @return array
	 */
	private function outbox( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', wptsall_table( 'content_change_outbox' ), (int) $id ), ARRAY_A );
		$this->assertIsArray( $row, "outbox row {$id} must exist" );
		return $row;
	}

	/**
	 * @param int $id Task id.
	 * @return string
	 */
	private function task_status( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', wptsall_table( 'tasks' ), (int) $id ) );
	}

	/**
	 * Skip the DB backoff so a test can claim the row again right away.
	 *
	 * @param int $id Outbox row id.
	 */
	private function make_available_now( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			wptsall_table( 'content_change_outbox' ),
			array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * The retry cap; falls back to the documented default so a missing API
	 * shows up as a failed behavior assertion rather than a fatal error.
	 *
	 * @return int
	 */
	private function cap() {
		return method_exists( Content_Change_Dispatcher::class, 'max_attempts' )
			? (int) Content_Change_Dispatcher::max_attempts()
			: 8;
	}

	private function assert_api( $method ) {
		$this->assertTrue(
			method_exists( Content_Change_Dispatcher::class, $method ),
			"ATS-01: Content_Change_Dispatcher::{$method}() must exist"
		);
	}

	public function test_cap_defaults_to_eight_and_is_filterable_with_a_floor_of_one() {
		$this->assert_api( 'max_attempts' );
		$this->assertSame( 8, Content_Change_Dispatcher::max_attempts(), 'documented default cap' );

		$three = static function () {
			return 3;
		};
		$zero  = static function () {
			return 0;
		};
		add_filter( 'wptsall_outbox_max_attempts', $three );
		try {
			$this->assertSame( 3, Content_Change_Dispatcher::max_attempts(), 'the filter overrides the cap' );
			remove_filter( 'wptsall_outbox_max_attempts', $three );
			add_filter( 'wptsall_outbox_max_attempts', $zero );
			$this->assertSame( 1, Content_Change_Dispatcher::max_attempts(), 'a zero/negative cap is floored at one attempt' );
		} finally {
			remove_all_filters( 'wptsall_outbox_max_attempts' );
		}
	}

	public function test_retry_backoff_is_exponential_and_capped() {
		$this->assert_api( 'retry_backoff_seconds' );
		$expected = array(
			0  => 30,
			1  => 30,
			2  => 60,
			3  => 120,
			4  => 240,
			5  => 480,
			6  => 960,
			7  => 1800,
			8  => 1800,
			50 => 1800,
		);
		foreach ( $expected as $attempts => $seconds ) {
			$this->assertSame( $seconds, Content_Change_Dispatcher::retry_backoff_seconds( $attempts ), "backoff after {$attempts} attempt(s)" );
		}
	}

	public function test_fail_below_cap_returns_the_row_to_pending_with_server_backoff() {
		$relation_id = $this->make_relation();
		try {
			$id     = $this->seed_outbox( $relation_id, array( 'status' => 'processing', 'attempts' => 1, 'owned' => true ) );
			$before = time();

			$this->assertTrue( Content_Change_Dispatcher::fail_outbox( $id, 'translation_callback_500', $this->owner_hash( $relation_id ) ) );

			$row = $this->outbox( $id );
			$this->assertSame( 'pending', $row['status'], 'below the cap a failed row is retried' );
			$this->assertSame( 'translation_callback_500', $row['last_error'] );
			$this->assertNull( $row['claimed_at'], 'the lease is released' );
			$delay = strtotime( $row['available_at'] . ' UTC' ) - $before;
			$this->assertGreaterThanOrEqual( 25, $delay, 'a server-side failure must not be instantly re-claimable (tight re-lease loop)' );
			$this->assertLessThanOrEqual( 45, $delay, 'first backoff step is ~30s' );
		} finally {
			$this->clean_own_state();
		}
	}

	public function test_fail_with_explicit_zero_hint_keeps_the_immediate_retry_contract() {
		$relation_id = $this->make_relation();
		try {
			// The ack endpoint always passes an explicit integer (the client's own
			// backoff hint, possibly 0); that contract must not change.
			$id     = $this->seed_outbox( $relation_id, array( 'status' => 'processing', 'attempts' => 1, 'owned' => true ) );
			$before = time();

			$this->assertTrue( Content_Change_Dispatcher::fail_outbox( $id, 'client_retry', $this->owner_hash( $relation_id ), 0 ) );

			$row = $this->outbox( $id );
			$this->assertSame( 'pending', $row['status'] );
			$this->assertLessThanOrEqual( 3, strtotime( $row['available_at'] . ' UTC' ) - $before, 'an explicit 0 hint means retry now' );

			// And an explicit hint is honored as given.
			$id2 = $this->seed_outbox( $relation_id, array( 'status' => 'processing', 'attempts' => 1, 'owned' => true ) );
			$this->assertTrue( Content_Change_Dispatcher::fail_outbox( $id2, 'client_retry', $this->owner_hash( $relation_id ), 120 ) );
			$delay = strtotime( $this->outbox( $id2 )['available_at'] . ' UTC' ) - $before;
			$this->assertGreaterThanOrEqual( 115, $delay );
			$this->assertLessThanOrEqual( 130, $delay );
		} finally {
			$this->clean_own_state();
		}
	}

	public function test_fail_at_cap_moves_the_row_to_dead_and_fails_its_task() {
		$relation_id = $this->make_relation();
		try {
			$task_id = $this->seed_task( $relation_id, 'processing' );
			$id      = $this->seed_outbox(
				$relation_id,
				array(
					'status'   => 'processing',
					'attempts' => $this->cap(),
					'task_id'  => $task_id,
					'owned'    => true,
				)
			);

			$this->assertTrue( Content_Change_Dispatcher::fail_outbox( $id, 'translation_callback_500', $this->owner_hash( $relation_id ) ) );

			$row = $this->outbox( $id );
			$this->assertSame( 'dead', $row['status'], 'the cap-th failed attempt is terminal' );
			$this->assertSame( 'translation_callback_500', $row['last_error'], 'the last error stays visible on the dead row' );
			$this->assertNull( $row['claimed_at'] );
			$this->assertNull( $row['completed_at'], 'dead is not completed' );
			$this->assertSame( 'failed', $this->task_status( $task_id ), 'the task projection must agree with the outbox row' );
		} finally {
			$this->clean_own_state();
		}
	}

	public function test_abandon_is_immediately_terminal_and_owner_bound() {
		$this->assert_api( 'abandon_outbox' );
		$relation_id = $this->make_relation();
		try {
			$task_id = $this->seed_task( $relation_id, 'retry' );
			$id      = $this->seed_outbox( $relation_id, array( 'status' => 'processing', 'attempts' => 1, 'task_id' => $task_id, 'owned' => true ) );

			$this->assertFalse( Content_Change_Dispatcher::abandon_outbox( $id, 'unsupported_outbox_source', str_repeat( 'a', 64 ) ), 'another device cannot abandon the lease' );
			$this->assertSame( 'processing', $this->outbox( $id )['status'] );

			$this->assertFalse( Content_Change_Dispatcher::abandon_outbox( $id, 'unsupported_outbox_source', 'not-a-hash' ), 'a malformed owner hash is rejected' );
			$this->assertSame( 'processing', $this->outbox( $id )['status'] );

			$this->assertTrue( Content_Change_Dispatcher::abandon_outbox( $id, 'unsupported_outbox_source', $this->owner_hash( $relation_id ) ) );
			$row = $this->outbox( $id );
			$this->assertSame( 'dead', $row['status'], 'a permanent error does not wait for the cap' );
			$this->assertSame( 'unsupported_outbox_source', $row['last_error'] );
			$this->assertSame( 1, (int) $row['attempts'], 'abandoning does not consume further attempts' );
			$this->assertSame( 'failed', $this->task_status( $task_id ) );
		} finally {
			$this->clean_own_state();
		}
	}

	public function test_claim_retires_expired_lease_rows_that_hit_the_cap() {
		$relation_id = $this->make_relation();
		try {
			$expired = gmdate( 'Y-m-d H:i:s', time() - 2000 );
			$task_id = $this->seed_task( $relation_id, 'processing' );
			// A poison pill: its client crashes on every lease, so nothing ever
			// calls fail_outbox - only lease expiry moves the row.
			$poison = $this->seed_outbox( $relation_id, array( 'status' => 'processing', 'attempts' => $this->cap(), 'claimed_at' => $expired, 'task_id' => $task_id ) );
			$normal = $this->seed_outbox( $relation_id, array( 'status' => 'processing', 'attempts' => 1, 'claimed_at' => $expired ) );
			$live   = $this->seed_outbox( $relation_id, array( 'status' => 'processing', 'attempts' => $this->cap() ) );

			$claimed = Content_Change_Dispatcher::claim_outbox( 10, 900, $relation_id, self::DEVICE );
			$ids     = array_map( 'intval', wp_list_pluck( $claimed, 'id' ) );

			$this->assertSame( array( $normal ), $ids, 'only the row with attempts left is re-leased' );
			$this->assertSame( 2, (int) $this->outbox( $normal )['attempts'] );

			$dead = $this->outbox( $poison );
			$this->assertSame( 'dead', $dead['status'], 'an expired-lease row at the cap is retired instead of re-leased forever' );
			$this->assertSame( $this->cap(), (int) $dead['attempts'], 'retiring does not bump attempts' );
			$this->assertNotEmpty( $dead['last_error'], 'the dead row says why' );
			$this->assertSame( 'failed', $this->task_status( $task_id ) );

			$this->assertSame( 'processing', $this->outbox( $live )['status'], 'a live lease is never retired, even at the cap' );
		} finally {
			$this->clean_own_state();
		}
	}

	public function test_claim_retires_pending_rows_over_a_lowered_cap() {
		$relation_id = $this->make_relation();
		$three       = static function () {
			return 3;
		};
		add_filter( 'wptsall_outbox_max_attempts', $three );
		try {
			$exhausted = $this->seed_outbox( $relation_id, array( 'status' => 'pending', 'attempts' => 3 ) );
			$fresh     = $this->seed_outbox( $relation_id, array( 'status' => 'pending', 'attempts' => 2 ) );

			$claimed = Content_Change_Dispatcher::claim_outbox( 10, 900, $relation_id, self::DEVICE );

			$this->assertSame( array( $fresh ), array_map( 'intval', wp_list_pluck( $claimed, 'id' ) ), 'an exhausted pending row is not leased again' );
			$this->assertSame( 'dead', $this->outbox( $exhausted )['status'], 'lowering the cap retires rows that already exceed it' );
		} finally {
			remove_all_filters( 'wptsall_outbox_max_attempts' );
			$this->clean_own_state();
		}
	}

	public function test_claim_cas_rechecks_the_cap_after_candidates_were_read() {
		global $wpdb;
		$relation_id = $this->make_relation();
		$id          = $this->seed_outbox( $relation_id, array( 'attempts' => $this->cap() - 1 ) );
		$table       = wptsall_table( 'content_change_outbox' );
		$cap         = $this->cap();
		$interleaved = false;
		// Deterministically change the row between its candidate SELECT and CAS.
		// This tests the second guard independently of the candidate predicate.
		$advance = static function ( $query ) use ( $wpdb, $table, $id, $cap, &$interleaved ) {
			if ( ! $interleaved
				&& 0 === strpos( $query, 'UPDATE ' )
				&& false !== strpos( $query, $table )
				&& false !== strpos( $query, 'attempts = attempts + 1' ) ) {
				$interleaved = true;
				$wpdb->update( $table, array( 'attempts' => $cap ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
			}
			return $query;
		};
		add_filter( 'query', $advance );
		try {
			$claimed = Content_Change_Dispatcher::claim_outbox( 1, 900, $relation_id, self::DEVICE );
			$this->assertTrue( $interleaved, 'fixture must run between the SELECT and CAS' );
			$this->assertSame( array(), $claimed, 'a candidate that reached the cap before CAS cannot acquire another lease' );
			$this->assertSame( $cap, (int) $this->outbox( $id )['attempts'] );
			$this->assertSame( 'pending', $this->outbox( $id )['status'] );
		} finally {
			remove_filter( 'query', $advance );
			$this->clean_own_state();
		}
	}

	/**
	 * Bulk-seed pending rows that already used up their attempts: what a site
	 * upgrading with a stuck backlog looks like, because `attempts` grew
	 * without limit before the cap existed.
	 *
	 * @param int $relation_id Relation id.
	 * @param int $count       Rows to seed.
	 */
	private function seed_exhausted_backlog( $relation_id, $count ) {
		global $wpdb;
		$table    = wptsall_table( 'content_change_outbox' );
		$now      = current_time( 'mysql', true );
		$ready    = gmdate( 'Y-m-d H:i:s', time() - 5 );
		$attempts = $this->cap() + 40;
		foreach ( array_chunk( range( 1, (int) $count ), 250 ) as $chunk ) {
			$tuples = array();
			foreach ( $chunk as $n ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value goes through prepare() below.
				$tuples[] = $wpdb->prepare(
					"(%s, 'post', %d, %d, %d, 'post_updated', '{}', 'pending', %d, %s, NULL, %s, %s)",
					hash( 'sha256', "dead-letter-backlog-{$relation_id}-{$n}" ),
					1000000 + $n,
					get_current_blog_id(),
					(int) $relation_id,
					$attempts,
					$ready,
					$now,
					$now
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- bulk fixture insert; the table name is plugin-generated and the tuples are prepared.
			$wpdb->query( "INSERT INTO {$table} (event_key, source_type, source_id, source_site_id, relation_id, event_name, payload, status, attempts, available_at, claimed_at, created_at, updated_at) VALUES " . implode( ',', $tuples ) );
			$this->assertSame( '', (string) $wpdb->last_error, 'backlog fixture insert: ' . $wpdb->last_error );
		}
	}

	/**
	 * @param int    $relation_id Relation id.
	 * @param string $status      Outbox status.
	 * @return int
	 */
	private function count_outbox( $relation_id, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE relation_id = %d AND status = %s', wptsall_table( 'content_change_outbox' ), (int) $relation_id, $status )
		);
	}

	/**
	 * A retirement pass is bounded, so rows over the cap can outlive the first
	 * claim after an upgrade. They must neither be leased again nor crowd a
	 * fresh row out of the claim window (1000 rows, oldest first).
	 */
	public function test_backlog_over_the_cap_neither_gets_leased_nor_starves_a_fresh_row() {
		$relation_id = $this->make_relation();
		try {
			$backlog = 1250;
			$this->seed_exhausted_backlog( $relation_id, $backlog );
			$fresh = $this->seed_outbox( $relation_id, array( 'status' => 'pending', 'attempts' => 0 ) );

			$claimed = Content_Change_Dispatcher::claim_outbox( 5, 900, $relation_id, self::DEVICE );

			$this->assertSame( array( $fresh ), array_map( 'intval', wp_list_pluck( $claimed, 'id' ) ), 'only the fresh row is leased: a row over the cap never is, and a backlog of them does not fill the claim window' );
			$this->assertSame( 1, $this->count_outbox( $relation_id, 'processing' ), 'no row over the cap took a lease' );

			for ( $pass = 0; $pass < 20 && $this->count_outbox( $relation_id, 'pending' ) > 0; $pass++ ) {
				Content_Change_Dispatcher::claim_outbox( 1, 900, $relation_id, self::DEVICE );
			}
			$this->assertSame( 0, $this->count_outbox( $relation_id, 'pending' ), 'each claim retires a slice of the backlog until none is pending' );
			$this->assertSame( $backlog, $this->count_outbox( $relation_id, 'dead' ), 'the whole backlog ends dead, none of it leased' );
		} finally {
			$this->clean_own_state();
		}
	}

	public function test_dead_rows_are_absorbing() {
		$relation_id = $this->make_relation();
		try {
			$id = $this->seed_outbox( $relation_id, array( 'status' => 'dead', 'attempts' => $this->cap(), 'claimed_at' => null, 'owned' => true ) );

			$this->assertFalse( Content_Change_Dispatcher::complete_outbox( $id, $this->owner_hash( $relation_id ) ), 'a dead row cannot be completed' );
			$this->assertFalse( Content_Change_Dispatcher::fail_outbox( $id, 'late_failure', $this->owner_hash( $relation_id ), 0 ), 'a dead row cannot be revived by a late failure' );
			$this->assertSame( array(), Content_Change_Dispatcher::claim_outbox( 10, 900, $relation_id, self::DEVICE ), 'a dead row is never leased' );

			$row = $this->outbox( $id );
			$this->assertSame( 'dead', $row['status'] );
			$this->assertSame( $this->cap(), (int) $row['attempts'] );
		} finally {
			$this->clean_own_state();
		}
	}

	public function test_peek_ignores_dead_and_exhausted_rows() {
		$relation_id = $this->make_relation();
		try {
			$this->seed_outbox( $relation_id, array( 'status' => 'dead', 'attempts' => $this->cap() ) );
			$this->seed_outbox( $relation_id, array( 'status' => 'pending', 'attempts' => $this->cap() ) );
			$this->seed_outbox( $relation_id, array( 'status' => 'pending', 'available_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
			$this->assertFalse(
				Content_Change_Dispatcher::has_claimable_outbox( $relation_id ),
				'dead, exhausted and backing-off rows must not wake the events/wait long-poll'
			);

			$this->seed_outbox( $relation_id, array( 'status' => 'pending', 'attempts' => 1 ) );
			$this->assertTrue( Content_Change_Dispatcher::has_claimable_outbox( $relation_id ), 'control: a genuinely claimable row still wakes it' );
		} finally {
			$this->clean_own_state();
		}
	}

	/**
	 * Redirect the outbox to the scratch table (filter callback).
	 *
	 * @param array $tables Table key => suffix map.
	 * @return array
	 */
	public function scratch_outbox_table_names( $tables ) {
		$tables['content_change_outbox'] = self::SCRATCH_OUTBOX_SUFFIX;
		return $tables;
	}

	/**
	 * Fail-closed drop of the scratch outbox (never the shared table).
	 */
	private function drop_scratch_outbox() {
		global $wpdb;
		$table = wptsall_table( 'content_change_outbox' );
		if ( false === strpos( $table, self::SCRATCH_OUTBOX_SUFFIX ) ) {
			throw new Exception( "scratch outbox fixture miswired: refusing to drop non-scratch table {$table}" );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
	}

	public function test_unscoped_peek_ignores_rows_of_missing_relations() {
		$relation_id = $this->make_relation();
		add_filter( 'wptsall_table_names', array( $this, 'scratch_outbox_table_names' ) );
		try {
			$this->drop_scratch_outbox();
			wptsall_create_content_change_outbox_table();
			$this->assertStringContainsString( self::SCRATCH_OUTBOX_SUFFIX, wptsall_table( 'content_change_outbox' ), 'fixture must point at the scratch outbox' );

			// Rows no claim can ever reach: a relation that was deleted before
			// the cascade existed, and the relation_id=0 rows written on sites
			// that had no relation yet. The unscoped long-poll used to wake for
			// them on every tick, so the client spun.
			$this->seed_outbox( 900000000 + mt_rand( 1, 99999 ), array( 'status' => 'pending' ) );
			$this->seed_outbox( 0, array( 'status' => 'pending' ) );
			$this->assertFalse(
				Content_Change_Dispatcher::has_claimable_outbox( 0 ),
				'rows of missing relations must not wake the unscoped events/wait long-poll'
			);

			$this->seed_outbox( $relation_id, array( 'status' => 'pending' ) );
			$this->assertTrue( Content_Change_Dispatcher::has_claimable_outbox( 0 ), 'control: a row of an active relation still wakes it' );
		} finally {
			remove_filter( 'wptsall_table_names', array( $this, 'scratch_outbox_table_names' ) );
			add_filter( 'wptsall_table_names', array( $this, 'scratch_outbox_table_names' ) );
			try {
				$this->drop_scratch_outbox();
			} finally {
				remove_filter( 'wptsall_table_names', array( $this, 'scratch_outbox_table_names' ) );
				$this->clean_own_state();
			}
		}
	}

	/**
	 * The defect's own scenario: a row that can never succeed must reach a
	 * terminal state after N rounds, and stay consistent with its task.
	 */
	public function test_permanently_failing_row_reaches_dead_in_cap_rounds() {
		$relation_id = $this->make_relation();
		try {
			$cap     = $this->cap();
			$task_id = $this->seed_task( $relation_id, 'pending' );
			$id      = $this->seed_outbox( $relation_id, array( 'status' => 'pending', 'task_id' => $task_id ) );

			for ( $round = 1; $round <= $cap; $round++ ) {
				$claimed = Content_Change_Dispatcher::claim_outbox( 5, 900, $relation_id, self::DEVICE );
				$this->assertCount( 1, $claimed, "round {$round}: the row is still claimable" );
				$this->assertSame( $round, (int) $claimed[0]['attempts'], "round {$round}: attempts count the leases" );

				// What the controller's callback tail does on a 5xx.
				$this->assertTrue( Content_Change_Dispatcher::fail_outbox( $id, 'translation_callback_500', $this->owner_hash( $relation_id ) ) );
				$row = $this->outbox( $id );
				if ( $round < $cap ) {
					$this->assertSame( 'pending', $row['status'], "round {$round}: still retryable" );
					$this->assertSame( 'retry', $this->task_status( $task_id ), "round {$round}: task projection follows" );
					$this->make_available_now( $id );
				}
			}

			$row = $this->outbox( $id );
			$this->assertSame( 'dead', $row['status'], "after {$cap} failed rounds the row must be terminal, not pending forever" );
			$this->assertSame( $cap, (int) $row['attempts'] );
			$this->assertSame( 'failed', $this->task_status( $task_id ), 'outbox and task projection end in the same terminal state' );
			$this->assertSame( array(), Content_Change_Dispatcher::claim_outbox( 5, 900, $relation_id, self::DEVICE ), 'nothing is left to lease' );
			$this->assertFalse( Content_Change_Dispatcher::has_claimable_outbox( $relation_id ), 'and nothing wakes the long-poll' );
		} finally {
			$this->clean_own_state();
		}
	}
}

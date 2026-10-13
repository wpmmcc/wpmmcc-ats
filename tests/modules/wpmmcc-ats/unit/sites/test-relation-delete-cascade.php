<?php
/**
 * Deleting a site relation removes what the sync pipeline derived from it (ATS-05)
 *
 * delete_relation used to clean the relation's models, hooks, post type configs
 * and monitoring tasks, then stop: its mappings, outbox events, translation
 * results, tasks, conflicts, option state and manual-queue items stayed behind.
 * Relation ids are only unique per blog, so a later relation can be handed the
 * same id and inherit that state - objects that look already translated, events
 * nobody can apply.
 *
 * The network-wide mapping tables (base prefix, shared by all blogs) are matched
 * on the relation's source site as well, so another blog's relation with the same
 * number is left alone. Sibling relations of the same source are untouched.
 * Translated content on the target belongs to the target and is not deleted.
 *
 * catalog: WP-CLASS-Site_Relation_Service
 * oracle: L2
 *
 * @package WPTSALL
 * @since 2.3.0
 */

use WPTSALL\Sites\Services\Site_Relation_Service;

class Test_Relation_Delete_Cascade extends WP_UnitTestCase {

	/**
	 * Source site ids no real blog has: every row seeded for them is this test's.
	 */
	const SOURCE_SITE = 8800001;

	const OTHER_SITE = 8800002;

	const MARKER = 'test-cascade-';

	/**
	 * Every table the cascade has to reach, by table key.
	 */
	const CASCADE_TABLES = array(
		'conflicts',
		'content_change_outbox',
		'manual_queue',
		'option_sync_state',
		'translation_results',
		'tasks',
		'task_logs',
		'task_items',
		'post_mappings',
		'term_mappings',
		'media_mappings',
		'menu_mappings',
		'mappings',
	);

	private $seq = 0;

	private static function plugin_root() {
		if ( defined( 'WPTSALL_PATH' ) && is_dir( WPTSALL_PATH ) ) {
			return trailingslashit( WPTSALL_PATH );
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
	 * tasks module) and create every table the cascade reaches.
	 */
	private static function ensure_tables() {
		$root = self::plugin_root();
		if ( ! function_exists( 'wptsall_insert_mapping' ) ) {
			$tasks_path = $root . 'includes/tasks/';
			require_once $tasks_path . 'database/schema-tasks.php';
			require_once $tasks_path . 'database/schema-translation-results.php';
			if ( ! class_exists( '\\WPTSALL\\Tasks\\Services\\Origin_Visit_Service' ) ) {
				require_once $tasks_path . 'services/class-origin-visit-service.php';
			}
			require_once $tasks_path . 'tasks.php';
			require_once $tasks_path . 'tasks-single.php';
		}
		if ( ! class_exists( '\\WPTSALL\\Tasks\\Services\\Monitoring_Task_Service' ) ) {
			require_once $root . 'includes/tasks/services/class-monitoring-task-service.php';
		}
		foreach ( array(
			'wptsall_create_manual_queue_table'  => 'includes/tasks/database/schema-manual-queue.php',
			'wptsall_create_term_mappings_table' => 'includes/models/database/schema-field-mappings.php',
			'wptsall_create_conflicts_table'     => 'includes/sync/database/schema-conflicts.php',
			'wptsall_create_menu_mappings_table' => 'includes/menu-translation/database/schema-menu-mappings.php',
		) as $creator => $file ) {
			if ( ! function_exists( $creator ) ) {
				require_once $root . $file;
			}
		}
		foreach ( array(
			'wptsall_create_tasks_table',
			'wptsall_create_task_logs_table',
			'wptsall_create_task_items_table',
			'wptsall_create_translation_results_table',
			'wptsall_create_field_mapping_tables',
			'wptsall_create_manual_queue_table',
			'wptsall_create_conflicts_table',
			'wptsall_create_menu_mappings_table',
			'wptsall_ensure_mapping_table',
		) as $creator ) {
			$creator();
		}
	}

	/**
	 * Remove everything this class seeded, including what an aborted earlier run
	 * left: rows are found by their markers, not by relation id.
	 */
	private function clean_own_state() {
		global $wpdb;
		$like = self::MARKER . '%';
		$sites = array( self::SOURCE_SITE, self::OTHER_SITE );

		$wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l INNER JOIN %i t ON t.id = l.task_id WHERE t.template LIKE %s', wptsall_table( 'task_logs' ), wptsall_table( 'tasks' ), $like ) );
		$wpdb->query( $wpdb->prepare( 'DELETE i FROM %i i INNER JOIN %i t ON t.id = i.task_id WHERE t.template LIKE %s', wptsall_table( 'task_items' ), wptsall_table( 'tasks' ), $like ) );
		foreach ( array(
			'tasks'                 => 'template',
			'conflicts'             => 'client_task_id',
			'content_change_outbox' => 'event_key',
			'manual_queue'          => 'reason',
			'option_sync_state'     => 'option_name',
			'translation_results'   => 'client_task_id',
			'menu_mappings'         => 'virtual_site_id',
			'site_relations'        => 'template',
		) as $key => $column ) {
			$pattern = 'option_sync_state' === $key ? 'test_cascade_%' : ( 'menu_mappings' === $key ? 'v\\_test\\_cascade\\_%' : $like );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i LIKE %s', wptsall_table( $key ), $column, $pattern ) );
		}
		foreach ( array( 'post_mappings', 'term_mappings', 'media_mappings' ) as $key ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_site_id IN (%d, %d)', wptsall_table( $key ), $sites[0], $sites[1] ) );
		}
		$legacy = wptsall_table( 'mappings' );
		if ( $legacy === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_blog_id IN (%d, %d)', $legacy, $sites[0], $sites[1] ) );
		}
	}

	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( Site_Relation_Service::class ) ) {
			require_once self::plugin_root() . 'includes/sites/services/class-site-relation-service.php';
		}
		self::ensure_tables();
		$this->clean_own_state();
	}

	public function tearDown(): void {
		// Keep the tasks module's table-name registration. Redirect tests remove
		// only their own filters in finally, so cleanup still reaches seeded rows.
		$this->clean_own_state();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	private function virtual_site_id() {
		return 'v_test_cascade_' . ( ++$this->seq ) . '_' . uniqid();
	}

	private function create_relation( $source_site, $virtual_site ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => $source_site,
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => self::MARKER . $virtual_site,
				'target_site_id'   => $virtual_site,
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $id, 'relation fixture: ' . $wpdb->last_error );
		return $id;
	}

	private function insert_row( $key, array $row ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( wptsall_table( $key ), $row );
		$id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $id, "{$key} fixture: " . $wpdb->last_error );
		return $id;
	}

	/**
	 * One row in every table the cascade covers, for one relation.
	 *
	 * @param int    $relation_id  Relation id the rows carry.
	 * @param int    $source_site  Source site the network-wide rows carry.
	 * @param string $virtual_site Target the rows point at.
	 * @return array<string,int> Row id per table key.
	 */
	private function seed_rows( $relation_id, $source_site, $virtual_site ) {
		global $wpdb;
		$n   = ++$this->seq;
		$now = current_time( 'mysql' );
		$gmt = current_time( 'mysql', true );

		$rows = array();
		$rows['conflicts']             = $this->insert_row( 'conflicts', array( 'relation_id' => $relation_id, 'client_task_id' => self::MARKER . $n ) );
		$rows['content_change_outbox'] = $this->insert_row(
			'content_change_outbox',
			array(
				'event_key'      => self::MARKER . $n,
				'source_type'    => 'post',
				'source_id'      => 1,
				'source_site_id' => $source_site,
				'relation_id'    => $relation_id,
				'event_name'     => 'post_updated',
				'status'         => 'pending',
				'available_at'   => $gmt,
				'created_at'     => $gmt,
				'updated_at'     => $gmt,
			)
		);
		$rows['manual_queue']          = $this->insert_row( 'manual_queue', array( 'relation_id' => $relation_id, 'reason' => self::MARKER . $n, 'created_at' => $now, 'updated_at' => $now ) );
		$rows['option_sync_state']     = $this->insert_row(
			'option_sync_state',
			array(
				'relation_id'    => $relation_id,
				'source_site_id' => $source_site,
				'target_site_id' => $virtual_site,
				'option_name'    => 'test_cascade_' . $n,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$result_id = wptsall_insert_translation_result(
			array(
				'relation_id'       => $relation_id,
				'object_type'       => 'post_type',
				'object_id'         => 10 + $n,
				'translated_fields' => array( 'post_title' => 'x' ),
				'client_task_id'    => self::MARKER . $n,
				'source_lang'       => 'zh_CN',
				'target_lang'       => 'en_US',
			)
		);
		$this->assertGreaterThan( 0, (int) $result_id, 'translation result fixture' );
		$rows['translation_results'] = (int) $result_id;

		$rows['tasks']      = $this->insert_row(
			'tasks',
			array(
				'blog_id'     => get_current_blog_id(),
				'site_id'     => $relation_id,
				'relation_id' => $relation_id,
				'template'    => self::MARKER . 'task-' . $n,
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 10 + $n,
				'status'      => 'pending',
				'payload'     => '{}',
				'created_at'  => $gmt,
				'updated_at'  => $gmt,
			)
		);
		$rows['task_logs']  = $this->insert_row( 'task_logs', array( 'task_id' => $rows['tasks'], 'created_at' => $gmt ) );
		$rows['task_items'] = $this->insert_row(
			'task_items',
			array(
				'task_id'    => $rows['tasks'],
				'item_type'  => 'post',
				'source_id'  => 10 + $n,
				'created_at' => $gmt,
				'updated_at' => $gmt,
			)
		);

		$rows['post_mappings']  = $this->insert_row(
			'post_mappings',
			array(
				'relation_id'      => $relation_id,
				'source_post_id'   => 10 + $n,
				'source_post_type' => 'post',
				'source_site_id'   => $source_site,
				'target_post_id'   => 0,
				'target_post_type' => 'post',
				'target_site_id'   => $virtual_site,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$rows['term_mappings']  = $this->insert_row(
			'term_mappings',
			array(
				'relation_id'     => $relation_id,
				'source_term_id'  => 10 + $n,
				'source_taxonomy' => 'category',
				'source_site_id'  => $source_site,
				'source_lang'     => 'zh_CN',
				'target_term_id'  => 0,
				'target_taxonomy' => 'category',
				'target_site_id'  => $virtual_site,
				'target_lang'     => 'en_US',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$rows['media_mappings'] = $this->insert_row(
			'media_mappings',
			array(
				'relation_id'      => $relation_id,
				'source_media_id'  => 10 + $n,
				'source_site_id'   => $source_site,
				'source_file_path' => '/src.png',
				'source_file_url'  => 'https://source.invalid/src.png',
				'target_media_id'  => 0,
				'target_site_id'   => $virtual_site,
				'target_file_path' => '/dst.png',
				'target_file_url'  => 'https://target.invalid/dst.png',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$rows['menu_mappings']  = $this->insert_row(
			'menu_mappings',
			array(
				'source_menu_term_id' => 10 + $n,
				'virtual_site_id'     => $virtual_site,
				'relation_id'         => $relation_id,
			)
		);

		wptsall_insert_mapping( $source_site, 'post_type', 'post', 10 + $n, 1, 20 + $n, 'test_cascade', $relation_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$legacy_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE source_blog_id = %d AND source_object_id = %d AND relation_id = %d',
				wptsall_table( 'mappings' ),
				$source_site,
				10 + $n,
				$relation_id
			)
		);
		$this->assertGreaterThan( 0, $legacy_id, 'legacy mapping fixture' );
		$rows['mappings'] = $legacy_id;

		return $rows;
	}

	private function row_exists( $key, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', wptsall_table( $key ), (int) $id ) );
	}

	private function assert_rows( array $rows, $expect_present, $what ) {
		foreach ( $rows as $key => $id ) {
			$this->assertSame( $expect_present, $this->row_exists( $key, $id ), "{$key}: {$what}" );
		}
	}

	// ------------------------------------------------------------------
	// Tests
	// ------------------------------------------------------------------

	public function test_fixture_covers_every_cascade_table() {
		$relation = $this->create_relation( self::SOURCE_SITE, $this->virtual_site_id() );
		$rows     = $this->seed_rows( $relation, self::SOURCE_SITE, 'v_test_cascade_fixture' );
		$this->assertSame( self::CASCADE_TABLES, array_keys( $rows ), 'the fixture seeds one row per table the cascade promises to reach' );
		$this->assert_rows( $rows, true, 'seeded' );
	}

	public function test_delete_relation_removes_the_rows_derived_from_it_and_nothing_else() {
		$virtual_a = $this->virtual_site_id();
		$virtual_b = $this->virtual_site_id();
		$relation_a = $this->create_relation( self::SOURCE_SITE, $virtual_a );
		$relation_b = $this->create_relation( self::SOURCE_SITE, $virtual_b );

		$mine    = $this->seed_rows( $relation_a, self::SOURCE_SITE, $virtual_a );
		$sibling = $this->seed_rows( $relation_b, self::SOURCE_SITE, $virtual_b );
		// Another blog's relation that happens to carry the same number: only the
		// network-wide tables can hold its rows next to ours.
		$other_blog = $this->seed_rows( $relation_a, self::OTHER_SITE, $this->virtual_site_id() );
		foreach ( array( 'conflicts', 'content_change_outbox', 'manual_queue', 'option_sync_state', 'translation_results', 'tasks', 'task_logs', 'task_items' ) as $per_blog_table ) {
			// Those tables are per blog: the other blog has its own copy, not a row here.
			unset( $other_blog[ $per_blog_table ] );
		}

		$result = Site_Relation_Service::delete_relation( $relation_a );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assert_rows( $mine, false, 'the deleted relation\'s row must be gone' );
		$this->assert_rows( $sibling, true, 'a sibling relation of the same source keeps its rows' );
		$this->assert_rows( $other_blog, true, 'a same-numbered relation of another blog keeps its rows' );
	}

	public function test_delete_relation_tolerates_tables_an_install_does_not_have() {
		$virtual  = $this->virtual_site_id();
		$relation = $this->create_relation( self::SOURCE_SITE, $virtual );
		$rows     = $this->seed_rows( $relation, self::SOURCE_SITE, $virtual );

		$absent = static function ( $tables ) {
			$tables['manual_queue']      = 'wptsall_cascade_absent_manual_queue';
			$tables['option_sync_state'] = 'wptsall_cascade_absent_option_state';
			$tables['task_items']        = 'wptsall_cascade_absent_task_items';
			return $tables;
		};
		add_filter( 'wptsall_table_names', $absent );
		try {
			$result = Site_Relation_Service::delete_relation( $relation );
		} finally {
			remove_filter( 'wptsall_table_names', $absent );
		}

		$this->assertTrue( $result['success'], 'a table that is not there must not stop the deletion: ' . wp_json_encode( $result ) );
		foreach ( array( 'manual_queue', 'option_sync_state', 'task_items' ) as $skipped ) {
			$this->assertTrue( $this->row_exists( $skipped, $rows[ $skipped ] ), "{$skipped}: outside the redirect it still stands" );
			unset( $rows[ $skipped ] );
		}
		$this->assert_rows( $rows, false, 'every table that does exist is still purged' );
	}

	public function test_delete_relation_removes_monitoring_task_children_before_the_task() {
		global $wpdb;
		$virtual  = $this->virtual_site_id();
		$relation = $this->create_relation( self::SOURCE_SITE, $virtual );
		$rows     = $this->seed_rows( $relation, self::SOURCE_SITE, $virtual );
		$wpdb->update( wptsall_table( 'tasks' ), array( 'type' => 'monitoring' ), array( 'id' => $rows['tasks'] ) );
		try {
			$result = Site_Relation_Service::delete_relation( $relation );
			$this->assertTrue( $result['success'], wp_json_encode( $result ) );
			$this->assert_rows( $rows, false, 'monitoring tasks must not leave logs or items behind' );
		} finally {
			// Even the broken ordering removes the parent, so join-based cleanup
			// cannot find these children. Clean only the fixture's explicit ids.
			foreach ( array( 'task_logs', 'task_items' ) as $key ) {
				$wpdb->delete( wptsall_table( $key ), array( 'id' => $rows[ $key ] ), array( '%d' ) );
			}
			$this->clean_own_state();
		}
	}
}

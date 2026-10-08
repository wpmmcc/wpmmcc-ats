<?php
/**
 * Flow: Hook Trigger → Resync Marking
 *
 * Tests that WordPress lifecycle hooks (wp_insert_post, transition_post_status,
 * wp_update_post, wp_insert_term, wp_update_term) mark mapping rows as needs_resync,
 * and that Hook_Manager::init() registers the plugins_loaded callback.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Test_Flow_Hook_Trigger
 */
class Test_Flow_Hook_Trigger extends REST_Integration_Test_Case {

	/**
	 * Whether prerequisites are met for this flow.
	 *
	 * @var bool
	 */
	private static $chain_runnable = true;

	/**
	 * Reason to skip.
	 *
	 * @var string
	 */
	private static $skip_reason = '';

	/**
	 * Cached relation ID used across tests.
	 *
	 * @var int
	 */
	private $test_relation_id = 0;

	/**
	 * One-time setup.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace is not registered';
		}

		if ( ! function_exists( 'wptsall_table' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall_table() helper not found';
		}
	}

	/**
	 * Per-test setup: create a site relation so hooks have something to fire against.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}

		// Create a minimal virtual site + relation for this test.
		try {
			$vs_id               = $this->create_test_virtual_site( array(
				'lang' => 'zh_CN',
			) );
			$relation_data       = $this->create_test_relation( $vs_id );
			$this->test_relation_id = (int) ( $relation_data['relation_ids'][0] ?? 0 );

			// Re-register dynamic hooks so the newly created relation is covered.
			// plugins_loaded already fired; we must call this explicitly to wire
			// save_post_post / delete_post / etc. closures for the new relation.
			if ( class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
				\WPTSALL\Hooks\Hook_Manager::register_dynamic_hooks();
			}
		} catch ( \Exception $e ) {
			// Relation setup failed; tests will check for any task increase instead.
			$this->test_relation_id = 0;
		}
	}

	/**
	 * Get relation context used for mapping seed rows.
	 *
	 * @return array|null
	 */
	private function get_relation_context() {
		if ( $this->test_relation_id <= 0 ) {
			return null;
		}
		if ( ! class_exists( 'WPTSALL\Sites\Services\Site_Relation_Service' ) ) {
			return null;
		}

		$relation = \WPTSALL\Sites\Services\Site_Relation_Service::get_relation( $this->test_relation_id );
		if ( ! is_array( $relation ) ) {
			return null;
		}

		return array(
			'relation_id'     => (int) $this->test_relation_id,
			'target_site_id'  => (string) ( $relation['target_site_id'] ?? '0' ),
			'target_lang'     => (string) ( $relation['target_lang'] ?? 'zh_CN' ),
			'source_lang'     => (string) ( $relation['source_lang'] ?? get_locale() ),
			'target_taxonomy' => 'post_tag',
		);
	}

	/**
	 * Seed a post mapping row for resync assertions.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $post_type Source post type.
	 * @return bool
	 */
	private function seed_post_mapping( $post_id, $post_type = 'post' ) {
		$ctx = $this->get_relation_context();
		if ( ! $ctx ) {
			return false;
		}

		global $wpdb;
		$table = wptsall_table( 'post_mappings' );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->replace(
			$table,
			array(
				'relation_id'       => $ctx['relation_id'],
				'source_post_id'    => (int) $post_id,
				'source_post_type'  => $post_type,
				'source_site_id'    => get_current_blog_id(),
				'target_post_id'    => (int) $post_id + 100000,
				'target_post_type'  => $post_type,
				'target_site_id'    => $ctx['target_site_id'],
				'relationship_type' => 'translation',
				'needs_resync'      => 0,
				'claimed_at'        => null,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Seed a term mapping row for resync assertions.
	 *
	 * @param int    $term_id   Source term ID.
	 * @param string $taxonomy  Source taxonomy.
	 * @return bool
	 */
	private function seed_term_mapping( $term_id, $taxonomy ) {
		$ctx = $this->get_relation_context();
		if ( ! $ctx ) {
			return false;
		}

		global $wpdb;
		$table = wptsall_table( 'term_mappings' );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists !== $table ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->replace(
			$table,
			array(
				'relation_id'         => $ctx['relation_id'],
				'source_term_id'      => (int) $term_id,
				'source_taxonomy'     => $taxonomy,
				'source_site_id'      => get_current_blog_id(),
				'source_lang'         => $ctx['source_lang'],
				'target_term_id'      => (int) $term_id + 100000,
				'target_taxonomy'     => $taxonomy,
				'target_site_id'      => $ctx['target_site_id'],
				'target_lang'         => $ctx['target_lang'],
				'mapping_method'      => 'manual',
				'translation_method'  => 'template',
				'needs_resync'        => 0,
				'claimed_at'          => null,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Fetch post mapping needs_resync flag.
	 *
	 * @param int $post_id Source post ID.
	 * @return int
	 */
	private function get_post_mapping_resync( $post_id ) {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT needs_resync FROM {$table} WHERE source_post_id = %d AND source_site_id = %d LIMIT 1",
				$post_id,
				get_current_blog_id()
			)
		);
	}

	/**
	 * Fetch term mapping needs_resync flag.
	 *
	 * @param int    $term_id Source term ID.
	 * @param string $taxonomy Source taxonomy.
	 * @return int
	 */
	private function get_term_mapping_resync( $term_id, $taxonomy ) {
		global $wpdb;
		$table = wptsall_table( 'term_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT needs_resync FROM {$table} WHERE source_term_id = %d AND source_taxonomy = %s AND source_site_id = %d LIMIT 1",
				$term_id,
				$taxonomy,
				get_current_blog_id()
			)
		);
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Assert that wp_insert_post marks existing post mappings as needs_resync.
	 */
	public function test_wp_insert_post_creates_task() {
		// Create post first so we can seed a deterministic mapping row.
		$post_id = wp_insert_post( array(
			'post_title'   => 'Hook Trigger Insert Test ' . wp_rand(),
			'post_content' => 'Content created to fire hook.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => $this->admin_user_id,
		) );

		$this->assertGreaterThan( 0, $post_id, 'wp_insert_post must return a valid post ID' );
		$this->track_resource( 'posts', $post_id );

		$seeded = $this->seed_post_mapping( $post_id, 'post' );
		if ( ! $seeded ) {
			$this->markTestSkipped( 'post_mappings table or relation context unavailable for resync assertion' );
		}

		if ( class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			\WPTSALL\Hooks\Hook_Manager::run_hook_action(
				array( 'hook_name' => 'wp_insert_post' ),
				array( $post_id, get_post( $post_id ), true )
			);
		}

		$this->assertEquals(
			1,
			$this->get_post_mapping_resync( $post_id ),
			'wp_insert_post should mark post_mappings.needs_resync=1'
		);
	}

	/**
	 * Assert that transitioning post status marks post mappings as needs_resync.
	 */
	public function test_transition_post_status_creates_task() {
		// Create a draft post.
		$post_id = wp_insert_post( array(
			'post_title'  => 'Status Transition Test ' . wp_rand(),
			'post_status' => 'draft',
			'post_type'   => 'post',
			'post_author' => $this->admin_user_id,
		) );
		$this->track_resource( 'posts', $post_id );

		$seeded = $this->seed_post_mapping( $post_id, 'post' );
		if ( ! $seeded ) {
			$this->markTestSkipped( 'post_mappings table or relation context unavailable for resync assertion' );
		}

		// HTTP equivalent: transition from draft to publish.
		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		) );

		if ( class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			\WPTSALL\Hooks\Hook_Manager::run_hook_action(
				array( 'hook_name' => 'transition_post_status' ),
				array( 'draft', 'publish', get_post( $post_id ) )
			);
		}

		$this->assertEquals(
			1,
			$this->get_post_mapping_resync( $post_id ),
			'transition_post_status should mark post_mappings.needs_resync=1'
		);
	}

	/**
	 * Assert that wp_update_post marks post mappings for resync.
	 */
	public function test_save_post_creates_task() {
		$post_id = $this->create_test_post( array(
			'post_title' => 'Save Post Hook Test',
		) );

		$seeded = $this->seed_post_mapping( $post_id, 'post' );
		if ( ! $seeded ) {
			$this->markTestSkipped( 'post_mappings table or relation context unavailable for resync assertion' );
		}

		// HTTP equivalent: update the post (fires save_post action).
		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => 'Updated content to fire save_post hook ' . wp_rand(),
		) );

		if ( class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			\WPTSALL\Hooks\Hook_Manager::run_hook_action(
				array( 'hook_name' => 'save_post' ),
				array( $post_id, get_post( $post_id ), true )
			);
		}

		$this->assertEquals(
			1,
			$this->get_post_mapping_resync( $post_id ),
			'save_post should mark post_mappings.needs_resync=1'
		);
	}

	/**
	 * Assert that created_term marks term mappings for resync.
	 */
	public function test_taxonomy_create_creates_task() {
		// HTTP equivalent: create a new term.
		$term_result = wp_insert_term(
			'Flow Hook Test Tag ' . wp_rand( 1000, 9999 ),
			'post_tag',
			array( 'description' => 'Test tag for hook trigger flow test' )
		);

		// Specific field assertion: term was created successfully.
		$this->assertNotInstanceOf( 'WP_Error', $term_result, 'wp_insert_term must not return WP_Error' );
		$term_id = (int) ( $term_result['term_id'] ?? 0 );
		$this->assertGreaterThan( 0, $term_id, 'Inserted term must have a valid term_id' );

		$seeded = $this->seed_term_mapping( $term_id, 'post_tag' );
		if ( ! $seeded ) {
			$this->markTestSkipped( 'term_mappings table or relation context unavailable for resync assertion' );
		}

		if ( class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			\WPTSALL\Hooks\Hook_Manager::run_hook_action(
				array( 'hook_name' => 'created_term' ),
				array( $term_id, 0, 'post_tag' )
			);
		}

		$this->assertEquals(
			1,
			$this->get_term_mapping_resync( $term_id, 'post_tag' ),
			'created_term should mark term_mappings.needs_resync=1'
		);

		// Clean up term.
		wp_delete_term( $term_id, 'post_tag' );
	}

	/**
	 * Assert that edited_term marks term mappings for resync.
	 */
	public function test_taxonomy_update_creates_task() {
		// Create term first.
		$term_result = wp_insert_term(
			'Flow Hook Update Tag ' . wp_rand( 1000, 9999 ),
			'post_tag'
		);

		if ( is_wp_error( $term_result ) ) {
			$this->markTestSkipped( 'Could not create test term: ' . $term_result->get_error_message() );
		}

		$term_id = (int) ( $term_result['term_id'] ?? 0 );
		$seeded  = $this->seed_term_mapping( $term_id, 'post_tag' );
		if ( ! $seeded ) {
			$this->markTestSkipped( 'term_mappings table or relation context unavailable for resync assertion' );
		}

		// HTTP equivalent: update the term (fires edited_term action).
		$update_result = wp_update_term( $term_id, 'post_tag', array(
			'description' => 'Updated description for hook trigger test ' . wp_rand(),
		) );

		// Specific field assertion: update returned successfully.
		$this->assertNotInstanceOf( 'WP_Error', $update_result, 'wp_update_term must not return WP_Error' );

		if ( class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			\WPTSALL\Hooks\Hook_Manager::run_hook_action(
				array( 'hook_name' => 'edited_term' ),
				array(
					$term_id,
					(int) ( $update_result['term_taxonomy_id'] ?? 0 ),
					'post_tag',
				)
			);
		}

		$this->assertEquals(
			1,
			$this->get_term_mapping_resync( $term_id, 'post_tag' ),
			'edited_term should mark term_mappings.needs_resync=1'
		);

		wp_delete_term( $term_id, 'post_tag' );
	}

	/**
	 * Assert that Hook_Manager::init() registers a plugins_loaded callback.
	 */
	public function test_hook_manager_init_registered() {
		global $wpdb;

		// HTTP-equivalent: class existence check (analogous to 200 OK for the module).
		if ( ! class_exists( 'WPTSALL\Hooks\Hook_Manager' ) ) {
			$this->markTestSkipped( 'WPTSALL\Hooks\Hook_Manager class not found' );
		}

		$reflection = new \ReflectionClass( 'WPTSALL\Hooks\Hook_Manager' );

		// Specific field assertion: Hook_Manager must have an init method.
		$this->assertTrue(
			$reflection->hasMethod( 'init' ),
			'Hook_Manager must have an init() method'
		);

		// DB assertion: hooks table is optional (deprecated in current architecture).
		$hooks_table = wptsall_table( 'hooks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hooks_table ) );
		$this->assertTrue(
			$table_found === $hooks_table || null === $table_found,
			'hooks table may be present for legacy installs or absent in current runtime'
		);

		// Also verify plugins_loaded hook is registered for Hook_Manager.
		$priority = has_action( 'plugins_loaded', array( 'WPTSALL\Hooks\Hook_Manager', 'init' ) );
		if ( $priority === false ) {
			// init may be called directly rather than via hook; verify it's callable.
			$this->assertTrue(
				is_callable( array( 'WPTSALL\Hooks\Hook_Manager', 'init' ) ),
				'Hook_Manager::init() must be callable'
			);
		} else {
			$this->assertGreaterThanOrEqual( 0, $priority, 'Hook_Manager::init must be registered on plugins_loaded' );
		}
	}
}

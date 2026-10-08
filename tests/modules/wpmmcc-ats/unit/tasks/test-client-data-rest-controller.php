<?php
/**
 * Client Data REST Controller Tests
 *
 * Tests for Client Data REST endpoints in
 * includes/tasks/api/class-client-data-rest-controller.php
 *
 * catalog: WP-WRITE-handle_content_callback
 * catalog: WP-WRITE-handle_site_string_callback
 * catalog: WP-HOOK-wptsall-require-client-storage-map-contract
 * oracle: L2
 *
 * The content write-back lane (handle_content_callback) is exercised by
 * the post_content callback tests below; the site-string lane
 * (handle_site_string_callback) is exercised by the site_strings guard
 * tests — its apply/aggregation leg (claimed string rows) is owned by
 * the String_Translation_Service suite and e2e.
 *
 * @package WPTSALL
 * @since 1.0.5
 */

class Test_Client_Data_REST_Controller extends WP_UnitTestCase {

	/**
	 * Controller instance.
	 *
	 * @var \WPTSALL\Tasks\API\Client_Data_REST_Controller
	 */
	protected $controller;

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Test relation ID.
	 *
	 * @var int
	 */
	protected $test_relation_id = 0;

	/**
	 * Test post IDs.
	 *
	 * @var array
	 */
	protected $test_post_ids = array();

	/**
	 * Test option names created during option-backed discovery tests.
	 *
	 * @var array
	 */
	protected $test_option_names = array();

	/**
	 * Test model IDs created during rule-discovery tests.
	 *
	 * @var array
	 */
	protected $test_model_ids = array();

	/**
	 * Resolve the active WPTSALL plugin root path.
	 *
	 * @return string
	 */
	private static function get_plugin_root_path() {
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
	 * Ensure tasks module files are loaded.
	 *
	 * On localhost the Tasks module is not auto-loaded because domain
	 * validation rejects localhost. We manually require the needed files.
	 */
	private static function ensure_tasks_module_loaded() {
		if ( function_exists( 'wptsall_ensure_task_table' ) ) {
			return;
		}

		$tasks_path = self::get_plugin_root_path() . 'includes/tasks/';
		if ( ! is_dir( $tasks_path ) ) {
			throw new \Exception( 'Cannot locate WPTSALL tasks module' );
		}

		// Load database schemas first.
		require_once $tasks_path . 'database/schema-tasks.php';
		require_once $tasks_path . 'database/schema-translation-results.php';

		// Services needed by the controller.
		if ( ! class_exists( '\\WPTSALL\\Tasks\\Services\\Origin_Visit_Service' ) ) {
			require_once $tasks_path . 'services/class-origin-visit-service.php';
		}

		// Task functions (defines wptsall_ensure_task_table, wptsall_insert_tasks, etc.).
		require_once $tasks_path . 'tasks.php';
		require_once $tasks_path . 'tasks-single.php';

		// The controller itself.
		require_once $tasks_path . 'api/class-client-data-rest-controller.php';
	}

	/**
	 * Ensure lifecycle outbox and snapshot helpers are loaded for protocol-v2 tests.
	 */
	private static function ensure_content_change_module_loaded() {
		$plugin_root = self::get_plugin_root_path();

		if ( ! class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ) {
			require_once $plugin_root . 'includes/core/class-job-snapshot.php';
		}

		if ( ! class_exists( '\\WPTSALL\\Hooks\\Content_Change_Dispatcher' ) ) {
			require_once $plugin_root . 'includes/hooks/class-content-change-dispatcher.php';
		}

		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Site_Relation_Service' ) ) {
			require_once $plugin_root . 'includes/sites/services/class-site-relation-service.php';
		}

		if ( ! function_exists( 'wptsall_create_content_change_outbox_table' ) ) {
			require_once $plugin_root . 'includes/models/database/schema-field-mappings.php';
		}

		if ( function_exists( 'wptsall_create_content_change_outbox_table' ) ) {
			wptsall_create_content_change_outbox_table();
		}
	}

	/**
	 * Create an additional relation for content-change isolation tests.
	 *
	 * @param array $overrides Relation field overrides.
	 * @return int Relation ID.
	 */
	private function create_content_changes_relation( $overrides = array() ) {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );
		$data  = wp_parse_args(
			$overrides,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'test-client-data-filter-' . uniqid(),
				'target_site_id'   => 'v_filter_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$wpdb->insert(
			$table,
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a pending content-change outbox row for tests.
	 *
	 * @param int   $relation_id Relation ID.
	 * @param int   $post_id     Source post ID.
	 * @param array $payload     Extra payload fields.
	 * @return int Outbox row ID.
	 */
	private function insert_content_change_outbox_row( $relation_id, $post_id, $payload = array() ) {
		global $wpdb;
		$outbox_table = wptsall_table( 'content_change_outbox' );
		$now          = current_time( 'mysql' );
		$event_key    = hash( 'sha256', 'test-client-data-outbox-' . uniqid( '', true ) . '-' . (int) $relation_id . '-' . (int) $post_id );
		$wpdb->insert(
			$outbox_table,
			array(
				'event_key'      => $event_key,
				'source_type'    => 'post',
				'source_id'      => (int) $post_id,
				'source_site_id' => get_current_blog_id(),
				'relation_id'    => (int) $relation_id,
				'event_name'     => 'post_updated',
				'payload'        => wp_json_encode( array_merge( array( 'post_type' => 'post' ), $payload ) ),
				'status'         => 'pending',
				'attempts'       => 0,
				'available_at'   => $now,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Ensure model/rule and relation-model services are available for
	 * option-backed discovery tests (execution plan 2-5, ISS A-2).
	 *
	 * @return void
	 */
	private static function ensure_rule_discovery_dependencies_loaded() {
		$plugin_root = self::get_plugin_root_path();

		if ( ! function_exists( 'wptsall_create_model_tables' ) ) {
			require_once $plugin_root . 'includes/models/database/schema-models.php';
		}
		if ( ! function_exists( 'wptsall_create_relation_models_table' ) ) {
			require_once $plugin_root . 'includes/sites/database/schema-relation-models.php';
		}
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
			require_once $plugin_root . 'includes/models/services/class-translation-rule-service.php';
		}
		if ( ! class_exists( '\\WPTSALL\\Models\\Services\\Model_Object_Service' ) ) {
			require_once $plugin_root . 'includes/models/services/class-model-object-service.php';
		}
		if ( ! class_exists( '\\WPTSALL\\Sites\\Services\\Relation_Model_Service' ) ) {
			require_once $plugin_root . 'includes/sites/services/class-relation-model-service.php';
		}
	}

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// On localhost the Tasks module is not auto-loaded.
		self::ensure_tasks_module_loaded();

		// Set up REST server.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create controller instance for direct method calls.
		$this->controller = new \WPTSALL\Tasks\API\Client_Data_REST_Controller();

		// Ensure required tables exist.
		wptsall_ensure_task_table();
		wptsall_create_translation_results_table();
		self::ensure_rule_discovery_dependencies_loaded();
		if ( function_exists( 'wptsall_create_model_tables' ) ) {
			wptsall_create_model_tables();
		}
		if ( function_exists( 'wptsall_create_relation_models_table' ) ) {
			wptsall_create_relation_models_table();
		}

		// Origin visits schema is loaded lazily during migration, load manually.
		if ( ! function_exists( 'wptsall_create_origin_visits_table' ) ) {
			$origin_visits_schema = WP_PLUGIN_DIR . '/wptsall-pro/includes/tasks/database/schema-origin-visits.php';
			if ( file_exists( $origin_visits_schema ) ) {
				require_once $origin_visits_schema;
			}
		}
		if ( function_exists( 'wptsall_create_origin_visits_table' ) ) {
			wptsall_create_origin_visits_table();
		}

		// Create test relation.
		global $wpdb;
		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );

		$unique_template = 'test-client-data-' . uniqid();

		$wpdb->insert(
			$table,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => $unique_template,
				'target_site_id'   => 'v_test_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->test_relation_id = $wpdb->insert_id;

		// Create test posts.
		for ( $i = 0; $i < 3; $i++ ) {
			$post_id = $this->factory->post->create( array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Client Data Test Post ' . ( $i + 1 ),
				'post_content' => '<p>Test content for post ' . ( $i + 1 ) . '</p>',
				'post_excerpt' => 'Excerpt for post ' . ( $i + 1 ),
			) );
			$this->test_post_ids[] = $post_id;
		}
	}

	/**
	 * Device id used by the claim + callback pair in Protocol v2 tests.
	 *
	 * @var string
	 */
	private $test_device_id = 'test-client-data-device';

	/**
	 * Ensure a post_mappings row exists for the given post so the
	 * claim → callback flow (Protocol v2) has a row to stamp.
	 *
	 * P1-TEST-02 (2026-09-02): translation callbacks now require a current
	 * device claim stamped onto post_mappings (claimed_at +
	 * claim_owner_hash). Tests must therefore create the mapping row and go
	 * through claim_content() before invoking translation_callback().
	 *
	 * Column set note (2026-09-12): post_mappings has NO target_lang/status
	 * columns by design — the target language lives on site_relations and
	 * is joined via relation_id (see class-translation-progress-service.php).
	 *
	 * @param int $post_id Source post id.
	 * @return void
	 */
	private function ensure_post_mapping_for_claim( $post_id ) {
		global $wpdb;
		$table = wptsall_table( 'post_mappings' );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$relation_row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT target_site_id, target_lang FROM %i WHERE id = %d', wptsall_table( 'site_relations' ), $this->test_relation_id ),
			ARRAY_A
		);

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE relation_id = %d AND source_post_id = %d AND source_site_id = %d AND target_site_id = %s LIMIT 1",
				$this->test_relation_id,
				(int) $post_id,
				get_current_blog_id(),
				(string) ( $relation_row['target_site_id'] ?? '' )
			)
		);
		if ( $existing ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'relation_id'      => $this->test_relation_id,
				'source_post_id'   => (int) $post_id,
				'source_post_type' => 'post',
				'source_site_id'   => get_current_blog_id(),
				'target_post_id'   => 0,
				'target_post_type' => 'post',
				'target_site_id'   => (string) ( $relation_row['target_site_id'] ?? '' ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Run the Protocol v2 content claim for a post via the real service
	 * entry point (claim_content), so claimed_at/claim_owner_hash are
	 * stamped exactly as the callback validation expects.
	 *
	 * @param int $post_id Source post id.
	 * @return \WP_REST_Response Claim response.
	 */
	private function claim_post_for_callback( $post_id ) {
		$this->ensure_post_mapping_for_claim( $post_id );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/content/claim' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->test_device_id );
		$request->set_body( wp_json_encode( array(
			'relation_id' => $this->test_relation_id,
			'data_type'   => 'post',
			'items'       => array(
				array( 'object_id' => (int) $post_id, 'post_type' => 'post' ),
			),
		) ) );

		return $this->controller->claim_content( $request );
	}

	/**
	 * Build a translation-callback request carrying the same device id used
	 * for the claim, as Protocol v2 validates claim ownership by device.
	 *
	 * @param array $body Callback payload.
	 * @return \WP_REST_Request
	 */
	private function build_callback_request( array $body ) {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->test_device_id );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * Clean up test data.
	 */
	public function tearDown(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;

		// Clean up test relation.
		if ( $this->test_relation_id ) {
			$table = wptsall_table( 'site_relations' );
			$wpdb->delete( $table, array( 'id' => $this->test_relation_id ), array( '%d' ) );
		}

		// Clean up test posts.
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->test_post_ids = array();

		// Clean up translation results.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$results_table} WHERE client_task_id LIKE 'test-%'" );

		// Clean up test tasks.
		$tasks_table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$tasks_table} WHERE template LIKE 'test%'" );

		if ( function_exists( 'wptsall_create_content_change_outbox_table' ) ) {
			$outbox_table = wptsall_table( 'content_change_outbox' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox_table} WHERE relation_id = %d", $this->test_relation_id ) );
		}

		// Clean up option-discovery test models (unset is_system so the
		// service accepts deletion) and their relation bindings.
		if ( $this->test_model_ids && class_exists( '\\WPTSALL\\Models\\Services\\Translation_Rule_Service' ) ) {
			foreach ( $this->test_model_ids as $model_id ) {
				$wpdb->update(
					wptsall_table( 'models' ),
					array( 'is_system' => 0 ),
					array( 'id' => (int) $model_id ),
					array( '%d' ),
					array( '%d' )
				);
				\WPTSALL\Models\Services\Translation_Rule_Service::delete_model( (int) $model_id );
			}
		}
		$this->test_model_ids = array();
		if ( function_exists( 'wptsall_create_relation_models_table' ) ) {
			$relation_models_table = wptsall_table( 'relation_models' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$relation_models_table} WHERE relation_id = %d", $this->test_relation_id ) );
		}

		// Clean up test options.
		foreach ( $this->test_option_names as $option_name ) {
			delete_option( $option_name );
		}
		$this->test_option_names = array();

		parent::tearDown();
	}

	// ========================================
	// Route Registration Tests
	// ========================================

	/**
	 * Test client data REST routes are registered.
	 *
	 * On localhost, the Tasks module is not auto-loaded so routes may not
	 * be registered via rest_api_init. We register them manually and verify.
	 *
	 * Note: In Pro, Client_Data_REST_Controller only registers /client/translation-callback.
	 * The /client/site-relations, /client/meta, and /client/site-relations/{id}/content
	 * endpoints were removed during the Pro migration.
	 */
	public function test_routes_registered() {
		// Manually register routes (on localhost rest_api_init fires before module is loaded).
		$this->controller->register_routes();

		$routes = $this->server->get_routes();
		$secret = function_exists( 'wptsall_get_client_route_secret' ) ? wptsall_get_client_route_secret() : '';
		$expected_route = '/wptsall/v2/' . $secret . '/client/translation-callback';
		$this->assertArrayHasKey( $expected_route, $routes, 'client/translation-callback route (expected: ' . $expected_route . ')' );
	}

	// ========================================
	// Translation Callback Tests (post_content)
	// ========================================

	/**
	 * Test translation callback with valid post_content data.
	 */
	public function test_translation_callback_post_content() {
		self::ensure_content_change_module_loaded();

		$client_task_id = 'test-content-' . uniqid();
		$post_id         = $this->test_post_ids[0];
		$source_revision = \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id );
		$policy_version  = \WPTSALL\Core\Job_Snapshot::current_policy_version();

		// Protocol v2: the callback is only accepted with a current
		// device-owned content claim (see assert_current_content_claim).
		$claim_response = $this->claim_post_for_callback( $post_id );
		$this->assertEquals(
			200,
			$claim_response->get_status(),
			'claim_content response: ' . wp_json_encode( $claim_response->get_data() )
		);

		$request = $this->build_callback_request( array(
			'business_line'     => 'post_content',
			'client_task_id'    => $client_task_id,
			'relation_id'       => $this->test_relation_id,
			'object_type'       => 'post_type',
			'object_id'         => $post_id,
			'post_type'         => 'post',
			'source_lang'       => 'zh_CN',
			'target_lang'       => 'en_US',
			'source_revision'   => $source_revision,
			'policy_version'    => $policy_version,
			'translated_fields' => array(
				'post_title'   => '【en_US】Client Data Test Post 1【/en_US】',
				'post_content' => '【en_US】<p>Test content for post 1</p>【/en_US】',
			),
			'translated_meta'   => array(),
			'media_mappings'    => array(),
		) );

		$response = $this->controller->translation_callback( $request );

		$this->assertEquals( 200, $response->get_status(), 'Response status should be 200' );

		$data = $response->get_data();
		$this->assertTrue( $data['success'], 'Response should indicate success' );
		$this->assertArrayHasKey( 'result_id', $data, 'Should return result_id' );
		$this->assertGreaterThan( 0, $data['result_id'], 'result_id should be positive' );

		// Verify the result was stored in the database.
		global $wpdb;
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$results_table} WHERE client_task_id = %s",
				$client_task_id
			),
			ARRAY_A
		);

		$this->assertNotNull( $row, 'Translation result should be stored in database' );
		$this->assertEquals( $this->test_relation_id, (int) $row['relation_id'] );
		$this->assertEquals( 'post_type', $row['object_type'] );
		$this->assertEquals( $post_id, (int) $row['object_id'] );
		$this->assertEquals( $source_revision, $row['source_revision'] );
		$this->assertEquals( $policy_version, $row['policy_version'] );
		// v1.1.0: translation_callback executes Sync_Executor synchronously
		// before returning, so the result row is already marked 'synced' here.
		$this->assertEquals( 'synced', $row['status'] );
	}

	public function test_get_untranslated_posts_include_ids_attaches_job_snapshot() {
		self::ensure_content_change_module_loaded();

		$method = new ReflectionMethod( $this->controller, 'get_untranslated_posts' );
		$method->setAccessible( true );

		$response = $method->invoke(
			$this->controller,
			array(
				'id'             => $this->test_relation_id,
				'source_site_id' => get_current_blog_id(),
				'target_site_id' => 'v_test_' . uniqid(),
			),
			array( 'post' ),
			1,
			10,
			false,
			array( $this->test_post_ids[0] )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['items'], 'include_ids fast path should return at least one item' );

		$item = $data['items'][0];
		// P1-TEST-02 (2026-09-02): discovery items carry the freshness
		// contract inside complete_data.__wptsall_job_snapshot; the legacy
		// top-level source_revision/policy_version keys no longer exist.
		$this->assertArrayHasKey( 'complete_data', $item );
		$this->assertArrayHasKey( '__wptsall_job_snapshot', $item['complete_data'] );
		$this->assertNotEmpty( $item['complete_data']['__wptsall_job_snapshot']['source_revision'] );
		$this->assertNotEmpty( $item['complete_data']['__wptsall_job_snapshot']['policy_version'] );
	}

	public function test_get_content_changes_outbox_claim_attaches_job_snapshot() {
		self::ensure_content_change_module_loaded();

		global $wpdb;
		$outbox_table = wptsall_table( 'content_change_outbox' );
		$now          = current_time( 'mysql' );
		$event_key    = 'test-client-data-outbox-' . uniqid();
		$post_id      = $this->test_post_ids[1];

		// P1-TEST-02 (2026-09-02): claim the oldest pending rows first, so a
		// stale row from a previous test would be returned as items[0] and
		// its snapshot digest would not match this test's post. Clear the
		// outbox (disposable test data) before inserting this row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$outbox_table}" );

		$wpdb->insert(
			$outbox_table,
			array(
				'event_key'      => $event_key,
				'source_type'    => 'post',
				'source_id'      => $post_id,
				'source_site_id' => get_current_blog_id(),
				'relation_id'    => $this->test_relation_id,
				'event_name'     => 'post_updated',
				'payload'        => wp_json_encode( array( 'post_type' => 'post' ) ),
				'status'         => 'pending',
				'attempts'       => 0,
				'available_at'   => $now,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/content-changes' );
		$request->set_param( 'limit', 1 );
		$request->set_header( 'X-WPTSALL-Device-Id', 'test-client-data-device' );

		$response = $this->controller->get_content_changes( $request );
		$this->assertEquals( 200, $response->get_status(), 'Outbox claim should return 200' );

		$data = $response->get_data();
		$this->assertTrue( $data['success'], 'Outbox claim should succeed' );
		$this->assertNotEmpty( $data['data']['items'], 'Outbox claim should return an item' );

		$item = $data['data']['items'][0]['item'];
		$this->assertArrayHasKey( 'complete_data', $item );
		$this->assertArrayHasKey( '__wptsall_job_snapshot', $item['complete_data'] );
		$this->assertNotEmpty( $item['complete_data']['__wptsall_job_snapshot']['source_revision'] );
		$this->assertEquals(
			\WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id ),
			$item['complete_data']['__wptsall_job_snapshot']['source_revision']
		);

		$tasks_table = wptsall_table( 'tasks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$task_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tasks_table} WHERE relation_id = %d AND object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
				$this->test_relation_id,
				'post_type',
				$post_id
			),
			ARRAY_A
		);
		$this->assertNotNull( $task_row, 'Outbox claim should materialize a task' );
		$task_payload = json_decode( (string) ( $task_row['payload'] ?? '' ), true );
		$this->assertIsArray( $task_payload );
		$this->assertArrayHasKey( 'complete_data', $task_payload );
		$this->assertArrayHasKey( '__wptsall_job_snapshot', $task_payload['complete_data'] );
		$this->assertEquals(
			$item['complete_data']['__wptsall_job_snapshot']['source_revision'],
			$task_payload['complete_data']['__wptsall_job_snapshot']['source_revision']
		);
	}

	public function test_get_content_changes_relation_id_claims_only_requested_relation() {
		self::ensure_content_change_module_loaded();

		global $wpdb;
		$outbox_table      = wptsall_table( 'content_change_outbox' );
		$tasks_table       = wptsall_table( 'tasks' );
		$sibling_relation  = $this->create_content_changes_relation( array( 'target_lang' => 'fr_FR' ) );
		$outbox_id         = 0;
		$sibling_outbox_id = 0;

		try {
			// 批D (X-12①) regression guard: the claim window is FIFO (id ASC,
			// LIMIT 1000) — on the SHARED slot-u lab database, leftover rows
			// from other tests would push freshly-seeded rows outside the
			// window (the pre-batch-D id DESC ordering always favored new
			// rows, which masked this). Seed from a clean queue.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$outbox_table}" );
			$outbox_id         = $this->insert_content_change_outbox_row( $this->test_relation_id, $this->test_post_ids[1] );
			$sibling_outbox_id = $this->insert_content_change_outbox_row( $sibling_relation, $this->test_post_ids[2] );

			$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/content-changes' );
			$request->set_param( 'limit', 10 );
			$request->set_param( 'relation_id', $this->test_relation_id );
			$request->set_header( 'X-WPTSALL-Device-Id', 'test-client-data-device' );

			$response = $this->controller->get_content_changes( $request );
			$this->assertEquals( 200, $response->get_status(), 'Relation-scoped outbox claim should return 200' );

			$data  = $response->get_data();
			$items = is_array( $data['data']['items'] ?? null ) ? $data['data']['items'] : array();
			$this->assertNotEmpty( $items, 'Requested relation should return its pending outbox row.' );

			$returned_outbox_ids = array_map(
				static function ( $row ) {
					return (int) ( $row['outbox_id'] ?? 0 );
				},
				$items
			);
			$this->assertContains( $outbox_id, $returned_outbox_ids, 'Requested relation outbox row should be returned.' );
			$this->assertNotContains( $sibling_outbox_id, $returned_outbox_ids, 'Sibling relation outbox row must not be returned.' );
			foreach ( $items as $row ) {
				$this->assertEquals( $this->test_relation_id, (int) ( $row['relation_id'] ?? 0 ), 'All returned rows must belong to requested relation.' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$requested_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$outbox_table} WHERE id = %d", $outbox_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$sibling_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$outbox_table} WHERE id = %d", $sibling_outbox_id ) );
			$this->assertSame( 'processing', $requested_status, 'Requested relation row should be claimed.' );
			$this->assertSame( 'pending', $sibling_status, 'Sibling relation row must remain unclaimed.' );
		} finally {
			foreach ( array_filter( array( $outbox_id, $sibling_outbox_id ) ) as $cleanup_outbox_id ) {
				$wpdb->delete( $outbox_table, array( 'id' => (int) $cleanup_outbox_id ), array( '%d' ) );
			}
			if ( $sibling_relation > 0 ) {
				$wpdb->delete( $tasks_table, array( 'relation_id' => $sibling_relation ), array( '%d' ) );
				$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => $sibling_relation ), array( '%d' ) );
			}
		}
	}

	public function test_get_content_changes_relation_id_invalid_scope_returns_empty_without_claiming() {
		self::ensure_content_change_module_loaded();

		global $wpdb;
		$outbox_table = wptsall_table( 'content_change_outbox' );
		$tasks_table  = wptsall_table( 'tasks' );
		$inactive_id  = $this->create_content_changes_relation( array( 'status' => 'inactive' ) );
		$foreign_id   = $this->create_content_changes_relation( array( 'source_site_id' => get_current_blog_id() + 1000 ) );
		$outbox_ids   = array();

		try {
			$outbox_ids[ $inactive_id ] = $this->insert_content_change_outbox_row( $inactive_id, $this->test_post_ids[1] );
			$outbox_ids[ $foreign_id ]  = $this->insert_content_change_outbox_row( $foreign_id, $this->test_post_ids[2] );

			foreach ( $outbox_ids as $relation_id => $outbox_id ) {
				$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/content-changes' );
				$request->set_param( 'limit', 1 );
				$request->set_param( 'relation_id', (int) $relation_id );
				$request->set_header( 'X-WPTSALL-Device-Id', 'test-client-data-device' );

				$response = $this->controller->get_content_changes( $request );
				$this->assertEquals( 200, $response->get_status(), 'Invalid relation scope should return an empty successful response.' );
				$data = $response->get_data();
				$this->assertEmpty( $data['data']['items'], 'Invalid relation scope must not return outbox rows.' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$outbox_table} WHERE id = %d", $outbox_id ) );
				$this->assertSame( 'pending', $status, 'Invalid relation scope must not claim its outbox row.' );
			}
		} finally {
			foreach ( array_filter( $outbox_ids ) as $cleanup_outbox_id ) {
				$wpdb->delete( $outbox_table, array( 'id' => (int) $cleanup_outbox_id ), array( '%d' ) );
			}
			foreach ( array_filter( array( $inactive_id, $foreign_id ) ) as $cleanup_relation_id ) {
				$wpdb->delete( $tasks_table, array( 'relation_id' => (int) $cleanup_relation_id ), array( '%d' ) );
				$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => (int) $cleanup_relation_id ), array( '%d' ) );
			}
		}
	}

	// ========================================
	// Translation Callback Tests (i18n)
	// ========================================

	/**
	 * Test translation callback with i18n data.
	 */
	public function test_translation_callback_i18n() {
		// Create test template entries.
		global $wpdb;
		$templates_table = wptsall_table( 'templates' );
		$entries_table   = wptsall_table( 'template_entries' );
		$now             = current_time( 'mysql' );

		// Create a template.
		$unique_slug = 'test-tpl-' . uniqid();
		$wpdb->insert(
			$templates_table,
			array(
				'relation_id'       => $this->test_relation_id,
				'slug'              => $unique_slug,
				'source_type'       => 'plugin',
				'source_identifier' => 'test-plugin',
				'text_domain'       => 'test-plugin-' . uniqid(),
				'target_language'   => 'en_US',
				'status'            => 'active',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$template_id = $wpdb->insert_id;

		// Create entries.
		// P1-TEST-02 (2026-09-02): the entry lifecycle is
		// pending → (claim) → translated; the legacy 'untranslated' status is
		// never claimable (the claim UPDATE requires status='pending').
		$entry_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->insert(
				$entries_table,
				array(
					'template_id' => $template_id,
					'msgid'       => 'Test string ' . ( $i + 1 ),
					'msgstr'      => '',
					'msgctxt'     => '',
					'source'      => 'scan',
					'reference'   => 'test-plugin.php:1',
					'status'      => 'pending',
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			);
			$entry_ids[] = $wpdb->insert_id;
		}

		// Protocol v2: claim the entries for this device before the callback;
		// handle_i18n_callback rejects entries without a current claim.
		$claim_request = new WP_REST_Request( 'POST', '/wptsall/v2/client/content/claim' );
		$claim_request->set_header( 'Content-Type', 'application/json' );
		$claim_request->set_header( 'X-WPTSALL-Device-Id', $this->test_device_id );
		$claim_request->set_body( wp_json_encode( array(
			'relation_id' => $this->test_relation_id,
			'data_type'   => 'language_pack',
			'subtype'     => 'plugin',
			'items'       => array(
				array( 'entry_id' => $entry_ids[0] ),
				array( 'entry_id' => $entry_ids[1] ),
			),
		) ) );
		$claim_response = $this->controller->claim_content( $claim_request );
		$this->assertEquals(
			200,
			$claim_response->get_status(),
			'language pack claim response: ' . wp_json_encode( $claim_response->get_data() )
		);

		$client_task_id = 'test-i18n-' . uniqid();

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->test_device_id );
		$request->set_body( wp_json_encode( array(
			'business_line'  => 'plugin_i18n',
			'client_task_id' => $client_task_id,
			'relation_id'    => $this->test_relation_id,
			'source_lang'    => 'zh_CN',
			'target_lang'    => 'en_US',
			'entries'        => array(
				array(
					'entry_id' => $entry_ids[0],
					'msgstr'   => '【en_US】Test string 1【/en_US】',
				),
				array(
					'entry_id' => $entry_ids[1],
					'msgstr'   => '【en_US】Test string 2【/en_US】',
				),
			),
		) ) );

		$response = $this->controller->translation_callback( $request );

		$this->assertEquals( 200, $response->get_status(), 'Response status should be 200' );

		$data = $response->get_data();
		$this->assertTrue( $data['success'], 'Response should indicate success' );
		$this->assertEquals( 2, $data['entries_updated'], 'Should update 2 entries' );

		// Verify entries were actually updated in the database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated_entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$entries_table} WHERE id = %d",
				$entry_ids[0]
			),
			ARRAY_A
		);

		$this->assertEquals( 'translated', $updated_entry['status'], 'Entry status should be translated' );
		$this->assertStringContainsString( 'Test string 1', $updated_entry['msgstr'] );

		// Third entry was never claimed nor sent; it must stay pending with
		// an empty msgstr (P1-TEST-02: pending is the pre-claim status).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$unchanged_entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$entries_table} WHERE id = %d",
				$entry_ids[2]
			),
			ARRAY_A
		);

		$this->assertEquals( 'pending', $unchanged_entry['status'], 'Untouched entry should remain pending' );
		$this->assertSame( '', (string) $unchanged_entry['msgstr'], 'Untouched entry should have no msgstr' );

		// Clean up.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$entries_table} WHERE template_id = %d",
			$template_id
		) );
		$wpdb->delete( $templates_table, array( 'id' => $template_id ), array( '%d' ) );
	}

	// ========================================
	// Idempotency Tests
	// ========================================

	/**
	 * Test idempotency: same client_task_id should not create duplicate.
	 */
	public function test_translation_callback_idempotency() {
		self::ensure_content_change_module_loaded();

		$client_task_id = 'test-idempotent-' . uniqid();
		$post_id         = $this->test_post_ids[0];
		$source_revision = \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id );
		$policy_version  = \WPTSALL\Core\Job_Snapshot::current_policy_version();

		// Protocol v2: claim before the first callback (P1-TEST-02).
		$claim_response = $this->claim_post_for_callback( $post_id );
		$this->assertEquals(
			200,
			$claim_response->get_status(),
			'claim_content response: ' . wp_json_encode( $claim_response->get_data() )
		);

		$body = array(
			'business_line'     => 'post_content',
			'client_task_id'    => $client_task_id,
			'relation_id'       => $this->test_relation_id,
			'object_type'       => 'post_type',
			'object_id'         => $post_id,
			'post_type'         => 'post',
			'source_lang'       => 'zh_CN',
			'target_lang'       => 'en_US',
			'source_revision'   => $source_revision,
			'policy_version'    => $policy_version,
			'translated_fields' => array(
				'post_title' => '【en_US】Test Title【/en_US】',
			),
			'translated_meta'   => array(),
			'media_mappings'    => array(),
		);

		// First call.
		$request1 = $this->build_callback_request( $body );

		$response1 = $this->controller->translation_callback( $request1 );
		$data1     = $response1->get_data();

		$this->assertEquals( 200, $response1->get_status(), 'First callback should return 200: ' . wp_json_encode( $data1 ) );
		$this->assertTrue( $data1['success'], 'First callback should succeed: ' . wp_json_encode( $data1 ) );
		$first_result_id = $data1['result_id'];

		// Second call with the same client_task_id.
		$request2 = $this->build_callback_request( $body );

		$response2 = $this->controller->translation_callback( $request2 );
		$data2     = $response2->get_data();

		$this->assertEquals( 200, $response2->get_status(), 'Duplicate call should return 200' );
		$this->assertTrue( $data2['success'], 'Duplicate call should succeed' );
		$this->assertTrue( $data2['idempotent'], 'Duplicate call should be flagged as idempotent' );
		$this->assertEquals( $first_result_id, $data2['result_id'], 'Should return same result_id' );

		// Verify only one row exists in the database.
		global $wpdb;
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s",
				$client_task_id
			)
		);

		$this->assertEquals( 1, $count, 'Should have exactly one row for duplicate client_task_id' );
	}

	// ========================================
	// Validation Error Tests
	// ========================================

	/**
	 * Test invalid relation_id returns error.
	 */
	public function test_translation_callback_invalid_relation_id() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'business_line'  => 'post_content',
			'client_task_id' => 'test-invalid-rel-' . uniqid(),
			'relation_id'    => 0,
			'object_type'    => 'post_type',
			'object_id'      => $this->test_post_ids[0],
		) ) );

		$response = $this->controller->translation_callback( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status(), 'Zero relation_id should return 400' );
		$this->assertFalse( $data['success'] );
		$this->assertEquals( 'missing_relation_id', $data['error'] );
	}

	/**
	 * Test missing required fields returns validation error.
	 */
	public function test_translation_callback_missing_client_task_id() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'business_line' => 'post_content',
			'relation_id'   => $this->test_relation_id,
			'object_type'   => 'post_type',
			'object_id'     => $this->test_post_ids[0],
		) ) );

		$response = $this->controller->translation_callback( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status(), 'Missing client_task_id should return 400' );
		$this->assertFalse( $data['success'] );
		$this->assertEquals( 'missing_client_task_id', $data['error'] );
	}

	/**
	 * Test missing object_id for content callback returns error.
	 */
	public function test_translation_callback_missing_object_id() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'business_line'  => 'post_content',
			'client_task_id' => 'test-no-object-' . uniqid(),
			'relation_id'    => $this->test_relation_id,
			'object_type'    => 'post_type',
			'object_id'      => 0,
		) ) );

		$response = $this->controller->translation_callback( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status(), 'Missing object_id should return 400' );
		$this->assertFalse( $data['success'] );
		$this->assertEquals( 'missing_object_id', $data['error'] );
	}

	/**
	 * Test unknown business_line returns error.
	 */
	public function test_translation_callback_unknown_business_line() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'business_line'  => 'invalid_type',
			'client_task_id' => 'test-bad-line-' . uniqid(),
			'relation_id'    => $this->test_relation_id,
		) ) );

		$response = $this->controller->translation_callback( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status() );
		$this->assertFalse( $data['success'] );
		$this->assertEquals( 'unknown_business_line', $data['error'] );
	}

	/**
	 * Test i18n callback with empty entries returns error.
	 */
	public function test_translation_callback_i18n_missing_entries() {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'business_line'  => 'theme_i18n',
			'client_task_id' => 'test-no-entries-' . uniqid(),
			'relation_id'    => $this->test_relation_id,
			'entries'        => array(),
		) ) );

		$response = $this->controller->translation_callback( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status() );
		$this->assertFalse( $data['success'] );
		$this->assertEquals( 'missing_entries', $data['error'] );
	}

	// ========================================
	// Option-backed Discovery / Claim (ISS A-2, execution plan 2-5)
	// ========================================

	/**
	 * Register a test option and track it for cleanup.
	 *
	 * The `wptsall_` prefix makes the option plugin-owned and therefore
	 * syncable (core/functions.php wptsall_is_syncable_option), so no
	 * allowlist filter is needed.
	 *
	 * @param mixed $value Option value.
	 * @return string Option name.
	 */
	private function create_test_option( $value ) {
		$option_name = 'wptsall_client_option_' . uniqid();
		update_option( $option_name, $value, false );
		$this->test_option_names[] = $option_name;
		return $option_name;
	}

	/**
	 * Create and bind a test model to the current relation.
	 *
	 * @param array $model_data Model data override.
	 * @return int Model ID.
	 */
	private function create_and_bind_test_model( array $model_data = array() ) {
		self::ensure_rule_discovery_dependencies_loaded();

		$model_id = \WPTSALL\Models\Services\Translation_Rule_Service::create_model(
			wp_parse_args(
				$model_data,
				array(
					'plugin_slug' => 'client-option-discovery-' . uniqid(),
					'plugin_name' => 'Client Option Discovery Test',
					'is_system'   => true,
					'post_types'  => array(),
					'taxonomies'  => array(),
				)
			)
		);

		$this->assertTrue( is_numeric( $model_id ) );
		$this->assertGreaterThan( 0, (int) $model_id );
		$this->test_model_ids[] = (int) $model_id;
		\WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $this->test_relation_id, $model_id );
		return (int) $model_id;
	}

	/**
	 * Seed the template object/fields required by rule validation.
	 *
	 * @param int    $model_id    Model ID.
	 * @param string $object_type Object type.
	 * @param string $object_name Object name.
	 * @param array  $field_keys  Field keys.
	 * @return void
	 */
	private function seed_template_object( $model_id, $object_type, $object_name, array $field_keys ) {
		$object_id = \WPTSALL\Models\Services\Model_Object_Service::create_object(
			$model_id,
			$object_type,
			$object_name,
			array(
				'source_type' => 'manual',
				'metadata'    => array( 'name' => $object_name ),
			)
		);

		$this->assertTrue( is_numeric( $object_id ) );
		foreach ( $field_keys as $field_key ) {
			$field_id = \WPTSALL\Models\Services\Model_Object_Service::add_field(
				$object_id,
				'meta',
				$field_key,
				'manual',
				array()
			);
			$this->assertTrue( is_numeric( $field_id ) );
		}
	}

	/**
	 * Insert a test translation rule row directly.
	 *
	 * Option-backed rules are not yet creatable through Model_Object_Service,
	 * so tests seed the authoritative translation_rules row directly.
	 *
	 * @param int    $model_id           Model ID.
	 * @param string $data_type          Rule data type.
	 * @param string $object_name        Rule object name.
	 * @param array  $field_capabilities Field capabilities.
	 * @return int Rule ID.
	 */
	private function insert_test_rule_row( $model_id, $data_type, $object_name, array $field_capabilities ) {
		global $wpdb;

		$wpdb->insert(
			wptsall_table( 'translation_rules' ),
			array(
				'model_id'           => $model_id,
				'name'               => 'Test Option Rule ' . uniqid(),
				'url_pattern'        => '/test/' . $object_name . '/',
				'url_type'           => 'single',
				'data_type'          => $data_type,
				'object_name'        => $object_name,
				'field_capabilities' => wp_json_encode( $field_capabilities ),
				'related_taxonomies' => wp_json_encode( array() ),
				'is_active'          => 1,
				'priority'           => 10,
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		$this->assertTrue( ! empty( $wpdb->insert_id ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Test /client/content discovery exposes enabled option-backed items.
	 *
	 * Authoritative default-track behavior test for the option discovery
	 * contract (ISS A-2 cleanup): a relation-bound model with an enabled
	 * option rule surfaces the option as an item with object_type=option,
	 * a stable synthetic object_id, and complete_data carrying the
	 * authoritative option value. The migration-track copy in
	 * tests/modules/wpmmcc-ats/unit-plugin/ must stay in behavioral sync but
	 * never overrides this track's conclusions.
	 *
	 * catalog: WP-REST-wptsall-content
	 * oracle: L2
	 */
	public function test_get_content_discovers_option_backed_items_from_enabled_rules() {
		self::ensure_rule_discovery_dependencies_loaded();

		$option_name = $this->create_test_option(
			array(
				'email_subject' => 'Welcome Subject',
				'email_body'    => '<p>Welcome Body</p>',
			)
		);

		$model_id = $this->create_and_bind_test_model();
		$this->seed_template_object( $model_id, 'option', $option_name, array( 'email_subject', 'email_body' ) );
		$rule_id = $this->insert_test_rule_row(
			$model_id,
			'option',
			$option_name,
			array(
				'email_subject' => array(
					'type'           => 'translate',
					'direction'      => 'one_way',
					'enabled'        => true,
					'content_format' => 'plain_text',
					'storage'        => 'option_value',
				),
				'email_body'    => array(
					'type'           => 'translate',
					'direction'      => 'one_way',
					'enabled'        => true,
					'content_format' => 'rich_html',
					'storage'        => 'option_value',
				),
			)
		);
		$this->assertGreaterThan( 0, $rule_id );

		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/content' );
		$request->set_param( 'relation_id', $this->test_relation_id );
		$request->set_param( 'data_type', 'option' );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'page', 1 );

		$response = $this->controller->get_content( $request );

		$this->assertEquals( 200, $response->get_status(), 'option is a supported data_type on /client/content' );
		$data = $response->get_data();
		$this->assertIsArray( $data['items'] ?? null );

		$match = null;
		foreach ( ( $data['items'] ?? array() ) as $item ) {
			if ( ( $item['subtype'] ?? '' ) === $option_name ) {
				$match = $item;
				break;
			}
		}
		$this->assertIsArray( $match, "enabled option rule item {$option_name} missing from /client/content discovery" );
		$this->assertSame( 'option', $match['object_type'] );
		$this->assertGreaterThan( 0, (int) $match['object_id'], 'option items carry a stable synthetic object id' );
		$this->assertSame( $option_name, (string) ( $match['complete_data']['option_name'] ?? '' ) );
		$this->assertSame(
			'Welcome Subject',
			( $match['complete_data']['option_value']['email_subject'] ?? null ),
			'complete_data must carry the authoritative option value'
		);
	}

	/**
	 * Test /client/content/claim echoes option items without placeholder rows.
	 *
	 * Authoritative default-track behavior test for the option claim
	 * contract (ISS A-2 cleanup): option claims echo {object_id, post_type}
	 * items with success/claimed_count and create no placeholder
	 * post_mappings rows.
	 *
	 * catalog: WP-REST-wptsall-content-claim
	 * oracle: L2
	 */
	public function test_claim_content_accepts_option_items_without_placeholder_rows() {
		self::ensure_rule_discovery_dependencies_loaded();

		$option_name = $this->create_test_option(
			array(
				'email_subject' => 'Claim Subject',
			)
		);

		$model_id = $this->create_and_bind_test_model();
		$this->seed_template_object( $model_id, 'option', $option_name, array( 'email_subject' ) );
		$this->insert_test_rule_row(
			$model_id,
			'option',
			$option_name,
			array(
				'email_subject' => array(
					'type'           => 'translate',
					'direction'      => 'one_way',
					'enabled'        => true,
					'content_format' => 'plain_text',
					'storage'        => 'option_value',
				),
			)
		);

		$discovery_request = new WP_REST_Request( 'GET', '/wptsall/v2/client/content' );
		$discovery_request->set_param( 'relation_id', $this->test_relation_id );
		$discovery_request->set_param( 'data_type', 'option' );
		$discovery_request->set_param( 'per_page', 20 );
		$discovery_request->set_param( 'page', 1 );

		$discovery_response = $this->controller->get_content( $discovery_request );
		$this->assertEquals( 200, $discovery_response->get_status() );
		$discovery_data = $discovery_response->get_data();
		$this->assertIsArray( $discovery_data['items'] ?? null );

		$option_item = null;
		foreach ( ( $discovery_data['items'] ?? array() ) as $item ) {
			if ( ( $item['subtype'] ?? '' ) === $option_name ) {
				$option_item = $item;
				break;
			}
		}
		$this->assertIsArray( $option_item, "option {$option_name} missing from discovery before claim" );

		$claim_request = new WP_REST_Request( 'POST', '/wptsall/v2/client/content/claim' );
		$claim_request->set_header( 'Content-Type', 'application/json' );
		$claim_request->set_body( wp_json_encode( array(
			'relation_id' => $this->test_relation_id,
			'data_type'   => 'option',
			'items'       => array(
				array(
					'object_id' => (int) $option_item['object_id'],
					'subtype'   => $option_name,
				),
			),
		) ) );

		$claim_response = $this->controller->claim_content( $claim_request );
		$this->assertEquals( 200, $claim_response->get_status() );
		$claim_data = $claim_response->get_data();
		$this->assertTrue( (bool) ( $claim_data['success'] ?? false ) );
		$this->assertEquals( 1, (int) ( $claim_data['claimed_count'] ?? 0 ) );
		$this->assertIsArray( $claim_data['claimed_items'] ?? null );
		$this->assertEquals( (int) $option_item['object_id'], (int) ( $claim_data['claimed_items'][0]['object_id'] ?? 0 ) );
		$this->assertSame( $option_name, (string) ( $claim_data['claimed_items'][0]['post_type'] ?? '' ) );

		// The option claim path echoes items and must not create placeholder
		// post_mappings rows for the synthetic option object id.
		global $wpdb;
		$mappings_table = wptsall_table( 'post_mappings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$placeholder_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$mappings_table} WHERE relation_id = %d AND source_post_id = %d",
				$this->test_relation_id,
				(int) $option_item['object_id']
			)
		);
		$this->assertEquals( 0, $placeholder_rows, 'option claim must not create placeholder post_mappings rows' );
	}

	/**
	 * Site-string callback lane: entries and target_lang are required.
	 *
	 * Drives the real translation_callback dispatch with a site_strings
	 * body through to handle_site_string_callback's entry guard.
	 */
	public function test_translation_callback_site_strings_missing_entries() {
		$request = $this->build_callback_request( array(
			'business_line'  => 'site_strings',
			'relation_id'    => (int) $this->test_relation_id,
			'client_task_id' => 'test-site-strings-missing-' . uniqid(),
			'target_lang'    => 'en_US',
			'entries'        => array(),
		) );

		$response = $this->controller->translation_callback( $request );
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'missing_entries', $data['error'] );
	}

	/**
	 * Site-string callback lane: a relation without the lane enabled in
	 * its template config is rejected with subtype_not_allowed.
	 */
	public function test_translation_callback_site_strings_lane_not_allowed() {
		$request = $this->build_callback_request( array(
			'business_line'  => 'site_strings',
			'relation_id'    => (int) $this->test_relation_id,
			'client_task_id' => 'test-site-strings-lane-' . uniqid(),
			'target_lang'    => 'en_US',
			'entries'        => array( array( 'string_id' => 1, 'msgstr' => 'x' ) ),
		) );

		$response = $this->controller->translation_callback( $request );
		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'subtype_not_allowed', $data['error'] );
	}

	/**
	 * Site-string callback lane: with the lane enabled, a callback whose
	 * target language does not match the relation is rejected 409.
	 */
	public function test_translation_callback_site_strings_target_language_mismatch() {
		global $wpdb;

		// Provision a lane-enabled relation with a different target lang.
		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'test-client-data-sitestrings-' . uniqid(),
				'target_site_id'   => 'v_test_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'fr_FR',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$relation_id = (int) $wpdb->insert_id;

		try {
			if ( class_exists( '\WPTSALL\Sites\Services\Relation_Config_Service' ) ) {
				\WPTSALL\Sites\Services\Relation_Config_Service::save_template_config(
					$relation_id,
					array( 'translate_site_strings' => true )
				);
			}

			$request = $this->build_callback_request( array(
				'business_line'  => 'site_strings',
				'relation_id'    => $relation_id,
				'client_task_id' => 'test-site-strings-mismatch-' . uniqid(),
				'target_lang'    => 'en_US',
				'entries'        => array( array( 'string_id' => 1, 'msgstr' => 'x' ) ),
			) );

			$response = $this->controller->translation_callback( $request );
			$this->assertEquals( 409, $response->get_status() );
			$data = $response->get_data();
			$this->assertEquals( 'target_language_mismatch', $data['error'] );
		} finally {
			$wpdb->delete( $table, array( 'id' => $relation_id ), array( '%d' ) );
			$config_table = wptsall_table( 'relation_post_type_configs' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $config_table, array( 'relation_id' => $relation_id ), array( '%d' ) );
		}
	}

	/**
	 * wptsall_require_client_storage_map_contract (default true, second arg
	 * the WP_REST_Request): gates whether production enforces the storage/
	 * content-format contract. A listener can force-disable the requirement;
	 * when enabled the decision is still environment-gated
	 * (wp_get_environment_type() === 'production').
	 */
	public function test_require_client_storage_map_contract_filter_gate() {
		$request = new WP_REST_Request( 'GET', '/wptsall/v1/client/content' );
		$method  = new ReflectionMethod( $this->controller, 'should_require_storage_map_contract' );
		$method->setAccessible( true );

		$captured = array();
		$capture  = function ( $enabled, $req ) use ( &$captured ) {
			$captured[] = array( $enabled, $req );
			return $enabled;
		};
		add_filter( 'wptsall_require_client_storage_map_contract', $capture, 10, 2 );

		try {
			$default = $method->invoke( $this->controller, $request );
			$this->assertNotEmpty( $captured, 'the filter must be consulted for the contract decision' );
			$this->assertTrue( $captured[0][0], 'documented default is true' );
			$this->assertSame( $request, $captured[0][1], 'second arg must be the live WP_REST_Request' );
			$this->assertSame(
				'production' === wp_get_environment_type(),
				$default,
				'with the default (enabled) the requirement is production-env-gated (Lab env: ' . wp_get_environment_type() . ')'
			);

			add_filter( 'wptsall_require_client_storage_map_contract', '__return_false', 20 );
			$disabled = $method->invoke( $this->controller, $request );
			remove_filter( 'wptsall_require_client_storage_map_contract', '__return_false', 20 );
			$this->assertFalse( $disabled, 'a listener override of false must disable the requirement regardless of environment' );
		} finally {
			remove_filter( 'wptsall_require_client_storage_map_contract', $capture, 10 );
		}
	}

	/**
	 * M-04 (opus5): a successful content callback records the translated field
	 * pairs into translation memory against the source post text, so the same
	 * phrases become editor suggestions on the next pass.
	 */
	public function test_translation_callback_records_translation_memory() {
		self::ensure_content_change_module_loaded();

		if ( ! function_exists( 'wptsall_create_translation_memory_table' ) ) {
			require_once self::get_plugin_root_path() . 'includes/translation-memory/database/schema-translation-memory.php';
		}
		wptsall_create_translation_memory_table();
		delete_option( \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::OPTION_AUTO_RECORD );

		$client_task_id = 'test-content-' . uniqid();
		$post_id        = $this->test_post_ids[0];
		$source_post    = get_post( $post_id );
		$source_title   = (string) $source_post->post_title;

		// Clean any residue of earlier callback tests for this exact pair.
		global $wpdb;
		$tm_table = $wpdb->prefix . 'wptsall_translation_memory';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $tm_table, array( 'source_text' => $source_title ) );

		$claim_response = $this->claim_post_for_callback( $post_id );
		$this->assertEquals( 200, $claim_response->get_status(), 'claim_content response: ' . wp_json_encode( $claim_response->get_data() ) );

		$expected_target_title = '【en_US】' . $source_title . '【/en_US】';
		$request = $this->build_callback_request( array(
			'business_line'     => 'post_content',
			'client_task_id'    => $client_task_id,
			'relation_id'       => $this->test_relation_id,
			'object_type'       => 'post_type',
			'object_id'         => $post_id,
			'post_type'         => 'post',
			'source_lang'       => 'zh_CN',
			'target_lang'       => 'en_US',
			'source_revision'   => \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id ),
			'policy_version'    => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
			'translated_fields' => array(
				'post_title' => $expected_target_title,
			),
			'translated_meta'   => array(),
			'media_mappings'    => array(),
		) );

		$response = $this->controller->translation_callback( $request );
		$this->assertEquals( 200, $response->get_status(), 'Callback must succeed before TM assertions' );
		$this->assertTrue( $response->get_data()['success'], 'Callback response must indicate success' );

		$tm_target = \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::lookup( $source_title, 'zh_CN', 'en_US' );
		$this->assertSame( $expected_target_title, $tm_target, 'callback success must record the title pair into translation memory' );

		// The recorded pair is scoped to the relation domain, so the editor
		// suggestion lookup resolves it like a curated entry.
		$suggestions = \WPTSALL\TranslationMemory\Services\Translation_Memory_Service::suggest_for_relation_domain( $this->test_relation_id, 'wordpress-blog', $source_title );
		$this->assertCount( 1, $suggestions, 'suggestion lookup must resolve the auto-recorded pair' );
		$this->assertSame( $expected_target_title, $suggestions[0]['target_text'] );
		$this->assertSame( 'post_title', $suggestions[0]['context'] );

		// Cleanup: this pair's source text is shared with other callback tests.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $tm_table, array( 'source_text' => $source_title ) );
	}

	/**
	 * A-04 (opus5, D-3i): the content callback carries a numeric payload
	 * schema_version; an unsupported version is rejected fail-closed with
	 * 400 before any persistence work.
	 */
	public function test_translation_callback_rejects_unsupported_schema_version() {
		self::ensure_content_change_module_loaded();

		$post_id = $this->test_post_ids[0];
		$this->claim_post_for_callback( $post_id );

		$request = $this->build_callback_request( array(
			'business_line'     => 'post_content',
			'client_task_id'    => 'test-content-' . uniqid(),
			'relation_id'       => $this->test_relation_id,
			'object_type'       => 'post_type',
			'object_id'         => $post_id,
			'post_type'         => 'post',
			'source_lang'       => 'zh_CN',
			'target_lang'       => 'en_US',
			'schema_version'    => 99,
			'translated_fields' => array(
				'post_title' => 'should never be written',
			),
			'translated_meta'   => array(),
			'media_mappings'    => array(),
		) );

		$response = $this->controller->translation_callback( $request );

		$this->assertEquals( 400, $response->get_status(), 'Unknown payload schema_version must fail closed' );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertEquals( 'unsupported_callback_schema_version', $response->get_data()['error'] );
		$this->assertStringContainsString( 'need 2', (string) $response->get_data()['message'] );
	}

	/**
	 * A-04 (opus5, D-3i): a supported payload schema_version passes the gate.
	 */
	public function test_translation_callback_accepts_supported_schema_version() {
		self::ensure_content_change_module_loaded();

		$client_task_id = 'test-content-' . uniqid();
		$post_id        = $this->test_post_ids[0];
		$source_revision = \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $post_id );
		$policy_version  = \WPTSALL\Core\Job_Snapshot::current_policy_version();

		$claim_response = $this->claim_post_for_callback( $post_id );
		$this->assertEquals( 200, $claim_response->get_status() );

		$request = $this->build_callback_request( array(
			'business_line'     => 'post_content',
			'client_task_id'    => $client_task_id,
			'relation_id'       => $this->test_relation_id,
			'object_type'       => 'post_type',
			'object_id'         => $post_id,
			'post_type'         => 'post',
			'source_lang'       => 'zh_CN',
			'target_lang'       => 'en_US',
			'source_revision'   => $source_revision,
			'policy_version'    => $policy_version,
			'schema_version'    => 2,
			'translated_fields' => array(
				'post_title'   => '【en_US】Schema Version Accept Test【/en_US】',
				'post_content' => '【en_US】schema version accepted content【/en_US】',
			),
			'translated_meta'   => array(),
			'media_mappings'    => array(),
		) );

		$response = $this->controller->translation_callback( $request );

		$this->assertEquals( 200, $response->get_status(), 'Supported payload schema_version must pass the gate' );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertGreaterThan( 0, $response->get_data()['result_id'] );
	}

}

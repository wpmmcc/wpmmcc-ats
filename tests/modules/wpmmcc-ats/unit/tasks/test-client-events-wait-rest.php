<?php
/**
 * Client Events Wait REST Tests (批 Q — 事件驱动)
 *
 * GET /{secret}/client/events/wait long-poll gate and the
 * Content_Change_Dispatcher::has_claimable_outbox() peek it rides:
 *
 * - ready-early: a claimable row returns events_ready=true immediately;
 * - clean timeout: no rows → events_ready=false after ~wait_seconds;
 * - peek purity: the wait NEVER mutates the outbox (status/claimed_at/
 *   attempts untouched — leasing stays in claim_outbox via content-changes);
 * - relation filter: rows for another relation never wake this one;
 * - backoff respect: a pending row whose available_at is in the future
 *   (ack'd retry backoff) is NOT claimable — the waiter must not hot-loop
 *   on it;
 * - dead lease: a processing row past the 900s lease window is claimable
 *   (mirrors claim_outbox's candidate predicate);
 * - auth: no client token → 401 (same permission gate as the family).
 *
 * 框架注记：本框架的 tearDown 仅在用例成功路径执行（失败用例跳过），
 * 且同一对象跨用例复用——故每个用例自建关系、不依赖 setUp 产出的
 * 共享状态，失败也零泄漏。
 *
 * catalog: WP-REST-wptsall-events-wait
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.3.0
 */

class Test_Client_Events_Wait_REST extends WP_UnitTestCase {

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
	 * Ensure tasks module files are loaded (localhost does not auto-load).
	 */
	private static function ensure_tasks_module_loaded() {
		if ( function_exists( 'wptsall_ensure_task_table' ) ) {
			return;
		}
		$plugin_root = self::get_plugin_root_path();
		$tasks_path  = $plugin_root . 'includes/tasks/';

		require_once $tasks_path . 'database/schema-tasks.php';
		require_once $tasks_path . 'database/schema-translation-results.php';
		if ( ! class_exists( '\\WPTSALL\\Tasks\\Services\\Origin_Visit_Service' ) ) {
			require_once $tasks_path . 'services/class-origin-visit-service.php';
		}
		require_once $tasks_path . 'tasks.php';
		require_once $tasks_path . 'tasks-single.php';
		require_once $tasks_path . 'api/class-client-data-rest-controller.php';
	}

	/**
	 * Ensure lifecycle outbox module is loaded and its table exists.
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
	 * Create an active relation for filter tests.
	 *
	 * @return int Relation ID.
	 */
	private function create_relation() {
		global $wpdb;
		$table = wptsall_table( 'site_relations' );
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => 'test-events-wait-' . uniqid(),
				'target_site_id'   => 'v_wait_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert an outbox row with full control over lease/backoff fields.
	 *
	 * @param int    $relation_id  Relation ID.
	 * @param int    $post_id      Source post ID.
	 * @param string $status       Row status.
	 * @param string $available_at GMT availability timestamp.
	 * @param string $claimed_at   GMT claim timestamp (empty = NULL).
	 * @param int    $attempts     Attempt count.
	 * @return int Outbox row ID.
	 */
	private function insert_outbox_row( $relation_id, $post_id, $status = 'pending', $available_at = null, $claimed_at = null, $attempts = 0 ) {
		global $wpdb;
		$outbox_table = wptsall_table( 'content_change_outbox' );
		$now          = current_time( 'mysql', true );
		$event_key    = hash( 'sha256', 'test-events-wait-' . uniqid( '', true ) . '-' . (int) $relation_id . '-' . (int) $post_id );
		$wpdb->insert(
			$outbox_table,
			array(
				'event_key'      => $event_key,
				'source_type'    => 'post',
				'source_id'      => (int) $post_id,
				'source_site_id' => get_current_blog_id(),
				'relation_id'    => (int) $relation_id,
				'event_name'     => 'post_updated',
				'payload'        => wp_json_encode( array( 'post_type' => 'post' ) ),
				'status'         => $status,
				'attempts'       => (int) $attempts,
				'available_at'   => null === $available_at ? $now : $available_at,
				'claimed_at'     => $claimed_at,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Build a WP_REST_Request for wait_for_events.
	 *
	 * @param array $params Query params.
	 * @param bool  $with_protocol_header Send the v2 protocol header.
	 * @return \WP_REST_Request
	 */
	private function make_wait_request( $params = array(), $with_protocol_header = true ) {
		$request = new \WP_REST_Request( 'GET', '/wptsall/v2/client/events/wait' );
		if ( $with_protocol_header ) {
			$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Remove state left by earlier runs of this class (plugin tables are
	 * outside the core unit-test rollback set): my template-prefixed
	 * relations plus any outbox rows referencing them.
	 */
	private function clean_own_state() {
		global $wpdb;
		$relations = wptsall_table( 'site_relations' );
		$outbox    = wptsall_table( 'content_change_outbox' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE relation_id IN ( SELECT id FROM %i WHERE template LIKE %s )",
				$outbox,
				$relations,
				'test-events-wait-%'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE template LIKE %s', $relations, 'test-events-wait-%' )
		);
	}

	/**
	 * Set up test environment (module loading only — each test creates its
	 * own relations/posts so a failed test leaks nothing into the next).
	 */
	public function setUp(): void {
		parent::setUp();

		self::ensure_tasks_module_loaded();
		self::ensure_content_change_module_loaded();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->controller = new \WPTSALL\Tasks\API\Client_Data_REST_Controller();

		wptsall_ensure_task_table();
		wptsall_create_translation_results_table();
		wptsall_create_content_change_outbox_table();

		$this->clean_own_state();
	}

	/**
	 * Tear down: remove this class's rows so later suites see pristine state.
	 */
	public function tearDown(): void {
		$this->clean_own_state();
		parent::tearDown();
	}

	/**
	 * A claimable row returns events_ready=true immediately.
	 *
	 * Relation-filtered: the outbox is shared with real dispatcher writes
	 * (factory post creation enqueues on any active relation), so an
	 * unfiltered positive test could pass for a foreign row.
	 */
	public function test_wait_returns_ready_immediately_when_claimable() {
		$post_id     = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$relation_id = $this->create_relation();
		$this->insert_outbox_row( $relation_id, $post_id );

		$started  = microtime( true );
		$response = $this->controller->wait_for_events( $this->make_wait_request( array( 'wait_seconds' => 5, 'relation_id' => $relation_id ) ) );
		$elapsed  = microtime( true ) - $started;
		$data     = $this->get_response_data( $response );

		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['data']['events_ready'] );
		$this->assertTrue( $elapsed < 2.0, 'ready path must return without waiting out the window' );
		$this->assertTrue( $data['data']['waited_ms'] < 2000, 'waited_ms must stay small on the ready path' );
	}

	/**
	 * No claimable rows → clean timeout shape after ~wait_seconds.
	 *
	 * Relation-filtered for the same isolation reason (an unfiltered peek
	 * observes the whole shared outbox, including foreign leftovers).
	 */
	public function test_wait_times_out_with_clean_shape_when_nothing_claimable() {
		$post_id     = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$relation_id = $this->create_relation();
		$started     = microtime( true );
		$response    = $this->controller->wait_for_events( $this->make_wait_request( array( 'wait_seconds' => 1, 'relation_id' => $relation_id ) ) );
		$elapsed     = microtime( true ) - $started;
		$data        = $this->get_response_data( $response );

		$this->assertTrue( $data['success'] );
		$this->assertFalse( $data['data']['events_ready'] );
		$this->assertGreaterThanOrEqual( 0.8, $elapsed, 'must honor the wait window before giving up' );
		$this->assertTrue( $elapsed <= 3.0, 'must not overshoot the window materially' );
		unset( $post_id );
	}

	/**
	 * The wait peek never mutates the outbox row.
	 */
	public function test_wait_peek_does_not_mutate_the_outbox() {
		global $wpdb;
		$post_id     = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$relation_id = $this->create_relation();
		$row_id      = $this->insert_outbox_row( $relation_id, $post_id, 'pending', null, null, 2 );

		$this->controller->wait_for_events( $this->make_wait_request( array( 'wait_seconds' => 1 ) ) );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, attempts, claimed_at FROM %i WHERE id = %d', wptsall_table( 'content_change_outbox' ), $row_id ), ARRAY_A );
		$this->assertSame( 'pending', $row['status'], 'peek must not lease' );
		$this->assertNull( $row['claimed_at'], 'peek must not stamp a claim' );
		$this->assertSame( 2, (int) $row['attempts'], 'peek must not bump attempts' );
	}

	/**
	 * Rows for another relation never wake this relation's wait.
	 */
	public function test_wait_respects_relation_filter() {
		$post_id    = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$relation_a = $this->create_relation();
		$relation_b = $this->create_relation();
		$this->insert_outbox_row( $relation_b, $post_id );

		// relation_a has no rows: its filtered wait must time out.
		$data = $this->get_response_data(
			$this->controller->wait_for_events( $this->make_wait_request( array( 'wait_seconds' => 1, 'relation_id' => $relation_a ) ) )
		);
		$this->assertFalse( $data['data']['events_ready'] );

		// relation_b has the row: its filtered wait must return ready.
		$data = $this->get_response_data(
			$this->controller->wait_for_events( $this->make_wait_request( array( 'wait_seconds' => 5, 'relation_id' => $relation_b ) ) )
		);
		$this->assertTrue( $data['data']['events_ready'] );
	}

	/**
	 * A pending row under available_at backoff is not claimable — the peek
	 * must respect the retry window so the waiter never hot-loops on it.
	 */
	public function test_peek_respects_available_at_backoff() {
		$post_id     = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$relation_id = $this->create_relation();
		$future      = gmdate( 'Y-m-d H:i:s', time() + 60 );
		$this->insert_outbox_row( $relation_id, $post_id, 'pending', $future );

		$data = $this->get_response_data(
			$this->controller->wait_for_events( $this->make_wait_request( array( 'wait_seconds' => 1, 'relation_id' => $relation_id ) ) )
		);
		$this->assertFalse( $data['data']['events_ready'], 'a backoff row must not be reported ready' );
		$this->assertFalse( \WPTSALL\Hooks\Content_Change_Dispatcher::has_claimable_outbox( $relation_id ) );
	}

	/**
	 * A processing row past the 900s lease window is claimable (dead lease),
	 * mirroring claim_outbox's candidate predicate.
	 */
	public function test_peek_sees_dead_lease_as_claimable() {
		$post_id     = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$relation_id = $this->create_relation();
		$stale       = gmdate( 'Y-m-d H:i:s', time() - 1000 );
		$this->insert_outbox_row( $relation_id, $post_id, 'processing', null, $stale );

		$this->assertTrue( \WPTSALL\Hooks\Content_Change_Dispatcher::has_claimable_outbox( $relation_id ) );
		$data = $this->get_response_data(
			$this->controller->wait_for_events( $this->make_wait_request( array( 'wait_seconds' => 2, 'relation_id' => $relation_id ) ) )
		);
		$this->assertTrue( $data['data']['events_ready'] );
	}

	/**
	 * A processing row inside its lease is not claimable.
	 */
	public function test_peek_ignores_live_lease() {
		$post_id     = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$relation_id = $this->create_relation();
		$fresh       = gmdate( 'Y-m-d H:i:s', time() - 10 );
		$this->insert_outbox_row( $relation_id, $post_id, 'processing', null, $fresh );

		$this->assertFalse( \WPTSALL\Hooks\Content_Change_Dispatcher::has_claimable_outbox( $relation_id ), 'a leased row must not be claimable' );
	}

	/**
	 * The route keeps the family permission gate: no client token → 401.
	 */
	public function test_wait_requires_client_token() {
		$request = $this->make_wait_request();
		$result  = $this->controller->check_client_permission( $request );
		$this->assertInstanceOf( 'WP_Error', $result, 'unauthenticated request must be rejected' );
		$this->assertSame( 'client_unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Normalize REST response payloads for direct controller calls.
	 *
	 * @param \WP_REST_Response|\WP_Error $response Response.
	 * @return array
	 */
	private function get_response_data( $response ) {
		$this->assertFalse( is_wp_error( $response ), 'wait must return a REST response, not an error' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'data', $data );
		return $data;
	}
}

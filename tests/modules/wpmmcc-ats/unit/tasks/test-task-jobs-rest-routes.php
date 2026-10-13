<?php
/**
 * Task Jobs REST Route Tests (G-14)
 *
 * REST-dispatch coverage for the four job route families that had no
 * dedicated unit coverage:
 *
 * - GET  /tasks/jobs                                  (get_task_jobs)
 * - POST /tasks/jobs                                  (create_task_job)
 * - GET  /tasks/jobs/{job_id}/tasks                   (get_tasks_by_job)
 * - POST /tasks/jobs/{job_id}/retry-failed-subtasks   (retry_failed_subtasks_by_job)
 *
 * Follows the fixture/assert style of test-rest-admin-matrix.php: real
 * WP_REST_Server dispatch, admin permission positive+negative, seeded
 * task/relation rows with direct cleanup.
 *
 * catalog: WP-REST-wptsall-tasks-jobs
 * oracle: L1
 *
 * @package WPTSALL\Tests\Unit
 * @since 2.2.0
 */

class Test_Task_Jobs_Rest_Routes extends WP_UnitTestCase {

	/**
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * @var int
	 */
	protected $admin_id = 0;

	/**
	 * @var array<int, int> Task row ids created by this run.
	 */
	private $task_ids = array();

	/**
	 * @var array<int, int> Site relation row ids created by this run.
	 */
	private $relation_ids = array();

	/**
	 * @var array<int, string> Job ids whose task_jobs aggregate rows need cleanup.
	 */
	private $job_ids = array();

	/**
	 * @var array<int, int> Model ids created by this run.
	 */
	private $model_ids = array();

	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Schema bootstrap (idempotent), matching the runner's own guarantees.
		if ( function_exists( 'wptsall_ensure_task_table' ) ) {
			wptsall_ensure_task_table();
		}
		if ( function_exists( 'wptsall_create_task_jobs_table' ) ) {
			wptsall_create_task_jobs_table();
		}
	}

	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		$tasks_table = wptsall_table( 'tasks' );
		foreach ( $this->task_ids as $task_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $tasks_table, array( 'id' => $task_id ), array( '%d' ) );
		}
		$this->task_ids = array();

		$relations_table = wptsall_table( 'site_relations' );
		foreach ( $this->relation_ids as $relation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $relations_table, array( 'id' => $relation_id ), array( '%d' ) );
			// Relation-model bindings seeded by seed_model_for_relation.
			if ( function_exists( 'wptsall_create_relation_models_table' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( wptsall_table( 'relation_models' ), array( 'relation_id' => $relation_id ), array( '%d' ) );
			}
		}
		$this->relation_ids = array();

		$jobs_table = wptsall_table( 'task_jobs' );
		foreach ( $this->job_ids as $job_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $jobs_table, array( 'job_id' => $job_id ), array( '%s' ) );
		}
		$this->job_ids = array();

		// Clean up created models (unset is_system so the service accepts
		// deletion, mirroring test-client-data-rest-controller.php).
		if ( $this->model_ids && class_exists( '\WPTSALL\Models\Services\Translation_Rule_Service' ) ) {
			foreach ( $this->model_ids as $model_id ) {
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
		$this->model_ids = array();

		parent::tearDown();
	}

	private function dispatch( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Seed a task row carrying the given job_id in its payload (the canonical
	 * job-membership record used by task_job_exists/get_tasks_by_job).
	 */
	private function seed_job_task( string $job_id, string $status ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			wptsall_table( 'tasks' ),
			array(
				'blog_id'     => 1,
				'target_blog' => 0,
				'site_id'     => 0,
				'template'    => 'zz_jobs_' . uniqid(),
				'object_type' => 'post_type',
				'subtype'     => 'post',
				'object_id'   => 900000 + (int) crc32( uniqid( '', true ) ),
				'status'      => $status,
				'payload'     => wp_json_encode( array( 'job_id' => $job_id ) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$this->assertNotEmpty( $wpdb->insert_id );
		$this->task_ids[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	/**
	 * Seed a task_jobs aggregate row (the table the /tasks/jobs list reads).
	 */
	private function seed_job_aggregate( string $job_id, string $status = 'running' ): void {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			wptsall_table( 'task_jobs' ),
			array(
				'job_id'          => $job_id,
				'status'          => $status,
				'progress'        => 0,
				'task_total'      => 1,
				'pending_count'   => 1,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%f', '%d', '%d', '%s', '%s' )
		);
		$this->assertNotEmpty( $wpdb->insert_id );
	}

	private function seed_relation( string $template = '' ): array {
		global $wpdb;
		$now      = current_time( 'mysql' );
		$template = '' !== $template ? $template : 'zz-jobs-' . uniqid();
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => $template,
				'target_site_id'   => 'v_jobs_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertNotEmpty( $wpdb->insert_id );
		$relation_id          = (int) $wpdb->insert_id;
		$this->relation_ids[] = $relation_id;
		return array( $relation_id, $template );
	}

	/**
	 * Create a model whose plugin_slug matches the relation template, bind it
	 * to the relation and seed one enabled translation rule, so task creation
	 * validation (relation template -> model -> rules) passes.
	 */
	private function seed_model_for_relation( int $relation_id, string $template ): int {
		global $wpdb;

		$model_id = \WPTSALL\Models\Services\Translation_Rule_Service::create_model(
			array(
				'plugin_slug' => $template,
				'plugin_name'  => 'G-14 jobs test model',
				'is_system'    => true,
				'post_types'   => array(),
				'taxonomies'   => array(),
			)
		);
		$this->assertTrue( is_numeric( $model_id ) );
		$this->assertGreaterThan( 0, (int) $model_id );
		$this->model_ids[] = (int) $model_id;

		\WPTSALL\Sites\Services\Relation_Model_Service::add_model_to_relation( $relation_id, (int) $model_id );

		// Seed the authoritative translation_rules row directly (option-backed
		// rules are not creatable via Model_Object_Service).
		$wpdb->insert(
			wptsall_table( 'translation_rules' ),
			array(
				'model_id'           => (int) $model_id,
				'name'               => 'G-14 rule ' . uniqid(),
				'url_pattern'        => '/test/jobs/',
				'url_type'           => 'single',
				'data_type'          => 'option',
				'object_name'        => 'g14_jobs_option_' . uniqid(),
				'field_capabilities' => wp_json_encode( array(
					'subject' => array(
						'type'    => 'translate',
						'enabled' => true,
					),
				) ),
				'related_taxonomies' => wp_json_encode( array() ),
				'is_active'          => 1,
				'priority'           => 10,
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			)
		);
		$this->assertNotEmpty( $wpdb->insert_id );

		return (int) $model_id;
	}

	// ========================================
	// GET /tasks/jobs (get_task_jobs)
	// ========================================

	public function test_get_task_jobs_list_route() {
		// Non-admin (no manage_wptsall_translations) is forbidden.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$response = $this->dispatch( 'GET', '/wptsall/v2/tasks/jobs' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );

		// Admin happy path: aggregate list shape.
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( 'GET', '/wptsall/v2/tasks/jobs' );
		$this->assertSame( 200, $response->get_status(), 'jobs list must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertIsArray( $data['items'] );
		$this->assertIsInt( (int) $data['total'] );
		$this->assertIsInt( (int) $data['pages'] );
		$this->assertIsInt( (int) $data['page'] );

		// A seeded job aggregate surfaces in the list (job_id filter). The
		// list reads the task_jobs aggregate table, not task payload rows.
		$job_id          = 'job_list_' . uniqid();
		$this->job_ids[] = $job_id;
		$this->seed_job_aggregate( $job_id );
		$this->seed_job_task( $job_id, 'pending' );

		$response = $this->dispatch( 'GET', '/wptsall/v2/tasks/jobs', array( 'job_id' => $job_id ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, (int) $data['total'], 'filtered list must find the seeded job aggregate' );
		$this->assertSame( $job_id, $data['items'][0]['job_id'] );
		$this->assertIsInt( (int) $data['items'][0]['total'] );
		$this->assertArrayHasKey( 'counts', $data['items'][0] );
	}

	// ========================================
	// POST /tasks/jobs (create_task_job)
	// ========================================

	public function test_create_task_job_route_validation() {
		// relation_id <= 0 is rejected before anything else.
		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/jobs', array( 'relation_id' => 0 ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_relation_id', $response->get_data()['code'] );

		// Unknown relation: rejected at the permission boundary, which
		// validates every relation_id as a relation object.
		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/jobs', array(
			'relation_id' => 999999999,
		) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_object_not_found', $response->get_data()['code'] );

		// Scope validation: both content and language pack disabled (the
		// relation must exist so the permission boundary passes).
		list( $relation_id ) = $this->seed_relation();
		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/jobs', array(
			'relation_id'            => $relation_id,
			'include_content'        => false,
			'include_language_pack'  => false,
		) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_scope', $response->get_data()['code'] );

		// Permission negative: editor is forbidden.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/jobs', array( 'relation_id' => $relation_id ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		wp_set_current_user( $this->admin_id );
	}

	public function test_create_task_job_route_preview_happy_path() {
		list( $relation_id, $template ) = $this->seed_relation();
		$this->seed_model_for_relation( $relation_id, $template );

		// Preview mode plans without inserting task rows.
		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/jobs', array(
			'relation_id'     => $relation_id,
			'include_content' => true,
			'preview'         => true,
		) );
		$this->assertSame( 200, $response->get_status(), 'preview must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertTrue( (bool) $data['data']['preview'] );
		$this->assertSame( $relation_id, (int) $data['data']['relation_id'] );
		$this->assertNotSame( '', (string) $data['data']['job_id'], 'a job_id must be assigned' );
		$this->assertIsInt( (int) $data['data']['total_tasks'] );
		$this->assertSame( 0, (int) $data['data']['inserted_count'], 'preview must not insert tasks' );
	}

	// ========================================
	// GET /tasks/jobs/{job_id}/tasks (get_tasks_by_job)
	// ========================================

	public function test_get_tasks_by_job_route() {
		// Unknown job: rejected at the permission boundary (object check).
		$response = $this->dispatch( 'GET', '/wptsall/v2/tasks/jobs/job_unknown_' . uniqid() . '/tasks' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_object_not_found', $response->get_data()['code'] );

		// Seed a job with two member tasks.
		$job_id          = 'job_tasks_' . uniqid();
		$this->job_ids[] = $job_id;
		$first_id  = $this->seed_job_task( $job_id, 'pending' );
		$second_id = $this->seed_job_task( $job_id, 'processing' );

		// Permission negative with an existing job: editor is forbidden.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$response = $this->dispatch( 'GET', '/wptsall/v2/tasks/jobs/' . $job_id . '/tasks' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		wp_set_current_user( $this->admin_id );

		// Admin happy path: both members listed with the summary shape.
		$response = $this->dispatch( 'GET', '/wptsall/v2/tasks/jobs/' . $job_id . '/tasks' );
		$this->assertSame( 200, $response->get_status(), 'job task list must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( $job_id, $data['job_id'] );
		$this->assertSame( 2, (int) $data['total'] );
		$this->assertArrayHasKey( 'summary', $data );
		$this->assertArrayHasKey( 'status', $data['summary'] );
		$listed_ids = array_map( 'intval', wp_list_pluck( $data['items'], 'id' ) );
		$this->assertContains( $first_id, $listed_ids );
		$this->assertContains( $second_id, $listed_ids );

		// Status filter narrows the listing.
		$response = $this->dispatch( 'GET', '/wptsall/v2/tasks/jobs/' . $job_id . '/tasks', array( 'status' => 'processing' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) $response->get_data()['total'] );
		$this->assertSame( $second_id, (int) $response->get_data()['items'][0]['id'] );
	}

	// ========================================
	// POST /tasks/jobs/{job_id}/retry-failed-subtasks
	// ========================================

	public function test_retry_failed_subtasks_by_job_route() {
		// Unknown job: rejected at the permission boundary (object check).
		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/jobs/job_unknown_' . uniqid() . '/retry-failed-subtasks' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_object_not_found', $response->get_data()['code'] );

		// Seed a job with a failed task that has no failed-subtask fragments:
		// the retry completes with zero updates (happy-path shape, no mutation).
		$job_id          = 'job_retry_' . uniqid();
		$this->job_ids[] = $job_id;
		$this->seed_job_task( $job_id, 'failed' );

		// Permission negative with an existing job: editor is forbidden.
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/jobs/' . $job_id . '/retry-failed-subtasks' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/wptsall/v2/tasks/jobs/' . $job_id . '/retry-failed-subtasks', array(
			'note' => 'G-14 route dispatch test',
		) );
		$this->assertSame( 200, $response->get_status(), 'job retry must succeed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( (bool) $data['success'] );
		$this->assertSame( $job_id, $data['data']['job_id'] );
		$this->assertSame( 0, (int) $data['data']['updated_tasks'], 'no failed subtask fragments were seeded' );
		$this->assertSame( 0, (int) $data['data']['updated_subtasks'] );
		$this->assertIsArray( $data['data']['updated_task_ids'] );
	}
}

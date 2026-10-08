<?php
/**
 * Manual Queue REST controller tests (opus5 A-01 / T-02)
 *
 * Exercises Manual_Queue_REST_Controller handlers directly (list with
 * counts, apply, reject) plus the 401/403 permission semantics. Before
 * 2.1.4 no test covered any manual-queue surface at all.
 *
 * @package WPTSALL
 * @since 2.1.4
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Tasks\API\Manual_Queue_REST_Controller;
use WPTSALL\Tasks\Sync\Manual_Queue;
use WPTSALL\Tasks\Sync\Write_Back_Adapter_Interface;
use WPTSALL\Tasks\Sync\Write_Back_Dispatcher;

/**
 * Test_Manual_Queue_REST
 */
class Test_Manual_Queue_REST extends SimpleTestCase {

	/**
	 * Queue row IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_queue_ids = array();

	/**
	 * Test relation IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_relation_ids = array();

	/**
	 * Test user IDs created during tests (for cleanup).
	 *
	 * @var array
	 */
	private $test_user_ids = array();

	public function setUp(): void {
		parent::setUp();
		// Load REST controllers (CLI does not fire rest_api_init on its own;
		// same pattern as Test_Tasks_REST_Controller::setUp).
		do_action( 'rest_api_init' );
		if ( function_exists( 'wptsall_create_manual_queue_table' ) ) {
			wptsall_create_manual_queue_table();
		}
		wp_cache_flush();
	}

	public function tearDown(): void {
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );

		foreach ( $this->test_queue_ids as $qid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => (int) $qid ), array( '%d' ) );
		}
		$this->test_queue_ids = array();

		foreach ( $this->test_relation_ids as $rid ) {
			Site_Relation_Service::delete_relation( (int) $rid );
		}
		$this->test_relation_ids = array();

		foreach ( $this->test_user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->test_user_ids = array();

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Helper: enqueue a synthetic queue item.
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $entity_type Entity type.
	 * @return int Queue item ID.
	 */
	private function enqueue_item( $relation_id, $entity_type ) {
		$id = Manual_Queue::enqueue(
			array(
				'entity_type'    => $entity_type,
				'source_id'       => 5,
				'translated_ref' => array( 'ref_type' => 'id', 'ref_value' => 'x', 'source_ref' => 'x' ),
				'metadata'       => array( 'k' => 'v' ),
			),
			array(
				'relation_id' => (int) $relation_id,
				'task_id'     => 0,
				'source_blog' => get_current_blog_id(),
				'target_blog' => get_current_blog_id(),
				'adapter'     => 'none',
			),
			'unit rest reason'
		);
		$this->assertGreaterThan( 0, (int) $id, 'enqueue must return a queue item id' );
		$this->test_queue_ids[] = (int) $id;
		return (int) $id;
	}

	/**
	 * Helper: create a test relation (virtual site target).
	 *
	 * @return int|null Relation ID or null on failure.
	 */
	private function create_test_relation() {
		$result = Site_Relation_Service::create_relation( array(
			'template'       => 'wordpress-blog',
			'source_site_id' => get_current_blog_id(),
			'source_lang'    => 'en',
			'target_sites'   => array(
				array(
					'id'   => 'v_test_mqr_' . uniqid(),
					'type' => 'virtual',
					'lang' => 'zh_CN',
				),
			),
		) );

		if ( ! empty( $result['success'] ) && ! empty( $result['relation_ids'] ) ) {
			$this->test_relation_ids = array_merge( $this->test_relation_ids, $result['relation_ids'] );
			return (int) $result['relation_ids'][0];
		}
		return null;
	}

	/**
	 * Helper: create a logged-in administrator with the translations cap.
	 *
	 * @return int User ID.
	 */
	private function create_admin_user() {
		$username = 'mqradmin_' . uniqid();
		$user_id  = wp_create_user( $username, 'pass-' . uniqid(), $username . '@test.example' );
		$user     = get_userdata( $user_id );
		$user->set_role( 'administrator' );
		$user->add_cap( 'manage_wptsall_translations' );
		wp_set_current_user( $user_id );
		$this->test_user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * REST list returns items with decoded payloads and status counts.
	 */
	public function test_rest_list_returns_items_and_counts() {
		$this->create_admin_user();
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$before = Manual_Queue::count_pending();
		$this->enqueue_item( $relation_id, 'image' );
		$this->enqueue_item( $relation_id, 'video' );

		$controller = new Manual_Queue_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/wptsall/v2/manual-queue' );
		$request->set_param( 'status', 'pending' );
		$request->set_param( 'relation_id', $relation_id );

		$response = $controller->get_items( $request );
		$data     = $response->get_data();

		$this->assertCount( 2, $data['items'], 'both enqueued rows must be listed for the relation' );
		foreach ( $data['items'] as $item ) {
			$this->assertIsArray( $item['payload'], 'REST list must decode the payload JSON' );
		}
		$this->assertGreaterThanOrEqual( $before + 2, (int) $data['counts']['pending'], 'counts.pending must reflect the enqueued rows' );
		foreach ( array( 'pending', 'reviewing', 'applied', 'rejected', 'expired' ) as $status ) {
			$this->assertArrayHasKey( $status, $data['counts'], "counts must carry the {$status} key" );
		}
	}

	/**
	 * REST apply re-dispatches through the adapter seam and marks the row.
	 */
	public function test_rest_apply_marks_item_applied() {
		$this->create_admin_user();
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		Write_Back_Dispatcher::register_adapter( new MQR_Test_Write_Back_Adapter() );
		$qid = $this->enqueue_item( $relation_id, 'mqr_fake' );

		$controller = new Manual_Queue_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/wptsall/v2/manual-queue/' . $qid . '/apply' );
		$request->set_param( 'id', $qid );

		$response = $controller->apply_item( $request );
		$data     = $response->get_data();

		$this->assertSame( 'applied', $data['status'], 'REST apply must mark the row applied' );
		$this->assertSame( $qid, (int) $data['queue_id'] );
		$this->assertNotEmpty( $data['dispatch']['success'] );
	}

	/**
	 * REST reject moves a pending row to rejected with the note.
	 */
	public function test_rest_reject_rejects_pending_item() {
		$this->create_admin_user();
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$qid = $this->enqueue_item( $relation_id, 'document' );

		$controller = new Manual_Queue_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/wptsall/v2/manual-queue/' . $qid . '/reject' );
		$request->set_param( 'id', $qid );
		$request->set_param( 'note', 'Manually dismissed in review' );

		$response = $controller->reject_item( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'rejected', $data['status'] );

		$row = Manual_Queue::get_item( $qid );
		$this->assertSame( 'rejected', $row['status'] );
		$this->assertSame( 'Manually dismissed in review', $row['reason'] );
	}

	/**
	 * REST expire-old bulk cleanup expires only rows past the days threshold.
	 *
	 * catalog: WP-REST-wptsall-manual-queue-expire
	 * oracle: L2
	 */
	public function test_rest_expire_expires_only_old_pending_items() {
		$this->create_admin_user();
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$old_id = $this->enqueue_item( $relation_id, 'image' );
		$new_id = $this->enqueue_item( $relation_id, 'image' );

		// Force the first row past the 30-day threshold (created_at).
		global $wpdb;
		$table = wptsall_table( 'manual_queue' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = DATE_SUB( %s, INTERVAL 40 DAY ) WHERE id = %d", current_time( 'mysql' ), $old_id ) );

		$controller = new Manual_Queue_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/wptsall/v2/manual-queue/expire' );
		$request->set_param( 'days', 30 );

		$response = $controller->expire_old( $request );
		$data     = $response->get_data();

		$this->assertTrue( (bool) $data['success'] );
		$this->assertGreaterThanOrEqual( 1, (int) $data['expired'], 'the 40-day-old row must be expired' );

		$old_row = Manual_Queue::get_item( $old_id );
		$new_row = Manual_Queue::get_item( $new_id );
		$this->assertSame( 'expired', $old_row['status'], 'row older than the threshold must flip to expired' );
		$this->assertSame( 'pending', $new_row['status'], 'row inside the threshold must stay pending' );
	}

	/**
	 * REST apply/reject return 404 for a missing row.
	 */
	public function test_rest_missing_item_returns_404() {
		$this->create_admin_user();

		$controller = new Manual_Queue_REST_Controller();

		$apply_request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-queue/999999999/apply' );
		$apply_request->set_param( 'id', 999999999 );
		$apply_result = $controller->apply_item( $apply_request );
		$this->assertTrue( is_wp_error( $apply_result ) );
		$this->assertSame( 'manual_queue_item_not_found', $apply_result->get_error_code() );
		$this->assertSame( 404, $apply_result->get_error_data()['status'] ?? null );

		$reject_request = new WP_REST_Request( 'POST', '/wptsall/v2/manual-queue/999999999/reject' );
		$reject_request->set_param( 'id', 999999999 );
		$reject_result = $controller->reject_item( $reject_request );
		$this->assertTrue( is_wp_error( $reject_result ) );
		$this->assertSame( 'manual_queue_item_not_found', $reject_result->get_error_code() );
		$this->assertSame( 404, $reject_result->get_error_data()['status'] ?? null );
	}

	/**
	 * Permission semantics: anonymous 401, cap-less 403, authorized true.
	 */
	public function test_rest_permission_semantics() {
		$controller = new Manual_Queue_REST_Controller();

		// Anonymous -> 401 rest_not_logged_in.
		wp_set_current_user( 0 );
		$anon_result = $controller->check_permission();
		$this->assertTrue( is_wp_error( $anon_result ) );
		$this->assertSame( 'rest_not_logged_in', $anon_result->get_error_code() );
		$this->assertSame( 401, $anon_result->get_error_data()['status'] ?? null );

		// Logged in without the cap -> 403 rest_forbidden.
		$username = 'mqrsub_' . uniqid();
		$user_id  = wp_create_user( $username, 'pass-' . uniqid(), $username . '@test.example' );
		$user     = get_userdata( $user_id );
		$user->set_role( 'subscriber' );
		wp_set_current_user( $user_id );
		$this->test_user_ids[] = $user_id;

		$forbidden_result = $controller->check_permission();
		$this->assertTrue( is_wp_error( $forbidden_result ) );
		$this->assertSame( 'rest_forbidden', $forbidden_result->get_error_code() );
		$this->assertSame( 403, $forbidden_result->get_error_data()['status'] ?? null );

		// Administrator with the cap -> true.
		$this->create_admin_user();
		$this->assertTrue( $controller->check_permission() );
	}
}

/**
 * Test-only write-back adapter for the REST suite: claims entity_type=mqr_fake.
 */
class MQR_Test_Write_Back_Adapter implements Write_Back_Adapter_Interface {

	public function get_type(): string {
		return 'mqr_fake';
	}

	public function get_supported_ref_types(): array {
		return array( 'id' );
	}

	public function validate_ref( array $translated_ref ) {
		return isset( $translated_ref['ref_value'] ) ? true : new \WP_Error( 'mqr_bad_ref', 'Missing ref_value' );
	}

	public function apply( array $item, array $context ): array {
		return array(
			'success'   => true,
			'target_id' => 5150,
			'error'     => null,
			'adapter'   => $this->get_type(),
		);
	}

	public function can_handle( array $item ): bool {
		return 'mqr_fake' === (string) ( $item['entity_type'] ?? '' );
	}
}

<?php
/**
 * Manual Queue behavior tests (opus5 A-01 / T-02)
 *
 * Before 2.1.4 the queue had three write points (Write_Back_Dispatcher
 * enqueue paths) and zero behavior tests. Covers the read/update/count
 * surface and the new apply_item()/expire_old() consumers that close the
 * black hole (decision D-1(a)).
 *
 * @package WPTSALL
 * @since 2.1.4
 */

use WPTSALL\Sites\Services\Site_Relation_Service;
use WPTSALL\Tasks\Sync\Manual_Queue;
use WPTSALL\Tasks\Sync\Write_Back_Adapter_Interface;
use WPTSALL\Tasks\Sync\Write_Back_Dispatcher;

/**
 * Test_Manual_Queue
 */
class Test_Manual_Queue extends SimpleTestCase {

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

	public function setUp(): void {
		parent::setUp();
		// Ensure the queue table exists even when the plugin bootstrap did
		// not create it in this process (same pattern as term_mappings).
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

		parent::tearDown();
	}

	/**
	 * Helper: enqueue a synthetic queue item.
	 *
	 * @param int    $relation_id Relation ID.
	 * @param string $entity_type Entity type.
	 * @param int    $source_id   Source object ID.
	 * @param string $reason      Routing reason.
	 * @return int Queue item ID.
	 */
	private function enqueue_item( $relation_id, $entity_type, $source_id = 0, $reason = 'unit test reason' ) {
		$id = Manual_Queue::enqueue(
			array(
				'entity_type'    => $entity_type,
				'source_id'      => $source_id,
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
			$reason
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
					'id'   => 'v_test_mq_' . uniqid(),
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

	// ==================== T-02: read/update/count ====================

	/**
	 * Enqueue → get_items roundtrip: row lands pending with decodable payload.
	 */
	public function test_enqueue_and_get_items_roundtrip() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$qid = $this->enqueue_item( $relation_id, 'image', 123, 'No adapter found for entity_type=image' );

		$items = Manual_Queue::get_items( array( 'relation_id' => $relation_id, 'status' => Manual_Queue::STATUS_PENDING ) );
		$this->assertNotEmpty( $items, 'pending items must be readable' );

		$found = null;
		foreach ( $items as $item ) {
			if ( (int) $item['id'] === $qid ) {
				$found = $item;
				break;
			}
		}
		$this->assertNotNull( $found, 'enqueued item must appear in get_items' );
		$this->assertSame( 'pending', $found['status'] );
		$this->assertSame( 'image', $found['entity_type'] );
		$this->assertSame( (string) get_current_blog_id(), (string) $found['source_blog'] );
		$this->assertSame( 'No adapter found for entity_type=image', $found['reason'] );

		$payload = json_decode( (string) $found['payload'], true );
		$this->assertIsArray( $payload, 'payload must be decodable JSON' );
		$this->assertSame( 123, (int) $payload['source_id'] );
	}

	/**
	 * get_items filters by relation_id and entity_type.
	 */
	public function test_get_items_filters_by_relation_and_entity_type() {
		$rel_a = $this->create_test_relation();
		$rel_b = $this->create_test_relation();
		if ( ! $rel_a || ! $rel_b ) {
			$this->markTestSkipped( 'Could not create test relations' );
			return;
		}

		$this->enqueue_item( $rel_a, 'image', 1 );
		$this->enqueue_item( $rel_a, 'video', 2 );
		$this->enqueue_item( $rel_b, 'image', 3 );

		$only_a = Manual_Queue::get_items( array( 'relation_id' => $rel_a ) );
		foreach ( $only_a as $item ) {
			$this->assertSame( (string) $rel_a, (string) $item['relation_id'], 'relation filter must hold' );
		}
		$this->assertCount( 2, $only_a, 'relation filter must select exactly the two rel_a rows' );

		$only_video = Manual_Queue::get_items( array( 'relation_id' => $rel_a, 'entity_type' => 'video' ) );
		$this->assertCount( 1, $only_video, 'entity_type filter must select exactly the video row' );
		$this->assertSame( 'video', $only_video[0]['entity_type'] );
	}

	/**
	 * update_status performs valid transitions and rejects invalid statuses.
	 */
	public function test_update_status_transitions_and_rejects_invalid() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}
		$qid = $this->enqueue_item( $relation_id, 'document', 9 );

		$this->assertTrue( Manual_Queue::update_status( $qid, Manual_Queue::STATUS_REVIEWING ), 'pending -> reviewing must succeed' );
		$row = Manual_Queue::get_item( $qid );
		$this->assertSame( 'reviewing', $row['status'] );

		$this->assertTrue( Manual_Queue::update_status( $qid, Manual_Queue::STATUS_APPLIED, 'Applied via manual queue (adapter=attachment)' ), 'reviewing -> applied must succeed' );
		$row = Manual_Queue::get_item( $qid );
		$this->assertSame( 'applied', $row['status'] );
		$this->assertSame( 'Applied via manual queue (adapter=attachment)', $row['reason'], 'note must be persisted into reason' );

		$this->assertFalse( Manual_Queue::update_status( $qid, 'bogus_status' ), 'an invalid status must be rejected' );
		$this->assertFalse( Manual_Queue::update_status( 999999999, Manual_Queue::STATUS_REJECTED ), 'a missing row must not be updated' );

		$row = Manual_Queue::get_item( $qid );
		$this->assertSame( 'applied', $row['status'], 'rejected updates must not have changed the row' );
	}

	/**
	 * count_pending counts with relation filtering.
	 */
	public function test_count_pending_with_relation_filter() {
		$rel_a = $this->create_test_relation();
		$rel_b = $this->create_test_relation();
		if ( ! $rel_a || ! $rel_b ) {
			$this->markTestSkipped( 'Could not create test relations' );
			return;
		}

		$before = Manual_Queue::count_pending( array( 'relation_id' => $rel_a ) );
		$this->enqueue_item( $rel_a, 'image', 1 );
		$this->enqueue_item( $rel_a, 'video', 2 );
		$this->enqueue_item( $rel_b, 'image', 3 );

		$this->assertSame( $before + 2, Manual_Queue::count_pending( array( 'relation_id' => $rel_a ) ), 'count_pending must respect the relation filter' );
		$this->assertSame( $before, Manual_Queue::count_pending( array( 'relation_id' => 999999999 ) ), 'an unknown relation must count 0 new rows' );
	}

	// ==================== A-01: apply_item / expire_old ====================

	/**
	 * apply_item success: re-dispatch through a registered adapter marks the
	 * row applied and records the adapter in the reason.
	 */
	public function test_apply_item_success_marks_applied() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$adapter = new M02_Test_Write_Back_Adapter();
		Write_Back_Dispatcher::register_adapter( $adapter );

		$qid = $this->enqueue_item( $relation_id, 'm02_fake', 77, 'Queued for unit test' );

		$result = Manual_Queue::apply_item( $qid );

		$this->assertFalse( is_wp_error( $result ), 'apply_item must succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$this->assertSame( $qid, (int) $result['queue_id'] );
		$this->assertSame( Manual_Queue::STATUS_APPLIED, $result['status'] );
		$this->assertNotEmpty( $result['dispatch']['success'], 'dispatch result must report success' );
		$this->assertSame( 4242, (int) $result['dispatch']['target_id'], 'fake adapter target must roundtrip' );

		$row = Manual_Queue::get_item( $qid );
		$this->assertSame( 'applied', $row['status'] );
		$this->assertStringContainsString( 'Applied via manual queue', (string) $row['reason'] );
		$this->assertStringContainsString( 'adapter=m02_fake', (string) $row['reason'] );
	}

	/**
	 * apply_item failure: row stays pending with the fresh error, and the
	 * duplicate row the failed retry enqueued is removed (exactly one row
	 * keeps tracking the item).
	 */
	public function test_apply_item_failure_keeps_pending_and_dedupes() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		// No adapter handles this entity type (fake adapter only claims m02_fake).
		$qid    = $this->enqueue_item( $relation_id, 'm02_unhandled', 88, 'Initial reason' );
		$before = Manual_Queue::count_pending( array( 'relation_id' => $relation_id ) );

		$result = Manual_Queue::apply_item( $qid );

		$this->assertTrue( is_wp_error( $result ), 'apply_item without a matching adapter must fail' );
		$this->assertSame( 'manual_queue_apply_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] ?? null );
		$this->assertStringContainsString( 'No write-back adapter', (string) $result->get_error_message() );

		$row = Manual_Queue::get_item( $qid );
		$this->assertSame( 'pending', $row['status'], 'the reviewed row must stay pending after a failed apply' );
		$this->assertStringContainsString( 'No write-back adapter', (string) $row['reason'], 'the fresh error must replace the stale reason' );

		$this->assertSame(
			$before,
			Manual_Queue::count_pending( array( 'relation_id' => $relation_id ) ),
			'the duplicate row the failed retry enqueued must be removed'
		);
	}

	/**
	 * apply_item enforces the M-01 doctrine: an inactive relation never
	 * accepts write-back.
	 */
	public function test_apply_item_requires_active_relation() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$qid = $this->enqueue_item( $relation_id, 'm02_fake', 99, 'Queued while active' );

		Site_Relation_Service::update_relation( $relation_id, array( 'status' => 'inactive' ) );
		wp_cache_flush();

		$result = Manual_Queue::apply_item( $qid );

		$this->assertTrue( is_wp_error( $result ), 'apply_item on an inactive relation must fail' );
		$this->assertSame( 'relation_inactive', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null );

		$row = Manual_Queue::get_item( $qid );
		$this->assertSame( 'pending', $row['status'], 'the row must stay pending' );
	}

	/**
	 * apply_item rejects rows that are no longer in a reviewable state.
	 */
	public function test_apply_item_rejects_non_reviewable_status() {
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$qid = $this->enqueue_item( $relation_id, 'm02_fake', 100 );
		$this->assertTrue( Manual_Queue::update_status( $qid, Manual_Queue::STATUS_REJECTED, 'Dismissed' ) );

		$result = Manual_Queue::apply_item( $qid );

		$this->assertTrue( is_wp_error( $result ), 'applying a rejected row must fail' );
		$this->assertSame( 'manual_queue_item_not_reviewable', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * apply_item returns 404 for a missing row.
	 */
	public function test_apply_item_missing_row_404() {
		$result = Manual_Queue::apply_item( 999999999 );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'manual_queue_item_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * expire_old marks only aged pending rows; recent rows and other
	 * statuses are untouched.
	 */
	public function test_expire_old_marks_only_aged_pending() {
		global $wpdb;
		$relation_id = $this->create_test_relation();
		if ( ! $relation_id ) {
			$this->markTestSkipped( 'Could not create test relation' );
			return;
		}

		$old_id = $this->enqueue_item( $relation_id, 'image', 1 );
		$new_id = $this->enqueue_item( $relation_id, 'image', 2 );

		// Backdate the first row 40 days.
		$table = wptsall_table( 'manual_queue' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $old_id ),
			array( '%s' ),
			array( '%d' )
		);

		$expired = Manual_Queue::expire_old( 30 );

		$this->assertGreaterThanOrEqual( 1, $expired, 'the aged row must be expired' );
		$this->assertSame( 'expired', (string) Manual_Queue::get_item( $old_id )['status'], 'the aged row must be expired' );
		$this->assertSame( 'pending', (string) Manual_Queue::get_item( $new_id )['status'], 'the recent row must stay pending' );

		// Applied/rejected rows must never be expired by the sweep.
		$rejected_id = $this->enqueue_item( $relation_id, 'video', 3 );
		$this->assertTrue( Manual_Queue::update_status( $rejected_id, Manual_Queue::STATUS_REJECTED, 'Dismissed' ) );
		$wpdb->update(
			$table,
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $rejected_id ),
			array( '%s' ),
			array( '%d' )
		);
		Manual_Queue::expire_old( 30 );
		$this->assertSame( 'rejected', (string) Manual_Queue::get_item( $rejected_id )['status'], 'rejected rows must not be expired' );
	}
}

/**
 * Test-only write-back adapter: claims entity_type=m02_fake and always
 * applies successfully. Registered via the public
 * Write_Back_Dispatcher::register_adapter() seam; can_handle() matches one
 * unique entity type so it never interferes with other tests.
 */
class M02_Test_Write_Back_Adapter implements Write_Back_Adapter_Interface {

	public function get_type(): string {
		return 'm02_fake';
	}

	public function get_supported_ref_types(): array {
		return array( 'id' );
	}

	public function validate_ref( array $translated_ref ) {
		return isset( $translated_ref['ref_value'] ) ? true : new \WP_Error( 'm02_bad_ref', 'Missing ref_value' );
	}

	public function apply( array $item, array $context ): array {
		return array(
			'success'   => true,
			'target_id' => 4242,
			'error'     => null,
			'adapter'   => $this->get_type(),
		);
	}

	public function can_handle( array $item ): bool {
		return 'm02_fake' === (string) ( $item['entity_type'] ?? '' );
	}
}

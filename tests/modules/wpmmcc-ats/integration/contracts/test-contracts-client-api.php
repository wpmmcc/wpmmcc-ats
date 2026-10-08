<?php
/**
 * Contract Tests: Client API Data Structure
 *
 * Verifies that the Client API response structures match the documented contracts:
 * - tasks list: { success, data: { schema_version: 3, items: [] } }
 * - translation-callback required fields and idempotency key
 * - media-upload required headers
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

class Test_Contracts_Client_Api extends REST_Integration_Test_Case {

	/** @var bool Whether this suite can run. */
	private static $chain_runnable = true;

	/** @var string Reason for skipping. */
	private static $skip_reason = '';

	/** @var string Client API token */
	private string $token = '';

	/** @var string Route secret prefix */
	private string $secret = '';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! function_exists( 'wptsall_issue_client_device_token' ) ||
			 ! function_exists( 'wptsall_get_client_route_secret' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'Client API helper functions not available';
		}
	}

	/** @var string */
	private string $device_id = '';

	public function setUp(): void {
		parent::setUp();
		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}

		$issued         = wptsall_issue_client_device_token( 'contract-' . wp_generate_password( 6, false ), 'contract' );
		$this->token    = (string) ( $issued['token'] ?? '' );
		$this->device_id = (string) ( $issued['device_id'] ?? '' );
		$this->secret   = wptsall_get_client_route_secret();

		if ( empty( $this->token ) || empty( $this->secret ) || empty( $this->device_id ) ) {
			$this->markTestSkipped( 'Client device token or route secret is empty' );
		}
	}

	// ─── Tasks list contract ──────────────────────────────────────────────

	/**
	 * Verify tasks list response envelope: { success, data: { schema_version, items } }
	 */
	public function test_tasks_list_response_structure() {
		$request = new WP_REST_Request( 'GET', "/wptsall/v2/{$this->secret}/client/tasks" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$r = rest_do_request( $request );

		// HTTP assertion.
		$this->assertEquals( 200, $r->get_status(), 'Client tasks list must return HTTP 200' );

		$data = $r->get_data();

		// Contract: top-level must have success.
		$this->assertArrayHasKey( 'success', $data, 'Response must have success key' );
		$this->assertTrue( $data['success'], 'success must be true' );

		// Contract: data envelope.
		$this->assertArrayHasKey( 'data', $data, 'Response must have data key' );
		$this->assertIsArray( $data['data'], 'data must be an array' );

		// Contract: schema_version = 3.
		$this->assertArrayHasKey( 'schema_version', $data['data'], 'data must have schema_version' );
		$this->assertEquals( 3, (int) $data['data']['schema_version'], 'schema_version must be 3' );

		// Contract: items (NOT tasks).
		$this->assertArrayHasKey( 'items', $data['data'], 'data must have items key (not tasks)' );
		$this->assertIsArray( $data['data']['items'], 'items must be an array' );
	}

	/**
	 * Verify each task item has required contract fields.
	 */
	public function test_task_item_has_required_fields() {
		// Insert a deterministic pending task row directly to avoid site-level
		// save_post callback noise from unrelated plugins in integration host.
		global $wpdb;
		$table    = wptsall_table( 'tasks' );
		$now      = current_time( 'mysql' );
		$seed_key = 'contract-task-' . wp_generate_uuid4();
		$wpdb->insert(
			$table,
			array(
				'blog_id'           => get_current_blog_id(),
				'target_blog'       => 0,
				'target_type'       => 'virtual',
				'target_identifier' => 'v_contract',
				'site_id'           => 1,
				'relation_id'       => 1,
				'template'          => 'contract-task',
				'type'              => 'sync',
				'model_ids'         => '',
				'priority'          => 'normal',
				'object_type'       => 'post',
				'subtype'           => 'post',
				'object_id'         => wp_rand( 10000, 99999 ),
				'lang_from'         => 'en_US',
				'lang_to'           => 'zh_CN',
				'site_mode'         => '',
				'status'            => 'pending',
				'status_note'       => '',
				'retry_count'       => 0,
				'progress'          => '',
				'meta'              => wp_json_encode( array( 'source' => 'contract_test' ) ),
				'payload'           => wp_json_encode( array(
					'job_id'        => $seed_key,
					'business_line' => 'post_content',
					'task_type'     => 'text',
					'fields'        => array(
						'post_title' => 'Contract title',
					),
				) ),
				'created_at'        => '2000-01-01 00:00:00',
				'updated_at'        => $now,
			),
			array(
				'%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s',
				'%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s',
				'%s', '%s', '%s',
			)
		);
		$seed_task_id = (int) $wpdb->insert_id;

		$request = new WP_REST_Request( 'GET', "/wptsall/v2/{$this->secret}/client/tasks" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$r    = rest_do_request( $request );
		$data = $r->get_data();

		if ( empty( $data['data']['items'] ) ) {
			$this->markTestSkipped( 'No tasks in list — cannot verify item structure' );
		}

		$item = $data['data']['items'][0];

		// Contract: required fields per task item.
		$required_fields = array( 'task_id', 'job_id', 'type', 'business_line', 'relation_id', 'target_lang' );
		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey(
				$field,
				$item,
				"Task item must have field: {$field}"
			);
		}

		// Contract: relation_id must be numeric.
		$this->assertIsNumeric( $item['relation_id'], 'relation_id must be numeric' );

		// Contract: job_id is the idempotency key (must be non-empty string).
		$this->assertNotEmpty( $item['job_id'], 'job_id must not be empty (used as idempotency key)' );

		// Best effort cleanup of seeded row.
		if ( $seed_task_id > 0 ) {
			$wpdb->delete(
				$table,
				array( 'id' => $seed_task_id ),
				array( '%d' )
			);
		}
	}

	// ─── Translation callback contract ───────────────────────────────────

	/**
	 * Missing client_task_id → 400 with specific error code.
	 */
	public function test_callback_requires_client_task_id() {
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$this->secret}/client/translation-callback" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			// client_task_id intentionally omitted
			'relation_id'   => 1,
			'business_line' => 'post_content',
			'object_type'   => 'post',
			'object_id'     => 1,
		) ) );

		$r = rest_do_request( $request );

		$this->assertEquals( 400, $r->get_status(), 'Missing client_task_id must return 400' );
		$data = $r->get_data();
		// Client controller returns { success: false, error: '...', message: '...' }
		// NOT WP_Error format — key is 'error', not 'code'.
		$this->assertArrayHasKey( 'error', $data, '400 response must have error key' );
		$this->assertEquals(
			'missing_client_task_id',
			$data['error'],
			'Error value must be missing_client_task_id'
		);
	}

	/**
	 * Missing relation_id → 400.
	 */
	public function test_callback_requires_relation_id() {
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$this->secret}/client/translation-callback" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'client_task_id' => 'contract-test-' . wp_rand(),
			// relation_id intentionally omitted
			'business_line'  => 'post_content',
			'object_type'    => 'post',
			'object_id'      => 1,
		) ) );

		$r = rest_do_request( $request );
		$this->assertEquals( 400, $r->get_status(), 'Missing relation_id must return 400' );
	}

	/**
	 * Unknown business_line → 400.
	 */
	public function test_callback_rejects_unknown_business_line() {
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$this->secret}/client/translation-callback" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'client_task_id' => 'contract-test-' . wp_rand(),
			'relation_id'    => 1,
			'business_line'  => 'completely_unknown_business_line',
			'object_type'    => 'post',
			'object_id'      => 1,
		) ) );

		$r    = rest_do_request( $request );
		$data = $r->get_data();

		$this->assertEquals( 400, $r->get_status(), 'Unknown business_line must return 400' );
		$this->assertArrayHasKey( 'error', $data, 'Must return error key' );
		$this->assertEquals( 'unknown_business_line', $data['error'], 'Error value must be unknown_business_line' );
	}

	// ─── Media upload contract ────────────────────────────────────────────

	/**
	 * Missing X-WPTSALL-Filename header → 400 with missing_filename error.
	 */
	public function test_media_upload_requires_filename_header() {
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$this->secret}/client/media-upload" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_header( 'Content-Type', 'image/png' );
		$request->set_header( 'X-WPTSALL-Task-ID', '1' );
		$request->set_header( 'X-WPTSALL-Source-ID', '1' );
		// X-WPTSALL-Filename intentionally omitted
		$request->set_body( str_repeat( 'x', 10 ) );

		$r    = rest_do_request( $request );
		$data = $r->get_data();

		$this->assertEquals( 400, $r->get_status(), 'Missing X-WPTSALL-Filename must return 400' );
		$this->assertArrayHasKey( 'error', $data, 'Must return error key' );
		$this->assertEquals( 'missing_filename', $data['error'], 'Error value must be missing_filename' );
	}

	/**
	 * PHP extension → 400 with blocked_file_type error.
	 */
	public function test_media_upload_blocks_php_extension() {
		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$this->secret}/client/media-upload" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_header( 'Content-Type', 'application/x-httpd-php' );
		$request->set_header( 'X-WPTSALL-Filename', 'malware.php' );
		$request->set_header( 'X-WPTSALL-Task-ID', '1' );
		$request->set_header( 'X-WPTSALL-Source-ID', '1' );
		$request->set_body( '<?php echo "hacked"; ?>' );

		$r    = rest_do_request( $request );
		$data = $r->get_data();

		$this->assertEquals( 400, $r->get_status(), 'PHP extension must return 400' );
		$this->assertArrayHasKey( 'error', $data, 'Must return error key' );
		$this->assertEquals( 'blocked_file_type', $data['error'], 'Error value must be blocked_file_type' );
	}

	// ─── Outbox ack 409 / 403 contracts ───────────────────────────────────

	/**
	 * Ack against a pending (unclaimed) outbox row → 409 outbox_not_claimed.
	 */
	public function test_outbox_ack_unclaimed_returns_409() {
		if ( ! function_exists( 'wptsall_create_content_change_outbox_table' ) ) {
			$this->markTestSkipped( 'content_change_outbox helpers unavailable' );
		}
		wptsall_create_content_change_outbox_table();

		global $wpdb;
		$outbox_table = wptsall_table( 'content_change_outbox' );
		$now          = current_time( 'mysql' );
		$post_id      = wp_insert_post(
			array(
				'post_title'  => 'Contract Outbox 409 ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		$wpdb->insert(
			$outbox_table,
			array(
				'event_key'      => 'contract-outbox-409-' . uniqid( '', true ),
				'source_type'    => 'post',
				'source_id'      => $post_id,
				'source_site_id' => get_current_blog_id(),
				'relation_id'    => 1,
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
		$outbox_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $outbox_id );

		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$this->secret}/client/content-changes/{$outbox_id}/ack" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'outcome' => 'completed' ) ) );

		$r    = rest_do_request( $request );
		$data = $r->get_data();

		$this->assertEquals( 409, $r->get_status(), 'Unclaimed outbox ack must return 409' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertEquals( 'outbox_not_claimed', $data['error'] );

		$wpdb->delete( $outbox_table, array( 'id' => $outbox_id ), array( '%d' ) );
		wp_delete_post( $post_id, true );
	}

	/**
	 * Ack claimed by another device → 403 outbox_claim_owner_mismatch.
	 */
	public function test_outbox_ack_owner_mismatch_returns_403() {
		if ( ! function_exists( 'wptsall_create_content_change_outbox_table' ) ) {
			$this->markTestSkipped( 'content_change_outbox helpers unavailable' );
		}
		wptsall_create_content_change_outbox_table();

		global $wpdb;
		$outbox_table = wptsall_table( 'content_change_outbox' );
		$now          = current_time( 'mysql' );
		$relation_id  = 1;
		$post_id      = wp_insert_post(
			array(
				'post_title'  => 'Contract Outbox 403 ' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		$foreign_owner = function_exists( 'wptsall_client_claim_owner_hash' )
			? wptsall_client_claim_owner_hash( 'foreign-device-xyz', $relation_id, '', 'outbox' )
			: hash( 'sha256', 'wptsall-claim-owner-v1|foreign-device-xyz|' . $relation_id . '||outbox' );

		$wpdb->insert(
			$outbox_table,
			array(
				'event_key'      => 'contract-outbox-403-' . uniqid( '', true ),
				'source_type'    => 'post',
				'source_id'      => $post_id,
				'source_site_id' => get_current_blog_id(),
				'relation_id'    => $relation_id,
				'event_name'     => 'post_updated',
				'payload'        => wp_json_encode(
					array(
						'post_type'                 => 'post',
						'_wptsall_claim_owner_hash' => $foreign_owner,
					)
				),
				'status'         => 'processing',
				'attempts'       => 1,
				'available_at'   => $now,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$outbox_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $outbox_id );

		$request = new WP_REST_Request( 'POST', "/wptsall/v2/{$this->secret}/client/content-changes/{$outbox_id}/ack" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', $this->token );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'outcome' => 'completed' ) ) );

		$r    = rest_do_request( $request );
		$data = $r->get_data();

		$this->assertEquals( 403, $r->get_status(), 'Foreign claim owner must return 403' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertEquals( 'outbox_claim_owner_mismatch', $data['error'] );

		$wpdb->delete( $outbox_table, array( 'id' => $outbox_id ), array( '%d' ) );
		wp_delete_post( $post_id, true );
	}

	/**
	 * Invalid client token → 401 (auth boundary, not silent 200).
	 */
	public function test_client_tasks_invalid_token_returns_401() {
		$request = new WP_REST_Request( 'GET', "/wptsall/v2/{$this->secret}/client/tasks" );
		$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', $this->device_id );
		$request->set_header( 'X-WPTSALL-Client-Token', 'definitely-not-a-valid-token' );

		$r = rest_do_request( $request );
		$this->assertEquals( 401, $r->get_status(), 'Invalid client token must return 401' );
	}
}

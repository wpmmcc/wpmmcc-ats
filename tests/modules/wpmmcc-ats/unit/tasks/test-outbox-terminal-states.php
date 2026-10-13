<?php
/**
 * Outbox terminal states and translation-callback replay (ATS-01..04)
 *
 * The D3 transition table. A callback may reach the server more than once
 * (client retry, lost response, re-lease after a failure), so what the server
 * answers for a client_task_id that already has a result row, and what happens
 * to the outbox row, must be fixed per result status:
 *
 *   result     replay, same body                      outbox row afterwards
 *   ---------  -------------------------------------  ------------------------------
 *   synced     200 idempotent                         completed
 *   completed  200 idempotent                         completed
 *   partial    200 idempotent, partial:true           completed (media remainder sits in
 *                                                     the operator manual queue)
 *   cancelled  200 idempotent, skipped:true           completed (delivery skipped on purpose)
 *   failed     409 callback_not_replayable            untouched - unless the caller holds the
 *                                                     lease again: then it is a NEW attempt, the
 *                                                     old row is renamed and the callback runs
 *   pending    409 callback_not_replayable (in flight) untouched - unless older than an hour:
 *                                                     then the request died, same takeover
 *   any, other body: 409 idempotency_conflict (ISS S3), except the takeover cases above.
 *
 * `partial` therefore has to mean "accepted with a parked remainder" and nothing
 * else: a write-back that lost items (not even the manual queue took them) or an
 * attachment binary that never arrived is a failure (500, result `failed`).
 *
 * A callback without outbox_id may only close a row that is unambiguous and
 * leased to the calling device (ATS-03). Rows no retry can fix are abandoned at
 * once by the content-changes endpoint; transient ones back off (ATS-01).
 *
 * Isolation: the runner skips tearDown when an assertion throws, so setUp also
 * cleans; everything is keyed on a template prefix / client_task_id prefix.
 *
 * catalog: WP-WRITE-handle_content_callback
 * oracle: L2
 *
 * @package WPTSALL
 * @since 2.3.0
 */

use WPTSALL\Hooks\Content_Change_Dispatcher;

class Test_Outbox_Terminal_States extends WP_UnitTestCase {

	const DEVICE = 'test-terminal-device';

	const OTHER_DEVICE = 'test-terminal-other-device';

	const TEMPLATE_PREFIX = 'test-terminal-states-';

	const TASK_PREFIX = 'test-terminal-';

	const ATTACHMENT_TITLE = 'Terminal States Attachment';

	/**
	 * @var \WPTSALL\Tasks\API\Client_Data_REST_Controller
	 */
	protected $controller;

	protected $relation_id = 0;

	protected $post_id = 0;

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
	 * The tasks module is not auto-loaded on localhost; load what the callback
	 * path needs (same set as test-client-data-rest-controller.php).
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
			require_once $tasks_path . 'api/class-client-data-rest-controller.php';
		}
		if ( ! class_exists( '\\WPTSALL\\Tasks\\API\\Client_Data_REST_Controller' ) ) {
			require_once $root . 'includes/tasks/api/class-client-data-rest-controller.php';
		}
		if ( ! class_exists( '\\WPTSALL\\Core\\Job_Snapshot' ) ) {
			require_once $root . 'includes/core/class-job-snapshot.php';
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
		if ( ! function_exists( 'wptsall_create_model_tables' ) ) {
			require_once $root . 'includes/models/database/schema-models.php';
		}
		if ( ! function_exists( 'wptsall_create_relation_models_table' ) ) {
			require_once $root . 'includes/sites/database/schema-relation-models.php';
		}
		wptsall_ensure_task_table();
		wptsall_create_translation_results_table();
		wptsall_create_content_change_outbox_table();
		wptsall_create_model_tables();
		wptsall_create_relation_models_table();
		if ( function_exists( 'wptsall_create_manual_queue_table' ) ) {
			wptsall_create_manual_queue_table();
		}
	}

	/**
	 * Remove everything this class (and its aborted earlier runs) created.
	 */
	private function clean_own_state() {
		global $wpdb;
		$relations = wptsall_table( 'site_relations' );
		foreach ( array( 'content_change_outbox', 'tasks', 'post_mappings', 'manual_queue', 'translation_results', 'relation_models' ) as $key ) {
			$table = wptsall_table( $key );
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
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE client_task_id LIKE %s', wptsall_table( 'translation_results' ), self::TASK_PREFIX . '%' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE template LIKE %s', $relations, self::TEMPLATE_PREFIX . '%' ) );
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'title' => self::ATTACHMENT_TITLE, 'numberposts' => -1, 'fields' => 'ids' ) ) as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}
	}

	public function setUp(): void {
		parent::setUp();
		self::ensure_modules_loaded();
		$this->clean_own_state();
		global $wpdb, $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$this->controller = new \WPTSALL\Tasks\API\Client_Data_REST_Controller();

		// The post first, the relation second: the dispatcher fans every save out
		// to the active relations, and this suite wants full control of its own
		// relation's outbox rows.
		$this->post_id = (int) wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Terminal States Test Post',
				'post_content' => '<p>Terminal states content</p>',
			)
		);
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id'   => get_current_blog_id(),
				'source_site_type' => 'wp',
				'source_lang'      => 'zh_CN',
				'template'         => self::TEMPLATE_PREFIX . uniqid(),
				'target_site_id'   => 'v_terminal_' . uniqid(),
				'target_site_type' => 'virtual',
				'target_lang'      => 'en_US',
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$this->relation_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $this->relation_id, 'relation fixture: ' . $wpdb->last_error );
	}

	public function tearDown(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;
		// Keep the tasks module's table-name registration. Redirect tests remove
		// only their own filters in finally, so cleanup still reaches seeded rows.
		$this->clean_own_state();
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE source_type = %s AND source_id = %d', wptsall_table( 'content_change_outbox' ), 'post', $this->post_id )
			);
		}
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	private function outbox_owner_hash( $device = self::DEVICE ) {
		return wptsall_client_claim_owner_hash( $device, $this->relation_id, '', 'outbox' );
	}

	/**
	 * Insert an outbox row for the fixture post.
	 *
	 * Options: status (default processing), attempts, device (owner of the lease,
	 * null = unowned), stamp_owner (a row keeps the owner of its last lease after
	 * it is completed or released; default: every status but a fresh pending
	 * one), event_name, post_type (false = omit it from the payload), source_id.
	 *
	 * @param array $options Overrides.
	 * @return int
	 */
	private function seed_outbox( array $options = array() ) {
		global $wpdb;
		$status  = $options['status'] ?? 'processing';
		$payload = array( 'relation_id' => $this->relation_id );
		if ( false !== ( $options['post_type'] ?? 'post' ) ) {
			$payload['post_type'] = $options['post_type'] ?? 'post';
		}
		$device      = array_key_exists( 'device', $options ) ? $options['device'] : self::DEVICE;
		$stamp_owner = $options['stamp_owner'] ?? 'pending' !== $status;
		if ( null !== $device && $stamp_owner ) {
			$payload['_wptsall_claim_owner_hash'] = $this->outbox_owner_hash( $device );
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			wptsall_table( 'content_change_outbox' ),
			array(
				'event_key'      => hash( 'sha256', uniqid( 'terminal-', true ) ),
				'source_type'    => 'post',
				'source_id'      => (int) ( $options['source_id'] ?? $this->post_id ),
				'source_site_id' => get_current_blog_id(),
				'relation_id'    => $this->relation_id,
				'event_name'     => $options['event_name'] ?? 'post_updated',
				'payload'        => wp_json_encode( $payload ),
				'status'         => $status,
				'attempts'       => (int) ( $options['attempts'] ?? 1 ),
				'available_at'   => gmdate( 'Y-m-d H:i:s', time() - 5 ),
				'claimed_at'     => 'processing' === $status ? $now : null,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$this->assertSame( '', (string) $wpdb->last_error, 'outbox fixture: ' . $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	private function outbox( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', wptsall_table( 'content_change_outbox' ), (int) $id ), ARRAY_A );
		$this->assertIsArray( $row, "outbox row {$id} must exist" );
		return $row;
	}

	private function result_by_task_id( $client_task_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE client_task_id = %s', wptsall_table( 'translation_results' ), $client_task_id ), ARRAY_A );
	}

	private function result_by_id( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', wptsall_table( 'translation_results' ), (int) $id ), ARRAY_A );
	}

	/**
	 * Drop the rows of one table-driven round so rounds cannot influence each other.
	 */
	private function reset_round() {
		global $wpdb;
		foreach ( array( 'content_change_outbox', 'tasks', 'post_mappings' ) as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE relation_id = %d', wptsall_table( $key ), $this->relation_id ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE relation_id = %d', wptsall_table( 'translation_results' ), $this->relation_id ) );
	}

	/**
	 * The callback body a client would send for the fixture post.
	 *
	 * @param string $client_task_id Task id.
	 * @param array  $overrides      Field overrides.
	 * @return array
	 */
	private function callback_body( $client_task_id, array $overrides = array() ) {
		return array_merge(
			array(
				'business_line'     => 'post_content',
				'client_task_id'    => $client_task_id,
				'relation_id'       => $this->relation_id,
				'object_type'       => 'post_type',
				'object_id'         => $this->post_id,
				'post_type'         => 'post',
				'source_lang'       => 'zh_CN',
				'target_lang'       => 'en_US',
				'source_revision'   => \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $this->post_id ),
				'policy_version'    => \WPTSALL\Core\Job_Snapshot::current_policy_version(),
				'translated_fields' => array( 'post_title' => '【en_US】Terminal States【/en_US】' ),
				'translated_meta'   => array(),
				'media_mappings'    => array(),
			),
			$overrides
		);
	}

	/**
	 * A media mapping no write-back adapter takes: the dispatcher parks it in the
	 * operator's manual queue (the partial, accepted outcome).
	 *
	 * @return array
	 */
	private function unhandled_media_mapping() {
		return array(
			array(
				'source_id'      => 1,
				'entity_type'    => 'terminal_states_unhandled',
				'translated_ref' => 'https://example.invalid/terminal-states.bin',
			),
		);
	}

	/**
	 * Stamp a content claim for a device through the real claim endpoint.
	 *
	 * @param string $device Device id.
	 */
	private function claim_content_as( $device ) {
		global $wpdb;
		$relation = $wpdb->get_row(
			$wpdb->prepare( 'SELECT target_site_id FROM %i WHERE id = %d', wptsall_table( 'site_relations' ), $this->relation_id ),
			ARRAY_A
		);
		$mappings = wptsall_table( 'post_mappings' );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE relation_id = %d AND source_post_id = %d AND source_site_id = %d AND target_site_id = %s LIMIT 1',
				$mappings,
				$this->relation_id,
				$this->post_id,
				get_current_blog_id(),
				(string) $relation['target_site_id']
			)
		);
		if ( ! $existing ) {
			$now = current_time( 'mysql' );
			$wpdb->insert(
				$mappings,
				array(
					'relation_id'      => $this->relation_id,
					'source_post_id'   => $this->post_id,
					'source_post_type' => 'post',
					'source_site_id'   => get_current_blog_id(),
					'target_post_id'   => 0,
					'target_post_type' => 'post',
					'target_site_id'   => (string) $relation['target_site_id'],
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		}
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/content/claim' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Device-Id', $device );
		$request->set_body(
			wp_json_encode(
				array(
					'relation_id' => $this->relation_id,
					'data_type'   => 'post',
					'items'       => array( array( 'object_id' => $this->post_id, 'post_type' => 'post' ) ),
				)
			)
		);
		$response = $this->controller->claim_content( $request );
		$this->assertSame( 200, $response->get_status(), 'claim_content: ' . wp_json_encode( $response->get_data() ) );
	}

	/**
	 * POST the callback as a device.
	 *
	 * @param array  $body   Callback body.
	 * @param string $device Device id.
	 * @return WP_REST_Response
	 */
	private function post_callback( array $body, $device = self::DEVICE ) {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Device-Id', $device );
		$request->set_body( wp_json_encode( $body ) );
		return $this->controller->translation_callback( $request );
	}

	/**
	 * Store a result row the way an earlier request would have left it.
	 *
	 * @param string $client_task_id Task id.
	 * @param string $status         Result status.
	 * @param array  $body           Body whose hash the row carries.
	 * @param int    $age_seconds    How long ago it was created.
	 * @param array  $extra          Result columns to override (object_id, media_mappings, ...).
	 * @return int
	 */
	private function seed_result( $client_task_id, $status, array $body, $age_seconds = 0, array $extra = array() ) {
		global $wpdb;
		$canonical = json_decode( wp_json_encode( $body ), true );
		$meta = in_array( $status, array( 'pending', 'failed' ), true )
			? array( '_wptsall_callback_receipt' => array(
				'version' => 1, 'stage' => 'prepared',
				'device_hash' => hash( 'sha256', 'wptsall-callback-device-v1|' . self::DEVICE ),
			) )
			: array();
		$id        = wptsall_insert_translation_result(
			array_merge(
				array(
					'relation_id'       => $this->relation_id,
					'object_type'       => 'post_type',
					'object_id'         => $this->post_id,
					'translated_fields' => $body['translated_fields'] ?? array(),
					'translated_meta'   => $meta,
					'client_task_id'    => $client_task_id,
					'source_revision'   => (string) ( $body['source_revision'] ?? '' ),
					'policy_version'    => (string) ( $body['policy_version'] ?? '' ),
					'request_hash'      => \WPTSALL\Core\Job_Snapshot::request_body_hash( $canonical ),
					'source_lang'       => 'zh_CN',
					'target_lang'       => 'en_US',
				),
				$extra
			)
		);
		$this->assertGreaterThan( 0, (int) $id, 'result fixture insert' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			wptsall_table( 'translation_results' ),
			array(
				'status'     => $status,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - (int) $age_seconds ),
			),
			array( 'id' => (int) $id )
		);
		return (int) $id;
	}

	private function new_task_id() {
		return self::TASK_PREFIX . uniqid();
	}

	/**
	 * The sync task the callback path materializes for a stored result.
	 *
	 * @param int    $result_id Result the task syncs.
	 * @param int    $object_id Source object.
	 * @param string $subtype   Post type of the source object.
	 * @param string $status    Task status.
	 * @return int
	 */
	private function seed_sync_task( $result_id, $object_id, $subtype = 'post', $status = 'pending' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			wptsall_table( 'tasks' ),
			array(
				'blog_id'     => get_current_blog_id(),
				'site_id'     => $this->relation_id,
				'relation_id' => $this->relation_id,
				'template'    => self::TEMPLATE_PREFIX . 'sync',
				'object_type' => 'post_type',
				'subtype'     => $subtype,
				'object_id'   => (int) $object_id,
				'status'      => $status,
				'payload'     => wp_json_encode( array( 'translation_result_id' => (int) $result_id, 'sync_source' => 'translation_callback' ) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$task_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $task_id, 'sync task fixture: ' . $wpdb->last_error );
		return $task_id;
	}

	/**
	 * Source attachment the fixture relation can sync (removed by clean_own_state).
	 *
	 * @return int
	 */
	private function create_attachment() {
		$id = (int) wp_insert_attachment(
			array(
				'post_title'     => self::ATTACHMENT_TITLE,
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			false
		);
		$this->assertGreaterThan( 0, $id, 'attachment fixture' );
		return $id;
	}

	// ------------------------------------------------------------------
	// D3 transition table: replays
	// ------------------------------------------------------------------

	/**
	 * Every row is: result status, age of the row, whether the caller still holds
	 * the outbox lease, whether the replay body differs, expected HTTP status,
	 * expected error code, expected outbox status, and response flags.
	 *
	 * @return array<string,array>
	 */
	private function replay_table() {
		return array(
			'synced replay'                       => array( 'synced', 0, true, false, 200, null, 'completed', array( 'idempotent' => true ) ),
			'completed replay'                    => array( 'completed', 0, true, false, 200, null, 'completed', array( 'idempotent' => true ) ),
			'partial replay closes the row'       => array( 'partial', 0, true, false, 200, null, 'completed', array( 'idempotent' => true, 'partial' => true ) ),
			'cancelled replay is a skipped ack'   => array( 'cancelled', 0, true, false, 200, null, 'completed', array( 'idempotent' => true, 'skipped' => true ) ),
			'synced replay after the row closed'  => array( 'synced', 0, false, false, 200, null, 'completed', array( 'idempotent' => true ) ),
			'failed replay without a lease'       => array( 'failed', 0, false, false, 409, 'callback_not_replayable', 'pending', array() ),
			'pending in flight'                   => array( 'pending', 300, true, false, 409, 'callback_not_replayable', 'processing', array() ),
			'synced, other body (S3 sticky)'      => array( 'synced', 0, true, true, 409, 'idempotency_conflict', 'processing', array() ),
			'partial, other body (S3 sticky)'     => array( 'partial', 0, true, true, 409, 'idempotency_conflict', 'processing', array() ),
			'cancelled, other body (S3 sticky)'   => array( 'cancelled', 0, true, true, 409, 'idempotency_conflict', 'processing', array() ),
			'failed without lease, other body'    => array( 'failed', 0, false, true, 409, 'idempotency_conflict', 'pending', array() ),
			'pending in flight, other body'       => array( 'pending', 300, true, true, 409, 'idempotency_conflict', 'processing', array() ),
		);
	}

	public function test_replay_transition_table() {
		try {
			foreach ( $this->replay_table() as $label => $row ) {
				list( $status, $age, $lease_held, $other_body, $http, $error, $outbox_after, $flags ) = $row;
				$this->reset_round();
				$client_task_id = $this->new_task_id();
				$body           = $this->callback_body( $client_task_id );
				$result_id      = $this->seed_result( $client_task_id, $status, $body, $age );
				// A released or closed row keeps the owner of its last lease.
				$outbox_id = $this->seed_outbox(
					array(
						'status'      => $lease_held ? 'processing' : ( 'completed' === $outbox_after ? 'completed' : 'pending' ),
						'stamp_owner' => true,
					)
				);

				$sent              = $other_body ? $this->callback_body( $client_task_id, array( 'translated_fields' => array( 'post_title' => 'another body' ) ) ) : $body;
				$sent['outbox_id'] = $outbox_id;
				if ( ! $other_body ) {
					// The client adds outbox_id to the body per lease, the stored hash
					// comes from the first attempt. Routing data must not turn a replay
					// into a conflict.
					$this->assertSame(
						\WPTSALL\Core\Job_Snapshot::request_body_hash( json_decode( wp_json_encode( $body ), true ) ),
						\WPTSALL\Core\Job_Snapshot::request_body_hash( json_decode( wp_json_encode( $sent ), true ) ),
						"{$label}: request hash must not depend on outbox_id"
					);
				}
				$response = $this->post_callback( $sent );
				$data     = $response->get_data();

				$this->assertSame( $http, $response->get_status(), "{$label}: http status " . wp_json_encode( $data ) );
				if ( null !== $error ) {
					$this->assertSame( $error, $data['error'] ?? null, "{$label}: error code" );
				}
				foreach ( $flags as $flag => $value ) {
					$this->assertSame( $value, $data[ $flag ] ?? null, "{$label}: response flag {$flag}" );
				}
				if ( 200 === $http ) {
					$this->assertSame( $result_id, (int) $data['result_id'], "{$label}: answers with the stored result" );
				}
				$this->assertSame( $outbox_after, $this->outbox( $outbox_id )['status'], "{$label}: outbox row afterwards" );
				$this->assertSame( $status, $this->result_by_id( $result_id )['status'], "{$label}: a replay never rewrites the stored result" );
			}
		} finally {
			$this->reset_round();
		}
	}

	public function test_terminal_result_write_failure_does_not_supersede_unknown_target_effects() {
		global $wpdb;
		$client_task_id = $this->new_task_id();
		$body = $this->callback_body( $client_task_id );
		$this->claim_content_as( self::DEVICE );
		$table = wptsall_table( 'translation_results' );
		$hits = 0;
		$fault = static function ( $sql ) use ( $table, &$hits ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` SET' ) && false !== strpos( $sql, "'synced'" ) ) {
				++$hits;
				return 'UPDATE wptsall_receipt_missing_table SET id = 1';
			}
			return $sql;
		};
		add_filter( 'query', $fault );
		try {
			$response = $this->post_callback( $body );
		} finally {
			remove_filter( 'query', $fault );
		}
		$this->assertSame( 1, $hits, 'actual final receipt write must fail after the target write' );
		$this->assertSame( 500, $response->get_status() );
		$original = $this->result_by_task_id( $client_task_id );
		$this->assertIsArray( $original, 'paid callback intent must remain durable' );
		$original_id = (int) $original['id'];
		$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3700 ) ), array( 'id' => $original_id ) );
		$replay = $this->post_callback( $body );
		$this->assertSame( 409, $replay->get_status(), 'unknown target effects require review, never a new attempt' );
		$remaining = $this->result_by_task_id( $client_task_id );
		$this->assertIsArray( $remaining, 'age cannot free the original identity after target effects may exist' );
		$this->assertSame( $original_id, (int) $remaining['id'] );
		$this->assertNotSame( 'synced', $remaining['status'] );
	}

	public function test_terminal_task_projection_failure_refuses_receipt() {
		$body = $this->callback_body( $this->new_task_id() );
		$this->claim_content_as( self::DEVICE );
		$table = wptsall_table( 'tasks' );
		$hits = 0;
		$fault = static function ( $sql ) use ( $table, &$hits ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` SET' ) && false !== strpos( $sql, 'fields_synced' ) && false !== strpos( $sql, 'target_id' ) ) {
				++$hits;
				return 'UPDATE wptsall_receipt_missing_table SET id = 1';
			}
			return $sql;
		};
		add_filter( 'query', $fault );
		try {
			$response = $this->post_callback( $body );
		} finally {
			remove_filter( 'query', $fault );
		}
		$this->assertSame( 1, $hits, 'actual final task projection must fail' );
		$this->assertSame( 500, $response->get_status(), 'a saved result alone cannot hide a failed task projection' );
		$result = $this->result_by_task_id( $body['client_task_id'] );
		$this->assertIsArray( $result );
		$this->assertNotSame( 'synced', $result['status'], 'task and terminal receipt must commit together' );
	}

	public function test_legacy_pending_failed_rows_with_unknown_effects_keep_original_identity() {
		foreach ( array( 'pending', 'failed' ) as $status ) {
			$body = $this->callback_body( $this->new_task_id() );
			$id = $this->seed_result( $body['client_task_id'], $status, $body, 3700, array( 'translated_meta' => array() ) );
			$body['outbox_id'] = $this->seed_outbox();
			$response = $this->post_callback( $body );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'callback_not_replayable', $response->get_data()['error'] );
			$this->assertSame( $id, (int) $this->result_by_task_id( $body['client_task_id'] )['id'], 'legacy state is not proof of non-delivery' );
			$this->reset_round();
		}
	}

	public function test_second_outbox_row_of_the_same_snapshot_closes_as_a_replay() {
		// Two lifecycle events for one unchanged object give the client one
		// snapshot, so one client_task_id; the second row's callback carries its own
		// outbox_id. It is the same work, already applied: acknowledge it and close
		// its row instead of leaving it to be retried until it dies.
		$client_task_id = $this->new_task_id();
		$body           = $this->callback_body( $client_task_id );
		$result_id      = $this->seed_result( $client_task_id, 'synced', $body );
		$first          = $this->seed_outbox( array( 'status' => 'completed' ) );
		$second         = $this->seed_outbox( array( 'status' => 'processing' ) );

		$sent              = $body;
		$sent['outbox_id'] = $second;
		$response          = $this->post_callback( $sent );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['idempotent'] ?? false );
		$this->assertSame( $result_id, (int) $response->get_data()['result_id'] );
		$this->assertSame( 'completed', $this->outbox( $second )['status'], 'the second row is closed by the replay' );
		$this->assertSame( 'completed', $this->outbox( $first )['status'] );

		// The hash still separates different content: only the routing id is ignored.
		$other              = $this->callback_body( $client_task_id, array( 'translated_fields' => array( 'post_title' => 'another body' ) ) );
		$other['outbox_id'] = $second;
		$this->assertSame( 409, $this->post_callback( $other )->get_status() );
	}

	// ------------------------------------------------------------------
	// Takeover: failed retry by the lease holder, stale pending (ATS-04)
	// ------------------------------------------------------------------

	public function test_lease_holder_retry_of_a_failed_result_runs_as_a_new_attempt() {
		$client_task_id = $this->new_task_id();
		$body           = $this->callback_body( $client_task_id );
		$failed_id      = $this->seed_result( $client_task_id, 'failed', $body );
		$outbox_id      = $this->seed_outbox( array( 'status' => 'processing', 'attempts' => 2 ) );
		$this->claim_content_as( self::DEVICE );

		// Re-leasing may resume the frozen paid result, not replace its body.
		$different = $this->callback_body( $client_task_id, array( 'translated_fields' => array( 'post_title' => 'Different paid output' ) ) );
		$different['outbox_id'] = $outbox_id;
		$this->assertSame( 409, $this->post_callback( $different )->get_status(), 'prepared evidence is still bound to the original request' );
		$retry = $body;
		$retry['outbox_id'] = $outbox_id;
		$response           = $this->post_callback( $retry );
		$data               = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'the retry of a failed callback must run: ' . wp_json_encode( $data ) );
		$this->assertTrue( $data['success'] );
		$this->assertArrayNotHasKey( 'idempotent', $data, 'a takeover is a new attempt, not a replay' );
		$this->assertNotSame( $failed_id, (int) $data['result_id'], 'the retry stores its own result' );

		$new = $this->result_by_task_id( $client_task_id );
		$this->assertSame( (int) $data['result_id'], (int) $new['id'] );
		$this->assertSame( 'synced', $new['status'] );

		$old = $this->result_by_id( $failed_id );
		$this->assertSame( 'failed', $old['status'], 'the failed attempt stays as audit evidence' );
		$this->assertStringContainsString( '~s' . $failed_id, $old['client_task_id'], 'renamed out of the way, not deleted' );
		$this->assertLessThanOrEqual( 100, strlen( $old['client_task_id'] ), 'fits client_task_id VARCHAR(100)' );

		$this->assertSame( 'completed', $this->outbox( $outbox_id )['status'], 'the successful retry closes the leased row' );

		// S3 stays sticky for the new, accepted result.
		$third = $this->callback_body( $client_task_id, array( 'translated_fields' => array( 'post_title' => 'yet another body' ) ) );
		$this->assertSame( 409, $this->post_callback( $third )->get_status(), 'an accepted result still rejects a different body' );
	}

	public function test_stale_pending_result_is_taken_over_and_its_open_task_failed() {
		$this->assert_stale_pending_takeover();
	}

	public function test_stale_takeover_closes_its_task_even_when_other_payloads_are_not_json() {
		global $wpdb;
		$unrelated = array();
		try {
			foreach ( array( '', 'not-json', '{"translation_result_id":' ) as $offset => $payload ) {
				$task_id = $this->seed_sync_task( 0, $this->post_id + 100 + $offset, 'post', 'active' );
				// A legacy/monitoring task need not carry a valid callback JSON object.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( wptsall_table( 'tasks' ), array( 'payload' => $payload ), array( 'id' => $task_id ) );
				$this->assertSame( '', (string) $wpdb->last_error, 'malformed-payload fixture: ' . $wpdb->last_error );
				$unrelated[] = $task_id;
			}

			$this->assert_stale_pending_takeover();

			foreach ( $unrelated as $task_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', wptsall_table( 'tasks' ), $task_id ) );
				$this->assertSame( 'active', $status, 'an unrelated task is not superseded' );
			}
		} finally {
			$this->clean_own_state();
		}
	}

	public function test_stale_takeover_keeps_the_old_result_when_its_task_cannot_be_closed() {
		$client_task_id = $this->new_task_id();
		$body          = $this->callback_body( $client_task_id );
		$stale_id      = $this->seed_result( $client_task_id, 'pending', $body, 3700 );
		$this->seed_sync_task( $stale_id, $this->post_id, 'post', 'processing' );
		$outbox_id = $this->seed_outbox( array( 'status' => 'processing' ) );
		$this->claim_content_as( self::DEVICE );
		$body['outbox_id'] = $outbox_id;

		$redirect = static function ( $tables ) {
			$tables['tasks'] = 'wptsall_terminal_states_missing_tasks';
			return $tables;
		};
		add_filter( 'wptsall_table_names', $redirect );
		try {
			$response = $this->post_callback( $body );
		} finally {
			remove_filter( 'wptsall_table_names', $redirect );
		}

		$this->assertSame( 409, $response->get_status(), 'a failed takeover does not start a new attempt' );
		$this->assertSame( 'callback_not_replayable', $response->get_data()['error'] ?? null );
		$old = $this->result_by_task_id( $client_task_id );
		$this->assertSame( $stale_id, (int) $old['id'], 'the old callback identity was not freed' );
		$this->assertSame( 'pending', $old['status'], 'renaming and failing the result rolled back with the task update' );

		$retry = $this->post_callback( $body );
		$this->assertSame( 200, $retry->get_status(), 'the takeover can be retried after persistence recovers' );
		$this->assertNotSame( $stale_id, (int) $retry->get_data()['result_id'] );
		$this->assertSame( 'completed', $this->outbox( $outbox_id )['status'] );
	}

	private function assert_stale_pending_takeover() {
		global $wpdb;
		$client_task_id = $this->new_task_id();
		$body           = $this->callback_body( $client_task_id );
		// A request that died after COMMIT: result pending for over an hour, its
		// sync task still open.
		$stale_id      = $this->seed_result( $client_task_id, 'pending', $body, 3700 );
		$stale_task_id = $this->seed_sync_task( $stale_id, $this->post_id, 'post', 'processing' );
		$outbox_id     = $this->seed_outbox( array( 'status' => 'processing' ) );
		$this->claim_content_as( self::DEVICE );

		$sent              = $body;
		$sent['outbox_id'] = $outbox_id;
		$response          = $this->post_callback( $sent );
		$data              = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'a pending result nobody can finish must not block the callback forever: ' . wp_json_encode( $data ) );
		$this->assertNotSame( $stale_id, (int) $data['result_id'] );
		$stale = $this->result_by_id( $stale_id );
		$this->assertSame( 'failed', $stale['status'], 'the dead request is settled as failed' );
		$this->assertStringContainsString( '~s' . $stale_id, $stale['client_task_id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 'failed', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', wptsall_table( 'tasks' ), $stale_task_id ) ), 'the abandoned attempt\'s open task cannot be reused by the new one' );
		$this->assertSame( 'synced', $this->result_by_task_id( $client_task_id )['status'] );
		$this->assertSame( 'completed', $this->outbox( $outbox_id )['status'] );
	}

	// ------------------------------------------------------------------
	// partial means "accepted with a parked remainder" only (ATS-02)
	// ------------------------------------------------------------------

	public function test_write_back_parked_in_the_manual_queue_is_an_accepted_partial() {
		global $wpdb;
		$client_task_id = $this->new_task_id();
		$outbox_id      = $this->seed_outbox( array( 'status' => 'processing' ) );
		$this->claim_content_as( self::DEVICE );
		$body              = $this->callback_body( $client_task_id, array( 'media_mappings' => $this->unhandled_media_mapping() ) );
		$body['outbox_id'] = $outbox_id;

		$response = $this->post_callback( $body );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['partial'] ?? false, 'the response says part of the media waits for an operator' );
		$this->assertTrue( $data['sync_result']['partial'] ?? false );
		$this->assertSame( 'partial', $this->result_by_task_id( $client_task_id )['status'] );
		$this->assertSame( 'completed', $this->outbox( $outbox_id )['status'], 'parked work is the operator\'s now - the lifecycle row closes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertGreaterThanOrEqual( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE relation_id = %d', wptsall_table( 'manual_queue' ), $this->relation_id ) ), 'the remainder is really in the manual queue' );
	}

	public function test_write_back_lost_entirely_keeps_original_partial_target_effects_for_review() {
		global $wpdb;
		$client_task_id = $this->new_task_id();
		$outbox_id      = $this->seed_outbox( array( 'status' => 'processing' ) );
		$this->claim_content_as( self::DEVICE );
		$body              = $this->callback_body( $client_task_id, array( 'media_mappings' => $this->unhandled_media_mapping() ) );
		$body['outbox_id'] = $outbox_id;

		// Memoize "the manual queue table exists" while the real table is still
		// reachable, then point the queue at a table that is not there: the item
		// reaches neither the target nor the queue.
		\WPTSALL\Tasks\Sync\Manual_Queue::get_items( array( 'limit' => 1 ) );
		$redirect = static function ( $tables ) {
			$tables['manual_queue'] = 'wptsall_terminal_states_missing_queue';
			return $tables;
		};
		add_filter( 'wptsall_table_names', $redirect );
		try {
			$response = $this->post_callback( $body );
		} finally {
			remove_filter( 'wptsall_table_names', $redirect );
		}
		$data = $response->get_data();

		$this->assertSame( 500, $response->get_status(), 'lost work is a failed callback, not a partial success: ' . wp_json_encode( $data ) );
		$this->assertSame( 'target_write_failed', $data['error'] ?? null );
		$this->assertSame( 'failed', $this->result_by_task_id( $client_task_id )['status'], 'the stored result must not read as an accepted partial' );

		$row = $this->outbox( $outbox_id );
		$this->assertSame( 'pending', $row['status'], 'the lifecycle row is released for another attempt, not closed' );
		$this->assertGreaterThanOrEqual( 25, strtotime( $row['available_at'] . ' UTC' ) - time(), 'and backs off instead of retrying in a tight loop' );

		// The client retrying the very same callback must not be told "done".
		$replay = $this->post_callback( $body );
		$this->assertSame( 409, $replay->get_status(), 'replaying a failed callback is not a success' );
		$this->assertSame( 'callback_not_replayable', $replay->get_data()['error'] ?? null );
		$this->assertSame( 'pending', $this->outbox( $outbox_id )['status'] );

		// Even after the queue is fixed, the target already changed. Re-leasing
		// is not proof that applying the complete callback again is safe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( wptsall_table( 'content_change_outbox' ), array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $outbox_id ) );
		$claimed = Content_Change_Dispatcher::claim_outbox( 5, 900, $this->relation_id, self::DEVICE );
		$this->assertSame( array( $outbox_id ), array_map( 'intval', wp_list_pluck( $claimed, 'id' ) ) );
		$this->assertSame( 2, (int) $claimed[0]['attempts'] );
		$retry = $this->post_callback( $body );
		$this->assertSame( 409, $retry->get_status(), 'partial target effects need explicit review, not blind replacement' );
		$this->assertSame( 'callback_not_replayable', $retry->get_data()['error'] ?? null );
		$this->assertSame( (int) $data['result_id'], (int) $this->result_by_task_id( $client_task_id )['id'] );
		$this->assertSame( 'pending', $this->outbox( $outbox_id )['status'] );
	}

	public function test_relation_that_turned_inactive_before_the_sync_fails_the_result() {
		global $wpdb;
		// The controller checks the relation up front; this is the window between
		// that check and the sync. Nothing was delivered and the caller gets an
		// error, so the result may not read as `cancelled` ("skipped on purpose",
		// which a replay answers with 200).
		$client_task_id = $this->new_task_id();
		$body           = $this->callback_body( $client_task_id );
		$result_id      = $this->seed_result( $client_task_id, 'pending', $body );
		$task_id        = $this->seed_sync_task( $result_id, $this->post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( wptsall_table( 'site_relations' ), array( 'status' => 'inactive' ), array( 'id' => $this->relation_id ) );

		$outcome = \WPTSALL\Tasks\Sync\Sync_Executor::execute_translation_sync( $task_id );

		$this->assertInstanceOf( WP_Error::class, $outcome );
		$this->assertSame( 'relation_inactive', $outcome->get_error_code() );
		$this->assertSame( 'failed', $this->result_by_id( $result_id )['status'] );
		$replay = $this->post_callback( $body );
		$this->assertSame( 409, $replay->get_status(), 'a callback that delivered nothing is not a success on replay' );
		$this->assertSame( 'callback_not_replayable', $replay->get_data()['error'] ?? null );
	}

	/**
	 * Run the sync of an attachment whose binary is handed to the write-back
	 * dispatcher as an unhandled media mapping.
	 *
	 * @param string $client_task_id Task id.
	 * @return array{0:array|WP_Error,1:int,2:array} Sync outcome, result id, callback body.
	 */
	private function sync_attachment_binary( $client_task_id ) {
		$attachment_id = $this->create_attachment();
		$mappings      = $this->unhandled_media_mapping();
		$body          = $this->callback_body(
			$client_task_id,
			array(
				'object_id'       => $attachment_id,
				'post_type'       => 'attachment',
				'source_revision' => \WPTSALL\Core\Job_Snapshot::compute_source_revision( 'post_type', $attachment_id ),
				'media_mappings'  => $mappings,
			)
		);
		$result_id     = $this->seed_result( $client_task_id, 'pending', $body, 0, array( 'object_id' => $attachment_id, 'media_mappings' => $mappings ) );
		$task_id       = $this->seed_sync_task( $result_id, $attachment_id, 'attachment' );
		return array( \WPTSALL\Tasks\Sync\Sync_Executor::execute_translation_sync( $task_id ), $result_id, $body );
	}

	public function test_attachment_binary_parked_in_the_manual_queue_is_an_accepted_partial() {
		global $wpdb;
		$client_task_id = $this->new_task_id();

		list( $outcome, $result_id, $body ) = $this->sync_attachment_binary( $client_task_id );

		$this->assertIsArray( $outcome, 'a parked binary is not an error: ' . ( is_wp_error( $outcome ) ? $outcome->get_error_code() . ' ' . $outcome->get_error_message() : '' ) );
		$this->assertTrue( $outcome['success'] );
		$this->assertTrue( $outcome['partial'] ?? false, 'the operator still has to apply the binary' );
		$this->assertSame( 'partial', $this->result_by_id( $result_id )['status'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE relation_id = %d', wptsall_table( 'manual_queue' ), $this->relation_id ) ), 'queued once' );
		$replay = $this->post_callback( $body );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertTrue( $replay->get_data()['partial'] ?? false );
	}

	public function test_attachment_binary_lost_entirely_is_a_failed_result() {
		$client_task_id = $this->new_task_id();
		\WPTSALL\Tasks\Sync\Manual_Queue::get_items( array( 'limit' => 1 ) );
		$redirect = static function ( $tables ) {
			$tables['manual_queue'] = 'wptsall_terminal_states_missing_queue';
			return $tables;
		};
		add_filter( 'wptsall_table_names', $redirect );
		try {
			list( $outcome, $result_id, $body ) = $this->sync_attachment_binary( $client_task_id );
		} finally {
			remove_filter( 'wptsall_table_names', $redirect );
		}

		$this->assertInstanceOf( WP_Error::class, $outcome );
		$this->assertSame( 'attachment_binary_writeback_failed', $outcome->get_error_code() );
		$this->assertSame( 'failed', $this->result_by_id( $result_id )['status'], 'an error answer may not leave an accepted-looking result behind' );
		$this->assertSame( 409, $this->post_callback( $body )->get_status() );
	}

	// ------------------------------------------------------------------
	// A callback without outbox_id does not guess (ATS-03)
	// ------------------------------------------------------------------

	public function test_callback_without_outbox_id_does_not_pick_between_two_leased_rows() {
		$first  = $this->seed_outbox( array( 'status' => 'processing' ) );
		$second = $this->seed_outbox( array( 'status' => 'processing', 'event_name' => 'post_untrashed' ) );
		$this->claim_content_as( self::DEVICE );

		$response = $this->post_callback( $this->callback_body( $this->new_task_id() ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'processing', $this->outbox( $first )['status'], 'with two candidates no row may be closed on a guess' );
		$this->assertSame( 'processing', $this->outbox( $second )['status'], 'and neither may the newer one' );
	}

	public function test_callback_without_outbox_id_closes_the_single_row_leased_to_the_caller() {
		$mine  = $this->seed_outbox( array( 'status' => 'processing' ) );
		$other = $this->seed_outbox( array( 'status' => 'processing', 'device' => self::OTHER_DEVICE ) );
		$this->claim_content_as( self::DEVICE );

		$response = $this->post_callback( $this->callback_body( $this->new_task_id() ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'completed', $this->outbox( $mine )['status'], 'the one unambiguous row of the calling device is closed (legacy clients keep working)' );
		$this->assertSame( 'processing', $this->outbox( $other )['status'], 'another device\'s lease is never touched' );
	}

	public function test_callback_without_outbox_id_and_without_device_closes_nothing() {
		$row = $this->seed_outbox( array( 'status' => 'processing' ) );
		$this->claim_content_as( self::DEVICE );

		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $this->callback_body( $this->new_task_id() ) ) );
		$this->controller->translation_callback( $request );

		$this->assertSame( 'processing', $this->outbox( $row )['status'], 'no device, no way to prove which lease this callback belongs to' );
	}

	public function test_failed_callback_without_outbox_id_does_not_release_another_devices_lease() {
		$other = $this->seed_outbox( array( 'status' => 'processing', 'device' => self::OTHER_DEVICE ) );
		// No content claim: the callback fails its claim check (409).
		$response = $this->post_callback( $this->callback_body( $this->new_task_id() ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status(), 'fixture: the callback must fail' );
		$this->assertSame( 'processing', $this->outbox( $other )['status'], 'a failing callback must not release a lease held by another device' );
	}

	// ------------------------------------------------------------------
	// content-changes: permanent errors end the row, transient ones back off (ATS-01)
	// ------------------------------------------------------------------

	private function get_content_changes( $device = self::DEVICE ) {
		$request = new WP_REST_Request( 'GET', '/wptsall/v2/client/content-changes' );
		$request->set_header( 'X-WPTSALL-Device-Id', $device );
		$request->set_param( 'relation_id', $this->relation_id );
		$request->set_param( 'limit', 20 );
		$response = $this->controller->get_content_changes( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['data']['items'] ?? array();
	}

	public function test_unknown_callback_effects_block_fresh_content_claims_before_translation() {
		$body = $this->callback_body( $this->new_task_id() );
		$this->seed_result( $body['client_task_id'], 'applying', $body );
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/content/claim' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Device-Id', self::DEVICE );
		$request->set_body( wp_json_encode( array(
			'relation_id' => $this->relation_id, 'data_type' => 'post',
			'items' => array( array( 'object_id' => $this->post_id, 'post_type' => 'post' ) ),
		) ) );
		$response = $this->controller->claim_content( $request );
		$this->assertSame( 409, $response->get_status(), 'a new provider attempt cannot bypass unknown saved target effects' );
		$this->assertSame( 'callback_effects_unresolved', $response->get_data()['error'] ?? null );
	}

	public function test_unknown_callback_effects_park_outbox_before_sending_work_to_client() {
		$body = $this->callback_body( $this->new_task_id() );
		$this->seed_result( $body['client_task_id'], 'applying', $body );
		$row = $this->seed_outbox( array( 'status' => 'pending', 'attempts' => 0 ) );
		$this->assertSame( array(), $this->get_content_changes(), 'unknown effects must not be handed to a new translating client' );
		$this->assertSame( 'dead', $this->outbox( $row )['status'], 'retain the original dead letter for explicit review' );
		$this->assertSame( 'callback_effects_unresolved', $this->outbox( $row )['last_error'] );
	}

	public function test_content_changes_abandons_rows_that_no_retry_can_fix() {
		$trashed  = $this->seed_outbox( array( 'status' => 'pending', 'attempts' => 0, 'event_name' => 'post_trashed', 'post_type' => false ) );
		$gone     = $this->seed_outbox( array( 'status' => 'pending', 'attempts' => 0, 'source_id' => 987650000 + mt_rand( 1, 9999 ) ) );
		$ordinary = $this->seed_outbox( array( 'status' => 'pending', 'attempts' => 0 ) );

		$items = $this->get_content_changes();

		$this->assertSame( array( $ordinary ), array_map( 'intval', wp_list_pluck( $items, 'outbox_id' ) ), 'only the ordinary event is handed to the client' );
		$this->assertSame( 'processing', $this->outbox( $ordinary )['status'] );

		$row = $this->outbox( $trashed );
		$this->assertSame( 'dead', $row['status'], 'trash/untrash/delete events carry no post type; no claim or translation can ever handle them' );
		$this->assertSame( 'unsupported_lifecycle_event', $row['last_error'] );

		$row = $this->outbox( $gone );
		$this->assertSame( 'dead', $row['status'], 'an event for a source object that does not exist is final' );
		$this->assertSame( 'source_object_not_found', $row['last_error'] );

		// Nothing is left to hand out, and nothing wakes the long-poll for them.
		$this->assertSame( array(), $this->get_content_changes(), 'dead rows are not leased again' );
	}

	public function test_content_changes_keeps_a_claim_conflict_retryable_with_backoff() {
		// Another device holds the source mapping's lease: transient by nature.
		$this->claim_content_as( self::OTHER_DEVICE );
		$row_id = $this->seed_outbox( array( 'status' => 'pending', 'attempts' => 0 ) );

		$this->assertSame( array(), $this->get_content_changes(), 'the conflicting row is not handed out' );

		$row = $this->outbox( $row_id );
		$this->assertSame( 'pending', $row['status'], 'a conflict that can clear by itself is not a dead letter' );
		$this->assertSame( 'content_claim_unavailable', $row['last_error'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertGreaterThanOrEqual( 25, strtotime( $row['available_at'] . ' UTC' ) - time(), 'it waits before the next lease instead of spinning' );
	}
}

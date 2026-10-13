<?php
/**
 * Durable callback receipts, batch rollback and outbox acknowledgment.
 *
 * catalog: WP-WRITE-handle_content_callback
 * catalog: WP-WRITE-handle_site_string_callback
 * oracle: L2
 */

use WPTSALL\Tasks\API\Client_Data_REST_Controller;
use WPTSALL\Hooks\Content_Change_Dispatcher;

class Test_Callback_Receipt_Consistency extends WP_UnitTestCase {
	const DEVICE = 'test-callback-receipt-device';
	const PREFIX = 'test-callback-receipt-';
	private $controller;
	private $relation_id;
	private $template_id;
	private $entry_ids = array();
	private $string_ids = array();
	private $cache_keys = array();

	public function setUp(): void {
		parent::setUp();
		$root = trailingslashit( WP_PLUGIN_DIR ) . 'wpmmcc-ats/includes/';
		require_once $root . 'tasks/database/schema-tasks.php';
		require_once $root . 'tasks/database/schema-translation-results.php';
		require_once $root . 'tasks/services/class-origin-visit-service.php';
		require_once $root . 'tasks/tasks.php';
		require_once $root . 'tasks/tasks-single.php';
		require_once $root . 'tasks/api/class-client-data-rest-controller.php';
		require_once $root . 'hooks/class-content-change-dispatcher.php';
		require_once $root . 'models/database/schema-field-mappings.php';
		require_once $root . 'templates/database/schema-templates.php';
		wptsall_ensure_task_table();
		wptsall_create_translation_results_table();
		wptsall_create_content_change_outbox_table();
		wptsall_create_templates_table();
		wptsall_create_template_entries_table();
		$this->controller = new Client_Data_REST_Controller();
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert(
			wptsall_table( 'site_relations' ),
			array(
				'source_site_id' => get_current_blog_id(), 'source_site_type' => 'wp',
				'source_lang' => 'zh_CN', 'target_lang' => 'en_US',
				'template' => self::PREFIX . uniqid(), 'target_site_type' => 'virtual',
				'target_site_id' => 'v_receipt_' . uniqid(), 'status' => 'active',
				'created_at' => $now, 'updated_at' => $now,
			)
		);
		$this->assertSame( 1, $ok, 'relation fixture: ' . $wpdb->last_error );
		$this->relation_id = (int) $wpdb->insert_id;
		$this->entry_ids = array();
		$this->string_ids = array();
		$this->template_id = null;
		$this->cache_keys = array();
	}

	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		foreach ( $this->cache_keys as $key ) {
			delete_transient( 'wptsall_idem_' . md5( $key ) );
		}
		if ( $this->template_id ) {
			$wpdb->delete( wptsall_table( 'template_entries' ), array( 'template_id' => $this->template_id ) );
			$wpdb->delete( wptsall_table( 'templates' ), array( 'id' => $this->template_id ) );
		}
		foreach ( $this->string_ids as $id ) {
			\WPTSALL\Strings\Services\String_Translation_Service::delete( $id );
		}
		foreach ( array( 'tasks', 'translation_results', 'content_change_outbox', 'relation_post_type_configs' ) as $key ) {
			$wpdb->delete( wptsall_table( $key ), array( 'relation_id' => $this->relation_id ) );
		}
		$wpdb->delete( wptsall_table( 'site_relations' ), array( 'id' => $this->relation_id ) );
		parent::tearDown();
	}

	private function owner( $scope ) {
		return function_exists( 'wptsall_client_claim_owner_hash' )
			? wptsall_client_claim_owner_hash( self::DEVICE, $this->relation_id, 'outbox' === $scope ? '' : 'en_US', $scope )
			: hash( 'sha256', 'wptsall-claim-owner-v1|' . self::DEVICE . '|' . $this->relation_id . '|' . ( 'outbox' === $scope ? '' : 'en_US' ) . '|' . $scope );
	}

	private function seed_entries() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->assertSame(
			1,
			$wpdb->insert(
				wptsall_table( 'templates' ),
				array(
					'relation_id' => $this->relation_id, 'slug' => self::PREFIX . uniqid(),
					'source_type' => 'plugin', 'source_identifier' => 'receipt-fixture',
					'text_domain' => self::PREFIX . uniqid(), 'target_language' => 'en_US',
					'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
				)
			),
			'template fixture: ' . $wpdb->last_error
		);
		$this->template_id = (int) $wpdb->insert_id;
		for ( $i = 0; $i < 2; ++$i ) {
			$this->assertSame(
				1,
				$wpdb->insert(
					wptsall_table( 'template_entries' ),
					array(
						'template_id' => $this->template_id, 'msgid' => 'Receipt source ' . $i,
						'msgstr' => '', 'msgctxt' => '', 'source' => 'scan', 'reference' => 'receipt.php:1',
						'status' => 'pending', 'claimed_at' => $now, 'claim_owner_hash' => $this->owner( 'plugin' ),
						'created_at' => $now, 'updated_at' => $now,
					)
				),
				'entry fixture: ' . $wpdb->last_error
			);
			$this->entry_ids[] = (int) $wpdb->insert_id;
		}
		return array(
			'business_line' => 'plugin_i18n', 'client_task_id' => self::PREFIX . uniqid(),
			'relation_id' => $this->relation_id, 'source_lang' => 'zh_CN', 'target_lang' => 'en_US',
			'entries' => array(
				array( 'entry_id' => $this->entry_ids[0], 'msgstr' => 'Owned translation one' ),
				array( 'entry_id' => $this->entry_ids[1], 'msgstr' => 'Owned translation two' ),
			),
		);
	}

	private function post_callback( array $body, $key = '', $device = self::DEVICE ) {
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/translation-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Device-Id', $device );
		if ( '' !== $key ) {
			$request->set_header( 'Idempotency-Key', $key );
			$this->cache_keys[] = $key;
		}
		$request->set_body( wp_json_encode( $body ) );
		return $this->controller->translation_callback( $request );
	}

	private function assert_entries_unmodified() {
		global $wpdb;
		foreach ( $this->entry_ids as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', wptsall_table( 'template_entries' ), $id ), ARRAY_A );
			$this->assertSame( '', $row['msgstr'], 'a failed batch must not consume any saved translation' );
			$this->assertSame( 'pending', $row['status'] );
			$this->assertSame( $this->owner( 'plugin' ), $row['claim_owner_hash'], 'retry must still own the original claim' );
			$this->assertNotEmpty( $row['claimed_at'] );
		}
	}

	private function assert_no_result( array $body ) {
		global $wpdb;
		$this->assertSame(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_task_id = %s', wptsall_table( 'translation_results' ), $body['client_task_id'] ) ),
			'a rolled-back batch must not leave a false receipt'
		);
	}

	public function test_i18n_receipt_is_replayable_without_transient_cache() {
		global $wpdb;
		$body = $this->seed_entries();
		$first = $this->post_callback( $body );
		$this->assertSame( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$second = $this->post_callback( $body );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 'v2', $second->get_data()['protocol'] ?? null, 'durable replay must satisfy the Client ack contract' );
		$this->assertSame( 2, $second->get_data()['entries_updated'] ?? null, 'lost response must replay the original complete batch receipt' );
		$this->assertSame( (int) $first->get_data()['result_id'], (int) $second->get_data()['result_id'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_task_id = %s', wptsall_table( 'translation_results' ), $body['client_task_id'] ) ) );
	}

	public function test_i18n_second_sql_failure_rolls_back_the_whole_batch() {
		global $wpdb;
		$body = $this->seed_entries();
		$hits = 0;
		$table = wptsall_table( 'template_entries' );
		$fault = static function ( $sql ) use ( $table, &$hits ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` e INNER JOIN' ) && ++$hits === 2 ) {
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
		$this->assertSame( 2, $hits, 'fault must hit the second actual entry UPDATE' );
		$this->assertSame( 500, $response->get_status(), 'a SQL failure is not an accepted batch: ' . wp_json_encode( $response->get_data() ) );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assert_entries_unmodified();
		$this->assert_no_result( $body );
		$this->assertSame( 200, $this->post_callback( $body )->get_status(), 'same paid result retries after storage recovers' );
	}

	public function test_i18n_result_insert_failure_keeps_all_original_claims() {
		$body = $this->seed_entries();
		$hits = 0;
		$table = wptsall_table( 'translation_results' );
		$fault = static function ( $sql ) use ( $table, &$hits ) {
			if ( 0 === strpos( $sql, 'INSERT INTO `' . $table . '`' ) ) {
				++$hits;
				return 'INSERT INTO wptsall_receipt_missing_table (id) VALUES (1)';
			}
			return $sql;
		};
		add_filter( 'query', $fault );
		try {
			$response = $this->post_callback( $body );
		} finally {
			remove_filter( 'query', $fault );
		}
		$this->assertSame( 1, $hits );
		$this->assertSame( 500, $response->get_status(), 'no receipt means no success' );
		$this->assert_entries_unmodified();
		$this->assert_no_result( $body );
		$this->assertSame( 200, $this->post_callback( $body )->get_status() );
	}

	public function test_i18n_mixed_claim_refusal_has_no_partial_write() {
		global $wpdb;
		$body = $this->seed_entries();
		$wpdb->update( wptsall_table( 'template_entries' ), array( 'claim_owner_hash' => str_repeat( 'b', 64 ) ), array( 'id' => $this->entry_ids[1] ) );
		$response = $this->post_callback( $body );
		$this->assertSame( 409, $response->get_status() );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', wptsall_table( 'template_entries' ), $this->entry_ids[0] ), ARRAY_A );
		$this->assertSame( '', $row['msgstr'], 'one foreign claim must refuse the entire batch before write-back' );
		$this->assertSame( $this->owner( 'plugin' ), $row['claim_owner_hash'] );
		$this->assert_no_result( $body );
	}

	public function test_i18n_receipt_write_failure_rolls_back_entries_and_notifications() {
		$body = $this->seed_entries();
		$hits = 0;
		$notified = array();
		$table = wptsall_table( 'translation_results' );
		$fault = static function ( $sql ) use ( $table, &$hits ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` SET `translated_fields`' ) ) {
				++$hits;
				return 'UPDATE wptsall_receipt_missing_table SET id = 1';
			}
			return $sql;
		};
		$listener = static function ( $id ) use ( &$notified ) { $notified[] = $id; };
		add_filter( 'query', $fault );
		add_action( 'wptsall_entry_updated', $listener );
		try {
			$response = $this->post_callback( $body );
		} finally {
			remove_filter( 'query', $fault );
			remove_action( 'wptsall_entry_updated', $listener );
		}
		$this->assertSame( 1, $hits );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array(), $notified );
		$this->assert_entries_unmodified();
		$this->assert_no_result( $body );
		$this->assertSame( 200, $this->post_callback( $body )->get_status() );
	}

	public function test_i18n_commit_failure_keeps_entries_claims_and_receipt_retryable() {
		$body = $this->seed_entries();
		$hits = 0;
		$fault = static function ( $sql ) use ( &$hits ) {
			if ( 'COMMIT' === $sql ) {
				++$hits;
				return 'SELECT id FROM wptsall_receipt_missing_table';
			}
			return $sql;
		};
		add_filter( 'query', $fault );
		try {
			$response = $this->post_callback( $body );
		} finally {
			remove_filter( 'query', $fault );
		}
		$this->assertSame( 1, $hits );
		$this->assertSame( 500, $response->get_status() );
		$this->assert_entries_unmodified();
		$this->assert_no_result( $body );
		$this->assertSame( 200, $this->post_callback( $body )->get_status() );
	}

	public function test_i18n_duplicate_entry_and_expired_claim_refuse_without_writes() {
		global $wpdb;
		$body = $this->seed_entries();
		$duplicate = $body;
		$duplicate['entries'][1] = $duplicate['entries'][0];
		$this->assertSame( 409, $this->post_callback( $duplicate )->get_status() );
		$this->assert_entries_unmodified();
		$this->assert_no_result( $body );
		$wpdb->update( wptsall_table( 'template_entries' ), array( 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ), array( 'id' => $this->entry_ids[1] ) );
		$this->assertSame( 409, $this->post_callback( $body )->get_status() );
		$this->assert_entries_unmodified();
		$this->assert_no_result( $body );
	}

	private function seed_strings( $lane = 'site' ) {
		$this->assertTrue( function_exists( 'wptsall_create_strings_table' ), 'real strings schema must be loaded' );
		wptsall_create_strings_table();
		$this->assertNotFalse(
			\WPTSALL\Sites\Services\Relation_Config_Service::save_template_config(
				$this->relation_id,
				array( 'translate_site_strings' => true, 'translate_menu_strings' => true, 'translate_widget_strings' => true )
			)
		);
		$svc = '\\WPTSALL\\Strings\\Services\\String_Translation_Service';
		$context = 'site' === $lane ? 'site_title' : $lane;
		$ids = array();
		for ( $i = 0; $i < 2; ++$i ) {
			$id = $svc::register( $context, self::PREFIX . uniqid(), 'Owned string source ' . $i, 'zh_CN' );
			$this->assertGreaterThan( 0, (int) $id );
			$this->string_ids[] = (int) $id;
			$ids[] = (int) $id;
		}
		$claimed = $svc::claim_string_ids( $ids, array( $context ), $this->owner( $lane ), 'en_US' );
		$this->assertSame( $ids, $claimed, 'both actual string claims must be owned' );
		return array(
			'business_line' => $lane . '_strings', 'client_task_id' => self::PREFIX . uniqid(),
			'relation_id' => $this->relation_id, 'source_lang' => 'zh_CN', 'target_lang' => 'en_US',
			'entries' => array(
				array( 'entry_id' => $ids[0], 'msgstr' => 'Owned string translation one' ),
				array( 'entry_id' => $ids[1], 'msgstr' => 'Owned string translation two' ),
			),
		);
	}

	private function assert_strings_unmodified( array $body ) {
		foreach ( $body['entries'] as $entry ) {
			$row = \WPTSALL\Strings\Services\String_Translation_Service::get( $entry['entry_id'] );
			$this->assertEmpty( json_decode( (string) $row['translations'], true ), 'failed string batch must roll back its translation map' );
			$this->assertSame( 'pending', $row['status'] );
			$this->assertNotEmpty( $row['claimed_at'] );
		}
	}

	public function test_site_menu_widget_callbacks_have_replayable_batch_receipts() {
		foreach ( array( 'site', 'menu', 'widget' ) as $lane ) {
			$body = $this->seed_strings( $lane );
			$first = $this->post_callback( $body );
			$this->assertSame( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
			$this->assertGreaterThan( 0, (int) ( $first->get_data()['result_id'] ?? 0 ), $lane . ' must have a durable result receipt, not only an updated count' );
			$this->assertSame( 'synced', $first->get_data()['result_status'] ?? null );
			$second = $this->post_callback( $body );
			$this->assertSame( 200, $second->get_status(), wp_json_encode( $second->get_data() ) );
			$this->assertSame( (int) $first->get_data()['result_id'], (int) $second->get_data()['result_id'] );
			$this->assertSame( 2, $second->get_data()['entries_updated'] ?? null );
			$this->assertTrue( $second->get_data()['idempotent'] ?? false );
		}
	}

	public function test_site_string_mixed_claim_refusal_rolls_back_the_batch() {
		global $wpdb;
		$body = $this->seed_strings();
		$wpdb->update( wptsall_table( 'strings' ), array( 'claim_owner_hash' => str_repeat( 'b', 64 ) ), array( 'id' => $body['entries'][1]['entry_id'] ) );
		$response = $this->post_callback( $body );
		$this->assertSame( 409, $response->get_status() );
		$this->assert_strings_unmodified( $body );
		$this->assert_no_result( $body );
	}

	public function test_site_string_second_sql_failure_has_no_write_or_notification() {
		$body = $this->seed_strings();
		$hits = 0;
		$notified = array();
		$table = wptsall_table( 'strings' );
		$fault = static function ( $sql ) use ( $table, &$hits ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` SET `translations`' ) && ++$hits === 2 ) {
				return 'UPDATE wptsall_receipt_missing_table SET id = 1';
			}
			return $sql;
		};
		$listener = static function ( $id ) use ( &$notified ) { $notified[] = $id; };
		add_filter( 'query', $fault );
		add_action( 'wptsall_string_translated', $listener );
		try {
			$response = $this->post_callback( $body );
		} finally {
			remove_filter( 'query', $fault );
			remove_action( 'wptsall_string_translated', $listener );
		}
		$this->assertSame( 2, $hits );
		$this->assertSame( 500, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( array(), $notified, 'a rolled-back batch has no committed update notifications' );
		$this->assert_strings_unmodified( $body );
		$this->assert_no_result( $body );
		$this->assertSame( 200, $this->post_callback( $body )->get_status() );
	}

	private function seed_outbox( $task_id = 0 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->assertSame(
			1,
			$wpdb->insert(
				wptsall_table( 'content_change_outbox' ),
				array(
					'event_key' => hash( 'sha256', uniqid( self::PREFIX, true ) ), 'source_type' => 'post',
					'source_id' => 999, 'source_site_id' => get_current_blog_id(), 'relation_id' => $this->relation_id,
					'event_name' => 'post_updated',
					'payload' => wp_json_encode( array( '_wptsall_claim_owner_hash' => $this->owner( 'outbox' ), 'task_id' => $task_id ) ),
					'status' => 'processing', 'attempts' => 1, 'claimed_at' => $now,
					'available_at' => $now, 'created_at' => $now, 'updated_at' => $now,
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function outbox_status( $id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', wptsall_table( 'content_change_outbox' ), $id ) );
	}

	private function seed_applied_result( array $body ) {
		global $wpdb;
		$id = wptsall_insert_translation_result(
			array(
				'relation_id' => $this->relation_id, 'object_type' => 'post_type', 'object_id' => 999,
				'client_task_id' => $body['client_task_id'], 'source_lang' => 'zh_CN', 'target_lang' => 'en_US',
				'request_hash' => \WPTSALL\Core\Job_Snapshot::request_body_hash( $body ),
			)
		);
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 1, $wpdb->update( wptsall_table( 'translation_results' ), array( 'status' => 'synced' ), array( 'id' => $id ) ) );
		return $id;
	}

	private function cached_outbox_callback() {
		$body = array(
			'business_line' => 'post_content', 'client_task_id' => self::PREFIX . uniqid(),
			'relation_id' => $this->relation_id, 'object_type' => 'post_type', 'object_id' => 999,
			'outbox_id' => $this->seed_outbox(),
		);
		$result_id = $this->seed_applied_result( $body );
		$key = self::PREFIX . uniqid();
		$this->cache_keys[] = $key;
		wptsall_idempotency_set(
			$key, 200, array( 'success' => true, 'result_id' => $result_id, 'protocol' => 'v2' ),
			DAY_IN_SECONDS, \WPTSALL\Core\Job_Snapshot::request_body_hash( $body )
		);
		return array( $body, $key );
	}

	public function test_cached_receipt_finishes_the_original_outbox_after_crash() {
		list( $body, $key ) = $this->cached_outbox_callback();
		$response = $this->post_callback( $body, $key );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $this->outbox_status( $body['outbox_id'] ), 'a cached ack must still finish durable outbox completion' );
	}

	public function test_cached_receipt_cannot_bypass_outbox_owner_validation() {
		list( $body, $key ) = $this->cached_outbox_callback();
		$response = $this->post_callback( $body, $key, 'test-callback-other-device' );
		$this->assertSame( 403, $response->get_status(), 'cache is not authority to acknowledge another device lease' );
		$this->assertSame( 'processing', $this->outbox_status( $body['outbox_id'] ) );
	}

	public function test_outbox_completion_storage_failure_refuses_ack_but_replays_same_result() {
		global $wpdb;
		list( $body, $key ) = $this->cached_outbox_callback();
		$hits = 0;
		$table = wptsall_table( 'content_change_outbox' );
		$fault = static function ( $sql ) use ( $table, &$hits ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` SET status' ) && false !== strpos( $sql, 'completed_at' ) ) {
				++$hits;
				return 'UPDATE wptsall_receipt_missing_table SET id = 1';
			}
			return $sql;
		};
		add_filter( 'query', $fault );
		try {
			$response = $this->post_callback( $body, $key );
		} finally {
			remove_filter( 'query', $fault );
		}
		$this->assertSame( 1, $hits );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'outbox_acknowledgement_failed', $response->get_data()['error'] ?? null );
		$this->assertSame( 'processing', $this->outbox_status( $body['outbox_id'] ) );
		$second = $this->post_callback( $body, $key );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( (int) $response->get_data()['result_id'], (int) $second->get_data()['result_id'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_task_id = %s', wptsall_table( 'translation_results' ), $body['client_task_id'] ) ) );
		$this->assertSame( 'completed', $this->outbox_status( $body['outbox_id'] ) );
	}

	public function test_receipt_cannot_complete_another_owned_source_outbox() {
		global $wpdb;
		list( $body, $key ) = $this->cached_outbox_callback();
		$wpdb->update( wptsall_table( 'content_change_outbox' ), array( 'source_id' => 888 ), array( 'id' => $body['outbox_id'] ) );
		$response = $this->post_callback( $body, $key );
		$this->assertSame( 409, $response->get_status(), 'same device and relation still require the original source object' );
		$this->assertSame( 'processing', $this->outbox_status( $body['outbox_id'] ) );
	}

	public function test_completed_outbox_ack_is_still_bound_to_original_owner() {
		global $wpdb;
		$id = $this->seed_outbox();
		$wpdb->update( wptsall_table( 'content_change_outbox' ), array( 'status' => 'completed' ), array( 'id' => $id ) );
		$request = new WP_REST_Request( 'POST', '/wptsall/v2/client/content-changes/' . $id . '/ack' );
		$request->set_param( 'id', $id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPTSALL-Device-Id', 'test-callback-other-device' );
		$request->set_body( wp_json_encode( array( 'outcome' => 'completed' ) ) );
		$response = $this->controller->ack_content_change( $request );
		$this->assertSame( 403, $response->get_status(), 'an already completed row is not permission to acknowledge another device delivery' );
		$request->set_header( 'X-WPTSALL-Device-Id', self::DEVICE );
		$this->assertSame( 200, $this->controller->ack_content_change( $request )->get_status() );
	}

	public function test_outbox_task_projection_failure_rolls_back_completion() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->assertSame(
			1,
			$wpdb->insert(
				wptsall_table( 'tasks' ),
				array(
					'blog_id' => get_current_blog_id(), 'site_id' => $this->relation_id, 'relation_id' => $this->relation_id,
					'template' => self::PREFIX . uniqid(), 'object_type' => 'post_type', 'subtype' => 'post',
					'object_id' => 999, 'status' => 'processing', 'created_at' => $now, 'updated_at' => $now,
				)
			)
		);
		$task_id = (int) $wpdb->insert_id;
		$outbox_id = $this->seed_outbox( $task_id );
		$hits = 0;
		$table = wptsall_table( 'tasks' );
		$fault = static function ( $sql ) use ( $table, &$hits ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` SET status' ) && false !== strpos( $sql, 'Completed through durable content outbox' ) ) {
				++$hits;
				return 'UPDATE wptsall_receipt_missing_table SET id = 1';
			}
			return $sql;
		};
		add_filter( 'query', $fault );
		try {
			$ok = Content_Change_Dispatcher::complete_outbox( $outbox_id, $this->owner( 'outbox' ) );
		} finally {
			remove_filter( 'query', $fault );
		}
		$this->assertSame( 1, $hits, 'actual task projection must fail' );
		$this->assertFalse( $ok, 'projection failure must not return a completed ack' );
		$this->assertSame( 'processing', $this->outbox_status( $outbox_id ) );
		$this->assertSame( 'processing', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $table, $task_id ) ) );
		$this->assertTrue( Content_Change_Dispatcher::complete_outbox( $outbox_id, $this->owner( 'outbox' ) ) );
		$this->assertSame( 'completed', $this->outbox_status( $outbox_id ) );
		$this->assertSame( 'completed', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $table, $task_id ) ) );
	}
}

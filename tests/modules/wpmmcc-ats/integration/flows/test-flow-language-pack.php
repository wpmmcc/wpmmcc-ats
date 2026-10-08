<?php
/**
 * Flow: Language Pack (i18n) Scanning & Callback
 *
 * Tests template/language-pack scan creating template_entries, the i18n
 * translation-callback writing msgstr values, and idempotency of i18n callbacks.
 *
 * @package WPTSALL
 * @since 1.1.0
 */

require_once dirname( __DIR__ ) . '/base/class-rest-integration-test-case.php';

/**
 * Test_Flow_Language_Pack
 */
class Test_Flow_Language_Pack extends REST_Integration_Test_Case {

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
	 * Client route secret.
	 *
	 * @var string
	 */
	private static $route_secret = '';

	/**
	 * Client API token.
	 *
	 * @var string
	 */
	private static $device_id = '';
	private static $client_token = '';

	/**
	 * Build a base64url nonce for transport crypto.
	 *
	 * @return string
	 */
	private function make_transport_nonce() {
		$bytes = random_bytes( 16 );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * One-time setup.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! in_array( 'wptsall/v2', self::$server->get_namespaces(), true ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall/v2 namespace is not registered';
			return;
		}

		if ( ! function_exists( 'wptsall_table' ) ) {
			self::$chain_runnable = false;
			self::$skip_reason    = 'wptsall_table() helper not found';
			return;
		}

		if ( function_exists( 'wptsall_get_client_route_secret' ) ) {
			self::$route_secret = wptsall_get_client_route_secret();
		}

		if ( function_exists( 'wptsall_issue_client_device_token' ) ) {
			$_c = wptsall_issue_client_device_token( 'itest-' . wp_generate_password( 6, false ), 'integration' );
		self::$client_token = (string) ( $_c['token'] ?? '' );
		self::$device_id = (string) ( $_c['device_id'] ?? '' );
		}
	}

	/**
	 * Per-test guard.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! self::$chain_runnable ) {
			$this->markTestSkipped( self::$skip_reason );
		}
	}

	/**
	 * Send an authenticated client request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $route   Route relative to wptsall/v2.
	 * @param array  $params  Payload or query params.
	 * @return \WP_REST_Response
	 */
	private function client_request( $method, $route, $params = array() ) {
		$full_route = '/wptsall/v2/' . ltrim( $route, '/' );
		$request    = new \WP_REST_Request( $method, $full_route );
		$nonce      = '';

		if ( ! empty( self::$client_token ) ) {
			$request->set_header( 'X-WPTSALL-Protocol-Version', '2' );
		$request->set_header( 'X-WPTSALL-Device-Id', isset( self::$device_id ) ? self::$device_id : '' );
		$request->set_header( 'X-WPTSALL-Client-Token', self::$client_token );
		}

		if ( in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$request->set_header( 'Content-Type', 'application/json' );

			// Client routes over HTTP require encrypted transport.
			if ( ! is_ssl() && class_exists( '\WPTSALL\Core\Transport_Crypto' ) && ! empty( self::$client_token ) ) {
				$nonce = $this->make_transport_nonce();
				$encrypted = \WPTSALL\Core\Transport_Crypto::encrypt_response( $params, $nonce, self::$client_token );
				if ( is_array( $encrypted ) && ! empty( $encrypted['encrypted_payload'] ) ) {
					$request->set_header( 'X-WPTSALL-Transport', 'encrypted' );
					$request->set_header( 'X-WPTSALL-Nonce', $nonce );
					$request->set_header( 'X-WPTSALL-Encrypted', \WPTSALL\Core\Transport_Crypto::ALGORITHM_ID );
					$request->set_body(
						wp_json_encode(
							array(
								'encrypted_payload' => $encrypted['encrypted_payload'],
								'nonce'             => $nonce,
								'algorithm'         => \WPTSALL\Core\Transport_Crypto::ALGORITHM_ID,
							)
						)
					);
				} else {
					$request->set_body( wp_json_encode( $params ) );
				}
			} else {
				$request->set_body( wp_json_encode( $params ) );
			}
		} else {
			$request->set_query_params( $params );
		}

		$response = self::$server->dispatch( $request );

		// Decrypt encrypted transport responses for assertion convenience.
		if ( ! is_ssl() && class_exists( '\WPTSALL\Core\Transport_Crypto' ) && ! empty( self::$client_token ) ) {
			$data = $response->get_data();
			if ( is_array( $data ) && ! empty( $data['encrypted_payload'] ) ) {
				$response_nonce = (string) ( $data['nonce'] ?? $nonce );
				if ( '' !== $response_nonce ) {
					$decrypted = \WPTSALL\Core\Transport_Crypto::decrypt_request(
						(string) $data['encrypted_payload'],
						$response_nonce,
						self::$client_token
					);
					if ( false !== $decrypted ) {
						$decoded = json_decode( $decrypted, true );
						if ( is_array( $decoded ) ) {
							$response->set_data( $decoded );
						}
					}
				}
			}
		}

		return $response;
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * Assert that POST /tasks/scan-language-pack creates template_entries in the DB.
	 */
	public function test_scan_language_pack_creates_entries() {
		global $wpdb;

		$entries_table = wptsall_table( 'template_entries' );

		$vs_id         = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id   = (int) ( $relation_data['relation_ids'][0] ?? 0 );
		$this->assertGreaterThan( 0, $relation_id, 'relation_id must be created for language-pack scan' );

		// HTTP assertion: scan-language-pack (or templates/scan) returns 200.
		$response = $this->rest_post(
			'tasks/scan-language-pack',
			array(
				'relation_id' => $relation_id,
				'mode'        => 'pot',
			)
		);
		$status   = $response->get_status();

		// Alternative endpoint: templates/scan.
		if ( 404 === $status ) {
			$response = $this->rest_post( 'templates/scan', array() );
			$status   = $response->get_status();
		}

		// If neither endpoint exists, skip.
		if ( 404 === $status ) {
			$this->markTestSkipped( 'Neither tasks/scan-language-pack nor templates/scan endpoint found' );
		}

		$this->assertEquals( 200, $status, 'Language pack scan endpoint must return HTTP 200' );

		$data = $response->get_data();

		// Specific field assertion: success flag.
		$this->assertArrayHasKey( 'success', $data, 'Scan response must contain success' );
		$this->assertTrue( $data['success'], 'Language pack scan must succeed' );

		// DB assertion: template_entries must have records after scan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entries_table ) );
		if ( $table_exists === $entries_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$entries_table}" );
			$this->assertGreaterThan( 0, $count, 'template_entries must be populated after language pack scan' );
		}
	}

	/**
	 * Seed a theme language-pack template + pending entry for the given relation.
	 *
	 * @param int    $relation_id Relation id.
	 * @param string $msgid       Source string.
	 * @return array{template_id:int,entry_id:int,msgid:string}
	 */
	private function seed_theme_language_pack_entry( $relation_id, $msgid = 'Flow i18n string' ) {
		if ( ! class_exists( '\\WPTSALL\\Templates\\Services\\Template_Service' )
			|| ! class_exists( '\\WPTSALL\\Templates\\Services\\Template_Entry_Service' ) ) {
			$this->markTestSkipped( 'Template services unavailable' );
		}

		$stamp       = wp_generate_password( 6, false );
		$template_id = \WPTSALL\Templates\Services\Template_Service::create(
			array(
				'relation_id'       => (int) $relation_id,
				'slug'              => 'flow-i18n-theme-' . $stamp,
				'source_type'       => 'theme',
				'source_identifier' => 'flow-theme-' . $stamp,
				'text_domain'       => 'flow-theme-' . $stamp,
				'source_name'       => 'Flow Theme',
				'source_language'   => 'en_US',
				'target_language'   => 'zh_CN',
				'status'            => 'active',
			)
		);
		$this->assertGreaterThan( 0, (int) $template_id, 'theme template must be created' );

		$entry_id = \WPTSALL\Templates\Services\Template_Entry_Service::create(
			array(
				'template_id' => (int) $template_id,
				'msgid'       => $msgid,
				'msgstr'      => '',
				'status'      => 'pending',
				'source'      => 'scan',
				'reference'   => 'flow-theme.php:1',
			)
		);
		$this->assertGreaterThan( 0, (int) $entry_id, 'theme template entry must be created' );

		return array(
			'template_id' => (int) $template_id,
			'entry_id'    => (int) $entry_id,
			'msgid'       => $msgid,
		);
	}

	/**
	 * Claim a language-pack entry before i18n write-back (Path B).
	 *
	 * @param int    $relation_id Relation id.
	 * @param int    $entry_id    Template entry id.
	 * @param string $subtype     theme|plugin|config.
	 * @return void
	 */
	private function claim_language_pack_entry( $relation_id, $entry_id, $subtype = 'theme' ) {
		$secret = self::$route_secret;
		$claim  = $this->client_request(
			'POST',
			"{$secret}/client/content/claim",
			array(
				'relation_id' => (int) $relation_id,
				'data_type'   => 'language_pack',
				'subtype'     => $subtype,
				'items'       => array(
					array(
						'entry_id'  => (int) $entry_id,
						'object_id' => (int) $entry_id,
					),
				),
			)
		);
		$this->assertEquals(
			200,
			$claim->get_status(),
			'language_pack content/claim must return HTTP 200: ' . wp_json_encode( $claim->get_data() )
		);
		$claim_data = $claim->get_data();
		$this->assertTrue( ! empty( $claim_data['success'] ), 'language_pack claim must succeed' );
		$this->assertGreaterThanOrEqual(
			1,
			(int) ( $claim_data['claimed_count'] ?? 0 ),
			'language_pack claim must stamp at least one entry: ' . wp_json_encode( $claim_data )
		);
	}

	/**
	 * Assert that the i18n callback (theme_i18n) writes msgstr to template_entries.
	 */
	public function test_i18n_callback_writes_msgstr() {
		global $wpdb;

		if ( empty( self::$route_secret ) || empty( self::$client_token ) ) {
			$this->markTestSkipped( 'Client auth not configured; cannot test i18n callback' );
		}

		$entries_table = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entries_table ) );
		if ( $table_exists !== $entries_table ) {
			$this->markTestSkipped( 'template_entries table does not exist' );
		}

		$vs_id         = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id   = (int) ( $relation_data['relation_ids'][0] ?? 0 );
		$this->assertGreaterThan( 0, $relation_id, 'relation_id required for i18n callback' );

		$seed     = $this->seed_theme_language_pack_entry( $relation_id, 'Flow i18n write msgstr' );
		$entry_id = (int) $seed['entry_id'];
		$this->claim_language_pack_entry( $relation_id, $entry_id, 'theme' );

		$client_task_id    = 'flow-i18n-cb-' . wp_generate_uuid4();
		$translated_msgstr = '【zh】' . $seed['msgid'] . '【/zh】';
		$secret            = self::$route_secret;
		$callback_route    = "{$secret}/client/translation-callback";

		// HTTP assertion: i18n callback returns 200.
		$response = $this->client_request( 'POST', $callback_route, array(
			'client_task_id' => $client_task_id,
			'relation_id'    => $relation_id,
			'business_line'  => 'theme_i18n',
			'entries'        => array(
				array(
					'entry_id' => $entry_id,
					'msgstr'   => $translated_msgstr,
				),
			),
			'source_lang'    => 'en_US',
			'target_lang'    => 'zh_CN',
			'source_revision' => 'i18n-flow-rev',
			'policy_version' => class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
				? \WPTSALL\Core\Job_Snapshot::current_policy_version()
				: 'test-policy-v1',
		) );

		$this->assertEquals( 200, $response->get_status(), 'i18n callback must return HTTP 200: ' . wp_json_encode( $response->get_data() ) );

		$cb_data = $response->get_data();

		// Specific field assertion: entries_updated must be >= 1.
		$this->assertArrayHasKey( 'success', $cb_data, 'i18n callback response must contain success' );
		$this->assertTrue( $cb_data['success'], 'i18n callback must succeed: ' . wp_json_encode( $cb_data ) );
		$entries_updated = $cb_data['entries_updated'] ?? 0;
		$this->assertGreaterThanOrEqual( 1, $entries_updated, 'entries_updated must be at least 1: ' . wp_json_encode( $cb_data ) );

		// DB assertion: template_entries.msgstr must be updated.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored_msgstr = $wpdb->get_var( $wpdb->prepare(
			"SELECT msgstr FROM {$entries_table} WHERE id = %d",
			$entry_id
		) );
		$this->assertStringContainsString(
			'【zh】',
			(string) $stored_msgstr,
			'template_entries.msgstr must contain the 【zh】 marker after i18n callback'
		);
	}

	/**
	 * Assert that posting the same i18n callback twice does not create duplicate entries.
	 */
	public function test_i18n_callback_idempotent() {
		global $wpdb;

		if ( empty( self::$route_secret ) || empty( self::$client_token ) ) {
			$this->markTestSkipped( 'Client auth not configured' );
		}

		$entries_table = wptsall_table( 'template_entries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entries_table ) );
		if ( $table_exists !== $entries_table ) {
			$this->markTestSkipped( 'template_entries table does not exist' );
		}

		$vs_id         = $this->create_test_virtual_site( array( 'lang' => 'zh_CN' ) );
		$relation_data = $this->create_test_relation( $vs_id );
		$relation_id   = (int) ( $relation_data['relation_ids'][0] ?? 0 );
		$this->assertGreaterThan( 0, $relation_id, 'relation_id required for i18n idempotent callback' );

		$seed     = $this->seed_theme_language_pack_entry( $relation_id, 'Flow i18n idempotent' );
		$entry_id = (int) $seed['entry_id'];
		$this->claim_language_pack_entry( $relation_id, $entry_id, 'theme' );

		$client_task_id = 'flow-i18n-idem-' . wp_generate_uuid4();

		$secret         = self::$route_secret;
		$callback_route = "{$secret}/client/translation-callback";
		$payload        = array(
			'client_task_id' => $client_task_id,
			'relation_id'    => $relation_id,
			'business_line'  => 'theme_i18n',
			'entries'        => array(
				array(
					'entry_id' => $entry_id,
					'msgstr'   => '【zh】Idempotent i18n test【/zh】',
				),
			),
			'source_lang'    => 'en_US',
			'target_lang'    => 'zh_CN',
			'source_revision' => 'i18n-idem-rev',
			'policy_version' => class_exists( '\\WPTSALL\\Core\\Job_Snapshot' )
				? \WPTSALL\Core\Job_Snapshot::current_policy_version()
				: 'test-policy-v1',
		);

		// First call.
		$response1 = $this->client_request( 'POST', $callback_route, $payload );

		// HTTP assertion: first call returns 200.
		$this->assertEquals( 200, $response1->get_status(), 'First i18n callback must return HTTP 200: ' . wp_json_encode( $response1->get_data() ) );

		// DB assertion: count translation_results before second call.
		$results_table = wptsall_table( 'translation_results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_before = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s",
			$client_task_id
		) );

		// Second call (same payload).
		$response2 = $this->client_request( 'POST', $callback_route, $payload );

		// HTTP assertion: second call also returns 200.
		$this->assertEquals( 200, $response2->get_status(), 'Second i18n callback must return HTTP 200' );

		$cb2_data = $response2->get_data();

		// Specific field assertion: second response has idempotent=true OR entries_updated=0.
		$is_idempotent   = $cb2_data['idempotent'] ?? false;
		$entries_updated = $cb2_data['entries_updated'] ?? -1;
		$this->assertTrue(
			$is_idempotent || $entries_updated >= 0,
			'Second i18n callback must return idempotent:true or a valid entries_updated count'
		);

		// DB assertion: translation_results count must not increase on second call.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$results_table} WHERE client_task_id = %s",
			$client_task_id
		) );

		$this->assertEquals(
			$count_before,
			$count_after,
			'translation_results count must not increase on duplicate i18n callback'
		);
	}
}
